<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M3 PR 2: top-ups through the gateway's hosted checkout, the signed webhooks that
 * confirm them, frozen wallets (D-20 uses them in PR 6) and the fan's display
 * currency (D-21). New tables only. Fan ids are Identity account ids: no foreign keys
 * across modules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topups', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('fan_id');
            // From the form or the Idempotency-Key header: a repeat returns the same top-up.
            $table->string('idempotency_key', 100);
            $table->string('gateway', 30);
            $table->string('gateway_ref', 100)->nullable()->unique();
            $table->string('checkout_url', 500)->nullable();
            $table->string('method', 10);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->bigInteger('credits');
            $table->bigInteger('bonus_credits')->default(0);
            $table->string('status', 15)->default('pending');
            // "Visa ····4417" as the gateway reports it; never a card number.
            $table->string('payment_hint', 40)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['fan_id', 'idempotency_key']);
            $table->index(['fan_id', 'created_at']);
        });

        DB::statement("ALTER TABLE topups ADD CONSTRAINT topups_status_check CHECK (status IN ('pending', 'paid', 'failed', 'charged_back'))");
        DB::statement('ALTER TABLE topups ADD CONSTRAINT topups_amounts_check CHECK (amount_minor > 0 AND credits > 0 AND bonus_credits >= 0)');

        Schema::create('gateway_webhooks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('gateway', 30);
            $table->string('event_id', 100);
            $table->string('type', 30);
            $table->string('gateway_ref', 100);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('payment_hint', 40)->nullable();
            // The raw body as received (spec: "store the raw payload"), encrypted at rest.
            $table->text('payload');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('error', 200)->nullable();

            // Spec and SEC-13: a replayed event is stored once and processed once.
            $table->unique(['gateway', 'event_id']);
            $table->index('gateway_ref');
        });

        Schema::create('wallet_freezes', function (Blueprint $table): void {
            $table->ulid('fan_id')->primary();
            $table->string('reason', 40);
            $table->timestamp('frozen_at');
            $table->timestamp('lifted_at')->nullable();
        });

        Schema::create('wallet_preferences', function (Blueprint $table): void {
            $table->ulid('user_id')->primary();
            $table->char('display_currency', 3);
            $table->timestamp('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_preferences');
        Schema::dropIfExists('wallet_freezes');
        Schema::dropIfExists('gateway_webhooks');
        Schema::dropIfExists('topups');
    }
};
