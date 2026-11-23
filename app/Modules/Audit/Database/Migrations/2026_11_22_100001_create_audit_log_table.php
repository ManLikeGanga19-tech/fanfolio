<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // Actor and subject are references, not foreign keys: audit outlives the rows it describes.
            $table->ulid('actor_id')->nullable()->index();
            $table->string('action', 100)->index();
            $table->string('subject_type', 50)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->jsonb('context')->default('{}');
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('occurred_at')->useCurrent()->index();

            $table->index(['subject_type', 'subject_id']);
        });

        // SEC-12: audit_log cannot be updated or deleted, by any role.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_log_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_log is append-only';
            END;
            $$;

            CREATE TRIGGER audit_log_no_update_delete
                BEFORE UPDATE OR DELETE ON audit_log
                FOR EACH ROW EXECUTE FUNCTION audit_log_append_only();

            CREATE TRIGGER audit_log_no_truncate
                BEFORE TRUNCATE ON audit_log
                FOR EACH STATEMENT EXECUTE FUNCTION audit_log_append_only();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_log_append_only()');
    }
};
