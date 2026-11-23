<?php

declare(strict_types=1);

use App\Modules\Identity\Models\EmailVerification;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\EmailVerificationCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\RecordingAuditLog;

// Sign up step 2: verify the email with a one-time code (decision D-12).

/** The code in the latest email sent to this user. */
function lastEmailCode(User $user): string
{
    $sent = Notification::sent($user, EmailVerificationCode::class);

    return $sent->isEmpty() ? '' : $sent->last()->code;
}

function wrongEmailCode(string $right): string
{
    return $right === '000000' ? '111111' : '000000';
}

beforeEach(function (): void {
    Notification::fake();
    $this->audit = RecordingAuditLog::swap();
    $this->user = User::factory()->create(['email' => 'new-fan@example.test', 'email_verified_at' => null]);
    $this->actingAs($this->user);
});

it('offers to send a code, then shows code entry with the address', function (): void {
    $this->get('/signup/email')
        ->assertOk()
        ->assertSee('STEP 2 OF 3 · EMAIL')
        ->assertSee('Verify your email')
        ->assertSee('new-fan@example.test')
        ->assertSee('Send code');

    $this->post('/signup/email/send')->assertRedirect(route('signup.email'));

    $this->get('/signup/email')
        ->assertSee('Enter the 6-digit code we sent to')
        ->assertSee('Resend code in')
        ->assertDontSee('Send code');
});

it('emails a 6-digit code to the account and stores only its hash', function (): void {
    $this->post('/signup/email/send');

    $code = lastEmailCode($this->user);
    $row = DB::table('email_verifications')->sole();

    expect($code)->toMatch('/^\d{6}$/')
        ->and($row->code_hash)->not->toContain($code);

    Notification::assertSentToTimes($this->user, EmailVerificationCode::class, 1);
    $mail = new EmailVerificationCode($code, 10)->toMail($this->user);
    expect($mail->subject)->toContain($code);
});

it('verifies the right code, audited, and moves to the age check', function (): void {
    $this->post('/signup/email/send');

    $this->post('/signup/email/verify', ['code' => lastEmailCode($this->user)])->assertRedirect(route('signup.age'));

    expect($this->user->refresh()->hasVerifiedEmail())->toBeTrue()
        ->and($this->audit->has('identity.email_verified', $this->user->id))->toBeTrue();

    // Done: the page moves on, and no new code is sent.
    $this->get('/signup/email')->assertRedirect(route('signup.age'));
    $this->travel(61)->seconds();
    $this->post('/signup/email/send')->assertSessionHasErrors(['email' => 'Your email is already verified.']);
});

it('counts wrong codes and locks after 5 attempts', function (): void {
    $this->post('/signup/email/send');
    $right = lastEmailCode($this->user);

    foreach (range(1, 5) as $attempt) {
        $this->post('/signup/email/verify', ['code' => wrongEmailCode($right)])
            ->assertSessionHasErrors(['code' => 'That code is not right. Check the email and try again.']);
    }

    expect(EmailVerification::query()->sole()->attempts)->toBe(5);

    $this->post('/signup/email/verify', ['code' => $right])
        ->assertSessionHasErrors(['code' => 'Too many attempts. Request a new code.']);

    expect($this->user->refresh()->hasVerifiedEmail())->toBeFalse();
});

it('refuses a code after 10 minutes', function (): void {
    $this->post('/signup/email/send');
    $code = lastEmailCode($this->user);

    $this->travel(11)->minutes();

    $this->post('/signup/email/verify', ['code' => $code])
        ->assertSessionHasErrors(['code' => 'This code has expired. Request a new one.']);

    // An expired code offers a fresh one.
    $this->get('/signup/email')->assertSee('Send code');
});

it('waits 60 seconds before resending, and only the latest code counts', function (): void {
    $this->post('/signup/email/send');
    $first = lastEmailCode($this->user);

    $this->post('/signup/email/send')->assertSessionHasErrors('email');

    $this->travel(61)->seconds();
    $this->post('/signup/email/send')->assertSessionHasNoErrors();

    expect(EmailVerification::query()->count())->toBe(2);

    $latest = lastEmailCode($this->user);
    if ($latest !== $first) {
        $this->post('/signup/email/verify', ['code' => $first])->assertSessionHasErrors('code');
    }
    $this->post('/signup/email/verify', ['code' => $latest])->assertSessionHasNoErrors();
});

it('refuses a code that is not 6 digits', function (string $code): void {
    $this->post('/signup/email/send');

    $this->post('/signup/email/verify', ['code' => $code])->assertSessionHasErrors(['code' => 'Enter the 6-digit code.']);
    expect(EmailVerification::query()->sole()->attempts)->toBe(0);
})->with(['', '12345', '1234567', 'abcdef']);

it('needs a code to be requested first', function (): void {
    $this->post('/signup/email/verify', ['code' => '123456'])->assertSessionHasErrors(['code' => 'Request a code first.']);
});

it('requires sign-in', function (): void {
    auth()->logout();

    $this->get('/signup/email')->assertRedirect(route('login'));
    $this->post('/signup/email/send')->assertRedirect(route('login'));
    $this->post('/signup/email/verify', ['code' => '123456'])->assertRedirect(route('login'));
});
