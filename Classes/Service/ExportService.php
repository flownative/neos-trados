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

use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Service\ContentContext;

/**
 * The Trados Export Service
 *
 * @Flow\Scope("singleton")
 */
class ExportService
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
     * The XMLWriter that is used to construct the export.
     *
     * @var \XMLWriter
     */
    protected $xmlWriter;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var \Neos\ContentRepository\Domain\Service\NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var \Neos\Neos\Domain\Repository\SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var \Neos\ContentRepository\Domain\Repository\NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @Flow\Inject
     * @var \Neos\Flow\Security\Context
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var \Neos\ContentRepository\Domain\Service\ContentDimensionCombinator
     */
    protected $contentDimensionCombinator;

    /**
     * Fetches the site with the given name and exports it into XML.
     *
     * @param string $startingPoint
     * @param string $sourceLanguage
     * @param string|null $targetLanguage
     * @param \DateTime|null $modifiedAfter
     * @param boolean $ignoreHidden
     * @return string
     */
    public function exportToString(string $startingPoint, string $sourceLanguage, string $targetLanguage = null, \DateTime $modifiedAfter = null, bool $ignoreHidden = true): string
    {
        $this->xmlWriter = new \XMLWriter();
        $this->xmlWriter->openMemory();
        $this->xmlWriter->setIndent(true);

        $this->export($startingPoint, $sourceLanguage, $targetLanguage, $modifiedAfter, 'live', $ignoreHidden);

        return $this->xmlWriter->outputMemory(true);
    }

    /**
     * Export into the given file.
     *
     * @param string $pathAndFilename Path to where the export output should be saved to
     * @param string $startingPoint
     * @param string $sourceLanguage
     * @param string|null $targetLanguage
     * @param \DateTime|null $modifiedAfter
     * @param boolean $ignoreHidden
     * @return void
     */
    public function exportToFile(string $pathAndFilename, string $startingPoint, string $sourceLanguage, string $targetLanguage = null, \DateTime $modifiedAfter = null, bool $ignoreHidden = true)
    {
        $this->xmlWriter = new \XMLWriter();
        $this->xmlWriter->openUri($pathAndFilename);
        $this->xmlWriter->setIndent(true);

        $this->export($startingPoint, $sourceLanguage, $targetLanguage, $modifiedAfter, 'live', $ignoreHidden);

        $this->xmlWriter->flush();
    }

    /**
     * Export to the XMLWriter.
     *
     * @param string $startingPoint
     * @param string $sourceLanguage
     * @param string|null $targetLanguage
     * @param \DateTime|null $modifiedAfter
     * @param string $workspaceName
     * @param boolean $ignoreHidden
     * @return void
     */
    protected function export(string $startingPoint, string $sourceLanguage, string $targetLanguage = null, \DateTime $modifiedAfter = null, string $workspaceName = 'live', bool $ignoreHidden = true)
    {
        $siteNodeName = current(explode('/', $startingPoint));
        /** @var Site $site */
        $site = $this->siteRepository->findOneByNodeName($siteNodeName);
        if ($site === null) {
            throw new \RuntimeException(sprintf('No site found for node name "%s"', $siteNodeName), 1473241812);
        }

        /** @var ContentContext $contentContext */
        $contentContext = $this->contextFactory->create([
            'workspaceName' => $workspaceName,
            'currentSite' => $site,
            'invisibleContentShown' => !$ignoreHidden,
            'removedContentShown' => false,
            'inaccessibleContentShown' => !$ignoreHidden,
            'dimensions' => current($this->getAllowedContentCombinationsForSourceLanguage($sourceLanguage))
        ]);

        $this->xmlWriter->startDocument('1.0', 'UTF-8');
        $this->xmlWriter->startElement('content');

        $this->xmlWriter->writeAttribute('name', $site->getName());
        $this->xmlWriter->writeAttribute('sitePackageKey', $site->getSiteResourcesPackageKey());
        $this->xmlWriter->writeAttribute('workspace', $workspaceName);
        $this->xmlWriter->writeAttribute('sourceLanguage', $sourceLanguage);
        if ($targetLanguage !== null) {
            $this->xmlWriter->writeAttribute('targetLanguage', $targetLanguage);
        }
        if ($modifiedAfter !== null) {
            $this->xmlWriter->writeAttribute('modifiedAfter', $targetLanguage);
        }

        $this->exportNodes('/sites/' . $startingPoint, $contentContext, $sourceLanguage, $targetLanguage, $modifiedAfter);

        $this->xmlWriter->endElement();
        $this->xmlWriter->endDocument();
    }

    /**
     * Exports the node data of all nodes in the given sub-tree
     * by writing them to the given XMLWriter.
     *
     * @param string $startingPointNodePath path to the root node of the sub-tree to export. The specified node will not be included, only its sub nodes.
     * @param ContentContext $contentContext
     * @param string $sourceLanguage
     * @param string|null $targetLanguage
     * @param \DateTime|null $modifiedAfter
     * @return void
     */
    public function exportNodes(string $startingPointNodePath, ContentContext $contentContext, string $sourceLanguage, string $targetLanguage = null, \DateTime $modifiedAfter = null)
    {
        $this->securityContext->withoutAuthorizationChecks(function () use ($startingPointNodePath, $contentContext, $sourceLanguage, $targetLanguage, $modifiedAfter) {
            $nodeDataList = $this->findNodeDataListToExport($startingPointNodePath, $contentContext, $sourceLanguage, $targetLanguage, $modifiedAfter);
            $this->exportNodeDataList($nodeDataList);
        });
    }

    /**
     * Find all nodes of the specified workspace lying below the path specified by
     * (and including) the given starting point.
     *
     * @param string $pathStartingPoint Absolute path specifying the starting point
     * @param ContentContext $contentContext
     * @param string $sourceLanguage
     * @param string|null $targetLanguage
     * @param \DateTime|null $modifiedAfter
     * @return array<NodeData>
     */
    protected function findNodeDataListToExport(string $pathStartingPoint, ContentContext $contentContext, string $sourceLanguage, string $targetLanguage = null, \DateTime $modifiedAfter = null): array
    {
        $allowedContentCombinations = $this->getAllowedContentCombinationsForSourceLanguage($sourceLanguage);
        $sourceContexts = [];

        /** @var NodeData[] $nodeDataList */
        $nodeDataList = [];
        foreach ($allowedContentCombinations as $contentDimensions) {
            $nodeDataList = array_merge(
                $nodeDataList,
                [$contentContext->getNode($pathStartingPoint)->getNodeData()],
                $this->nodeDataRepository->findByParentAndNodeType($pathStartingPoint, null, $contentContext->getWorkspace(), $contentDimensions, $contentContext->isRemovedContentShown() ? null : false, true)
            );
            $sourceContexts[] = $this->contextFactory->create([
                'invisibleContentShown' => $contentContext->isInvisibleContentShown(),
                'removedContentShown' => false,
                'inaccessibleContentShown' => $contentContext->isInaccessibleContentShown(),
                'dimensions' => $contentDimensions
            ]);
        }

        $uniqueNodeDataList = [];
        usort($nodeDataList, function (NodeData $node1, NodeData $node2) use ($sourceLanguage) {
            if ($node1->getDimensionValues()[$this->languageDimension][0] === $sourceLanguage) {
                return 1;
            }
            if ($node2->getDimensionValues()[$this->languageDimension][0] === $sourceLanguage) {
                return -1;
            }

            return 0;
        });
        foreach ($nodeDataList as $nodeData) {
            $uniqueNodeDataList[$nodeData->getIdentifier()] = $nodeData;
        }
        $nodeDataList = array_filter(array_values($uniqueNodeDataList), function (NodeData $nodeData) use ($sourceContexts, $sourceLanguage) {
            /** @var ContentContext $sourceContext */
            foreach ($sourceContexts as $sourceContext) {
                if ($sourceContext->getDimensions()[$this->languageDimension][0] !== $sourceLanguage) {
                    continue;
                }
                if ($nodeData->getDimensionValues()[$this->languageDimension][0] !== $sourceLanguage) {
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
                    while ($parent !== null) {
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
            }

            return $nodeData !== null;
        });

        // Sort nodeDataList by path, replacing "/" with "!" (the first visible ASCII character)
        // because there may be characters like "-" in the node path
        // that would break the sorting order
        usort($nodeDataList,
            function (NodeData $node1, NodeData $node2) {
                return strcmp(
                    str_replace("/", "!", $node1->getPath()),
                    str_replace("/", "!", $node2->getPath())
                );
            }
        );

        return $nodeDataList;
    }

    /**
     * Exports the given Nodes into the XML structure, contained in <nodes> </nodes> tags.
     *
     * @param array<NodeData> $nodeDataList The nodes to export
     * @return void The result is written directly into $this->xmlWriter
     */
    protected function exportNodeDataList(array &$nodeDataList)
    {
        $this->xmlWriter->startElement('nodes');
        $this->xmlWriter->writeAttribute('formatVersion', self::SUPPORTED_FORMAT_VERSION);

        $currentNodeDataIdentifier = null;
        foreach ($nodeDataList as $nodeData) {
            $this->writeNode($nodeData, $currentNodeDataIdentifier);
        }

        $this->xmlWriter->endElement();
    }

    /**
     * Write a single node into the XML structure
     *
     * @param NodeData $nodeData The node data
     * @param string|null $currentNodeDataIdentifier The "current" node, as passed by exportNodeDataList()
     * @return void The result is written directly into $this->xmlWriter
     */
    protected function writeNode(NodeData $nodeData, string &$currentNodeDataIdentifier = null)
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
     * Write a node variant into the XML structure
     *
     * @param NodeData $nodeData
     * @return void
     */
    protected function writeVariant(NodeData $nodeData)
    {
        $this->xmlWriter->startElement('variant');
        $this->xmlWriter->writeAttribute('nodeType', $nodeData->getNodeType()->getName());

        $this->writeDimensions($nodeData);
        $this->writeProperties($nodeData);

        $this->xmlWriter->endElement();
    }

    /**
     * Write dimensions and their values into the XML structure.
     *
     * @param NodeData $nodeData
     * @return void
     */
    protected function writeDimensions(NodeData $nodeData)
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
     * Write properties and their values into the XML structure.
     *
     * @param NodeData $nodeData
     * @return void
     */
    protected function writeProperties(NodeData $nodeData)
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

    /**
     * Writes out a single string property into the XML structure.
     *
     * @param string $propertyName The name of the property
     * @param string $propertyValue The value of the property
     */
    protected function writeProperty(string $propertyName, string $propertyValue)
    {
        $this->xmlWriter->startElement($propertyName);
        $this->xmlWriter->writeAttribute('type', 'string');
        if ($propertyValue !== '' && $propertyValue !== null) {
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
}
