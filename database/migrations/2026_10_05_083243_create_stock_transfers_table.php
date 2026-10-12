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
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('transfer_number')->unique();
            $table->foreignUlid('source_warehouse_id')->constrained('warehouses');
            $table->foreignUlid('destination_warehouse_id')->constrained('warehouses');
            $table->string('status')->default('DRAFT'); // DRAFT, REQUESTED, APPROVED, IN_TRANSIT, COMPLETED, CANCELLED
            $table->date('expected_date')->nullable();
            $table->text('remarks')->nullable();
            $table->ulid('created_by')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
