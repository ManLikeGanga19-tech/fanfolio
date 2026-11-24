<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\AccountController;
use App\Modules\Identity\Http\Controllers\AgeVerificationController;
use App\Modules\Identity\Http\Controllers\DeviceLimitController;
use App\Modules\Identity\Http\Controllers\EmailVerificationController;
use App\Modules\Identity\Http\Controllers\FakeKycCaptureController;
use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Identity\Http\Controllers\MfaChallengeController;
use App\Modules\Identity\Http\Controllers\PasskeyLoginController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Identity\Http\Controllers\PhoneHandoffController;
use App\Modules\Identity\Http\Controllers\SecurityController;
use App\Modules\Identity\Http\Controllers\SignUpController;
use App\Modules\Identity\Http\Controllers\StepUpController;
use Illuminate\Support\Facades\Route;

// Decision D-14: the phone side of the ID and face scan. The phone is usually not signed
// in; the single-use, 10-minute link is the proof.
Route::get('/verify/on-phone/{token}', [PhoneHandoffController::class, 'open'])->middleware('throttle:phone-handoff')->name('handoff.open');
Route::get('/verify/on-phone-done', [PhoneHandoffController::class, 'done'])->middleware('throttle:phone-handoff')->name('handoff.done');

// Decision D-14, development only: the stand-in for the KYC provider's capture page.
if (app()->environment('local', 'testing') && config('fanfolio.drivers.kyc') === 'fake') {
    Route::get('/_fake/kyc/{ref}', [FakeKycCaptureController::class, 'show'])->name('fake.kyc.capture');
    Route::post('/_fake/kyc/{ref}', [FakeKycCaptureController::class, 'finish']);
}

Route::middleware('guest')->group(function (): void {
    // Canvas MarketingHome; members go to their account.
    Route::view('/', 'marketing.home')->name('landing');

    Route::get('/signup', [SignUpController::class, 'show'])->name('signup');
    Route::post('/signup', [SignUpController::class, 'store'])->middleware('throttle:signup');
    Route::post('/signup/passkey/options', [SignUpController::class, 'passkeyOptions'])->middleware('throttle:signup')->name('signup.passkey.options');
    Route::post('/signup/passkey', [SignUpController::class, 'storePasskey'])->middleware('throttle:passkey')->name('signup.passkey');

    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');

    Route::post('/login/passkey/options', [PasskeyLoginController::class, 'options'])->middleware('throttle:passkey')->name('login.passkey.options');
    Route::post('/login/passkey', [PasskeyLoginController::class, 'store'])->middleware('throttle:passkey')->name('login.passkey');

    Route::get('/login/mfa', [MfaChallengeController::class, 'show'])->name('login.mfa');
    Route::post('/login/mfa', [MfaChallengeController::class, 'store'])->middleware('throttle:mfa');

    // "Forgot password?"
    Route::get('/forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'store'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:password-reset')->name('password.update');

    // D-03: device limit reached at sign-in.
    Route::get('/login/devices', [DeviceLimitController::class, 'show'])->name('login.devices');
    Route::delete('/login/devices/{device}', [DeviceLimitController::class, 'destroy'])->name('login.devices.destroy');
    Route::post('/login/devices/continue', [DeviceLimitController::class, 'continue'])->name('login.devices.continue');
});

Route::middleware('auth')->group(function (): void {
    // Sign up step 2: verify the email with a one-time code (decision D-12).
    Route::get('/signup/email', [EmailVerificationController::class, 'show'])->name('signup.email');
    Route::post('/signup/email/send', [EmailVerificationController::class, 'send'])->middleware('throttle:otp-send')->name('signup.email.send');
    Route::post('/signup/email/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:otp-verify')->name('signup.email.verify');

    // Sign up step 3 (optional): 18+ check through the KYC provider.
    Route::get('/signup/age', [AgeVerificationController::class, 'show'])->name('signup.age');
    Route::post('/signup/age', [AgeVerificationController::class, 'start'])->middleware('throttle:age-check')->name('signup.age.start');
    Route::get('/signup/age/return', [AgeVerificationController::class, 'return'])->name('signup.age.return');

    Route::get('/account', [AccountController::class, 'show'])->name('account');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Step-up: "Confirm it's you".
    Route::get('/account/confirm', [StepUpController::class, 'show'])->name('account.confirm');
    Route::post('/account/confirm', [StepUpController::class, 'store'])->middleware('throttle:mfa');
    Route::post('/account/confirm/passkey/options', [StepUpController::class, 'passkeyOptions'])->middleware('throttle:passkey')->name('account.confirm.passkey.options');
    Route::post('/account/confirm/passkey', [StepUpController::class, 'passkey'])->middleware('throttle:passkey')->name('account.confirm.passkey');

    // Devices & security: sign-in methods. Changes need a recent step-up.
    Route::get('/account/security', [SecurityController::class, 'show'])->name('account.security');
    Route::delete('/account/devices/{device}', [SecurityController::class, 'destroyDevice'])->name('account.security.devices.destroy');
    Route::post('/account/devices/sign-out-others', [SecurityController::class, 'signOutOtherDevices'])->name('account.security.devices.others');
    Route::get('/account/security/recovery-codes', [SecurityController::class, 'showRecoveryCodes'])->name('account.security.recovery-codes');

    Route::middleware('step-up')->group(function (): void {
        Route::post('/account/passkeys/options', [SecurityController::class, 'passkeyOptions'])->middleware('throttle:passkey')->name('account.passkeys.options');
        Route::post('/account/passkeys', [SecurityController::class, 'storePasskey'])->middleware('throttle:passkey')->name('account.passkeys.store');
        Route::delete('/account/passkeys/{credential}', [SecurityController::class, 'destroyPasskey'])->name('account.passkeys.destroy');

        Route::post('/account/security/totp', [SecurityController::class, 'startTotp'])->name('account.totp.start');
        Route::get('/account/security/totp', [SecurityController::class, 'showTotp'])->name('account.totp.show');
        Route::post('/account/security/totp/confirm', [SecurityController::class, 'confirmTotp'])->middleware('throttle:mfa')->name('account.totp.confirm');
        Route::delete('/account/security/totp', [SecurityController::class, 'destroyTotp'])->name('account.totp.destroy');

        Route::post('/account/security/recovery-codes', [SecurityController::class, 'regenerateRecoveryCodes'])->name('account.security.recovery-codes.regenerate');
    });
});
