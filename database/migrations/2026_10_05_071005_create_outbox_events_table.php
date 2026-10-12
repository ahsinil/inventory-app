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
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('event_id')->unique();
            $table->string('event_type');
            $table->string('aggregate_type')->nullable();
            $table->string('aggregate_id')->nullable();
            $table->json('payload');
            $table->string('status')->default('PENDING'); // PENDING, PUBLISHED, FAILED
            $table->integer('attempts')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
