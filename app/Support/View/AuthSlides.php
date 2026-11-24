<?php

declare(strict_types=1);

namespace App\Support\View;

/**
 * Slides of the auth carousel (canvas boards AuthLogin and AuthSignup). The canvas draws five
 * slides but gives the copy of only two ("Create on your terms.", "Closer to the creators you
 * love."); the third uses the landing headline. Slides 4 and 5 use the owner's photos (24 Nov
 * 2026) with copy from the landing page's safety cards, pending the owner's approval.
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
            [
                'image' => 'resources/images/auth/collab.webp',
                'headline' => 'Create together, with consent.',
                'line' => 'Tag your co-creators. Everyone is ID-verified and approves the post before it goes live.',
            ],
            [
                'image' => 'resources/images/auth/protected.webp',
                'headline' => 'Your content, protected.',
                'line' => 'Media is encrypted and watermarked to each viewer, so leaks can be traced and taken down.',
            ],
        ];
    }
}
