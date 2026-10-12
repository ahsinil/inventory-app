<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StockBalance;
use Illuminate\Http\Request;

class ExternalStockController extends Controller
{
    /** Stock availability aggregated per product (optionally filtered by warehouse / sku). */
    public function availability(Request $request)
    {
        $companyId = $request->attributes->get('service_credential')->company_id;

        $rows = StockBalance::query()
            ->join('products', 'products.id', '=', 'stock_balances.product_id')
            ->where('stock_balances.company_id', $companyId)
            ->when($request->query('warehouse_id'), fn ($q, $v) => $q->where('stock_balances.warehouse_id', $v))
            ->when($request->query('sku'), fn ($q, $v) => $q->where('products.sku', $v))
            ->groupBy('products.id', 'products.sku', 'products.name')
            ->selectRaw('products.id as product_id, products.sku, products.name, SUM(stock_balances.quantity) as available')
            ->paginate(min((int) $request->query('per_page', 50), 200));

        return $this->successResponse($rows);
    }
}
