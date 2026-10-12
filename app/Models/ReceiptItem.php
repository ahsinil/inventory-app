<?php

namespace App\Models;

use App\Support\Auditable;
use App\Support\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['receipt_id', 'product_id', 'expected_quantity', 'received_quantity', 'remarks'])]
class ReceiptItem extends Model
{
    use Auditable, HasUlid;

    protected function casts(): array
    {
        return [
            'expected_quantity' => 'decimal:2',
            'received_quantity' => 'decimal:2',
        ];
    }

    public function receipt()
    {
        return $this->belongsTo(Receipt::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
