<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Accounts made with "Continue with a passkey" have no password. Dropping NOT NULL
     * is a catalogue change in Postgres: no table rewrite, no long lock.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN password DROP NOT NULL');
    }

    /**
     * Passkey-only accounts get an empty password, which never matches at log in
     * (the hasher refuses empty hashes), so they still cannot sign in with one.
     */
    public function down(): void
    {
        DB::statement("UPDATE users SET password = '' WHERE password IS NULL");
        DB::statement('ALTER TABLE users ALTER COLUMN password SET NOT NULL');
    }
};
