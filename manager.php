<?php

declare(strict_types=1);

namespace UWMadison\ClinicalTerminologyMapper;

use PDO;
use Throwable;

/** @var ClinicalTerminologyMapper $module */

require_once __DIR__ . '/TerminologyBuilderService.php';
require_once __DIR__ . '/ValueSetSizeCatalog.php';
$dbPath = __DIR__ . '/fhir_valuesets.sqlite';

$stats = TerminologyBuilderService::getDbStats($dbPath);
$isValid = ($stats !== null);
$allPackages = TerminologyBuilderService::PACKAGE_METADATA;

$truncatedValueSets = [];
if ($isValid) {
    try {
        $db = new PDO('sqlite:' . $dbPath);

        $catalogCandidates = ValueSetSizeCatalog::getAllEntries();
        $candidateIds = array_unique(array_column($catalogCandidates, 'id'));

        $query = "SELECT id, title, package, version, concept_count, url, description FROM valuesets WHERE concept_count >= 1000";
        if (!empty($candidateIds)) {
            $inList = "'" . implode("','", array_map(fn($id) => addslashes($id), array_unique($candidateIds))) . "'";
            $query .= " OR id IN ({$inList})";
        }
        $query .= " ORDER BY title ASC";
        $stmt = $db->query($query);
        $rawTruncated = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $loincAliases = ['consent-content-code', 'questionnaire-questions', 'c80-doc-typecodes', 'doc-typecodes', 'doc-section-codes', 'report-codes', 'observation-codes'];
        $snomedAliases = [
            'condition-code', 'condition-outcome', 'encounter-reason', 'flag-code',
            'manifestation-or-symptom', 'procedure-reason', 'allergyintolerance-code',
            'clinical-findings', 'body-site', 'adverse-event-type', 'procedure-code',
            'device-type', 'device-kind', 'observation-methods', 'medication-as-needed-reason',
            'medication-codes', 'bodystructure-code', 'supply-item', 'substance-code',
            'approach-site-codes'
        ];

        foreach ($rawTruncated as $row) {
            $count = (int)$row['concept_count'];
            $cachedTotalVal = null;

            // Strict versioned catalog lookup
            $version = $row['version'] ?? null;
            $catalogEntry = ValueSetSizeCatalog::lookup($row['id'], $row['package'], $version);
            if ($catalogEntry !== null) {
                $cachedTotalVal = $catalogEntry['total'];
                $row['catalog_entry'] = $catalogEntry;
            }

            // Strictly exclude any small sets (< 1,000 concepts) - Capped ValueSets section is ONLY for large/capped sets
            if (is_numeric($cachedTotalVal) && (int)$cachedTotalVal < 1000) {
                continue;
            }
            if ($count < 1000 && $cachedTotalVal === null) {
                continue;
            }

            // Exclude sets that already have all their concepts in the database (fully expanded/loaded initially)
            if (is_numeric($cachedTotalVal) && $count > 0 && $count >= (int)$cachedTotalVal) {
                continue;
            }

            // Exclude sets that have no catalog entry but have concepts loaded (not capped)
            if ($catalogEntry === null && $count > 0) {
                continue;
            }

            // Also exclude sets with 0 concepts unless they have a known remote total or catalog entry
            if ($count === 0 && $cachedTotalVal === null && $catalogEntry === null) {
                continue;
            }

            $idLower = strtolower($row['id']);
            $titleLower = strtolower($row['title']);
            $urlLower = strtolower($row['url'] ?? '');
            $descLower = strtolower($row['description'] ?? '');

            $catSourceLower = strtolower($catalogEntry['source'] ?? '');

            $infoSystem = null;
            if (str_contains($catSourceLower, 'snomed') 
                || in_array($row['id'], $snomedAliases, true) 
                || str_contains($urlLower, 'snomed') 
                || str_contains($idLower, 'snomed') 
                || str_contains($titleLower, 'snomed')) {
                $infoSystem = 'SNOMED CT';
            } elseif (str_contains($catSourceLower, 'loinc') 
                || in_array($row['id'], $loincAliases, true) 
                || str_contains($urlLower, 'loinc') 
                || str_contains($idLower, 'loinc') 
                || str_contains($titleLower, 'loinc') 
                || (is_numeric($cachedTotalVal) && (int)$cachedTotalVal >= 250000)) {
                $infoSystem = 'LOINC';
            } elseif (str_contains($catSourceLower, 'rxnorm') 
                || str_contains($urlLower, 'rxnorm') 
                || str_contains($idLower, 'rxnorm') 
                || str_contains($titleLower, 'rxnorm') 
                || str_contains($descLower, 'rxnorm') 
                || $row['id'] === 'us-core-medication-codes') {
                $infoSystem = 'RxNorm';
            } elseif (str_contains($catSourceLower, '639') 
                || $row['id'] === 'all-languages' 
                || $row['id'] === 'languages') {
                $infoSystem = 'BCP 47';
            }

            $row['info_system'] = $infoSystem;
            $row['cached_total'] = $cachedTotalVal;

            $isOver20k = false;
            if (is_numeric($cachedTotalVal) && (int)$cachedTotalVal > 20000) {
                $isOver20k = true;
            } elseif ($count > 20000) {
                $isOver20k = true;
            } elseif (is_string($cachedTotalVal) && (str_contains($cachedTotalVal, 'grammar') || str_contains($cachedTotalVal, 'Infinite') || str_contains($cachedTotalVal, 'too many') || str_contains($cachedTotalVal, '>'))) {
                $isOver20k = true;
            } elseif ($catalogEntry !== null && isset($catalogEntry['can_expand']) && !$catalogEntry['can_expand']) {
                $isOver20k = true;
            }
            $row['is_over_20k'] = $isOver20k;
            $truncatedValueSets[] = $row;
        }
    } catch (Throwable $e) {
        $truncatedValueSets = [];
    }
}

$loincStats = null;
if ($isValid) {
    try {
        $db = new PDO('sqlite:' . $dbPath);
        $loincCount = (int)$db->query("SELECT count(*) FROM concepts WHERE package = 'LOINC'")->fetchColumn();
        if ($loincCount > 0) {
            $loincStats = [
                'concepts' => $loincCount,
                'valuesets' => (int)$db->query("SELECT count(*) FROM valuesets WHERE package = 'LOINC'")->fetchColumn()
            ];
        }
    } catch (Throwable $e) {}
}

$module->initializeJavascriptModuleObject();
?>

<link rel="stylesheet" href="<?= htmlspecialchars($module->getUrl('style.css'), ENT_QUOTES) ?>">
<div style="max-width: 950px; margin: 10px 0 30px 0;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
        <div>
            <h4 style="margin: 0; color: #1e3a8a; display: flex; align-items: center; gap: 10px; font-size: 1.25rem; white-space: nowrap;">
                <i class="fas fa-database" style="color: #2563eb;"></i>
                Clinical Terminology Mapper: Database Manager
            </h4>
            <div style="color: #64748b; font-size: 0.85rem; margin-top: 4px;">
                Manage and compile the SQLite terminology store powering HL7 FHIR & LOINC multiple-choice option mapping.
            </div>
        </div>
    </div>

    <!-- Status Card -->
    <div class="card" style="border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 24px;">
        <div class="card-header" style="background: #f8fafc; border-bottom: 1px solid #cbd5e1; padding: 12px 18px; font-weight: 600; color: #1e293b; display: flex; align-items: center; justify-content: space-between;">
            <span><i class="fas fa-server" style="color: #64748b; margin-right: 6px;"></i> Terminology Database Status</span>
            <?php if ($isValid): ?>
                <span class="badge badge-primary" style="font-size: 0.85rem; padding: 5px 10px; background-color: #2563eb; color: #fff;">
                    <i class="fas fa-check-circle"></i> Initialized & Ready
                </span>
            <?php else: ?>
                <span class="badge badge-danger" style="font-size: 0.85rem; padding: 5px 10px; background-color: #ef4444;">
                    <i class="fas fa-exclamation-triangle"></i> Not Initialized
                </span>
            <?php endif; ?>
        </div>
        <div class="card-body" style="padding: 20px;">
            <?php if ($isValid): ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px;">
                    <div style="background: #f1f5f9; padding: 14px; border-radius: 6px;">
                        <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; font-weight: 700;">ValueSets</div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: #0f172a; margin-top: 4px;"><?= number_format($stats['valueset_count']) ?></div>
                    </div>
                    <div style="background: #f1f5f9; padding: 14px; border-radius: 6px;">
                        <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; font-weight: 700;">Discrete Concepts</div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: #0f172a; margin-top: 4px;"><?= number_format($stats['concept_count']) ?></div>
                    </div>
                    <div style="background: #f1f5f9; padding: 14px; border-radius: 6px;">
                        <div style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; font-weight: 700;">Database Size</div>
                        <div style="font-size: 1.5rem; font-weight: 700; color: #0f172a; margin-top: 4px;"><?= $stats['file_size_mb'] ?> MB</div>
                    </div>
                </div>

                <div style="margin-bottom: 12px; display: flex; align-items: center; justify-content: flex-end;">
                    <span style="font-size: 0.8rem; color: #64748b;">
                        Select packages to include in the database. Deselecting an installed package will remove it; selecting a new package will download and compile it.
                    </span>
                </div>

                <div class="pkg-table-container">
                    <table class="pkg-table">
                    <thead>
                        <tr>
                            <th style="width: 40px; text-align: center;">
                                <input type="checkbox" id="check-all-packages" title="Select / Deselect All" style="width: 17px; height: 17px; cursor: pointer; accent-color: #2563eb; vertical-align: middle;">
                            </th>
                            <th>Package & Description</th>
                            <th style="text-align: right; width: 140px;">ValueSets</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allPackages as $pkgName => $pkgMeta): 
                            $cnt = $stats['packages'][$pkgName] ?? null;
                            $isIngested = ($cnt !== null && (int)$cnt > 0);
                            $vsTotal = (int)($pkgMeta['valueset_count'] ?? 0);
                        ?>
                            <tr>
                                <td style="text-align: center; vertical-align: middle;">
                                    <?php if (!empty($pkgMeta['external'])): ?>
                                        <span style="color: #94a3b8; font-size: 0.85rem;" title="External package: managed via dedicated section below">&mdash;</span>
                                    <?php else: ?>
                                        <input type="checkbox" class="pkg-checkbox" 
                                            value="<?= htmlspecialchars((string)$pkgName, ENT_QUOTES) ?>" 
                                            data-name="<?= htmlspecialchars((string)$pkgMeta['name'], ENT_QUOTES) ?>" 
                                            data-count="<?= $vsTotal ?>" 
                                            data-installed="<?= $isIngested ? '1' : '0' ?>" 
                                            <?= ($isIngested ? 'checked' : '') ?> 
                                            style="width: 17px; height: 17px; cursor: pointer; accent-color: #2563eb; vertical-align: middle;">
                                    <?php endif; ?>
                                </td>
                                <td style="color: #1e293b;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <i class="fas fa-archive" style="color: #94a3b8; font-size: 0.85rem; transform: translateY(1.5px);"></i>
                                        <strong><?= htmlspecialchars((string)$pkgMeta['name'], ENT_QUOTES) ?></strong>
                                        <?php if ($isIngested): ?>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; background: #dbeafe; color: #1d4ed8; font-size: 0.72rem; font-weight: 600; padding: 1px 7px; border-radius: 9999px;">
                                                <i class="fas fa-check" style="font-size: 0.65rem;"></i> Installed
                                            </span>
                                        <?php else: ?>
                                            <span style="display: inline-flex; align-items: center; gap: 4px; background: #f1f5f9; color: #64748b; font-size: 0.72rem; font-weight: 500; padding: 1px 7px; border-radius: 9999px;">
                                                Not Installed
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 0.8rem; color: #64748b; margin-top: 3px; padding-left: 22px;">
                                        <?= htmlspecialchars((string)$pkgMeta['desc'], ENT_QUOTES) ?>
                                        <?php if ($pkgName === 'RxNorm'): ?>
                                            <div style="font-size: 0.74rem; color: #0284c7; margin-top: 3px; display: flex; align-items: center; gap: 5px;">
                                                <i class="fas fa-pills" style="font-size: 0.72rem;"></i>
                                                <span>~14,710 normalized generic substances &bull; Ideal for medication history & prescription matching</span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($pkgMeta['external'])): ?>
                                            <div style="font-size: 0.75rem; color: #6366f1; margin-top: 2px;">
                                                <i class="fas fa-arrow-circle-down"></i> Managed via the LOINC account panel below
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="text-align: right; vertical-align: middle; color: #334155;">
                                    <?php if ($isIngested): ?>
                                        <span style="display: inline-block; background: #e0f2fe; color: #0369a1; font-weight: 600; font-size: 0.8rem; padding: 3px 8px; border-radius: 4px; border: 1px solid #bae6fd;" title="Compiled and active in database">
                                            <?= number_format((int)$cnt) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="display: inline-block; background: #f8fafc; color: #64748b; font-weight: 500; font-size: 0.8rem; padding: 3px 8px; border-radius: 4px; border: 1px solid #e2e8f0;" title="Available in package (not installed)">
                                            <?= number_format($vsTotal) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="alert alert-warning" style="margin-bottom: 20px;">
                    <h5 style="margin-top: 0;"><i class="fas fa-exclamation-triangle"></i> Database Required</h5>
                    <p style="margin-bottom: 0;">
                        The SQLite database file (<code>fhir_valuesets.sqlite</code>) has not been compiled yet.
                        Project users will see an administrative action required message until the database is built.
                    </p>
                </div>

                <div style="margin-bottom: 12px; display: flex; align-items: center; justify-content: flex-end;">
                    <span style="font-size: 0.8rem; color: #64748b;">
                        Select packages to compile into the database. Checked packages will be available to projects.
                    </span>
                </div>

                <div class="pkg-table-container">
                    <table class="pkg-table">
                    <thead>
                        <tr>
                            <th style="width: 40px; text-align: center;">
                                <input type="checkbox" id="check-all-packages" title="Select / Deselect All" style="width: 17px; height: 17px; cursor: pointer; accent-color: #2563eb; vertical-align: middle;">
                            </th>
                            <th>Package & Description</th>
                            <th style="text-align: right; width: 140px;">ValueSets</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $initialDefaults = [
                            'FHIR R4',
                            'US Core v3.1.1'
                        ];
                        foreach ($allPackages as $pkgName => $pkgMeta): 
                            $isChecked = in_array($pkgName, $initialDefaults, true);
                            $vsTotal = (int)($pkgMeta['valueset_count'] ?? 0);
                        ?>
                            <tr>
                                <td style="text-align: center; vertical-align: middle;">
                                    <?php if (!empty($pkgMeta['external'])): ?>
                                        <span style="color: #94a3b8; font-size: 0.85rem;" title="External package: managed via dedicated section below">&mdash;</span>
                                    <?php else: ?>
                                        <input type="checkbox" class="pkg-checkbox" 
                                            value="<?= htmlspecialchars((string)$pkgName, ENT_QUOTES) ?>" 
                                            data-name="<?= htmlspecialchars((string)$pkgMeta['name'], ENT_QUOTES) ?>" 
                                            data-count="<?= $vsTotal ?>" 
                                            data-installed="0" 
                                            <?= ($isChecked ? 'checked' : '') ?> 
                                            style="width: 17px; height: 17px; cursor: pointer; accent-color: #2563eb; vertical-align: middle;">
                                    <?php endif; ?>
                                </td>
                                <td style="color: #1e293b;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <i class="fas fa-archive" style="color: #94a3b8; font-size: 0.85rem; transform: translateY(1.5px);"></i>
                                        <strong><?= htmlspecialchars((string)$pkgMeta['name'], ENT_QUOTES) ?></strong>
                                    </div>
                                    <div style="font-size: 0.8rem; color: #64748b; margin-top: 3px; padding-left: 22px;">
                                        <?= htmlspecialchars((string)$pkgMeta['desc'], ENT_QUOTES) ?>
                                        <?php if ($pkgName === 'RxNorm'): ?>
                                            <div style="font-size: 0.74rem; color: #0284c7; margin-top: 3px; display: flex; align-items: center; gap: 5px;">
                                                <i class="fas fa-pills" style="font-size: 0.72rem;"></i>
                                                <span>~14,710 normalized generic substances &bull; Ideal for medication history & prescription matching</span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($pkgMeta['external'])): ?>
                                            <div style="font-size: 0.75rem; color: #6366f1; margin-top: 2px;">
                                                <i class="fas fa-arrow-circle-down"></i> Managed via the LOINC account panel below
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="text-align: right; vertical-align: middle; color: #334155;">
                                    <span style="display: inline-block; background: #f8fafc; color: #64748b; font-weight: 500; font-size: 0.8rem; padding: 3px 8px; border-radius: 4px; border: 1px solid #e2e8f0;" title="Available in package">
                                        <?= number_format($vsTotal) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div style="display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap;">
                <div>
                    <?php if ($isValid): ?>
                        <span style="display: inline-flex; align-items: center; gap: 6px; background: #f1f5f9; color: #475569; font-size: 0.8rem; font-weight: 500; padding: 5px 12px; border-radius: 9999px; border: 1px solid #e2e8f0; line-height: 1;">
                            <i class="far fa-clock" style="color: #64748b; font-size: 0.8rem; transform: translateY(0.5px);"></i>
                            <span>Compiled: <strong><?= $stats['last_modified'] ?></strong></span>
                        </span>
                    <?php endif; ?>
                </div>
                <div style="display: flex; gap: 12px; align-items: center;">
                    <span id="build-status-text" style="color: #64748b; font-size: 0.85rem;"></span>
                    <button id="btn-build-db" class="btn btn-primary" data-db-valid="<?= $isValid ? '1' : '0' ?>" style="padding: 8px 18px; font-weight: 600;">
                        <i class="fas fa-sync-alt" id="build-spinner" style="display: none; margin-right: 4px;"></i>
                        <i class="fas <?= $isValid ? 'fa-sync-alt' : 'fa-database' ?>" id="build-icon" style="margin-right: 4px;"></i>
                        <?= $isValid ? 'Update Database' : 'Build Database' ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- LOINC Common Labs Pull Card -->
    <div class="card" style="border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 24px;">
        <div class="card-header" style="background: #f8fafc; border-bottom: 1px solid #cbd5e1; padding: 12px 18px; font-weight: 600; color: #1e293b; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-vial" style="color: #0284c7;"></i>
                <span>LOINC Common Lab Observations (Top ~2,000 Subset)</span>
            </div>
            <div id="loinc-badge-container">
                <?php if ($loincStats): ?>
                    <span class="badge" style="background: #e0f2fe; color: #0369a1; font-size: 0.8rem; padding: 4px 10px; border-radius: 9999px; border: 1px solid #bae6fd;">
                        <i class="fas fa-check-circle"></i> Loaded (<?= number_format($loincStats['concepts']) ?> concepts)
                    </span>
                <?php else: ?>
                    <span class="badge" style="background: #f1f5f9; color: #64748b; font-size: 0.8rem; padding: 4px 10px; border-radius: 9999px; border: 1px solid #e2e8f0;">
                        Not Loaded
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body" style="padding: 20px;">
            <div class="alert alert-info" style="font-size: 0.85rem; background-color: #f0f9ff; border-color: #bae6fd; color: #0369a1; margin-bottom: 16px;">
                <div style="font-weight: 600; margin-bottom: 4px;">
                    <i class="fas fa-info-circle"></i> Why are LOINC credentials required?
                </div>
                Regenstrief Institute holds the copyright to LOINC. While LOINC is free for clinical and research use, Regenstrief requires users to accept their terms of use by registering an account. You can register for a free account at <a href="https://loinc.org/join/" target="_blank" style="font-weight: 600; text-decoration: underline; color: #0284c7;">loinc.org/join <i class="fas fa-external-link-alt" style="font-size: 0.75rem;"></i></a>.
            </div>

            <p style="color: #475569; font-size: 0.85rem; margin-bottom: 16px;">
                Out of 250,000+ total LOINC codes, Regenstrief's empirical usage research confirms that approximately <strong>2,000 codes account for ~99% of all routine clinical lab orders and results</strong> in US health systems. Pulling this subset enables sub-millisecond lab auto-matching in clinical workflows without bloating database storage.
            </p>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; margin-bottom: 16px;">
                <div>
                    <label style="font-size: 0.8rem; font-weight: 600; color: #334155; margin-bottom: 4px; display: block;">LOINC Username / Email</label>
                    <input type="text" id="loinc-username" class="form-control form-control-sm" placeholder="user@institution.edu" style="font-size: 0.85rem;">
                </div>
                <div>
                    <label style="font-size: 0.8rem; font-weight: 600; color: #334155; margin-bottom: 4px; display: block;">LOINC Password</label>
                    <input type="password" id="loinc-password" class="form-control form-control-sm" placeholder="••••••••••••" style="font-size: 0.85rem;">
                </div>
            </div>

            <div style="display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap;">
                <div id="loinc-status-msg" style="font-size: 0.85rem; color: #64748b;"></div>
                <div style="display: flex; gap: 10px;">
                    <?php if ($loincStats): ?>
                        <button type="button" id="btn-purge-loinc" class="btn btn-sm btn-outline-danger" style="font-weight: 600;">
                            <i class="fas fa-trash-alt" style="margin-right: 4px;"></i> Remove LOINC
                        </button>
                    <?php endif; ?>
                    <button type="button" id="btn-sync-loinc" class="btn btn-sm btn-primary" style="font-weight: 600; padding: 6px 16px;">
                        <i class="fas fa-cloud-download-alt" id="loinc-sync-icon" style="margin-right: 4px;"></i>
                        <i class="fas fa-spinner fa-spin" id="loinc-sync-spinner" style="display: none; margin-right: 4px;"></i>
                        Pull LOINC
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Truncated ValueSets Section -->
    <?php if ($isValid && !empty($truncatedValueSets)): ?>
        <div class="card" style="border: 1px solid #cbd5e1; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 24px;">
            <div class="card-header" style="background: #f8fafc; border-bottom: 1px solid #cbd5e1; padding: 12px 18px; font-weight: 600; color: #1e293b; display: flex; align-items: center; justify-content: space-between;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-exclamation-circle" style="color: #f59e0b;"></i>
                    <span>Capped ValueSets</span>
                    <span class="badge" style="background: #fef3c7; color: #92400e; font-size: 0.75rem; padding: 2px 8px; border-radius: 9999px; border: 1px solid #fde68a;">
                        <?= count($truncatedValueSets) ?> Capped ValueSets
                    </span>
                </div>
                <button type="button" id="btn-toggle-truncated" class="btn btn-sm btn-outline-secondary" style="font-size: 0.78rem; padding: 3px 10px;">
                    <i class="fas fa-chevron-down" id="toggle-trunc-icon" style="margin-right: 4px;"></i>
                    <span id="toggle-trunc-text">Show ValueSets</span>
                </button>
            </div>
            <div id="truncated-section-body" class="card-body" style="padding: 0; display: none;">
                <div style="max-height: 380px; overflow-y: auto;">
                    <table class="trunc-table">
                        <thead>
                            <tr>
                                <th>ValueSet Title & Identifier</th>
                                <th style="width: 150px;">Package</th>
                                <th style="width: 220px;">Concepts</th>
                                <th style="text-align: center; width: 90px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($truncatedValueSets as $tvs): 
                                $cachedTot = $tvs['cached_total'];
                            ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars((string)$tvs['title'], ENT_QUOTES) ?></strong>
                                        <div class="vs-meta-container" style="font-size: 0.75rem; color: #64748b; font-family: monospace; display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 2px;">
                                            <span><?= htmlspecialchars((string)$tvs['id'], ENT_QUOTES) ?></span>
                                            <?php if (!empty($tvs['info_system']) && $tvs['info_system'] !== 'UCUM'): 
                                                $pillColors = [
                                                    'SNOMED CT' => 'background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0;',
                                                    'LOINC'     => 'background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe;',
                                                    'RxNorm'    => 'background: #faf5ff; color: #6b21a8; border: 1px solid #e9d5ff;',
                                                    'BCP 47'    => 'background: #fffbeb; color: #92400e; border: 1px solid #fde68a;'
                                                ];
                                                $pillStyle = $pillColors[$tvs['info_system']] ?? 'background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1;';
                                            ?>
                                                <span class="info-system-badge" style="font-family: sans-serif; display: inline-flex; align-items: center; font-weight: 600; font-size: 0.7rem; padding: 1px 7px; border-radius: 9999px; white-space: nowrap; flex-shrink: 0; line-height: 1.25; <?= $pillStyle ?>">
                                                    <?= htmlspecialchars((string)$tvs['info_system'], ENT_QUOTES) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td style="color: #475569; font-size: 0.8rem; vertical-align: middle;">
                                        <div style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars((string)$tvs['package'], ENT_QUOTES) ?></div>
                                    </td>
                                    <td style="vertical-align: middle;">
                                        <?php if ((int)$tvs['concept_count'] > 0): ?>
                                            <div style="margin-bottom: 2px;">
                                                <span style="font-weight: 600; color: #334155;"><?= number_format((int)$tvs['concept_count']) ?></span>
                                                <span style="color: #64748b; font-size: 0.75rem;">in database</span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($tvs['catalog_entry'])): ?>
                                            <div style="font-size: 0.8rem; color: #0369a1; font-weight: 600;" title="<?= htmlspecialchars((string)$tvs['catalog_entry']['notes'], ENT_QUOTES) ?>">
                                                <?= is_numeric($tvs['catalog_entry']['total']) ? number_format((int)$tvs['catalog_entry']['total']) : htmlspecialchars((string)$tvs['catalog_entry']['total'], ENT_QUOTES) ?>
                                            </div>
                                            <div style="font-size: 0.7rem; color: #64748b; margin-top: 1px;" title="<?= htmlspecialchars((string)$tvs['catalog_entry']['notes'], ENT_QUOTES) ?>">
                                                <?= htmlspecialchars((string)$tvs['catalog_entry']['source'], ENT_QUOTES) ?>
                                            </div>
                                        <?php elseif ($cachedTot !== null): ?>
                                            <div style="font-size: 0.8rem; color: #0369a1; font-weight: 600;">
                                                <?= is_numeric($cachedTot) ? number_format((int)$cachedTot) : htmlspecialchars((string)$cachedTot, ENT_QUOTES) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="action-col" style="text-align: center; vertical-align: middle;">
                                        <?php 
                                            $canExpand = !empty($tvs['catalog_entry']['can_expand']);
                                            if (!empty($tvs['catalog_entry'])) {
                                                $tot = $tvs['catalog_entry']['total'] ?? null;
                                                if (!is_numeric($tot) || (int)$tot > 3000) {
                                                    $canExpand = false;
                                                }
                                            } else {
                                                $canExpand = false;
                                            }
                                        ?>
                                        <?php if ($canExpand): ?>
                                            <button type="button" class="btn btn-xs btn-outline-primary btn-expand-vs"
                                                    data-id="<?= htmlspecialchars((string)$tvs['id'], ENT_QUOTES) ?>"
                                                    data-package="<?= htmlspecialchars((string)$tvs['package'], ENT_QUOTES) ?>"
                                                    data-title="<?= htmlspecialchars((string)$tvs['title'], ENT_QUOTES) ?>"
                                                    data-system="<?= htmlspecialchars((string)($tvs['info_system'] ?? ''), ENT_QUOTES) ?>"
                                                    data-count="<?= htmlspecialchars((string)$tvs['concept_count'], ENT_QUOTES) ?>"
                                                    data-total="<?= htmlspecialchars((string)($cachedTot ?? ''), ENT_QUOTES) ?>"
                                                    data-source="<?= htmlspecialchars((string)($tvs['catalog_entry']['source'] ?? ''), ENT_QUOTES) ?>"
                                                    data-notes="<?= htmlspecialchars((string)($tvs['catalog_entry']['notes'] ?? ''), ENT_QUOTES) ?>"
                                                    data-large="0"
                                                    title="Click to review and expand from tx.fhir.org"
                                                    style="font-size: 0.72rem; padding: 2px 8px; font-weight: 600; white-space: nowrap;">
                                                <i class="fas fa-expand-arrows-alt"></i> Expand
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-xs btn-outline-secondary"
                                                    disabled
                                                    title="Expansion unavailable: ValueSet exceeds external terminology server limits (>3,000 concepts) or is an unconstrained ontology."
                                                    style="font-size: 0.72rem; padding: 2px 8px; font-weight: 600; white-space: nowrap; opacity: 0.65; cursor: not-allowed;">
                                                <i class="fas fa-lock" style="margin-right: 3px;"></i> Locked
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- CLI Instructions -->
    <div class="card" style="border: 1px solid #cbd5e1; border-radius: 8px;">
        <div class="card-header" style="background: #f8fafc; border-bottom: 1px solid #cbd5e1; padding: 12px 18px; font-weight: 600; color: #1e293b;">
            <i class="fas fa-terminal" style="color: #64748b; margin-right: 6px;"></i> Command Line Interface (CLI) Alternative
        </div>
        <div class="card-body" style="padding: 20px;">
            <p style="color: #475569; font-size: 0.875rem; margin-bottom: 10px;">
                If your web server has strict timeout limits (e.g. 30-second PHP limits or reverse proxy timeouts), you can build or refresh the database directly from the terminal. <strong>To review the available package flags and compile only what you need, run the CLI help command:</strong>
            </p>
            <pre style="background: #0f172a; color: #38bdf8; padding: 14px; border-radius: 6px; font-family: monospace; font-size: 0.85rem; margin: 0; overflow-x: auto;">php <?= htmlspecialchars(__DIR__ . '/build_terminology.php', ENT_QUOTES) ?> --help</pre>
            <p style="color: #64748b; font-size: 0.8rem; margin-top: 8px; margin-bottom: 0;">
                The CLI requires you to specify which packages to compile (e.g. <code>--r4</code>, <code>--us-core-311</code>, <code>--all</code>) so you only install the standards your projects require.
            </p>
        </div>
    </div>

    <!-- Expand ValueSet Warning Modal -->
    <div class="modal fade" id="expand-warning-modal" tabindex="-1" role="dialog" aria-labelledby="expandModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document" style="max-width: 580px;">
            <div class="modal-content" style="border-radius: 8px; border: 1px solid #cbd5e1; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);">
                <div class="modal-header" style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; border-top-left-radius: 8px; border-top-right-radius: 8px; padding: 14px 18px; display: flex; align-items: center; justify-content: space-between;">
                    <h5 class="modal-title" id="expandModalLabel" style="font-size: 1rem; font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 8px; margin: 0;">
                        <i id="expand-modal-header-icon" class="fas fa-expand-arrows-alt text-primary"></i>
                        <span>Expand ValueSet: <span id="expand-modal-title" style="font-weight: 700;"></span></span>
                    </h5>
                    <button type="button" class="btn-close close modal-close-trigger" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close" style="font-size: 1.15rem; color: #64748b; opacity: 0.8; border: none; background: transparent; cursor: pointer; padding: 4px;">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" style="padding: 20px;">
                    <!-- ValueSet Details Card -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 16px; margin-bottom: 16px; font-size: 0.85rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 600;">Identifier</span>
                            <code id="expand-modal-id" style="font-weight: 600; color: #0f172a; font-size: 0.8rem;"></code>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 600;">Package</span>
                            <span id="expand-modal-pkg-system" style="font-weight: 500; color: #334155;"></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 600;">Currently In Database</span>
                            <span id="expand-modal-current" style="font-weight: 600; color: #334155;"></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 600;">Expected Total</span>
                            <span id="expand-modal-total" style="font-weight: 700; color: #0369a1;"></span>
                        </div>
                        <div id="expand-modal-notes-container" style="display: none; margin-top: 8px; padding-top: 8px; border-top: 1px dashed #cbd5e1; font-size: 0.78rem; color: #64748b;">
                            <i class="fas fa-info-circle" style="color: #0ea5e9; margin-right: 4px;"></i>
                            <span id="expand-modal-notes"></span>
                        </div>
                    </div>

                    <!-- Normal Warning Alert (for <= 20,000 concepts) -->
                    <div id="expand-modal-normal-alert" class="alert alert-warning" style="display: none; font-size: 0.85rem; margin-bottom: 16px; background-color: #fffbeb; border-color: #fde68a; color: #92400e; border-radius: 6px; padding: 12px 16px;">
                        <div style="font-weight: 700; margin-bottom: 6px; font-size: 0.88rem; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-exclamation-triangle" style="color: #d97706;"></i> Performance Notice
                        </div>
                        <p style="margin-bottom: 6px; line-height: 1.45;">
                            In REDCap, multiple-choice fields render every concept as an HTML dropdown <code>&lt;option&gt;</code> or radio button. ValueSets with several thousand concepts may increase form load times and browser memory usage.
                        </p>
                        <p style="margin-bottom: 0; line-height: 1.45;">
                            Expanding this ValueSet will contact the HL7 FHIR Terminology Service (<code>tx.fhir.org</code>) and compile all available concepts into your local database.
                        </p>
                    </div>

                    <!-- Red Danger Alert (for > 20,000 concepts) -->
                    <div id="expand-modal-danger-alert" class="alert alert-danger" style="display: none; font-size: 0.85rem; margin-bottom: 16px; background-color: #fef2f2; border-color: #fecaca; color: #991b1b; border-radius: 6px; padding: 14px 18px;">
                        <div style="font-weight: 700; margin-bottom: 6px; font-size: 0.9rem; display: flex; align-items: center; gap: 8px; color: #b91c1c;">
                            <i class="fas fa-exclamation-circle" style="color: #dc2626; font-size: 1rem;"></i> Performance Notice: Large ValueSet
                        </div>
                        <div style="font-weight: 600; font-size: 0.88rem; margin-bottom: 8px; color: #7f1d1d;">
                            Note: Using this valueset will, at best, cause minor performance issues.
                        </div>
                        <p style="margin-bottom: 8px; line-height: 1.45;">
                            This ValueSet contains over 20,000 concepts (or an unconstrained terminology system). Rendering large choice lists in REDCap forms or surveys can increase page load times and browser memory usage for data entry users.
                        </p>
                        <p style="margin-bottom: 0; line-height: 1.45;">
                            Expansion from the FHIR terminology server may take several minutes to download and process. Proceed if your project requires this complete dictionary.
                        </p>
                    </div>

                    <!-- Progress / Status Spinner -->
                    <div id="expand-modal-progress" style="display: none; padding: 16px; border-radius: 6px; background: #f8fafc; border: 1px solid #e2e8f0; text-align: center; margin-bottom: 12px;">
                        <div style="color: #2563eb; font-size: 1.5rem; margin-bottom: 8px;">
                            <i class="fas fa-spinner fa-spin"></i>
                        </div>
                        <div style="font-weight: 600; color: #1e293b; font-size: 0.9rem;" id="expand-modal-progress-title">Expanding ValueSet...</div>
                        <div style="font-size: 0.8rem; color: #64748b; margin-top: 4px;" id="expand-modal-progress-sub">Connecting to external FHIR terminology server (tx.fhir.org). This may take up to several minutes...</div>
                    </div>

                    <!-- Error Message -->
                    <div id="expand-modal-error" class="alert alert-danger" style="display: none; font-size: 0.85rem; margin-bottom: 0;"></div>

                    <!-- Success Message -->
                    <div id="expand-modal-success" class="alert alert-success" style="display: none; font-size: 0.85rem; margin-bottom: 0;"></div>
                </div>
                <div class="modal-footer" style="background: #f8fafc; border-top: 1px solid #e2e8f0; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px; padding: 12px 18px; display: flex; justify-content: flex-end; gap: 8px;">
                    <button type="button" class="btn btn-sm btn-secondary modal-close-trigger" data-dismiss="modal" data-bs-dismiss="modal" id="btn-cancel-expand" style="padding: 6px 14px; font-weight: 500;">Cancel</button>
                    <button type="button" class="btn btn-sm btn-primary" id="btn-confirm-expand" style="padding: 6px 16px; font-weight: 600;">
                        <i class="fas fa-expand-arrows-alt"></i> Proceed with Expansion
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Terminology Database Update / Build Summary Modal -->
    <div class="modal fade" id="update-summary-modal" tabindex="-1" role="dialog" aria-labelledby="updateSummaryModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document" style="max-width: 580px;">
            <div class="modal-content" style="border-radius: 8px; border: 1px solid #cbd5e1; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);">
                <div class="modal-header" style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; border-top-left-radius: 8px; border-top-right-radius: 8px; padding: 14px 18px; display: flex; align-items: center; justify-content: space-between;">
                    <h5 class="modal-title" id="updateSummaryModalLabel" style="font-size: 1rem; font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 8px; margin: 0;">
                        <i id="update-modal-header-icon" class="fas fa-layer-group text-primary"></i>
                        <span id="update-modal-header-title">Confirm Terminology Database Update</span>
                    </h5>
                    <button type="button" class="btn-close close modal-close-trigger" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Close" style="font-size: 1.15rem; color: #64748b; opacity: 0.8; border: none; background: transparent; cursor: pointer; padding: 4px;">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" style="padding: 20px;">
                    <p id="update-modal-intro" style="color: #475569; font-size: 0.9rem; margin-bottom: 16px; line-height: 1.45;"></p>

                    <!-- Packages To Add Section -->
                    <div id="update-modal-add-section" style="margin-bottom: 14px; display: none;">
                        <div style="font-size: 0.78rem; font-weight: 700; color: #166534; text-transform: uppercase; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                            <i class="fas fa-plus-circle" style="color: #16a34a;"></i>
                            <span id="update-modal-add-header-title">Packages to Install</span>
                        </div>
                        <div id="update-modal-add-list" style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 10px 14px; font-size: 0.85rem; color: #15803d; max-height: 180px; overflow-y: auto;"></div>
                    </div>

                    <!-- Packages To Remove Section -->
                    <div id="update-modal-remove-section" style="margin-bottom: 14px; display: none;">
                        <div style="font-size: 0.78rem; font-weight: 700; color: #991b1b; text-transform: uppercase; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                            <i class="fas fa-minus-circle" style="color: #dc2626;"></i>
                            <span id="update-modal-remove-header-title">Packages to Remove</span>
                        </div>
                        <div id="update-modal-remove-list" style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 10px 14px; font-size: 0.85rem; color: #b91c1c; max-height: 180px; overflow-y: auto;"></div>
                    </div>

                    <!-- Unchanged Packages Section -->
                    <div id="update-modal-unchanged-section" style="margin-bottom: 14px; display: none;">
                        <div style="font-size: 0.78rem; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                            <i class="fas fa-check-circle" style="color: #94a3b8;"></i>
                            <span id="update-modal-unchanged-header-title">Remaining Active</span>
                        </div>
                        <div id="update-modal-unchanged-list" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 12px; font-size: 0.8rem; color: #64748b; display: flex; flex-wrap: wrap; gap: 6px; max-height: 120px; overflow-y: auto;"></div>
                    </div>

                    <!-- No Changes Notice -->
                    <div id="update-modal-no-changes" class="alert alert-info" style="display: none; margin-bottom: 0; font-size: 0.85rem;">
                        <i class="fas fa-info-circle"></i> No changes detected. All selected packages match the currently installed database.
                    </div>

                    <div id="update-modal-timing-hint" style="font-size: 0.78rem; color: #64748b; margin-top: 14px;">
                        <i class="far fa-clock"></i> In-place differential updates typically take 15–35 seconds.
                    </div>
                </div>
                <div class="modal-footer" style="background: #f8fafc; border-top: 1px solid #e2e8f0; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px; padding: 12px 18px; display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn btn-secondary modal-close-trigger" data-dismiss="modal" data-bs-dismiss="modal" style="padding: 6px 16px; font-size: 0.875rem;">
                        Cancel
                    </button>
                    <button type="button" id="btn-modal-confirm-update" class="btn btn-primary" style="padding: 6px 18px; font-size: 0.875rem; font-weight: 600;">
                        <i class="fas fa-check" style="margin-right: 4px;"></i> <span id="update-modal-confirm-btn-text">Confirm & Update</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= htmlspecialchars($module->getUrl('manager.js'), ENT_QUOTES) ?>"></script>