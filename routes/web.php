<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Marketing site (canvas MarketingCreators). Open to everyone, signed in or not.
Route::view('/creators', 'marketing.creators')->name('marketing.creators');

// Placeholder legal pages. The legal entity and terms are an open decision (CLAUDE.md):
// the body stays [LEGAL TEXT] until the owner supplies it.
Route::view('/terms', 'legal.page', ['title' => 'Terms'])->name('legal.terms');
Route::view('/privacy', 'legal.page', ['title' => 'Privacy Policy'])->name('legal.privacy');
Route::view('/records-notice', 'legal.page', ['title' => '2257 records notice'])->name('legal.records');
