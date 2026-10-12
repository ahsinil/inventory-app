<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('warehouse_location_id')->constrained()->cascadeOnDelete();
            $table->decimal('system_quantity', 15, 2);
            $table->decimal('actual_quantity', 15, 2);
            $table->decimal('difference', 15, 2); // actual_quantity - system_quantity
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
    }
};
