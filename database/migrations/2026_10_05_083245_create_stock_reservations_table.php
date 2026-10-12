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
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('reservation_number')->unique();
            $table->foreignUlid('warehouse_id')->constrained('warehouses');
            $table->string('status')->default('DRAFT'); // DRAFT, RESERVED, CONFIRMED, RELEASED, EXPIRED
            $table->nullableUlidMorphs('reference'); // e.g. SalesOrder, WorkOrder
            $table->ulid('customer_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('remarks')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
