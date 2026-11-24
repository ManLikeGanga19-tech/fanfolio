<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan M8a use case 5: who holds a submitted application on the approvals board.
 * Additive and nullable, so it is safe for a zero-downtime deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            // The staff account (Identity). No foreign key: modules do not reach into each other's tables.
            $table->ulid('assigned_to')->nullable();
            $table->timestamp('assigned_at')->nullable();

            $table->index(['status', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->dropIndex(['status', 'submitted_at']);
            $table->dropColumn(['assigned_to', 'assigned_at']);
        });
    }
};
