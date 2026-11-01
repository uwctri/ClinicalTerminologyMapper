<?php

declare(strict_types=1);

namespace UWMadison\ClinicalTerminologyMapper;

use PDO;
use PharData;
use ZipArchive;
use RecursiveIteratorIterator;
use Throwable;
use RuntimeException;

/**
 * Service to build and manage the FHIR R4 & US Core SQLite Terminology Database.
 * 
 * Fetches official HL7 FHIR R4 conformance definitions, expansions, and versioned US Core
 * Implementation Guide packages (STU 3.1.1, 5.0.1, 6.1.0, 7.0.0, 8.0.0, 9.0.0) from hl7.org,
 * tags all ValueSets with their exact package versions, resolves CodeSystems,
 * and compiles them into an optimized, FTS5-indexed SQLite database for REDCap Clinical Terminology Mapper.
 */
class TerminologyBuilderService
{
    private string $outputPath;
    private string $cacheDir;
    private bool $keepCache;
    private ?PDO $db = null;

    private const EXPANSIONS_URL = 'https://hl7.org/fhir/R4/hl7.fhir.r4.expansions.tgz';
    private const DEFINITIONS_URL = 'https://hl7.org/fhir/R4/definitions.json.zip';
    private const R5_EXPANSIONS_URL = 'https://hl7.org/fhir/R5/hl7.fhir.r5.expansions.tgz';
    private const R5_DEFINITIONS_URL = 'https://hl7.org/fhir/R5/definitions.json.zip';

    public const US_CORE_PACKAGES = [
        'US Core v3.1.1' => [
            'url' => 'https://hl7.org/fhir/us/core/STU3.1.1/package.tgz',
            'filename' => 'uscore_3.1.1.tgz',
            'expansions_url' => 'https://hl7.org/fhir/us/core/STU3.1.1/expansions.json',
            'expansions_filename' => 'uscore_3.1.1_expansions.json',
            'version' => '3.1.1'
        ],
        'US Core v5.0.1' => [
            'url' => 'https://hl7.org/fhir/us/core/STU5.0.1/package.tgz',
            'filename' => 'uscore_5.0.1.tgz',
            'expansions_url' => 'https://hl7.org/fhir/us/core/STU5.0.1/expansions.json',
            'expansions_filename' => 'uscore_5.0.1_expansions.json',
            'version' => '5.0.1'
        ],
        'US Core v6.1.0' => [
            'url' => 'https://hl7.org/fhir/us/core/STU6.1/package.tgz',
            'filename' => 'uscore_6.1.0.tgz',
            'expansions_url' => 'https://hl7.org/fhir/us/core/STU6.1/expansions.json',
            'expansions_filename' => 'uscore_6.1.0_expansions.json',
            'version' => '6.1.0'
        ],
        'US Core v7.0.0' => [
            'url' => 'https://hl7.org/fhir/us/core/STU7/package.tgz',
            'filename' => 'uscore_7.0.0.tgz',
            'expansions_url' => 'https://hl7.org/fhir/us/core/STU7/expansions.json',
            'expansions_filename' => 'uscore_7.0.0_expansions.json',
            'version' => '7.0.0'
        ],
        'US Core v8.0.0' => [
            'url' => 'https://hl7.org/fhir/us/core/STU8/package.tgz',
            'filename' => 'uscore_8.0.0.tgz',
            'expansions_url' => 'https://hl7.org/fhir/us/core/STU8/expansions.json',
            'expansions_filename' => 'uscore_8.0.0_expansions.json',
            'version' => '8.0.0'
        ],
        'US Core v9.0.0' => [
            'url' => 'https://hl7.org/fhir/us/core/STU9/package.tgz',
            'filename' => 'uscore_9.0.0.tgz',
            'expansions_url' => 'https://hl7.org/fhir/us/core/STU9/expansions.json',
            'expansions_filename' => 'uscore_9.0.0_expansions.json',
            'version' => '9.0.0'
        ],
        'mCODE v3.0.0' => [
            'url' => 'https://hl7.org/fhir/us/mcode/STU3/package.tgz',
            'filename' => 'mcode_3.0.0.tgz',
            'version' => '3.0.0'
        ],
        'mCODE v4.0.0' => [
            'url' => 'https://hl7.org/fhir/us/mcode/package.tgz',
            'filename' => 'mcode_4.0.0.tgz',
            'version' => '4.0.0'
        ],
        'SDOH Clinical Care v2.3.0' => [
            'url' => 'https://hl7.org/fhir/us/sdoh-clinicalcare/STU2.3/package.tgz',
            'filename' => 'sdoh_2.3.0.tgz',
            'version' => '2.3.0'
        ],
        'SDOH Clinical Care v3.0.0' => [
            'url' => 'https://hl7.org/fhir/us/sdoh-clinicalcare/package.tgz',
            'filename' => 'sdoh_3.0.0.tgz',
            'version' => '3.0.0'
        ]
    ];

    public const PACKAGE_METADATA = [
        'FHIR R4' => [
            'key' => 'pkg_fhir_r4',
            'name' => 'Base HL7 FHIR R4',
            'desc' => 'Standard international terminology (v4.0.1)',
            'valueset_count' => 1317
        ],
        'FHIR R5' => [
            'key' => 'pkg_fhir_r5',
            'name' => 'Base HL7 FHIR R5',
            'desc' => 'Next-generation international terminology (v5.0.0)',
            'valueset_count' => 827
        ],
        'US Core v3.1.1' => [
            'key' => 'pkg_us_core_311',
            'name' => 'US Core v3.1.1',
            'desc' => 'Epic & Cerner ONC USCDI v1 standard (Birth sex, OMB race & ethnicity categories)',
            'valueset_count' => 34
        ],
        'US Core v5.0.1' => [
            'key' => 'pkg_us_core_501',
            'name' => 'US Core v5.0.1',
            'desc' => 'ONC USCDI v2 (adds sexual orientation, expanded clinical notes)',
            'valueset_count' => 36
        ],
        'US Core v6.1.0' => [
            'key' => 'pkg_us_core_610',
            'name' => 'US Core v6.1.0',
            'desc' => 'ONC USCDI v3 (adds gender identity, observation category additions)',
            'valueset_count' => 38
        ],
        'US Core v7.0.0' => [
            'key' => 'pkg_us_core_700',
            'name' => 'US Core v7.0.0',
            'desc' => 'ONC USCDI v4 (Sex Parameter for Clinical Use / SFCU)',
            'valueset_count' => 32
        ],
        'US Core v8.0.0' => [
            'key' => 'pkg_us_core_800',
            'name' => 'US Core v8.0.0',
            'desc' => 'ONC USCDI v5 (Updated laboratory and clinical values)',
            'valueset_count' => 26
        ],
        'US Core v9.0.0' => [
            'key' => 'pkg_us_core_900',
            'name' => 'US Core v9.0.0',
            'desc' => 'Draft / latest updates from US Core development ballot',
            'valueset_count' => 26
        ],
        'mCODE v3.0.0' => [
            'key' => 'pkg_mcode_300',
            'name' => 'mCODE v3.0.0',
            'desc' => 'STU 3 baseline implemented in EHRs (TNM cancer staging, ECOG performance, histology, genomics)',
            'valueset_count' => 83
        ],
        'mCODE v4.0.0' => [
            'key' => 'pkg_mcode_400',
            'name' => 'mCODE v4.0.0',
            'desc' => 'STU 4 latest release (expanded pediatric oncology, tumor markers, surgical reporting)',
            'valueset_count' => 104
        ],
        'SDOH Clinical Care v2.3.0' => [
            'key' => 'pkg_sdoh_230',
            'name' => 'SDOH Clinical Care v2.3.0',
            'desc' => 'STU 2.3 baseline aligned with USCDI v3/v4 (PRAPARE, food, housing, transportation insecurity)',
            'valueset_count' => 15
        ],
        'SDOH Clinical Care v3.0.0' => [
            'key' => 'pkg_sdoh_300',
            'name' => 'SDOH Clinical Care v3.0.0',
            'desc' => 'STU 3 latest release (Gravity Project updated screening batteries, US Core 7.0 alignment)',
            'valueset_count' => 20
        ],
        'RxNorm' => [
            'key' => 'pkg_rxnorm',
            'name' => 'RxNorm Active Ingredients',
            'desc' => 'All ~14,710 NLM generic active substances & official RxCUIs (e.g. Amoxicillin, Atorvastatin, Lisinopril). Excludes 400,000+ brand-name, dose-form, and packaging permutations to ensure fast search and prevent dropdown freezing.',
            'valueset_count' => 1
        ],
        'LOINC' => [
            'key' => 'pkg_loinc',
            'name' => 'LOINC Common Lab Observations',
            'desc' => 'Top ~2,000 lab observations accounting for ~99% of clinical laboratory volume in US health systems',
            'external' => true,
            'valueset_count' => 2
        ]
    ];

    private ?array $selectedPackages = null;

    public function __construct(string $outputPath, ?string $cacheDir = null, bool $keepCache = false, ?array $selectedPackages = null)
    {
        $this->outputPath = $outputPath;
        $this->cacheDir = $cacheDir ?: sys_get_temp_dir() . '/fhir_r4_cache_' . getmypid();
        $this->keepCache = $keepCache;
        $this->selectedPackages = $selectedPackages;

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    public static function isDbValid(string $dbPath): bool
    {
        if (!file_exists($dbPath) || filesize($dbPath) < 1000000) {
            return false;
        }
        try {
            $db = new PDO('sqlite:' . $dbPath);
            $res = $db->query("SELECT count(*) FROM valuesets");
            if (!$res || (int)$res->fetchColumn() === 0) return false;
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function getDbStats(string $dbPath): ?array
    {
        if (!self::isDbValid($dbPath)) return null;
        try {
            $db = new PDO('sqlite:' . $dbPath);
            $vsCount = (int)$db->query("SELECT count(*) FROM valuesets")->fetchColumn();
            $conceptCount = (int)$db->query("SELECT count(*) FROM concepts")->fetchColumn();
            $pkgStmt = $db->query("SELECT package, count(*) as cnt FROM valuesets GROUP BY package ORDER BY package");
            $packages = [];
            while ($row = $pkgStmt->fetch(PDO::FETCH_ASSOC)) {
                $packages[$row['package']] = (int)$row['cnt'];
            }
            return [
                'valid' => true,
                'path' => $dbPath,
                'file_size_bytes' => filesize($dbPath),
                'file_size_mb' => round(filesize($dbPath) / 1024 / 1024, 2),
                'last_modified' => date('Y-m-d H:i:s', filemtime($dbPath)),
                'valueset_count' => $vsCount,
                'concept_count' => $conceptCount,
                'packages' => $packages
            ];
        } catch (Throwable $e) {
            return null;
        }
    }

    public function build(): array
    {
        $startTime = microtime(true);

        if (file_exists($this->outputPath) && self::isDbValid($this->outputPath) && $this->selectedPackages !== null) {
            return $this->updateDatabaseDifferential();
        }

        $dbDir = dirname($this->outputPath);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0777, true);
        }

        $tempDbPath = $this->outputPath . '.tmp.' . uniqid();
        if (file_exists($tempDbPath)) @unlink($tempDbPath);

        $this->initDatabase($tempDbPath);

        $includeFhirR4 = ($this->selectedPackages === null || in_array('FHIR R4', $this->selectedPackages, true));
        $includeFhirR5 = ($this->selectedPackages !== null && in_array('FHIR R5', $this->selectedPackages, true));

        // Step 1: Download required archives
        $expansionsTgz = null;
        $definitionsZip = null;
        if ($includeFhirR4) {
            $expansionsTgz = $this->downloadFile(self::EXPANSIONS_URL, 'hl7.fhir.r4.expansions.tgz');
            $definitionsZip = $this->downloadFile(self::DEFINITIONS_URL, 'definitions.json.zip');
        }

        $r5ExpansionsTgz = null;
        $r5DefinitionsZip = null;
        if ($includeFhirR5) {
            $r5ExpansionsTgz = $this->downloadFile(self::R5_EXPANSIONS_URL, 'hl7.fhir.r5.expansions.tgz');
            $r5DefinitionsZip = $this->downloadFile(self::R5_DEFINITIONS_URL, 'r5_definitions.json.zip');
        }

        $defaultIgPackages = ['US Core v3.1.1', 'US Core v6.1.0', 'US Core v7.0.0'];
        $downloadedUsCore = [];
        foreach (self::US_CORE_PACKAGES as $pkgName => $pkgInfo) {
            if ($this->selectedPackages !== null) {
                if (!in_array($pkgName, $this->selectedPackages, true)) {
                    continue;
                }
            } else {
                if (!in_array($pkgName, $defaultIgPackages, true)) {
                    continue;
                }
            }
            $downloadedUsCore[$pkgName] = [
                'path' => $this->downloadFile($pkgInfo['url'], $pkgInfo['filename']),
                'expansions_path' => isset($pkgInfo['expansions_url'])
                    ? $this->downloadFile($pkgInfo['expansions_url'], $pkgInfo['expansions_filename'])
                    : null,
                'version' => $pkgInfo['version']
            ];
        }

        // Step 2: Parse CodeSystems from definitions and US Core archives
        $codeSystems = [];
        if ($definitionsZip) {
            $codeSystems = $this->parseCodeSystems($definitionsZip);
        }
        if ($r5DefinitionsZip) {
            $r5CodeSystems = $this->parseCodeSystems($r5DefinitionsZip);
            foreach ($r5CodeSystems as $sysUrl => $sysData) {
                if (!isset($codeSystems[$sysUrl])) {
                    $codeSystems[$sysUrl] = $sysData;
                }
            }
        }
        foreach ($downloadedUsCore as $pkgName => $info) {
            $this->parseUsCoreCodeSystems($info['path'], $codeSystems);
        }

        // Step 3: Ingest pre-expanded Base FHIR ValueSets
        if ($includeFhirR4 && $expansionsTgz) {
            $this->ingestExpansions($expansionsTgz, 'FHIR R4', '4.0.1');
        }

        if ($includeFhirR5 && $r5ExpansionsTgz) {
            $this->ingestExpansions($r5ExpansionsTgz, 'FHIR R5', '5.0.0');
        }

        // Step 4: Ingest definition ValueSets & resolve unions
        if ($includeFhirR4 && $definitionsZip) {
            $this->ingestDefinitions($definitionsZip, $codeSystems, 'FHIR R4', '4.0.1');
        }

        if ($includeFhirR5 && $r5DefinitionsZip) {
            $this->ingestDefinitions($r5DefinitionsZip, $codeSystems, 'FHIR R5', '5.0.0');
        }

        // Step 5: Ingest versioned US Core packages and official expansions
        foreach ($downloadedUsCore as $pkgName => $info) {
            $this->ingestUsCorePackage($info['path'], $pkgName, $info['version'], $codeSystems);
            if (!empty($info['expansions_path'])) {
                $this->ingestUsCoreExpansions($info['expansions_path'], $pkgName, $info['version']);
            }
        }

        // Step 6: Seed supplemental demographic sets from decoupled configuration
        $this->seedSupplementalDemographics($downloadedUsCore);

        // Step 7: Auto-expand capped ValueSets with <= 5,000 concepts from HL7 FHIR Terminology Service
        $this->autoExpandCappedValuesets();

        // Step 7.5: Ingest RxNorm active ingredients if selected
        $includeRxNorm = ($this->selectedPackages !== null && in_array('RxNorm', $this->selectedPackages, true));
        if ($includeRxNorm) {
            $this->ingestRxNorm();
        }

        // Step 8: Preserve external packages (like LOINC) if already present in the existing database
        $this->preserveExternalPackages();

        // Finalizing: Post-processing, FTS5 virtual table, and optimization
        $this->rebuildFtsAndOptimize();

        $this->db = null;

        if (file_exists($this->outputPath)) {
            @unlink($this->outputPath);
        }
        rename($tempDbPath, $this->outputPath);
        @chmod($this->outputPath, 0666);

        if (!$this->keepCache) {
            $this->removeDirectory($this->cacheDir);
        }

        $elapsed = round(microtime(true) - $startTime, 2);
        $fileSizeMb = round(filesize($this->outputPath) / 1024 / 1024, 2);

        $verifyDb = new PDO('sqlite:' . $this->outputPath);
        $totalVs = (int)$verifyDb->query('SELECT COUNT(*) FROM valuesets')->fetchColumn();
        $totalConcepts = (int)$verifyDb->query('SELECT COUNT(*) FROM concepts')->fetchColumn();

        $pkgStmt = $verifyDb->query("SELECT package, count(*) as vs_count FROM valuesets GROUP BY package ORDER BY package");
        $packages = [];
        while ($p = $pkgStmt->fetch(PDO::FETCH_ASSOC)) {
            $packages[$p['package']] = (int)$p['vs_count'];
        }

        return [
            'success' => true,
            'path' => $this->outputPath,
            'valueset_count' => $totalVs,
            'concept_count' => $totalConcepts,
            'packages' => $packages,
            'file_size_mb' => $fileSizeMb,
            'elapsed_seconds' => $elapsed
        ];
    }

    private function initDatabase(string $path): void
    {
        $this->db = new PDO('sqlite:' . $path);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec('PRAGMA journal_mode = WAL;');
        $this->db->exec('PRAGMA synchronous = NORMAL;');
        $this->db->exec('PRAGMA temp_store = MEMORY;');
        $this->db->exec('PRAGMA cache_size = -64000;');

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS valuesets (
                id TEXT NOT NULL,
                package TEXT NOT NULL,
                url TEXT NOT NULL,
                urn TEXT,
                name TEXT,
                title TEXT,
                description TEXT,
                version TEXT,
                status TEXT,
                concept_count INTEGER DEFAULT 0,
                PRIMARY KEY (id, package)
            );
            CREATE INDEX IF NOT EXISTS idx_valuesets_id ON valuesets(id);
            CREATE INDEX IF NOT EXISTS idx_valuesets_url ON valuesets(url);
            CREATE INDEX IF NOT EXISTS idx_valuesets_urn ON valuesets(urn);
            CREATE INDEX IF NOT EXISTS idx_valuesets_package ON valuesets(package);
            CREATE INDEX IF NOT EXISTS idx_valuesets_concept_count ON valuesets(concept_count, title);

            CREATE TABLE IF NOT EXISTS concepts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                valueset_id TEXT NOT NULL,
                package TEXT NOT NULL,
                system TEXT,
                code TEXT NOT NULL,
                display TEXT NOT NULL,
                definition TEXT,
                sort_order INTEGER DEFAULT 0,
                FOREIGN KEY(valueset_id, package) REFERENCES valuesets(id, package) ON DELETE CASCADE
            );
            CREATE INDEX IF NOT EXISTS idx_concepts_vs_pkg ON concepts(valueset_id, package);
            CREATE INDEX IF NOT EXISTS idx_concepts_code_pkg ON concepts(valueset_id, package, code);

            CREATE VIRTUAL TABLE IF NOT EXISTS valuesets_fts USING fts5(
                id,
                title,
                name,
                description,
                url,
                urn,
                package UNINDEXED,
                tokenize = 'unicode61 remove_diacritics 2'
            );

            CREATE VIRTUAL TABLE IF NOT EXISTS concepts_fts USING fts5(
                code,
                display,
                valueset_id UNINDEXED,
                package UNINDEXED,
                tokenize = 'unicode61 remove_diacritics 2'
            );
        ");
    }

    private function downloadFile(string $url, string $filename): string
    {
        $dest = $this->cacheDir . '/' . $filename;
        if (file_exists($dest) && filesize($dest) > 50000) {
            return $dest;
        }

        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) REDCap-Clinical-Terminology-Mapper/1.0\r\nAccept: */*\r\n",
                'timeout' => 120
            ]
        ];
        $ctx = stream_context_create($opts);
        $fp = fopen($url, 'r', false, $ctx);
        if (!$fp) {
            throw new RuntimeException("Failed to download {$url}");
        }

        file_put_contents($dest, $fp);
        fclose($fp);

        return $dest;
    }

    private function parseCodeSystems(string $definitionsZipPath): array
    {
        $codeSystems = [];
        $zip = new ZipArchive();
        if ($zip->open($definitionsZipPath) !== true) {
            throw new RuntimeException("Cannot open {$definitionsZipPath}");
        }

        $targets = ['valuesets.json', 'v3-codesystems.json'];
        foreach ($targets as $targetName) {
            $fp = $zip->getStream($targetName);
            if (!$fp) continue;

            $content = stream_get_contents($fp);
            fclose($fp);
            $bundle = json_decode($content, true);

            if (!empty($bundle['entry'])) {
                foreach ($bundle['entry'] as $entry) {
                    $res = $entry['resource'] ?? [];
                    if (($res['resourceType'] ?? '') === 'CodeSystem') {
                        $sysUrl = $res['url'] ?? '';
                        if ($sysUrl === '') continue;

                        $concepts = [];
                        $this->extractCodeSystemConcepts($res['concept'] ?? [], $sysUrl, $concepts);
                        if (!empty($concepts)) {
                            $codeSystems[$sysUrl] = [
                                'flat' => $concepts,
                                'tree' => $res['concept'] ?? []
                            ];
                        }
                    }
                }
            }
        }

        $zip->close();
        return $codeSystems;
    }

    private function parseUsCoreCodeSystems(string $usCoreTgzPath, array &$codeSystems): void
    {
        $phar = new PharData($usCoreTgzPath);
        foreach (new RecursiveIteratorIterator($phar) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'json') continue;
            $filename = $file->getFilename();
            if (!str_starts_with($filename, 'CodeSystem-')) continue;

            $data = json_decode(file_get_contents($file->getPathname()), true);
            if (!$data || ($data['resourceType'] ?? '') !== 'CodeSystem') continue;

            $sysUrl = $data['url'] ?? '';
            if ($sysUrl === '') continue;

            $concepts = [];
            $this->extractCodeSystemConcepts($data['concept'] ?? [], $sysUrl, $concepts);
            if (!empty($concepts)) {
                $codeSystems[$sysUrl] = [
                    'flat' => $concepts,
                    'tree' => $data['concept'] ?? []
                ];
            }
        }
    }

    private function extractCodeSystemConcepts(array $conceptsList, string $systemUrl, array &$out): void
    {
        foreach ($conceptsList as $item) {
            $code = (string)($item['code'] ?? '');
            if ($code === '') continue;

            $out[] = [
                'system' => $systemUrl,
                'code' => $code,
                'display' => (string)($item['display'] ?? $code),
                'definition' => (string)($item['definition'] ?? '')
            ];

            if (!empty($item['concept']) && is_array($item['concept'])) {
                $this->extractCodeSystemConcepts($item['concept'], $systemUrl, $out);
            }
        }
    }

    private function ingestExpansions(string $tgzPath, string $packageName = 'FHIR R4', string $defaultVersion = '4.0.1'): int
    {
        $phar = new PharData($tgzPath);
        $count = 0;

        $this->db->beginTransaction();

        $vsStmt = $this->db->prepare("
            INSERT OR REPLACE INTO valuesets (id, package, url, urn, name, title, description, version, status)
            VALUES (:id, :package, :url, :urn, :name, :title, :description, :version, :status)
        ");

        $delConcepts = $this->db->prepare("DELETE FROM concepts WHERE valueset_id = ? AND package = ?");

        $cStmt = $this->db->prepare("
            INSERT INTO concepts (valueset_id, package, system, code, display, definition, sort_order)
            VALUES (:vs_id, :package, :system, :code, :display, :definition, :sort_order)
        ");

        foreach (new RecursiveIteratorIterator($phar) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'json') continue;

            $content = file_get_contents($file->getPathname());
            $data = json_decode($content, true);
            if (!$data || ($data['resourceType'] ?? '') !== 'ValueSet') continue;

            $id = $data['id'] ?? '';
            if ($id === '') continue;

            $urn = null;
            if (!empty($data['identifier'])) {
                foreach ($data['identifier'] as $ident) {
                    $val = $ident['value'] ?? '';
                    if (str_starts_with($val, 'urn:oid:') || str_starts_with($val, '2.16.840.')) {
                        $urn = str_starts_with($val, 'urn:oid:') ? $val : 'urn:oid:' . $val;
                        break;
                    }
                }
            }

            $vsStmt->execute([
                ':id' => $id,
                ':package' => $packageName,
                ':url' => $data['url'] ?? ('http://hl7.org/fhir/ValueSet/' . $id),
                ':urn' => $urn,
                ':name' => $data['name'] ?? $id,
                ':title' => $data['title'] ?? $data['name'] ?? $id,
                ':description' => $data['description'] ?? '',
                ':version' => $data['version'] ?? $defaultVersion,
                ':status' => $data['status'] ?? 'active'
            ]);

            $delConcepts->execute([$id, $packageName]);

            $concepts = [];
            if (!empty($data['expansion']['contains'])) {
                $this->extractContainsConcepts($data['expansion']['contains'], $concepts);
            } elseif (!empty($data['compose']['include'])) {
                foreach ($data['compose']['include'] as $inc) {
                    $sys = $inc['system'] ?? '';
                    if (!empty($inc['concept'])) {
                        foreach ($inc['concept'] as $c) {
                            $code = (string)($c['code'] ?? '');
                            if ($code === '') continue;
                            $concepts[] = [
                                'system' => $sys,
                                'code' => $code,
                                'display' => (string)($c['display'] ?? $code),
                                'definition' => (string)($c['definition'] ?? '')
                            ];
                        }
                    }
                }
            }

            $order = 0;
            foreach ($concepts as $c) {
                $cStmt->execute([
                    ':vs_id' => $id,
                    ':package' => $packageName,
                    ':system' => $c['system'],
                    ':code' => $c['code'],
                    ':display' => $c['display'],
                    ':definition' => $c['definition'],
                    ':sort_order' => $order++
                ]);
            }

            $count++;
        }

        $this->db->commit();
        return $count;
    }

    private function extractContainsConcepts(array $contains, array &$out): void
    {
        foreach ($contains as $item) {
            $code = (string)($item['code'] ?? '');
            if ($code === '') continue;

            $out[] = [
                'system' => $item['system'] ?? '',
                'code' => $code,
                'display' => (string)($item['display'] ?? $code),
                'definition' => ''
            ];

            if (!empty($item['contains']) && is_array($item['contains'])) {
                $this->extractContainsConcepts($item['contains'], $out);
            }
        }
    }

    /**
     * Resolves concepts for a compose.include block, handling explicit concepts,
     * hierarchical filters (e.g., concept is-a <parent>), and full code system inclusions.
     */
    private function resolveIncludeConcepts(array $include, array $codeSystems): array
    {
        $sys = $include['system'] ?? '';

        // Case 1: Explicit concept codes listed in the include
        if (!empty($include['concept']) && is_array($include['concept'])) {
            $concepts = [];
            $codeMap = [];
            if (!empty($sys) && isset($codeSystems[$sys]['flat'])) {
                foreach ($codeSystems[$sys]['flat'] as $item) {
                    $codeMap[$item['code']] = $item;
                }
            }

            foreach ($include['concept'] as $c) {
                $code = (string)($c['code'] ?? '');
                if ($code === '') continue;

                $disp = (string)($c['display'] ?? '');
                $def = (string)($c['definition'] ?? '');
                if ($disp === '' && isset($codeMap[$code])) {
                    $disp = $codeMap[$code]['display'];
                }
                if ($def === '' && isset($codeMap[$code])) {
                    $def = $codeMap[$code]['definition'];
                }

                $concepts[] = [
                    'system' => $sys,
                    'code' => $code,
                    'display' => $disp !== '' ? $disp : $code,
                    'definition' => $def
                ];
            }
            return $concepts;
        }

        // Case 2: Filter-based inclusion (e.g. concept is-a <code)
        if (!empty($include['filter']) && is_array($include['filter'])) {
            if (empty($sys) || !isset($codeSystems[$sys])) {
                // We cannot evaluate filters on systems not loaded locally (e.g. SNOMED CT)
                // Never fall back to dumping everything when a filter is specified!
                return [];
            }

            $csTree = $codeSystems[$sys]['tree'] ?? [];
            $concepts = [];

            foreach ($include['filter'] as $filter) {
                $prop = $filter['property'] ?? '';
                $op = $filter['op'] ?? '';
                $targetCode = (string)($filter['value'] ?? '');
                if ($targetCode === '') continue;

                if ($prop === 'concept' || $prop === 'code' || $prop === '') {
                    if ($op === 'is-a' || $op === '=') {
                        $node = $this->findConceptTreeNode($csTree, $targetCode);
                        if ($node !== null) {
                            $this->extractSubtreeConcepts($node, $sys, $concepts, true);
                        }
                    } elseif ($op === 'descendent-of') {
                        $node = $this->findConceptTreeNode($csTree, $targetCode);
                        if ($node !== null) {
                            $this->extractSubtreeConcepts($node, $sys, $concepts, false);
                        }
                    }
                }
            }

            return $concepts;
        }

        // Case 3: Complete CodeSystem inclusion (no explicit concepts, no filter)
        if (!empty($sys) && isset($codeSystems[$sys]['flat'])) {
            return $codeSystems[$sys]['flat'];
        }

        return [];
    }

    private function findConceptTreeNode(array $tree, string $code): ?array
    {
        foreach ($tree as $node) {
            if (($node['code'] ?? '') === $code) {
                return $node;
            }
            if (!empty($node['concept']) && is_array($node['concept'])) {
                $found = $this->findConceptTreeNode($node['concept'], $code);
                if ($found !== null) return $found;
            }
        }
        return null;
    }

    private function extractSubtreeConcepts(array $node, string $systemUrl, array &$out, bool $includeSelf = true): void
    {
        if ($includeSelf && !empty($node['code'])) {
            $out[] = [
                'system' => $systemUrl,
                'code' => (string)$node['code'],
                'display' => (string)($node['display'] ?? $node['code']),
                'definition' => (string)($node['definition'] ?? '')
            ];
        }
        if (!empty($node['concept']) && is_array($node['concept'])) {
            foreach ($node['concept'] as $child) {
                $this->extractSubtreeConcepts($child, $systemUrl, $out, true);
            }
        }
    }

    private function ingestDefinitions(string $definitionsZipPath, array $codeSystems, string $packageName = 'FHIR R4', string $defaultVersion = '4.0.1'): int
    {
        $zip = new ZipArchive();
        if ($zip->open($definitionsZipPath) !== true) {
            throw new RuntimeException("Cannot open {$definitionsZipPath}");
        }

        $count = 0;
        $targets = ['valuesets.json', 'v2-tables.json', 'v3-codesystems.json'];

        $this->db->beginTransaction();

        $vsStmt = $this->db->prepare("
            INSERT OR REPLACE INTO valuesets (id, package, url, urn, name, title, description, version, status)
            VALUES (:id, :package, :url, :urn, :name, :title, :description, :version, :status)
        ");

        $delConcepts = $this->db->prepare("DELETE FROM concepts WHERE valueset_id = ? AND package = ?");

        $cStmt = $this->db->prepare("
            INSERT INTO concepts (valueset_id, package, system, code, display, definition, sort_order)
            VALUES (:vs_id, :package, :system, :code, :display, :definition, :sort_order)
        ");

        $checkConceptsStmt = $this->db->prepare("SELECT count(*) FROM concepts WHERE valueset_id = ? AND package = ?");

        foreach ($targets as $targetName) {
            $fp = $zip->getStream($targetName);
            if (!$fp) continue;

            $content = stream_get_contents($fp);
            fclose($fp);
            $bundle = json_decode($content, true);

            if (empty($bundle['entry'])) continue;

            foreach ($bundle['entry'] as $entry) {
                $data = $entry['resource'] ?? [];
                if (($data['resourceType'] ?? '') !== 'ValueSet') continue;

                $id = $data['id'] ?? '';
                if ($id === '') continue;

                $urn = null;
                if (!empty($data['identifier'])) {
                    foreach ($data['identifier'] as $ident) {
                        $val = $ident['value'] ?? '';
                        if (str_starts_with($val, 'urn:oid:') || str_starts_with($val, '2.16.840.')) {
                            $urn = str_starts_with($val, 'urn:oid:') ? $val : 'urn:oid:' . $val;
                            break;
                        }
                    }
                }

                $vsStmt->execute([
                    ':id' => $id,
                    ':package' => $packageName,
                    ':url' => $data['url'] ?? ('http://hl7.org/fhir/ValueSet/' . $id),
                    ':urn' => $urn,
                    ':name' => $data['name'] ?? $id,
                    ':title' => $data['title'] ?? $data['name'] ?? $id,
                    ':description' => $data['description'] ?? '',
                    ':version' => $data['version'] ?? $defaultVersion,
                    ':status' => $data['status'] ?? 'active'
                ]);

                $checkConceptsStmt->execute([$id, $packageName]);
                $existingConceptCount = (int)$checkConceptsStmt->fetchColumn();

                if ($existingConceptCount === 0) {
                    $concepts = [];

                    if (!empty($data['expansion']['contains'])) {
                        $this->extractContainsConcepts($data['expansion']['contains'], $concepts);
                    } elseif (!empty($data['compose']['include'])) {
                        foreach ($data['compose']['include'] as $inc) {
                            $incConcepts = $this->resolveIncludeConcepts($inc, $codeSystems);
                            foreach ($incConcepts as $ic) {
                                $concepts[] = $ic;
                            }
                        }
                    }

                    if (!empty($concepts)) {
                        $delConcepts->execute([$id, $packageName]);
                        $order = 0;
                        $seen = [];
                        foreach ($concepts as $c) {
                            $sig = $c['system'] . '::' . $c['code'];
                            if (isset($seen[$sig])) continue;
                            $seen[$sig] = true;

                            $cStmt->execute([
                                ':vs_id' => $id,
                                ':package' => $packageName,
                                ':system' => $c['system'],
                                ':code' => $c['code'],
                                ':display' => $c['display'],
                                ':definition' => $c['definition'],
                                ':sort_order' => $order++
                            ]);
                        }
                    }
                }

                $count++;
            }
        }

        $this->db->commit();
        $zip->close();
        return $count;
    }

    private function ingestUsCorePackage(string $usCoreTgzPath, string $pkgLabel, string $pkgVer, array $codeSystems): int
    {
        $phar = new PharData($usCoreTgzPath);
        $count = 0;

        $this->db->beginTransaction();

        $vsStmt = $this->db->prepare("
            INSERT OR REPLACE INTO valuesets (id, package, url, urn, name, title, description, version, status)
            VALUES (:id, :pkg, :url, :urn, :name, :title, :description, :version, :status)
        ");

        $delConcepts = $this->db->prepare("DELETE FROM concepts WHERE valueset_id = ? AND package = ?");

        $cStmt = $this->db->prepare("
            INSERT INTO concepts (valueset_id, package, system, code, display, definition, sort_order)
            VALUES (:vs_id, :pkg, :system, :code, :display, :definition, :sort_order)
        ");

        foreach (new RecursiveIteratorIterator($phar) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'json') continue;

            $filename = $file->getFilename();
            if (!str_starts_with($filename, 'ValueSet-')) continue;

            $content = file_get_contents($file->getPathname());
            $data = json_decode($content, true);
            if (!$data || ($data['resourceType'] ?? '') !== 'ValueSet') continue;

            $id = $data['id'] ?? '';
            if ($id === '') continue;

            $urn = null;
            if (!empty($data['identifier'])) {
                foreach ($data['identifier'] as $ident) {
                    $val = $ident['value'] ?? '';
                    if (str_starts_with($val, 'urn:oid:') || str_starts_with($val, '2.16.840.')) {
                        $urn = str_starts_with($val, 'urn:oid:') ? $val : 'urn:oid:' . $val;
                        break;
                    }
                }
            }

            $vsStmt->execute([
                ':id' => $id,
                ':pkg' => $pkgLabel,
                ':url' => $data['url'] ?? ('http://hl7.org/fhir/us/core/ValueSet/' . $id),
                ':urn' => $urn,
                ':name' => $data['name'] ?? $id,
                ':title' => $data['title'] ?? $data['name'] ?? $id,
                ':description' => $data['description'] ?? '',
                ':version' => $pkgVer,
                ':status' => $data['status'] ?? 'active'
            ]);

            $delConcepts->execute([$id, $pkgLabel]);

            $concepts = [];
            if (!empty($data['expansion']['contains'])) {
                $this->extractContainsConcepts($data['expansion']['contains'], $concepts);
            } elseif (!empty($data['compose']['include'])) {
                foreach ($data['compose']['include'] as $inc) {
                    $incConcepts = $this->resolveIncludeConcepts($inc, $codeSystems);
                    foreach ($incConcepts as $ic) {
                        $concepts[] = $ic;
                    }
                }
            }

            $order = 0;
            $seen = [];
            foreach ($concepts as $c) {
                $sig = $c['system'] . '::' . $c['code'];
                if (isset($seen[$sig])) continue;
                $seen[$sig] = true;

                $cStmt->execute([
                    ':vs_id' => $id,
                    ':pkg' => $pkgLabel,
                    ':system' => $c['system'],
                    ':code' => $c['code'],
                    ':display' => $c['display'],
                    ':definition' => $c['definition'],
                    ':sort_order' => $order++
                ]);
            }

            $count++;
        }

        $this->db->commit();
        return $count;
    }

    private function ingestUsCoreExpansions(string $jsonPath, string $pkgLabel, string $pkgVer): int
    {
        if (!file_exists($jsonPath)) return 0;
        $content = file_get_contents($jsonPath);
        $bundle = json_decode($content, true);
        if (empty($bundle['entry'])) return 0;

        $count = 0;
        $this->db->beginTransaction();

        $vsStmt = $this->db->prepare("
            INSERT OR REPLACE INTO valuesets (id, package, url, urn, name, title, description, version, status)
            VALUES (:id, :pkg, :url, :urn, :name, :title, :description, :version, :status)
        ");

        $delConcepts = $this->db->prepare("DELETE FROM concepts WHERE valueset_id = ? AND package = ?");

        $cStmt = $this->db->prepare("
            INSERT INTO concepts (valueset_id, package, system, code, display, definition, sort_order)
            VALUES (:vs_id, :pkg, :system, :code, :display, :definition, :sort_order)
        ");

        foreach ($bundle['entry'] as $entry) {
            $data = $entry['resource'] ?? [];
            if (($data['resourceType'] ?? '') !== 'ValueSet') continue;

            $id = $data['id'] ?? basename($data['url'] ?? '');
            if ($id === '') continue;

            $urn = null;
            if (!empty($data['identifier'])) {
                foreach ($data['identifier'] as $ident) {
                    $val = $ident['value'] ?? '';
                    if (str_starts_with($val, 'urn:oid:') || str_starts_with($val, '2.16.840.')) {
                        $urn = str_starts_with($val, 'urn:oid:') ? $val : 'urn:oid:' . $val;
                        break;
                    }
                }
            }

            $vsStmt->execute([
                ':id' => $id,
                ':pkg' => $pkgLabel,
                ':url' => $data['url'] ?? ('http://hl7.org/fhir/us/core/ValueSet/' . $id),
                ':urn' => $urn,
                ':name' => $data['name'] ?? $id,
                ':title' => $data['title'] ?? $data['name'] ?? $id,
                ':description' => $data['description'] ?? '',
                ':version' => $pkgVer,
                ':status' => $data['status'] ?? 'active'
            ]);

            $concepts = [];
            if (!empty($data['expansion']['contains'])) {
                $this->extractContainsConcepts($data['expansion']['contains'], $concepts);
            }

            if (!empty($concepts)) {
                $delConcepts->execute([$id, $pkgLabel]);
                $order = 0;
                $seen = [];
                foreach ($concepts as $c) {
                    $sig = $c['system'] . '::' . $c['code'];
                    if (isset($seen[$sig])) continue;
                    $seen[$sig] = true;

                    $cStmt->execute([
                        ':vs_id' => $id,
                        ':pkg' => $pkgLabel,
                        ':system' => $c['system'],
                        ':code' => $c['code'],
                        ':display' => $c['display'],
                        ':definition' => $c['definition'],
                        ':sort_order' => $order++
                    ]);
                }

                // If birthsex, register us-core-birthsex alias as well
                if ($id === 'birthsex') {
                    $aliasId = 'us-core-birthsex';
                    $vsStmt->execute([
                        ':id' => $aliasId,
                        ':pkg' => $pkgLabel,
                        ':url' => $data['url'] ?? ('http://hl7.org/fhir/us/core/ValueSet/' . $aliasId),
                        ':urn' => $urn,
                        ':name' => 'USCoreBirthSex',
                        ':title' => 'US Core Birth Sex',
                        ':description' => $data['description'] ?? '',
                        ':version' => $pkgVer,
                        ':status' => $data['status'] ?? 'active'
                    ]);
                    $delConcepts->execute([$aliasId, $pkgLabel]);
                    $order = 0;
                    foreach ($concepts as $c) {
                        $cStmt->execute([
                            ':vs_id' => $aliasId,
                            ':pkg' => $pkgLabel,
                            ':system' => $c['system'],
                            ':code' => $c['code'],
                            ':display' => $c['display'],
                            ':definition' => $c['definition'],
                            ':sort_order' => $order++
                        ]);
                    }
                }
            }

            $count++;
        }

        $this->db->commit();
        return $count;
    }

    private function seedSupplementalDemographics(array $activeUsCorePackages = []): void
    {
        $usCoreVersions = [];
        foreach ($activeUsCorePackages as $pkgLabel => $info) {
            if (str_starts_with($pkgLabel, 'US Core')) {
                $usCoreVersions[$pkgLabel] = $info['version'];
            }
        }
        if (empty($activeUsCorePackages) && $this->selectedPackages === null) {
            foreach (self::US_CORE_PACKAGES as $pkgLabel => $info) {
                if (str_starts_with($pkgLabel, 'US Core')) {
                    $usCoreVersions[$pkgLabel] = $info['version'];
                }
            }
        }
        if (empty($usCoreVersions)) return;

        // Fetch official Gender Identity ValueSet directly from HL7 FHIR Terminology Service (VSAC OID 2.16.840.1.113762.1.4.1021.32)
        $vsacUrl = 'https://tx.fhir.org/r4/ValueSet/$expand?url=http://cts.nlm.nih.gov/fhir/ValueSet/2.16.840.1.113762.1.4.1021.32';
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/fhir+json, application/json\r\nUser-Agent: Mozilla/5.0 REDCap-Clinical-Terminology-Mapper/1.0\r\n",
                'timeout' => 30
            ]
        ];
        $ctx = stream_context_create($opts);
        $raw = @file_get_contents($vsacUrl, false, $ctx);
        if (!$raw) return;

        $json = json_decode($raw, true);
        $contains = $json['expansion']['contains'] ?? [];
        if (empty($contains)) return;

        $this->db->beginTransaction();

        $vsStmt = $this->db->prepare("
            INSERT OR REPLACE INTO valuesets (id, package, url, urn, name, title, description, version, status)
            VALUES (:id, :package, :url, :urn, :name, :title, :description, :version, :status)
        ");
        $del = $this->db->prepare("DELETE FROM concepts WHERE valueset_id = ? AND package = ?");
        $cStmt = $this->db->prepare("
            INSERT INTO concepts (valueset_id, package, system, code, display, definition, sort_order)
            VALUES (:vs_id, :package, :system, :code, :display, :definition, :sort_order)
        ");

        $id = 'us-core-gender-identity';
        $url = 'http://hl7.org/fhir/us/core/ValueSet/us-core-gender-identity';
        $urn = 'urn:oid:2.16.840.1.113762.1.4.1021.32';
        $name = 'USCoreGenderIdentity';
        $title = 'US Core Gender Identity';
        $description = 'Codes for gender identity in US Core (sourced from VSAC / NLM).';

        foreach ($usCoreVersions as $pkgLabel => $ver) {
            $vsStmt->execute([
                ':id' => $id,
                ':package' => $pkgLabel,
                ':url' => $url,
                ':urn' => $urn,
                ':name' => $name,
                ':title' => $title,
                ':description' => $description,
                ':version' => $ver,
                ':status' => 'active'
            ]);

            $del->execute([$id, $pkgLabel]);
            $order = 0;
            foreach ($contains as $c) {
                $code = (string)($c['code'] ?? '');
                if ($code === '') continue;
                $display = (string)($c['display'] ?? $code);
                $system = (string)($c['system'] ?? '');

                $cStmt->execute([
                    ':vs_id' => $id,
                    ':package' => $pkgLabel,
                    ':system' => $system,
                    ':code' => $code,
                    ':display' => $display,
                    ':definition' => $display,
                    ':sort_order' => $order++
                ]);
            }
        }

        $this->db->commit();
    }

    private function autoExpandCappedValuesets(?array $packagesToFilter = null): void
    {
        // Materialize current counts to find candidates
        $this->db->exec("
            UPDATE valuesets 
            SET concept_count = (
                SELECT COUNT(*) 
                FROM concepts c 
                WHERE c.valueset_id = valuesets.id AND c.package = valuesets.package
            );
        ");

        $sql = "SELECT id, package, url, version, concept_count FROM valuesets WHERE concept_count >= 1000";
        $params = [];
        if (!empty($packagesToFilter)) {
            $placeholders = implode(',', array_fill(0, count($packagesToFilter), '?'));
            $sql .= " AND package IN ({$placeholders})";
            $params = array_values($packagesToFilter);
        }
        $sql .= " ORDER BY concept_count DESC";

        $st = $this->db->prepare($sql);
        $st->execute($params);
        $candidates = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($candidates)) {
            return;
        }

        require_once __DIR__ . '/ValueSetSizeCatalog.php';

        $cStmt = $this->db->prepare("
            INSERT INTO concepts (valueset_id, package, system, code, display, definition, sort_order)
            VALUES (:vs_id, :package, :system, :code, :display, :definition, :sort_order)
        ");
        $delStmt = $this->db->prepare("DELETE FROM concepts WHERE valueset_id = ? AND package = ?");
        $updVsStmt = $this->db->prepare("UPDATE valuesets SET concept_count = ? WHERE id = ? AND package = ?");

        foreach ($candidates as $row) {
            $id = $row['id'];
            $pkg = $row['package'];
            $url = $row['url'] ?? '';
            $curCount = (int)$row['concept_count'];

            if (empty($url)) continue;

            $catalogEntry = ValueSetSizeCatalog::lookup($id, $pkg, $row['version'] ?? null);
            $cachedVal = $catalogEntry !== null ? $catalogEntry['total'] : null;

            // If total is numeric and <= 5000, and current_count differs, auto-expand!
            if (is_numeric($cachedVal) && (int)$cachedVal <= 5000 && (int)$cachedVal !== $curCount) {
                $expandedConcepts = $this->fetchRemoteExpansionConcepts($url, $pkg);
                if (!empty($expandedConcepts)) {
                    $this->db->beginTransaction();
                    $delStmt->execute([$id, $pkg]);
                    $order = 0;
                    foreach ($expandedConcepts as $c) {
                        $cStmt->execute([
                            ':vs_id' => $id,
                            ':package' => $pkg,
                            ':system' => $c['system'],
                            ':code' => $c['code'],
                            ':display' => $c['display'],
                            ':definition' => $c['definition'],
                            ':sort_order' => $order++
                        ]);
                    }
                    $newCount = count($expandedConcepts);
                    $updVsStmt->execute([$newCount, $id, $pkg]);
                    $this->db->commit();
                }
            }
        }
    }

    private function fetchRemoteExpansionConcepts(string $url, string $package = 'FHIR R4'): ?array
    {
        $endpoint = ($package === 'FHIR R5' ? 'r5' : 'r4');
        $expandUrl = "https://tx.fhir.org/{$endpoint}/ValueSet/\$expand?url=" . urlencode($url) . "&count=5000";
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/fhir+json\r\nUser-Agent: REDCap-Clinical-Terminology-Mapper/1.0\r\n",
                'timeout' => 25,
                'ignore_errors' => true
            ]
        ]);

        $raw = @file_get_contents($expandUrl, false, $ctx);
        if (!$raw) return null;

        $json = json_decode($raw, true);
        if (!$json || empty($json['expansion']['contains'])) {
            return null;
        }

        $concepts = [];
        $seen = [];
        foreach ($json['expansion']['contains'] as $item) {
            $code = (string)($item['code'] ?? '');
            if ($code === '') continue;
            $sys = (string)($item['system'] ?? '');
            $sig = $sys . '::' . $code;
            if (isset($seen[$sig])) continue;
            $seen[$sig] = true;

            $concepts[] = [
                'system' => $sys,
                'code' => $code,
                'display' => (string)($item['display'] ?? $code),
                'definition' => (string)($item['display'] ?? $code)
            ];
        }

        return $concepts;
    }

    private function preserveExternalPackages(): void
    {
        if (!file_exists($this->outputPath)) {
            return;
        }

        try {
            $oldDb = new PDO('sqlite:' . $this->outputPath);
            $loincCount = (int)$oldDb->query("SELECT count(*) FROM valuesets WHERE package = 'LOINC'")->fetchColumn();
            if ($loincCount > 0) {
                $this->db->exec("ATTACH DATABASE '" . addslashes($this->outputPath) . "' AS olddb");
                $this->db->exec("INSERT OR IGNORE INTO valuesets SELECT * FROM olddb.valuesets WHERE package = 'LOINC'");
                $this->db->exec("INSERT OR IGNORE INTO concepts SELECT * FROM olddb.concepts WHERE package = 'LOINC'");
                $this->db->exec("DETACH DATABASE olddb");
            }
        } catch (Throwable $e) {}
    }

    public function updateDatabaseDifferential(): array
    {
        $startTime = microtime(true);
        $this->db = new PDO('sqlite:' . $this->outputPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $this->db->query("SELECT DISTINCT package FROM valuesets");
        $installedPackages = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $selected = $this->selectedPackages ?? [];

        // Identify packages to drop, excluding external packages (e.g. LOINC managed in dedicated panel)
        $externalPackages = [];
        foreach (self::PACKAGE_METADATA as $metaPkgName => $meta) {
            if (!empty($meta['external'])) {
                $externalPackages[] = $metaPkgName;
            }
        }
        $toDrop = array_values(array_diff($installedPackages, $selected, $externalPackages));
        $toAdd = array_values(array_diff($selected, $installedPackages));

        // 1. Drop deselected packages
        if (!empty($toDrop)) {
            $this->db->beginTransaction();
            $delVs = $this->db->prepare("DELETE FROM valuesets WHERE package = ?");
            $delC = $this->db->prepare("DELETE FROM concepts WHERE package = ?");
            foreach ($toDrop as $pkg) {
                $delVs->execute([$pkg]);
                $delC->execute([$pkg]);
            }
            $this->db->commit();
        }

        // 2. Ingest newly selected packages
        if (!empty($toAdd)) {
            $codeSystems = [];

            // Base FHIR R4
            if (in_array('FHIR R4', $toAdd, true)) {
                $expansionsTgz = $this->downloadFile(self::EXPANSIONS_URL, 'hl7.fhir.r4.expansions.tgz');
                $definitionsZip = $this->downloadFile(self::DEFINITIONS_URL, 'definitions.json.zip');
                $codeSystems = array_merge($codeSystems, $this->parseCodeSystems($definitionsZip));
                $this->ingestExpansions($expansionsTgz, 'FHIR R4', '4.0.1');
                $this->ingestDefinitions($definitionsZip, $codeSystems, 'FHIR R4', '4.0.1');
            }

            // Base FHIR R5
            if (in_array('FHIR R5', $toAdd, true)) {
                $r5ExpansionsTgz = $this->downloadFile(self::R5_EXPANSIONS_URL, 'hl7.fhir.r5.expansions.tgz');
                $r5DefinitionsZip = $this->downloadFile(self::R5_DEFINITIONS_URL, 'r5_definitions.json.zip');
                $r5CodeSystems = $this->parseCodeSystems($r5DefinitionsZip);
                foreach ($r5CodeSystems as $sysUrl => $sysData) {
                    if (!isset($codeSystems[$sysUrl])) {
                        $codeSystems[$sysUrl] = $sysData;
                    }
                }
                $this->ingestExpansions($r5ExpansionsTgz, 'FHIR R5', '5.0.0');
                $this->ingestDefinitions($r5DefinitionsZip, $codeSystems, 'FHIR R5', '5.0.0');
            }

            // IG Packages (US Core versions, mCODE, SDOH)
            $igPackagesToAdd = [];
            foreach (self::US_CORE_PACKAGES as $pkgName => $pkgInfo) {
                if (in_array($pkgName, $toAdd, true)) {
                    $igPackagesToAdd[$pkgName] = [
                        'path' => $this->downloadFile($pkgInfo['url'], $pkgInfo['filename']),
                        'expansions_path' => isset($pkgInfo['expansions_url'])
                            ? $this->downloadFile($pkgInfo['expansions_url'], $pkgInfo['expansions_filename'])
                            : null,
                        'version' => $pkgInfo['version']
                    ];
                }
            }

            if (!empty($igPackagesToAdd)) {
                foreach ($igPackagesToAdd as $pkgName => $info) {
                    $this->parseUsCoreCodeSystems($info['path'], $codeSystems);
                }
                foreach ($igPackagesToAdd as $pkgName => $info) {
                    $this->ingestUsCorePackage($info['path'], $pkgName, $info['version'], $codeSystems);
                    if (!empty($info['expansions_path'])) {
                        $this->ingestUsCoreExpansions($info['expansions_path'], $pkgName, $info['version']);
                    }
                }
                $this->seedSupplementalDemographics($igPackagesToAdd);
            }

            // Auto-expand capped ValueSets <= 5,000 for newly added packages
            $this->autoExpandCappedValuesets($toAdd);

            // RxNorm
            if (in_array('RxNorm', $toAdd, true)) {
                $this->ingestRxNorm();
            }
        }

        // 3. Rebuild FTS and vacuum if any changes occurred
        if (!empty($toDrop) || !empty($toAdd)) {
            $this->rebuildFtsAndOptimize();
        }

        $this->db = null;

        if (!$this->keepCache) {
            $this->removeDirectory($this->cacheDir);
        }

        $elapsed = round(microtime(true) - $startTime, 2);
        $stats = self::getDbStats($this->outputPath);

        return [
            'success' => true,
            'valueset_count' => $stats['valueset_count'] ?? 0,
            'concept_count' => $stats['concept_count'] ?? 0,
            'elapsed_seconds' => $elapsed,
            'added' => $toAdd,
            'removed' => $toDrop,
            'packages' => $stats['packages'] ?? []
        ];
    }

    private function ingestRxNorm(): int
    {
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) REDCap/1.0\r\nAccept: application/json\r\n",
                'timeout' => 60
            ]
        ];
        $ctx = stream_context_create($opts);
        $url = 'https://rxnav.nlm.nih.gov/REST/allconcepts.json?tty=IN';
        $raw = @file_get_contents($url, false, $ctx);
        if (!$raw) {
            return 0;
        }
        $data = json_decode($raw, true);
        $list = $data['minConceptGroup']['minConcept'] ?? [];
        if (empty($list)) {
            return 0;
        }

        $vsStmt = $this->db->prepare("
            INSERT OR REPLACE INTO valuesets (id, package, url, urn, name, title, description, version, status)
            VALUES (:id, :pkg, :url, :urn, :name, :title, :description, :version, :status)
        ");
        $vsStmt->execute([
            ':id' => 'rxnorm-medications',
            ':pkg' => 'RxNorm',
            ':url' => 'http://www.nlm.nih.gov/research/umls/rxnorm',
            ':urn' => 'urn:oid:2.16.840.1.113883.6.88',
            ':name' => 'RxNormActiveIngredients',
            ':title' => 'RxNorm Active Ingredients',
            ':description' => 'All ~14,710 normalized generic active drug ingredients and RxCUIs from the National Library of Medicine (RxNorm tty=IN). Covers generic medication history and prescribing without 400,000+ brand/dosage/packaging permutations.',
            ':version' => 'Current',
            ':status' => 'active'
        ]);

        $this->db->exec("DELETE FROM concepts WHERE valueset_id = 'rxnorm-medications' AND package = 'RxNorm'");

        $cStmt = $this->db->prepare("
            INSERT INTO concepts (valueset_id, package, system, code, display, definition, sort_order)
            VALUES ('rxnorm-medications', 'RxNorm', 'http://www.nlm.nih.gov/research/umls/rxnorm', :code, :display, '', :sort_order)
        ");

        $this->db->beginTransaction();
        $order = 0;
        foreach ($list as $c) {
            $code = trim((string)($c['rxcui'] ?? ''));
            $name = trim((string)($c['name'] ?? ''));
            if ($code === '' || $name === '') continue;
            $display = ucwords(strtolower($name));
            $cStmt->execute([
                ':code' => $code,
                ':display' => $display,
                ':sort_order' => $order++
            ]);
        }
        $this->db->commit();
        return $order;
    }

    private function rebuildFtsAndOptimize(): void
    {
        // 1. Materialize concept_count on valuesets table
        $this->db->exec("
            UPDATE valuesets 
            SET concept_count = (
                SELECT COUNT(*) 
                FROM concepts c 
                WHERE c.valueset_id = valuesets.id AND c.package = valuesets.package
            );
        ");

        // 2. Populate valuesets_fts
        $this->db->exec("DELETE FROM valuesets_fts;");
        $this->db->exec("
            INSERT INTO valuesets_fts (id, title, name, description, url, urn, package)
            SELECT id, title, name, description, url, urn, package FROM valuesets;
        ");

        // 3. Populate concepts_fts
        $this->db->exec("DELETE FROM concepts_fts;");
        $this->db->exec("
            INSERT INTO concepts_fts (code, display, valueset_id, package)
            SELECT code, display, valueset_id, package FROM concepts;
        ");

        $this->db->exec("PRAGMA optimize;");
        $this->db->exec("VACUUM;");
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
