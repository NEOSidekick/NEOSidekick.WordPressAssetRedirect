<?php

declare(strict_types=1);

namespace NEOSidekick\WordPressAssetRedirect\Command;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Property\PropertyMapper;
use Neos\Flow\Property\PropertyMappingConfiguration;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\TagRepository;
use Neos\Media\TypeConverter\AssetInterfaceConverter;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use NEOSidekick\WordPressAssetRedirect\Exception\InvalidPathException;
use NEOSidekick\WordPressAssetRedirect\Exception\PathNotReadableException;
use NEOSidekick\WordPressAssetRedirect\Service\AssetDirectoryIteratorService;

/**
 * Command controller for importing WordPress assets into the Neos Media module
 */
final class AssetsCommandController extends CommandController
{
    private const PERSISTENCE_BATCH_SIZE = 250;

    /**
     * @Flow\Inject
     * @var AssetDirectoryIteratorService
     */
    protected $assetDirectoryIteratorService;

    /**
     * Counters for tracking import statistics
     */
    private int $importedCount = 0;
    private int $skippedCount = 0;
    private int $blockedCount = 0;
    private int $failedCount = 0;
    private array $importErrors = [];

    /**
     * @Flow\InjectConfiguration(package="Neos.Flow", path="resource.extensionsBlockedFromUpload")
     * @var array<string, bool>
     */
    protected array $extensionsBlockedFromUpload = [];

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var PropertyMapper
     */
    protected $propertyMapper;

    /**
     * @Flow\Inject
     * @var AssetRepository
     */
    protected $assetRepository;

    /**
     * @Flow\Inject
     * @var AssetCollectionRepository
     */
    protected $assetCollectionRepository;

    /**
     * @Flow\Inject
     * @var TagRepository
     */
    protected $tagRepository;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * Imports files from a WordPress uploads directory into the Neos Media module.
     *
     * This command recursively scans the directory specified by the --path argument,
     * imports each file as a resource, and creates an Asset in the specified collection or tag.
     * Assets must be assigned to either a collection or a tag. One of these two options is mandatory.
     * Optionally, you can filter files by type (e.g., 'image', 'document').
     *
     * @param string $path The absolute path to the root of the WordPress 'uploads' directory. This argument is required.
     * @param string|null $collection Title of the asset collection to import assets into. This argument is optional.
     * @param string|null $tag The label of the asset tag to assign to the imported assets. This argument is optional. If the tag does not exist, it will be created.
     * @param string|null $type Filter files by type (e.g., 'image', 'document'). If omitted, all files are processed.
     * @return void
     */
    public function importCommand(string $path, ?string $collection = null, ?string $tag = null, ?string $type = null): void
    {
        // Check that both collection and tag are not provided at the same time
        if ($collection !== null && $tag !== null) {
            $this->outputLine('<error>You can specify either a collection or a tag, but not both.</error>');
            $this->quit(1);
        }

        // Check that at least one of collection or tag is provided
        if ($collection === null && $tag === null) {
            $this->outputLine('<error>You must specify either a collection or a tag.</error>');
            $this->quit(1);
        }

        // Initialize target variables
        $target = null;
        $targetName = '';

        // Find the target asset collection if specified
        if ($collection !== null) {
            $target = $this->assetCollectionRepository->findOneByTitle($collection);
            if ($target === null) {
                $this->outputLine('<error>Asset collection "%s" not found. Please create it first.</error>', [$collection]);
                $this->quit(1);
            }
            $targetName = $collection;
        } elseif ($tag !== null) {
            // Find or create the tag if specified
            $target = $this->tagRepository->findOneByLabel($tag);
            if ($target === null) {
                // Create a new tag
                $newTag = new Tag($tag);
                $this->tagRepository->add($newTag);
                $this->persistenceManager->persistAll();
                $target = $newTag;
            }
            $targetName = $tag;
        }

        $targetIdentifier = $this->persistenceManager->getIdentifierByObject($target);
        if (!is_string($targetIdentifier)) {
            throw new \RuntimeException(sprintf('Could not determine the persistence identifier of target "%s".', $targetName));
        }

        // Validate the file type filter if provided
        if ($type !== null) {
            $availableTypes = $this->assetDirectoryIteratorService->getAvailableFilterTypes();
            if (!in_array($type, $availableTypes)) {
                $this->outputLine('<error>Invalid type "%s". Available types are: %s.</error>', [
                    $type,
                    implode(', ', $availableTypes)
                ]);
                $this->quit(1);
            }
        }

        try {
            if ($type !== null) {
                $this->outputLine('<info>Starting import from "%s" into "%s" (type: %s)...</info>', [
                    $path,
                    $targetName,
                    $type
                ]);
            } else {
                $this->outputLine('<info>Starting import from "%s" into "%s"...</info>', [$path, $targetName]);
            }

            // First, count the total number of files to process
            $totalFiles = 0;
            $countCallback = function (\SplFileInfo $fileInfo) use (&$totalFiles) {
                $totalFiles++;
            };
            $this->assetDirectoryIteratorService->iterate($path, $countCallback, $type);

            // Initialize progress bar
            $this->output->progressStart($totalFiles);

            // Process each file with progress updates and bounded persistence batches
            $filesSinceLastFlush = 0;
            $fileProcessorCallback = function (\SplFileInfo $fileInfo) use (
                &$target,
                &$filesSinceLastFlush,
                $targetIdentifier,
                $collection
            ) {
                $this->processFile($fileInfo, $target);
                $this->output->progressAdvance();

                $filesSinceLastFlush++;
                if ($filesSinceLastFlush >= self::PERSISTENCE_BATCH_SIZE) {
                    $this->persistAndClearState();
                    $target = $this->reloadTarget($targetIdentifier, $collection !== null);
                    $filesSinceLastFlush = 0;
                }
            };

            $this->assetDirectoryIteratorService->iterate($path, $fileProcessorCallback, $type);
            $this->output->progressFinish();

            if ($filesSinceLastFlush > 0) {
                $this->persistenceManager->persistAll();
            }
        } catch (InvalidPathException | PathNotReadableException $e) {
            $this->outputLine('<error>FATAL ERROR:</error> %s', [$e->getMessage()]);
            $this->quit(1);
        }

        // Check for non-fatal errors
        $errors = $this->assetDirectoryIteratorService->getErrors();

        // Display summary report
        $this->outputLine("\n<info>Import Summary:</info>");
        if ($type !== null) {
            $this->outputLine("- Total files of type '%s' found: %d", [$type, $totalFiles]);
        } else {
            $this->outputLine("- Total files found: %d", [$totalFiles]);
        }
        $this->outputLine("- New assets imported: %d", [$this->importedCount]);
        $this->outputLine("- Files skipped (already exist): %d", [$this->skippedCount]);
        $this->outputLine("- Files skipped (blocked extension): %d", [$this->blockedCount]);
        $this->outputLine("- Files failed: %d", [$this->failedCount]);

        if (empty($errors) && empty($this->importErrors)) {
            $this->outputLine("\n<info>Import completed successfully.</info>");
        } else {
            $totalErrors = count($errors) + count($this->importErrors);
            $this->outputLine("\n<warning>Import completed with %d errors:</warning>", [$totalErrors]);

            // Display directory iterator errors
            foreach ($errors as $index => $error) {
                $this->outputLine("%d) %s", [$index + 1, $error]);
            }

            // Display import errors
            $startIndex = count($errors) + 1;
            foreach ($this->importErrors as $index => $error) {
                $this->outputLine("%d) %s", [$startIndex + $index, $error]);
            }
        }
    }
    /**
     * Processes a single file for import into the Neos Media module
     *
     * This method imports the file as a resource, checks for duplicates,
     * and creates an Asset object with the appropriate metadata.
     * It uses a dedicated property mapping configuration to ensure a clean conversion.
     *
     * @param \SplFileInfo $fileInfo The file to process
     * @param AssetCollection|Tag $target The asset collection or tag to assign the asset to
     * @return void
     */
    private function processFile(\SplFileInfo $fileInfo, AssetCollection|Tag $target): void
    {
        $extension = strtolower($fileInfo->getExtension());
        // Resources blocked by Flow cannot be published and would abort the entire persistence batch.
        if ($extension !== '' && ($this->extensionsBlockedFromUpload[$extension] ?? false) === true) {
            $this->blockedCount++;
            return;
        }

        try {
            // Import the file as a resource
            $persistentResource = $this->resourceManager->importResource($fileInfo->getRealPath());

            // Check if an asset with this resource already exists
            $existingAsset = $this->assetRepository->findOneByResourceSha1($persistentResource->getSha1());
            if ($existingAsset !== null) {
                $this->skippedCount++;
                return;
            }

            // Create a dedicated property mapping configuration
            $propertyMappingConfiguration = new PropertyMappingConfiguration();
            $propertyMappingConfiguration->setTypeConverter(new AssetInterfaceConverter());
            $propertyMappingConfiguration->allowProperties('title', 'resource');
            $propertyMappingConfiguration->setTypeConverterOption(AssetInterfaceConverter::class, AssetInterfaceConverter::CONFIGURATION_CREATION_ALLOWED, true);

            // Prepare the source array for conversion
            $assetData = [
                'resource' => $persistentResource,
                'title' => $fileInfo->getFilename(),
            ];

            try {
                // Convert the data to an Asset object
                $newAsset = $this->propertyMapper->convert(
                    $assetData,
                    AssetInterface::class,
                    $propertyMappingConfiguration
                );

                // Verify the conversion was successful
                if (!($newAsset instanceof Asset)) {
                    throw new \RuntimeException(
                        sprintf('Failed to convert resource to asset for file "%s"', $fileInfo->getFilename())
                    );
                }
            } catch (\Exception $e) {
                $this->resourceManager->deleteResource($persistentResource);
                throw $e;
            }

            // Add the asset to the target collection or tag and persist it
            if ($target instanceof AssetCollection) {
                $target->addAsset($newAsset);
                $this->assetRepository->add($newAsset);
                $this->assetCollectionRepository->update($target);
            } elseif ($target instanceof Tag) {
                $newAsset->addTag($target);
                $this->assetRepository->add($newAsset);
            }

            $this->importedCount++;
        } catch (\Exception $e) {
            $this->failedCount++;
            $this->importErrors[] = sprintf(
                'Error importing file "%s": %s',
                $fileInfo->getRealPath(),
                $e->getMessage()
            );
        }
    }

    private function persistAndClearState(): void
    {
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();
    }

    private function reloadTarget(string $targetIdentifier, bool $isAssetCollection): AssetCollection|Tag
    {
        $target = $isAssetCollection
            ? $this->assetCollectionRepository->findByIdentifier($targetIdentifier)
            : $this->tagRepository->findByIdentifier($targetIdentifier);

        if (!$target instanceof AssetCollection && !$target instanceof Tag) {
            throw new \RuntimeException(sprintf('Could not reload import target "%s".', $targetIdentifier));
        }

        return $target;
    }
}
