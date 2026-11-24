<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\AccountStats;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;

// Decision D-15: times are shown in the signed-in user's own time zone, read from the
// browser and saved on the account. Stored times stay UTC.

it('saves the time zone the browser reports', function (): void {
    $user = signedInFan();

    $this->postJson('/account/timezone', ['timezone' => 'Africa/Nairobi'])->assertNoContent();

    expect($user->refresh()->timezone)->toBe('Africa/Nairobi');
});

it('refuses something that is not a time zone', function (string $zone): void {
    $user = signedInFan();

    $this->postJson('/account/timezone', ['timezone' => $zone])->assertUnprocessable();

    expect($user->refresh()->timezone)->toBeNull();
})->with(['Mars/Olympus', '', '+03:00']);

it('needs a signed-in account', function (): void {
    $this->postJson('/account/timezone', ['timezone' => 'Africa/Lagos'])->assertUnauthorized();
});

it('puts the saved zone on the page for the script to compare', function (): void {
    $user = signedInFan();
    $user->forceFill(['timezone' => 'Africa/Accra'])->save();

    $this->get('/account')->assertSee('<meta name="ff-timezone" content="Africa/Accra"', false);
});

it('shows times in the user\'s zone and goes back to UTC for the next guest', function (): void {
    $user = signedInFan();
    $user->forceFill(['timezone' => 'Africa/Nairobi'])->save();

    $this->get('/account')->assertOk();
    expect(FilamentTimezone::get())->toBe('Africa/Nairobi');

    auth()->logout();
    $this->get('/login')->assertOk();
    expect(FilamentTimezone::get())->toBe(config('app.timezone'));
});

it('counts sign-ups by the viewer\'s calendar day', function (): void {
    // 21:30 UTC on 23 Nov is 00:30 on 24 Nov in Nairobi (UTC+3).
    User::factory()->create(['created_at' => CarbonImmutable::parse('2026-11-23 21:30', 'UTC')]);
    // 20:00 UTC on 23 Nov is 23:00 on 23 Nov in Nairobi.
    User::factory()->create(['created_at' => CarbonImmutable::parse('2026-11-23 20:00', 'UTC')]);

    $stats = app(AccountStats::class);

    expect($stats->signUpsPerDay(CarbonImmutable::parse('2026-11-23', 'Africa/Nairobi'), 2))->toBe([1, 1])
        ->and($stats->signUpsPerDay(CarbonImmutable::parse('2026-11-23', 'UTC'), 2))->toBe([2, 0])
        ->and($stats->signUps(CarbonImmutable::parse('2026-11-24', 'Africa/Nairobi'), CarbonImmutable::parse('2026-11-25', 'Africa/Nairobi')))->toBe(1);
});
