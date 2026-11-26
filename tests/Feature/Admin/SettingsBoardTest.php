<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\SettingProposals;
use App\Modules\Identity\Contracts\PlatformSettings;
use App\Modules\Identity\Contracts\Setting;
use App\Modules\Identity\Filament\Admin\SettingsBoard;
use App\Modules\Identity\Models\SettingProposal;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\RecordingAuditLog;

// Canvas board AdminSettings (plan M8a use cases 34, 35 and 42; M3 PR 7; D-16, D-24).

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $this->audit = RecordingAuditLog::swap();
});

it('is for admins only, and shows the locked values read-only', function (): void {
    $admin = signedInStaff('admin');
    signedInStaff('moderator');
    $this->get('/admin/settings')->assertForbidden();

    actAsUserId($admin->id);
    $this->get('/admin/settings')->assertOk()
        ->assertSee('Fees &amp; settings', false)
        ->assertSee('Money and safety settings need a second admin to approve before they take effect.')
        ->assertSeeInOrder(['Platform fee', '20%', 'Locked by the brief', 'Earnings hold', '7 days', 'Minimum withdrawal', 'Manual review above', '$1,000'])
        ->assertSeeInOrder(['Credit value', '100 credits = $1.00', 'Top-up packs (USD)', 'Bonus per pack (%)'])
        ->assertSeeInOrder(['Adult content', 'Crypto top-ups (USDT)', 'VPN at staff sign-in', 'VPN for creator ID checks', 'VPN for withdrawals', 'VPN for top-ups', 'Devices per account', 'Live streaming', 'Phase 3'])
        ->assertSee('Request approval')
        ->assertSee('No setting has been changed yet.');
});

it('fills the form with the current settings', function (): void {
    signedInStaff();

    Livewire::test(SettingsBoard::class)
        ->assertSet('payoutMinimum', '20')
        ->assertSet('packAmounts', '5 · 10 · 25 · 50')
        ->assertSet('packBonuses', '0 · 0 · 4 · 10')
        ->assertSet('switches.crypto', true)
        ->assertSet('switches.vpnTopUps', true);
});

it('sends every edited setting for approval with one reason, and changes nothing yet', function (): void {
    $admin = signedInStaff();

    Livewire::test(SettingsBoard::class)
        ->set('payoutMinimum', '15')
        ->set('packAmounts', '10, 25, 50')
        ->set('packBonuses', '0, 5, 10')
        ->set('switches.crypto', false)
        ->callAction('requestApproval', ['reason' => 'Simpler packs, smaller withdrawals'])
        ->assertNotified('3 changes sent for approval.')
        ->assertSee(['Pending', 'minimum withdrawal $20 → $15', 'Waiting for another admin.', 'Why: Simpler packs, smaller withdrawals', 'Withdraw']);

    expect(SettingProposal::query()->where('proposed_by', $admin->id)->pluck('key')->map->value->sort()->values()->all())
        ->toBe(['money.payout_min_minor', 'topup.crypto', 'topup.packs'])
        ->and(app(PlatformSettings::class)->integer(Setting::PayoutMinimum))->toBe(2_000)
        ->and(SettingsBoard::getNavigationBadge())->toBe('3')
        ->and(SettingsBoard::getNavigationBadgeTooltip())->toBe('Waiting for a second admin');
});

it('lets a second admin approve or reject, and the proposer withdraw', function (): void {
    $first = signedInStaff();
    $proposals = app(SettingProposals::class);
    $minimum = $proposals->propose(Setting::PayoutMinimum, 1_500, $first->id, 'Smaller withdrawals');
    $vpn = $proposals->propose(Setting::VpnTopUps, false, $first->id, 'Provider is flaky');
    $crypto = $proposals->propose(Setting::CryptoTopUps, false, $first->id, 'Gateway paused');

    Livewire::test(SettingsBoard::class)
        ->callAction('withdraw', [], ['proposal' => $crypto->id])
        ->assertNotified('Withdrawn.');

    signedInStaff();
    Livewire::test(SettingsBoard::class)
        ->assertSee(['minimum withdrawal $20 → $15', 'Waiting for your approval.', 'Approve', 'Reject'])
        ->callAction('approve', ['note' => 'Agreed'], ['proposal' => $minimum->id])
        ->assertNotified('Approved. The new setting is live.')
        ->assertSet('payoutMinimum', '15')
        ->callAction('reject', ['note' => ''], ['proposal' => $vpn->id])
        ->assertHasActionErrors(['note' => 'required']);

    Livewire::test(SettingsBoard::class)
        ->callAction('reject', ['note' => 'Keep it on until a provider is chosen'], ['proposal' => $vpn->id])
        ->assertNotified('Rejected. Nothing changed.')
        ->assertSee(['Recent decisions', 'Minimum withdrawal', '$20 → $15', 'Approved by', 'Rejected by', 'Keep it on until a provider is chosen', 'Withdrawn by']);

    expect(app(PlatformSettings::class)->integer(Setting::PayoutMinimum))->toBe(1_500)
        ->and(app(PlatformSettings::class)->enabled(Setting::VpnTopUps))->toBeTrue();
});

it('says when nothing changed, when input is not valid, and when a decision is refused', function (): void {
    $first = signedInStaff();

    Livewire::test(SettingsBoard::class)
        ->callAction('requestApproval', ['reason' => 'Nothing really'])
        ->assertNotified('Nothing changed. Edit a setting first.');

    Livewire::test(SettingsBoard::class)
        ->set('payoutMinimum', 'twenty')
        ->set('switches.vpnPayouts', false)
        ->callAction('requestApproval', ['reason' => 'Typo test'])
        ->assertHasErrors('money.payout_min_minor')
        ->assertSee('Enter a minimum withdrawal between $1 and $1,000.');
    // One bad value sends nothing.
    expect(SettingProposal::query()->count())->toBe(0);

    Livewire::test(SettingsBoard::class)
        ->set('packAmounts', '5, 10')
        ->set('packBonuses', '0')
        ->callAction('requestApproval', ['reason' => 'Mismatch'])
        ->assertHasErrors('topup.packs');

    Livewire::test(SettingsBoard::class)
        ->set('packAmounts', '5, ten')
        ->callAction('requestApproval', ['reason' => 'Letters'])
        ->assertHasErrors('topup.packs');

    // Your own proposal cannot be approved by you, even by forcing the action.
    $own = app(SettingProposals::class)->propose(Setting::VpnPayouts, false, $first->id, 'Testing');
    Livewire::test(SettingsBoard::class)
        ->callAction('approve', [], ['proposal' => $own->id])
        ->assertHasErrors('proposal')
        ->assertNotified('A second admin has to decide on your own proposal.');

    expect(SettingsBoard::cents('12.5'))->toBe(1_250)
        ->and(SettingsBoard::cents('$1,000'))->toBe(100_000)
        ->and(SettingsBoard::numbers(' 5 · 10, 25 / 50 '))->toBe([5, 10, 25, 50]);
});

it('puts an approved change into effect where fans and creators meet it', function (): void {
    $first = signedInStaff();
    $proposals = app(SettingProposals::class);
    $packs = $proposals->propose(Setting::TopUpPacks, [['amount_minor' => 1_000, 'bonus_bps' => 0], ['amount_minor' => 2_000, 'bonus_bps' => 500]], $first->id, 'Two packs');
    $crypto = $proposals->propose(Setting::CryptoTopUps, false, $first->id, 'Gateway paused');
    $second = signedInStaff();
    $proposals->approve($packs->id, $second->id);
    $proposals->approve($crypto->id, $second->id);

    signedInFan();
    $this->get('/wallet')->assertOk()
        ->assertSeeInOrder(['1,000 credits', '$10', '2,100 credits', '$20', '+5%'])
        ->assertDontSee('5,500 credits')
        ->assertDontSee('Crypto (USDT)');

    $this->post('/wallet/topups', ['pack' => 1, 'method' => 'crypto', 'idempotency_key' => 'k1'])
        ->assertSessionHasErrors(['method' => 'That payment method is not available right now.']);
});
