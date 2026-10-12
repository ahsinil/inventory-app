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
        Schema::create('stock_returns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('return_number')->unique();
            $table->foreignUlid('warehouse_id')->constrained('warehouses');
            $table->string('status')->default('DRAFT'); // DRAFT, RECEIVED, INSPECTED, POSTED
            $table->nullableUlidMorphs('reference'); // e.g. Customer, Deployment
            $table->ulid('returned_by')->nullable();
            $table->text('reason')->nullable();
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
        Schema::dropIfExists('stock_returns');
    }
};
