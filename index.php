<?php

namespace UWMadison\ClinicalTerminologyMapper;

/** @var ClinicalTerminologyMapper $module */

// Initialize Native JSMO object
$module->initializeJavascriptModuleObject();
$initialData = $module->getInitialPageData();
?>

<link rel="stylesheet" href="<?= htmlspecialchars($module->getUrl('style.css'), ENT_QUOTES) ?>">
<div id="cdis-option-mapper-app"></div>
<script>
    window.CDIS_INITIAL_DATA = <?= json_encode($initialData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= htmlspecialchars($module->getUrl('index.js'), ENT_QUOTES) ?>"></script>