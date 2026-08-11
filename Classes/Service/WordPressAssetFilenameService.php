<?php

declare(strict_types=1);

namespace NEOSidekick\WordPressAssetRedirect\Service;

/**
 * Shares the WordPress thumbnail filename convention between imports and redirects.
 */
final class WordPressAssetFilenameService
{
    private const THUMBNAIL_SIZE_PATTERN = '(-[0-9]{2,4}x[0-9]{2,4})';

    public function isThumbnail(string $filename): bool
    {
        return preg_match(self::THUMBNAIL_SIZE_PATTERN, $filename) === 1;
    }

    public function removeThumbnailSize(string $filename): string
    {
        return preg_replace(self::THUMBNAIL_SIZE_PATTERN, '', $filename) ?? $filename;
    }
}
