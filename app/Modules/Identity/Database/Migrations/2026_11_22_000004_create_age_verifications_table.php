<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rule 10: only the provider's reference, the result and the date. No images,
        // no document numbers, no date of birth for the fan age check.
        Schema::create('age_verifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider_ref', 191)->unique();
            $table->string('purpose', 20);
            $table->string('method', 20);
            $table->string('status', 20)->default('pending');
            $table->string('failure_reason', 50)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('age_verifications');
    }
};
