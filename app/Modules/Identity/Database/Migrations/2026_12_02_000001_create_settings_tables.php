<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M3 PR 7 / M8a PR 6 (canvas AdminSettings, D-16, D-24): the settings admins changed,
 * and the two-admin proposals that change them. New tables only.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Only settings an admin changed have a row; the rest read their config default.
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->string('key', 60)->primary();
            $table->jsonb('value');
            $table->ulid('updated_by');
            $table->timestamp('updated_at');
        });

        Schema::create('settings_proposals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key', 60);
            // The value when proposed: approval is refused if it has changed since.
            $table->jsonb('current_value');
            $table->jsonb('proposed_value');
            $table->string('reason', 500);
            $table->ulid('proposed_by');
            $table->timestamp('proposed_at');
            $table->string('status', 10)->default('pending');
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();

            $table->index(['status', 'proposed_at']);
        });

        DB::statement("ALTER TABLE settings_proposals ADD CONSTRAINT settings_proposals_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'withdrawn'))");
        // One open proposal per setting at a time.
        DB::statement("CREATE UNIQUE INDEX settings_proposals_one_pending ON settings_proposals (key) WHERE status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('settings_proposals');
        Schema::dropIfExists('platform_settings');
    }
};
