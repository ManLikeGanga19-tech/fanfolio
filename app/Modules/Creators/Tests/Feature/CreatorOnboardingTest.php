<?php

declare(strict_types=1);

use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Events\CreatorApplied;
use App\Modules\Creators\Models\Creator;
use Illuminate\Support\Facades\Event;
use Tests\Support\RecordingAuditLog;

// Creator onboarding boards 1–2: Become a creator, Profile basics.

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function profileData(array $overrides = []): array
{
    return [
        'display_name' => 'Test Creator',
        'handle' => 'test.creator',
        'category' => 'music',
        'is_adult' => '0',
        ...$overrides,
    ];
}

it('sends guests to log in', function (): void {
    $this->get('/creator/start')->assertRedirect(route('login'));
    $this->post('/creator/start')->assertRedirect(route('login'));
});

it('shows Become a creator with the screen copy', function (): void {
    signedInFan();

    $this->get('/creator/start')
        ->assertOk()
        ->assertSee('Get paid by the fans who love your work')
        ->assertSee('Keep 80% of every sale')
        ->assertSee('Start verification')
        ->assertSee('You must be 18 or older.')
        // D-01, rule 10: the board's "stored encrypted" is replaced; we never store the image.
        ->assertSee('We never store the image.')
        ->assertDontSee('stored encrypted');
});

it('needs MFA before starting, and comes back here after', function (): void {
    $user = signedInFan();

    $this->post('/creator/start')
        ->assertRedirect(route('account.security'))
        ->assertSessionHas('status', 'Creators need a passkey or an authenticator app. Set one up, then start again.');

    expect(Creator::query()->count())->toBe(0)
        ->and(session('url.intended'))->toBe(route('creator.start'));
});

it('starts an application once, audited, with an event after commit', function (): void {
    Event::fake([CreatorApplied::class]);
    $user = signedInFanWithMfa();

    $this->post('/creator/start')->assertRedirect(route('creator.profile'));
    $this->post('/creator/start')->assertRedirect(route('creator.profile'));

    $creator = Creator::query()->sole();
    expect($creator->user_id)->toBe($user->id)
        ->and($creator->status)->toBe(CreatorStatus::Onboarding)
        ->and($creator->kyc_status)->toBe(KycStatus::NotStarted)
        ->and($this->audit->of('creator.application_started'))->toHaveCount(1);

    Event::assertDispatchedTimes(CreatorApplied::class, 1);
    $this->get('/creator/start')->assertRedirect(route('creator.profile'));
});

it('saves profile basics', function (): void {
    signedInFanWithMfa();
    $this->post('/creator/start');

    $this->get('/creator/profile')->assertOk()->assertSee('STEP 1 OF 4 · YOUR PAGE')->assertSee('[domain]/');

    $this->post('/creator/profile', profileData(['handle' => '@Test.Creator', 'is_adult' => '1']))
        ->assertRedirect(route('creator.kyc'))
        ->assertSessionHas('status', 'Saved. Next: verify your identity.');

    $creator = Creator::query()->sole();
    expect($creator->handle)->toBe('test.creator')
        ->and($creator->display_name)->toBe('Test Creator')
        ->and($creator->category)->toBe('music')
        ->and($creator->is_adult)->toBeTrue()
        ->and($creator->initials())->toBe('TC')
        ->and($this->audit->of('creator.profile_saved')[0]['context'])->toBe(['handle' => 'test.creator', 'is_adult' => true]);
});

it('saves and exits to the account hub', function (): void {
    signedInFanWithMfa();
    $this->post('/creator/start');

    $this->post('/creator/profile', profileData(['exit' => '1']))
        ->assertRedirect(route('account'))
        ->assertSessionHas('status', 'Saved. Come back any time to finish becoming a creator.');
});

it('refuses bad, reserved and taken handles', function (string $handle, string $message): void {
    Creator::factory()->create(['handle' => 'taken.name']);
    signedInFanWithMfa();
    $this->post('/creator/start');

    $this->post('/creator/profile', profileData(['handle' => $handle]))->assertSessionHasErrors(['handle' => $message]);
})->with([
    'too short' => ['ab', 'Use 3 to 30 lowercase letters, numbers, dots or underscores. Start and end with a letter or number.'],
    'two dots' => ['a..b', 'Use 3 to 30 lowercase letters, numbers, dots or underscores. Start and end with a letter or number.'],
    'ends with a dot' => ['name.', 'Use 3 to 30 lowercase letters, numbers, dots or underscores. Start and end with a letter or number.'],
    'spaces' => ['my name', 'Use 3 to 30 lowercase letters, numbers, dots or underscores. Start and end with a letter or number.'],
    'reserved' => ['studio', 'This handle is reserved.'],
    'taken, any case' => ['Taken.Name', 'This handle is taken.'],
]);

it('refuses a category that is not on the list', function (): void {
    signedInFanWithMfa();
    $this->post('/creator/start');

    $this->post('/creator/profile', profileData(['category' => 'not-a-category']))->assertSessionHasErrors(['category' => 'Choose a category.']);
});

it('checks handle availability while typing', function (): void {
    Creator::factory()->create(['handle' => 'taken.name']);
    signedInFanWithMfa();
    $this->post('/creator/start');

    $this->getJson('/creator/handle-check?handle=free.name')->assertOk()->assertJson(['available' => true, 'message' => 'Available']);
    $this->getJson('/creator/handle-check?handle=TAKEN.name')->assertJson(['available' => false, 'message' => 'This handle is taken.']);
    $this->getJson('/creator/handle-check?handle=admin')->assertJson(['available' => false, 'message' => 'This handle is reserved.']);
});

it('keeps your own handle available to you', function (): void {
    signedInFanWithMfa();
    $this->post('/creator/start');
    $this->post('/creator/profile', profileData());

    $this->getJson('/creator/handle-check?handle=test.creator')->assertJson(['available' => true]);
    $this->post('/creator/profile', profileData(['display_name' => 'Renamed Creator']))->assertSessionHasNoErrors();
});

it('locks profile basics once the application is submitted', function (): void {
    $user = signedInFanWithMfa();
    Creator::factory()->withStatus(CreatorStatus::Submitted, KycStatus::Review)->create(['user_id' => $user->id, 'handle' => 'locked.name']);

    $this->post('/creator/profile', profileData(['handle' => 'new.name']))
        ->assertSessionHasErrors(['handle' => 'Your application is already submitted. Contact support to change your page.']);
});

it('rate limits the handle check', function (): void {
    cache()->flush();
    signedInFanWithMfa();

    foreach (range(1, 60) as $ignored) {
        $this->getJson('/creator/handle-check?handle=some.name')->assertOk();
    }

    $this->getJson('/creator/handle-check?handle=some.name')->assertTooManyRequests()->assertHeader('Retry-After');
});

it('links to Become a creator from the account hub and the landing page', function (): void {
    // A visitor signs up as a creator first (canvas AuthSignup); Become a creator follows the email step.
    $this->get('/')->assertSee(e(route('signup', ['as' => 'creator'])), false);

    signedInFan();
    $this->get('/account')->assertSee('Become a creator')->assertSee(route('creator.start'), false);
});
