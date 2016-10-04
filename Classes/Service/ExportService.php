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

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Neos\Domain\Model\Site;
use TYPO3\Neos\Domain\Service\ContentContext;
use TYPO3\TYPO3CR\Domain\Model\NodeData;
use TYPO3\TYPO3CR\Domain\Service\ContextFactoryInterface;
use TYPO3\TYPO3CR\Domain\Utility\NodePaths;

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
     * Doctrine's Entity Manager. Note that "ObjectManager" is the name of the related
     * interface ...
     *
     * @Flow\Inject
     * @var \Doctrine\Common\Persistence\ObjectManager
     */
    protected $entityManager;

    /**
     * @Flow\Inject
     * @var \TYPO3\TYPO3CR\Domain\Service\NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var \TYPO3\Neos\Domain\Repository\SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var \TYPO3\TYPO3CR\Domain\Repository\NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @Flow\Inject
     * @var \TYPO3\Flow\Security\Context
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var \TYPO3\TYPO3CR\Domain\Service\ContentDimensionCombinator
     */
    protected $contentDimensionCombinator;

    /**
     * Fetches the site with the given name and exports it into XML.
     *
     * @param string $startingPoint
     * @param string $sourceLanguage
     * @param string $targetLanguage
     * @param \DateTime $modifiedAfter
     * @return string
     */
    public function exportToString($startingPoint, $sourceLanguage, $targetLanguage = null, \DateTime $modifiedAfter = null)
    {
        $this->xmlWriter = new \XMLWriter();
        $this->xmlWriter->openMemory();
        $this->xmlWriter->setIndent(true);

        $this->export($startingPoint, $sourceLanguage, $targetLanguage, $modifiedAfter);

        return $this->xmlWriter->outputMemory(true);
    }

    /**
     * Export into the given file.
     *
     * @param string $pathAndFilename Path to where the export output should be saved to
     * @param string $startingPoint
     * @param string $sourceLanguage
     * @param string $targetLanguage
     * @param \DateTime $modifiedAfter
     * @return void
     */
    public function exportToFile($pathAndFilename, $startingPoint, $sourceLanguage, $targetLanguage = null, \DateTime $modifiedAfter = null)
    {
        $this->xmlWriter = new \XMLWriter();
        $this->xmlWriter->openUri($pathAndFilename);
        $this->xmlWriter->setIndent(true);

        $this->export($startingPoint, $sourceLanguage, $targetLanguage, $modifiedAfter);

        $this->xmlWriter->flush();
    }

    /**
     * Export to the XMLWriter.
     *
     * @param string $startingPoint
     * @param string $sourceLanguage
     * @param string $targetLanguage
     * @param \DateTime $modifiedAfter
     * @param string $workspaceName
     * @return void
     */
    protected function export($startingPoint, $sourceLanguage, $targetLanguage = null, \DateTime $modifiedAfter = null, $workspaceName = 'live')
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
            'invisibleContentShown' => false,
            'removedContentShown' => false
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
     * @param string $targetLanguage
     * @param \DateTime $modifiedAfter
     * @return void
     */
    public function exportNodes($startingPointNodePath, ContentContext $contentContext, $sourceLanguage, $targetLanguage = null, \DateTime $modifiedAfter = null)
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
     * @param string $targetLanguage
     * @param \DateTime $modifiedAfter
     * @return array<NodeData>
     */
    protected function findNodeDataListToExport($pathStartingPoint, ContentContext $contentContext, $sourceLanguage, $targetLanguage = null, \DateTime $modifiedAfter = null)
    {
        $parentPath = NodePaths::getParentPath($pathStartingPoint);

        $allAllowedContentCombinations = $this->contentDimensionCombinator->getAllAllowedCombinations();

        $allowedContentCombinations = array_filter($allAllowedContentCombinations, function ($combination) use ($sourceLanguage) {
            return (isset($combination[$this->languageDimension]) && $combination[$this->languageDimension][0] === $sourceLanguage);
        });

        $nodeDataList = [];
        foreach ($allowedContentCombinations as $contentDimensions) {
            $nodeDataList = array_merge(
                $nodeDataList,
                $this->nodeDataRepository->findByParentAndNodeType($parentPath, null, $contentContext->getWorkspace(), $contentDimensions, $contentContext->isRemovedContentShown(), true)
            );
        }

        if (!$contentContext->isInvisibleContentShown()) {
            $nodeDataList = array_filter($nodeDataList, function (NodeData $nodeData) {
                return !$nodeData->isHidden();
            });
        }

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
     * @param string $currentNodeDataIdentifier The "current" node, as passed by exportNodeDataList()
     * @return void The result is written directly into $this->xmlWriter
     */
    protected function writeNode(NodeData $nodeData, &$currentNodeDataIdentifier)
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
     * Writes out a single property into the XML structure.
     *
     * @param string $propertyName The name of the property
     * @param mixed $propertyValue The value of the property
     */
    protected function writeProperty($propertyName, $propertyValue)
    {
        $this->xmlWriter->startElement($propertyName);
        $this->xmlWriter->writeAttribute('type', gettype($propertyValue));
        if ($propertyValue !== '' && $propertyValue !== null) {
            $this->xmlWriter->text($propertyValue);
        }
        $this->xmlWriter->endElement();
    }
}
