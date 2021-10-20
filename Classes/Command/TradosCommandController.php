<?php
namespace Flownative\Neos\Trados\Command;

/*
 * This file is part of the Flownative.Neos.Trados package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flownative\Neos\Trados\Service\ExportService;
use Flownative\Neos\Trados\Service\ImportService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;

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
     * @param string $startingPoint The node with which to start the export: as identifier or the path relative to the site node.
     * @param string $sourceLanguage The language to use as base for the export.
     * @param string|null $targetLanguage The target language for the translation, optional.
     * @param string|null $filename Path and filename to the XML file to create.
     * @param string|null $modifiedAfter
     * @param boolean $ignoreHidden
     * @return void
     * @throws \Exception
     */
    public function exportCommand(string $startingPoint,
                                  string $sourceLanguage,
                                  string $targetLanguage = null,
                                  string $filename = null,
                                  string $modifiedAfter = null,
                                  bool $ignoreHidden = true)
    {
        if ($modifiedAfter !== null) {
            $modifiedAfter = new \DateTime($modifiedAfter);
        }

        $this->exportService->initialize($startingPoint, $sourceLanguage, $targetLanguage, $modifiedAfter, $ignoreHidden);

        try {
            if ($filename === null) {
                $this->output($this->exportService->exportToString());
            } else {
                $this->exportService->exportToFile($filename);
                $this->outputLine('<success>The tree starting at "/sites/%s" has been exported to "%s".</success>', [$startingPoint, $filename]);
            }
        } catch (\Exception $exception) {
            $this->outputLine('<error>%s</error>', [$exception->getMessage()]);
        }
        $this->outputLine('Peak memory used: %u', [memory_get_peak_usage()]);
    }

    /**
     * Import sites content (e.g. trados:import --filename "acme.com.xml" --workspace "czech-review")
     *
     * This command imports translated content from XML.
     *
     * @param string $filename Path and filename to the XML file to import.
     * @param string|null $targetLanguage The target language for the translation, optional if included in XML.
     * @param string $workspace A workspace to import into, optional but recommended
     */
    public function importCommand(string $filename, string $targetLanguage = null, string $workspace = 'live')
    {
        try {
            $importedLanguage = $this->importService->importFromFile($filename, $workspace, $targetLanguage);
            $this->outputLine('<success>The file "%s" has been imported to language "%s" in workspace "%s".</success>', [$filename, $importedLanguage, $workspace]);
        } catch (\Exception $exception) {
            $this->outputLine('<error>%s</error>', [$exception->getMessage()]);
        }
        $this->outputLine('Peak memory used: %u', [memory_get_peak_usage()]);
    }
}
