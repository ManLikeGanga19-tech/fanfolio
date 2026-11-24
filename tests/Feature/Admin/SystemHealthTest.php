<?php

declare(strict_types=1);

use App\Support\Health\HealthCheck;
use App\Support\Health\SystemHealth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Plan M8a use case 2: the "System health" card on the admin Overview.

/** @return array<string, HealthCheck> */
function healthByTitle(): array
{
    $checks = [];
    foreach (app(SystemHealth::class)->checks() as $check) {
        $checks[$check->title] = $check;
    }

    return $checks;
}

it('records each scheduler run', function (): void {
    $event = collect(app(Schedule::class)->events())->first(static fn ($e): bool => $e->description === 'fanfolio:heartbeat');

    expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *');

    $event->run(app());

    expect(healthByTitle()['Scheduler']->level)->toBe(HealthCheck::OK);
});

it('warns when the scheduler has never run, and flags it when it stops', function (): void {
    Cache::forget(SystemHealth::HEARTBEAT_KEY);
    expect(healthByTitle()['Scheduler']->level)->toBe(HealthCheck::WARNING);

    SystemHealth::beat(Cache::store());
    $this->travel(10)->minutes();

    expect(healthByTitle()['Scheduler'])
        ->level->toBe(HealthCheck::PROBLEM)
        ->detail->toBe('Last ran 10 minutes ago');
});

it('reads the heartbeat as Redis returns it, a numeric string', function (): void {
    Cache::forever(SystemHealth::HEARTBEAT_KEY, (string) now()->subMinute()->getTimestamp());

    expect(healthByTitle()['Scheduler'])
        ->level->toBe(HealthCheck::OK)
        ->detail->toBe('Last ran 1 minute ago');
});

it('counts failed jobs', function (): void {
    expect(healthByTitle()['Failed jobs']->detail)->toBe('None');

    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(), 'connection' => 'redis', 'queue' => 'default',
        'payload' => '{}', 'exception' => 'Test', 'failed_at' => now(),
    ]);

    expect(healthByTitle()['Failed jobs'])->level->toBe(HealthCheck::WARNING);
});

it('lists the gateways still on their Fake stand-in', function (): void {
    config(['fanfolio.drivers.sms' => 'live-sms']);

    $providers = healthByTitle()['Providers'];

    expect($providers->detail)->toContain('kyc')->toContain('payout bank')->not->toContain('sms')
        ->and($providers->level)->toBe(HealthCheck::OK);
});
