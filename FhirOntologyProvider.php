<?php

namespace UWMadison\ClinicalTerminologyMapper;

use OntologyProvider;

class FhirOntologyProvider implements OntologyProvider
{
    private ?TerminologyService $terminology = null;
    private array $allowedPackages = [];

    public function __construct(?TerminologyService $terminology = null, array $allowedPackages = [])
    {
        $this->terminology = $terminology;
        $this->allowedPackages = $allowedPackages;
    }

    public function setAllowedPackages(array $packages): void
    {
        $this->allowedPackages = $packages;
    }

    public function getAllowedPackages(): array
    {
        return $this->allowedPackages;
    }

    private function getTerminology(): TerminologyService
    {
        if ($this->terminology === null) {
            $this->terminology = new TerminologyService(__DIR__ . '/fhir_valuesets.sqlite');
        }
        return $this->terminology;
    }

    public function getProviderName()
    {
        return "HL7 FHIR ValueSets";
    }

    public function getServicePrefix()
    {
        return "FHIR";
    }

    public function searchOntology($category, $search_term, $result_limit)
    {
        $limit = ($result_limit && is_numeric($result_limit)) ? (int)$result_limit : 25;
        $results = $this->getTerminology()->searchConcepts($category, $search_term ?? '', $limit, $this->allowedPackages);

        $out = [];
        foreach ($results as $row) {
            $out[(string)$row['code']] = (string)$row['display'];
        }
        return $out;
    }

    public function getLabelForValue($category, $value)
    {
        if ($value === null || $value === '') return '';
        $label = $this->getTerminology()->getLabelForConcept($category, (string)$value, $this->allowedPackages);
        return $label ?? (string)$value;
    }

    public function getOnlineDesignerSection()
    {
        $packages = !empty($this->allowedPackages) ? $this->allowedPackages : ['FHIR R4'];

        $db = $this->getTerminology()->getDb();
        $placeholders = [];
        $params = [];
        foreach ($packages as $i => $pkg) {
            $ph = ":pkg_{$i}";
            $placeholders[] = $ph;
            $params[$ph] = $pkg;
        }

        $sql = "
            SELECT id, title, package, concept_count
            FROM valuesets
            WHERE package IN (" . implode(',', $placeholders) . ") AND concept_count > 0
            ORDER BY
                CASE WHEN package LIKE 'US Core%' THEN 0 ELSE 1 END,
                package ASC,
                title ASC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $valueSets = $stmt->fetchAll();

        $hasMultiplePackages = count($packages) > 1;
        $options = "<option value=''>-- Select a FHIR ValueSet --</option>\n";

        if ($hasMultiplePackages) {
            $grouped = [];
            foreach ($valueSets as $vs) {
                $grouped[$vs['package']][] = $vs;
            }
            foreach ($grouped as $pkgName => $vsList) {
                $pkgEsc = htmlspecialchars($pkgName, ENT_QUOTES);
                $options .= "<optgroup label=\"{$pkgEsc}\">\n";
                foreach ($vsList as $vs) {
                    $val = htmlspecialchars("{$vs['id']}::{$vs['package']}", ENT_QUOTES);
                    $title = htmlspecialchars($vs['title'], ENT_QUOTES);
                    $id = htmlspecialchars($vs['id'], ENT_QUOTES);
                    $options .= "  <option value=\"{$val}\">{$title} ({$id})</option>\n";
                }
                $options .= "</optgroup>\n";
            }
        } else {
            foreach ($valueSets as $vs) {
                $val = htmlspecialchars("{$vs['id']}::{$vs['package']}", ENT_QUOTES);
                $title = htmlspecialchars($vs['title'], ENT_QUOTES);
                $id = htmlspecialchars($vs['id'], ENT_QUOTES);
                $options .= "<option value=\"{$val}\">{$title} ({$id})</option>\n";
            }
        }

        $html = <<<EOD
<script type="text/javascript">
  function FHIR_ontology_changed(service, category) {
    var newSelection = ('FHIR' === service) ? category : '';
    if (newSelection && !$('#fhir_valueset_select option[value="' + newSelection + '"]').length) {
      var matched = false;
      $('#fhir_valueset_select option').each(function() {
        if (this.value === newSelection || this.value.indexOf(newSelection + '::') === 0) {
          newSelection = this.value;
          matched = true;
          return false;
        }
      });
      if (!matched) {
        $('#fhir_valueset_select').append($('<option>', { value: newSelection, text: newSelection }));
      }
    }
    $('#fhir_valueset_select').val(newSelection);
  }
</script>
<div style="margin:5px 0 2px;">
  <b>Select FHIR ValueSet:</b>
</div>
<select id="fhir_valueset_select" name="fhir_valueset_select"
        onchange="update_ontology_selection('FHIR', this.options[this.selectedIndex].value)"
        class="x-form-text x-form-field" style="width:330px;max-width:330px;">
  {$options}
</select>
<div style="font-size:11px;color:#666;margin-top:3px;">
  Terms will autocomplete in this field using HL7 FHIR concepts.
</div>
EOD;

        return $html;
    }
}
