<?php

namespace App\Http\Controllers;

use App\Models\StockBalance;
use App\Models\StockMovement;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function index(Request $request)
    {
        $balances = StockBalance::with(['product.unit', 'warehouse', 'warehouseLocation'])
            ->where('quantity', '>', 0)
            ->when($request->query('warehouse_id'), fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($request->query('search'), function ($q, $search) {
                $q->whereHas('product', function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            })
            ->paginate(min((int) $request->query('per_page', 15), 500));

        return $this->successResponse($balances);
    }

    public function movements($productId)
    {
        $movements = StockMovement::with('reference')
            ->where('product_id', $productId)
            ->latest()
            ->paginate();

        return $this->successResponse($movements);
    }
}
