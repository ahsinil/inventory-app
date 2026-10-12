<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id ?? Company::first()?->id;
        $warehouseId = $request->query('warehouse_id');

        // Total Stock Value and Physical Stock
        $balanceQuery = StockBalance::where('stock_balances.company_id', $companyId)
            ->join('products', 'products.id', '=', 'stock_balances.product_id')
            ->when($warehouseId, fn ($q) => $q->where('stock_balances.warehouse_id', $warehouseId));

        $totalStockValue = (float) $balanceQuery->sum(DB::raw('stock_balances.quantity * COALESCE(products.cost_price, 0)'));
        $totalPhysicalStock = (float) $balanceQuery->sum('stock_balances.quantity');

        // Products count
        $totalProductsInStock = StockBalance::where('company_id', $companyId)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->where('quantity', '>', 0)
            ->distinct('product_id')
            ->count('product_id');

        $totalCatalogProducts = Product::where('company_id', $companyId)->count();

        // Pending Transfers
        $pendingTransfers = StockTransfer::where('company_id', $companyId)
            ->when($warehouseId, fn ($q) => $q->where(fn ($sub) => $sub->where('source_warehouse_id', $warehouseId)->orWhere('destination_warehouse_id', $warehouseId)))
            ->whereIn('status', ['REQUESTED', 'APPROVED'])
            ->count();

        // Pending Reservations
        $pendingReservations = StockReservation::where('company_id', $companyId)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->whereIn('status', ['DRAFT', 'PENDING', 'CONFIRMED'])
            ->count();

        // Low stock products
        $products = Product::where('company_id', $companyId)
            ->with(['unit', 'category'])
            ->withSum([
                'stockBalances as total_quantity' => fn ($q) => $q->when($warehouseId, fn ($sq) => $sq->where('warehouse_id', $warehouseId)),
            ], 'quantity')
            ->get()
            ->map(function ($product) {
                $qty = (float) ($product->total_quantity ?? 0);
                $min = (float) $product->minimum_stock;
                $isLow = ($min > 0 && $qty <= $min) || ($min == 0 && $qty == 0);
                $pct = $min > 0 ? min(100, round(($qty / $min) * 100)) : ($qty > 0 ? 100 : 0);

                return [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'category_name' => $product->category?->name ?? 'Tanpa Kategori',
                    'unit_name' => $product->unit?->name ?? 'pcs',
                    'quantity' => $qty,
                    'minimum_stock' => $min,
                    'percentage' => $pct,
                    'is_low_stock' => $isLow,
                    'status' => $qty <= 0 ? 'Habis' : ($qty <= $min ? 'Menipis' : 'Aman'),
                ];
            });

        $lowStockItems = $products->filter(fn ($p) => $p['is_low_stock'])
            ->sortBy([
                ['minimum_stock', 'desc'],
                ['quantity', 'asc'],
            ])
            ->values();

        $lowStockCount = $lowStockItems->count();

        // Monthly movement breakdown (last 6 months)
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $dt = now()->subMonths($i);
            $key = $dt->format('Y-m');
            $months[$key] = [
                'key' => $key,
                'label' => $dt->translatedFormat('M'),
                'in' => 0.0,
                'out' => 0.0,
            ];
        }

        $recentMovementsForMonths = StockMovement::where('company_id', $companyId)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->where('created_at', '>=', now()->subMonths(5)->startOfMonth())
            ->get(['created_at', 'type', 'quantity']);

        foreach ($recentMovementsForMonths as $mov) {
            $monthKey = $mov->created_at->format('Y-m');
            if (isset($months[$monthKey])) {
                $typeKey = strtolower($mov->type) === 'in' ? 'in' : 'out';
                $months[$monthKey][$typeKey] += (float) $mov->quantity;
            }
        }

        // Recent Movements
        $recentMovements = StockMovement::where('company_id', $companyId)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->with([
                'product:id,sku,name',
                'warehouse:id,name,code',
                'warehouseLocation:id,code,name',
                'user:id,name',
            ])
            ->latest()
            ->take(8)
            ->get();

        // Active warehouses for filter dropdown
        $warehouses = Warehouse::where('company_id', $companyId)
            ->where('is_active', true)
            ->get(['id', 'code', 'name']);

        return $this->successResponse([
            'kpi' => [
                'total_stock_value' => $totalStockValue,
                'total_physical_stock' => $totalPhysicalStock,
                'total_products_in_stock' => $totalProductsInStock,
                'total_catalog_products' => $totalCatalogProducts,
                'pending_transfers' => $pendingTransfers,
                'pending_reservations' => $pendingReservations,
                'low_stock_count' => $lowStockCount,
            ],
            'monthly_movements' => array_values($months),
            'low_stock_items' => $lowStockItems->take(5),
            'recent_movements' => $recentMovements,
            'warehouses' => $warehouses,
        ]);
    }
}
