<?php

namespace App\Jobs;

use App\Models\StockBalance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExportReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $type;

    protected array $filters;

    protected string $reportId;

    protected ?string $userId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $type, array $filters, string $reportId, ?string $userId)
    {
        $this->type = $type;
        $this->filters = $filters;
        $this->reportId = $reportId;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $filename = "reports/{$this->type}_{$this->reportId}.csv";
        $file = fopen(storage_path('app/'.$filename), 'w');

        if ($this->type === 'stock_balance') {
            fputcsv($file, ['Company', 'Warehouse', 'Location', 'SKU', 'Product', 'Quantity']);
            $query = StockBalance::with(['product.unit', 'warehouse', 'warehouseLocation'])
                ->when($this->filters['warehouse_id'] ?? null, fn ($q, $wh) => $q->where('warehouse_id', $wh))
                ->when($this->filters['product_id'] ?? null, fn ($q, $pid) => $q->where('product_id', $pid));

            $query->chunk(500, function ($balances) use ($file) {
                foreach ($balances as $b) {
                    fputcsv($file, [
                        $b->company_id,
                        $b->warehouse->name ?? '-',
                        $b->warehouseLocation->name ?? '-',
                        $b->product->sku ?? '-',
                        $b->product->name ?? '-',
                        $b->quantity,
                    ]);
                }
            });
        } elseif ($this->type === 'movements') {
            fputcsv($file, ['Date', 'Type', 'SKU', 'Product', 'Qty', 'Balance', 'Reference']);
            // ... similar chunking logic
        }

        fclose($file);

        // Notify user via DB notification or broadcast here if needed.
        Log::info("ExportReportJob completed for {$filename}");
    }
}
