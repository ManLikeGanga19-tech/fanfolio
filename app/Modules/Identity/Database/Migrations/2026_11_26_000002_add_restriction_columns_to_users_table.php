<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Plan M8a, Users & bans: when a suspension ends and when the account was last
// suspended or banned. Nullable, so adding them does not rewrite the table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->timestamp('suspended_until')->nullable();
            $table->timestamp('restricted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->dropColumn(['suspended_until', 'restricted_at']);
        });
    }
};
