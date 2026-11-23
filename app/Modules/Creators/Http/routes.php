<?php

declare(strict_types=1);

use App\Modules\Creators\Http\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;

// Creator onboarding (screens: Become a creator, Profile basics). Signed-in accounts only.
Route::middleware('auth')->prefix('creator')->name('creator.')->group(function (): void {
    Route::get('/start', [OnboardingController::class, 'start'])->name('start');
    Route::post('/start', [OnboardingController::class, 'begin'])->middleware('throttle:creator-onboarding');

    Route::get('/profile', [OnboardingController::class, 'profile'])->name('profile');
    Route::post('/profile', [OnboardingController::class, 'saveProfile'])->middleware('throttle:creator-onboarding');

    Route::get('/handle-check', [OnboardingController::class, 'handleCheck'])->middleware('throttle:handle-check')->name('handle-check');
});
