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
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\Flow\Annotations as Flow;
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
     * @Flow\InjectConfiguration(path = "export.workspace")
     * @var string
     */
    protected string $workspaceName;

    /**
     * @Flow\InjectConfiguration(path = "export.documentTypeFilter")
     * @var string
     */
    protected string $documentTypeFilter;

    /**
     * The XMLWriter that is used to construct the export.
     *
     * @var \XMLWriter
     */
    protected $xmlWriter;

    /**
     * @Flow\Inject
     * @var \Neos\ContentRepository\Domain\Service\NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @Flow\Inject
     * @var \Neos\ContentRepository\Domain\Service\ContentDimensionCombinator
     */
    protected $contentDimensionCombinator;

    /**
     * @var ContentContext
     *
     */
    protected ContentContext $contentContext;

    /**
     * @var string
     */
    protected string $startingPoint;

    /**
     * @var NodeInterface
     */
    protected NodeInterface $startingPointNode;

    /**
     * @var Site
     */
    protected Site $site;

    /**
     * @var string
     */
    protected string $sourceLanguage;

    /**
     * @var string|null
     */
    protected ?string $targetLanguage;

    /**
     * @var \DateTime|null
     */
    protected ?\DateTime $modifiedAfter;

    /**
     * @var bool
     */
    protected bool $ignoreHidden;

    /**
     * @var bool
     */
    protected bool $excludeChildDocuments;

    /**
     * @var int
     */
    protected int $depth;

    /**
     * @param string $startingPoint
     * @param string $sourceLanguage
     * @param string|null $targetLanguage
     * @param \DateTime|null $modifiedAfter
     * @param bool $ignoreHidden
     * @param string $documentTypeFilter
     * @param int $depth
     */
    public function initialize(
        string $startingPoint,
        string $sourceLanguage,
        string $targetLanguage = null,
        \DateTime $modifiedAfter = null,
        bool $ignoreHidden = true,
        bool $excludeChildDocuments = false,
        string $documentTypeFilter = 'Neos.Neos:Document',
        int $depth = 0
    ) {
        $this->startingPoint = $startingPoint;
        $this->sourceLanguage = $sourceLanguage;
        $this->targetLanguage = $targetLanguage;
        $this->modifiedAfter = $modifiedAfter;
        $this->ignoreHidden = $ignoreHidden;
        $this->excludeChildDocuments = $excludeChildDocuments;
        $this->documentTypeFilter = $documentTypeFilter;
        $this->depth = $depth;

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
        $pathArray = explode('/', $this->startingPointNode->findNodePath());
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

        return $this->xmlWriter->outputMemory(true);
    }

    /**
     * Export into the given file.
     *
     * @param string $pathAndFilename Path to where the export output should be saved to
     * @return void
     * @throws \Exception
     */
    public function exportToFile(string $pathAndFilename)
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
     * @return void
     * @throws \Exception
     */
    protected function exportToXmlWriter()
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
        $this->exportNodes($this->startingPointNode->findNodePath(), $this->contentContext);
        $this->xmlWriter->endElement(); // nodes

        $this->xmlWriter->endElement();
        $this->xmlWriter->endDocument();
    }

    /**
     * Exports the node data of all nodes in the given sub-tree
     * by writing them to the given XMLWriter.
     *
     * @param string $startingPointNodePath path to the root node of the sub-tree to export. The specified node will not be included, only its sub nodes.
     * @param ContentContext $contentContext
     * @return void
     * @throws \Exception
     */
    protected function exportNodes(string $startingPointNodePath, ContentContext $contentContext)
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
     * @param string $pathStartingPoint Absolute path specifying the starting point
     * @param ContentContext $contentContext
     * @return array<NodeData>
     * @throws \Neos\Flow\Persistence\Exception\IllegalObjectTypeException
     */
    protected function findNodeDataListToExport(string $pathStartingPoint, ContentContext $contentContext): array
    {
        $allowedContentCombinations = $this->getAllowedContentCombinationsForSourceLanguage($this->sourceLanguage);
        $sourceContexts = [];

        /** @var NodeData[] $nodeDataList */
        $nodeDataList = [];
        foreach ($allowedContentCombinations as $contentDimensions) {
            // If exclude-child-documents argument is set to true, only add content child nodes to nodeDataList (recursively), don't descend into document child nodes.
            if ($this->excludeChildDocuments === true) {
                $node = $contentContext->getNode($pathStartingPoint)->getNodeData();
                $childNodes = $this->getChildNodeDataOnPage($node, $contentContext->getWorkspace(), $contentDimensions, $contentContext->isRemovedContentShown());
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
     * @throws \Neos\ContentRepository\Exception\NodeTypeNotFoundException
     */
    protected function exportNodeDataList(array &$nodeDataList)
    {
        $this->xmlWriter->writeAttribute('formatVersion', self::SUPPORTED_FORMAT_VERSION);

        $currentNodeDataIdentifier = null;
        foreach ($nodeDataList as $nodeData) {
            $this->writeNode($nodeData, $currentNodeDataIdentifier);
        }
    }

    /**
     * Write a single node into the XML structure
     *
     * @param NodeData $nodeData The node data
     * @param string|null $currentNodeDataIdentifier The "current" node, as passed by exportNodeDataList()
     * @return void The result is written directly into $this->xmlWriter
     * @throws \Neos\ContentRepository\Exception\NodeTypeNotFoundException
     */
    protected function writeNode(NodeData $nodeData, ?string &$currentNodeDataIdentifier)
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
     * @throws \Neos\ContentRepository\Exception\NodeTypeNotFoundException
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
     * @throws \Neos\ContentRepository\Exception\NodeTypeNotFoundException
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

    /**
     * Find all content nodes under the given node.
     *
     * @param NodeData $node
     * @param Workspace $workspace
     * @param array $contentDimensions
     * @param boolean $isRemovedContentShown
     * @return array
     */
    private function getChildNodeDataOnPage(NodeData $node, Workspace $workspace, array $contentDimensions, bool $isRemovedContentShown): array
    {
        $results = [];
        // We traverse through all Content (=!Document) nodes underneath the start node here and add it to the result list.
        // NOTE: The $results list is passed into the callback by reference, so that we can modify it in-place.
        $this->traverseRecursively($node, '!Neos.Neos:Document', $workspace, $contentDimensions, $isRemovedContentShown, function($node) use (&$results) {
            $results[] = $node;
        });
        return $results;
    }

    /**
     * This function recursively traverses all nodes underneath $node which match $nodeTypeFilter; and calls
     * $callback on each of them (Depth-First Traversal).
     *
     * You have to watch out yourself to not build very deep nesting; so we suggest to use a node type filter
     * like "!Neos.Neos:Document" or "Neos.Neos:ContentCollection, Neos.Neos:Content" which matches only content.
     *
     * For reference how the Node Type filter works, see:
     *
     * - NodeDataRepository::addNodeTypeFilterConstraintsToQueryBuilder
     * - NodeDataRepository::getNodeTypeFilterConstraintsForDql
     *
     * @param NodeData $node
     * @param string $nodeTypeFilter
     * @param Workspace $workspace
     * @param array $contentDimensions
     * @param boolean $isRemovedContentShown
     * @param \Closure $callback
     */
    private function traverseRecursively(NodeData $node, string $nodeTypeFilter, Workspace $workspace, array $contentDimensions, bool $isRemovedContentShown, \Closure $callback)
    {
        $callback($node);
        $childNodes = $this->nodeDataRepository->findByParentAndNodeType($node->getPath(), $nodeTypeFilter, $workspace, $contentDimensions, $isRemovedContentShown ? null : false, false);
        foreach ($childNodes as $childNode) {
            $this->traverseRecursively($childNode, $nodeTypeFilter, $workspace, $contentDimensions, $isRemovedContentShown, $callback);
        }
    }
}
