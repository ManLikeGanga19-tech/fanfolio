<?php

declare(strict_types=1);

namespace App\Support\View;

/**
 * Photos for a carousel, from resources/images/<folder>. Drop three images into the
 * folder (WebP preferred) to change them; until a folder has three, the auth photos
 * are used. Files are taken in name order.
 */
final class PhotoSlides
{
    public const int COUNT = 3;

    private const array EXTENSIONS = ['webp', 'jpg', 'jpeg', 'png', 'avif'];

    /**
     * @return list<string> paths relative to the project root, for Vite::asset()
     */
    public static function for(string $folder): array
    {
        $slides = self::inFolder($folder);

        return count($slides) >= self::COUNT ? $slides : self::inFolder('auth');
    }

    /**
     * @return list<string>
     */
    private static function inFolder(string $folder): array
    {
        $files = glob(resource_path('images/'.basename($folder).'/*')) ?: [];

        $images = array_values(array_filter(
            $files,
            static fn (string $file): bool => in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::EXTENSIONS, true),
        ));
        sort($images);

        return array_map(
            static fn (string $file): string => 'resources/images/'.basename($folder).'/'.basename($file),
            array_slice($images, 0, self::COUNT),
        );
    }
}
