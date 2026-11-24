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
        Schema::create('creators', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // The Identity account. No foreign key: modules do not reach into each other's tables.
            $table->ulid('user_id')->unique();
            $table->rawColumn('handle', 'citext')->nullable()->unique();
            $table->string('display_name', 50)->nullable();
            $table->string('category', 30)->nullable();
            $table->boolean('is_adult')->default(false);
            $table->string('kyc_status', 20)->default('not_started');
            $table->string('status', 20)->default('onboarding');
            // Set in M5 (subscription pricing). Integer minor units.
            $table->bigInteger('subscription_price_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'category']);
        });

        DB::statement("ALTER TABLE creators ADD CONSTRAINT creators_status_check CHECK (status IN ('onboarding', 'submitted', 'active', 'suspended'))");
        DB::statement("ALTER TABLE creators ADD CONSTRAINT creators_kyc_status_check CHECK (kyc_status IN ('not_started', 'pending', 'review', 'approved', 'action_needed', 'rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('creators');
    }
};
