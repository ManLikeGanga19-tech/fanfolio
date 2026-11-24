<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff review of a submitted application (decision D-10): who decided and when, and
 * what the creator must fix ("One thing to fix", board 8). Additive and nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->string('fix_kind', 20)->nullable();
            $table->string('fix_note', 300)->nullable();
            // The staff account (Identity). No foreign key: modules do not reach into each other's tables.
            $table->ulid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
        });

        DB::statement("ALTER TABLE creators ADD CONSTRAINT creators_fix_kind_check CHECK (fix_kind IS NULL OR fix_kind IN ('retake_id', 'payout', 'profile'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE creators DROP CONSTRAINT IF EXISTS creators_fix_kind_check');

        Schema::table('creators', function (Blueprint $table): void {
            $table->dropColumn(['fix_kind', 'fix_note', 'reviewed_by', 'reviewed_at']);
        });
    }
};
