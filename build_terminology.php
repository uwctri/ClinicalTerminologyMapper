<?php

/**
 * CLI Entry point to build the FHIR R4, R5 & Versioned US Core Terminology Database.
 * 
 * Usage:
 *   php build_terminology.php --help
 *   php build_terminology.php --r4 --us-core-311 [OPTIONS]
 */

declare(strict_types=1);

namespace UWMadison\ClinicalTerminologyMapper;

use Throwable;

ini_set('memory_limit', '1024M');
set_time_limit(0);

require_once __DIR__ . '/TerminologyBuilderService.php';

function printHelp(): void
{
    $script = basename(__FILE__);
    echo <<<HELP
=============================================================================
 Clinical Terminology Mapper: Terminology Database Builder CLI
=============================================================================

Usage:
  php {$script} [PACKAGE FLAGS] [BUILD OPTIONS]

Description:
  Downloads official HL7 FHIR conformance expansions, definitions, and versioned
  US Core Implementation Guide packages from hl7.org, and compiles them into an
  optimized, FTS5-indexed SQLite terminology database for REDCap Clinical Terminology Mapper.

Package Selection Flags (select only what your projects need):
  --r4                  Include Base HL7 FHIR R4 (v4.0.1 international standard)
  --r5                  Include Base HL7 FHIR R5 (v5.0.0 next-generation standard)
  --us-core-311         Include US Core v3.1.1 (USCDI v1, Epic/Cerner baseline)
  --us-core-501         Include US Core v5.0.1 (USCDI v2)
  --us-core-610         Include US Core v6.1.0 (USCDI v3)
  --us-core-700         Include US Core v7.0.0 (USCDI v4, Sex Parameter for Clinical Use)
  --us-core-800         Include US Core v8.0.0 (USCDI v5)
  --us-core-900         Include US Core v9.0.0 (Draft / latest ballot)
  --mcode-300           Include mCODE v3.0.0 (STU 3, EHR oncology baseline)
  --mcode-400           Include mCODE v4.0.0 (STU 4, latest release)
  --mcode               Include mCODE (v3.0.0 and v4.0.0)
  --sdoh-230            Include SDOH Clinical Care v2.3.0 (STU 2.3, USCDI v3/v4 baseline)
  --sdoh-300            Include SDOH Clinical Care v3.0.0 (STU 3, latest release)
  --sdoh                Include SDOH Clinical Care (v2.3.0 and v3.0.0)
  --rxnorm              Include RxNorm Active Ingredients (~14,710 NLM generic substances & RxCUIs; excludes dosage/brand permutations)
  --us-core-all         Include all US Core versions (v3.1.1 through v9.0.0)
  --all                 Include standard production set (FHIR R4 + all US Core)
  --all-plus-r5         Include all packages including FHIR R5

  --packages="<list>"   Comma-separated list of packages or shorthand codes
                        (e.g. --packages="FHIR R4, US Core v3.1.1, US Core v6.1.0"
                         or shorthand: --packages="r4,r5,311,610")

Build Options:
  --output=<path>       Destination SQLite path (default: fhir_valuesets.sqlite)
  --cache-dir=<path>    Custom directory for caching downloaded archives
  --keep-cache          Preserve downloaded .tgz/.zip files after compilation
  -h, --help            Display this help manual and exit

Examples:
  # 1. Build standard production set (FHIR R4 + all US Core versions):
  php {$script} --all

  # 2. Build only FHIR R4 and the ONC USCDI v1 & v3 versions used by Epic:
  php {$script} --r4 --us-core-311 --us-core-610

  # 3. Build only next-generation FHIR R5:
  php {$script} --r5

  # 4. Build all packages with custom database path:
  php {$script} --all --output="/var/lib/terminology/terminology.sqlite"

  # 5. Build selected packages using shorthand list:
  php {$script} --packages="r4,311,610"
=============================================================================

HELP;
}

$shortopts = 'h';
$longopts = [
    'help',
    'output::',
    'cache-dir::',
    'keep-cache',
    'all',
    'all-plus-r5',
    'r4',
    'r5',
    'us-core-all',
    'us-core-311',
    'us-core-501',
    'us-core-610',
    'us-core-700',
    'us-core-800',
    'us-core-900',
    'mcode',
    'mcode-300',
    'mcode-400',
    'sdoh',
    'sdoh-230',
    'sdoh-300',
    'rxnorm',
    'packages::'
];

$options = getopt($shortopts, $longopts);

if (isset($options['help']) || isset($options['h'])) {
    printHelp();
    exit(0);
}

$outputPath = $options['output'] ?? __DIR__ . '/fhir_valuesets.sqlite';
$cacheDir = $options['cache-dir'] ?? null;
$keepCache = isset($options['keep-cache']);

$selected = [];

if (isset($options['all-plus-r5'])) {
    $selected = [
        'FHIR R4',
        'FHIR R5',
        'US Core v3.1.1',
        'US Core v5.0.1',
        'US Core v6.1.0',
        'US Core v7.0.0',
        'US Core v8.0.0',
        'US Core v9.0.0'
    ];
} elseif (isset($options['all'])) {
    $selected = [
        'FHIR R4',
        'US Core v3.1.1',
        'US Core v5.0.1',
        'US Core v6.1.0',
        'US Core v7.0.0',
        'US Core v8.0.0',
        'US Core v9.0.0'
    ];
} else {
    if (isset($options['r4'])) {
        $selected[] = 'FHIR R4';
    }
    if (isset($options['r5'])) {
        $selected[] = 'FHIR R5';
    }
    if (isset($options['us-core-all'])) {
        $selected = array_merge($selected, [
            'US Core v3.1.1',
            'US Core v5.0.1',
            'US Core v6.1.0',
            'US Core v7.0.0',
            'US Core v8.0.0',
            'US Core v9.0.0'
        ]);
    } else {
        if (isset($options['us-core-311'])) $selected[] = 'US Core v3.1.1';
        if (isset($options['us-core-501'])) $selected[] = 'US Core v5.0.1';
        if (isset($options['us-core-610'])) $selected[] = 'US Core v6.1.0';
        if (isset($options['us-core-700'])) $selected[] = 'US Core v7.0.0';
        if (isset($options['us-core-800'])) $selected[] = 'US Core v8.0.0';
        if (isset($options['us-core-900'])) $selected[] = 'US Core v9.0.0';
    }

    if (isset($options['mcode-300'])) {
        $selected[] = 'mCODE v3.0.0';
    }
    if (isset($options['mcode-400'])) {
        $selected[] = 'mCODE v4.0.0';
    }
    if (isset($options['mcode'])) {
        $selected[] = 'mCODE v3.0.0';
        $selected[] = 'mCODE v4.0.0';
    }

    if (isset($options['sdoh-230'])) {
        $selected[] = 'SDOH Clinical Care v2.3.0';
    }
    if (isset($options['sdoh-300'])) {
        $selected[] = 'SDOH Clinical Care v3.0.0';
    }
    if (isset($options['sdoh'])) {
        $selected[] = 'SDOH Clinical Care v2.3.0';
        $selected[] = 'SDOH Clinical Care v3.0.0';
    }

    if (isset($options['rxnorm'])) {
        $selected[] = 'RxNorm';
    }

    if (!empty($options['packages'])) {
        $rawList = explode(',', (string)$options['packages']);
        foreach ($rawList as $item) {
            $trimmed = trim($item);
            $lower = strtolower($trimmed);
            if ($lower === 'r4' || $lower === 'fhir r4') $selected[] = 'FHIR R4';
            elseif ($lower === 'r5' || $lower === 'fhir r5') $selected[] = 'FHIR R5';
            elseif ($lower === '311' || $lower === '3.1.1' || str_contains($lower, '3.1.1')) $selected[] = 'US Core v3.1.1';
            elseif ($lower === '501' || $lower === '5.0.1' || str_contains($lower, '5.0.1')) $selected[] = 'US Core v5.0.1';
            elseif ($lower === '610' || $lower === '6.1.0' || str_contains($lower, '6.1.0')) $selected[] = 'US Core v6.1.0';
            elseif ($lower === '700' || $lower === '7.0.0' || str_contains($lower, '7.0.0')) $selected[] = 'US Core v7.0.0';
            elseif ($lower === '800' || $lower === '8.0.0' || str_contains($lower, '8.0.0')) $selected[] = 'US Core v8.0.0';
            elseif ($lower === '900' || $lower === '9.0.0' || str_contains($lower, '9.0.0')) $selected[] = 'US Core v9.0.0';
            elseif ($lower === 'mcode3' || $lower === 'mcode 3' || str_contains($lower, 'mcode') && str_contains($lower, '3')) $selected[] = 'mCODE v3.0.0';
            elseif ($lower === 'mcode4' || $lower === 'mcode 4' || str_contains($lower, 'mcode') && str_contains($lower, '4')) $selected[] = 'mCODE v4.0.0';
            elseif ($lower === 'mcode') { $selected[] = 'mCODE v3.0.0'; $selected[] = 'mCODE v4.0.0'; }
            elseif ($lower === 'sdoh2' || $lower === 'sdoh 2' || str_contains($lower, 'sdoh') && (str_contains($lower, '2') || str_contains($lower, '2.3'))) $selected[] = 'SDOH Clinical Care v2.3.0';
            elseif ($lower === 'sdoh3' || $lower === 'sdoh 3' || str_contains($lower, 'sdoh') && str_contains($lower, '3')) $selected[] = 'SDOH Clinical Care v3.0.0';
            elseif ($lower === 'sdoh') { $selected[] = 'SDOH Clinical Care v2.3.0'; $selected[] = 'SDOH Clinical Care v3.0.0'; }
            elseif ($lower === 'rxnorm' || $lower === 'rx' || str_contains($lower, 'rxnorm')) $selected[] = 'RxNorm';
            elseif (isset(TerminologyBuilderService::PACKAGE_METADATA[$trimmed])) $selected[] = $trimmed;
        }
    }
}

$selected = array_values(array_unique($selected));

if (empty($selected)) {
    echo "\n[!] Error: No packages specified to compile.\n";
    echo "    Please specify which packages you want to build into the database using flags.\n";
    echo "    See below for available flags or run with --help:\n\n";
    printHelp();
    exit(1);
}

echo "========================================================\n";
echo " Clinical Terminology Mapper: Terminology Database Builder CLI\n";
echo " Target DB: {$outputPath}\n";
echo " Selected Packages (" . count($selected) . "):\n";
foreach ($selected as $pkg) {
    echo "   - {$pkg}\n";
}
echo "========================================================\n\n";

try {
    $builder = new TerminologyBuilderService($outputPath, $cacheDir, $keepCache, null, $selected);
    $stats = $builder->build();
    if (!empty($stats['success'])) {
        echo "\nBuild completed successfully in {$stats['elapsed_seconds']}s.\n";
        echo "ValueSets: {$stats['valueset_count']} | Concepts: {$stats['concept_count']} | Size: {$stats['file_size_mb']} MB\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\nERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
