<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('client_type')->default('external'); // external, internal
            $table->string('key_id')->unique();
            $table->string('secret_hash');
            $table->json('scopes');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('scope'); // credential id
            $table->string('key');
            $table->string('request_hash');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
            $table->unique(['scope', 'key']);
        });

        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('url');
            $table->string('secret');
            $table->json('events'); // e.g. ["stock.moved","reservation.*"] or ["*"]
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('webhook_subscription_id')->constrained()->cascadeOnDelete();
            $table->string('event_id');
            $table->string('event_type');
            $table->json('payload');
            $table->string('status')->default('PENDING'); // PENDING, SUCCESS, FAILED
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->string('external_system')->nullable();
            $table->string('external_reference')->nullable();
            $table->index(['external_system', 'external_reference']);
        });

        Schema::table('outbox_events', function (Blueprint $table) {
            $table->ulid('company_id')->nullable()->after('id');
            $table->timestamp('fanned_out_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->dropColumn(['company_id', 'fanned_out_at']);
        });
        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->dropIndex(['external_system', 'external_reference']);
            $table->dropColumn(['external_system', 'external_reference']);
        });
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_subscriptions');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('service_credentials');
    }
};
