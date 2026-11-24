<?php

declare(strict_types=1);

use App\Support\Health\SystemHealth;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Admin Overview, system health: shows when the scheduler last ran.
Schedule::call(static fn (Repository $cache) => SystemHealth::beat($cache))
    ->everyMinute()
    ->name('fanfolio:heartbeat')
    ->onOneServer();
