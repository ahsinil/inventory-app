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
        Schema::create('stock_return_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('stock_return_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 15, 2);
            $table->string('condition')->default('GOOD'); // GOOD, DAMAGED, DISPOSED
            $table->json('returned_serial_numbers')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_return_items');
    }
};
