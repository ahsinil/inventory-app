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
        Schema::create('asset_histories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('inventory_asset_id')->constrained()->cascadeOnDelete();
            $table->string('event'); // e.g., RECEIVED, TRANSFERRED, RESERVED, DEPLOYED
            $table->string('status_from')->nullable();
            $table->string('status_to');
            $table->nullableUlidMorphs('reference');
            $table->ulid('user_id')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_histories');
    }
};
