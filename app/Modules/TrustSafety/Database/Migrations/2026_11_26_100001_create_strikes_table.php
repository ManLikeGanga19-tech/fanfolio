<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Plan M8a use case 19: strikes on an account. A removed strike keeps its row (who
// removed it and when); the reasons for both live in the audit log.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strikes', static function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('user_id');
            $table->string('reason', 32);
            $table->ulid('given_by');
            $table->timestamp('created_at');
            $table->timestamp('removed_at')->nullable();
            $table->ulid('removed_by')->nullable();

            $table->index(['user_id', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strikes');
    }
};
