<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\View\ViewException;

// Decision D-19: icons and illustrations come from the screens canvas and use token classes only.

/** @return list<string> the names a generated component's map holds */
function canvasComponentNames(string $component): array
{
    preg_match_all("/^\s+'([a-z0-9-]+)' => '/m", (string) file_get_contents(resource_path("views/components/ui/{$component}.blade.php")), $m);

    return $m[1];
}

it('renders every canvas icon as a decorative stroke SVG, or a labelled one', function (): void {
    $names = canvasComponentNames('icon');
    expect($names)->toHaveCount(22);

    foreach ($names as $name) {
        $html = Blade::render('<x-ui.icon :name="$name" />', ['name' => $name]);
        expect($html)->toContain('viewBox="0 0 24 24"')->toContain('stroke="currentColor"')->toContain('aria-hidden="true"');
    }

    expect(Blade::render('<x-ui.icon name="lock" label="Protected" />'))
        ->toContain('role="img"')->toContain('aria-label="Protected"')->not->toContain('aria-hidden');
});

it('renders every canvas illustration, hidden from screen readers', function (): void {
    $names = canvasComponentNames('illustration');
    expect($names)->toHaveCount(12);

    foreach ($names as $name) {
        expect(Blade::render('<x-ui.illustration :name="$name" />', ['name' => $name]))
            ->toContain('viewBox="0 0 120 120"')->toContain('aria-hidden="true"');
    }
});

it('keeps hex colours out of the generated components', function (string $component): void {
    expect((string) file_get_contents(resource_path("views/components/ui/{$component}.blade.php")))
        ->not->toMatch('/#[0-9a-fA-F]{6}\b/');
})->with(['icon', 'illustration']);

it('refuses an unknown name instead of rendering nothing', function (): void {
    Blade::render('<x-ui.icon name="no-such-icon" />');
})->throws(ViewException::class);
