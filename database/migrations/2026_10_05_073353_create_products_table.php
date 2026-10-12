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
        Schema::create('products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('sku');
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignUlid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('unit_id')->constrained()->restrictOnDelete();
            $table->boolean('has_serial_number')->default(false); // Does it need serialized tracking?
            $table->decimal('minimum_stock', 10, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'sku']); // SKU must be unique per company
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
