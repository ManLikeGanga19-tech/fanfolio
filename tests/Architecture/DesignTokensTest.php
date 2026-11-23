<?php

declare(strict_types=1);

use Tests\Architecture\Support\Files;

// Brief: "UI uses the design-system tokens ... No hard-coded hex values in views."

it('has no hard-coded colours in views', function (): void {
    $dirs = [resource_path('views'), ...glob(app_path('Modules/*/Resources/views')) ?: []];
    $offences = [];

    foreach ($dirs as $dir) {
        foreach (Files::php($dir) as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match_all('/(#[0-9a-fA-F]{3,8}\b(?![-\w])|\brgba?\(|\bhsla?\(|\boklch\()/', $source, $matches) > 0) {
                foreach ($matches[0] as $match) {
                    $offences[] = Files::relative($file).": {$match}";
                }
            }
        }
    }

    expect($offences)->toBe([], "Use token utilities (bg-surface, text-ink, ...) instead of:\n".implode("\n", $offences));
});

it('keeps resources/css/tokens.css in sync with tokens.json', function (): void {
    /** @var array{color: array{tokens: list<array{name: string, value: array{dark: string, light: string}}>}} $tokens */
    $tokens = json_decode((string) file_get_contents(base_path('docs/design-system/tokens.json')), true, flags: JSON_THROW_ON_ERROR);
    $css = (string) file_get_contents(resource_path('css/tokens.css'));

    [$dark, $light] = explode('[data-theme="light"]', $css, 2);

    foreach ($tokens['color']['tokens'] as $token) {
        $name = $token['name'];
        expect($dark)->toContain("--ff-{$name}: ".strtolower($token['value']['dark']).';')
            ->and($light)->toContain("--ff-{$name}: ".strtolower($token['value']['light']).';')
            ->and($css)->toContain("--color-{$name}: var(--ff-{$name});");
    }
});
