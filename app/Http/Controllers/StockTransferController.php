<?php

namespace App\Http\Controllers;

use App\Actions\Inventory\ReceiveTransferAction;
use App\Actions\Inventory\ShipTransferAction;
use App\Models\Company;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockTransferController extends Controller
{
    public function index(Request $request)
    {
        $query = StockTransfer::with(['sourceWarehouse', 'destinationWarehouse', 'items.product.unit']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('transfer_number', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhereHas('sourceWarehouse', function ($w) use ($search) {
                        $w->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('destinationWarehouse', function ($w) use ($search) {
                        $w->where('name', 'like', "%{$search}%");
                    });
            });
        }
        if ($startDate = $request->query('start_date')) {
            $query->whereDate('created_at', '>=', $startDate);
        }
        if ($endDate = $request->query('end_date')) {
            $query->whereDate('created_at', '<=', $endDate);
        }

        $sortBy = $request->input('sort_by');
        $sortDir = strtolower($request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        if ($sortBy === 'source_warehouse') {
            $query->orderBy(
                Warehouse::select('name')->whereColumn('warehouses.id', 'stock_transfers.source_warehouse_id'),
                $sortDir
            );
        } elseif ($sortBy === 'destination_warehouse') {
            $query->orderBy(
                Warehouse::select('name')->whereColumn('warehouses.id', 'stock_transfers.destination_warehouse_id'),
                $sortDir
            );
        } elseif (in_array($sortBy, ['transfer_number', 'status', 'created_at', 'expected_date'], true)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->latest();
        }

        $transfers = $query->paginate();

        return $this->successResponse($transfers);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'source_warehouse_id' => 'required|exists:warehouses,id',
            'destination_warehouse_id' => 'required|exists:warehouses,id|different:source_warehouse_id',
            'expected_date' => 'nullable|date',
            'remarks' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.requested_quantity' => 'required|numeric|min:0.01',
        ]);

        $transfer = DB::transaction(function () use ($validated, $request) {
            $companyId = $request->user()->company_id ?? Company::first()->id;

            $transfer = StockTransfer::create([
                'company_id' => $companyId,
                'transfer_number' => 'TRF-'.date('YmdHis').rand(10, 99),
                'source_warehouse_id' => $validated['source_warehouse_id'],
                'destination_warehouse_id' => $validated['destination_warehouse_id'],
                'expected_date' => $validated['expected_date'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
                'status' => 'APPROVED', // skip DRAFT/REQUESTED for simplicity
                'created_by' => $request->user()?->id,
                'approved_by' => $request->user()?->id,
            ]);

            foreach ($validated['items'] as $item) {
                $transfer->items()->create([
                    'product_id' => $item['product_id'],
                    'requested_quantity' => $item['requested_quantity'],
                ]);
            }

            return $transfer->load(['sourceWarehouse', 'destinationWarehouse', 'items.product.unit']);
        });

        return $this->successResponse($transfer, ['message' => 'Transfer created successfully'], 201);
    }

    public function show(StockTransfer $transfer)
    {
        $transfer->load(['sourceWarehouse', 'destinationWarehouse', 'items.product.unit']);

        return $this->successResponse($transfer);
    }

    public function ship(Request $request, StockTransfer $transfer, ShipTransferAction $action)
    {
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.sent_quantity' => 'required|numeric|min:0',
            'items.*.warehouse_location_id' => 'required|exists:warehouse_locations,id',
            'items.*.serial_numbers' => 'nullable|array',
            'items.*.serial_numbers.*' => 'string',
        ]);

        try {
            $shipped = $action->execute($transfer, (string) $request->user()?->id, $validated['items']);

            return $this->successResponse($shipped, ['message' => 'Transfer shipped successfully']);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 'SHIP_FAILED', [], 422);
        }
    }

    public function receive(Request $request, StockTransfer $transfer, ReceiveTransferAction $action)
    {
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.received_quantity' => 'required|numeric|min:0',
            'items.*.warehouse_location_id' => 'nullable|exists:warehouse_locations,id',
            'items.*.serial_numbers' => 'nullable|array',
            'items.*.serial_numbers.*' => 'string',
        ]);

        try {
            $received = $action->execute($transfer, (string) $request->user()?->id, $validated['items']);

            return $this->successResponse($received, ['message' => 'Transfer received successfully']);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 'RECEIVE_FAILED', [], 422);
        }
    }
}
