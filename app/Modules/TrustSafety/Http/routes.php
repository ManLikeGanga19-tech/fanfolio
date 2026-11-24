<?php

declare(strict_types=1);

use App\Modules\TrustSafety\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

// The "Report" sheet (canvas FanMore). Signed-in fans and creators only.
Route::middleware(['auth', 'throttle:reports'])->post('/reports', [ReportController::class, 'store'])->name('reports.store');
