<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Passkeys. Columns follow laragear/webauthn's model, with ULID owner keys.
        Schema::create('webauthn_credentials', function (Blueprint $table): void {
            // The credential id the authenticator generated (base64url).
            $table->string('id', 510)->primary();
            $table->ulidMorphs('authenticatable', 'webauthn_user_index');
            // Anonymous user handle shared by all of a user's passkeys.
            $table->uuid('user_id');
            $table->string('alias')->nullable();
            $table->unsignedBigInteger('counter')->nullable();
            $table->string('rp_id');
            $table->string('origin');
            $table->json('transports')->nullable();
            $table->uuid('aaguid')->nullable();
            $table->text('public_key');
            $table->string('attestation_format')->default('none');
            $table->json('certificates')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });

        // Authenticator app (TOTP). The secret is encrypted; the last accepted time step
        // blocks a code from being used twice.
        Schema::create('mfa_totp', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('secret');
            $table->unsignedBigInteger('last_used_timestep')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mfa_recovery_codes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_recovery_codes');
        Schema::dropIfExists('mfa_totp');
        Schema::dropIfExists('webauthn_credentials');
    }
};
