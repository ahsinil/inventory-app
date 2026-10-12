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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('warehouse_location_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['IN', 'OUT']);
            $table->decimal('quantity', 15, 2);
            $table->decimal('balance_after', 15, 2); // Snapshot of balance after movement
            $table->nullableUlidMorphs('reference'); // e.g., Receipt, Transfer, Adjustment
            $table->ulid('user_id')->nullable(); // Who caused this movement
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('
                CREATE TRIGGER prevent_stock_movements_update BEFORE UPDATE ON stock_movements
                FOR EACH ROW BEGIN
                    SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT = "Updates are not allowed on stock_movements table.";
                END;
            ');
            DB::unprepared('
                CREATE TRIGGER prevent_stock_movements_delete BEFORE DELETE ON stock_movements
                FOR EACH ROW BEGIN
                    SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT = "Deletions are not allowed on stock_movements table.";
                END;
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS prevent_stock_movements_update');
            DB::unprepared('DROP TRIGGER IF EXISTS prevent_stock_movements_delete');
        }
        Schema::dropIfExists('stock_movements');
    }
};
