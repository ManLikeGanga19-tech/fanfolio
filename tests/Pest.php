<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Feature tests hit PostgreSQL and Redis (run them in Docker or CI).
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', '../app/Modules/*/Tests/Feature');

// Unit and architecture tests boot the app but never touch the database.
pest()->extend(TestCase::class)
    ->in('Unit', 'Architecture', '../app/Modules/*/Tests/Unit');
