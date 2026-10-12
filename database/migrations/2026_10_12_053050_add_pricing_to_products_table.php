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
        Schema::table('products', function (Blueprint $table) {
            // Harga pokok / biaya pembelian per satuan (HPP)
            $table->decimal('cost_price', 18, 4)->nullable()->after('minimum_stock')
                ->comment('Harga pokok / biaya beli per satuan (HPP)');

            // Harga jual per satuan
            $table->decimal('selling_price', 18, 4)->nullable()->after('cost_price')
                ->comment('Harga jual per satuan');

            // Mata uang (opsional, default IDR)
            $table->char('currency', 3)->default('IDR')->after('selling_price')
                ->comment('Kode mata uang ISO 4217');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['cost_price', 'selling_price', 'currency']);
        });
    }
};
