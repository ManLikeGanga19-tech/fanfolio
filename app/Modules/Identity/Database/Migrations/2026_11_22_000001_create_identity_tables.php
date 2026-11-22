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
        Schema::create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // citext: Kevin@Example.test and kevin@example.test are the same account.
            $table->rawColumn('email', 'citext')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->rawColumn('username', 'citext')->unique();
            $table->string('password');
            // SEC-10: the number is encrypted; the keyed hash makes it unique and searchable.
            $table->text('phone_e164')->nullable();
            $table->char('phone_hash', 64)->nullable()->unique();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('role', 16)->default('fan');
            // Soft delete only through status (spec data model).
            $table->string('status', 16)->default('active');
            $table->boolean('mfa_enabled')->default(false);
            $table->timestamp('age_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('fan', 'creator', 'moderator', 'admin'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'paused', 'suspended', 'banned'))");

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->rawColumn('email', 'citext')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignUlid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('phone_verifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('phone_e164');
            $table->char('phone_hash', 64);
            $table->char('code_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_verifications');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
