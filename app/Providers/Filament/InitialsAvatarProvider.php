<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The panels' user avatar, drawn here as an inline SVG. Filament's default calls
 * ui-avatars.com with the user's name: an outside call the CSP blocks and that would
 * hand usernames to a third party.
 */
final class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $name = ltrim(Filament::getNameForDefaultAvatar($record), '@ ');
        $initial = mb_strtoupper(mb_substr($name, 0, 1)) ?: '?';

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40"><rect width="40" height="40" fill="%s"/><text x="20" y="26" font-family="Inter, sans-serif" font-size="18" font-weight="600" text-anchor="middle" fill="%s">%s</text></svg>',
            config()->string('fanfolio.design.surface-raised'),
            config()->string('fanfolio.design.ink'),
            htmlspecialchars($initial, ENT_XML1 | ENT_QUOTES),
        );

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
