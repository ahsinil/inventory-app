<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['stock_return_id', 'product_id', 'quantity', 'condition', 'returned_serial_numbers'])]
class StockReturnItem extends Model
{
    use Auditable, HasUlid;

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'returned_serial_numbers' => 'array',
        ];
    }
}
