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
        // SEC-05: at most 3 active devices per account; any of them can be signed out remotely.
        Schema::create('user_devices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            // Keyed hash of a random device token kept in an HttpOnly cookie. No browser fingerprinting.
            $table->char('device_fingerprint_hash', 64);
            $table->string('label', 100);
            $table->ipAddress('last_ip')->nullable();
            // Filled only once a location provider is chosen; never guessed.
            $table->string('last_city', 100)->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });

        // One active row per device per account.
        DB::statement('CREATE UNIQUE INDEX user_devices_active_unique ON user_devices (user_id, device_fingerprint_hash) WHERE revoked_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};
