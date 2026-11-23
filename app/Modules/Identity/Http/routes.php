<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\AccountController;
use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Identity\Http\Controllers\PhoneController;
use App\Modules\Identity\Http\Controllers\SignUpController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::view('/', 'identity::landing')->name('landing');

    Route::get('/signup', [SignUpController::class, 'show'])->name('signup');
    Route::post('/signup', [SignUpController::class, 'store'])->middleware('throttle:signup');

    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/signup/phone', [PhoneController::class, 'show'])->name('signup.phone');
    Route::post('/signup/phone/send', [PhoneController::class, 'send'])->middleware('throttle:otp-send')->name('signup.phone.send');
    Route::post('/signup/phone/verify', [PhoneController::class, 'verify'])->middleware('throttle:otp-verify')->name('signup.phone.verify');

    Route::get('/account', [AccountController::class, 'show'])->name('account');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});
