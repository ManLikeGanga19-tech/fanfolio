<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per ID and selfie check with the KYC provider. Only the provider's reference,
 * the result and the date are kept: no image, selfie or full document number (rule 10).
 * Legal name and date of birth are encrypted by the model (rule 11, SEC-10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_kyc', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('creator_id')->constrained('creators')->cascadeOnDelete();
            $table->string('provider_ref', 100)->unique();
            $table->string('status', 20)->default('pending');
            $table->boolean('liveness_passed')->default(false);
            $table->boolean('age_over_18')->default(false);
            $table->string('id_type', 30)->nullable();
            $table->char('document_last4', 4)->nullable();
            $table->date('document_expires_at')->nullable();
            $table->text('legal_name')->nullable();
            $table->text('dob')->nullable();
            $table->string('failure_reason', 50)->nullable();
            $table->boolean('suspected_fraud')->default(false);
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['creator_id', 'created_at']);
        });

        DB::statement("ALTER TABLE creator_kyc ADD CONSTRAINT creator_kyc_status_check CHECK (status IN ('pending', 'passed', 'failed', 'review'))");

        // When the approved ID stops being valid (null: the document has no expiry date).
        Schema::table('creators', function (Blueprint $table): void {
            $table->timestamp('id_expires_at')->nullable();
            $table->timestamp('id_expiry_reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->dropColumn(['id_expires_at', 'id_expiry_reminded_at']);
        });

        Schema::dropIfExists('creator_kyc');
    }
};
