<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Each milestone adds its module seeders to the list below. Seeds are realistic but obviously
 * fake: no real names, faces or adult media (brief: working loop, step 4).
 */
final class DatabaseSeeder extends Seeder
{
    /** @var list<class-string<Seeder>> */
    private const array SEEDERS = [];

    public function run(): void
    {
        $this->call(self::SEEDERS);
    }
}
