<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\PlatformSettingsStore;
use App\Modules\Identity\Actions\SettingProposals;
use App\Modules\Identity\Actions\SettingValues;
use App\Modules\Identity\Contracts\PlatformSettings;
use App\Modules\Identity\Contracts\Setting;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\SettingProposal;
use App\Modules\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecordingAuditLog;

// M3 PR 7 / M8a PR 6: platform settings with two-admin approval (canvas AdminSettings,
// plan M8a use cases 35 and 42, decisions D-16 and D-24).

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
    $this->first = signedInStaff();
    $this->second = signedInStaff();
    $this->proposals = app(SettingProposals::class);
    $this->settings = app(PlatformSettings::class);
});

it('reads config defaults until an admin changes a setting', function (): void {
    config(['fanfolio.network_risk.refuse.topups' => false, 'fanfolio.topup.methods' => ['card']]);

    expect($this->settings->integer(Setting::PayoutMinimum))->toBe(2_000)
        ->and($this->settings->topUpPacks())->toHaveCount(4)
        ->and($this->settings->enabled(Setting::CryptoTopUps))->toBeFalse()
        ->and($this->settings->enabled(Setting::VpnTopUps))->toBeFalse()
        ->and($this->settings->enabled(Setting::VpnStaffSignIn))->toBeTrue()
        ->and(collect(Setting::cases())->map->label()->unique())->toHaveCount(count(Setting::cases()))
        ->and(Setting::PayoutMinimum->isSwitch())->toBeFalse()
        ->and(Setting::VpnPayouts->isSwitch())->toBeTrue();
});

it('changes a setting only when a second admin approves', function (): void {
    $proposal = $this->proposals->propose(Setting::PayoutMinimum, 1_500, $this->first->id, 'Creators asked for smaller withdrawals');

    expect($this->settings->integer(Setting::PayoutMinimum))->toBe(2_000)
        ->and($this->audit->of('settings.proposed')[0]['context'])->toBe(['setting' => 'money.payout_min_minor', 'from' => '$20', 'to' => '$15'])
        ->and(fn () => $this->proposals->approve($proposal->id, $this->first->id))->toThrow(IdentityException::class, 'A second admin');

    $this->proposals->approve($proposal->id, $this->second->id, 'Agreed in the money review');

    expect($this->settings->integer(Setting::PayoutMinimum))->toBe(1_500)
        ->and($proposal->refresh()->status)->toBe(SettingProposal::APPROVED)
        ->and($proposal->decided_by)->toBe($this->second->id)
        ->and($proposal->decision_note)->toBe('Agreed in the money review')
        ->and($this->audit->of('settings.changed')[0])->toMatchArray(['actor' => $this->second->id])
        ->and($this->audit->of('settings.changed')[0]['context'])->toMatchArray(['from' => '$20', 'to' => '$15', 'proposed_by' => $this->first->id])
        // The cache is cleared at once: a fresh store sees it too.
        ->and(app(PlatformSettingsStore::class)->value(Setting::PayoutMinimum))->toBe(1_500)
        ->and(fn () => $this->proposals->approve($proposal->id, $this->second->id))->toThrow(IdentityException::class, 'no longer waiting');
});

it('rejects with a note, lets the proposer withdraw, and keeps one open proposal per setting', function (): void {
    $packs = [['amount_minor' => 1_000, 'bonus_bps' => 0], ['amount_minor' => 2_000, 'bonus_bps' => 500]];
    $proposal = $this->proposals->propose(Setting::TopUpPacks, $packs, $this->first->id, 'Simpler packs');

    expect(fn () => $this->proposals->propose(Setting::TopUpPacks, [['amount_minor' => 500, 'bonus_bps' => 0]], $this->second->id, 'Other idea'))
        ->toThrow(IdentityException::class, 'already waiting')
        ->and(fn () => $this->proposals->reject($proposal->id, $this->second->id, ' '))->toThrow(IdentityException::class, 'note')
        ->and(fn () => $this->proposals->reject($proposal->id, $this->first->id, 'Mine'))->toThrow(IdentityException::class, 'A second admin');

    $this->proposals->reject($proposal->id, $this->second->id, 'Keep the canvas packs');

    expect($proposal->refresh()->status)->toBe(SettingProposal::REJECTED)
        ->and($this->settings->topUpPacks())->toHaveCount(4)
        ->and($this->audit->of('settings.rejected')[0]['context'])->toBe(['setting' => 'topup.packs', 'note' => 'Keep the canvas packs']);

    $again = $this->proposals->propose(Setting::CryptoTopUps, false, $this->first->id, 'Gateway paused');
    expect(fn () => $this->proposals->withdraw($again->id, $this->second->id))->toThrow(IdentityException::class, 'Only the admin who proposed');

    $this->proposals->withdraw($again->id, $this->first->id);
    expect($again->refresh()->status)->toBe(SettingProposal::WITHDRAWN)
        ->and($this->audit->has('settings.withdrawn', $again->id))->toBeTrue();

    // The database also keeps one pending proposal per setting.
    $this->proposals->propose(Setting::VpnTopUps, false, $this->first->id, 'Testing a provider');
    expect(fn () => DB::table('settings_proposals')->insert([
        'id' => strtolower((string) Str::ulid()), 'key' => 'network_risk.topups', 'current_value' => 'true', 'proposed_value' => 'false',
        'reason' => 'x', 'proposed_by' => $this->first->id, 'proposed_at' => now(), 'status' => 'pending',
    ]))->toThrow(QueryException::class);
});

it('refuses approval when the setting changed since it was proposed', function (): void {
    $stale = $this->proposals->propose(Setting::PayoutMinimum, 1_500, $this->first->id, 'Smaller');
    // Another change got through first (by any route).
    app(PlatformSettingsStore::class)->put(Setting::PayoutMinimum, 2_500, $this->second->id);

    expect(fn () => $this->proposals->approve($stale->id, $this->second->id))->toThrow(IdentityException::class, 'changed since it was proposed')
        ->and($this->settings->integer(Setting::PayoutMinimum))->toBe(2_500);
});

it('refuses values that would be mistakes, and no-change proposals', function (Setting $setting, mixed $value, string $message): void {
    expect(fn () => $this->proposals->propose($setting, $value, $this->first->id, 'Testing limits'))->toThrow(IdentityException::class, $message);
})->with([
    'minimum below $1' => [Setting::PayoutMinimum, 99, 'between $1 and $1,000'],
    'minimum over the review line' => [Setting::PayoutMinimum, 100_001, 'between $1 and $1,000'],
    'minimum not a number' => [Setting::PayoutMinimum, '15', 'between $1 and $1,000'],
    'no packs' => [Setting::TopUpPacks, [], 'one to six packs'],
    'seven packs' => [Setting::TopUpPacks, array_map(static fn (int $i): array => ['amount_minor' => $i * 100, 'bonus_bps' => 0], range(1, 7)), 'one to six packs'],
    'not a list' => [Setting::TopUpPacks, ['a' => ['amount_minor' => 500, 'bonus_bps' => 0]], 'one to six packs'],
    'cents in a pack' => [Setting::TopUpPacks, [['amount_minor' => 550, 'bonus_bps' => 0]], 'one to six packs'],
    'not rising' => [Setting::TopUpPacks, [['amount_minor' => 1_000, 'bonus_bps' => 0], ['amount_minor' => 500, 'bonus_bps' => 0]], 'one to six packs'],
    'over $1,000' => [Setting::TopUpPacks, [['amount_minor' => 100_100, 'bonus_bps' => 0]], 'one to six packs'],
    'bonus over 50%' => [Setting::TopUpPacks, [['amount_minor' => 500, 'bonus_bps' => 5_100]], 'one to six packs'],
    'fractional bonus' => [Setting::TopUpPacks, [['amount_minor' => 500, 'bonus_bps' => 150]], 'one to six packs'],
    'negative bonus' => [Setting::TopUpPacks, [['amount_minor' => 500, 'bonus_bps' => -100]], 'one to six packs'],
    'pack missing a field' => [Setting::TopUpPacks, [['amount_minor' => 500]], 'one to six packs'],
    'pack not an array' => [Setting::TopUpPacks, [500], 'one to six packs'],
    'switch not a bool' => [Setting::VpnPayouts, 'off', 'Choose on or off'],
    'the same value' => [Setting::PayoutMinimum, 2_000, 'already set to that'],
]);

it('needs an admin with MFA, and a reason', function (): void {
    $moderator = User::factory()->withRole(Role::Moderator)->withMfa()->create();

    expect(fn () => $this->proposals->propose(Setting::VpnTopUps, false, $moderator->id, 'Because'))->toThrow(IdentityException::class, 'Your role')
        ->and(fn () => $this->proposals->propose(Setting::VpnTopUps, false, $this->first->id, ' '))->toThrow(IdentityException::class, 'Say why')
        ->and(fn () => $this->proposals->approve('nope', $moderator->id))->toThrow(IdentityException::class, 'Your role');
});

it('describes values the way the board shows them', function (): void {
    expect(SettingValues::describe(Setting::PayoutMinimum, 1_250))->toBe('$12.50')
        ->and(SettingValues::describe(Setting::PayoutMinimum, 'x'))->toBe('$0')
        ->and(SettingValues::describe(Setting::TopUpPacks, [['amount_minor' => 500, 'bonus_bps' => 0], ['amount_minor' => 5_000, 'bonus_bps' => 1_000]]))->toBe('$5 · $50 (+10%)')
        ->and(SettingValues::describe(Setting::TopUpPacks, null))->toBe('')
        ->and(SettingValues::describe(Setting::CryptoTopUps, true))->toBe('On')
        ->and(SettingValues::describe(Setting::CryptoTopUps, false))->toBe('Off');
});

it('falls back to the default when a stored value has the wrong type', function (): void {
    DB::table('platform_settings')->insert(['key' => 'money.payout_min_minor', 'value' => '"oops"', 'updated_by' => $this->first->id, 'updated_at' => now()]);

    expect($this->settings->integer(Setting::PayoutMinimum))->toBe(2_000);
});

it('refuses staff sign-in through a VPN while the rule is on (D-16)', function (): void {
    auth()->logout();
    config(['fanfolio.fake.anonymised_ips' => ['198.51.100.20']]);
    $admin = User::factory()->withRole(Role::Admin)->create(['email' => 'staff@example.test']);
    $fan = User::factory()->create(['email' => 'fan@example.test']);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->post('/login', ['email' => 'staff@example.test', 'password' => 'password-for-tests-only'])
        ->assertSessionHasErrors(['email' => 'Turn off your VPN or proxy to sign in to a staff account.']);
    $this->assertGuest();
    expect($this->audit->of('identity.login_refused')[0]['context'])->toBe(['reason' => 'vpn']);

    // Fans may sign in behind one.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->post('/login', ['email' => 'fan@example.test', 'password' => 'password-for-tests-only']);
    $this->assertAuthenticatedAs($fan);
    auth()->logout();

    // With the rule switched off by two admins, staff may too.
    app(PlatformSettingsStore::class)->put(Setting::VpnStaffSignIn, false, $this->second->id);
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
        ->post('/login', ['email' => 'staff@example.test', 'password' => 'password-for-tests-only']);
    $this->assertAuthenticatedAs($admin);
});
