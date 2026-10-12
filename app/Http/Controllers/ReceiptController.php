<?php

namespace App\Http\Controllers;

use App\Actions\Inventory\ReceiveStockAction;
use App\Models\Company;
use App\Models\Receipt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReceiptController extends Controller
{
    public function index(Request $request)
    {
        $query = Receipt::with(['items', 'warehouse'])->latest();
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
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
        $receipts = $query->paginate();

        return $this->successResponse($receipts);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'expected_date' => 'nullable|date',
            'remarks' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.expected_quantity' => 'required|numeric|min:0.01',
        ]);

        $receipt = DB::transaction(function () use ($validated, $request) {
            $receipt = Receipt::create([
                'company_id' => $request->user()->company_id ?? Company::first()->id, // Simplified
                'receipt_number' => 'RC-'.date('YmdHis').rand(10, 99),
                'warehouse_id' => $validated['warehouse_id'],
                'supplier_id' => $validated['supplier_id'] ?? null,
                'expected_date' => $validated['expected_date'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            foreach ($validated['items'] as $item) {
                $receipt->items()->create([
                    'product_id' => $item['product_id'],
                    'expected_quantity' => $item['expected_quantity'],
                ]);
            }

            return $receipt->load('items');
        });

        return $this->successResponse($receipt, ['message' => 'Receipt created successfully'], 201);
    }

    public function show(Receipt $receipt)
    {
        $receipt->load(['items.product', 'warehouse']);

        return $this->successResponse($receipt);
    }

    public function post(Request $request, Receipt $receipt, ReceiveStockAction $action)
    {
        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.received_quantity' => 'required|numeric|min:0',
            'items.*.serial_numbers' => 'nullable|array',
            'items.*.serial_numbers.*' => 'string|max:100',
        ]);

        try {
            $posted = $action->execute($receipt, (string) $request->user()?->id, $validated['items']);

            return $this->successResponse($posted, ['message' => 'Receipt posted successfully']);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 'POST_FAILED', [], 422);
        }
    }
}
