<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['stock_transfer_id', 'product_id', 'requested_quantity', 'sent_quantity', 'received_quantity', 'sent_serial_numbers', 'received_serial_numbers'])]
class StockTransferItem extends Model
{
    use Auditable, HasUlid;

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'decimal:2',
            'sent_quantity' => 'decimal:2',
            'received_quantity' => 'decimal:2',
            'sent_serial_numbers' => 'array',
            'received_serial_numbers' => 'array',
        ];
    }

    public function transfer()
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
