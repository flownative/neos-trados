<?php
namespace Flownative\Neos\Trados\Command;

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
use TYPO3\Flow\Cli\CommandController;
use Flownative\Neos\Trados\Service\ExportService;
use Flownative\Neos\Trados\Service\ImportService;

/**
 * The Export Command Controller
 *
 * @Flow\Scope("singleton")
 */
class TradosCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var ExportService
     */
    protected $exportService;

    /**
     * @Flow\Inject
     * @var ImportService
     */
    protected $importService;

    /**
     * Export sites content (e.g. trados:export --filename "acme.com.xml" --source-language "en" --target-language "cz")
     *
     * This command exports a specific site including all content into an XML format.
     *
     * @param string $startingPoint The node with which to start the export, relative to the site node. Optional.
     * @param string $sourceLanguage The language to use es base for the export.
     * @param string $targetLanguage The target language for the translation, optional.
     * @param string $filename Path and filename to the XML file to create.
     * @param string $modifiedAfter
     * @return void
     */
    public function exportCommand($startingPoint, $sourceLanguage, $targetLanguage = null, $filename = null, $modifiedAfter = null)
    {
        if ($modifiedAfter !== null) {
            $modifiedAfter = new \DateTime($modifiedAfter);
        }

        try {
            if ($filename === null) {
                    $this->output($this->exportService->exportToString($startingPoint, $sourceLanguage, $targetLanguage, $modifiedAfter));
            } else {
                $this->exportService->exportToFile($filename, $startingPoint, $sourceLanguage, $targetLanguage, $modifiedAfter);
                $this->outputLine('<success>The tree starting at "/sites/%s" has been exported to "%s".</success>', array($startingPoint, $filename));
            }
        } catch (\Exception $exception) {
            $this->outputLine('<error>%s</error>', array($exception->getMessage()));
        }
        $this->outputLine('Peak memory used: %u', array(memory_get_peak_usage()));
    }

    /**
     * Import sites content (e.g. trados:import --filename "acme.com.xml" --workspace "czech-review")
     *
     * This command imports translated content from XML.
     *
     * @param string $filename Path and filename to the XML file to import.
     * @param string $targetLanguage The target language for the translation, optional if included in XML.
     * @param string $workspace A workspace to import into, optional but recommended
     */
    public function importCommand($filename, $targetLanguage = null, $workspace = 'live')
    {
        $this->outputLine('<error>This is not yet implemented.</error>');
    }
}
