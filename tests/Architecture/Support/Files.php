<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class Files
{
    /**
     * @return list<string>
     */
    public static function php(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    public static function relative(string $path): string
    {
        return ltrim(str_replace(str_replace('\\', '/', base_path()), '', $path), '/');
    }

    /**
     * @param  list<Violation>  $violations
     */
    public static function report(array $violations): string
    {
        return implode("\n", array_map(static fn (Violation $v): string => (string) $v, $violations));
    }
}
