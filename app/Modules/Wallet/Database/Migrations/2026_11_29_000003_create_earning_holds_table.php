<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M3 PR 3: one row per sale, tracking the creator's share through the 7-day hold
 * (brief rule 3; spec "Hold released (after 7 days)"). The money itself lives in the
 * ledger; this table only says when each sale's share may move from pending to
 * earnings, and whether a dispute or a refund stopped it. New table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('earning_holds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // The sale's ledger transaction.
            $table->char('transaction_id', 26)->unique();
            $table->ulid('fan_id');
            // The creator's Identity account id.
            $table->ulid('creator_id');
            $table->string('kind', 20);
            $table->string('reference_type', 40)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->bigInteger('gross_minor');
            $table->bigInteger('creator_minor');
            $table->bigInteger('fee_minor');
            $table->char('currency', 3);
            $table->timestamp('release_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            // A dispute on the fan's payment stops the release (spec: "skipped if a chargeback is open").
            $table->boolean('frozen')->default(false);
            $table->timestamp('created_at');

            $table->index(['creator_id', 'created_at']);
            $table->index(['fan_id', 'created_at']);
        });

        DB::statement('CREATE INDEX earning_holds_due_index ON earning_holds (release_at) WHERE released_at IS NULL AND refunded_at IS NULL');
        DB::statement("ALTER TABLE earning_holds ADD CONSTRAINT earning_holds_kind_check CHECK (kind IN ('subscription', 'unlock', 'tip', 'message'))");
        DB::statement('ALTER TABLE earning_holds ADD CONSTRAINT earning_holds_amounts_check CHECK (gross_minor > 0 AND creator_minor >= 0 AND fee_minor >= 0 AND creator_minor + fee_minor = gross_minor)');
    }

    public function down(): void
    {
        Schema::dropIfExists('earning_holds');
    }
};
