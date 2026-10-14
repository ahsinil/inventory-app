<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function index(Request $request)
    {
        $query = StockBalance::with(['product.unit', 'warehouse', 'warehouseLocation'])
            ->where('quantity', '>', 0)
            ->when($request->query('warehouse_id'), fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($request->query('search'), function ($q, $search) {
                $q->whereHas('product', function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            });

        $sortBy = $request->input('sort_by');
        $sortDir = strtolower($request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        if ($sortBy === 'product') {
            $query->orderBy(
                Product::select('name')->whereColumn('products.id', 'stock_balances.product_id'),
                $sortDir
            );
        } elseif ($sortBy === 'warehouse') {
            $query->orderBy(
                Warehouse::select('name')->whereColumn('warehouses.id', 'stock_balances.warehouse_id'),
                $sortDir
            );
        } elseif (in_array($sortBy, ['quantity', 'reserved_quantity', 'updated_at'], true)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('quantity', 'desc');
        }

        $balances = $query->paginate(min((int) $request->query('per_page', 15), 500));

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
