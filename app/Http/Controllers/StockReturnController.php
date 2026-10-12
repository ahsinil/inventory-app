<?php

namespace App\Http\Controllers;

use App\Actions\Inventory\ProcessReturnAction;
use App\Models\Company;
use App\Models\StockReturn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockReturnController extends Controller
{
    public function index(Request $request)
    {
        $query = StockReturn::with(['items.product.unit', 'warehouse'])->latest();
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('return_number', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhereHas('warehouse', function ($w) use ($search) {
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
        $returns = $query->paginate();

        return $this->successResponse($returns);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'reason' => 'required|string',
            'returned_by' => 'nullable|string',
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
        ]);

        $returnDoc = DB::transaction(function () use ($validated, $request) {
            $ret = StockReturn::create([
                'company_id' => $request->user()->company_id ?? Company::first()->id,
                'return_number' => 'RET-'.date('YmdHis').rand(10, 99),
                'warehouse_id' => $validated['warehouse_id'],
                'reason' => $validated['reason'],
                'returned_by' => $validated['returned_by'] ?? null,
                'reference_type' => $validated['reference_type'] ?? null,
                'reference_id' => $validated['reference_id'] ?? null,
                'status' => 'PENDING',
                'created_by' => $request->user()?->id,
            ]);

            foreach ($validated['items'] as $item) {
                $ret->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                ]);
            }

            return $ret->load(['items.product.unit', 'warehouse']);
        });

        return $this->successResponse($returnDoc, ['message' => 'Return document created successfully'], 201);
    }

    public function show(StockReturn $return)
    {
        $return->load(['items.product.unit', 'warehouse']);

        return $this->successResponse($return);
    }

    public function post(Request $request, StockReturn $return, ProcessReturnAction $action)
    {
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.condition' => 'required|string|in:GOOD,DAMAGED,DISPOSED',
            'items.*.serial_numbers' => 'nullable|array',
        ]);

        try {
            $posted = $action->execute($return, (string) $request->user()?->id, $validated['items']);

            return $this->successResponse($posted, ['message' => 'Return document posted successfully']);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 'POST_FAILED', [], 422);
        }
    }
}
