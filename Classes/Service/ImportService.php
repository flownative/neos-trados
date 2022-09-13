<?php
/** @noinspection PhpComposerExtensionStubsInspection */
declare(strict_types=1);

namespace Flownative\Neos\Trados\Service;

/*
 * This file is part of the Flownative.Neos.Trados package.
 *
 * (c) Flownative GmbH - https://www.flownative.com/
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Exception\NodeException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Package\Exception\InvalidPackageStateException;
use Neos\Flow\Package\Exception\UnknownPackageException;
use Neos\Flow\Package\PackageManager;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;

/**
 * The Trados Import Service
 *
 * @Flow\Scope("singleton")
 */
class ImportService extends AbstractService
{
    /**
     * @Flow\Inject
     * @var PackageManager
     */
    protected $packageManager;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionPresetSource;

    protected string $currentNodeIdentifier;
    protected string $currentNodeName;
    protected array $currentNodeData;
    protected array $currentNodeVariants;
    protected ?Workspace $targetWorkspace;
    protected string $sourceLanguage;
    protected ?string $targetLanguage = null;
    protected ContentContext $contentContext;
    protected array $languageDimensionPreset;

    /**
     * @throws InvalidPackageStateException
     * @throws UnknownPackageException
     * @throws IllegalObjectTypeException
     */
    public function importFromFile(string $pathAndFilename, string $workspaceName = null, string $targetLanguage = null): string
    {
        $xmlReader = new \XMLReader();
        $opened = $xmlReader->open($pathAndFilename, null, LIBXML_PARSEHUGE);
        if ($opened === false) {
            throw new \RuntimeException('Could not open file', 1663067260);
        }

        while ($xmlReader->read()) {
            if ($xmlReader->nodeType !== \XMLReader::ELEMENT || $xmlReader->name !== 'content') {
                continue;
            }

            $sourceWorkspaceName = $xmlReader->getAttribute('workspace');
            $this->sourceLanguage = $xmlReader->getAttribute('sourceLanguage');
            $this->targetLanguage = $targetLanguage ?: $xmlReader->getAttribute('targetLanguage');

            if ($this->targetLanguage === null) {
                throw new \RuntimeException('No target language given (neither in XML nor as argument)', 1475578770);
            }

            $this->languageDimensionPreset = $this->contentDimensionPresetSource->findPresetsByTargetValues([$this->languageDimension => [$this->targetLanguage]]);

            if ($this->languageDimensionPreset === []) {
                throw new \RuntimeException(sprintf('No language dimension preset found for language "%s".', $this->targetLanguage), 1571230670);
            }

            $sitePackageKey = $xmlReader->getAttribute('sitePackageKey');
            if (!$this->packageManager->isPackageAvailable($sitePackageKey)) {
                throw new UnknownPackageException(sprintf('Package "%s" specified in the XML as site package does not exist.', $sitePackageKey), 1474357509);
            }
            if ($this->siteRepository->findOneBySiteResourcesPackageKey($sitePackageKey) === null) {
                throw new InvalidPackageStateException(sprintf('Site for package "%s" specified in the XML as site package could not be found.', $sitePackageKey), 1474357541);
            }

            while ($xmlReader->nodeType !== \XMLReader::ELEMENT || $xmlReader->name !== 'nodes') {
                if (!$xmlReader->read()) {
                    break;
                }
            }

            $formatVersion = $xmlReader->getAttribute('formatVersion');
            if ($formatVersion === null) {
                throw new \RuntimeException('The XML file could not be parsed.', 1474364384);
            }
            if ($formatVersion !== self::SUPPORTED_FORMAT_VERSION) {
                throw new \RuntimeException(sprintf('The XML file contains an unsupported format version (%s).', $formatVersion), 1473411690);
            }

            $this->targetWorkspace = $this->workspaceRepository->findOneByName($workspaceName ?: $sourceWorkspaceName);
            if ($this->targetWorkspace === null) {
                $liveWorkspace = $this->workspaceRepository->findOneByName('live');
                $this->targetWorkspace = new Workspace($workspaceName, $liveWorkspace);
                $this->workspaceRepository->add($this->targetWorkspace);
                $this->persistenceManager->persistAll();
            }

            $this->contentContext = $this->contentContextFactory->create([
                'workspaceName' => $sourceWorkspaceName,
                'dimensions' => [],
                'targetDimensions' => [],
                'invisibleContentShown' => true,
                'removedContentShown' => false,
                'inaccessibleContentShown' => false
            ]);

            $this->securityContext->withoutAuthorizationChecks(function () use ($xmlReader) {
                $this->importNodes($xmlReader);
            });
        }

        return $this->targetLanguage;
    }

    /**
     * @throws NodeException
     */
    protected function importNodes(\XMLReader $xmlReader): void
    {
        while ($xmlReader->read()) {
            if ($xmlReader->nodeType === \XMLReader::COMMENT) {
                continue;
            }

            switch ($xmlReader->nodeType) {
                case \XMLReader::ELEMENT:
                    if (!$xmlReader->isEmptyElement) {
                        $this->parseElement($xmlReader);
                    }
                break;
                case \XMLReader::END_ELEMENT:
                    if ($xmlReader->name === 'nodes') {
                        return; // all done, reached the closing </nodes> tag
                    }
                    $this->parseEndElement($xmlReader);
                break;
            }
        }
    }

    protected function parseElement(\XMLReader $xmlReader): void
    {
        $elementName = $xmlReader->name;
        switch ($elementName) {
            case 'node':
                $this->currentNodeIdentifier = $xmlReader->getAttribute('identifier');
                $this->currentNodeName = $xmlReader->getAttribute('nodeName');
                $this->currentNodeVariants = array_filter($this->contentContext->getNodeVariantsByIdentifier($this->currentNodeIdentifier));
            break;
            case 'variant':
                $this->currentNodeData = [
                    'dimensionValues' => [],
                    'properties' => []
                ];
            break;
            case 'dimensions':
                $this->currentNodeData['dimensionValues'] = $this->parseDimensionsElement($xmlReader);
            break;
            case 'properties':
                $this->currentNodeData['properties'] = $this->parsePropertiesElement($xmlReader);
            break;
            default:
                throw new \RuntimeException(sprintf('Unexpected element <%s> ', $elementName), 1423578065);
        }
    }

    /**
     * @throws NodeException
     */
    protected function parseEndElement(\XMLReader $reader): void
    {
        switch ($reader->name) {
            case 'node':
            break;
            case 'variant':
                // we have collected all data for the node so we save it
                $this->persistNodeData($this->currentNodeData);
            break;
            default:
                throw new \RuntimeException(sprintf('Unexpected end element <%s> ', $reader->name), 1423578066);
        }
    }

    /**
     * Parses the content of the dimensions-tag and returns the dimensions as an array
     * 'dimension name' => dimension value
     *
     * @param \XMLReader $reader reader positioned just after an opening dimensions-tag
     */
    protected function parseDimensionsElement(\XMLReader $reader): array
    {
        $dimensions = [];
        $currentDimension = null;

        while ($reader->read()) {
            switch ($reader->nodeType) {
                case \XMLReader::ELEMENT:
                    $currentDimension = $reader->name;
                break;
                case \XMLReader::END_ELEMENT:
                    if ($reader->name === 'dimensions') {
                        return $dimensions;
                    }
                break;
                case \XMLReader::CDATA:
                case \XMLReader::TEXT:
                    $dimensions[$currentDimension][] = $reader->value;
                break;
            }
        }

        return $dimensions;
    }

    /**
     * Parses the content of the properties-tag and returns the properties as an array
     * 'property name' => property value
     *
     * @param \XMLReader $reader reader positioned just after an opening properties-tag
     */
    protected function parsePropertiesElement(\XMLReader $reader): array
    {
        $properties = [];
        $currentProperty = null;

        while ($reader->read()) {
            switch ($reader->nodeType) {
                case \XMLReader::ELEMENT:
                    $currentProperty = $reader->name;
                    if ($reader->getAttribute('type') !== 'string') {
                        throw new \RuntimeException(sprintf('Non-string property "%s" found in XML file', $currentProperty), 1474362378);
                    }

                    if ($reader->isEmptyElement) {
                        $properties[$currentProperty] = '';
                    }
                break;
                case \XMLReader::END_ELEMENT:
                    if ($reader->name === 'properties') {
                        return $properties;
                    }
                break;
                case \XMLReader::CDATA:
                case \XMLReader::TEXT:
                    $properties[$currentProperty] = $reader->value;
                break;
            }
        }

        return $properties;
    }

    /**
     * @throws NodeException
     */
    protected function persistNodeData(array $translatedData): void
    {
        if ($this->currentNodeVariants === []) {
            return;
        }

        /** @var NodeInterface $currentNodeVariant */
        $currentNodeVariant = array_reduce($this->currentNodeVariants, function ($carry, NodeInterface $nodeVariant) use ($translatedData) {
            // best match
            $dimensionsToMatch = array_merge($translatedData['dimensionValues'], [$this->languageDimension => [$this->targetLanguage]]);
            if ($nodeVariant->getDimensions() === $dimensionsToMatch) {
                return $nodeVariant;
            }

            // next best match
            if ($nodeVariant->getDimensions() === $translatedData['dimensionValues']) {
                return $nodeVariant;
            }

            return $carry;
        });

        $dimensions = array_merge($translatedData['dimensionValues'], [$this->languageDimension => $this->languageDimensionPreset[$this->languageDimension]['values']]);
        $targetDimensions = array_map(
            static function ($values) {
                return current($values);
            },
            $dimensions
        );
        $targetDimensions = array_merge($targetDimensions, [$this->languageDimension => $this->targetLanguage]);

        $targetContentContext = $this->contentContextFactory->create([
            'workspaceName' => $this->targetWorkspace->getName(),
            'dimensions' => $dimensions,
            'targetDimensions' => $targetDimensions,
            'invisibleContentShown' => true,
        ]);

        if ($currentNodeVariant === null) {
            return;
        }

        $propertiesToSet = [];
        foreach ($translatedData['properties'] as $key => $value) {
            if ($currentNodeVariant->getProperty($key) !== $value) {
                $propertiesToSet[$key] = $value;
            }
        }

        // don't adopt node if no properties have changed and there is a fallback in place
        if ($propertiesToSet === [] && count($targetContentContext->getDimensions()[$this->languageDimension]) > 1) {
            return;
        }

        $translatedNodeVariant = $targetContentContext->adoptNode($currentNodeVariant);
        foreach ($propertiesToSet as $key => $value) {
            $translatedNodeVariant->setProperty($key, $value);
        }
    }
}
