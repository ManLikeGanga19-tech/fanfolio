<?php

declare(strict_types=1);

use App\Modules\Creators\Http\Controllers\ApplicationController;
use App\Modules\Creators\Http\Controllers\KycController;
use App\Modules\Creators\Http\Controllers\KycDocumentController;
use App\Modules\Creators\Http\Controllers\OnboardingController;
use App\Modules\Creators\Http\Controllers\StatusController;
use Illuminate\Support\Facades\Route;

// Creator onboarding (screens: Become a creator, Profile basics, ID and selfie). Signed-in accounts only.
Route::middleware('auth')->prefix('creator')->name('creator.')->group(function (): void {
    Route::get('/start', [OnboardingController::class, 'start'])->name('start');
    Route::post('/start', [OnboardingController::class, 'begin'])->middleware('throttle:creator-onboarding');

    Route::get('/profile', [OnboardingController::class, 'profile'])->name('profile');
    Route::post('/profile', [OnboardingController::class, 'saveProfile'])->middleware('throttle:creator-onboarding');

    Route::get('/kyc', [KycController::class, 'show'])->name('kyc');
    Route::post('/kyc', [KycController::class, 'start'])->middleware('throttle:creator-kyc');
    // The provider sends the creator back here; the result is fetched from the provider.
    Route::get('/kyc/return', [KycController::class, 'return'])->middleware('throttle:creator-onboarding')->name('kyc.return');

    // Step 4 of 4: payout method. Viewing and changing it need a passkey or authenticator code
    // confirmed in the last 10 minutes (SEC-04, decision D-13); signing in is not enough.
    Route::middleware('step-up:second-factor')->group(function (): void {
        Route::get('/payout', [ApplicationController::class, 'payout'])->name('payout');
        Route::post('/payout', [ApplicationController::class, 'savePayout'])->middleware('throttle:creator-onboarding');
    });

    Route::get('/review', [ApplicationController::class, 'review'])->name('review');
    Route::post('/review', [ApplicationController::class, 'submit'])->middleware('throttle:creator-onboarding');

    // Board 7 · In review and board 8 · Action needed; Studio sends everyone it refuses here.
    Route::get('/status', [StatusController::class, 'show'])->name('status');

    Route::get('/handle-check', [OnboardingController::class, 'handleCheck'])->middleware('throttle:handle-check')->name('handle-check');
});

// Decision D-01: staff view an ID image fetched from the provider on demand, never stored.
Route::middleware(['auth', 'staff'])
    ->get('/'.config()->string('fanfolio.admin.path').'/kyc-documents/{creator}/{side}', [KycDocumentController::class, 'show'])
    ->name('creator.kyc-document');
