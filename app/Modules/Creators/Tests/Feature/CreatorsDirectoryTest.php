<?php

declare(strict_types=1);

use App\Modules\Creators\Actions\ApproveCreator;
use App\Modules\Creators\Actions\ReinstateCreator;
use App\Modules\Creators\Actions\RequireIdRecheck;
use App\Modules\Creators\Actions\SuspendCreator;
use App\Modules\Creators\Contracts\CreatorEligibility;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\FixKind;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Events\CreatorReinstated;
use App\Modules\Creators\Events\CreatorSuspended;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Filament\Admin\CreatorsDirectory;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Notifications\ApplicationDecision;
use App\Modules\Creators\Notifications\CreatorStatusNotice;
use App\Modules\Identity\Contracts\AccountRestrictions;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\RecordingAuditLog;

// Plan M8a use cases 9–12: Creators directory, suspend, pause, reinstate, new ID check.

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
    Notification::fake();
    Filament::setCurrentPanel('admin');
    $this->admin = signedInStaff();
});

/** A creator approved through the real screens and Action. Leaves the creator signed in. */
function liveCreator(string $name = 'Live Creator', string $handle = 'live.creator'): Creator
{
    $user = signedInFanWithMfa();
    $creator = Creator::factory()->create(['user_id' => $user->id, 'display_name' => $name, 'handle' => $handle, 'category' => 'music']);

    config(['fanfolio.fake.kyc_outcome' => 'pass']);
    test()->get((string) test()->post('/creator/kyc', ['device' => 'phone'])->headers->get('Location'));
    confirmedSecondFactor();
    test()->post('/creator/payout', ['rail' => 'bank', 'bank_name' => 'Test Bank', 'account_number' => '000011112222', 'account_name' => 'Test Person']);
    test()->post('/creator/review', ['terms' => '1', 'co_performers' => '1']);
    app(ApproveCreator::class)->handle($creator->id, test()->admin->id);

    expect($creator->refresh()->status)->toBe(CreatorStatus::Active);

    return $creator;
}

/**
 * Notifications of a class sent to an account, found by id, so the test never touches
 * Identity's model (module boundaries).
 *
 * @template T of object
 *
 * @param  class-string<T>  $class
 * @return list<T>
 */
function sentToAccount(string $userId, string $class): array
{
    $found = [];
    foreach (Notification::sentNotifications() as $byId) {
        foreach ($byId[$userId][$class] ?? [] as $sent) {
            $found[] = $sent['notification'];
        }
    }

    return $found;
}

it('lists creators by status for moderators, with masked details only', function (): void {
    $live = liveCreator();
    $moderator = signedInStaff('moderator');
    actAsUserId($moderator->id);

    $this->get('/admin/creators')
        ->assertOk()
        ->assertSee('Creators')
        ->assertSee('1 active · 0 suspended or paused')
        ->assertSee('Live Creator');

    Livewire::test(CreatorsDirectory::class)
        ->call('select', $live->id)
        ->assertSee('Live Creator · @live.creator')
        ->assertSee('Passed')
        ->assertSee('••••0000', false)
        ->assertSee('Test Bank ····2222', false)
        ->assertSee('name matches ID')
        // Rule 13: no legal name or full numbers.
        ->assertDontSee('TEST PERSON')
        ->assertDontSee('Test Person')
        ->assertDontSee('000011112222')
        ->call('setFilter', 'suspended')
        ->assertSee('No creators match.')
        ->call('setFilter', 'active')
        ->set('search', '@live')
        ->assertSee('Live Creator');
});

it('keeps fans out', function (): void {
    signedInFan();

    $this->get('/admin/creators')->assertForbidden();
});

it('lets an admin suspend from the board: reason and note required, sales stop, creator told the category', function (): void {
    Event::fake([CreatorSuspended::class]);
    $creator = liveCreator();
    actAsUserId($this->admin->id);

    Livewire::test(CreatorsDirectory::class, ['selected' => $creator->id])
        ->callAction('suspend')
        ->assertHasErrors('reason')
        ->set('reason', 'policy')
        ->callAction('suspend')
        ->assertHasErrors('note')
        ->set('note', 'Repeated off-platform payment links')
        ->callAction('suspend')
        ->assertNotified('Suspended.')
        ->assertSee('Reinstate');

    $creator->refresh();
    expect($creator->status)->toBe(CreatorStatus::Suspended)
        ->and($creator->suspended_until)->toBeNull()
        ->and(app(CreatorEligibility::class)->canEarn($creator->user_id))->toBeFalse()
        ->and($this->audit->of('creator.suspended')[0]['context'])->toMatchArray(['reason' => 'policy', 'note' => 'Repeated off-platform payment links', 'days' => null]);
    Event::assertDispatched(CreatorSuspended::class);

    $notices = sentToAccount($creator->user_id, CreatorStatusNotice::class);
    expect($notices)->toHaveCount(1);
    $mail = $notices[0]->toMail(new stdClass);
    expect(implode(' ', [$mail->subject, ...$mail->introLines]))
        ->toContain('Breaks the Creator Terms')
        ->not->toContain('off-platform');
});

it('lets moderators pause for up to 7 days but not suspend; the pause ends by itself (D-18)', function (): void {
    $creator = liveCreator();
    $moderator = signedInStaff('moderator');

    expect(fn () => app(SuspendCreator::class)->handle($creator->id, $moderator->id, 'policy', 'Spam'))
        ->toThrow(CreatorException::class, 'Your role cannot do this.')
        ->and(fn () => app(SuspendCreator::class)->handle($creator->id, $moderator->id, 'policy', 'Spam', 8))
        ->toThrow(CreatorException::class, 'A pause lasts 1 to 7 days.');

    actAsUserId($moderator->id);
    Livewire::test(CreatorsDirectory::class, ['selected' => $creator->id])
        ->set('reason', 'content')
        ->set('note', 'Waiting on a report')
        ->callAction('suspend')
        ->assertHasErrors('staff')
        ->set('note', 'Waiting on a report')
        ->set('days', 3)
        ->callAction('pause')
        ->assertNotified('Paused.')
        ->assertSee('Paused to')
        ->assertSee('Only an admin can reinstate');

    $creator->refresh();
    expect($creator->status)->toBe(CreatorStatus::Suspended)
        ->and($creator->suspended_until)->not->toBeNull()
        ->and($this->audit->of('creator.paused')[0]['context'])->toMatchArray(['days' => 3])
        ->and(app(CreatorEligibility::class)->canPublish($creator->user_id))->toBeFalse();

    $this->artisan('creators:end-pauses')->assertSuccessful();
    expect($creator->refresh()->status)->toBe(CreatorStatus::Suspended);

    $this->travel(3)->days();
    $this->travel(1)->minutes();
    $this->artisan('creators:end-pauses')->assertSuccessful();
    $this->artisan('creators:end-pauses')->assertSuccessful();

    $creator->refresh();
    expect($creator->status)->toBe(CreatorStatus::Active)
        ->and($creator->suspended_until)->toBeNull()
        ->and($this->audit->of('creator.pause_ended'))->toHaveCount(1)
        ->and(app(CreatorEligibility::class)->canPublish($creator->user_id))->toBeTrue();
    $kinds = array_map(static fn (CreatorStatusNotice $n): string => $n->kind, sentToAccount($creator->user_id, CreatorStatusNotice::class));
    expect($kinds)->toBe([CreatorStatusNotice::SUSPENDED, CreatorStatusNotice::REINSTATED]);
});

it('never ends an admin suspension by itself', function (): void {
    $creator = liveCreator();
    app(SuspendCreator::class)->handle($creator->id, $this->admin->id, 'policy', 'Fraud');

    $this->travel(60)->days();
    $this->artisan('creators:end-pauses')->assertSuccessful();

    expect($creator->refresh()->status)->toBe(CreatorStatus::Suspended);
});

it('reinstates: admins only, with a note, and not while the account is restricted', function (): void {
    Event::fake([CreatorReinstated::class, CreatorSuspended::class]);
    $creator = liveCreator();
    app(SuspendCreator::class)->handle($creator->id, $this->admin->id, 'policy', 'Fraud');
    $moderator = signedInStaff('moderator');

    expect(fn () => app(ReinstateCreator::class)->handle($creator->id, $moderator->id, 'Cleared'))
        ->toThrow(CreatorException::class, 'Your role cannot do this.')
        ->and(fn () => app(ReinstateCreator::class)->handle($creator->id, $this->admin->id, ' '))
        ->toThrow(CreatorException::class, 'Write why');

    app(AccountRestrictions::class)->ban($creator->user_id);
    expect(fn () => app(ReinstateCreator::class)->handle($creator->id, $this->admin->id, 'Cleared'))
        ->toThrow(CreatorException::class, 'Lift that on Users & bans first.');
    app(AccountRestrictions::class)->lift($creator->user_id);

    actAsUserId($this->admin->id);
    Livewire::test(CreatorsDirectory::class, ['selected' => $creator->id])
        ->set('note', 'Report was false')
        ->callAction('reinstate')
        ->assertNotified('Reinstated.');

    expect($creator->refresh()->status)->toBe(CreatorStatus::Active)
        ->and($this->audit->of('creator.reinstated')[0]['context'])->toMatchArray(['note' => 'Report was false'])
        ->and(app(CreatorEligibility::class)->canEarn($creator->user_id))->toBeTrue();
    Event::assertDispatched(CreatorReinstated::class);
});

it('requires a new ID check: sales stop, the creator sees a neutral message, and comes back through approvals', function (): void {
    $creator = liveCreator();
    $moderator = signedInStaff('moderator');
    actAsUserId($moderator->id);

    Livewire::test(CreatorsDirectory::class, ['selected' => $creator->id])
        ->set('note', 'Report says the account is used by someone else')
        ->callAction('recheck')
        ->assertNotified('New ID check requested.');

    $creator->refresh();
    expect($creator->status)->toBe(CreatorStatus::Onboarding)
        ->and($creator->kyc_status)->toBe(KycStatus::ActionNeeded)
        ->and($creator->fix_kind)->toBe(FixKind::RetakeId)
        ->and(app(CreatorEligibility::class)->canPublish($creator->user_id))->toBeFalse()
        ->and($this->audit->of('creator.id_recheck_required')[0]['context'])->toMatchArray(['note' => 'Report says the account is used by someone else']);

    $decisions = sentToAccount($creator->user_id, ApplicationDecision::class);
    $mail = end($decisions)->toMail(new stdClass);
    expect($mail->introLines)->toContain(RequireIdRecheck::MESSAGE)
        ->and(implode(' ', $mail->introLines))->not->toContain('someone else');

    // The creator verifies again and submits; the application is back in the queue.
    actAsUserId($creator->user_id);
    $this->get('/creator/status')->assertOk()->assertSee(RequireIdRecheck::MESSAGE);
    $this->get((string) $this->post('/creator/kyc', ['device' => 'phone'])->headers->get('Location'));
    $this->post('/creator/review', ['terms' => '1', 'co_performers' => '1']);

    expect($creator->refresh()->status)->toBe(CreatorStatus::Submitted);
});

it('refuses staff acting on their own creator page', function (): void {
    $creator = Creator::factory()->create(['user_id' => $this->admin->id, 'handle' => 'own.page', 'display_name' => 'Own Page']);
    $creator->forceFill(['status' => CreatorStatus::Active, 'kyc_status' => KycStatus::Approved, 'approved_at' => now()])->save();

    expect(fn () => app(SuspendCreator::class)->handle($creator->id, $this->admin->id, 'policy', 'x'))
        ->toThrow(CreatorException::class);

    actAsUserId($this->admin->id);
    Livewire::test(CreatorsDirectory::class, ['selected' => $creator->id])
        ->assertSee('This is your own creator page.')
        ->assertDontSee('Require a new ID check');
});
