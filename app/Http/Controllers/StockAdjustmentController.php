<?php

namespace App\Http\Controllers;

use App\Actions\Inventory\AdjustStockAction;
use App\Models\Company;
use App\Models\StockAdjustment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockAdjustmentController extends Controller
{
    public function index(Request $request)
    {
        $query = StockAdjustment::with(['items.product.unit', 'items.warehouseLocation', 'warehouse'])->latest();
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('adjustment_number', 'like', "%{$search}%")
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
        $adjustments = $query->paginate();

        return $this->successResponse($adjustments);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'reason' => 'required|string',
            'remarks' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.warehouse_location_id' => 'required|exists:warehouse_locations,id',
            'items.*.system_quantity' => 'required|numeric|min:0',
            'items.*.actual_quantity' => 'required|numeric|min:0',
        ]);

        $adjustment = DB::transaction(function () use ($validated, $request) {
            $adj = StockAdjustment::create([
                'company_id' => $request->user()->company_id ?? Company::first()->id,
                'adjustment_number' => 'SA-'.date('YmdHis').rand(10, 99),
                'warehouse_id' => $validated['warehouse_id'],
                'reason' => $validated['reason'],
                'remarks' => $validated['remarks'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            foreach ($validated['items'] as $item) {
                $adj->items()->create([
                    'product_id' => $item['product_id'],
                    'warehouse_location_id' => $item['warehouse_location_id'],
                    'system_quantity' => $item['system_quantity'],
                    'actual_quantity' => $item['actual_quantity'],
                    'difference' => $item['actual_quantity'] - $item['system_quantity'],
                ]);
            }

            return $adj->load(['items.product.unit', 'items.warehouseLocation', 'warehouse']);
        });

        return $this->successResponse($adjustment, ['message' => 'Adjustment created successfully'], 201);
    }

    public function show(StockAdjustment $adjustment)
    {
        $adjustment->load(['items.product.unit', 'items.warehouseLocation', 'warehouse']);

        return $this->successResponse($adjustment);
    }

    public function post(Request $request, StockAdjustment $adjustment, AdjustStockAction $action)
    {
        try {
            $posted = $action->execute($adjustment, (string) $request->user()?->id);

            return $this->successResponse($posted, ['message' => 'Adjustment posted successfully']);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 'POST_FAILED', [], 422);
        }
    }
}
