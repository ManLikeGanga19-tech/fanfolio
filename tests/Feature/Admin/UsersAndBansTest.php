<?php

declare(strict_types=1);

use App\Modules\Creators\Contracts\CreatorEligibility;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Listeners\SuspendBannedCreator;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Contracts\AccountRestrictions;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserDevice;
use App\Modules\TrustSafety\Actions\BanAccount;
use App\Modules\TrustSafety\Actions\LiftRestriction;
use App\Modules\TrustSafety\Actions\SignOutAccount;
use App\Modules\TrustSafety\Actions\Strikes;
use App\Modules\TrustSafety\Actions\SuspendAccount;
use App\Modules\TrustSafety\Contracts\RestrictionReason;
use App\Modules\TrustSafety\Events\UserBanned;
use App\Modules\TrustSafety\Exceptions\TrustSafetyException;
use App\Modules\TrustSafety\Filament\Admin\UsersBoard;
use App\Modules\TrustSafety\Notifications\AccountDecision;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\RecordingAuditLog;

// Canvas board AdminUsers, plan M8a use cases 14–20 and 24; decision D-18.

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
    Notification::fake();
    Filament::setCurrentPanel('admin');
});

/** A fan who is signed in on one device, then the given staff member signed in. */
function fanAndStaff(string $role = 'admin'): array
{
    $fan = signedInFan();
    test()->get('/account')->assertOk(); // registers the fan's device
    auth()->logout();

    return [$fan->refresh(), signedInStaff($role)];
}

function devicesOf(User $user): int
{
    return UserDevice::query()->where('user_id', $user->id)->whereNull('revoked_at')->count();
}

it('suspends an account, signs it out everywhere and emails the category only', function (): void {
    [$fan, $admin] = fanAndStaff();
    expect(devicesOf($fan))->toBe(1);

    $until = app(SuspendAccount::class)->handle($fan->id, $admin->id, 7, RestrictionReason::Spam, 'Posted scam links in 40 DMs');

    $fan->refresh();
    expect($fan->status)->toBe(AccountStatus::Suspended)
        ->and($fan->suspended_until->getTimestamp())->toBe($until->getTimestamp())
        ->and($fan->canSignIn())->toBeFalse()
        ->and(devicesOf($fan))->toBe(0)
        ->and($this->audit->has('account.suspended', $fan->id))->toBeTrue()
        ->and($this->audit->of('account.suspended')[0]['context'])->toMatchArray(['days' => 7, 'reason' => 'spam', 'note' => 'Posted scam links in 40 DMs']);

    Notification::assertSentTo($fan, AccountDecision::class, function (AccountDecision $n) use ($fan): bool {
        $mail = $n->toMail($fan);
        $text = implode(' ', [$mail->subject, ...$mail->introLines, ...$mail->outroLines]);

        return $n->kind === AccountDecision::SUSPENDED
            && str_contains($text, 'Spam or scams')
            && ! str_contains($text, 'scam links in 40 DMs');
    });
});

it('refuses sign-in while suspended and lets the account back when it ends', function (): void {
    [$fan, $admin] = fanAndStaff();
    app(SuspendAccount::class)->handle($fan->id, $admin->id, 1, RestrictionReason::Spam, 'Spam');
    auth()->logout();

    $this->post('/login', ['email' => $fan->email, 'password' => 'password-for-tests-only'])->assertSessionHasErrors('email');

    $this->travel(25)->hours();
    expect($fan->refresh()->canSignIn())->toBeTrue();

    $this->artisan('identity:end-suspensions')->assertSuccessful();
    expect($fan->refresh()->status)->toBe(AccountStatus::Active)
        ->and($this->audit->has('account.suspension_ended', $fan->id))->toBeTrue();
});

it('signs a restricted account out on its next request', function (): void {
    [$fan, $admin] = fanAndStaff();
    app(AccountRestrictions::class)->suspend($fan->id, now()->addDay()->toImmutable());

    auth()->logout();
    actAs($fan->refresh());
    $this->get('/account')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('lets moderators suspend for up to 7 days only (D-18)', function (): void {
    [$fan, $moderator] = fanAndStaff('moderator');

    expect(fn () => app(SuspendAccount::class)->handle($fan->id, $moderator->id, 30, RestrictionReason::Spam, 'Spam'))
        ->toThrow(TrustSafetyException::class, 'up to 7 days');

    app(SuspendAccount::class)->handle($fan->id, $moderator->id, 7, RestrictionReason::Spam, 'Spam');
    expect($fan->refresh()->status)->toBe(AccountStatus::Suspended);
});

it('lets only admins ban and lift (D-18)', function (): void {
    [$fan, $moderator] = fanAndStaff('moderator');

    expect(fn () => app(BanAccount::class)->handle($fan->id, $moderator->id, RestrictionReason::Leak, 'Leaked'))
        ->toThrow(TrustSafetyException::class, 'Your role cannot do this.');

    app(SuspendAccount::class)->handle($fan->id, $moderator->id, 1, RestrictionReason::Spam, 'Spam');

    expect(fn () => app(LiftRestriction::class)->handle($fan->id, $moderator->id, 'Mistake'))
        ->toThrow(TrustSafetyException::class, 'Your role cannot do this.');
});

it('bans: blocks sign-in, stops a creator earning, suspends the page and tells other modules', function (): void {
    Event::fake([UserBanned::class]);
    [$fan, $admin] = fanAndStaff();
    $creator = Creator::factory()->approved()->create(['user_id' => $fan->id]);
    expect(app(CreatorEligibility::class)->canEarn($fan->id))->toBeTrue();

    app(BanAccount::class)->handle($fan->id, $admin->id, RestrictionReason::Leak, 'Leaked paid content to Telegram');

    expect($fan->refresh()->status)->toBe(AccountStatus::Banned)
        ->and($fan->canSignIn())->toBeFalse()
        ->and(devicesOf($fan))->toBe(0)
        ->and(app(CreatorEligibility::class)->canEarn($fan->id))->toBeFalse()
        ->and($this->audit->has('account.banned', $fan->id))->toBeTrue();

    Event::assertDispatched(UserBanned::class, fn (UserBanned $e): bool => $e->userId === $fan->id && $e->reason === 'leak');

    // The Creators listener, run for real.
    app(SuspendBannedCreator::class)->handle(new UserBanned($fan->id, $admin->id, 'leak'));
    expect($creator->refresh()->status)->toBe(CreatorStatus::Suspended)
        ->and($this->audit->has('creator.suspended', $creator->id))->toBeTrue();

    Notification::assertSentTo($fan, AccountDecision::class, fn (AccountDecision $n): bool => $n->kind === AccountDecision::BANNED);
});

it('lifts a ban', function (): void {
    [$fan, $admin] = fanAndStaff();
    app(BanAccount::class)->handle($fan->id, $admin->id, RestrictionReason::Spam, 'Spam');

    app(LiftRestriction::class)->handle($fan->id, $admin->id, 'Appeal accepted');

    expect($fan->refresh()->status)->toBe(AccountStatus::Active)
        ->and($this->audit->has('account.restriction_lifted', $fan->id))->toBeTrue();
    Notification::assertSentTo($fan, AccountDecision::class, fn (AccountDecision $n): bool => $n->kind === AccountDecision::LIFTED);
});

it('never acts on the staff member themselves or on other staff', function (): void {
    $admin = signedInStaff();
    $other = User::factory()->withRole(Role::Moderator)->withMfa()->create();

    expect(fn () => app(SuspendAccount::class)->handle($admin->id, $admin->id, 1, RestrictionReason::Spam, 'Test'))
        ->toThrow(TrustSafetyException::class, 'your own account')
        ->and(fn () => app(BanAccount::class)->handle($other->id, $admin->id, RestrictionReason::Spam, 'Test'))
        ->toThrow(TrustSafetyException::class, 'Staff board');
});

it('needs a written reason', function (): void {
    [$fan, $admin] = fanAndStaff();

    expect(fn () => app(SuspendAccount::class)->handle($fan->id, $admin->id, 1, RestrictionReason::Spam, '  '))
        ->toThrow(TrustSafetyException::class, 'Write the reason.');
});

it('signs an account out everywhere', function (): void {
    [$fan, $moderator] = fanAndStaff('moderator');

    expect(app(SignOutAccount::class)->handle($fan->id, $moderator->id))->toBe(1)
        ->and(devicesOf($fan))->toBe(0)
        ->and($fan->refresh()->status)->toBe(AccountStatus::Active)
        ->and($this->audit->has('account.signed_out_everywhere', $fan->id))->toBeTrue();
});

it('gives and removes strikes; three flag the account', function (): void {
    [$fan, $moderator] = fanAndStaff('moderator');
    $strikes = app(Strikes::class);

    $first = $strikes->give($fan->id, $moderator->id, RestrictionReason::Harassment, 'Abusive DMs');
    $strikes->give($fan->id, $moderator->id, RestrictionReason::Harassment, 'Abusive DMs again');
    expect(Strikes::flaggedUserIds())->toBe([]);

    $strikes->give($fan->id, $moderator->id, RestrictionReason::Spam, 'Spam');
    expect(Strikes::flaggedUserIds())->toBe([$fan->id])
        ->and(Strikes::activeCount($fan->id))->toBe(3);

    $strikes->remove($first->id, $moderator->id, 'Reporter withdrew');
    expect(Strikes::activeCount($fan->id))->toBe(2)
        ->and(Strikes::flaggedUserIds())->toBe([])
        ->and($this->audit->has('strike.removed', $fan->id))->toBeTrue();

    Notification::assertSentToTimes($fan, AccountDecision::class, 3);
});

it('shows the board to moderators and admins and keeps fans out', function (): void {
    signedInFan();
    $this->get('/admin/accounts')->assertForbidden();

    auth()->logout();
    signedInStaff('moderator');
    $this->get('/admin/accounts')->assertOk()->assertSee('Users &amp; bans', false)->assertSee('banned this month');
});

it('searches and filters, and shows the detail pane without personal data', function (): void {
    [$fan, $admin] = fanAndStaff();
    $banned = User::factory()->withStatus(AccountStatus::Banned)->create(['username' => 'spam_2041', 'restricted_at' => now()]);

    Livewire::test(UsersBoard::class)
        ->assertSee('@'.$fan->username)
        ->call('setFilter', 'banned')
        ->assertSee('@spam_2041')->assertDontSee('@'.$fan->username)
        ->call('setFilter', 'all')
        ->set('search', $fan->email)
        ->assertSee('@'.$fan->username)->assertDontSee('@spam_2041')
        ->call('select', $fan->id)
        ->assertSee('Active devices')
        ->assertSee('1 of 3')
        // Rule 13: staff never see the email address.
        ->assertDontSee($fan->email);

    expect($banned->id)->not->toBeEmpty();
});

it('runs the decisions from the board, with the role\'s buttons only', function (): void {
    [$fan, $moderator] = fanAndStaff('moderator');

    Livewire::test(UsersBoard::class)
        ->call('select', $fan->id)
        ->assertActionHidden('ban')
        ->assertActionVisible('suspend')
        ->set('days', 7)
        ->callAction('suspend')
        ->assertHasErrors('reason')
        ->set('reason', 'spam')
        ->set('note', 'Posted scam links')
        ->callAction('suspend')
        ->assertNotified('Suspended.');

    expect($fan->refresh()->status)->toBe(AccountStatus::Suspended);
});

it('lists flagged accounts', function (): void {
    [$fan, $admin] = fanAndStaff();
    foreach (range(1, 3) as $i) {
        app(Strikes::class)->give($fan->id, $admin->id, RestrictionReason::Spam, "Spam {$i}");
    }
    $other = User::factory()->create();

    Livewire::test(UsersBoard::class)
        ->call('setFilter', 'flagged')
        ->assertSee('@'.$fan->username)
        ->assertSee('Flagged · 3 strikes')
        ->assertDontSee('@'.$other->username);
});

it('removes a strike from the board', function (): void {
    [$fan, $admin] = fanAndStaff();
    $strike = app(Strikes::class)->give($fan->id, $admin->id, RestrictionReason::Spam, 'Spam');

    Livewire::test(UsersBoard::class)
        ->call('select', $fan->id)
        ->set('note', 'Given by mistake')
        ->callAction(TestAction::make('removeStrike')->arguments(['strike' => $strike->id]))
        ->assertNotified('Strike removed.');

    expect(Strikes::activeCount($fan->id))->toBe(0);
});
