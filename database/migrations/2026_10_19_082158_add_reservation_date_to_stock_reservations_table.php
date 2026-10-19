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
        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->date('reservation_date')->nullable()->after('customer_id');
        });

        DB::table('stock_reservations')
            ->whereNull('reservation_date')
            ->update(['reservation_date' => DB::raw('DATE(created_at)')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->dropColumn('reservation_date');
        });
    }
};
