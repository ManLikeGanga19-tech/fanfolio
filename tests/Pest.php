<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Feature tests hit PostgreSQL and Redis (run them in Docker or CI).
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Pages render without a built Vite manifest in tests.
        $this->withoutVite();
    })
    ->in('Feature', '../app/Modules/*/Tests/Feature');

// Unit and architecture tests boot the app but never touch the database.
pest()->extend(TestCase::class)
    ->in('Unit', 'Architecture', '../app/Modules/*/Tests/Unit');

// Shared helpers that module tests provide for their own feature tests.
foreach (glob(__DIR__.'/../app/Modules/*/Tests/Helpers.php') ?: [] as $helpers) {
    require_once $helpers;
}
