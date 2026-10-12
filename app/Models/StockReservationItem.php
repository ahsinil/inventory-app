<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['stock_reservation_id', 'product_id', 'quantity', 'confirmed_quantity', 'reserved_serial_numbers'])]
class StockReservationItem extends Model
{
    use Auditable, HasUlid;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'confirmed_quantity' => 'decimal:2',
            'reserved_serial_numbers' => 'array',
        ];
    }

    public function reservation()
    {
        return $this->belongsTo(StockReservation::class, 'stock_reservation_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
