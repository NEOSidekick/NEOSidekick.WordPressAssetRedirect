<?php
declare(strict_types=1);

namespace NEOSidekick\WordPressAssetRedirect\Service;

use Neos\Flow\Annotations as Flow;
use NEOSidekick\WordPressAssetRedirect\Exception\InvalidPathException;
use NEOSidekick\WordPressAssetRedirect\Exception\PathNotReadableException;

/**
 * Service for recursively iterating through a directory using SPL Iterators
 *
 * @Flow\Scope("singleton")
 */
final class AssetDirectoryIteratorService
{
    /**
     * Array to store errors encountered during iteration
     *
     * @var array
     */
    private array $errors = [];

    /**
     * Supported file type filters and their corresponding extensions
     *
     * @var array<string, array<string>>
     */
    private array $fileTypeFilters = [
        'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt']
    ];

    /**
     * Recursively iterates through a directory and executes a callback for each file
     *
     * This method validates the provided path, creates SPL iterators to traverse
     * the directory structure, and executes the provided callback for each file found.
     * Any errors encountered during iteration are logged for later retrieval.
     * If a fileType is specified, only files with matching extensions will be processed.
     *
     * @param string $path The absolute path to the directory to iterate
     * @param callable $actionCallback Callback to execute for each file, receives SplFileInfo as parameter
     * @param string|null $fileType Optional filter to process only files of a specific type
     * @throws InvalidPathException If the provided path is not a valid directory
     * @throws PathNotReadableException If the provided directory cannot be read due to permissions
     * @return void
     */
    public function iterate(string $path, callable $actionCallback, ?string $fileType = null): void
    {
        // Reset the error log before starting a new iteration
        $this->errors = [];

        // Validate the path
        if (!is_dir($path)) {
            throw new InvalidPathException(sprintf('The path "%s" is not a valid directory.', $path), 1720000001);
        }

        if (!is_readable($path)) {
            throw new PathNotReadableException(sprintf('The directory "%s" is not readable.', $path), 1720000002);
        }

        // Get the canonical path
        $canonicalPath = realpath($path);

        try {
            // Create recursive directory iterator with SKIP_DOTS flag to skip "." and ".." entries
            $directoryIterator = new \RecursiveDirectoryIterator(
                $canonicalPath,
                \RecursiveDirectoryIterator::SKIP_DOTS
            );

            // Create recursive iterator iterator with LEAVES_ONLY to only process files
            // and CATCH_GET_CHILD to handle permission errors
            $iterator = new \RecursiveIteratorIterator(
                $directoryIterator,
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            // Iterate through all files and execute the callback
            foreach ($iterator as $fileInfo) {
                // Apply file type filtering if specified
                if ($fileType !== null && isset($this->fileTypeFilters[$fileType])) {
                    $extension = strtolower($fileInfo->getExtension());
                    if (!in_array($extension, $this->fileTypeFilters[$fileType])) {
                        continue; // Skip files that don't match the filter
                    }
                }

                $actionCallback($fileInfo);
            }
        } catch (\UnexpectedValueException $e) {
            // Log any unexpected errors during iteration
            $this->errors[] = sprintf(
                'Error accessing path "%s": %s',
                $canonicalPath,
                $e->getMessage()
            );
        }
    }

    /**
     * Returns any errors encountered during iteration
     *
     * @return array Array of error messages
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Returns the available file type filters
     *
     * @return array Array of available filter types
     */
    public function getAvailableFilterTypes(): array
    {
        return array_keys($this->fileTypeFilters);
    }
}
