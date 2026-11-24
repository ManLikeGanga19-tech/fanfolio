<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M3 PR 4: creator withdrawals (spec "Payout flow"; brief rule 3). The money is in the
 * ledger (creator_earnings → payout_clearing on request); this row tracks the request
 * through review and sending. The method is a masked snapshot; details stay in Creators.
 * New table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // The creator's Identity account id (ledger owner).
            $table->ulid('creator_id');
            $table->string('idempotency_key', 100);
            $table->ulid('payout_method_id');
            $table->string('rail', 10);
            $table->string('display_hint', 80);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 15)->default('review');
            $table->jsonb('flags')->default('[]');
            $table->string('gateway_ref', 120)->nullable()->unique();
            $table->string('failure_reason', 200)->nullable();
            $table->ulid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['creator_id', 'idempotency_key']);
            $table->index(['status', 'created_at']);
            $table->index(['creator_id', 'created_at']);
        });

        DB::statement("ALTER TABLE payouts ADD CONSTRAINT payouts_status_check CHECK (status IN ('review', 'held', 'approved', 'sending', 'sent', 'failed', 'rejected'))");
        DB::statement('ALTER TABLE payouts ADD CONSTRAINT payouts_amount_check CHECK (amount_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
