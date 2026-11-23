<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\AccountController;
use App\Modules\Identity\Http\Controllers\AgeVerificationController;
use App\Modules\Identity\Http\Controllers\DeviceLimitController;
use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Identity\Http\Controllers\MfaChallengeController;
use App\Modules\Identity\Http\Controllers\PasskeyLoginController;
use App\Modules\Identity\Http\Controllers\PhoneController;
use App\Modules\Identity\Http\Controllers\SecurityController;
use App\Modules\Identity\Http\Controllers\SignUpController;
use App\Modules\Identity\Http\Controllers\StepUpController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::view('/', 'identity::landing')->name('landing');

    Route::get('/signup', [SignUpController::class, 'show'])->name('signup');
    Route::post('/signup', [SignUpController::class, 'store'])->middleware('throttle:signup');

    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');

    Route::post('/login/passkey/options', [PasskeyLoginController::class, 'options'])->middleware('throttle:passkey')->name('login.passkey.options');
    Route::post('/login/passkey', [PasskeyLoginController::class, 'store'])->middleware('throttle:passkey')->name('login.passkey');

    Route::get('/login/mfa', [MfaChallengeController::class, 'show'])->name('login.mfa');
    Route::post('/login/mfa', [MfaChallengeController::class, 'store'])->middleware('throttle:mfa');

    // D-03: device limit reached at sign-in.
    Route::get('/login/devices', [DeviceLimitController::class, 'show'])->name('login.devices');
    Route::delete('/login/devices/{device}', [DeviceLimitController::class, 'destroy'])->name('login.devices.destroy');
    Route::post('/login/devices/continue', [DeviceLimitController::class, 'continue'])->name('login.devices.continue');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/signup/phone', [PhoneController::class, 'show'])->name('signup.phone');
    Route::post('/signup/phone/send', [PhoneController::class, 'send'])->middleware('throttle:otp-send')->name('signup.phone.send');
    Route::post('/signup/phone/verify', [PhoneController::class, 'verify'])->middleware('throttle:otp-verify')->name('signup.phone.verify');

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
