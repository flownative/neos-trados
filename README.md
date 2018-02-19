# Trados Support for Neos

## Features

* Export content into an XML format Trados can digest and that carries only needed data
* Import translations into a specific language

## Installation

`composer require flownative/neos-trados`

## Usage

The export command:

    Export sites content (e.g. trados:export --filename "acme.com.xml" --source-language "en")
    
    COMMAND:
      flownative.neos.trados:trados:export
    
    USAGE:
      ./flow trados:export [<options>] <starting point> <source language>
    
    ARGUMENTS:
      --starting-point     The node with which to start the export, relative to the
                           site node. Optional.
      --source-language    The language to use as base for the export.
    
    OPTIONS:
      --target-language    The target language for the translation, optional.
      --filename           Path and filename to the XML file to create.
      --modified-after

The import command:

    Import sites content (e.g. trados:import --filename "acme.com.xml" --workspace "czech-review")
    
    COMMAND:
      flownative.neos.trados:trados:import
    
    USAGE:
      ./flow trados:import [<options>] <filename>
    
    ARGUMENTS:
      --filename           Path and filename to the XML file to import.
    
    OPTIONS:
      --target-language    The target language for the translation, optional if
                           included in XML.
      --workspace          A workspace to import into, optional but recommended

### Workflow

The workflow usually goes like this:

1. The site content is exported using `trados:export`
2. The XML is translated by some translation agency
3. The translated XML is imported into a fresh workspace using `trados:import`
4. The changes are reviewed and published in the workspace module

## Configuration

The command usually exports all properties of type _string_. If you want to exclude
certain properties, you can configure that in your `NodeTypes.yaml` file like this:

    'TYPO3.Neos:Document':
      options:
        Flownative:
          Neos:
            Trados:
              properties:
                twitterCardType:
                  skip: true
