<?php
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

use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Package\Exception\InvalidPackageStateException;
use Neos\Flow\Package\Exception\UnknownPackageException;
use Neos\Neos\Domain\Service\ContentContext;

/**
 * The Trados Import Service
 *
 * @Flow\Scope("singleton")
 */
class ImportService
{
    /**
     * @var string
     */
    const SUPPORTED_FORMAT_VERSION = '1.0';

    /**
     * @Flow\InjectConfiguration(path = "languageDimension")
     * @var string
     */
    protected $languageDimension;

    /**
     * @Flow\Inject
     * @var \Neos\Flow\Package\PackageManager
     */
    protected $packageManager;

    /**
     * @Flow\Inject
     * @var \Neos\Flow\Persistence\PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var \Neos\Neos\Domain\Repository\SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var \Neos\ContentRepository\Domain\Repository\WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var \Neos\Neos\Domain\Service\ContentContextFactory
     */
    protected $contentContextFactory;

    /**
     * @Flow\Inject
     * @var \Neos\Flow\Security\Context
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var \Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionPresetSource;

    /**
     * @var \XMLReader
     */
    protected $xmlReader;

    /**
     * @var string
     */
    protected $currentNodeIdentifier;

    /**
     * @var string
     */
    protected $currentNodeName;

    /**
     * @var array
     */
    protected $currentNodeData;

    /**
     * @var array
     */
    protected $currentNodeVariants;

    /**
     * @var Workspace
     */
    protected $targetWorkspace;

    /**
     * @var string
     */
    protected $sourceWorkspaceName;

    /**
     * @var string
     */
    protected $sourceLanguage;

    /**
     * @var string
     */
    protected $targetLanguage;

    /**
     * @var ContentContext
     */
    protected $contentContext;

    /**
     * @var array
     */
    protected $languageDimensionPreset;

    /**
     *
     *
     * @param string $pathAndFilename
     * @param string $workspaceName
     * @param string $targetLanguage
     * @return string
     * @throws InvalidPackageStateException
     * @throws UnknownPackageException
     */
    public function importFromFile($pathAndFilename, $workspaceName = null, $targetLanguage = null)
    {
        /** @var \Neos\Neos\Domain\Model\Site $importedSite */
        $site = null;
        $xmlReader = new \XMLReader();
        $xmlReader->open($pathAndFilename, null, LIBXML_PARSEHUGE);

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

            $this->languageDimensionPreset = $this->contentDimensionPresetSource->findPresetByDimensionValues($this->languageDimension, [$this->targetLanguage]);

            if ($this->languageDimensionPreset === null) {
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
     * Imports the nodes from the xml reader.
     *
     * @param \XMLReader $xmlReader A prepared XML Reader with the nodes to import
     * @return void
     */
    protected function importNodes(\XMLReader $xmlReader)
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
                    if ((string)$xmlReader->name === 'nodes') {
                        return; // all done, reached the closing </nodes> tag
                    }
                    $this->parseEndElement($xmlReader);
                break;
            }
        }
    }

    /**
     * Parses the given XML element and adds its content to the internal content tree
     *
     * @param \XMLReader $xmlReader The XML Reader with the element to be parsed as its root
     * @return void
     * @throws \Exception
     */
    protected function parseElement(\XMLReader $xmlReader)
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
                throw new \Exception(sprintf('Unexpected element <%s> ', $elementName), 1423578065);
            break;
        }
    }

    /**
     * Parses the closing tags writes data to the database then
     *
     * @param \XMLReader $reader
     * @return void
     * @throws \Exception
     */
    protected function parseEndElement(\XMLReader $reader)
    {
        switch ($reader->name) {
            case 'node':
            break;
            case 'variant':
                // we have collected all data for the node so we save it
                $this->persistNodeData($this->currentNodeData);
            break;
            default:
                throw new \Exception(sprintf('Unexpected end element <%s> ', $reader->name), 1423578066);
            break;
        }
    }

    /**
     * Parses the content of the dimensions-tag and returns the dimensions as an array
     * 'dimension name' => dimension value
     *
     * @param \XMLReader $reader reader positioned just after an opening dimensions-tag
     * @return array the dimension values
     */
    protected function parseDimensionsElement(\XMLReader $reader)
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
     * @return array the properties
     * @throws \Exception
     */
    protected function parsePropertiesElement(\XMLReader $reader)
    {
        $properties = [];
        $currentProperty = null;

        while ($reader->read()) {
            switch ($reader->nodeType) {
                case \XMLReader::ELEMENT:
                    $currentProperty = $reader->name;
                    if ($reader->getAttribute('type') !== 'string') {
                        throw new \Exception(sprintf('Non-string property "%s" found in XML file', $currentProperty), 1474362378);
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
     * @param array $translatedData
     * @return void
     */
    protected function persistNodeData(array $translatedData)
    {
        if ($this->currentNodeVariants === []) {
            return;
        }

        /** @var \Neos\ContentRepository\Domain\Model\NodeInterface $currentNodeVariant */
        $currentNodeVariant = array_reduce($this->currentNodeVariants, function ($carry, \Neos\ContentRepository\Domain\Model\NodeInterface $nodeVariant) use ($translatedData) {
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

        $dimensions = array_merge($translatedData['dimensionValues'], [$this->languageDimension => $this->languageDimensionPreset['values']]);
        $targetDimensions = array_map(
            function ($values) {
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
