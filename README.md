# Clinical Terminology Mapper - REDCap External Module

## What does it do?

When importing EHR data through Clinical Data Pull (CDP) or Clinical Data Mart (CDM), REDCap expects field choices to match incoming source codes. If a project field uses default or arbitrary numeric keys (such as `1, Male \n 2, Female` instead of `male, Male \n female, Female`), REDCap flags incoming values as invalid and disables the adjudication checkbox.

Clinical Terminology Mapper inspects your project's CDIS-mapped fields and custom fields against standard clinical ontologies (HL7 FHIR R4/R5, US Core, and LOINC). It flags choice-code mismatches, shows a side-by-side diff preview, and updates the choices in REDCap metadata.

## Features

* **CDIS Auto-Detection** - Automatically detects mapped CDIS fields, identifies the canonical ValueSet, and highlights choice mismatches.
* **Custom Field Standardization** - Standardize any radio, dropdown, or checkbox field in your project, even without EHR integration.
* **Built-in Terminology Database** - Self-contained SQLite database with FHIR, US Core, and LOINC ValueSets and fast full-text search.
* **Ontology Auto-Suggest** - Can serve as an ontology provider in the Online Designer for text fields.

## Installing

You can install the module from the REDCap EM repo or drop it directly into your modules folder (i.e. `redcap/modules/clinical_terminology_mapper_v1.0.0`) and enable it in the Control Center and your project.
