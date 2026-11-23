<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a creator's money goes (onboarding step 4). The rail details and the name on
 * the account are encrypted by the model (rule 11, SEC-10); staff screens show only
 * display_hint (rule 13). Rows are never deleted: a removed method gets revoked_at, so
 * a past payout can always be traced to the method it used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_methods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('creator_id')->constrained('creators')->cascadeOnDelete();
            $table->string('rail', 10);
            $table->text('details');
            $table->text('account_name');
            $table->string('display_hint', 80);
            $table->boolean('name_matches_kyc')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['creator_id', 'revoked_at']);
        });

        DB::statement("ALTER TABLE payout_methods ADD CONSTRAINT payout_methods_rail_check CHECK (rail IN ('bank', 'usdt', 'paxum'))");
        // At most one active default per creator.
        DB::statement('CREATE UNIQUE INDEX payout_methods_one_default ON payout_methods (creator_id) WHERE is_default AND revoked_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_methods');
    }
};
