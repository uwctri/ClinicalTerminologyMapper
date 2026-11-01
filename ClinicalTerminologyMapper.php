<?php

namespace UWMadison\ClinicalTerminologyMapper;

use ExternalModules\AbstractExternalModule;
use REDCap;
use Project;
use UserRights;
use OntologyManager;
use Logging;
use PDO;
use Throwable;

class ClinicalTerminologyMapper extends AbstractExternalModule
{
    private const PACKAGE_KEY_MAP = [
        'pkg_fhir_r4'     => 'FHIR R4',
        'pkg_fhir_r5'     => 'FHIR R5',
        'pkg_us_core_311' => 'US Core v3.1.1',
        'pkg_us_core_501' => 'US Core v5.0.1',
        'pkg_us_core_610' => 'US Core v6.1.0',
        'pkg_us_core_700' => 'US Core v7.0.0',
        'pkg_us_core_800' => 'US Core v8.0.0',
        'pkg_us_core_900' => 'US Core v9.0.0',
        'pkg_mcode_300'   => 'mCODE v3.0.0',
        'pkg_mcode_400'   => 'mCODE v4.0.0',
        'pkg_sdoh_230'    => 'SDOH Clinical Care v2.3.0',
        'pkg_sdoh_300'    => 'SDOH Clinical Care v3.0.0',
        'pkg_rxnorm'      => 'RxNorm',
        'pkg_loinc'       => 'LOINC',
    ];

    private ?TerminologyService $terminologyService = null;
    private ?FhirOntologyProvider $ontologyProvider = null;

    public function getTerminologyService(): TerminologyService
    {
        if ($this->terminologyService === null) {
            require_once __DIR__ . '/TerminologyService.php';
            $dbPath = __DIR__ . '/fhir_valuesets.sqlite';
            $this->terminologyService = new TerminologyService($dbPath);
        }
        return $this->terminologyService;
    }

    public function getOntologyProvider(?int $projectId = null): FhirOntologyProvider
    {
        if ($this->ontologyProvider === null) {
            require_once __DIR__ . '/FhirOntologyProvider.php';
            $this->ontologyProvider = new FhirOntologyProvider($this->getTerminologyService());
        }
        $pid = $projectId ?? $this->getProjectId() ?? (isset($_GET['pid']) && is_numeric($_GET['pid']) ? (int)$_GET['pid'] : null);
        $enabledPackages = $this->getEnabledPackages($pid);
        $this->ontologyProvider->setAllowedPackages($enabledPackages);
        return $this->ontologyProvider;
    }

    public function redcap_module_project_enable($version, $project_id): void
    {
        $available = $this->getTerminologyService()->getAvailablePackages();
        if (in_array('FHIR R4', $available, true) && $this->getProjectSetting('pkg_fhir_r4', $project_id) === null) {
            $this->setProjectSetting('pkg_fhir_r4', true, $project_id);
        }
        if (in_array('US Core v3.1.1', $available, true) && $this->getProjectSetting('pkg_us_core_311', $project_id) === null) {
            $this->setProjectSetting('pkg_us_core_311', true, $project_id);
        }
    }

    public function redcap_module_configuration_settings($project_id, $settings): array
    {
        // Only dynamically filter project-level settings
        if ($project_id === null) return $settings;

        $available = $this->getTerminologyService()->getAvailablePackages();

        $filtered = [];
        foreach ($settings as $s) {
            $key = $s['key'] ?? '';
            if (isset(self::PACKAGE_KEY_MAP[$key])) {
                $pkgName = self::PACKAGE_KEY_MAP[$key];
                if (!in_array($pkgName, $available, true)) {
                    // Package is not built in the database, don't list it in project configuration
                    continue;
                }
            }
            $filtered[] = $s;
        }

        return $filtered;
    }

    public function redcap_every_page_before_render($project_id = null): void
    {
        if (!class_exists('OntologyManager')) return;

        $pid = $project_id ?? $this->getProjectId() ?? (isset($_GET['pid']) && is_numeric($_GET['pid']) ? (int)$_GET['pid'] : null);
        if ($pid === null) return;

        $enabled = (bool)$this->getProjectSetting('enable_ontology_provider', $pid);
        if ($enabled && $this->getTerminologyService()->isAvailable()) {
            $manager = OntologyManager::getOntologyManager();
            $manager->addProvider($this->getOntologyProvider($pid));
        }
    }

    public function redcap_module_link_check_display($project_id, $link): ?array
    {
        // Control Center link ($project_id === null) - only show to Super Users / Admins
        if ($project_id === null) {
            return (defined('SUPER_USER') && SUPER_USER) ? $link : null;
        }

        if (!$this->userHasDesignRights($project_id)) return null;
        return $link;
    }

    public function userHasDesignRights($project_id): bool
    {
        if (defined('SUPER_USER') && SUPER_USER) return true;
        if ($project_id === null) return false;

        $userId = defined('USERID') ? USERID : '';
        if ($userId === '') return false;

        $rights = UserRights::getPrivileges($project_id, $userId);
        $userRights = $rights[$project_id][strtolower($userId)] ?? [];

        return (bool)($userRights['design'] ?? false);
    }

    public function redcap_module_ajax($action, $payload, $project_id, $record): array
    {
        if ($action === 'build_terminology') {
            if (!defined('SUPER_USER') || !SUPER_USER) {
                return ['success' => false, 'error' => 'Super User privileges required.'];
            }
            set_time_limit(600);
            ini_set('memory_limit', '1024M');
            require_once __DIR__ . '/TerminologyBuilderService.php';
            $dbPath = __DIR__ . '/fhir_valuesets.sqlite';
            $selectedPackages = isset($payload['packages']) && is_array($payload['packages'])
                ? array_values(array_map('strval', $payload['packages']))
                : null;
            $builder = new TerminologyBuilderService($dbPath, null, false, $selectedPackages);
            return $builder->build();
        }

        if ($action === 'expand_valueset') {
            if (!defined('SUPER_USER') || !SUPER_USER) {
                return ['success' => false, 'error' => 'Super User privileges required.'];
            }
            return $this->expandSingleValueset($payload);
        }

        if ($action === 'sync_loinc') {
            if (!defined('SUPER_USER') || !SUPER_USER) {
                return ['success' => false, 'error' => 'Super User privileges required.'];
            }
            return $this->syncLoincFromApi($payload);
        }

        if ($action === 'purge_loinc') {
            if (!defined('SUPER_USER') || !SUPER_USER) {
                return ['success' => false, 'error' => 'Super User privileges required.'];
            }
            return $this->purgeLoinc();
        }

        if (!$this->userHasDesignRights($project_id)) {
            return ['success' => false, 'error' => 'Project design rights are required to use Clinical Terminology Mapper.'];
        }

        if ($action === 'get_mapped_fields') return $this->getMappedFields($project_id);
        if ($action === 'get_project_fields') return $this->getProjectFields($project_id);
        if ($action === 'add_custom_field') return $this->addCustomField($project_id, $payload);
        if ($action === 'remove_custom_field') return $this->removeCustomField($project_id, $payload);
        if ($action === 'search_valuesets') return $this->searchValuesets($payload);
        if ($action === 'get_valueset_options') return $this->getValueSetOptions($payload);
        if ($action === 'apply_choices') return $this->applyFieldChoices($project_id, $payload);
        if ($action === 'batch_apply_choices') return $this->batchApplyChoices($project_id, $payload);
        if ($action === 'set_field_valueset_override') return $this->setFieldValuesetOverride($project_id, $payload);
        if ($action === 'parse_fhir_payload') return $this->parseFhirPayload($project_id, $payload);

        return ['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)];
    }

    public function getInitialPageData(?int $project_id = null): array
    {
        if (!$project_id) {
            $project_id = (int)($this->getProjectId() ?? 0);
        }
        return $this->getMappedFields($project_id);
    }

    private function getMappedFields($project_id): array
    {
        $terminology = $this->getTerminologyService();
        if (!$terminology->isAvailable()) {
            return [
                'success' => false,
                'db_missing' => true,
                'error' => 'The FHIR Terminology Database is not initialized. Please contact your REDCap System Administrator to build the database from the Control Center.'
            ];
        }

        $project = new Project($project_id);
        $isProduction = ((int)$project->project['status'] === 1);
        $hasDraftMode = ($isProduction && (int)$project->project['draft_mode'] === 1);

        $sql = "
            SELECT m.map_id, m.field_name, m.external_source_field_name, m.event_id
            FROM redcap_ddp_mapping m
            WHERE m.project_id = ?
            ORDER BY m.field_name ASC
        ";
        $q = $this->query($sql, [$project_id]);

        $fields = [];
        $enabledPackages = $this->getEnabledPackages($project_id);

        $overrides = $this->getProjectSetting('field_valueset_overrides', $project_id) ?: [];
        if (is_string($overrides)) {
            $overrides = json_decode($overrides, true) ?: [];
        }

        $metaSource = ($isProduction && $hasDraftMode && !empty($project->metadata_temp))
            ? $project->metadata_temp
            : $project->metadata;

        $rawRows = [];
        $fieldNames = [];

        while ($row = $q->fetch_assoc()) {
            $fieldName = $row['field_name'];
            $meta = $metaSource[$fieldName] ?? null;
            if (!$meta) continue;

            $sourceField = strtolower((string)$row['external_source_field_name']);
            $availablePresets = $this->getPresetsForSource($sourceField, $enabledPackages);
            if (empty($availablePresets)) {
                continue;
            }

            $rawRows[] = [
                'row' => $row,
                'meta' => $meta,
                'sourceField' => $sourceField,
                'presets' => $availablePresets,
                'is_custom' => false
            ];
            $fieldNames[] = $fieldName;
        }

        // Additional project fields added manually by user (non-CDIS or extra fields)
        $customFieldNames = $this->getProjectSetting('custom_fields', $project_id) ?: [];
        if (is_string($customFieldNames)) {
            $customFieldNames = json_decode($customFieldNames, true) ?: [];
        }

        foreach ($customFieldNames as $customFieldName) {
            if (in_array($customFieldName, $fieldNames, true)) {
                continue;
            }
            $meta = $metaSource[$customFieldName] ?? null;
            if (!$meta) continue;

            $rawRows[] = [
                'row' => [
                    'map_id' => 'custom_' . $customFieldName,
                    'field_name' => $customFieldName,
                    'external_source_field_name' => 'Project Field',
                    'event_id' => null
                ],
                'meta' => $meta,
                'sourceField' => 'custom',
                'presets' => [],
                'is_custom' => true
            ];
            $fieldNames[] = $customFieldName;
        }

        $recordCounts = $this->getFieldRecordCounts($project_id, $fieldNames);

        foreach ($rawRows as $item) {
            $row = $item['row'];
            $meta = $item['meta'];
            $fieldName = $row['field_name'];
            $availablePresets = $item['presets'];
            $isCustom = !empty($item['is_custom']);

            $elementType = strtolower((string)$meta['element_type']);
            $isMultipleChoice = in_array($elementType, ['radio', 'select', 'checkbox', 'advcheckbox'], true);
            $currentEnum = (string)($meta['element_enum'] ?? '');
            $parsedChoices = parseEnum($currentEnum);

            $primaryPreset = $availablePresets[0] ?? null;

            $targetConcepts = [];
            $isMatch = false;
            $selectedPresetId = null;
            $targetVsTitle = null;
            $targetVsPackage = 'FHIR R4';

            $override = $overrides[$fieldName] ?? null;
            if ($override === '__none__') {
                $selectedPresetId = null;
                $targetVsTitle = null;
                $targetVsPackage = null;
                $targetConcepts = [];
                $isMatch = false;
            } else if (!empty($override)) {
                $overridePkg = null;
                $overrideVsId = $override;
                if (str_contains($override, '::')) {
                    [$overrideVsId, $overridePkg] = explode('::', $override, 2);
                }
                $customVs = $terminology->getValueSet($overrideVsId, $overridePkg, $enabledPackages);
                if ($customVs) {
                    $selectedPresetId = $customVs['id'];
                    $targetVsTitle = $customVs['title'];
                    $targetVsPackage = $customVs['package'] ?? 'FHIR R4';
                    $targetConcepts = $terminology->getConceptsForValueset($customVs['id'], $customVs['package']);
                }
            } else if ($primaryPreset) {
                $selectedPresetId = $primaryPreset['id'];
                $targetVsTitle = $primaryPreset['title'];
                $targetVsPackage = $primaryPreset['package'] ?? 'FHIR R4';
                $targetConcepts = $terminology->getConceptsForValueset($primaryPreset['id'], $primaryPreset['package'] ?? null);
            }

            if ($isMultipleChoice && !empty($targetConcepts)) {
                $targetCodes = array_map('strval', array_column($targetConcepts, 'code'));
                $existingCodes = array_map('strval', array_keys($parsedChoices));

                if (!empty($existingCodes) && empty(array_diff($existingCodes, $targetCodes)) && empty(array_diff($targetCodes, $existingCodes))) {
                    $isMatch = true;
                }
            }

            $recordCount = $recordCounts[$fieldName] ?? 0;

            $fields[] = [
                'map_id' => $row['map_id'],
                'field_name' => $fieldName,
                'field_label' => strip_tags((string)$meta['element_label']),
                'form_name' => $meta['form_name'],
                'element_type' => $elementType,
                'external_source' => $row['external_source_field_name'],
                'is_custom' => $isCustom,
                'current_choices' => $parsedChoices,
                'presets' => $availablePresets,
                'selected_preset_id' => $selectedPresetId,
                'target_vs_title' => $targetVsTitle,
                'target_vs_package' => $targetVsPackage,
                'target_concepts' => $targetConcepts,
                'is_match' => $isMatch,
                'record_count' => $recordCount,
                'is_multiple_choice' => $isMultipleChoice
            ];
        }

        return [
            'success' => true,
            'project_id' => $project_id,
            'is_production' => $isProduction,
            'has_draft_mode' => $hasDraftMode,
            'can_modify' => (!$isProduction || $hasDraftMode),
            'enabled_packages' => $enabledPackages,
            'fields' => $fields
        ];
    }

    private function getProjectFields(int $project_id): array
    {
        $project = new Project($project_id);
        $isProduction = ((int)$project->project['status'] === 1);
        $hasDraftMode = ($isProduction && (int)$project->project['draft_mode'] === 1);

        $metaSource = ($isProduction && $hasDraftMode && !empty($project->metadata_temp))
            ? $project->metadata_temp
            : $project->metadata;

        // Existing mapped fields in Option Mapper (CDIS + custom)
        $mappedData = $this->getMappedFields($project_id);
        $alreadyMappedFieldNames = [];
        if (!empty($mappedData['fields'])) {
            $alreadyMappedFieldNames = array_column($mappedData['fields'], 'field_name');
        }

        $fields = [];
        foreach ($metaSource as $fieldName => $meta) {
            $elementType = strtolower((string)$meta['element_type']);
            // Allow text, radio, dropdown (select), and checkbox
            if (!in_array($elementType, ['text', 'radio', 'select', 'checkbox'], true)) {
                continue;
            }

            // Exclude form_status fields (e.g. form_name_complete)
            if (str_ends_with($fieldName, '_complete') && ($meta['element_type'] === 'select')) {
                continue;
            }

            $currentEnum = (string)($meta['element_enum'] ?? '');
            $choices = parseEnum($currentEnum);

            $fields[] = [
                'field_name' => $fieldName,
                'field_label' => strip_tags((string)$meta['element_label']),
                'form_name' => $meta['form_name'],
                'element_type' => $elementType,
                'current_choices' => $choices,
                'choice_count' => count($choices),
                'is_already_added' => in_array($fieldName, $alreadyMappedFieldNames, true)
            ];
        }

        return [
            'success' => true,
            'fields' => $fields
        ];
    }

    private function addCustomField(int $project_id, array $payload): array
    {
        $fieldName = trim((string)($payload['field_name'] ?? ''));
        if ($fieldName === '') {
            return ['success' => false, 'error' => 'Field name required'];
        }

        $project = new Project($project_id);
        $isProduction = ((int)$project->project['status'] === 1);
        $hasDraftMode = ($isProduction && (int)$project->project['draft_mode'] === 1);
        $metaSource = ($isProduction && $hasDraftMode && !empty($project->metadata_temp))
            ? $project->metadata_temp
            : $project->metadata;

        if (!isset($metaSource[$fieldName])) {
            return ['success' => false, 'error' => "Field '{$fieldName}' does not exist in this project."];
        }

        $customFields = $this->getProjectSetting('custom_fields', $project_id) ?: [];
        if (is_string($customFields)) {
            $customFields = json_decode($customFields, true) ?: [];
        }

        if (!in_array($fieldName, $customFields, true)) {
            $customFields[] = $fieldName;
            $this->setProjectSetting('custom_fields', $customFields, $project_id);
        }

        return ['success' => true, 'field_name' => $fieldName];
    }

    private function removeCustomField(int $project_id, array $payload): array
    {
        $fieldName = trim((string)($payload['field_name'] ?? ''));
        if ($fieldName === '') {
            return ['success' => false, 'error' => 'Field name required'];
        }

        $customFields = $this->getProjectSetting('custom_fields', $project_id) ?: [];
        if (is_string($customFields)) {
            $customFields = json_decode($customFields, true) ?: [];
        }

        $customFields = array_values(array_filter($customFields, fn($f) => $f !== $fieldName));
        $this->setProjectSetting('custom_fields', $customFields, $project_id);

        // Also clean up any override
        $overrides = $this->getProjectSetting('field_valueset_overrides', $project_id) ?: [];
        if (is_string($overrides)) {
            $overrides = json_decode($overrides, true) ?: [];
        }
        if (isset($overrides[$fieldName])) {
            unset($overrides[$fieldName]);
            $this->setProjectSetting('field_valueset_overrides', $overrides, $project_id);
        }

        return ['success' => true, 'field_name' => $fieldName];
    }

    public function getEnabledPackages(?int $project_id = null): array
    {
        if (!$project_id) {
            $project_id = (int)($this->getProjectId() ?? 0);
        }

        $enabled = [];
        if ($project_id > 0) {
            foreach (self::PACKAGE_KEY_MAP as $settingKey => $pkgName) {
                $val = $this->getProjectSetting($settingKey, $project_id);
                if ($val === true || $val === '1' || $val === 1) {
                    $enabled[] = $pkgName;
                }
            }
        }

        $available = $this->getTerminologyService()->getAvailablePackages();

        // Default: if no settings are configured, default to FHIR R4 and US Core v3.1.1 (if available)
        if (empty($enabled)) {
            $defaults = ['FHIR R4', 'US Core v3.1.1'];
            $enabled = array_values(array_intersect($defaults, $available));
        } else {
            // Filter enabled to only what is actually built in the database
            $enabled = array_values(array_intersect($enabled, $available));
        }

        return $enabled;
    }

    private function getPresetsForSource(string $source, array $enabledPackages = []): array
    {
        if (empty($enabledPackages)) {
            $enabledPackages = $this->getEnabledPackages();
        }
        $terminology = $this->getTerminologyService();
        $presets = [];

        $findInEnabled = function (string $vsId) use ($terminology, $enabledPackages) {
            return $terminology->getValueSet($vsId, null, $enabledPackages);
        };

        if (str_contains($source, 'birth') && str_contains($source, 'sex')) {
            $vs1 = $findInEnabled('us-core-birthsex');
            $vs2 = $findInEnabled('administrative-gender');
            if ($vs1) $presets[] = $vs1;
            if ($vs2) $presets[] = $vs2;
            return $presets;
        }

        if (str_contains($source, 'gender-identity') || str_contains($source, 'gender_identity')) {
            $vs = $findInEnabled('us-core-gender-identity');
            if ($vs) $presets[] = $vs;
            return $presets;
        }

        if (str_contains($source, 'gender') || str_contains($source, 'sex')) {
            $vs1 = $findInEnabled('administrative-gender');
            $vs2 = $findInEnabled('us-core-birthsex');
            if ($vs1) $presets[] = $vs1;
            if ($vs2) $presets[] = $vs2;
            return $presets;
        }

        if (str_contains($source, 'race')) {
            $vs = $findInEnabled('omb-race-category');
            if ($vs) $presets[] = $vs;
            return $presets;
        }

        if (str_contains($source, 'ethnicity')) {
            $vs = $findInEnabled('omb-ethnicity-category');
            if ($vs) $presets[] = $vs;
            return $presets;
        }

        if (str_contains($source, 'marital')) {
            $vs = $findInEnabled('marital-status');
            if ($vs) $presets[] = $vs;
            return $presets;
        }

        if (str_contains($source, 'sexual') || str_contains($source, 'orientation')) {
            $vs = $findInEnabled('us-core-sexual-orientation');
            if ($vs) $presets[] = $vs;
            return $presets;
        }

        return $presets;
    }

    private function getFieldRecordCounts(int $project_id, array $fieldNames): array
    {
        if (empty($fieldNames)) return [];

        $dataTable = REDCap::getDataTable($project_id);
        $inClause = implode(',', array_fill(0, count($fieldNames), '?'));
        $sql = "SELECT field_name, COUNT(DISTINCT record) as cnt 
                FROM {$dataTable} 
                WHERE project_id = ? AND field_name IN ({$inClause}) AND value != '' 
                GROUP BY field_name";

        $params = array_merge([$project_id], $fieldNames);
        $res = $this->query($sql, $params);

        $counts = [];
        while ($row = $res->fetch_assoc()) {
            $counts[$row['field_name']] = (int)$row['cnt'];
        }
        return $counts;
    }

    private function searchValuesets(array $payload): array
    {
        $terminology = $this->getTerminologyService();
        if (!$terminology->isAvailable()) {
            return ['success' => false, 'db_missing' => true, 'error' => 'The FHIR Terminology Database is not initialized.'];
        }

        require_once __DIR__ . '/ValueSetSizeCatalog.php';
        $query = (string)($payload['query'] ?? '');
        $limit = (int)($payload['limit'] ?? 25);
        $enabledPackages = $this->getEnabledPackages();
        $results = $terminology->searchValuesets($query, $enabledPackages, $limit);

        foreach ($results as &$vs) {
            $cat = ValueSetSizeCatalog::lookup($vs['id'], $vs['package'] ?? null, $vs['version'] ?? null);
            if ($cat !== null) {
                $vs['catalog_size'] = $cat['total'];
                $vs['catalog_version'] = $cat['version'];
                $vs['catalog_is_exact'] = $cat['is_exact'];
                $vs['catalog_source'] = $cat['source'];
                $vs['catalog_notes'] = $cat['notes'];
            }
        }
        unset($vs);

        return ['success' => true, 'valuesets' => $results, 'enabled_packages' => $enabledPackages];
    }

    private function getValueSetOptions(array $payload): array
    {
        $terminology = $this->getTerminologyService();
        if (!$terminology->isAvailable()) {
            return ['success' => false, 'db_missing' => true, 'error' => 'The FHIR Terminology Database is not initialized.'];
        }

        $idOrUrl = (string)($payload['valueset_id'] ?? '');
        if ($idOrUrl === '') return ['success' => false, 'error' => 'ValueSet ID required'];

        $pkg = (string)($payload['package'] ?? '');
        $enabledPackages = $this->getEnabledPackages();

        $vs = $terminology->getValueSet($idOrUrl, $pkg ?: null, $enabledPackages);
        if (!$vs) return ['success' => false, 'error' => 'ValueSet not found'];

        $concepts = $terminology->getConceptsForValueset($vs['id'], $vs['package']);

        return [
            'success' => true,
            'valueset' => $vs,
            'concepts' => $concepts
        ];
    }

    private function applyFieldChoices(int $project_id, array $payload): array
    {
        $fieldName = (string)($payload['field_name'] ?? '');
        $newEnum = (string)($payload['element_enum'] ?? '');

        if ($fieldName === '') return ['success' => false, 'error' => 'Field name required'];
        if ($newEnum === '') return ['success' => false, 'error' => 'Choices cannot be empty'];

        $project = new Project($project_id);
        $isProduction = ((int)$project->project['status'] === 1);
        $hasDraftMode = ($isProduction && (int)$project->project['draft_mode'] === 1);

        if ($isProduction && !$hasDraftMode) {
            return [
                'success' => false,
                'error' => 'This project is in Production status. Changes are blocked until Draft Mode is opened in the Online Designer.'
            ];
        }

        $targetTable = ($isProduction && $hasDraftMode) ? 'redcap_metadata_temp' : 'redcap_metadata';

        $overrides = $this->getProjectSetting('field_valueset_overrides', $project_id) ?: [];
        if (is_string($overrides)) {
            $overrides = json_decode($overrides, true) ?: [];
        }
        if (isset($overrides[$fieldName]) && $overrides[$fieldName] === '__none__') {
            return ['success' => false, 'error' => "Cannot update field '{$fieldName}' because it is explicitly unmapped."];
        }

        $checkSql = "SELECT field_name, element_type FROM {$targetTable} WHERE project_id = ? AND field_name = ? LIMIT 1";
        $checkRes = $this->query($checkSql, [$project_id, $fieldName]);
        $existingRow = $checkRes->fetch_assoc();
        if (!$existingRow) {
            return ['success' => false, 'error' => "Field '{$fieldName}' not found in {$targetTable}."];
        }

        $parsed = parseEnum($newEnum);
        $optionCount = is_array($parsed) ? count($parsed) : 0;
        if ($optionCount === 0) {
            $lines = array_filter(array_map('trim', explode("\n", $newEnum)));
            $optionCount = count($lines);
        }

        $currentType = strtolower((string)($existingRow['element_type'] ?? ''));
        $newElementType = in_array($currentType, ['checkbox', 'advcheckbox'], true)
            ? $currentType
            : (($optionCount > 6) ? 'select' : 'radio');

        $updateSql = "UPDATE {$targetTable} SET element_enum = ?, element_type = ?, element_validation_type = NULL, element_validation_min = NULL, element_validation_max = NULL, element_validation_checktype = NULL WHERE project_id = ? AND field_name = ?";
        $this->query($updateSql, [$newEnum, $newElementType, $project_id, $fieldName]);

        Logging::logEvent(
            $updateSql,
            $targetTable,
            'MANAGE',
            $fieldName,
            "field_name = '{$fieldName}'",
            "Updated field choices and field type ({$newElementType}) via Clinical Terminology Mapper ({$fieldName})"
        );

        return [
            'success' => true,
            'field_name' => $fieldName,
            'element_type' => $newElementType,
            'target_table' => $targetTable,
            'message' => "Successfully updated choices and set field type to {$newElementType} for '{$fieldName}'."
        ];
    }

    private function batchApplyChoices(int $project_id, array $payload): array
    {
        $updates = (array)($payload['updates'] ?? []);
        if (empty($updates)) return ['success' => false, 'error' => 'No field updates provided'];

        $project = new Project($project_id);
        $isProduction = ((int)$project->project['status'] === 1);
        $hasDraftMode = ($isProduction && (int)$project->project['draft_mode'] === 1);

        if ($isProduction && !$hasDraftMode) {
            return [
                'success' => false,
                'error' => 'This project is in Production status. Changes are blocked until Draft Mode is opened in the Online Designer.'
            ];
        }

        $targetTable = ($isProduction && $hasDraftMode) ? 'redcap_metadata_temp' : 'redcap_metadata';
        $updatedFields = [];

        $overrides = $this->getProjectSetting('field_valueset_overrides', $project_id) ?: [];
        if (is_string($overrides)) {
            $overrides = json_decode($overrides, true) ?: [];
        }

        $customFields = $this->getProjectSetting('custom_fields', $project_id) ?: [];
        if (is_string($customFields)) {
            $customFields = json_decode($customFields, true) ?: [];
        }

        foreach ($updates as $item) {
            $fieldName = (string)($item['field_name'] ?? '');
            $newEnum = (string)($item['element_enum'] ?? '');
            if ($fieldName === '' || $newEnum === '') continue;

            // Never modify fields that are unmapped
            if (isset($overrides[$fieldName]) && $overrides[$fieldName] === '__none__') {
                continue;
            }
            if (in_array($fieldName, $customFields, true) && empty($overrides[$fieldName])) {
                continue;
            }

            $checkSql = "SELECT field_name, element_type FROM {$targetTable} WHERE project_id = ? AND field_name = ? LIMIT 1";
            $checkRes = $this->query($checkSql, [$project_id, $fieldName]);
            $existingRow = $checkRes->fetch_assoc();
            if (!$existingRow) {
                continue;
            }

            $parsed = parseEnum($newEnum);
            $optionCount = is_array($parsed) ? count($parsed) : 0;
            if ($optionCount === 0) {
                $lines = array_filter(array_map('trim', explode("\n", $newEnum)));
                $optionCount = count($lines);
            }

            $currentType = strtolower((string)($existingRow['element_type'] ?? ''));
            $newElementType = in_array($currentType, ['checkbox', 'advcheckbox'], true)
                ? $currentType
                : (($optionCount > 6) ? 'select' : 'radio');

            $updateSql = "UPDATE {$targetTable} SET element_enum = ?, element_type = ?, element_validation_type = NULL, element_validation_min = NULL, element_validation_max = NULL, element_validation_checktype = NULL WHERE project_id = ? AND field_name = ?";
            $this->query($updateSql, [$newEnum, $newElementType, $project_id, $fieldName]);

            Logging::logEvent(
                $updateSql,
                $targetTable,
                'MANAGE',
                $fieldName,
                "field_name = '{$fieldName}'",
                "Updated field choices and field type ({$newElementType}) via Clinical Terminology Mapper ({$fieldName})"
            );

            $updatedFields[] = $fieldName;
        }

        return [
            'success' => true,
            'updated_count' => count($updatedFields),
            'updated_fields' => $updatedFields,
            'target_table' => $targetTable
        ];
    }

    private function setFieldValuesetOverride(int $project_id, array $payload): array
    {
        $fieldName = (string)($payload['field_name'] ?? '');
        $valuesetId = (string)($payload['valueset_id'] ?? '');
        $package = (string)($payload['package'] ?? '');

        if ($fieldName === '') {
            return ['success' => false, 'error' => 'Field name required'];
        }

        $overrides = $this->getProjectSetting('field_valueset_overrides', $project_id) ?: [];
        if (is_string($overrides)) {
            $overrides = json_decode($overrides, true) ?: [];
        }

        if ($valuesetId === '' || $valuesetId === '__none__') {
            $overrides[$fieldName] = '__none__';
        } else {
            $overrides[$fieldName] = ($package !== '') ? "{$valuesetId}::{$package}" : $valuesetId;
        }
        $this->setProjectSetting('field_valueset_overrides', $overrides, $project_id);

        return ['success' => true, 'overrides' => $overrides];
    }

    private function parseFhirPayload(int $project_id, array $payload): array
    {
        $raw = (string)($payload['fhir_json'] ?? '');
        if (trim($raw) === '') return ['success' => false, 'error' => 'Please paste a FHIR JSON message.'];

        $json = json_decode($raw, true);
        if (!$json || !is_array($json)) {
            return ['success' => false, 'error' => 'Invalid JSON format. Please ensure valid FHIR JSON is provided.'];
        }

        $terminology = $this->getTerminologyService();
        if (!$terminology->isAvailable()) {
            return ['success' => false, 'db_missing' => true, 'error' => 'The FHIR Terminology Database is not initialized.'];
        }
        $extractedItems = [];
        $this->extractFhirConcepts($json, '', $extractedItems);

        $sql = "SELECT map_id, field_name, external_source_field_name FROM redcap_ddp_mapping WHERE project_id = ?";
        $res = $this->query($sql, [$project_id]);
        $projectMappings = [];
        while ($row = $res->fetch_assoc()) {
            $projectMappings[strtolower($row['external_source_field_name'])] = $row['field_name'];
        }

        $enabledPackages = $this->getEnabledPackages($project_id);
        $results = [];
        foreach ($extractedItems as $item) {
            $code = $item['code'];
            $system = $item['system'] ?? null;
            $matchedVsList = $terminology->findValueSetsByConcept($code, $system, $enabledPackages);

            $matchedField = null;
            $sourceHint = strtolower($item['path']);
            foreach ($projectMappings as $sourceField => $rcField) {
                if (str_contains($sourceHint, $sourceField) || str_contains($sourceField, $sourceHint)) {
                    $matchedField = $rcField;
                    break;
                }
            }

            $results[] = [
                'path' => $item['path'],
                'code' => $code,
                'display' => $item['display'] ?? $code,
                'system' => $system,
                'matched_valuesets' => $matchedVsList,
                'matched_redcap_field' => $matchedField
            ];
        }

        return [
            'success' => true,
            'detected_count' => count($results),
            'detected_concepts' => $results
        ];
    }

    private function extractFhirConcepts(mixed $node, string $path, array &$results): void
    {
        if (!is_array($node)) return;

        if (isset($node['gender']) && is_string($node['gender'])) {
            $results[] = [
                'path' => $path ? "{$path}.gender" : 'Patient.gender',
                'code' => $node['gender'],
                'display' => ucfirst($node['gender']),
                'system' => 'http://hl7.org/fhir/administrative-gender'
            ];
        }

        if (isset($node['valueCoding']) && is_array($node['valueCoding'])) {
            $vc = $node['valueCoding'];
            if (!empty($vc['code'])) {
                $results[] = [
                    'path' => $path ? "{$path}.valueCoding" : 'valueCoding',
                    'code' => (string)$vc['code'],
                    'display' => (string)($vc['display'] ?? $vc['code']),
                    'system' => (string)($vc['system'] ?? '')
                ];
            }
        }

        if (isset($node['valueCode']) && is_string($node['valueCode'])) {
            $results[] = [
                'path' => $path ? "{$path}.valueCode" : 'valueCode',
                'code' => $node['valueCode'],
                'display' => $node['valueCode'],
                'system' => null
            ];
        }

        if (isset($node['coding']) && is_array($node['coding'])) {
            foreach ($node['coding'] as $c) {
                if (!empty($c['code'])) {
                    $results[] = [
                        'path' => $path ? "{$path}.coding" : 'coding',
                        'code' => (string)$c['code'],
                        'display' => (string)($c['display'] ?? $c['code']),
                        'system' => (string)($c['system'] ?? '')
                    ];
                }
            }
        }

        foreach ($node as $key => $child) {
            if ($key === 'valueCoding' || $key === 'coding') continue;
            if (is_array($child)) {
                $childPath = $path ? "{$path}.{$key}" : (string)$key;
                if (isset($child['url'])) {
                    $childPath .= '[' . basename((string)$child['url']) . ']';
                }
                $this->extractFhirConcepts($child, $childPath, $results);
            }
        }
    }

    private function expandSingleValueset(array $payload): array
    {
        $id = trim((string)($payload['id'] ?? ''));
        $package = trim((string)($payload['package'] ?? ''));
        if ($id === '' || $package === '') {
            return ['success' => false, 'error' => 'ValueSet ID and package are required.'];
        }

        $terminology = $this->getTerminologyService();
        if (!$terminology->isAvailable()) {
            return ['success' => false, 'error' => 'Terminology database is not available.'];
        }

        $vs = $terminology->getValueSet($id, $package);
        if (!$vs) {
            return ['success' => false, 'error' => "ValueSet '{$id}' not found in package '{$package}'."];
        }

        $dbPath = __DIR__ . '/fhir_valuesets.sqlite';
        if (!is_writable($dbPath)) {
            @chmod($dbPath, 0666);
        }
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode = WAL;');

        set_time_limit(300);
        ini_set('memory_limit', '1024M');

        // Expand via HL7 public terminology service
        $endpoint = ($package === 'FHIR R5' ? 'r5' : 'r4');
        $expandUrl = "https://tx.fhir.org/{$endpoint}/ValueSet/\$expand?url=" . urlencode($vs['url']) . "&count=50000";
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/fhir+json\r\nUser-Agent: REDCap-Clinical-Terminology-Mapper/1.0\r\n",
                'timeout' => 90,
                'ignore_errors' => true
            ]
        ]);

        $raw = @file_get_contents($expandUrl, false, $ctx);
        if (!$raw) {
            return ['success' => false, 'error' => 'Could not connect to HL7 FHIR Terminology Server (tx.fhir.org).'];
        }

        $json = json_decode($raw, true);
        if (!$json || !isset($json['expansion']['contains'])) {
            $msg = $json['issue'][0]['details']['text'] ?? 'The terminology server was unable to expand this ValueSet.';
            return ['success' => false, 'error' => $msg];
        }

        $contains = $json['expansion']['contains'];
        if (empty($contains)) {
            return ['success' => false, 'error' => 'The terminology server returned 0 concepts for this ValueSet.'];
        }

        // Insert new concepts and update database
        $db->beginTransaction();
        try {
            $delConcepts = $db->prepare("DELETE FROM concepts WHERE valueset_id = ? AND package = ?");
            $delConcepts->execute([$id, $package]);

            $cStmt = $db->prepare("
                INSERT INTO concepts (valueset_id, package, system, code, display, definition, sort_order)
                VALUES (:vs_id, :package, :system, :code, :display, :definition, :sort_order)
            ");
            $order = 0;
            $seen = [];
            foreach ($contains as $c) {
                $code = (string)($c['code'] ?? '');
                if ($code === '') continue;
                $sys = (string)($c['system'] ?? '');
                $sig = $sys . '::' . $code;
                if (isset($seen[$sig])) continue;
                $seen[$sig] = true;

                $cStmt->execute([
                    ':vs_id' => $id,
                    ':package' => $package,
                    ':system' => $sys,
                    ':code' => $code,
                    ':display' => (string)($c['display'] ?? $code),
                    ':definition' => (string)($c['display'] ?? $code),
                    ':sort_order' => $order++
                ]);
            }

            // Update concept_count
            $cntStmt = $db->prepare("UPDATE valuesets SET concept_count = (SELECT COUNT(*) FROM concepts WHERE valueset_id = ? AND package = ?) WHERE id = ? AND package = ?");
            $cntStmt->execute([$id, $package, $id, $package]);

            // Refresh FTS for this valueset
            $delFts = $db->prepare("DELETE FROM concepts_fts WHERE valueset_id = ? AND package = ?");
            $delFts->execute([$id, $package]);
            $insFts = $db->prepare("
                INSERT INTO concepts_fts (code, display, valueset_id, package)
                SELECT code, display, valueset_id, package FROM concepts WHERE valueset_id = ? AND package = ?
            ");
            $insFts->execute([$id, $package]);

            $db->commit();

            $newCountStmt = $db->prepare("SELECT concept_count FROM valuesets WHERE id = ? AND package = ?");
            $newCountStmt->execute([$id, $package]);
            $newCount = (int)$newCountStmt->fetchColumn();

            return [
                'success' => true,
                'id' => $id,
                'package' => $package,
                'previous_count' => (int)($vs['concept_count'] ?? 1000),
                'new_count' => $newCount,
                'added_count' => count($seen)
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['success' => false, 'error' => 'Database update error: ' . $e->getMessage()];
        }
    }

    public function syncLoincFromApi(array $payload): array
    {
        set_time_limit(300);
        ini_set('memory_limit', '1024M');

        $username = trim((string)($payload['username'] ?? ''));
        $password = (string)($payload['password'] ?? '');

        if ($username === '' || $password === '') {
            return ['success' => false, 'error' => 'LOINC username and password are required.'];
        }

        $auth = base64_encode($username . ':' . $password);

        // Helper to perform authenticated HTTP requests to fhir.loinc.org
        $queryLoinc = function(string $url) use ($auth): array {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'header' => "Authorization: Basic {$auth}\r\n" .
                                "Accept: application/fhir+json, application/json\r\n" .
                                "User-Agent: REDCap-Clinical-Terminology-Mapper/1.0\r\n",
                    'timeout' => 90,
                    'ignore_errors' => true
                ]
            ]);

            $raw = @file_get_contents($url, false, $ctx);
            if ($raw === false) {
                return ['success' => false, 'error' => 'Unable to connect to https://fhir.loinc.org. Please check network connectivity.'];
            }

            $statusCode = 0;
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $header) {
                    if (preg_match('/^HTTP\/\d\.\d\s+(\d+)/i', $header, $matches)) {
                        $statusCode = (int)$matches[1];
                        break;
                    }
                }
            }

            if ($statusCode === 401 || $statusCode === 403) {
                return [
                    'success' => false,
                    'auth_failed' => true,
                    'error' => 'Authentication failed (HTTP ' . $statusCode . '). Invalid LOINC username or password. Please verify your credentials at loinc.org.'
                ];
            }

            $json = json_decode($raw, true);
            if (!$json || !is_array($json)) {
                return ['success' => false, 'error' => 'Invalid JSON response from LOINC server (HTTP ' . $statusCode . ').'];
            }

            if (($json['resourceType'] ?? '') === 'OperationOutcome') {
                $msg = $json['issue'][0]['diagnostics'] ?? $json['issue'][0]['details']['text'] ?? 'LOINC expansion failed';
                return ['success' => false, 'error' => $msg];
            }

            return ['success' => true, 'data' => $json];
        };

        // Recursive concept extractor for flat or nested FHIR contains arrays
        $extractConcepts = function(array $items) use (&$extractConcepts): array {
            $results = [];
            foreach ($items as $item) {
                if (!empty($item['code'])) {
                    $results[] = [
                        'code' => (string)$item['code'],
                        'display' => (string)($item['display'] ?? $item['code']),
                        'system' => (string)($item['system'] ?? 'http://loinc.org')
                    ];
                }
                if (!empty($item['contains']) && is_array($item['contains'])) {
                    $results = array_merge($results, $extractConcepts($item['contains']));
                }
            }
            return $results;
        };

        // Candidate subsets on fhir.loinc.org
        $targets = [
            [
                'id' => 'top-observations',
                'name' => 'LOINCTopObservations',
                'title' => 'LOINC Top Observations',
                'desc' => 'Top common laboratory observations accounting for ~99% of routine clinical lab volume.',
                'canonical' => 'http://loinc.org/vs/top-observations',
                'candidates' => [
                    'https://fhir.loinc.org/ValueSet/$expand?url=http://loinc.org/vs/top-observations&count=5000',
                    'https://fhir.loinc.org/ValueSet/$expand?url=' . urlencode('http://loinc.org/vs/top-observations') . '&count=5000',
                    'https://fhir.loinc.org/ValueSet/$expand?url=http://loinc.org/vs/top-observations',
                    'https://fhir.loinc.org/ValueSet/top-observations/$expand?count=5000',
                    'https://fhir.loinc.org/ValueSet/top-observations/$expand',
                    'https://fhir.loinc.org/ValueSet/$expand?url=http://loinc.org/vs/top-2000-lab-observations-us&count=5000',
                    'https://fhir.loinc.org/ValueSet/$expand?url=http://loinc.org/vs/top-2000-lab-observations-us'
                ]
            ],
            [
                'id' => 'top-lab-orders',
                'name' => 'LOINCTopLabOrders',
                'title' => 'LOINC Top Lab Orders',
                'desc' => 'Top universal laboratory orders and panels.',
                'canonical' => 'http://loinc.org/vs/top-lab-orders',
                'candidates' => [
                    'https://fhir.loinc.org/ValueSet/$expand?url=http://loinc.org/vs/top-lab-orders&count=5000',
                    'https://fhir.loinc.org/ValueSet/$expand?url=' . urlencode('http://loinc.org/vs/top-lab-orders') . '&count=5000',
                    'https://fhir.loinc.org/ValueSet/$expand?url=http://loinc.org/vs/top-lab-orders',
                    'https://fhir.loinc.org/ValueSet/top-lab-orders/$expand?count=5000',
                    'https://fhir.loinc.org/ValueSet/top-lab-orders/$expand'
                ]
            ]
        ];

        $savedValueSets = [];
        $lastError = 'Failed to find matching value set';

        foreach ($targets as $target) {
            foreach ($target['candidates'] as $candidateUrl) {
                $res = $queryLoinc($candidateUrl);
                if (!empty($res['auth_failed'])) {
                    return ['success' => false, 'error' => $res['error']];
                }
                if ($res['success'] && isset($res['data']['expansion']['contains'])) {
                    $concepts = $extractConcepts($res['data']['expansion']['contains']);
                    if (!empty($concepts)) {
                        $savedValueSets[] = [
                            'id' => $target['id'],
                            'name' => $target['name'],
                            'title' => $res['data']['title'] ?? $target['title'],
                            'description' => $res['data']['description'] ?? $target['desc'],
                            'url' => $res['data']['url'] ?? $target['canonical'],
                            'version' => $res['data']['version'] ?? date('Y-m-d'),
                            'concepts' => $concepts
                        ];
                        break; // Successfully expanded this target, move to next
                    }
                } elseif (!empty($res['error'])) {
                    $lastError = $res['error'];
                }
            }
        }

        if (empty($savedValueSets)) {
            return ['success' => false, 'error' => 'LOINC FHIR Error: ' . $lastError];
        }

        $dbPath = __DIR__ . '/fhir_valuesets.sqlite';
        if (!file_exists($dbPath)) {
            return ['success' => false, 'error' => 'Terminology database does not exist. Please build the database first.'];
        }

        if (!is_writable($dbPath)) {
            @chmod($dbPath, 0666);
        }

        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode = WAL;');

        $db->beginTransaction();

        $db->exec("DELETE FROM concepts WHERE package = 'LOINC'");
        $db->exec("DELETE FROM valuesets WHERE package = 'LOINC'");

        $vsStmt = $db->prepare("
            INSERT INTO valuesets (id, package, url, urn, name, title, description, version, status, concept_count)
            VALUES (:id, :package, :url, NULL, :name, :title, :description, :version, 'active', :concept_count)
        ");

        $cStmt = $db->prepare("
            INSERT INTO concepts (valueset_id, package, system, code, display, definition, sort_order)
            VALUES (:vs_id, :package, :system, :code, :display, :definition, :sort_order)
        ");

        $totalConceptCount = 0;
        foreach ($savedValueSets as $item) {
            $conceptCount = count($item['concepts']);
            $vsStmt->execute([
                ':id' => $item['id'],
                ':package' => 'LOINC',
                ':url' => $item['url'],
                ':name' => $item['name'],
                ':title' => $item['title'],
                ':description' => $item['description'],
                ':version' => $item['version'],
                ':concept_count' => $conceptCount
            ]);

            $order = 0;
            foreach ($item['concepts'] as $c) {
                $cStmt->execute([
                    ':vs_id' => $item['id'],
                    ':package' => 'LOINC',
                    ':system' => $c['system'],
                    ':code' => $c['code'],
                    ':display' => $c['display'],
                    ':definition' => $c['display'],
                    ':sort_order' => $order++
                ]);
                $totalConceptCount++;
            }
        }

        $db->commit();

        try {
            $db->exec("
                DELETE FROM valuesets_fts;
                INSERT INTO valuesets_fts (id, package, title, name, description, url, urn)
                SELECT id, package, title, name, description, url, urn FROM valuesets;

                DELETE FROM concepts_fts;
                INSERT INTO concepts_fts (valueset_id, package, code, display)
                SELECT valueset_id, package, code, display FROM concepts;

                INSERT INTO valuesets_fts(valuesets_fts) VALUES('optimize');
                INSERT INTO concepts_fts(concepts_fts) VALUES('optimize');
            ");
        } catch (Throwable $e) {}

        return [
            'success' => true,
            'concept_count' => $totalConceptCount,
            'valueset_count' => count($savedValueSets),
            'package' => 'LOINC'
        ];
    }

    public function purgeLoinc(): array
    {
        $dbPath = __DIR__ . '/fhir_valuesets.sqlite';
        if (!file_exists($dbPath)) {
            return ['success' => false, 'error' => 'Database does not exist.'];
        }

        if (!is_writable($dbPath)) {
            @chmod($dbPath, 0666);
        }

        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode = WAL;');

        $db->beginTransaction();
        $db->exec("DELETE FROM concepts WHERE package = 'LOINC'");
        $db->exec("DELETE FROM valuesets WHERE package = 'LOINC'");
        $db->commit();

        try {
            $db->exec("
                DELETE FROM valuesets_fts;
                INSERT INTO valuesets_fts (id, package, title, name, description, url, urn)
                SELECT id, package, title, name, description, url, urn FROM valuesets;

                DELETE FROM concepts_fts;
                INSERT INTO concepts_fts (valueset_id, package, code, display)
                SELECT valueset_id, package, code, display FROM concepts;

                INSERT INTO valuesets_fts(valuesets_fts) VALUES('optimize');
                INSERT INTO concepts_fts(concepts_fts) VALUES('optimize');
            ");
        } catch (Throwable $e) {}

        return ['success' => true];
    }
}
