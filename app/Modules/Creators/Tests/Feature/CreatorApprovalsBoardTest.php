<?php

declare(strict_types=1);

use App\Modules\Creators\Actions\AssignApplication;
use App\Modules\Creators\Actions\RequestCreatorFix;
use App\Modules\Creators\Actions\RevealApplicantDob;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\FixKind;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Filament\Admin\CreatorApprovals;
use App\Modules\Creators\Models\Creator;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\RecordingAuditLog;

// Canvas board AdminApprovals, plan M8a use cases 4–8; rule 13 (masked data, logged reveals).

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
    Notification::fake();
    Filament::setCurrentPanel('admin');
});

/**
 * An application sent through the real onboarding screens, waiting for review. Leaves
 * the creator signed in.
 *
 * @param  'pass'|'review'  $outcome
 */
function waitingApplication(string $name = 'Waiting Creator', string $handle = 'waiting.creator', string $outcome = 'pass'): Creator
{
    $user = signedInFanWithMfa();
    $creator = Creator::factory()->create(['user_id' => $user->id, 'display_name' => $name, 'handle' => $handle, 'category' => 'fitness']);

    config(['fanfolio.fake.kyc_outcome' => $outcome]);
    test()->get((string) test()->post('/creator/kyc', ['device' => 'phone'])->headers->get('Location'));
    confirmedSecondFactor();
    test()->post('/creator/payout', ['rail' => 'bank', 'bank_name' => 'Test Bank', 'account_number' => '000011112222', 'account_name' => 'Test Person']);
    test()->post('/creator/review', ['terms' => '1', 'co_performers' => '1']);

    expect($creator->refresh()->status)->toBe(CreatorStatus::Submitted);

    return $creator;
}

it('shows the queue and the first application with masked details only', function (): void {
    $staff = signedInStaff();
    waitingApplication();
    actAsUserId($staff->id);

    $this->get('/admin/creator-approvals')
        ->assertOk()
        ->assertSee('Creator approvals')
        ->assertSee('1 waiting · automatic checks run first; you make the final call.')
        ->assertSee('Waiting Creator · @waiting.creator')
        ->assertSee('All checks passed')
        ->assertSee('adult: no')
        ->assertSee('Age 30')
        ->assertSee('••••0000', false)
        ->assertSee('Test Bank ····2222', false)
        ->assertSee('Payout account name matches ID')
        ->assertSee('ID document (front) · click to view, logged')
        ->assertSee('Not assigned')
        // Rule 13: no legal name, date of birth or full numbers.
        ->assertDontSee('TEST PERSON')
        ->assertDontSee('Test Person')
        ->assertDontSee('000011112222')
        ->assertDontSee(now()->subYears(30)->format('j M Y'));

    expect($this->audit->of('kyc.dob_revealed'))->toBe([]);
});

it('marks an application the partner sent for a closer look', function (): void {
    $staff = signedInStaff();
    waitingApplication(outcome: 'review');
    actAsUserId($staff->id);

    $this->get('/admin/creator-approvals')
        ->assertOk()
        ->assertSee('Name mismatch')
        ->assertSee('Partner asked for a closer look: the name on the ID differs from the account');
});

it('lists oldest first and opens the one chosen', function (): void {
    $staff = signedInStaff();
    waitingApplication('First Applicant', 'first.applicant');
    $this->travel(1)->hours();
    $second = waitingApplication('Second Applicant', 'second.applicant');
    actAsUserId($staff->id);

    Livewire::test(CreatorApprovals::class)
        ->assertSeeInOrder(['First Applicant', 'Second Applicant'])
        ->assertSee('First Applicant · @first.applicant')
        ->call('select', $second->id)
        ->assertSee('Second Applicant · @second.applicant')
        ->set('search', '@first')
        ->assertSee('First Applicant')
        ->assertDontSee('Second Applicant');
});

it('reveals the date of birth only on request, and logs it', function (): void {
    $staff = signedInStaff('moderator');
    $creator = waitingApplication();
    actAsUserId($staff->id);

    Livewire::test(CreatorApprovals::class)
        ->assertDontSee(now()->subYears(30)->format('j M Y'))
        ->callAction('revealDob')
        ->assertSee(now()->subYears(30)->format('j M Y').' · age 30')
        ->assertDontSee('Show date of birth');

    expect($this->audit->of('kyc.dob_revealed'))->toHaveCount(1)
        ->and($this->audit->of('kyc.dob_revealed')[0]['actor'])->toBe($staff->id);

    // The reveal belongs to the application it was made on.
    Livewire::test(CreatorApprovals::class, ['selected' => $creator->id])
        ->assertDontSee(now()->subYears(30)->format('j M Y'));
});

it('refuses a reveal to non-staff and on the reviewer\'s own application', function (): void {
    $creator = waitingApplication();

    expect(fn () => app(RevealApplicantDob::class)->handle($creator->id, $creator->user_id))
        ->toThrow(CreatorException::class);

    $fan = signedInFan();
    expect(fn () => app(RevealApplicantDob::class)->handle($creator->id, $fan->id))
        ->toThrow(CreatorException::class, 'Only staff');
});

it('assigns, hands over and unassigns, each audited', function (): void {
    $first = signedInStaff('moderator');
    $second = signedInStaff();
    $creator = waitingApplication();
    actAsUserId($first->id);

    Livewire::test(CreatorApprovals::class)
        ->callAction('assign')
        ->assertNotified('Assigned to you.')
        ->assertSee('Assigned to you')
        ->assertSee('Unassign')
        ->assertDontSee('Assign to me');

    expect($creator->refresh()->assigned_to)->toBe($first->id)
        ->and($this->audit->of('creator.assigned')[0]['actor'])->toBe($first->id);

    // Someone else sees who holds it, and may take it over.
    actAsUserId($second->id);
    Livewire::test(CreatorApprovals::class)->assertSee('Held by @'.$first->username);

    expect(fn () => app(AssignApplication::class)->release($creator->id, $second->id))
        ->toThrow(CreatorException::class, 'Someone else holds this application.');

    app(AssignApplication::class)->assign($creator->id, $second->id);
    expect($creator->refresh()->assigned_to)->toBe($second->id)
        ->and($this->audit->of('creator.assigned')[1]['context'])->toMatchArray(['taken_from' => $first->id]);

    Livewire::test(CreatorApprovals::class)->callAction('release')->assertNotified('Unassigned.');
    expect($creator->refresh()->assigned_to)->toBeNull()
        ->and($this->audit->of('creator.unassigned'))->toHaveCount(1);
});

it('puts a resubmitted application back in the queue unassigned', function (): void {
    $staff = signedInStaff();
    $creator = waitingApplication();
    app(AssignApplication::class)->assign($creator->id, $staff->id);
    app(RequestCreatorFix::class)->handle($creator->id, $staff->id, FixKind::Profile);

    actAsUserId($creator->user_id);
    $this->post('/creator/review', ['terms' => '1', 'co_performers' => '1']);

    expect($creator->refresh()->status)->toBe(CreatorStatus::Submitted)
        ->and($creator->assigned_to)->toBeNull();
});

it('approves from the board, as a moderator too', function (): void {
    $staff = signedInStaff('moderator');
    $creator = waitingApplication();
    actAsUserId($staff->id);

    Livewire::test(CreatorApprovals::class)
        ->callAction('approve')
        ->assertNotified('Approved.')
        ->assertSee('Nothing to review.');

    expect($creator->refresh()->status)->toBe(CreatorStatus::Active)
        ->and($this->audit->of('creator.approved')[0]['actor'])->toBe($staff->id);
});

it('requests changes and rejects from the board', function (): void {
    $staff = signedInStaff();
    $fix = waitingApplication('Fix Me', 'fix.me');
    $reject = waitingApplication('Reject Me', 'reject.me');
    actAsUserId($staff->id);

    Livewire::test(CreatorApprovals::class, ['selected' => $fix->id])
        ->callAction('requestChanges', data: ['kind' => FixKind::Payout->value, 'note' => 'Use an account in your own name.'])
        ->assertNotified('Sent back to the creator.');

    expect($fix->refresh()->status)->toBe(CreatorStatus::Onboarding)
        ->and($fix->fix_kind)->toBe(FixKind::Payout);

    Livewire::test(CreatorApprovals::class, ['selected' => $reject->id])
        ->callAction('reject', data: ['reason' => 'policy'])
        ->assertNotified('Rejected.');

    expect($reject->refresh()->kyc_status)->toBe(KycStatus::Rejected)
        ->and($this->audit->of('creator.rejected')[0]['context'])->toMatchArray(['reason' => 'policy']);
});

it('never shows staff their own application', function (): void {
    $staff = signedInStaff('moderator');
    $creator = Creator::factory()->create(['user_id' => $staff->id, 'display_name' => 'Waiting Creator', 'handle' => 'waiting.creator']);
    $creator->forceFill(['status' => CreatorStatus::Submitted, 'kyc_status' => KycStatus::Review, 'submitted_at' => now()])->save();

    Livewire::test(CreatorApprovals::class, ['selected' => $creator->id])
        ->assertDontSee('Waiting Creator')
        ->assertSee('Nothing to review.');
});
