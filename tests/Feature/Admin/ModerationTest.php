<?php

declare(strict_types=1);

use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use App\Modules\TrustSafety\Actions\AssignCase;
use App\Modules\TrustSafety\Actions\DecideCase;
use App\Modules\TrustSafety\Enums\CaseDecision;
use App\Modules\TrustSafety\Enums\CaseSource;
use App\Modules\TrustSafety\Enums\CaseStatus;
use App\Modules\TrustSafety\Enums\ReportReason;
use App\Modules\TrustSafety\Exceptions\TrustSafetyException;
use App\Modules\TrustSafety\Filament\Admin\ModerationBoard;
use App\Modules\TrustSafety\Filament\Admin\UsersBoard;
use App\Modules\TrustSafety\Http\Controllers\ReportController;
use App\Modules\TrustSafety\Models\ModerationCase;
use App\Modules\TrustSafety\Models\Strike;
use App\Modules\TrustSafety\Notifications\AccountDecision;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use Tests\Support\RecordingAuditLog;

// Canvas board AdminModeration and FanMore "Report" sheet; plan M8a use cases 23 and 25–29.

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
    Notification::fake();
    Filament::setCurrentPanel('admin');
});

/** An active creator page with this handle. */
function creatorPage(string $handle = 'reported.page'): User
{
    $user = User::factory()->create();
    $creator = Creator::factory()->create(['user_id' => $user->id, 'handle' => $handle, 'display_name' => 'Reported Page']);
    $creator->forceFill(['status' => CreatorStatus::Active, 'kyc_status' => KycStatus::Approved, 'approved_at' => now()])->save();

    return $user;
}

/** A signed-in fan reports the page through the sheet's endpoint. */
function reportPage(string $handle, string $reason, ?string $details = null): User
{
    $fan = signedInFan();
    test()->from('/somewhere')->post('/reports', ['kind' => 'creator_page', 'target' => $handle, 'reason' => $reason, 'details' => $details])
        ->assertRedirect('/somewhere')
        ->assertSessionHas('status', ReportController::THANKS);

    return $fan;
}

it('files a report on a creator page into a case; details encrypted, reporter kept private', function (): void {
    $target = creatorPage();
    $fan = reportPage('@Reported.Page', 'spam', 'Sends links to a scam site in every DM');

    $case = ModerationCase::query()->sole();
    expect($case->target_user_id)->toBe($target->id)
        ->and($case->target_kind)->toBe(ModerationCase::CREATOR_PAGE)
        ->and($case->source)->toBe(CaseSource::UserReport)
        ->and($case->reason)->toBe(ReportReason::Spam)
        ->and($case->report_count)->toBe(1)
        ->and($case->urgent)->toBeFalse()
        ->and($case->number)->toBeGreaterThanOrEqual(1000)
        ->and($this->audit->of('report.filed')[0]['actor'])->toBe($fan->id);

    // Rule 11 in spirit: free text is encrypted at rest.
    $raw = DB::table('reports')->value('details');
    expect($raw)->not->toContain('scam');

    $moderator = signedInStaff('moderator');
    actAsUserId($moderator->id);
    Livewire::test(ModerationBoard::class)
        ->assertSee('Case #'.$case->number.' · User report')
        ->assertSee('@reported.page · creator page')
        ->assertSee('Sends links to a scam site in every DM')
        ->assertSee('Reporters stay anonymous.')
        ->assertDontSee($fan->username);
});

it('joins reports into one case, keeps the most serious reason, and ignores repeats within a day', function (): void {
    creatorPage();
    $first = reportPage('reported.page', 'spam');
    reportPage('reported.page', 'leak');

    actAs($first);
    $this->post('/reports', ['kind' => 'creator_page', 'target' => 'reported.page', 'reason' => 'other'])->assertSessionHas('status');

    $case = ModerationCase::query()->sole();
    expect($case->report_count)->toBe(2)
        ->and($case->reason)->toBe(ReportReason::Leak)
        ->and(DB::table('reports')->count())->toBe(2);
});

it('puts anything that may involve a minor on top, with a 1-hour target, for an admin', function (): void {
    creatorPage('older.page');
    reportPage('older.page', 'spam');
    $this->travel(5)->minutes();
    creatorPage('minor.page');
    reportPage('minor.page', 'minor');

    $urgent = ModerationCase::query()->where('urgent', true)->sole();
    expect($urgent->reason)->toBe(ReportReason::Minor)
        ->and($urgent->due_at?->diffInMinutes(now()->addHour()))->toBeLessThan(1);

    $moderator = signedInStaff('moderator');
    actAsUserId($moderator->id);
    Livewire::test(ModerationBoard::class)
        ->assertSeeInOrder(['@minor.page', '@older.page'])
        ->assertSee('May involve a minor · due in')
        ->assertSee('Only an admin can decide this case.')
        ->set('note', 'Profile photo looks young')
        ->callAction('strike')
        ->assertHasErrors('case');

    expect(fn () => app(DecideCase::class)->handle($urgent->id, $moderator->id, CaseDecision::NoAction, 'Looks fine'))
        ->toThrow(TrustSafetyException::class, 'Only an admin can decide this case.');

    app(DecideCase::class)->handle($urgent->id, $moderator->id, CaseDecision::Escalate, 'Needs an admin');
    expect($urgent->refresh()->status)->toBe(CaseStatus::Escalated)
        ->and($this->audit->of('case.escalated'))->toHaveCount(1);

    $admin = signedInStaff();
    app(DecideCase::class)->handle($urgent->id, $admin->id, CaseDecision::NoAction, 'Verified adult; ID on file');
    expect($urgent->refresh()->status)->toBe(CaseStatus::Closed);
});

it('refuses reports on yourself, on unknown accounts and on staff, and requires a reason', function (): void {
    $fan = signedInFan();

    $this->post('/reports', ['kind' => 'account', 'target' => $fan->username, 'reason' => 'spam'])->assertSessionHasErrors(['target' => 'You cannot report yourself.']);
    $this->post('/reports', ['kind' => 'account', 'target' => 'nobody-here', 'reason' => 'spam'])->assertSessionHasErrors(['target' => 'We could not find that account.']);
    $this->post('/reports', ['kind' => 'creator_page', 'target' => 'no.such.page', 'reason' => 'spam'])->assertSessionHasErrors('target');
    $this->post('/reports', ['kind' => 'account', 'target' => 'x', 'reason' => ''])->assertSessionHasErrors(['reason' => 'Choose what is wrong.']);

    $staff = User::factory()->withRole(Role::Moderator)->create();
    actAs($fan);
    $this->post('/reports', ['kind' => 'account', 'target' => $staff->username, 'reason' => 'spam'])->assertSessionHasErrors('target');

    expect(ModerationCase::query()->count())->toBe(0);
});

it('lets guests report nothing', function (): void {
    $this->post('/reports', ['kind' => 'account', 'target' => 'x', 'reason' => 'spam'])->assertRedirect(route('login'));
});

it('assigns, hands over and unassigns cases, each audited', function (): void {
    creatorPage();
    reportPage('reported.page', 'spam');
    $case = ModerationCase::query()->sole();
    $moderator = signedInStaff('moderator');
    $other = signedInStaff('moderator');
    actAsUserId($moderator->id);

    Livewire::test(ModerationBoard::class)
        ->callAction('assign')
        ->assertNotified('Assigned to you.')
        ->assertSee('Assigned to you')
        ->assertSee('Unassign');

    expect(fn () => app(AssignCase::class)->release($case->id, $other->id))->toThrow(TrustSafetyException::class, 'Someone else holds this case.');
    app(AssignCase::class)->assign($case->id, $other->id);

    expect($case->refresh()->assigned_to)->toBe($other->id)
        ->and($this->audit->of('case.assigned')[1]['context'])->toMatchArray(['taken_from' => $moderator->id]);
});

it('decides: note required; warn emails the category; strike and suspend go through their own rules', function (): void {
    $target = creatorPage();
    reportPage('reported.page', 'spam');
    $case = ModerationCase::query()->sole();
    $moderator = signedInStaff('moderator');
    actAsUserId($moderator->id);

    Livewire::test(ModerationBoard::class)
        ->callAction('warn')
        ->assertHasErrors('note')
        ->set('note', 'Spam links confirmed in 3 reports')
        ->callAction('warn')
        ->assertNotified('Decided: warn.')
        ->assertSee('Decided: Warn');

    expect($case->refresh()->status)->toBe(CaseStatus::Closed)
        ->and($case->decision)->toBe(CaseDecision::Warn)
        ->and($this->audit->of('case.decided')[0]['context'])->toMatchArray(['decision' => 'warn', 'reason' => 'spam', 'note' => 'Spam links confirmed in 3 reports']);
    Notification::assertSentTo($target, AccountDecision::class, function (AccountDecision $n) use ($target): bool {
        $mail = $n->toMail($target);

        return $n->kind === AccountDecision::WARNING && in_array('Reason: Spam or scams.', $mail->introLines, true)
            && ! str_contains(implode(' ', $mail->introLines), '3 reports');
    });

    expect(fn () => app(DecideCase::class)->handle($case->id, $moderator->id, CaseDecision::Strike, 'Again'))
        ->toThrow(TrustSafetyException::class, 'This case is already decided.');

    // A new report opens a new case; a strike goes through Strikes.
    reportPage('reported.page', 'spam');
    $second = ModerationCase::query()->pending()->sole();
    app(DecideCase::class)->handle($second->id, $moderator->id, CaseDecision::Strike, 'Spam again');
    expect(Strike::query()->where('user_id', $target->id)->count())->toBe(1);

    // Moderators suspend for up to 7 days only (D-18), enforced by SuspendAccount.
    reportPage('reported.page', 'spam');
    $third = ModerationCase::query()->pending()->sole();
    expect(fn () => app(DecideCase::class)->handle($third->id, $moderator->id, CaseDecision::Suspend, 'Third time', null, 30))
        ->toThrow(TrustSafetyException::class, 'up to 7 days')
        ->and($third->refresh()->status)->toBe(CaseStatus::Open);

    app(DecideCase::class)->handle($third->id, $moderator->id, CaseDecision::Suspend, 'Third time', null, 7);
    expect($target->refresh()->status)->toBe(AccountStatus::Suspended)
        ->and($third->refresh()->decision)->toBe(CaseDecision::Suspend);
});

it('runs every decision button from the board', function (string $action, CaseDecision $decision): void {
    creatorPage();
    reportPage('reported.page', 'spam');
    $case = ModerationCase::query()->sole();
    actAsUserId(signedInStaff()->id);

    Livewire::test(ModerationBoard::class)
        ->set('note', 'Checked from the board')
        ->set('days', 1)
        ->callAction($action)
        ->assertHasNoErrors();

    expect($case->refresh()->decision)->toBe($decision === CaseDecision::Escalate ? null : $decision)
        ->and($case->status)->toBe($decision === CaseDecision::Escalate ? CaseStatus::Escalated : CaseStatus::Closed);
})->with([
    'no action' => ['dismiss', CaseDecision::NoAction],
    'warn' => ['warn', CaseDecision::Warn],
    'strike' => ['strike', CaseDecision::Strike],
    'suspend' => ['suspend', CaseDecision::Suspend],
    'escalate' => ['escalate', CaseDecision::Escalate],
]);

it('refuses a case about the decider and keeps fans out of the board', function (): void {
    $admin = signedInStaff();
    $case = new ModerationCase;
    $case->forceFill(['target_user_id' => $admin->id, 'target_kind' => 'account', 'source' => CaseSource::UserReport, 'reason' => ReportReason::Spam])->save();

    expect(fn () => app(DecideCase::class)->handle($case->id, $admin->id, CaseDecision::NoAction, 'Not me'))
        ->toThrow(TrustSafetyException::class, 'You cannot decide a case about your own account.');

    signedInFan();
    $this->get('/admin/moderation')->assertForbidden();
});

it('flags an account from Users & bans into the queue', function (): void {
    $fan = signedInFan();
    $moderator = signedInStaff('moderator');
    actAsUserId($moderator->id);

    Livewire::test(UsersBoard::class, ['selected' => $fan->id])
        ->set('reason', 'harassment')
        ->set('note', 'Abusive messages seen in a support ticket')
        ->callAction('flag')
        ->assertNotified('Flagged. The case is in the moderation queue.');

    $case = ModerationCase::query()->sole();
    expect($case->source)->toBe(CaseSource::StaffFlag)
        ->and($case->opened_by)->toBe($moderator->id)
        ->and($case->reason)->toBe(ReportReason::Other)
        ->and($this->audit->of('case.flagged'))->toHaveCount(1);

    Livewire::test(ModerationBoard::class)
        ->call('setTab', 'staff_flag')
        ->assertSee('Flagged by staff · 1')
        ->assertSee('Abusive messages seen in a support ticket');
});

it('shows open cases on the Overview and in the sidebar', function (): void {
    creatorPage();
    reportPage('reported.page', 'minor');
    actAsUserId(signedInStaff('moderator')->id);

    $this->get('/admin')->assertOk()->assertSee('Open cases')->assertSee('1 may involve a minor');
    expect(ModerationBoard::getNavigationBadge())->toBe('1');
});

it('renders the Report sheet from the canvas', function (): void {
    signedInFan();

    // Rendered outside a web request, so the shared error bag is passed in.
    $html = view('trustsafety::partials.report-sheet', ['kind' => 'creator_page', 'target' => 'some.page', 'title' => 'Report this creator', 'errors' => new ViewErrorBag])->render();

    expect($html)->toContain('Report this creator')
        ->toContain('Reports are anonymous. Anything involving a minor is reviewed within 1 hour.')
        ->toContain('Someone may be under 18')
        ->toContain('Shared without consent')
        ->toContain('Leaked or stolen content')
        ->toContain('Spam or scam')
        ->toContain('Something else')
        ->toContain('Details (optional)')
        ->toContain('Submit report')
        ->toContain(route('reports.store'));
});
