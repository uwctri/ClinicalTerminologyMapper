<?php

declare(strict_types=1);

namespace UWMadison\ClinicalTerminologyMapper;

/**
 * Static ValueSet Size Catalog for large / capped clinical ontologies.
 * 
 * Only catalogs large or capped ValueSets (>= 1,000 concepts) that exceed or approach
 * REDCap multiple-choice form limits, or whose remote expansion returns truncated
 * responses (>3,000, >10,000, or full unconstrained code systems like LOINC 257,267).
 * 
 * Standard small ValueSets (e.g. birthsex 3, gender 4, marital status 11, OMB race 7)
 * are already fully materialized in the local database and are excluded from this catalog.
 */
class ValueSetSizeCatalog
{
    /**
     * Authoritative catalog entries for large/capped ValueSets.
     * Strictly indexed by package and version.
     */
    private const CATALOG = [
        // ==========================================
        // Base HL7 FHIR R4 (v4.0.1) - Large & Capped Sets
        // ==========================================
        [
            'id' => 'consent-content-code',
            'url' => 'http://hl7.org/fhir/ValueSet/consent-content-code',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 257267,
            'is_exact' => true,
            'can_expand' => false,
            'source' => 'Regenstrief LOINC Master Database',
            'notes' => 'Unconstrained include of entire LOINC database (257,267 concepts)'
        ],
        [
            'id' => 'observation-codes',
            'url' => 'http://hl7.org/fhir/ValueSet/observation-codes',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 257267,
            'is_exact' => true,
            'can_expand' => false,
            'source' => 'Regenstrief LOINC Master Database',
            'notes' => 'Unconstrained LOINC include (all observation codes)'
        ],
        [
            'id' => 'report-codes',
            'url' => 'http://hl7.org/fhir/ValueSet/report-codes',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 257267,
            'is_exact' => true,
            'can_expand' => false,
            'source' => 'Regenstrief LOINC Master Database',
            'notes' => 'Unconstrained LOINC include (diagnostic reports)'
        ],
        [
            'id' => 'condition-code',
            'url' => 'http://hl7.org/fhir/ValueSet/condition-code',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 112410,
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International (<< 404684003)',
            'notes' => 'All active SNOMED CT Clinical findings (~112,410 concepts)'
        ],
        [
            'id' => 'condition-outcome',
            'url' => 'http://hl7.org/fhir/ValueSet/condition-outcome',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 112410,
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International (<< 404684003)',
            'notes' => 'All active SNOMED CT Clinical findings'
        ],
        [
            'id' => 'procedure-code',
            'url' => 'http://hl7.org/fhir/ValueSet/procedure-code',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 62800,
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International (<< 71388002)',
            'notes' => 'All active SNOMED CT Procedures (~62,800 concepts)'
        ],
        [
            'id' => 'procedure-reason',
            'url' => 'http://hl7.org/fhir/ValueSet/procedure-reason',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 112410,
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International',
            'notes' => 'SNOMED CT Clinical findings'
        ],
        [
            'id' => 'manifestation-or-symptom',
            'url' => 'http://hl7.org/fhir/ValueSet/manifestation-or-symptom',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 112410,
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International',
            'notes' => 'SNOMED CT Clinical findings'
        ],
        [
            'id' => 'encounter-reason',
            'url' => 'http://hl7.org/fhir/ValueSet/encounter-reason',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 112410,
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International',
            'notes' => 'SNOMED CT Clinical findings'
        ],
        [
            'id' => 'flag-code',
            'url' => 'http://hl7.org/fhir/ValueSet/flag-code',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 112410,
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International',
            'notes' => 'SNOMED CT Clinical findings'
        ],
        [
            'id' => 'allergyintolerance-code',
            'url' => 'http://hl7.org/fhir/ValueSet/allergyintolerance-code',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 14250,
            'is_exact' => false,
            'can_expand' => true,
            'source' => 'SNOMED CT Substance & Finding',
            'notes' => 'SNOMED CT substance and clinical findings (~14,250 concepts)'
        ],
        [
            'id' => 'doc-typecodes',
            'url' => 'http://hl7.org/fhir/ValueSet/doc-typecodes',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 12569,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'LOINC Document Ontology',
            'notes' => 'LOINC clinical document types (12,569 entries)'
        ],
        [
            'id' => 'c80-doc-typecodes',
            'url' => 'http://hl7.org/fhir/ValueSet/c80-doc-typecodes',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 12569,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'HITSP C80 / LOINC Document Types',
            'notes' => 'HITSP C80 document type codes (12,569 entries)'
        ],
        [
            'id' => 'device-type',
            'url' => 'http://hl7.org/fhir/ValueSet/device-type',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 8420,
            'is_exact' => false,
            'can_expand' => true,
            'source' => 'SNOMED CT Physical Object',
            'notes' => 'SNOMED CT medical device hierarchy (~8,420 concepts)'
        ],
        [
            'id' => 'device-kind',
            'url' => 'http://hl7.org/fhir/ValueSet/device-kind',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 8420,
            'is_exact' => false,
            'can_expand' => true,
            'source' => 'SNOMED CT Physical Object',
            'notes' => 'SNOMED CT medical devices'
        ],
        [
            'id' => 'all-languages',
            'url' => 'http://hl7.org/fhir/ValueSet/all-languages',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 7849,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'ISO 639-1 / 639-2 Registry',
            'notes' => 'Complete international language registry (7,849 entries)'
        ],
        [
            'id' => 'observation-methods',
            'url' => 'http://hl7.org/fhir/ValueSet/observation-methods',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 4200,
            'is_exact' => false,
            'can_expand' => true,
            'source' => 'SNOMED CT Evaluation Procedures',
            'notes' => 'Clinical measurement techniques (~4,200 concepts; safe to expand)'
        ],
        [
            'id' => 'animal-breeds',
            'url' => 'http://hl7.org/fhir/ValueSet/animal-breeds',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 2370,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'HL7 FHIR R4 Conformance Pack',
            'notes' => 'SNOMED CT veterinary breed concepts (2,370 concepts)'
        ],
        [
            'id' => 'food-type',
            'url' => 'http://hl7.org/fhir/ValueSet/food-type',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 1848,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'HL7 FHIR R4 Conformance Pack',
            'notes' => 'SNOMED CT food substances (1,848 concepts)'
        ],
        [
            'id' => 'condition-stage-type',
            'url' => 'http://hl7.org/fhir/ValueSet/condition-stage-type',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 1655,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'HL7 FHIR R4 Conformance Pack',
            'notes' => 'SNOMED CT staging and assessment types (1,655 concepts)'
        ],
        [
            'id' => 'ucum-common',
            'url' => 'http://hl7.org/fhir/ValueSet/ucum-common',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 1357,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'HL7 FHIR R4 Common UCUM Units',
            'notes' => 'Common laboratory UCUM units of measure (1,357 concepts)'
        ],
        [
            'id' => 'questionnaire-questions',
            'url' => 'http://hl7.org/fhir/ValueSet/questionnaire-questions',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 257267,
            'is_exact' => true,
            'can_expand' => false,
            'source' => 'Regenstrief LOINC Master Database',
            'notes' => 'All active LOINC question and observation codes (257,267 concepts)'
        ],
        [
            'id' => 'clinical-findings',
            'url' => 'http://hl7.org/fhir/ValueSet/clinical-findings',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 112410,
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International (<< 404684003)',
            'notes' => 'All active SNOMED CT Clinical findings (~112,410 concepts)'
        ],
        [
            'id' => 'adverse-event-type',
            'url' => 'http://hl7.org/fhir/ValueSet/adverse-event-type',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 112410,
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International (<< 404684003)',
            'notes' => 'All active SNOMED CT Clinical findings (~112,410 concepts)'
        ],
        [
            'id' => 'body-site',
            'url' => 'http://hl7.org/fhir/ValueSet/body-site',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International (<< 442083009)',
            'notes' => 'SNOMED CT Anatomical or acquired body site (>3,000 concepts)'
        ],
        [
            'id' => 'bodystructure-code',
            'url' => 'http://hl7.org/fhir/ValueSet/bodystructure-code',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International (<< 442083009)',
            'notes' => 'SNOMED CT anatomical structures (>3,000 concepts)'
        ],
        [
            'id' => 'approach-site-codes',
            'url' => 'http://hl7.org/fhir/ValueSet/approach-site-codes',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International',
            'notes' => 'SNOMED CT approach sites (>3,000 concepts)'
        ],
        [
            'id' => 'medication-as-needed-reason',
            'url' => 'http://hl7.org/fhir/ValueSet/medication-as-needed-reason',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International',
            'notes' => 'SNOMED CT clinical findings & procedure reasons (>3,000 concepts)'
        ],
        [
            'id' => 'medication-codes',
            'url' => 'http://hl7.org/fhir/ValueSet/medication-codes',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International / RxNorm',
            'notes' => 'SNOMED CT substances and clinical products (>3,000 concepts)'
        ],
        [
            'id' => 'supply-item',
            'url' => 'http://hl7.org/fhir/ValueSet/supply-item',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International',
            'notes' => 'SNOMED CT physical objects & devices (>3,000 concepts)'
        ],
        [
            'id' => 'substance-code',
            'url' => 'http://hl7.org/fhir/ValueSet/substance-code',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International (<< 105590001)',
            'notes' => 'SNOMED CT substance hierarchy (>3,000 concepts)'
        ],
        [
            'id' => 'ucum-units',
            'url' => 'http://hl7.org/fhir/ValueSet/ucum-units',
            'package' => 'FHIR R4',
            'version' => '4.0.1',
            'total' => 'Infinite grammar',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'Unified Code for Units of Measure (UCUM)',
            'notes' => 'Formal mathematical grammar with infinite valid expressions'
        ],
        [
            'id' => 'v3-ObservationType',
            'url' => 'http://terminology.hl7.org/ValueSet/v3-ObservationType',
            'package' => 'FHIR R4',
            'version' => '2014-03-26',
            'total' => '> 10,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'HL7 v3 Code System ObservationType',
            'notes' => 'HL7 v3 Observation Type hierarchy (>10,000 concepts)'
        ],

        // ==========================================
        // Base HL7 FHIR R5 (v5.0.0) - Large & Capped Sets
        // ==========================================
        [
            'id' => 'observation-codes',
            'url' => 'http://hl7.org/fhir/ValueSet/observation-codes',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 257267,
            'is_exact' => true,
            'can_expand' => false,
            'source' => 'Regenstrief LOINC Master Database',
            'notes' => 'All active LOINC laboratory and clinical observation codes (257,267 concepts)'
        ],
        [
            'id' => 'report-codes',
            'url' => 'http://hl7.org/fhir/ValueSet/report-codes',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 257267,
            'is_exact' => true,
            'can_expand' => false,
            'source' => 'Regenstrief LOINC Master Database',
            'notes' => 'All active LOINC diagnostic report codes (257,267 concepts)'
        ],
        [
            'id' => 'consent-content-code',
            'url' => 'http://hl7.org/fhir/ValueSet/consent-content-code',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 257267,
            'is_exact' => true,
            'can_expand' => false,
            'source' => 'Regenstrief LOINC Master Database',
            'notes' => 'All active LOINC document and observation codes (257,267 concepts)'
        ],
        [
            'id' => 'questionnaire-questions',
            'url' => 'http://hl7.org/fhir/ValueSet/questionnaire-questions',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 257267,
            'is_exact' => true,
            'can_expand' => false,
            'source' => 'Regenstrief LOINC Master Database',
            'notes' => 'All active LOINC question and observation codes (257,267 concepts)'
        ],
        [
            'id' => 'doc-typecodes',
            'url' => 'http://hl7.org/fhir/ValueSet/doc-typecodes',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 12569,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'LOINC Document Ontology',
            'notes' => 'LOINC clinical document types (12,569 entries)'
        ],
        [
            'id' => 'condition-code',
            'url' => 'http://hl7.org/fhir/ValueSet/condition-code',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 112410,
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International (<< 404684003)',
            'notes' => 'All active SNOMED CT Clinical findings (~112,410 concepts)'
        ],
        [
            'id' => 'condition-outcome',
            'url' => 'http://hl7.org/fhir/ValueSet/condition-outcome',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 112410,
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International (<< 404684003)',
            'notes' => 'All active SNOMED CT Clinical findings'
        ],
        [
            'id' => 'clinical-findings',
            'url' => 'http://hl7.org/fhir/ValueSet/clinical-findings',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 112410,
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International (<< 404684003)',
            'notes' => 'All active SNOMED CT Clinical findings'
        ],
        [
            'id' => 'encounter-reason',
            'url' => 'http://hl7.org/fhir/ValueSet/encounter-reason',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 112410,
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International',
            'notes' => 'SNOMED CT Clinical findings'
        ],
        [
            'id' => 'flag-code',
            'url' => 'http://hl7.org/fhir/ValueSet/flag-code',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 112410,
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International',
            'notes' => 'SNOMED CT Clinical findings'
        ],
        [
            'id' => 'manifestation-or-symptom',
            'url' => 'http://hl7.org/fhir/ValueSet/manifestation-or-symptom',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 112410,
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International',
            'notes' => 'SNOMED CT Clinical findings'
        ],
        [
            'id' => 'procedure-reason',
            'url' => 'http://hl7.org/fhir/ValueSet/procedure-reason',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 112410,
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International',
            'notes' => 'SNOMED CT Clinical findings'
        ],
        [
            'id' => 'allergyintolerance-code',
            'url' => 'http://hl7.org/fhir/ValueSet/allergyintolerance-code',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 14250,
            'is_exact' => false,
            'can_expand' => true,
            'source' => 'SNOMED CT Substance & Finding',
            'notes' => 'SNOMED CT substance and clinical findings (~14,250 concepts)'
        ],
        [
            'id' => 'procedure-code',
            'url' => 'http://hl7.org/fhir/ValueSet/procedure-code',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 62800,
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International (<< 71388002)',
            'notes' => 'All active SNOMED CT Procedures (~62,800 concepts)'
        ],
        [
            'id' => 'devicemetric-type',
            'url' => 'http://hl7.org/fhir/ValueSet/devicemetric-type',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 48307,
            'is_exact' => true,
            'can_expand' => false,
            'source' => 'IEEE 11073-10101 / Rosette',
            'notes' => 'Device metric and component types (48,307 concepts)'
        ],
        [
            'id' => 'icd-10',
            'url' => 'http://hl7.org/fhir/ValueSet/icd-10',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 13831,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'WHO ICD-10 Classification',
            'notes' => 'International Classification of Diseases, 10th Revision (13,831 codes)'
        ],
        [
            'id' => 'all-languages',
            'url' => 'http://hl7.org/fhir/ValueSet/all-languages',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 7849,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'ISO 639-1 / 639-2 Registry',
            'notes' => 'Complete international language code registry'
        ],
        [
            'id' => 'medicinal-product-classification',
            'url' => 'http://hl7.org/fhir/ValueSet/medicinal-product-classification',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 6996,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'WHO ATC Classification',
            'notes' => 'Anatomical Therapeutic Chemical Classification System (6,996 codes)'
        ],
        [
            'id' => 'observation-methods',
            'url' => 'http://hl7.org/fhir/ValueSet/observation-methods',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 4200,
            'is_exact' => false,
            'can_expand' => true,
            'source' => 'SNOMED CT Evaluation Procedures',
            'notes' => 'Observation methods (4,200 concepts, 2,015 in R5 expansion)'
        ],
        [
            'id' => 'edible-substance-type',
            'url' => 'http://hl7.org/fhir/ValueSet/edible-substance-type',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 2282,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'SNOMED CT Food Substances',
            'notes' => 'SNOMED CT food and edible substances (2,282 concepts)'
        ],
        [
            'id' => 'nutrition-product-category',
            'url' => 'http://hl7.org/fhir/ValueSet/nutrition-product-category',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 1963,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'SNOMED CT Food & Nutritional Products',
            'notes' => 'Nutritional product categories (1,963 concepts)'
        ],
        [
            'id' => 'food-type',
            'url' => 'http://hl7.org/fhir/ValueSet/food-type',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 1848,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'SNOMED CT Food Types',
            'notes' => 'SNOMED CT food substances (1,848 concepts)'
        ],
        [
            'id' => 'jurisdiction',
            'url' => 'http://hl7.org/fhir/ValueSet/jurisdiction',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 1776,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'ISO 3166-1 / UN M49 Countries & Regions',
            'notes' => 'Jurisdiction and country subdivisions (1,776 codes)'
        ],
        [
            'id' => 'condition-stage-type',
            'url' => 'http://hl7.org/fhir/ValueSet/condition-stage-type',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 1655,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'SNOMED CT Assessment & Staging',
            'notes' => 'SNOMED CT staging and assessment types (1,655 concepts)'
        ],
        [
            'id' => 'v3-ActCode',
            'url' => 'http://terminology.hl7.org/ValueSet/v3-ActCode',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 1302,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'HL7 v3 Code System ActCode',
            'notes' => 'v3 ActCode hierarchy (1,302 concepts)'
        ],
        [
            'id' => 'not-consumed-reason',
            'url' => 'http://hl7.org/fhir/ValueSet/not-consumed-reason',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 1006,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'SNOMED CT Clinical Findings',
            'notes' => 'Reasons food item was not consumed (1,006 concepts)'
        ],
        [
            'id' => 'referenced-item-category',
            'url' => 'http://hl7.org/fhir/ValueSet/referenced-item-category',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 1005,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'SNOMED CT Physical Objects',
            'notes' => 'Referenced item categories (1,005 concepts)'
        ],
        [
            'id' => 'consent-content-class',
            'url' => 'http://hl7.org/fhir/ValueSet/consent-content-class',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 1002,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'HL7 Consent Content Class',
            'notes' => 'Consent content classification (1,002 concepts)'
        ],
        [
            'id' => 'adverse-event-contributing-factor',
            'url' => 'http://hl7.org/fhir/ValueSet/adverse-event-contributing-factor',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT Findings & Situations',
            'notes' => 'Adverse event contributing factor hierarchy (6,624 concepts in R5 expansion)'
        ],
        [
            'id' => 'adverse-event-supporting-info',
            'url' => 'http://hl7.org/fhir/ValueSet/adverse-event-supporting-info',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT Findings & Situations',
            'notes' => 'Adverse event supporting information (6,624 concepts in R5 expansion)'
        ],
        [
            'id' => 'adverse-event-preventive-action',
            'url' => 'http://hl7.org/fhir/ValueSet/adverse-event-preventive-action',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT Procedures & Regimes',
            'notes' => 'Adverse event preventive actions (4,047 concepts in R5 expansion)'
        ],
        [
            'id' => 'adverse-event-mitigating-action',
            'url' => 'http://hl7.org/fhir/ValueSet/adverse-event-mitigating-action',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT Procedures & Regimes',
            'notes' => 'Adverse event mitigating actions (3,985 concepts in R5 expansion)'
        ],
        [
            'id' => 'use-context',
            'url' => 'http://hl7.org/fhir/ValueSet/use-context',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT Context of Use',
            'notes' => 'Context of use concepts (3,821 concepts in R5 expansion)'
        ],
        [
            'id' => 'disease-symptom-procedure',
            'url' => 'http://hl7.org/fhir/ValueSet/disease-symptom-procedure',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT Findings & Procedures',
            'notes' => 'Disease symptom procedure hierarchy (>3,000 concepts)'
        ],
        [
            'id' => 'therapy',
            'url' => 'http://hl7.org/fhir/ValueSet/therapy',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT Procedures & Therapies',
            'notes' => 'Clinical therapy concepts (>3,000 concepts)'
        ],
        [
            'id' => 'body-site',
            'url' => 'http://hl7.org/fhir/ValueSet/body-site',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International (<< 442083009)',
            'notes' => 'SNOMED CT Anatomical or acquired body site (>3,000 concepts)'
        ],
        [
            'id' => 'bodystructure-code',
            'url' => 'http://hl7.org/fhir/ValueSet/bodystructure-code',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International (<< 442083009)',
            'notes' => 'SNOMED CT anatomical structures (>3,000 concepts)'
        ],
        [
            'id' => 'approach-site-codes',
            'url' => 'http://hl7.org/fhir/ValueSet/approach-site-codes',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International',
            'notes' => 'SNOMED CT approach sites (>3,000 concepts)'
        ],
        [
            'id' => 'medication-as-needed-reason',
            'url' => 'http://hl7.org/fhir/ValueSet/medication-as-needed-reason',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International',
            'notes' => 'SNOMED CT clinical findings & procedure reasons (>3,000 concepts)'
        ],
        [
            'id' => 'medication-codes',
            'url' => 'http://hl7.org/fhir/ValueSet/medication-codes',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International / RxNorm',
            'notes' => 'SNOMED CT substances and clinical products (>3,000 concepts)'
        ],
        [
            'id' => 'supply-item',
            'url' => 'http://hl7.org/fhir/ValueSet/supply-item',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International',
            'notes' => 'SNOMED CT physical objects & devices (>3,000 concepts)'
        ],
        [
            'id' => 'substance-code',
            'url' => 'http://hl7.org/fhir/ValueSet/substance-code',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT International (<< 105590001)',
            'notes' => 'SNOMED CT substance hierarchy (>3,000 concepts)'
        ],
        [
            'id' => 'substance-codes',
            'url' => 'http://hl7.org/fhir/ValueSet/substance-codes',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT Substance Codes',
            'notes' => 'SNOMED CT substance hierarchy (>3,000 concepts)'
        ],
        [
            'id' => 'interactant',
            'url' => 'http://hl7.org/fhir/ValueSet/interactant',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT Substances & Products',
            'notes' => 'Clinical interactants (>3,000 concepts)'
        ],
        [
            'id' => 'interaction-effect',
            'url' => 'http://hl7.org/fhir/ValueSet/interaction-effect',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT Findings',
            'notes' => 'Clinical interaction effects (>3,000 concepts)'
        ],
        [
            'id' => 'interaction-management',
            'url' => 'http://hl7.org/fhir/ValueSet/interaction-management',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT Procedures',
            'notes' => 'Interaction management actions (>3,000 concepts)'
        ],
        [
            'id' => 'undesirable-effect-classification',
            'url' => 'http://hl7.org/fhir/ValueSet/undesirable-effect-classification',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT Findings',
            'notes' => 'Undesirable effect classification (>3,000 concepts)'
        ],
        [
            'id' => 'undesirable-effect-symptom',
            'url' => 'http://hl7.org/fhir/ValueSet/undesirable-effect-symptom',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => '> 3,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT Findings',
            'notes' => 'Undesirable effect symptoms (>3,000 concepts)'
        ],
        [
            'id' => 'ucum-units',
            'url' => 'http://hl7.org/fhir/ValueSet/ucum-units',
            'package' => 'FHIR R5',
            'version' => '5.0.0',
            'total' => 'Infinite grammar',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'Unified Code for Units of Measure (UCUM)',
            'notes' => 'Formal mathematical grammar with infinite valid expressions'
        ],

        // ==========================================
        // US Core Packages - Large / Capped Sets Only (>= 1,000)
        // ==========================================
        [
            'id' => 'us-core-condition-codes',
            'url' => 'http://hl7.org/fhir/us/core/ValueSet/us-core-condition-codes',
            'package' => 'US Core v3.1.1',
            'version' => '3.1.1',
            'total' => 1000,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'HL7 US Core STU 3.1.1 Expansions',
            'notes' => 'Pre-expanded top 1,000 condition subset in package'
        ],
        [
            'id' => 'us-core-condition-codes',
            'url' => 'http://hl7.org/fhir/us/core/ValueSet/us-core-condition-codes',
            'package' => 'US Core v6.1.0',
            'version' => '6.1.0',
            'total' => 1000,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'HL7 US Core STU 6.1.0 Expansions',
            'notes' => 'Pre-expanded top 1,000 condition subset in package'
        ],
        [
            'id' => 'us-core-non-laboratory-codes',
            'url' => 'http://hl7.org/fhir/us/core/ValueSet/us-core-non-laboratory-codes',
            'package' => 'US Core v6.1.0',
            'version' => '6.1.0',
            'total' => 1000,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'HL7 US Core STU 6.1.0 Expansions',
            'notes' => 'Pre-expanded top 1,000 procedure/assessment subset in package'
        ],
        [
            'id' => 'us-core-encounter-type',
            'url' => 'http://hl7.org/fhir/us/core/ValueSet/us-core-encounter-type',
            'package' => 'US Core v3.1.1',
            'version' => '3.1.1',
            'total' => 12569,
            'is_exact' => false,
            'can_expand' => true,
            'source' => 'CPT & SNOMED Encounter Codes',
            'notes' => 'Comprehensive encounter classification codes'
        ],
        [
            'id' => 'us-core-encounter-type',
            'url' => 'http://hl7.org/fhir/us/core/ValueSet/us-core-encounter-type',
            'package' => 'US Core v6.1.0',
            'version' => '6.1.0',
            'total' => 12569,
            'is_exact' => false,
            'can_expand' => true,
            'source' => 'CPT & SNOMED Encounter Codes',
            'notes' => 'Comprehensive encounter classification codes'
        ],
        [
            'id' => 'us-core-procedure-code',
            'url' => 'http://hl7.org/fhir/us/core/ValueSet/us-core-procedure-code',
            'package' => 'US Core v3.1.1',
            'version' => '3.1.1',
            'total' => 120000,
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT + CPT + ICD-10-PCS + HCPCS',
            'notes' => 'All US Core clinical procedure codes (~120,000 entries)'
        ],
        [
            'id' => 'us-core-procedure-code',
            'url' => 'http://hl7.org/fhir/us/core/ValueSet/us-core-procedure-code',
            'package' => 'US Core v6.1.0',
            'version' => '6.1.0',
            'total' => 120000,
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT + CPT + ICD-10-PCS + HCPCS',
            'notes' => 'All US Core clinical procedure codes (~120,000 entries)'
        ],
        [
            'id' => 'us-core-medication-codes',
            'url' => 'http://hl7.org/fhir/us/core/ValueSet/us-core-medication-codes',
            'package' => 'US Core v3.1.1',
            'version' => '3.1.1',
            'total' => 45000,
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'NLM RxNorm Clinical Drug Set',
            'notes' => 'All RxNorm active clinical drugs and packs (~45,000 entries)'
        ],
        [
            'id' => 'us-core-diagnosticreport-lab-codes',
            'url' => 'http://hl7.org/fhir/us/core/ValueSet/us-core-diagnosticreport-lab-codes',
            'package' => 'US Core v3.1.1',
            'version' => '3.1.1',
            'total' => 257267,
            'is_exact' => true,
            'can_expand' => false,
            'source' => 'Regenstrief LOINC Lab Test Hierarchy',
            'notes' => 'Full LOINC laboratory test inclusion'
        ],
        [
            'id' => 'us-core-laboratory-test-codes',
            'url' => 'http://hl7.org/fhir/us/core/ValueSet/us-core-laboratory-test-codes',
            'package' => 'US Core v6.1.0',
            'version' => '6.1.0',
            'total' => 257267,
            'is_exact' => true,
            'can_expand' => false,
            'source' => 'Regenstrief LOINC Lab Test Hierarchy',
            'notes' => 'Full LOINC laboratory test inclusion'
        ],

        // ==========================================
        // Regenstrief LOINC Top Common Subsets (>= 1,000)
        // ==========================================
        [
            'id' => 'top-observations',
            'url' => 'http://loinc.org/vs/top-observations',
            'package' => 'LOINC',
            'version' => null,
            'total' => 2000,
            'is_exact' => true,
            'can_expand' => true,
            'source' => 'Regenstrief Top 2,000 Common Lab Observations',
            'notes' => 'Empirically covers ~99% of all routine clinical lab test volume in US health systems'
        ],
        // ==========================================
        // mCODE - Large & Capped Hybrid Sets
        // ==========================================
        [
            'id' => 'mcode-primary-malignant-neoplasm-disorder-vs',
            'url' => 'http://hl7.org/fhir/us/mcode/ValueSet/mcode-primary-malignant-neoplasm-disorder-vs',
            'package' => 'mCODE v3.0.0',
            'version' => '3.0.0',
            'total' => '> 12,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT + ICD-10-CM',
            'notes' => 'Contains 1,046 explicit ICD-10-CM neoplasm codes (installed) + ~12,000 SNOMED CT concepts (is-a 363346000 Malignant neoplastic disease)'
        ],
        [
            'id' => 'mcode-primary-malignant-neoplasm-disorder-vs',
            'url' => 'http://hl7.org/fhir/us/mcode/ValueSet/mcode-primary-malignant-neoplasm-disorder-vs',
            'package' => 'mCODE v4.0.0',
            'version' => '4.0.0',
            'total' => '> 12,000',
            'is_exact' => false,
            'can_expand' => false,
            'source' => 'SNOMED CT + ICD-10-CM',
            'notes' => 'Contains 1,046 explicit ICD-10-CM neoplasm codes (installed) + ~12,000 SNOMED CT concepts (is-a 363346000 Malignant neoplastic disease)'
        ]
    ];

    /**
     * Look up a ValueSet size from the catalog, strictly matching on version and/or package.
     * 
     * @param string $idOrUrl ValueSet ID or canonical URL
     * @param string|null $package Package name (e.g. 'US Core v6.1.0', 'FHIR R4')
     * @param string|null $version Exact version string (e.g. '6.1.0', '3.1.1', '4.0.1')
     * @return array|null The matched catalog entry, or null if no version match found.
     */
    public static function lookup(string $idOrUrl, ?string $package = null, ?string $version = null): ?array
    {
        $idClean = trim($idOrUrl);
        if ($idClean === '') return null;

        if (str_contains($idClean, '|')) {
            [$idClean, $parsedVer] = explode('|', $idClean, 2);
            if (!empty($parsedVer) && empty($version)) {
                $version = $parsedVer;
            }
        }

        $idBase = basename($idClean);

        foreach (self::CATALOG as $entry) {
            $idMatch = ($entry['id'] === $idClean || $entry['id'] === $idBase || $entry['url'] === $idClean);
            if (!$idMatch) continue;

            if (!empty($package) && $entry['package'] !== $package) {
                continue;
            }

            if (!empty($version) && $entry['version'] !== null && $entry['version'] !== $version) {
                continue;
            }

            if (empty($package) && empty($version)) {
                $matches = array_filter(self::CATALOG, fn($e) => $e['id'] === $idClean || $e['url'] === $idClean);
                if (count($matches) > 1) {
                    return null;
                }
            }

            return $entry;
        }

        return null;
    }

    /**
     * Get all catalog entries.
     * 
     * @return array
     */
    public static function getAllEntries(): array
    {
        return self::CATALOG;
    }
}
