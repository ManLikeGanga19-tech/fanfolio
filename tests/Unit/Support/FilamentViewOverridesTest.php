<?php

declare(strict_types=1);

// Decision D-07: Filament's inline scripts get the per-request CSP nonce through three view
// overrides. They must stay identical to Filament's originals apart from the nonce, so
// an upgrade that changes an original fails here until the copy is re-synced.

const FILAMENT_VIEW_OVERRIDES = [
    'resources/views/vendor/filament/assets.blade.php' => 'vendor/filament/support/resources/views/assets.blade.php',
    'resources/views/vendor/filament-panels/components/layout/base.blade.php' => 'vendor/filament/filament/resources/views/components/layout/base.blade.php',
    'resources/views/vendor/filament-panels/livewire/sidebar.blade.php' => 'vendor/filament/filament/resources/views/livewire/sidebar.blade.php',
];

it('matches Filament\'s original views apart from the nonce', function (string $override, string $original): void {
    $ours = (string) file_get_contents(base_path($override));

    // Drop our header comment and the nonce attributes.
    $ours = (string) preg_replace('/\A\{\{-- FanFolio override.*?--\}\}\n/s', '', $ours);
    $ours = str_replace(' nonce="{{ \\Illuminate\\Support\\Facades\\Vite::cspNonce() }}"', '', $ours);

    expect($ours)->toBe((string) file_get_contents(base_path($original)));
})->with(array_map(null, array_keys(FILAMENT_VIEW_OVERRIDES), array_values(FILAMENT_VIEW_OVERRIDES)));

it('gives every inline script in the overrides the nonce', function (string $override): void {
    preg_match_all('/<script(?![^>]*\bsrc=)[^>]*>/', (string) file_get_contents(base_path($override)), $tags);

    expect($tags[0])->not->toBeEmpty();
    foreach ($tags[0] as $tag) {
        expect($tag)->toContain('nonce="{{ \\Illuminate\\Support\\Facades\\Vite::cspNonce() }}"');
    }
})->with(array_keys(FILAMENT_VIEW_OVERRIDES));
