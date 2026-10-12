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
        Schema::create('receipts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_number')->unique();
            $table->foreignUlid('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('EXPECTED'); // EXPECTED, VALIDATED, POSTED, CANCELLED
            $table->string('external_reference')->nullable();
            $table->date('expected_date')->nullable();
            $table->date('received_date')->nullable();
            $table->text('remarks')->nullable();
            $table->ulid('created_by')->nullable();
            $table->ulid('posted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
