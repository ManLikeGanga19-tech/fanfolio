<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M3 PR 1: the double-entry ledger (spec "Wallet and ledger", brief rules 1–3).
 *
 * - Transactions and entries are append-only by trigger, for every database role
 *   (SEC-12): mistakes are fixed with a reversing transaction.
 * - Entries are partitioned by month from day one (spec scale note). A default
 *   partition catches anything outside the months created ahead
 *   (`wallet:ledger-partitions`).
 * - Balances are a cache written in the same database transaction as each posting and
 *   checked against the entries every night (`wallet:verify-ledger`).
 *
 * New tables only: safe for a zero-downtime deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // "user" for fans and creators (the Identity account), "platform" for FanFolio's own accounts.
            $table->string('owner_type', 20);
            $table->ulid('owner_id')->nullable();
            $table->string('type', 30);
            $table->char('currency', 3);
            $table->timestamp('created_at');
        });

        DB::statement('ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_owner_unique UNIQUE NULLS NOT DISTINCT (owner_type, owner_id, type, currency)');
        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_type_check CHECK (type IN ('fan_wallet', 'creator_pending', 'creator_earnings', 'payout_clearing', 'platform_revenue', 'platform_promotions', 'processor_clearing'))");

        Schema::create('ledger_transactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('type', 40);
            $table->string('idempotency_key', 191)->unique();
            // A replayed key must carry the same transaction; anything else is refused.
            $table->char('fingerprint', 64);
            $table->string('reference_type', 40)->nullable();
            $table->string('reference_id', 64)->nullable();
            // Ledger tag for tax lines; no tax maths until the owner confirms the rules (brief, open decisions).
            $table->string('tax_tag', 40)->nullable();
            $table->timestamp('created_at');

            $table->index(['reference_type', 'reference_id']);
        });

        DB::statement(<<<'SQL'
            CREATE TABLE ledger_entries (
                id char(26) NOT NULL,
                transaction_id char(26) NOT NULL REFERENCES ledger_transactions (id),
                account_id char(26) NOT NULL REFERENCES ledger_accounts (id),
                direction varchar(6) NOT NULL CHECK (direction IN ('debit', 'credit')),
                amount_minor bigint NOT NULL CHECK (amount_minor > 0),
                currency char(3) NOT NULL,
                created_at timestamp(0) without time zone NOT NULL,
                PRIMARY KEY (id, created_at)
            ) PARTITION BY RANGE (created_at)
            SQL);
        DB::statement('CREATE INDEX ledger_entries_account_index ON ledger_entries (account_id, id)');
        DB::statement('CREATE INDEX ledger_entries_transaction_index ON ledger_entries (transaction_id)');
        DB::statement('CREATE TABLE ledger_entries_default PARTITION OF ledger_entries DEFAULT');

        Schema::create('ledger_balances', function (Blueprint $table): void {
            $table->foreignUlid('account_id')->primary()->constrained('ledger_accounts');
            $table->bigInteger('balance_minor')->default(0);
            $table->timestamp('updated_at');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only: post a reversing transaction instead', TG_TABLE_NAME;
            END;
            $$;

            CREATE TRIGGER ledger_transactions_no_update_delete BEFORE UPDATE OR DELETE ON ledger_transactions
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
            CREATE TRIGGER ledger_transactions_no_truncate BEFORE TRUNCATE ON ledger_transactions
                FOR EACH STATEMENT EXECUTE FUNCTION ledger_append_only();
            CREATE TRIGGER ledger_entries_no_update_delete BEFORE UPDATE OR DELETE ON ledger_entries
                FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
            CREATE TRIGGER ledger_entries_no_truncate BEFORE TRUNCATE ON ledger_entries
                FOR EACH STATEMENT EXECUTE FUNCTION ledger_append_only();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_balances');
        DB::statement('DROP TABLE IF EXISTS ledger_entries');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('ledger_accounts');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_append_only()');
    }
};
