<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan M8a use case 11: a moderator's pause of up to 7 days ends by itself at
 * suspended_until; an admin's suspension has none. Additive and nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->timestamp('suspended_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('creators', function (Blueprint $table): void {
            $table->dropColumn('suspended_until');
        });
    }
};
