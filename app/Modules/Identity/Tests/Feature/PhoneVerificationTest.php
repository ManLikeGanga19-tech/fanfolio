<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\SmsSender;
use App\Modules\Identity\Gateways\Fake\FakeSmsSender;
use App\Modules\Identity\Models\PhoneVerification;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecordingAuditLog;

const TEST_PHONE = '+254700000412';

function lastCodeSentTo(string $phone): string
{
    $sms = app(SmsSender::class);
    assert($sms instanceof FakeSmsSender);

    preg_match('/\b(\d{6})\b/', (string) $sms->lastMessageTo($phone), $match);

    return $match[1] ?? '';
}

function wrongCode(string $right): string
{
    return $right === '000000' ? '111111' : '000000';
}

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('asks for the number, then shows the masked number and code entry', function (): void {
    $this->get('/signup/phone')->assertOk()->assertSee('STEP 2 OF 3 · PHONE')->assertSee('Phone number');

    $this->post('/signup/phone/send', ['phone' => '+254 700 000 412'])->assertRedirect(route('signup.phone'));

    $this->get('/signup/phone')
        ->assertSee('Enter the 6-digit code we sent to')
        ->assertSee('+254 7•• ••• 412')
        ->assertDontSee(TEST_PHONE)
        ->assertSee('Resend code in');
});

it('sends a 6-digit code by SMS and stores only its hash', function (): void {
    $this->post('/signup/phone/send', ['phone' => TEST_PHONE]);

    $code = lastCodeSentTo(TEST_PHONE);
    $row = DB::table('phone_verifications')->sole();

    expect($code)->toMatch('/^\d{6}$/')
        ->and($row->code_hash)->not->toContain($code)
        ->and($row->phone_e164)->not->toBe(TEST_PHONE);
});

it('verifies the right code and links the number, encrypted', function (): void {
    $this->post('/signup/phone/send', ['phone' => TEST_PHONE]);

    $this->post('/signup/phone/verify', ['code' => lastCodeSentTo(TEST_PHONE)])->assertRedirect(route('account'));

    $user = $this->user->refresh();
    expect($user->hasVerifiedPhone())->toBeTrue()
        ->and($user->phone_e164)->toBe(TEST_PHONE)
        ->and(DB::table('users')->where('id', $user->id)->value('phone_e164'))->not->toBe(TEST_PHONE)
        ->and($this->audit->has('identity.phone_verified', $user->id))->toBeTrue();
});

it('counts wrong codes and locks after 5 attempts', function (): void {
    $this->post('/signup/phone/send', ['phone' => TEST_PHONE]);
    $right = lastCodeSentTo(TEST_PHONE);

    foreach (range(1, 5) as $attempt) {
        $this->post('/signup/phone/verify', ['code' => wrongCode($right)])
            ->assertSessionHasErrors(['code' => 'That code is not right. Check the SMS and try again.']);
    }

    expect(PhoneVerification::query()->sole()->attempts)->toBe(5);

    $this->post('/signup/phone/verify', ['code' => $right])
        ->assertSessionHasErrors(['code' => 'Too many attempts. Request a new code.']);

    expect($this->user->refresh()->hasVerifiedPhone())->toBeFalse();
});

it('refuses a code after 10 minutes', function (): void {
    $this->post('/signup/phone/send', ['phone' => TEST_PHONE]);
    $code = lastCodeSentTo(TEST_PHONE);

    $this->travel(11)->minutes();

    $this->post('/signup/phone/verify', ['code' => $code])
        ->assertSessionHasErrors(['code' => 'This code has expired. Request a new one.']);
});

it('waits 60 seconds before resending, then resends to the same number', function (): void {
    $this->post('/signup/phone/send', ['phone' => TEST_PHONE]);
    $first = lastCodeSentTo(TEST_PHONE);

    $this->post('/signup/phone/send', ['resend' => '1'])->assertSessionHasErrors('phone');

    $this->travel(61)->seconds();
    $this->post('/signup/phone/send', ['resend' => '1'])->assertSessionHasNoErrors();

    expect(PhoneVerification::query()->count())->toBe(2);

    // Only the latest code counts.
    $latest = lastCodeSentTo(TEST_PHONE);
    if ($latest !== $first) {
        $this->post('/signup/phone/verify', ['code' => $first])->assertSessionHasErrors('code');
    }
    $this->post('/signup/phone/verify', ['code' => $latest])->assertSessionHasNoErrors();
});

it('refuses a number already linked to another account', function (): void {
    $this->post('/signup/phone/send', ['phone' => TEST_PHONE]);
    $this->post('/signup/phone/verify', ['code' => lastCodeSentTo(TEST_PHONE)]);

    actAs(User::factory()->create());

    $this->post('/signup/phone/send', ['phone' => TEST_PHONE])
        ->assertSessionHasErrors(['phone' => 'This number is already linked to another account.']);
});

it('rejects numbers without a country code', function (): void {
    $this->post('/signup/phone/send', ['phone' => '0700000412'])->assertSessionHasErrors('phone');
});

it('requires sign-in', function (): void {
    auth()->logout();

    $this->get('/signup/phone')->assertRedirect(route('login'));
    $this->post('/signup/phone/send', ['phone' => TEST_PHONE])->assertRedirect(route('login'));
});
