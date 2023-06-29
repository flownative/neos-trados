<?php
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

use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Service\ContentDimensionCombinator;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Service\ContentContext;

/**
 * The Trados Export Service
 *
 * @Flow\Scope("singleton")
 */
class ExportService extends AbstractService
{
    /**
     * @Flow\InjectConfiguration(path="export.workspace")
     * @var string
     */
    protected string $workspaceName;

    /**
     * @Flow\Inject
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @Flow\Inject
     * @var ContentDimensionCombinator
     */
    protected $contentDimensionCombinator;

    protected \XMLWriter $xmlWriter;
    protected ContentContext $contentContext;
    protected string $startingPoint;
    protected NodeInterface $startingPointNode;
    protected Site $site;
    protected string $sourceLanguage;
    protected ?string $targetLanguage;
    protected ?\DateTime $modifiedAfter;
    protected bool $ignoreHidden = true;
    protected int $depth = 0;
    protected bool $excludeChildDocuments = false;

    public function initialize(
        string $startingPoint,
        string $sourceLanguage,
        string $targetLanguage = null,
        \DateTime $modifiedAfter = null,
        bool $ignoreHidden = true,
        bool $excludeChildDocuments = false
    ): void {
        $this->startingPoint = $startingPoint;
        $this->sourceLanguage = $sourceLanguage;
        $this->targetLanguage = $targetLanguage;
        $this->modifiedAfter = $modifiedAfter;
        $this->ignoreHidden = $ignoreHidden;
        $this->excludeChildDocuments = $excludeChildDocuments;

        /** @var ContentContext $contentContext */
        $contentContext = $this->contentContextFactory->create([
            'workspaceName' => $this->workspaceName,
            'invisibleContentShown' => !$this->ignoreHidden,
            'removedContentShown' => false,
            'inaccessibleContentShown' => !$this->ignoreHidden,
            'dimensions' => current($this->getAllowedContentCombinationsForSourceLanguage($this->sourceLanguage))
        ]);
        $this->contentContext = $contentContext;

        $startingPointNode = $this->contentContext->getNodeByIdentifier($startingPoint);
        if ($startingPointNode === null) {
            $startingPointNode = $this->contentContext->getNode('/sites/' . $this->startingPoint);
            if ($startingPointNode === null) {
                throw new \RuntimeException(sprintf('Could not find node "%s"', $this->startingPoint), 1473241812);
            }
        }

        $this->startingPointNode = $startingPointNode;
        $pathArray = explode('/', (string)$this->startingPointNode->findNodePath());
        $this->site = $this->siteRepository->findOneByNodeName($pathArray[2]);

        if ($this->workspaceRepository->findOneByName($this->workspaceName) === null) {
            throw new \RuntimeException(sprintf('Could not find workspace "%s"', $this->workspaceName), 14732418113);
        }
    }

    /**
     * Fetches the site with the given name and exports it into XML.
     *
     * @return string
     * @throws \Exception
     */
    public function exportToString(): string
    {
        $this->xmlWriter = new \XMLWriter();
        $this->xmlWriter->openMemory();
        $this->xmlWriter->setIndent(true);

        $this->exportToXmlWriter();

        return $this->xmlWriter->outputMemory();
    }

    /**
     * Export into the given file.
     *
     * @throws \Exception
     */
    public function exportToFile(string $pathAndFilename): void
    {
        $this->xmlWriter = new \XMLWriter();
        $this->xmlWriter->openUri($pathAndFilename);
        $this->xmlWriter->setIndent(true);

        $this->exportToXmlWriter();

        $this->xmlWriter->flush();
    }

    /**
     * Export to the XMLWriter.
     *
     * @throws \Exception
     */
    protected function exportToXmlWriter(): void
    {
        $this->xmlWriter->startDocument('1.0', 'UTF-8');
        $this->xmlWriter->startElement('content');

        $this->xmlWriter->writeAttribute('name', $this->site->getName());
        $this->xmlWriter->writeAttribute('sitePackageKey', $this->site->getSiteResourcesPackageKey());
        $this->xmlWriter->writeAttribute('workspace', $this->workspaceName);
        $this->xmlWriter->writeAttribute('sourceLanguage', $this->sourceLanguage);
        if ($this->targetLanguage !== null) {
            $this->xmlWriter->writeAttribute('targetLanguage', $this->targetLanguage);
        }
        if ($this->modifiedAfter !== null) {
            $this->xmlWriter->writeAttribute('modifiedAfter', $this->modifiedAfter->format('c'));
        }

        $this->xmlWriter->startElement('nodes');
        $this->exportNodes((string)$this->startingPointNode->findNodePath(), $this->contentContext);
        $this->xmlWriter->endElement(); // nodes

        $this->xmlWriter->endElement();
        $this->xmlWriter->endDocument();
    }

    /**
     * @param string $startingPointNodePath path to the root node of the sub-tree to export. The specified node will not be included, only its sub nodes.
     * @throws \Exception
     */
    protected function exportNodes(string $startingPointNodePath, ContentContext $contentContext): void
    {
        $this->securityContext->withoutAuthorizationChecks(function () use ($startingPointNodePath, $contentContext) {
            $nodeDataList = $this->findNodeDataListToExport($startingPointNodePath, $contentContext);
            $this->exportNodeDataList($nodeDataList);
        });
    }

    /**
     * Find all nodes of the specified workspace lying below the path specified by
     * (and including) the given starting point.
     *
     * @throws IllegalObjectTypeException
     */
    protected function findNodeDataListToExport(string $pathStartingPoint, ContentContext $contentContext): array
    {
        $allowedContentCombinations = $this->getAllowedContentCombinationsForSourceLanguage($this->sourceLanguage);
        $sourceContexts = [];

        /** @var NodeData[] $nodeDataList */
        $nodeDataList = [];
        foreach ($allowedContentCombinations as $contentDimensions) {
            if ($this->excludeChildDocuments === true) {
                $node = $contentContext->getNode($pathStartingPoint)->getNodeData();
                $childNodes = [];
                $this->collectContentNodes($node, $contentDimensions, $contentContext, $childNodes);
            } else {
                $childNodes = $this->nodeDataRepository->findByParentAndNodeType($pathStartingPoint, null, $contentContext->getWorkspace(), $contentDimensions, $contentContext->isRemovedContentShown() ? null : false, true);
            }
            $nodeDataList = array_merge(
                $nodeDataList,
                [$contentContext->getNode($pathStartingPoint)->getNodeData()],
                $childNodes
            );
            $sourceContexts[] = $this->contentContextFactory->create([
                'invisibleContentShown' => $contentContext->isInvisibleContentShown(),
                'removedContentShown' => false,
                'inaccessibleContentShown' => $contentContext->isInaccessibleContentShown(),
                'dimensions' => $contentDimensions
            ]);
        }

        $uniqueNodeDataList = [];
        usort($nodeDataList, function (NodeData $node1, NodeData $node2) {
            if ($node1->getDimensionValues()[$this->languageDimension][0] === $this->sourceLanguage) {
                return 1;
            }
            if ($node2->getDimensionValues()[$this->languageDimension][0] === $this->sourceLanguage) {
                return -1;
            }

            return 0;
        });
        foreach ($nodeDataList as $nodeData) {
            $uniqueNodeDataList[$nodeData->getIdentifier()] = $nodeData;
        }
        $nodeDataList = array_filter(array_values($uniqueNodeDataList), function (NodeData $nodeData) use ($sourceContexts) {
            /** @var ContentContext $sourceContext */
            foreach ($sourceContexts as $sourceContext) {
                if ($sourceContext->getDimensions()[$this->languageDimension][0] !== $this->sourceLanguage) {
                    continue;
                }
                if ($nodeData->getDimensionValues()[$this->languageDimension][0] !== $this->sourceLanguage) {
                    // "reload" nodedata in correct dimension
                    $node = $sourceContext->getNodeByIdentifier($nodeData->getIdentifier());
                    if ($node === null || $node->getNodeData() === null) {
                        continue;
                    }
                    $nodeData = $node->getNodeData();
                }

                if (!$sourceContext->isInvisibleContentShown()) {
                    // filter out node if any of the parents is hidden
                    $parent = $nodeData;
                    while (true) {
                        if ($parent->isHidden()) {
                            return false;
                        }
                        $parentNode = $sourceContext->getNode($parent->getParentPath());
                        if (!$parentNode instanceof NodeInterface
                            || $parentNode->getNodeData()->getDimensionValues() === []) {
                            break;
                        }
                        $parent = $parentNode->getNodeData();
                    }
                }

                if (!is_null($this->modifiedAfter)) {
                    // filter out node if last modification date is _before_ modifiedAfter option
                    $lastModified = $nodeData->getLastModificationDateTime();

                    if ($lastModified < $this->modifiedAfter) {
                        return false;
                    }
                }
            }

            return true;
        });

        // Sort nodeDataList by path, replacing "/" with "!" (the first visible ASCII character)
        // because there may be characters like "-" in the node path
        // that would break the sorting order
        usort($nodeDataList,
            static function (NodeData $node1, NodeData $node2) {
                return strcmp(
                    str_replace("/", "!", $node1->getPath()),
                    str_replace("/", "!", $node2->getPath())
                );
            }
        );

        return $nodeDataList;
    }

    /**
     * @throws NodeTypeNotFoundException
     */
    protected function exportNodeDataList(array $nodeDataList): void
    {
        $this->xmlWriter->writeAttribute('formatVersion', self::SUPPORTED_FORMAT_VERSION);

        $currentNodeDataIdentifier = null;
        foreach ($nodeDataList as $nodeData) {
            $this->writeNode($nodeData, $currentNodeDataIdentifier);
        }
    }

    /**
     * @throws NodeTypeNotFoundException
     */
    protected function writeNode(NodeData $nodeData, ?string &$currentNodeDataIdentifier): void
    {
        $nodeName = $nodeData->getName();

        // is this a variant of currently open node?
        // then close all open node and start new node element
        // else reuse the currently open node element and add a new variant element
        if ($currentNodeDataIdentifier === null || $currentNodeDataIdentifier !== $nodeData->getIdentifier()) {
            if ($currentNodeDataIdentifier !== null) {
                $this->xmlWriter->endElement(); // "node"
            }

            $currentNodeDataIdentifier = $nodeData->getIdentifier();
            $this->xmlWriter->startElement('node');
            $this->xmlWriter->writeAttribute('identifier', $nodeData->getIdentifier());
            $this->xmlWriter->writeAttribute('nodeName', $nodeName);
        }

        $this->writeVariant($nodeData);
    }

    /**
     * @throws NodeTypeNotFoundException
     */
    protected function writeVariant(NodeData $nodeData): void
    {
        $this->xmlWriter->startElement('variant');
        $this->xmlWriter->writeAttribute('nodeType', $nodeData->getNodeType()->getName());

        $this->writeDimensions($nodeData);
        $this->writeProperties($nodeData);

        $this->xmlWriter->endElement();
    }

    protected function writeDimensions(NodeData $nodeData): void
    {
        $this->xmlWriter->startElement('dimensions');
        foreach ($nodeData->getDimensionValues() as $dimensionKey => $dimensionValues) {
            foreach ($dimensionValues as $dimensionValue) {
                $this->xmlWriter->writeElement($dimensionKey, $dimensionValue);
            }
        }
        $this->xmlWriter->endElement();
    }

    /**
     * @throws NodeTypeNotFoundException
     */
    protected function writeProperties(NodeData $nodeData): void
    {
        $this->xmlWriter->startElement('properties');
        $nodeType = $nodeData->getNodeType();

        foreach ($nodeData->getProperties() as $propertyName => $propertyValue) {
            if ($nodeType->hasConfiguration('properties.' . $propertyName)) {
                $options = $nodeType->getConfiguration('options.Flownative.Neos.Trados.properties.' . $propertyName);
                if (isset($options['skip']) && $options['skip'] === true) {
                    continue;
                }

                $declaredPropertyType = $nodeType->getPropertyType($propertyName);
                if ($declaredPropertyType === 'string') {
                    $this->writeProperty($propertyName, $propertyValue);
                }
            }
        }
        $this->xmlWriter->endElement();
    }

    protected function writeProperty(string $propertyName, string $propertyValue): void
    {
        $this->xmlWriter->startElement($propertyName);
        $this->xmlWriter->writeAttribute('type', 'string');
        if ($propertyValue !== '') {
            $this->xmlWriter->startCData();
            $this->xmlWriter->text($propertyValue);
            $this->xmlWriter->endCData();
        }
        $this->xmlWriter->endElement();
    }

    protected function getAllowedContentCombinationsForSourceLanguage(string $sourceLanguage): array
    {
        $allAllowedContentCombinations = $this->contentDimensionCombinator->getAllAllowedCombinations();

        return array_filter($allAllowedContentCombinations, function ($combination) use ($sourceLanguage) {
            return (isset($combination[$this->languageDimension]) && $combination[$this->languageDimension][0] === $sourceLanguage);
        });
    }

    /**
     * This function recursively traverses all nodes underneath $node which are content; and calls
     * $callback on each of them (Depth-First Traversal).
     *
     * @throws IllegalObjectTypeException
     */
    private function collectContentNodes(NodeData $node, array $contentDimensions, ContentContext $contentContext, array &$nodes): void
    {
        $nodes[] = $node;

        $childNodes = $this->nodeDataRepository->findByParentAndNodeType($node->getPath(), '!Neos.Neos:Document', $contentContext->getWorkspace(), $contentDimensions, $contentContext->isRemovedContentShown() ? null : false);
        foreach ($childNodes as $childNode) {
            $this->collectContentNodes($childNode, $contentDimensions, $contentContext, $nodes);
        }
    }
}
