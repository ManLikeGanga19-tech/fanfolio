<?php

declare(strict_types=1);

namespace App\Support\View;

/**
 * Slides of the auth carousel (canvas boards AuthLogin and AuthSignup). The canvas draws five
 * slides but gives the copy of only two ("Create on your terms.", "Closer to the creators you
 * love."); the third uses the landing headline. The owner supplies two more photos and their
 * copy to reach five (docs/screens/conformance.md).
 */
final class AuthSlides
{
    /** Index of the slide each page opens on, as drawn on its board. */
    public const int LOGIN = 0;

    public const int SIGNUP = 1;

    /**
     * @return list<array{image: string, headline: string, line: string}>
     */
    public static function all(): array
    {
        return [
            [
                'image' => 'resources/images/auth/creator-studio.webp',
                'headline' => 'Create on your terms.',
                'line' => 'Set your prices, own your audience and keep 80% of what you earn.',
            ],
            [
                'image' => 'resources/images/auth/fan-evening.webp',
                'headline' => 'Closer to the creators you love.',
                'line' => 'Subscribe, unlock exclusive posts and message creators directly. Your name stays private.',
            ],
            [
                'image' => 'resources/images/auth/phone-glow.webp',
                'headline' => 'Support the creators you love, directly.',
                'line' => 'Pay by card or crypto; creators get paid fast.',
            ],
        ];
    }
}
