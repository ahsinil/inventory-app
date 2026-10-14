<?php

namespace App\Http\Controllers;

use App\Jobs\ExportReportJob;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryAsset;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function stockBalance(Request $request)
    {
        $query = StockBalance::with(['product.unit', 'warehouse', 'warehouseLocation'])
            ->when($request->warehouse_id, fn ($q, $wh) => $q->where('warehouse_id', $wh))
            ->when($request->product_id, fn ($q, $pid) => $q->where('product_id', $pid))
            ->when($request->category_id, function ($q, $cid) {
                $categoryIds = Category::where('id', $cid)->orWhere('parent_id', $cid)->pluck('id');
                $q->whereHas('product', fn ($q) => $q->whereIn('category_id', $categoryIds));
            });

        if ($request->export) {
            $reportId = (string) Str::ulid();
            ExportReportJob::dispatch('stock_balance', $request->all(), $reportId, $request->user()?->id);

            return $this->successResponse(['report_id' => $reportId], ['message' => 'Export job queued']);
        }

        return $this->successResponse($query->paginate());
    }

    public function movements(Request $request)
    {
        $query = StockMovement::with(['product', 'warehouse', 'warehouseLocation', 'reference', 'user'])
            ->when($request->warehouse_id, fn ($q, $wh) => $q->where('warehouse_id', $wh))
            ->when($request->product_id, fn ($q, $pid) => $q->where('product_id', $pid))
            ->when($request->category_id, function ($q, $cid) {
                $categoryIds = Category::where('id', $cid)->orWhere('parent_id', $cid)->pluck('id');
                $q->whereHas('product', fn ($q) => $q->whereIn('category_id', $categoryIds));
            })
            ->when($request->start_date, fn ($q, $sd) => $q->whereDate('created_at', '>=', $sd))
            ->when($request->end_date, fn ($q, $ed) => $q->whereDate('created_at', '<=', $ed))
            ->when($request->type, function ($q, $type) {
                if ($type === 'RECEIPT') {
                    $q->where('type', 'IN')->where('reference_type', '!=', 'App\\Models\\StockTransfer');
                } elseif ($type === 'ISSUE') {
                    $q->where('type', 'OUT')->where('reference_type', '!=', 'App\\Models\\StockTransfer');
                } elseif ($type === 'TRANSFER') {
                    $q->where('reference_type', 'App\\Models\\StockTransfer');
                } else {
                    $q->where('type', $type);
                }
            })
            ->when($request->search, function ($q, $search) {
                $q->whereHas('product', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"))
                    ->orWhere('id', 'like', "%{$search}%");
            });

        $sortBy = $request->input('sort_by');
        $sortDir = strtolower($request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        if (in_array($sortBy, ['created_at', 'quantity', 'type'], true)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->latest();
        }

        if ($request->export) {
            $reportId = (string) Str::ulid();
            ExportReportJob::dispatch('movements', $request->all(), $reportId, $request->user()?->id);

            return $this->successResponse(['report_id' => $reportId], ['message' => 'Export job queued']);
        }

        return $this->successResponse($query->paginate());
    }

    public function movementStats(Request $request)
    {
        $baseQuery = StockMovement::query()
            ->when($request->warehouse_id, fn ($q, $wh) => $q->where('warehouse_id', $wh))
            ->when($request->product_id, fn ($q, $pid) => $q->where('product_id', $pid))
            ->when($request->category_id, function ($q, $cid) {
                $categoryIds = Category::where('id', $cid)->orWhere('parent_id', $cid)->pluck('id');
                $q->whereHas('product', fn ($q) => $q->whereIn('category_id', $categoryIds));
            })
            ->when($request->start_date, fn ($q, $sd) => $q->whereDate('created_at', '>=', $sd))
            ->when($request->end_date, fn ($q, $ed) => $q->whereDate('created_at', '<=', $ed));

        $totalIn = (clone $baseQuery)->where('type', 'IN')->where('reference_type', '!=', 'App\\Models\\StockTransfer')->sum('quantity');
        $totalOut = (clone $baseQuery)->where('type', 'OUT')->where('reference_type', '!=', 'App\\Models\\StockTransfer')->sum('quantity');
        $transferCount = (clone $baseQuery)->where('reference_type', 'App\\Models\\StockTransfer')->distinct()->count('reference_id');

        return $this->successResponse([
            'total_in' => (float) $totalIn,
            'total_out' => (float) $totalOut,
            'transfer_count' => $transferCount,
        ]);
    }

    public function assets(Request $request)
    {
        $query = InventoryAsset::with(['product', 'warehouseLocation'])
            ->when($request->status, fn ($q, $st) => $q->where('status', $st));

        if ($request->export) {
            $reportId = (string) Str::ulid();
            ExportReportJob::dispatch('assets', $request->all(), $reportId, $request->user()?->id);

            return $this->successResponse(['report_id' => $reportId], ['message' => 'Export job queued']);
        }

        return $this->successResponse($query->paginate());
    }

    /**
     * Menghitung total valuasi inventaris berdasarkan cost_price × quantity.
     * Hanya produk yang sudah memiliki cost_price yang masuk ke total.
     *
     * @return JsonResponse
     */
    public function inventoryValuation(Request $request)
    {
        $companyId = $request->user()->company_id ?? Company::first()->id;

        $balances = StockBalance::query()
            ->where('stock_balances.company_id', $companyId)
            ->where('quantity', '>', 0)
            ->join('products', 'products.id', '=', 'stock_balances.product_id')
            ->whereNull('products.deleted_at')
            ->when($request->warehouse_id, fn ($q, $wh) => $q->where('stock_balances.warehouse_id', $wh))
            ->when($request->category_id, fn ($q, $cid) => $q->where('products.category_id', $cid))
            ->selectRaw('
                SUM(stock_balances.quantity) as total_quantity,
                SUM(CASE WHEN products.cost_price IS NOT NULL THEN stock_balances.quantity * products.cost_price ELSE 0 END) as total_cost_value,
                SUM(CASE WHEN products.selling_price IS NOT NULL THEN stock_balances.quantity * products.selling_price ELSE 0 END) as total_selling_value,
                COUNT(DISTINCT stock_balances.product_id) as total_products,
                COUNT(DISTINCT CASE WHEN products.cost_price IS NOT NULL THEN stock_balances.product_id END) as products_with_cost,
                COUNT(DISTINCT CASE WHEN products.cost_price IS NULL THEN stock_balances.product_id END) as products_without_cost
            ')
            ->first();

        /** @var array<string, float|int> $result */
        $result = [
            'total_quantity' => (float) ($balances->total_quantity ?? 0),
            'total_cost_value' => (float) ($balances->total_cost_value ?? 0),
            'total_selling_value' => (float) ($balances->total_selling_value ?? 0),
            'total_products' => (int) ($balances->total_products ?? 0),
            'products_with_cost' => (int) ($balances->products_with_cost ?? 0),
            'products_without_cost' => (int) ($balances->products_without_cost ?? 0),
            'coverage_percent' => $balances->total_products > 0
                ? round(($balances->products_with_cost / $balances->total_products) * 100, 1)
                : 0,
        ];

        return $this->successResponse($result);
    }

    public function chart(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id ?? Company::first()?->id;
        $period = $request->query('period', '8w');

        $series = [];
        if ($period === '6m') {
            for ($i = 5; $i >= 0; $i--) {
                $dt = now()->subMonths($i);
                $key = $dt->format('Y-m');
                $series[$key] = [
                    'key' => $key,
                    'label' => $dt->translatedFormat('M'),
                    'in' => 0.0,
                    'out' => 0.0,
                    'volume' => 0.0,
                ];
            }

            $movements = StockMovement::where('company_id', $companyId)
                ->where('created_at', '>=', now()->subMonths(5)->startOfMonth())
                ->get(['created_at', 'type', 'quantity']);

            foreach ($movements as $m) {
                $k = $m->created_at->format('Y-m');
                if (isset($series[$k])) {
                    $qty = (float) $m->quantity;
                    if (strtolower($m->type) === 'in') {
                        $series[$k]['in'] += $qty;
                    } else {
                        $series[$k]['out'] += $qty;
                    }
                    $series[$k]['volume'] += $qty;
                }
            }
        } elseif ($period === '7d') {
            for ($i = 6; $i >= 0; $i--) {
                $dt = now()->subDays($i);
                $key = $dt->format('Y-m-d');
                $series[$key] = [
                    'key' => $key,
                    'label' => $dt->translatedFormat('D'),
                    'in' => 0.0,
                    'out' => 0.0,
                    'volume' => 0.0,
                ];
            }

            $movements = StockMovement::where('company_id', $companyId)
                ->where('created_at', '>=', now()->subDays(6)->startOfDay())
                ->get(['created_at', 'type', 'quantity']);

            foreach ($movements as $m) {
                $k = $m->created_at->format('Y-m-d');
                if (isset($series[$k])) {
                    $qty = (float) $m->quantity;
                    if (strtolower($m->type) === 'in') {
                        $series[$k]['in'] += $qty;
                    } else {
                        $series[$k]['out'] += $qty;
                    }
                    $series[$k]['volume'] += $qty;
                }
            }
        } else {
            // Default 8 weeks
            $startWeek = now()->subWeeks(7)->startOfWeek();
            for ($i = 7; $i >= 0; $i--) {
                $wStart = now()->subWeeks($i)->startOfWeek();
                $wEnd = (clone $wStart)->endOfWeek();
                $series[] = [
                    'label' => 'W'.(8 - $i),
                    'start' => $wStart->format('Y-m-d'),
                    'end' => $wEnd->format('Y-m-d'),
                    'in' => 0.0,
                    'out' => 0.0,
                    'volume' => 0.0,
                ];
            }

            $movements = StockMovement::where('company_id', $companyId)
                ->where('created_at', '>=', $startWeek)
                ->get(['created_at', 'type', 'quantity']);

            foreach ($movements as $m) {
                $dateStr = $m->created_at->format('Y-m-d');
                $qty = (float) $m->quantity;
                foreach ($series as &$bucket) {
                    if ($dateStr >= $bucket['start'] && $dateStr <= $bucket['end']) {
                        if (strtolower($m->type) === 'in') {
                            $bucket['in'] += $qty;
                        } else {
                            $bucket['out'] += $qty;
                        }
                        $bucket['volume'] += $qty;
                        break;
                    }
                }
                unset($bucket);
            }
        }

        $items = array_values($series);
        $maxVolume = max(array_merge([10], array_column($items, 'volume')));

        return $this->successResponse([
            'period' => $period,
            'max_volume' => $maxVolume,
            'series' => $items,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $companyId = $request->user()?->company_id ?? Company::first()?->id;
        $type = $request->query('type', 'valuation');
        $now = now()->format('Ymd_His');

        switch ($type) {
            case 'valuation':
                $filename = "laporan-valuasi-inventaris-{$now}.csv";

                return response()->streamDownload(function () use ($companyId) {
                    $out = fopen('php://output', 'w');
                    fwrite($out, "\xEF\xBB\xBF");
                    fputcsv($out, ['No', 'SKU', 'Nama Produk', 'Kategori', 'Satuan', 'Stok Fisik', 'HPP / Harga Pokok (IDR)', 'Harga Jual (IDR)', 'Total Valuasi HPP (IDR)', 'Status']);

                    $products = Product::where('company_id', $companyId)
                        ->with(['category', 'unit'])
                        ->withSum('stockBalances as total_quantity', 'quantity')
                        ->get();

                    $no = 1;
                    foreach ($products as $p) {
                        $qty = (float) ($p->total_quantity ?? 0);
                        $cost = (float) ($p->cost_price ?? 0);
                        $sell = (float) ($p->selling_price ?? 0);
                        $totalVal = $qty * $cost;
                        $status = $p->cost_price ? 'Tervaluasi' : 'Belum Ada HPP';

                        fputcsv($out, [
                            $no++,
                            $p->sku,
                            $p->name,
                            $p->category?->name ?? '-',
                            $p->unit?->name ?? 'pcs',
                            $qty,
                            $cost,
                            $sell,
                            $totalVal,
                            $status,
                        ]);
                    }
                    fclose($out);
                }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);

            case 'movements':
                $filename = "laporan-mutasi-stok-{$now}.csv";

                return response()->streamDownload(function () use ($companyId) {
                    $out = fopen('php://output', 'w');
                    fwrite($out, "\xEF\xBB\xBF");
                    fputcsv($out, ['No', 'Waktu Transaksi', 'Tipe Mutasi', 'No Referensi / Keterangan', 'SKU', 'Nama Produk', 'Gudang', 'Lokasi / Rak', 'Jumlah', 'Saldo Akhir', 'Petugas']);

                    $movements = StockMovement::where('company_id', $companyId)
                        ->with(['product', 'warehouse', 'warehouseLocation', 'user'])
                        ->latest()
                        ->get();

                    $no = 1;
                    foreach ($movements as $m) {
                        fputcsv($out, [
                            $no++,
                            $m->created_at->format('Y-m-d H:i:s'),
                            $m->type,
                            $m->remarks ?? $m->reference_id ?? '-',
                            $m->product?->sku ?? '-',
                            $m->product?->name ?? '-',
                            $m->warehouse?->name ?? '-',
                            $m->warehouseLocation?->code ?? '-',
                            ($m->type === 'OUT' ? '-' : '+').$m->quantity,
                            $m->balance_after,
                            $m->user?->name ?? 'System',
                        ]);
                    }
                    fclose($out);
                }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);

            case 'low_stock':
                $filename = "laporan-stok-menipis-{$now}.csv";

                return response()->streamDownload(function () use ($companyId) {
                    $out = fopen('php://output', 'w');
                    fwrite($out, "\xEF\xBB\xBF");
                    fputcsv($out, ['No', 'SKU', 'Nama Produk', 'Kategori', 'Satuan', 'Stok Saat Ini', 'Batas Minimum', 'Defisit / Kekurangan', 'Status']);

                    $products = Product::where('company_id', $companyId)
                        ->with(['category', 'unit'])
                        ->withSum('stockBalances as total_quantity', 'quantity')
                        ->get();

                    $no = 1;
                    foreach ($products as $p) {
                        $qty = (float) ($p->total_quantity ?? 0);
                        $min = (float) $p->minimum_stock;
                        if (($min > 0 && $qty <= $min) || ($min == 0 && $qty == 0)) {
                            $deficit = max(0, $min - $qty);
                            $status = $qty <= 0 ? 'Habis (0 Stok)' : 'Menipis di Bawah Minimum';
                            fputcsv($out, [
                                $no++,
                                $p->sku,
                                $p->name,
                                $p->category?->name ?? '-',
                                $p->unit?->name ?? 'pcs',
                                $qty,
                                $min,
                                $deficit,
                                $status,
                            ]);
                        }
                    }
                    fclose($out);
                }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);

            case 'stock_balance':
            default:
                $filename = "laporan-saldo-gudang-{$now}.csv";

                return response()->streamDownload(function () use ($companyId) {
                    $out = fopen('php://output', 'w');
                    fwrite($out, "\xEF\xBB\xBF");
                    fputcsv($out, ['No', 'Gudang', 'Kode Rak / Lokasi', 'Nama Lokasi', 'SKU', 'Nama Produk', 'Kuantitas Fisik', 'Satuan']);

                    $balances = StockBalance::where('company_id', $companyId)
                        ->with(['product.unit', 'warehouse', 'warehouseLocation'])
                        ->get();

                    $no = 1;
                    foreach ($balances as $b) {
                        fputcsv($out, [
                            $no++,
                            $b->warehouse?->name ?? '-',
                            $b->warehouseLocation?->code ?? '-',
                            $b->warehouseLocation?->name ?? '-',
                            $b->product?->sku ?? '-',
                            $b->product?->name ?? '-',
                            $b->quantity,
                            $b->product?->unit?->name ?? 'pcs',
                        ]);
                    }
                    fclose($out);
                }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
    }
}
