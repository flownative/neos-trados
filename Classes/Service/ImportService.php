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
     * The XMLWriter that is used to construct the export.
     *
     * @var \XMLReader
     */
    protected $xmlReader;
}
