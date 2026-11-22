<?php

declare(strict_types=1);

// Legal entity and terms are an open decision: pages show the [LEGAL TEXT] placeholder exactly.

it('shows placeholder legal pages', function (string $path, string $title): void {
    $this->get($path)
        ->assertOk()
        ->assertSee($title)
        ->assertSee('[LEGAL TEXT]')
        ->assertSee('privacy@[domain]');
})->with([
    ['/terms', 'Terms'],
    ['/privacy', 'Privacy Policy'],
    ['/records-notice', '2257 records notice'],
]);
