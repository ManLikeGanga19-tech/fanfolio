<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan M8a PR 4: moderation cases and the reports that open them. Account and user ids
 * belong to Identity: no foreign keys across modules. Reporters stay anonymous to staff;
 * the free-text details are encrypted by the model. New tables only, so the deploy is
 * safe without downtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_cases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // The account the case is about, and whether it was reported as a creator page.
            $table->ulid('target_user_id');
            $table->string('target_kind', 20);
            $table->string('source', 20);
            $table->string('reason', 20);
            $table->boolean('urgent')->default(false);
            // Plan M8a use case 29: the 1-hour target for anything that may involve a minor.
            $table->timestamp('due_at')->nullable();
            $table->string('status', 20)->default('open');
            $table->unsignedInteger('report_count')->default(0);
            $table->ulid('opened_by')->nullable();
            $table->ulid('assigned_to')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->string('decision', 20)->nullable();
            $table->text('decision_note')->nullable();
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'urgent', 'created_at']);
            $table->index(['target_user_id', 'status']);
        });

        // "Case #4821" on the board: a display number only. URLs and the API use the ULID.
        DB::statement('ALTER TABLE moderation_cases ADD COLUMN number bigint GENERATED ALWAYS AS IDENTITY (START WITH 1000) UNIQUE');
        DB::statement("ALTER TABLE moderation_cases ADD CONSTRAINT moderation_cases_status_check CHECK (status IN ('open', 'escalated', 'closed'))");
        DB::statement("ALTER TABLE moderation_cases ADD CONSTRAINT moderation_cases_source_check CHECK (source IN ('hash_match', 'ai_flag', 'user_report', 'staff_flag', 'consent_withdrawn', 'leak'))");
        DB::statement("ALTER TABLE moderation_cases ADD CONSTRAINT moderation_cases_kind_check CHECK (target_kind IN ('account', 'creator_page'))");
        DB::statement("ALTER TABLE moderation_cases ADD CONSTRAINT moderation_cases_decision_check CHECK (decision IS NULL OR decision IN ('no_action', 'warn', 'strike', 'suspend', 'escalate'))");

        Schema::create('reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('case_id')->constrained('moderation_cases')->cascadeOnDelete();
            // Never shown to staff (plan M8a use case 26).
            $table->ulid('reporter_id');
            $table->ulid('target_user_id');
            $table->string('reason', 20);
            $table->text('details')->nullable();
            $table->timestamp('created_at');

            $table->index(['reporter_id', 'target_user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_reason_check CHECK (reason IN ('minor', 'no_consent', 'leak', 'spam', 'other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
        Schema::dropIfExists('moderation_cases');
    }
};
