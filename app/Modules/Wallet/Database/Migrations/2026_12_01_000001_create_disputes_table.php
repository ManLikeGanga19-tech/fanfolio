<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M3 PR 6: chargebacks and disputes (D-20, canvas AdminDisputes). A new table, plus
 * nullable columns on three small Wallet tables (no rewrite, no long lock):
 * - earning_holds.dispute_id: the dispute that clawed a sale back;
 * - gateway_webhooks.case_ref and details: the dispute fields a chargeback event carries;
 * - topups.ip and known_device: evidence for a dispute, recorded when the top-up starts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('topup_id')->index();
            $table->ulid('fan_id')->index();
            $table->string('gateway', 30);
            // The processor's case number ("CB-2291"), shown on the board.
            $table->string('gateway_case_ref', 100)->unique();
            $table->string('reason', 30);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            // 3-D Secure as the processor reports it: passed, failed, not_used (null when not reported).
            $table->string('three_ds', 20)->nullable();
            $table->timestamp('respond_by');
            $table->string('status', 15)->default('open');
            // What D-20 took back when the chargeback arrived, so a won dispute can return it exactly.
            $table->jsonb('recovery');
            // The evidence sent to the processor: each line's text and how it reads for us.
            $table->jsonb('evidence')->nullable();
            $table->timestamp('evidence_sent_at')->nullable();
            $table->ulid('evidence_sent_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->ulid('accepted_by')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['status', 'respond_by']);
            $table->index('received_at');
        });

        DB::statement("ALTER TABLE disputes ADD CONSTRAINT disputes_status_check CHECK (status IN ('open', 'evidence_sent', 'won', 'lost', 'accepted'))");
        DB::statement('ALTER TABLE disputes ADD CONSTRAINT disputes_amount_check CHECK (amount_minor > 0)');

        Schema::table('earning_holds', function (Blueprint $table): void {
            $table->ulid('dispute_id')->nullable();
        });

        Schema::table('gateway_webhooks', function (Blueprint $table): void {
            $table->string('case_ref', 100)->nullable();
            $table->jsonb('details')->nullable();
        });

        Schema::table('topups', function (Blueprint $table): void {
            $table->ipAddress('ip')->nullable();
            // Started from a device registered on the account for more than 7 days.
            $table->boolean('known_device')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('topups', function (Blueprint $table): void {
            $table->dropColumn(['ip', 'known_device']);
        });

        Schema::table('gateway_webhooks', function (Blueprint $table): void {
            $table->dropColumn(['case_ref', 'details']);
        });

        Schema::table('earning_holds', function (Blueprint $table): void {
            $table->dropColumn('dispute_id');
        });

        Schema::dropIfExists('disputes');
    }
};
