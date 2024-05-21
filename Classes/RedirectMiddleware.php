<?php

namespace NEOSidekick\WordPressAssetRedirect;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Persistence\Exception\InvalidQueryException;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Repository\AssetRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class RedirectMiddleware implements MiddlewareInterface
{
    /**
     * @Flow\Inject
     * @var AssetRepository
     */
    protected $assetRepository;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $systemLogger;

    /**
     * @Flow\Inject
     * @var ResponseFactoryInterface
     */
    protected $responseFactory;

    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (!str_contains($path, '/wp-content/uploads/')) {
            $this->systemLogger->debug('Path does not contain "/wp-content/uploads/"', [
                ...LogEnvironment::fromMethodName(__METHOD__),
                'path' => $path
            ]);
            return $handler->handle($request);
        }
        $pathAsArray = explode('/', $path);
        $filename = array_pop($pathAsArray);
        $filenameAsArray = explode('.', $filename);
        array_pop($filenameAsArray);
        $filenameWithoutExtension = join('.', $filenameAsArray);
        // Remove thumbnail size
        $filenameWithoutExtensionWithoutThumbnailSize = preg_replace('(-[0-9]{2,4}x[0-9]{2,4})', '', $filenameWithoutExtension);
        try {
            $queryResult = $this->assetRepository->findBySearchTermOrTags($filenameWithoutExtensionWithoutThumbnailSize);
        } catch (InvalidQueryException $e) {
            $this->systemLogger->debug('Query failed', [
                ...LogEnvironment::fromMethodName(__METHOD__),
                'path' => $path,
                'pathAsArray' => $pathAsArray,
                'filename' => $filename,
                'filenameAsArrayWithoutExtension' => $filenameAsArray,
                'filenameWithoutExtension' => $filenameWithoutExtension,
                'filenameWithoutExtensionWithoutThumbnailSize' => $filenameWithoutExtensionWithoutThumbnailSize
            ]);
            // if an error occurs here, we just skip
            return $handler->handle($request);
        }

        if ($queryResult->count() === 0) {
            $this->systemLogger->debug('No suitable asset found', [
                ...LogEnvironment::fromMethodName(__METHOD__),
                'path' => $path,
                'pathAsArray' => $pathAsArray,
                'filename' => $filename,
                'filenameAsArrayWithoutExtension' => $filenameAsArray,
                'filenameWithoutExtension' => $filenameWithoutExtension,
                'filenameWithoutExtensionWithoutThumbnailSize' => $filenameWithoutExtensionWithoutThumbnailSize
            ]);
            return $handler->handle($request);
        }

        /** @var AssetInterface $firstFoundAsset */
        $firstFoundAsset = $queryResult->getFirst();
        $this->systemLogger->debug(sprintf('Found %s assets, chose first asset "%s"', $queryResult->count(), $firstFoundAsset->getResource()->getFilename()), [
            ...LogEnvironment::fromMethodName(__METHOD__),
            'path' => $path,
            'pathAsArray' => $pathAsArray,
            'filename' => $filename,
            'filenameAsArrayWithoutExtension' => $filenameAsArray,
            'filenameWithoutExtension' => $filenameWithoutExtension,
            'filenameWithoutExtensionWithoutThumbnailSize' => $filenameWithoutExtensionWithoutThumbnailSize
        ]);

        $publicPersistentResourceUri = $this->resourceManager->getPublicPersistentResourceUri($firstFoundAsset->getResource());

        return $this->buildResponse($publicPersistentResourceUri);
    }

    /**
     * Inspired by class Neos\RedirectHandler\RedirectService
     *
     * @param  string  $publicPersistentResourceUri
     * @return ResponseInterface|null
     */
    protected function buildResponse(string $publicPersistentResourceUri): ?ResponseInterface
    {
        /** @psalm-suppress UndefinedConstant */
        if (headers_sent() === true && FLOW_SAPITYPE !== 'CLI') {
            return null;
        }

        $statusCode = 301;
        $response = $this->responseFactory->createResponse($statusCode);

        return $response->withHeader('Location', $publicPersistentResourceUri)
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0')
            ->withHeader('Expires', 'Sat, 26 Jul 1997 05:00:00 GMT');
    }
}
