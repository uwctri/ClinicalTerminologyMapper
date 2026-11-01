document.addEventListener('DOMContentLoaded', () => {
    const module = window.ExternalModules?.UWMadison?.ClinicalTerminologyMapper;
    const appEl = document.getElementById('cdis-option-mapper-app');
    if (!appEl) return;

    const escapeHtml = (str) => String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const computeIsMatch = (field) => {
        if (!field.is_multiple_choice) return false;
        const existingCodes = Object.keys(field.current_choices || {}).map(String);
        const targetCodes = (field.target_concepts || []).map(c => String(c.code));
        if (existingCodes.length === 0 || existingCodes.length !== targetCodes.length) return false;
        const targetSet = new Set(targetCodes);
        const existingSet = new Set(existingCodes);
        if (existingSet.size !== targetSet.size) return false;
        for (const code of existingSet) {
            if (!targetSet.has(code)) return false;
        }
        return true;
    };

    const getFieldTypeLabels = (elementType, targetCount) => {
        const isCheckbox = elementType === 'checkbox' || elementType === 'advcheckbox';
        const currentTypeLabel = elementType === 'select'
            ? 'dropdown'
            : (isCheckbox ? 'checkbox' : (elementType === 'radio' ? 'radio' : (elementType || 'text')));
        const targetTypeLabel = isCheckbox ? 'checkbox' : (targetCount > 6 ? 'dropdown' : 'radio');
        return { currentTypeLabel, targetTypeLabel };
    };

    let state = {
        loading: true,
        projectId: null,
        isProduction: false,
        hasDraftMode: false,
        canModify: false,
        fields: [],
        activeModalField: null,
        modalConcepts: [],
        modalSearchQuery: '',
        modalSearchResults: null,
        modalIsSearching: false,
        modalLoadingConcepts: false,
        modalSearchScrollTop: 0,
        modalSearchTimer: null,
        defaultValuesets: [],
        isApplying: false,
        isSaving: false,
        modalInitialState: null,
        inspectorModalOpen: false,
        confirmModalOpen: false,
        addFieldModalOpen: false,
        addFieldLoading: false,
        addFieldSearchQuery: '',
        projectFields: [],
        projectFieldsLoaded: false,
        projectFieldsLoading: false,
        fhirPayloadText: '',
        isInspecting: false,
        detectedConcepts: null,
        dbMissing: false,
        dbMissingError: '',
        searchCache: {}
    };

    const samplePayload = {
        "resourceType": "Patient",
        "id": "example-patient",
        "gender": "female",
        "birthDate": "1984-05-12",
        "maritalStatus": {
            "coding": [
                {
                    "system": "http://terminology.hl7.org/CodeSystem/v3-MaritalStatus",
                    "code": "M",
                    "display": "Married"
                }
            ]
        },
        "extension": [
            {
                "url": "http://hl7.org/fhir/us/core/StructureDefinition/us-core-birthsex",
                "valueCode": "F"
            },
            {
                "url": "http://hl7.org/fhir/us/core/StructureDefinition/us-core-race",
                "extension": [
                    {
                        "url": "ombCategory",
                        "valueCoding": {
                            "system": "urn:oid:2.16.840.1.113883.6.238",
                            "code": "2106-3",
                            "display": "White"
                        }
                    }
                ]
            },
            {
                "url": "http://hl7.org/fhir/us/core/StructureDefinition/us-core-ethnicity",
                "extension": [
                    {
                        "url": "ombCategory",
                        "valueCoding": {
                            "system": "urn:oid:2.16.840.1.113883.6.238",
                            "code": "2186-5",
                            "display": "Not Hispanic or Latino"
                        }
                    }
                ]
            }
        ]
    };

    const applyInitialData = (data) => {
        if (!data) return false;
        if (data.success) {
            state.projectId = data.project_id;
            state.isProduction = data.is_production;
            state.hasDraftMode = data.has_draft_mode;
            state.canModify = data.can_modify;
            state.fields = data.fields || [];
            state.dbMissing = false;
            state.loading = false;
            return true;
        } else if (data.db_missing) {
            state.dbMissing = true;
            state.dbMissingError = data.error;
            state.loading = false;
            return true;
        }
        return false;
    };

    const ensureDefaultValuesets = async () => {
        if (state.defaultValuesets && state.defaultValuesets.length > 0) return;
        try {
            const res = await module.ajax('search_valuesets', { query: '', limit: 35 });
            if (res && res.success) {
                state.defaultValuesets = res.valuesets || [];
            }
        } catch (e) {}
    };

    const ensureProjectFields = async () => {
        if (state.projectFieldsLoaded || state.projectFieldsLoading) return;
        state.projectFieldsLoading = true;
        try {
            const res = await module.ajax('get_project_fields');
            if (res && res.success) {
                state.projectFields = res.fields || [];
                state.projectFieldsLoaded = true;
            }
        } catch (e) {
            console.error('Failed to prefetch project fields:', e);
        } finally {
            state.projectFieldsLoading = false;
            if (state.addFieldModalOpen && state.addFieldLoading) {
                state.addFieldLoading = false;
                renderAddFieldModal();
            }
        }
    };

    const loadData = async (forceRefresh = false) => {
        if (!forceRefresh && window.CDIS_INITIAL_DATA && applyInitialData(window.CDIS_INITIAL_DATA)) {
            // Immediate zero-latency render using server-hydrated initial payload
            window.CDIS_INITIAL_DATA = null; // Clear initial cache so future loadData calls fetch fresh data
            render();
            // Prefetch modal valuesets & all project fields in the background without blocking the view
            ensureDefaultValuesets();
            ensureProjectFields();
            return;
        }

        state.loading = true;
        render();

        try {
            const [fieldsRes] = await Promise.all([
                module.ajax('get_mapped_fields', {}),
                ensureDefaultValuesets(),
                ensureProjectFields()
            ]);

            if (fieldsRes && fieldsRes.success) {
                state.projectId = fieldsRes.project_id;
                state.isProduction = fieldsRes.is_production;
                state.hasDraftMode = fieldsRes.has_draft_mode;
                state.canModify = fieldsRes.can_modify;
                state.fields = fieldsRes.fields || [];
                state.dbMissing = false;
            } else if (fieldsRes && fieldsRes.db_missing) {
                state.dbMissing = true;
                state.dbMissingError = fieldsRes.error;
            } else {
                alert(fieldsRes?.error || 'Failed to load mapped fields.');
            }
        } catch (e) {
            console.error(e);
            alert('An unexpected error occurred while communicating with REDCap.');
        } finally {
            state.loading = false;
            render();
        }
    };

    const parseFhirPayload = async () => {
        const textarea = document.getElementById('cdis-fhir-input');
        const text = textarea ? textarea.value.trim() : (state.fhirPayloadText || '');
        if (!text) {
            alert('Please paste a FHIR JSON message first.');
            return;
        }

        state.fhirPayloadText = text;
        state.isInspecting = true;
        renderInspectorModal();

        try {
            const res = await module.ajax('parse_fhir_payload', { fhir_json: text });
            if (res.success) {
                state.detectedConcepts = res.detected_concepts || [];
            } else {
                alert(res.error || 'Failed to parse FHIR message.');
            }
        } catch (e) {
            console.error(e);
            alert('Failed to parse FHIR payload.');
        } finally {
            state.isInspecting = false;
            renderInspectorModal();
        }
    };

    const applyDetectedValueSet = async (fieldName, valueSetId, valueSetTitle, valueSetPackage) => {
        state.inspectorModalOpen = false;
        renderInspectorModal();
        await openDiffModal(fieldName);
        await selectModalValueSet(fieldName, valueSetId, valueSetTitle, valueSetPackage);
    };

    const openDiffModal = async (fieldName) => {
        const field = state.fields.find(f => f.field_name === fieldName);
        if (!field) return;

        await ensureDefaultValuesets();

        state.modalInitialState = {
            selected_preset_id: field.selected_preset_id,
            target_vs_title: field.target_vs_title,
            target_vs_package: field.target_vs_package,
            target_concepts: field.target_concepts ? [...field.target_concepts] : [],
            is_match: field.is_match
        };

        state.activeModalField = field;
        state.modalConcepts = field.target_concepts || [];
        state.modalSearchQuery = '';
        state.modalSearchResults = state.defaultValuesets || [];
        state.modalIsSearching = false;
        state.modalSearchScrollTop = 0;
        state.isSaving = false;

        renderModal();
    };

    const closeModal = () => {
        state.activeModalField = null;
        state.modalInitialState = null;
        state.modalConcepts = [];
        state.modalSearchQuery = '';
        state.modalSearchResults = null;
        state.modalIsSearching = false;
        state.modalSearchScrollTop = 0;
        state.modalLoadingConcepts = false;
        state.isSaving = false;
        renderModal();
        render();
    };

    const cancelModal = () => {
        if (state.activeModalField && state.modalInitialState) {
            const field = state.activeModalField;
            field.selected_preset_id = state.modalInitialState.selected_preset_id;
            field.target_vs_title = state.modalInitialState.target_vs_title;
            field.target_vs_package = state.modalInitialState.target_vs_package;
            field.target_concepts = state.modalInitialState.target_concepts;
            field.is_match = state.modalInitialState.is_match;
        }
        closeModal();
    };

    const saveModalValueSet = async (fieldName) => {
        const field = state.fields.find(f => f.field_name === fieldName);
        if (!field) return;

        state.isSaving = true;
        const saveBtn = document.getElementById('btn-modal-save');
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        }

        try {
            const vsId = field.selected_preset_id || '__none__';
            const pkg = field.target_vs_package || 'FHIR R4';

            const res = await module.ajax('set_field_valueset_override', {
                field_name: fieldName,
                valueset_id: vsId,
                package: pkg
            });

            if (res && res.success) {
                closeModal();
                await loadData();
            } else {
                alert((res && res.error) || 'Failed to save selected ValueSet.');
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="fas fa-save"></i> Save';
                }
            }
        } catch (e) {
            console.error(e);
            alert('An error occurred while saving.');
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fas fa-save"></i> Save';
            }
        } finally {
            state.isSaving = false;
        }
    };

    const applyFieldChoices = async (fieldName) => {
        const field = state.fields.find(f => f.field_name === fieldName);
        if (!field) return;

        if (!field.selected_preset_id || field.selected_preset_id === '__none__') {
            alert('Cannot update choices for an unmapped field. Please select a ValueSet first.');
            return;
        }

        const concepts = field.target_concepts || [];
        if (concepts.length === 0) {
            alert('No target ValueSet concepts selected.');
            return;
        }

        const lines = concepts.map(c => `${c.code}, ${c.display}`);
        const newEnum = lines.join(' \\n ');

        state.isApplying = true;
        const applyBtn = document.getElementById('btn-modal-apply');
        if (applyBtn) {
            applyBtn.disabled = true;
            applyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
        }

        try {
            // First persist the selected ValueSet override
            await module.ajax('set_field_valueset_override', {
                field_name: fieldName,
                valueset_id: field.selected_preset_id || '__none__',
                package: field.target_vs_package || 'FHIR R4'
            });

            // Then apply choices to REDCap field metadata
            const res = await module.ajax('apply_choices', {
                field_name: fieldName,
                element_enum: newEnum
            });

            if (res.success) {
                closeModal();
                await loadData();
            } else {
                alert(res.error || 'Failed to update field choices.');
                if (applyBtn) {
                    applyBtn.disabled = false;
                    applyBtn.innerHTML = '<i class="fas fa-bolt"></i> Update Field Now';
                }
            }
        } catch (e) {
            console.error(e);
            alert('An error occurred during update.');
            if (applyBtn) {
                applyBtn.disabled = false;
                applyBtn.innerHTML = '<i class="fas fa-bolt"></i> Update Field Now';
            }
        } finally {
            state.isApplying = false;
        }
    };

    const batchApplyMismatches = async () => {
        const mismatchedFields = state.fields.filter(f => 
            !f.is_match && 
            Boolean(f.selected_preset_id) && 
            f.selected_preset_id !== '__none__' && 
            Array.isArray(f.target_concepts) && 
            f.target_concepts.length > 0
        );
        if (mismatchedFields.length === 0) {
            alert('No mismatched fields found to update.');
            return;
        }

        const updates = mismatchedFields.map(f => {
            const lines = f.target_concepts.map(c => `${c.code}, ${c.display}`);
            return {
                field_name: f.field_name,
                element_enum: lines.join(' \\n ')
            };
        });

        state.isApplying = true;
        render();

        try {
            const res = await module.ajax('batch_apply_choices', { updates });
            if (res.success) {
                alert(`Successfully updated choices for ${res.updated_count} fields.`);
                await loadData();
            } else {
                alert(res.error || 'Failed to update fields in batch.');
            }
        } catch (e) {
            console.error(e);
            alert('An error occurred during batch update.');
        } finally {
            state.isApplying = false;
            render();
        }
    };

    const render = () => {
        if (state.dbMissing) {
            appEl.innerHTML = `
                <div class="cdis-wrapper">
                    <div class="cdis-card" style="text-align: center; padding: 48px 24px; max-width: 680px; margin: 40px auto; border-top: 4px solid #ef4444; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                        <i class="fas fa-database fa-3x" style="color: #ef4444; margin-bottom: 16px;"></i>
                        <h3 style="color: #0f172a; margin-bottom: 8px; font-weight: 700;">Terminology Database Not Initialized</h3>
                        <p style="color: #475569; font-size: 0.95rem; line-height: 1.5; margin-bottom: 24px;">
                            The Clinical Terminology Mapper requires the pre-compiled FHIR & LOINC Terminology Database to align multiple-choice fields. The database has not been initialized on this REDCap system yet.
                        </p>
                        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 18px; text-align: left; font-size: 0.875rem; color: #334155;">
                            <div style="font-weight: 700; color: #1e3a8a; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                                <i class="fas fa-tools" style="color: #2563eb;"></i> System Administrator Action Required:
                            </div>
                            <p style="margin: 0 0 10px 0; color: #64748b;">
                                A REDCap System Administrator must build the terminology database before this module can be used:
                            </p>
                            <ol style="margin: 0 0 0 18px; padding: 0; color: #475569; line-height: 1.6;">
                                <li>Navigate to <strong>Control Center</strong> in REDCap.</li>
                                <li>In the left menu under External Modules, click <strong>Clinical Terminology DB Manager</strong>.</li>
                                <li>Click <strong>Build Terminology Database</strong> (or run the CLI build script).</li>
                            </ol>
                        </div>
                    </div>
                </div>
            `;
            return;
        }

        const mismatched = state.loading
            ? 0
            : state.fields.filter(f => 
                !f.is_match && 
                Boolean(f.selected_preset_id) && 
                f.selected_preset_id !== '__none__' && 
                Array.isArray(f.target_concepts) && 
                f.target_concepts.length > 0
            ).length;

        let alertHtml = '';
        if (!state.loading && state.isProduction && !state.hasDraftMode) {
            alertHtml = `
                <div class="cdis-alert cdis-alert-warning">
                    <i class="fas fa-exclamation-triangle fa-lg"></i>
                    <div>
                        <strong>Notice: Project is currently in Production status.</strong><br>
                        Metadata modifications are blocked until you enter <strong>Draft Mode</strong> in the Online Designer or Project Setup.
                    </div>
                </div>
            `;
        } else if (!state.loading && state.isProduction && state.hasDraftMode) {
            alertHtml = `
                <div class="cdis-alert cdis-alert-info">
                    <i class="fas fa-pencil-ruler fa-lg"></i>
                    <div>
                        <strong>Draft Mode Active:</strong> Choice updates made here will be staged into your project's draft metadata.
                    </div>
                </div>
            `;
        }

        let tableRows = '';
        if (state.loading) {
            tableRows = [1, 2, 3, 4, 5].map(() => `
                <tr>
                    <td>
                        <div class="cdis-skeleton" style="width: 140px; height: 16px; margin-bottom: 5px;"></div>
                        <div class="cdis-skeleton" style="width: 190px; height: 12px;"></div>
                    </td>
                    <td>
                        <div class="cdis-skeleton" style="width: 130px; height: 16px; margin-bottom: 5px;"></div>
                        <div class="cdis-skeleton" style="width: 85px; height: 12px;"></div>
                    </td>
                    <td>
                        <div class="cdis-skeleton" style="width: 160px; height: 16px; margin-bottom: 5px;"></div>
                        <div class="cdis-skeleton" style="width: 110px; height: 12px;"></div>
                    </td>
                    <td>
                        <div class="cdis-skeleton cdis-skeleton-pill"></div>
                    </td>
                    <td>
                        <div class="cdis-skeleton" style="width: 55px; height: 16px;"></div>
                    </td>
                    <td style="white-space: nowrap; text-align: right;">
                        <div class="cdis-skeleton" style="width: 62px; height: 28px; border-radius: 6px;"></div>
                    </td>
                </tr>
            `).join('');
        } else if (state.fields.length === 0) {
            tableRows = `
                <tr>
                    <td colspan="6" class="cdis-empty-state">
                        <i class="fas fa-info-circle fa-2x" style="color: #94a3b8; margin-bottom: 8px;"></i>
                        <p style="margin-bottom: 4px; font-weight: 600; color: #1e293b;">No fields currently in Option Mapper.</p>
                        <small style="color: #64748b;">Click <strong>Add Field</strong> above to add any text, radio, dropdown, or checkbox field from your project, or configure CDIS mapping in Clinical Data Pull.</small>
                    </td>
                </tr>
            `;
        } else {
            tableRows = state.fields.map(f => {
                const badge = !f.selected_preset_id
                    ? `<span class="cdis-badge cdis-badge-neutral"><i class="fas fa-minus-circle"></i> Unmapped</span>`
                    : (f.is_match
                        ? `<span class="cdis-badge cdis-badge-success"><i class="fas fa-check-circle"></i> Matched</span>`
                        : (!f.is_multiple_choice
                            ? `<span class="cdis-badge cdis-badge-neutral">${escapeHtml(f.element_type)}</span>`
                            : `<span class="cdis-badge cdis-badge-danger"><i class="fas fa-times-circle"></i> Mismatch</span>`));

                const recordWarning = f.record_count > 0
                    ? `<div class="cdis-record-warning" title="Existing records have data stored in this field"><i class="fas fa-exclamation-circle"></i> ${f.record_count} records with data</div>`
                    : '';

                const activeVsTitle = f.target_vs_title || (f.presets?.find(p => p.id === f.selected_preset_id)?.title) || f.selected_preset_id;
                const activePkg = f.target_vs_package || (f.presets?.find(p => p.id === f.selected_preset_id)?.package) || ((f.selected_preset_id && (f.selected_preset_id.startsWith('us-core') || f.selected_preset_id === 'omb-race-category' || f.selected_preset_id === 'omb-ethnicity-category')) ? 'US Core v3.1.1' : 'FHIR R4');
                const usCoreBadge = (activePkg && activePkg.startsWith('US Core')) ? `<span class="cdis-badge cdis-badge-uscore" style="margin-left: 6px;"><i class="fas fa-flag-usa" style="margin-right: 3px;"></i>${escapeHtml(activePkg)}</span>` : '';

                const vsCellText = f.selected_preset_id ? `
                    <div style="font-weight: 600; color: #1e293b; font-size: 0.875rem; display: flex; align-items: center; flex-wrap: wrap;">
                        <span>${escapeHtml(activeVsTitle)}</span>
                        ${usCoreBadge}
                    </div>
                    <div style="font-size: 0.75rem; color: #64748b; margin-top: 2px;"><code>${escapeHtml(f.selected_preset_id)}</code></div>
                ` : `
                    <span style="color: #94a3b8; font-size: 0.85rem; font-style: italic;">Unmapped</span>
                `;

                const currentCount = Object.keys(f.current_choices || {}).length;
                const targetCount = (f.target_concepts || []).length;
                const { currentTypeLabel, targetTypeLabel } = getFieldTypeLabels(f.element_type, targetCount);
                const comparisonText = f.is_multiple_choice
                    ? (f.selected_preset_id ? `${currentCount} &rarr; ${targetCount}` : `${currentCount} &rarr; 0`)
                    : `Text &rarr; ${targetCount}`;
                const typeTransition = (f.selected_preset_id && targetCount > 0)
                    ? (currentTypeLabel.toLowerCase() === targetTypeLabel.toLowerCase()
                        ? `<div style="font-size: 0.725rem; color: #64748b; margin-top: 2px;">${escapeHtml(targetTypeLabel)}</div>`
                        : `<div style="font-size: 0.725rem; color: #64748b; margin-top: 2px;">${escapeHtml(currentTypeLabel)} &rarr; <strong>${escapeHtml(targetTypeLabel)}</strong></div>`)
                    : `<div style="font-size: 0.725rem; color: #64748b; margin-top: 2px;">${escapeHtml(currentTypeLabel)}</div>`;

                const sourceHtml = f.is_custom ? `
                    <span class="badge" style="background: #f1f5f9; color: #475569; font-size: 0.72rem; padding: 2px 7px; border-radius: 4px; border: 1px solid #cbd5e1; font-weight: 600;">
                        <i class="fas fa-user-edit" style="color: #64748b; font-size: 0.68rem; margin-right: 3px;"></i>Project Field
                    </span>
                    <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 2px;">Form: ${escapeHtml(f.form_name)}</div>
                ` : `
                    <code>${escapeHtml(f.external_source)}</code>
                    <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 2px;">Form: ${escapeHtml(f.form_name)}</div>
                `;

                const removeBtn = f.is_custom ? `
                    <button class="cdis-btn cdis-btn-sm btn-remove-custom-field" data-field="${escapeHtml(f.field_name)}" title="Remove from Option Mapper" style="margin-left: 4px; color: #ef4444; border: 1px solid #fca5a5; background: #fff; padding: 4px 8px;">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                ` : '';

                return `
                    <tr>
                        <td>
                            <strong>${escapeHtml(f.field_name)}</strong>
                            <div style="font-size: 0.8rem; color: #64748b;">${escapeHtml(f.field_label || '')}</div>
                            ${recordWarning}
                        </td>
                        <td>
                            ${sourceHtml}
                        </td>
                        <td>${vsCellText}</td>
                        <td>${badge}</td>
                        <td>
                            <div style="font-size: 0.875rem; font-weight: 600; color: #334155;">
                                ${comparisonText}
                            </div>
                            ${typeTransition}
                        </td>
                        <td style="white-space: nowrap; text-align: right;">
                            <button class="cdis-btn cdis-btn-secondary cdis-btn-sm btn-edit-field" data-field="${escapeHtml(f.field_name)}">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            ${removeBtn}
                        </td>
                    </tr>
                `;
            }).join('');
        }

        appEl.innerHTML = `
            <div class="cdis-wrapper">
                <div class="cdis-header">
                    <div class="cdis-title">
                        <i class="fas fa-exchange-alt" style="color: #2563eb;"></i>
                        Clinical Terminology Mapper
                    </div>
                    <div class="cdis-subtitle">
                        Harmonize REDCap fields and CDIS multiple-choice options with HL7 FHIR & LOINC ValueSets to prevent validation errors and standardize data dictionaries.
                    </div>
                </div>

                ${alertHtml}

                <div class="cdis-toolbar">
                    <div style="font-size: 0.9rem; font-weight: 600; color: #334155; display: flex; align-items: center; gap: 8px;">
                        <span>Mapped Project Fields</span>
                        ${state.loading ? '<span style="font-size: 0.8rem; font-weight: normal; color: #64748b;"><i class="fas fa-spinner fa-spin"></i> Loading...</span>' : ''}
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button class="cdis-btn cdis-btn-secondary" id="btn-add-field" ${state.loading ? 'disabled' : ''}>
                            <i class="fas fa-plus"></i> Add Field
                        </button>
                        <button class="cdis-btn cdis-btn-secondary" id="btn-open-inspector" ${state.loading ? 'disabled' : ''}>
                            <i class="fas fa-magic"></i> FHIR Message Inspector
                        </button>
                        <button class="cdis-btn cdis-btn-primary" id="btn-open-confirm-update" ${(state.loading || !state.canModify || mismatched === 0) ? 'disabled' : ''}>
                            <i class="fas fa-bolt"></i> Update
                        </button>
                    </div>
                </div>

                <div class="cdis-card">
                    <table class="cdis-table">
                        <thead>
                            <tr>
                                <th>REDCap Field</th>
                                <th>Source / Standard</th>
                                <th>FHIR ValueSet</th>
                                <th>Status</th>
                                <th>Choice Comparison</th>
                                <th style="text-align: right; width: 80px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            ${tableRows}
                        </tbody>
                    </table>
                </div>

                <div id="cdis-modal-container"></div>
                <div id="cdis-inspector-modal-container"></div>
                <div id="cdis-confirm-modal-container"></div>
                <div id="cdis-add-field-modal-container"></div>
            </div>
        `;

        bindEvents();
        if (state.activeModalField) renderModal();
        if (state.inspectorModalOpen) renderInspectorModal();
        if (state.confirmModalOpen) renderConfirmModal();
        if (state.addFieldModalOpen) renderAddFieldModal();
    };

    const renderTargetConceptsHtml = (field, targetConcepts) => {
        if (!field.selected_preset_id) {
            return '<div style="color:#94a3b8;font-size:0.85rem;padding:12px;font-style:italic;"><i class="fas fa-info-circle"></i> Field is unmapped. No FHIR ValueSet target selected.</div>';
        }
        if (targetConcepts.length === 0) {
            return `
                <div style="background: #fffbeb; border: 1px solid #fef3c7; border-radius: 6px; padding: 12px; margin-top: 4px;">
                    <div style="font-weight: 600; color: #b45309; font-size: 0.8rem; margin-bottom: 4px;">
                        <i class="fas fa-exclamation-triangle" style="margin-right: 4px;"></i> No Pre-Expanded Concepts in HL7 Package
                    </div>
                    <div style="font-size: 0.75rem; color: #78350f; line-height: 1.45; margin-bottom: 6px;">
                        This ValueSet (<code>${escapeHtml(field.selected_preset_id)}</code>) is an <em>intensional definition</em> referencing an external or proprietary code system (e.g., AMA CPT, SNOMED CT, or LOINC).
                    </div>
                    <div style="font-size: 0.725rem; color: #92400e; line-height: 1.4;">
                        HL7 packages cannot legally redistribute licensed code sets, and large codebooks (10,000+ codes) cannot be used as static multiple-choice options in REDCap.
                    </div>
                </div>
            `;
        }
        return targetConcepts.map(c =>
            `<div class="cdis-choice-item"><strong>${escapeHtml(c.code)}</strong>: ${escapeHtml(c.display)}</div>`
        ).join('');
    };

    const selectModalValueSet = async (fieldName, vsId, vsTitle, vsPackage) => {
        const field = state.fields.find(f => f.field_name === fieldName);
        if (!field) return;

        const resultsEl = document.getElementById('modal-vs-search-results');
        if (resultsEl) {
            state.modalSearchScrollTop = resultsEl.scrollTop;
        }

        const pkgLabel = vsPackage || ((vsId && (vsId.startsWith('us-core') || vsId === 'omb-race-category' || vsId === 'omb-ethnicity-category')) ? 'US Core v3.1.1' : 'FHIR R4');
        const usCoreTag = (pkgLabel && pkgLabel.startsWith('US Core')) ? `<span class="cdis-badge cdis-badge-uscore" style="margin-left: 6px;"><i class="fas fa-flag-usa" style="margin-right: 3px;"></i>${escapeHtml(pkgLabel)}</span>` : '';

        // 1. Immediately update local field state
        field.selected_preset_id = vsId;
        field.target_vs_title = vsTitle || vsId;
        field.target_vs_package = pkgLabel;
        field.target_concepts = [];
        state.activeModalField = field;
        state.modalConcepts = [];
        state.modalLoadingConcepts = true;

        // In-place UI update for immediate response without re-rendering modal shell
        const bannerEl = document.getElementById('modal-active-vs-banner');
        if (bannerEl) {
            bannerEl.innerHTML = `
                <div>
                    <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #64748b;">Active Selection:</span>
                    <strong style="margin-left: 6px; color: #0f172a;">${escapeHtml(vsTitle || vsId)}</strong>
                    <code style="font-size: 0.75rem; background: #e2e8f0; padding: 2px 6px; border-radius: 4px; margin-left: 6px;">${escapeHtml(vsId)}</code>
                    ${usCoreTag}
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span class="cdis-badge cdis-badge-neutral">Fetching...</span>
                    <button class="cdis-btn cdis-btn-sm cdis-btn-secondary" id="btn-modal-quick-deselect" title="Deselect and remove mapping">
                        <i class="fas fa-times-circle" style="color: #ef4444;"></i> Unmap
                    </button>
                </div>
            `;
            document.getElementById('btn-modal-quick-deselect')?.addEventListener('click', async () => {
                await deselectModalValueSet(fieldName);
            });
        }

        const conceptsColEl = document.getElementById('modal-target-concepts-col');
        if (conceptsColEl) {
            conceptsColEl.innerHTML = `
                <div class="cdis-diff-col-title">Target FHIR Concepts</div>
                <div class="cdis-loading-centered" style="min-height: 120px; padding: 24px 12px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <i class="fas fa-spinner fa-spin fa-2x" style="color: #3b82f6; margin-bottom: 8px;"></i>
                    <div style="font-size: 0.85rem; color: #475569; font-weight: 600;">Loading FHIR concepts...</div>
                    <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 2px;">Fetching from terminology service</div>
                </div>
            `;
        }

        const applyBtn = document.getElementById('btn-modal-apply');
        if (applyBtn) {
            applyBtn.disabled = true;
            applyBtn.innerHTML = '<i class="fas fa-bolt"></i> Update Field Now';
        }

        // Update selection dots in-place so scroll is completely untouched
        if (resultsEl) {
            resultsEl.querySelectorAll('.cdis-modal-result-item').forEach(el => {
                const isSelected = (el.getAttribute('data-vs-id') === vsId && (el.getAttribute('data-vs-package') || 'FHIR R4') === pkgLabel);
                el.classList.toggle('cdis-result-active', isSelected);
                const dot = el.querySelector('.cdis-selection-dot');
                if (dot) {
                    dot.classList.toggle('selected', isSelected);
                }
            });
        }

        try {
            const res = await module.ajax('get_valueset_options', { valueset_id: vsId, package: pkgLabel });

            if (field.selected_preset_id === vsId && field.target_vs_package === pkgLabel) {
                if (res.success) {
                    field.target_concepts = res.concepts || [];
                    field.target_vs_title = res.valueset ? res.valueset.title : (vsTitle || vsId);
                    field.target_vs_package = res.valueset?.package || pkgLabel;
                    field.is_match = computeIsMatch(field);

                    state.modalConcepts = field.target_concepts;
                } else {
                    alert(res.error || 'Failed to load options for the selected ValueSet.');
                }
            }
        } catch (e) {
            console.error(e);
        } finally {
            if (field.selected_preset_id === vsId && field.target_vs_package === pkgLabel) {
                state.modalLoadingConcepts = false;
                const targetConcepts = state.modalConcepts || [];
                const targetItems = renderTargetConceptsHtml(field, targetConcepts);

                if (conceptsColEl) {
                    const { currentTypeLabel: currType, targetTypeLabel: targetType } = getFieldTypeLabels(field.element_type, targetConcepts.length);
                    const typeBadgeText = currType.toLowerCase() === targetType.toLowerCase() ? escapeHtml(targetType) : `&rarr; ${escapeHtml(targetType)}`;

                    conceptsColEl.innerHTML = `
                        <div class="cdis-diff-col-title" style="display: flex; justify-content: space-between; align-items: center;">
                            <span>Target FHIR Concepts (${targetConcepts.length})</span>
                            ${targetConcepts.length > 0 ? `
                                <span class="cdis-badge cdis-badge-neutral" style="font-size: 0.7rem; text-transform: uppercase;">${typeBadgeText}</span>
                            ` : ''}
                        </div>
                        <div style="max-height: 160px; overflow-y: auto;">
                            ${targetItems}
                        </div>
                    `;
                }

                if (bannerEl) {
                    const badge = bannerEl.querySelector('.cdis-badge');
                    if (badge) {
                        badge.className = 'cdis-badge cdis-badge-neutral';
                        badge.innerHTML = `${targetConcepts.length} Concepts`;
                    }
                }

                if (applyBtn) {
                    const canUpdate = state.canModify && Boolean(field.selected_preset_id) && field.selected_preset_id !== '__none__' && targetConcepts.length > 0;
                    applyBtn.disabled = !canUpdate;
                    applyBtn.innerHTML = '<i class="fas fa-bolt"></i> Update Field Now';
                }

                const saveBtn = document.getElementById('btn-modal-save');
                if (saveBtn) {
                    saveBtn.disabled = false;
                }
            }
        }
    };

    const deselectModalValueSet = async (fieldName) => {
        const field = state.fields.find(f => f.field_name === fieldName);
        if (!field) return;

        const resultsEl = document.getElementById('modal-vs-search-results');
        if (resultsEl) {
            state.modalSearchScrollTop = resultsEl.scrollTop;
        }

        field.selected_preset_id = null;
        field.target_vs_title = '';
        field.target_concepts = [];
        field.is_match = false;

        state.activeModalField = field;
        state.modalConcepts = [];
        state.modalLoadingConcepts = false;

        // In-place UI update
        const bannerEl = document.getElementById('modal-active-vs-banner');
        if (bannerEl) {
            bannerEl.innerHTML = `
                <div>
                    <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #64748b;">Active Selection:</span>
                    <span class="cdis-badge cdis-badge-neutral" style="margin-left: 6px;">None / Unmapped</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span class="cdis-badge">No ValueSet</span>
                </div>
            `;
        }

        const conceptsColEl = document.getElementById('modal-target-concepts-col');
        if (conceptsColEl) {
            conceptsColEl.innerHTML = `
                <div class="cdis-diff-col-title">Target FHIR Concepts (0)</div>
                <div style="max-height: 160px; overflow-y: auto;">
                    <div style="color:#94a3b8;font-size:0.85rem;padding:12px;font-style:italic;"><i class="fas fa-info-circle"></i> Field is unmapped. No FHIR ValueSet target selected.</div>
                </div>
            `;
        }

        const applyBtn = document.getElementById('btn-modal-apply');
        if (applyBtn) {
            applyBtn.disabled = true;
            applyBtn.innerHTML = '<i class="fas fa-bolt"></i> Update Field Now';
        }

        const saveBtn = document.getElementById('btn-modal-save');
        if (saveBtn) {
            saveBtn.disabled = false;
        }

        if (resultsEl) {
            resultsEl.querySelectorAll('.cdis-modal-result-item').forEach(el => {
                el.classList.remove('cdis-result-active');
                const dot = el.querySelector('.cdis-selection-dot');
                if (dot) {
                    dot.classList.remove('selected');
                }
            });
        }
    };

    const renderModalSearchResults = () => {
        const resultsEl = document.getElementById('modal-vs-search-results');
        if (!resultsEl || !state.activeModalField) return;

        const field = state.activeModalField;
        const activePkg = field.target_vs_package || 'FHIR R4';

        if (state.modalIsSearching) {
            resultsEl.innerHTML = `
                <div style="height: 100%; min-height: 200px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; color: #475569;">
                    <i class="fas fa-spinner fa-spin fa-2x" style="color: #2563eb;"></i>
                    <span style="font-size: 0.85rem; font-weight: 500;">Searching FHIR ValueSets...</span>
                </div>
            `;
            return;
        }

        const getDefaultPkg = (item) => item.package || ((item.id.startsWith('us-core') || item.id === 'omb-race-category' || item.id === 'omb-ethnicity-category') ? 'US Core v3.1.1' : 'FHIR R4');
        const recommendations = field.presets || [];
        const recKeys = new Set(recommendations.map(r => `${r.id}::${getDefaultPkg(r)}`));

        // Recommendations HTML at top
        let recHtml = '';
        if (recommendations.length > 0) {
            recHtml = recommendations.map(rec => {
                const pkg = getDefaultPkg(rec);
                const isSelected = rec.id === field.selected_preset_id && pkg === activePkg;
                const usCoreTag = (pkg && pkg.startsWith('US Core')) ? `<span class="cdis-badge cdis-badge-uscore" style="margin-left: 4px;"><i class="fas fa-flag-usa" style="margin-right: 3px;"></i>${escapeHtml(pkg)}</span>` : '';
                const conceptCountTag = rec.concept_count !== undefined
                    ? (rec.concept_count === 0
                        ? (rec.catalog_size !== undefined
                            ? `<span class="cdis-badge" style="font-size:0.7rem; color:#0369a1; background:#e0f2fe; border:1px solid #bae6fd; margin-left:4px;" title="${escapeHtml(rec.catalog_source || '')} - ${escapeHtml(rec.catalog_notes || '')}">${typeof rec.catalog_size === 'number' ? rec.catalog_size.toLocaleString() : escapeHtml(rec.catalog_size)}</span>`
                            : `<span class="cdis-badge cdis-badge-neutral" style="font-size:0.7rem; color:#b45309; background:#fffbeb; border:1px solid #fde68a; margin-left:4px;" title="Intensional definition with no pre-expanded concepts in HL7 package"><i class="fas fa-exclamation-triangle" style="margin-right:2px;"></i> 0 concepts</span>`)
                        : `<span style="font-size:0.725rem; color:#64748b; margin-left:4px;">(${rec.concept_count} concepts)</span>`)
                    : '';

                return `
                    <div class="cdis-modal-result-item cdis-rec-item ${isSelected ? 'cdis-result-active' : ''}"
                         data-vs-id="${escapeHtml(rec.id)}"
                         data-vs-title="${encodeURIComponent(rec.title)}"
                         data-vs-package="${escapeHtml(pkg)}">
                        <div style="flex: 1;">
                            <div style="font-weight: 600; font-size: 0.85rem; color: #0f172a; display: flex; align-items: center; flex-wrap: wrap;">
                                <i class="fas fa-star" style="color: #eab308; margin-right: 4px;"></i>
                                <span>${escapeHtml(rec.title)}</span>
                                <span class="cdis-badge cdis-badge-rec" style="margin-left: 4px;">Recommended</span>
                                ${usCoreTag}
                                <code style="font-size: 0.75rem; background: #f1f5f9; padding: 1px 5px; border-radius: 3px; margin-left: 4px;">${escapeHtml(rec.id)}</code>
                                ${conceptCountTag}
                            </div>
                            ${rec.description ? `<div style="font-size: 0.75rem; color: #64748b; margin-top: 2px;">${escapeHtml(rec.description)}</div>` : ''}
                        </div>
                        <div>
                            <div class="cdis-selection-dot ${isSelected ? 'selected' : ''}" title="${isSelected ? 'Selected' : 'Select'}"></div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        // Search results or default alphabetical results (exclude items in recommendations)
        const candidateItems = (state.modalSearchResults !== null ? state.modalSearchResults : (state.defaultValuesets || []));
        const searchItems = candidateItems.filter(vs => !recKeys.has(`${vs.id}::${getDefaultPkg(vs)}`));
        const count = searchItems.length;
        const sectionHeader = state.modalSearchQuery
            ? `Search Results (${count})`
            : `All FHIR ValueSets (${count})`;

        let itemsHtml = '';
        if (count === 0) {
            itemsHtml = `
                <div style="text-align: center; padding: 16px 12px; color: #94a3b8; font-size: 0.85rem;">
                    <i class="fas fa-search" style="margin-right: 6px;"></i> No additional ValueSets found matching "${escapeHtml(state.modalSearchQuery)}".
                </div>
            `;
        } else {
            itemsHtml = searchItems.map(vs => {
                const pkg = getDefaultPkg(vs);
                const isSelected = vs.id === field.selected_preset_id && pkg === activePkg;
                const usCoreTag = (pkg && pkg.startsWith('US Core')) ? `<span class="cdis-badge cdis-badge-uscore" style="margin-left: 4px;"><i class="fas fa-flag-usa" style="margin-right: 3px;"></i>${escapeHtml(pkg)}</span>` : '';
                const urnTag = vs.urn ? `<span style="font-size:0.725rem; color:#64748b; margin-left:6px;">URN: <code>${escapeHtml(vs.urn)}</code></span>` : '';
                const conceptCountTag = vs.concept_count !== undefined
                    ? (vs.concept_count === 0
                        ? (vs.catalog_size !== undefined
                            ? `<span class="cdis-badge" style="font-size:0.7rem; color:#0369a1; background:#e0f2fe; border:1px solid #bae6fd; margin-left:4px;" title="${escapeHtml(vs.catalog_source || '')} - ${escapeHtml(vs.catalog_notes || '')}">${typeof vs.catalog_size === 'number' ? vs.catalog_size.toLocaleString() : escapeHtml(vs.catalog_size)}</span>`
                            : `<span class="cdis-badge cdis-badge-neutral" style="font-size:0.7rem; color:#b45309; background:#fffbeb; border:1px solid #fde68a; margin-left:4px;" title="Intensional definition with no pre-expanded concepts in HL7 package"><i class="fas fa-exclamation-triangle" style="margin-right:2px;"></i> 0 concepts</span>`)
                        : `<span style="font-size:0.725rem; color:#64748b; margin-left:4px;">(${vs.concept_count} concepts)</span>`)
                    : '';
                return `
                    <div class="cdis-modal-result-item ${isSelected ? 'cdis-result-active' : ''}"
                         data-vs-id="${escapeHtml(vs.id)}"
                         data-vs-title="${encodeURIComponent(vs.title || vs.name)}"
                         data-vs-package="${escapeHtml(pkg)}">
                        <div style="flex: 1;">
                            <div style="font-weight: 600; font-size: 0.85rem; color: #0f172a; display: flex; align-items: center; flex-wrap: wrap;">
                                <i class="fas fa-book" style="color: #2563eb; margin-right: 4px;"></i>
                                <span>${escapeHtml(vs.title || vs.name)}</span>
                                ${usCoreTag}
                                <code style="font-size: 0.75rem; background: #f1f5f9; padding: 1px 5px; border-radius: 3px; margin-left: 4px;">${escapeHtml(vs.id)}</code>
                                ${conceptCountTag}
                                ${urnTag}
                            </div>
                            ${vs.description ? `<div style="font-size: 0.75rem; color: #64748b; margin-top: 2px;">${escapeHtml(vs.description)}</div>` : ''}
                        </div>
                        <div>
                            <div class="cdis-selection-dot ${isSelected ? 'selected' : ''}" title="${isSelected ? 'Selected' : 'Select'}"></div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        resultsEl.innerHTML = `
            <div class="cdis-modal-results-list">
                ${recHtml}
                <div class="cdis-results-separator">${sectionHeader}</div>
                ${itemsHtml}
            </div>
        `;

        if (state.modalSearchScrollTop) {
            resultsEl.scrollTop = state.modalSearchScrollTop;
        }

        resultsEl.onscroll = () => {
            state.modalSearchScrollTop = resultsEl.scrollTop;
        };

        resultsEl.querySelectorAll('.cdis-modal-result-item').forEach(item => {
            item.addEventListener('click', async () => {
                const vsId = item.getAttribute('data-vs-id');
                const vsTitle = decodeURIComponent(item.getAttribute('data-vs-title') || vsId);
                const vsPackage = item.getAttribute('data-vs-package') || 'FHIR R4';
                if (field.selected_preset_id !== vsId || (field.target_vs_package || 'FHIR R4') !== vsPackage) {
                    state.modalSearchScrollTop = resultsEl.scrollTop;
                    await selectModalValueSet(field.field_name, vsId, vsTitle, vsPackage);
                }
            });
        });
    };

    const renderModal = () => {
        const container = document.getElementById('cdis-modal-container');
        if (!container) return;

        if (!state.activeModalField) {
            container.innerHTML = '';
            return;
        }

        const prevScrollTop = document.getElementById('modal-vs-search-results')?.scrollTop || state.modalSearchScrollTop || 0;

        const field = state.activeModalField;
        const currentChoices = field.current_choices || {};
        const targetConcepts = state.modalConcepts || [];

        const currentItems = Object.entries(currentChoices).map(([code, label]) =>
            `<div class="cdis-choice-item"><strong>${escapeHtml(code)}</strong>: ${escapeHtml(label)}</div>`
        ).join('') || '<div style="color:#94a3b8;font-size:0.8rem;padding:6px;">No choices defined</div>';

        const targetItems = state.modalLoadingConcepts
            ? `
                <div class="cdis-loading-centered" style="min-height: 120px; padding: 24px 12px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <i class="fas fa-spinner fa-spin fa-2x" style="color: #3b82f6; margin-bottom: 8px;"></i>
                    <div style="font-size: 0.85rem; color: #475569; font-weight: 600;">Loading FHIR concepts...</div>
                    <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 2px;">Fetching from terminology service</div>
                </div>
              `
            : renderTargetConceptsHtml(field, targetConcepts);

        const isUnmapped = !field.selected_preset_id || field.selected_preset_id === '__none__';
        const canUpdate = !state.modalLoadingConcepts && state.canModify && !isUnmapped && targetConcepts.length > 0;
        const activeVsTitle = field.target_vs_title || (field.presets?.find(p => p.id === field.selected_preset_id)?.title) || field.selected_preset_id;
        const activeVsPkg = field.target_vs_package || (field.presets?.find(p => p.id === field.selected_preset_id)?.package) || ((field.selected_preset_id && (field.selected_preset_id.startsWith('us-core') || field.selected_preset_id === 'omb-race-category' || field.selected_preset_id === 'omb-ethnicity-category')) ? 'US Core v3.1.1' : 'FHIR R4');
        const activeVsBadge = (activeVsPkg && activeVsPkg.startsWith('US Core')) ? `<span class="cdis-badge cdis-badge-uscore" style="margin-left: 6px;"><i class="fas fa-flag-usa" style="margin-right: 3px;"></i>${escapeHtml(activeVsPkg)}</span>` : '';
        const { currentTypeLabel: currType, targetTypeLabel: targetType } = getFieldTypeLabels(field.element_type, targetConcepts.length);
        const typeBadgeText = currType.toLowerCase() === targetType.toLowerCase() ? escapeHtml(targetType) : `&rarr; ${escapeHtml(targetType)}`;

        container.innerHTML = `
            <div class="cdis-modal-overlay">
                <div class="cdis-modal" style="max-width: 860px;">
                    <div class="cdis-modal-header">
                        <div class="cdis-modal-title">
                            <i class="fas fa-edit" style="color: #2563eb; margin-right: 6px;"></i>
                            Map Field Choices: <code>${escapeHtml(field.field_name)}</code>
                            ${field.field_label ? `<span style="font-size:0.85rem; font-weight:normal; color:#64748b; margin-left:8px;">(${escapeHtml(field.field_label)})</span>` : ''}
                        </div>
                        <button class="cdis-modal-close" id="btn-close-modal">&times;</button>
                    </div>
                    <div class="cdis-modal-body">
                        <!-- ValueSet Selection & Integrated Search -->
                        <div class="cdis-modal-section">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <label for="modal-vs-search-input" style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase; color: #475569; margin: 0;">
                                    <i class="fas fa-search" style="color: #2563eb; margin-right: 4px;"></i> FHIR ValueSet Selection & Search:
                                </label>
                                <span style="font-size: 0.75rem; color: #64748b;">Search 700+ FHIR & US Core ValueSets</span>
                            </div>
                            <input type="text" id="modal-vs-search-input" class="cdis-search-input" 
                                   placeholder="Type to filter ValueSets (e.g. 'gender', 'race', 'condition')..."
                                   value="${escapeHtml(state.modalSearchQuery || '')}" style="margin-bottom: 0;">
                            <div id="modal-vs-search-results" class="cdis-modal-search-results" style="display: block;">
                            </div>

                            <div class="cdis-active-vs-banner" id="modal-active-vs-banner">
                                <div>
                                    <span style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #64748b;">Active Selection:</span>
                                    ${field.selected_preset_id ? `
                                        <strong style="margin-left: 6px; color: #0f172a;">${escapeHtml(activeVsTitle)}</strong>
                                        <code style="font-size: 0.75rem; background: #e2e8f0; padding: 2px 6px; border-radius: 4px; margin-left: 6px;">${escapeHtml(field.selected_preset_id)}</code>
                                        ${activeVsBadge}
                                    ` : `
                                        <span class="cdis-badge cdis-badge-neutral" style="margin-left: 6px;">None / Unmapped</span>
                                    `}
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    ${state.modalLoadingConcepts ? `
                                        <span class="cdis-badge cdis-badge-neutral">Fetching...</span>
                                    ` : `
                                        <span class="cdis-badge ${field.selected_preset_id ? 'cdis-badge-neutral' : ''}">${field.selected_preset_id ? `${targetConcepts.length} Concepts` : 'No ValueSet'}</span>
                                    `}
                                    ${field.selected_preset_id ? `
                                        <button class="cdis-btn cdis-btn-sm cdis-btn-secondary" id="btn-modal-quick-deselect" title="Deselect and remove mapping">
                                            <i class="fas fa-times-circle" style="color: #ef4444;"></i> Unmap
                                        </button>
                                    ` : ''}
                                </div>
                            </div>
                        </div>

                        <!-- Side-by-Side Choice Preview -->
                        <div class="cdis-diff-grid">
                            <div class="cdis-diff-col">
                                <div class="cdis-diff-col-title" style="display: flex; justify-content: space-between; align-items: center;">
                                    <span>Current REDCap Choices (${Object.keys(currentChoices).length})</span>
                                    <span class="cdis-badge cdis-badge-neutral" style="font-size: 0.7rem; text-transform: uppercase;">${escapeHtml(currType)}</span>
                                </div>
                                <div style="max-height: 160px; overflow-y: auto;">
                                    ${currentItems}
                                </div>
                            </div>
                            <div class="cdis-diff-col" id="modal-target-concepts-col">
                                <div class="cdis-diff-col-title" style="display: flex; justify-content: space-between; align-items: center;">
                                    <span>Target FHIR Concepts${state.modalLoadingConcepts ? '' : ` (${field.selected_preset_id ? targetConcepts.length : 0})`}</span>
                                    ${(!state.modalLoadingConcepts && field.selected_preset_id && targetConcepts.length > 0) ? `
                                        <span class="cdis-badge cdis-badge-neutral" style="font-size: 0.7rem; text-transform: uppercase;">${typeBadgeText}</span>
                                    ` : ''}
                                </div>
                                <div style="max-height: 160px; overflow-y: auto;">
                                    ${targetItems}
                                </div>
                            </div>
                        </div>

                        ${field.record_count > 0 ? `
                            <div class="cdis-alert cdis-alert-warning" style="margin-top: 16px; margin-bottom: 0;">
                                <i class="fas fa-exclamation-triangle fa-lg"></i>
                                <div>
                                    <strong>Existing Data Advisory:</strong> This field currently has <strong>${field.record_count}</strong> records with data. Updating metadata choices does not rewrite existing data rows.
                                </div>
                            </div>
                        ` : ''}
                    </div>
                    <div class="cdis-modal-footer" style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <button class="cdis-btn cdis-btn-primary" id="btn-modal-apply" ${(!canUpdate || state.isApplying || state.isSaving) ? 'disabled' : ''}>
                                <i class="fas fa-bolt"></i> ${state.isApplying ? 'Updating...' : 'Update Field Now'}
                            </button>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button class="cdis-btn cdis-btn-secondary" id="btn-modal-cancel">Cancel</button>
                            <button class="cdis-btn cdis-btn-success" id="btn-modal-save" ${(state.isSaving || state.isApplying || state.modalLoadingConcepts) ? 'disabled' : ''}>
                                <i class="fas fa-save"></i> ${state.isSaving ? 'Saving...' : 'Save'}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.getElementById('btn-close-modal')?.addEventListener('click', cancelModal);
        document.getElementById('btn-modal-cancel')?.addEventListener('click', cancelModal);
        document.getElementById('btn-modal-save')?.addEventListener('click', () => saveModalValueSet(field.field_name));
        document.getElementById('btn-modal-apply')?.addEventListener('click', () => applyFieldChoices(field.field_name));
        document.getElementById('btn-modal-quick-deselect')?.addEventListener('click', async () => {
            await deselectModalValueSet(field.field_name);
        });

        const searchInput = document.getElementById('modal-vs-search-input');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                clearTimeout(state.modalSearchTimer);
                const q = e.target.value;
                state.modalSearchQuery = q;
                const trimmed = q.trim();
                if (!trimmed) {
                    state.modalSearchResults = state.defaultValuesets;
                    state.modalIsSearching = false;
                    renderModalSearchResults();
                    return;
                }

                // Check in-memory cache for instant zero-latency return
                const cacheKey = trimmed.toLowerCase();
                if (state.searchCache[cacheKey]) {
                    state.modalSearchResults = state.searchCache[cacheKey];
                    state.modalIsSearching = false;
                    renderModalSearchResults();
                    return;
                }

                state.modalIsSearching = true;
                renderModalSearchResults();

                state.modalSearchTimer = setTimeout(async () => {
                    try {
                        const res = await module.ajax('search_valuesets', { query: trimmed, limit: 30 });
                        if (res.success && state.activeModalField) {
                            const results = res.valuesets || [];
                            state.searchCache[cacheKey] = results;
                            if (state.modalSearchQuery.trim().toLowerCase() === cacheKey) {
                                state.modalSearchResults = results;
                            }
                        }
                    } catch (err) {
                        console.error(err);
                    } finally {
                        if (state.modalSearchQuery.trim().toLowerCase() === cacheKey) {
                            state.modalIsSearching = false;
                            renderModalSearchResults();
                        }
                    }
                }, 150);
            });
        }

        renderModalSearchResults();
        const newResultsEl = document.getElementById('modal-vs-search-results');
        if (newResultsEl && prevScrollTop) {
            newResultsEl.scrollTop = prevScrollTop;
        }
    };

    const renderInspectorModal = () => {
        const container = document.getElementById('cdis-inspector-modal-container');
        if (!container) return;

        if (!state.inspectorModalOpen) {
            container.innerHTML = '';
            return;
        }

        let detectedHtml = '';
        if (state.detectedConcepts && state.detectedConcepts.length > 0) {
            detectedHtml = `
                <div class="cdis-detected-grid">
                    <div style="font-size: 0.85rem; font-weight: 700; color: #1e293b;">
                        Detected Concepts from Pasted FHIR Message (${state.detectedConcepts.length}):
                    </div>
                    ${state.detectedConcepts.map(d => {
                        const vsBadges = (d.matched_valuesets || []).map(vs => {
                            const alignBtn = d.matched_redcap_field
                                ? `<button class="cdis-btn cdis-btn-primary cdis-btn-sm btn-align-detected" data-field="${escapeHtml(d.matched_redcap_field)}" data-vs="${escapeHtml(vs.id)}" data-vs-title="${encodeURIComponent(vs.title || vs.id)}" data-vs-package="${escapeHtml(vs.package || 'FHIR R4')}">
                                     <i class="fas fa-link"></i> Align '${escapeHtml(d.matched_redcap_field)}' to ${escapeHtml(vs.title)}
                                   </button>`
                                : '';
                            return `
                                <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin-top:4px; padding:4px 0;">
                                    <div><i class="fas fa-book" style="color:#2563eb;"></i> <strong>${escapeHtml(vs.title)}</strong> (<code>${escapeHtml(vs.id)}</code>)</div>
                                    ${alignBtn}
                                </div>
                            `;
                        }).join('') || '<div style="color:#94a3b8; font-size:0.8rem;">No exact ValueSet match found</div>';

                        const fieldTag = d.matched_redcap_field
                            ? `<span class="cdis-badge cdis-badge-success"><i class="fas fa-check"></i> Mapped to '${escapeHtml(d.matched_redcap_field)}'</span>`
                            : `<span class="cdis-badge cdis-badge-neutral">Unmapped in Project</span>`;

                        return `
                            <div class="cdis-detected-item">
                                <div style="flex:1;">
                                    <div style="display:flex; gap:8px; align-items:center; margin-bottom:4px;">
                                        <code>${escapeHtml(d.path)}</code> &rarr; <strong>${escapeHtml(d.code)}</strong> (${escapeHtml(d.display)})
                                        ${fieldTag}
                                    </div>
                                    <div style="font-size:0.75rem; color:#64748b;">System: <code>${escapeHtml(d.system || 'default')}</code></div>
                                    <div style="margin-top:6px; border-top:1px dashed #cbd5e1; padding-top:4px;">
                                        ${vsBadges}
                                    </div>
                                </div>
                            </div>
                        `;
                    }).join('')}
                </div>
            `;
        }

        container.innerHTML = `
            <div class="cdis-modal-overlay">
                <div class="cdis-modal" style="max-width: 820px;">
                    <div class="cdis-modal-header">
                        <div class="cdis-modal-title">
                            <i class="fas fa-magic" style="color: #2563eb; margin-right: 8px;"></i>
                            FHIR Message Inspector & Code Detector
                        </div>
                        <button class="cdis-modal-close" id="btn-close-inspector-modal">&times;</button>
                    </div>
                    <div class="cdis-modal-body">
                        <div style="display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 8px;">
                            <div style="font-size: 0.85rem; color: #475569; flex: 1; line-height: 1.4;">
                                Paste an example FHIR JSON message from your EHR (Patient, Bundle, Observation) to identify codes and matching ValueSets:
                            </div>
                            <button class="cdis-btn cdis-btn-secondary cdis-btn-sm" id="btn-load-sample" style="white-space: nowrap; flex-shrink: 0;">
                                <i class="fas fa-file-code"></i> Load Example
                            </button>
                        </div>
                        <textarea id="cdis-fhir-input" class="cdis-textarea" style="height: 150px;" placeholder="Paste FHIR JSON here...">${escapeHtml(state.fhirPayloadText || '')}</textarea>
                        <div style="margin-top: 10px; display: flex; gap: 10px; align-items: center;">
                            <button class="cdis-btn cdis-btn-primary" id="btn-parse-fhir" ${state.isInspecting ? 'disabled' : ''}>
                                <i class="fas fa-search"></i> ${state.isInspecting ? 'Parsing...' : 'Parse & Detect ValueSets'}
                            </button>
                        </div>
                        ${detectedHtml}
                    </div>
                    <div class="cdis-modal-footer">
                        <button class="cdis-btn cdis-btn-secondary" id="btn-cancel-inspector">Close</button>
                    </div>
                </div>
            </div>
        `;

        document.getElementById('btn-close-inspector-modal')?.addEventListener('click', () => {
            state.inspectorModalOpen = false;
            renderInspectorModal();
        });
        document.getElementById('btn-cancel-inspector')?.addEventListener('click', () => {
            state.inspectorModalOpen = false;
            renderInspectorModal();
        });
        document.getElementById('btn-load-sample')?.addEventListener('click', () => {
            const ta = document.getElementById('cdis-fhir-input');
            const sampleJson = JSON.stringify(samplePayload, null, 2);
            if (ta) ta.value = sampleJson;
            state.fhirPayloadText = sampleJson;
        });
        document.getElementById('btn-parse-fhir')?.addEventListener('click', parseFhirPayload);

        container.querySelectorAll('.btn-align-detected').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const field = e.currentTarget.getAttribute('data-field');
                const vsId = e.currentTarget.getAttribute('data-vs');
                const vsTitle = decodeURIComponent(e.currentTarget.getAttribute('data-vs-title') || vsId);
                const vsPkg = e.currentTarget.getAttribute('data-vs-package') || 'FHIR R4';
                applyDetectedValueSet(field, vsId, vsTitle, vsPkg);
            });
        });
    };

    const renderConfirmModal = () => {
        const container = document.getElementById('cdis-confirm-modal-container');
        if (!container) return;

        if (!state.confirmModalOpen) {
            container.innerHTML = '';
            return;
        }

        const mismatchedFields = state.fields.filter(f => 
            !f.is_match && 
            Boolean(f.selected_preset_id) && 
            f.selected_preset_id !== '__none__' && 
            Array.isArray(f.target_concepts) && 
            f.target_concepts.length > 0
        );
        const hasRecordsWithData = mismatchedFields.some(f => f.record_count > 0);

        container.innerHTML = `
            <div class="cdis-modal-overlay">
                <div class="cdis-modal" style="max-width: 680px;">
                    <div class="cdis-modal-header">
                        <div class="cdis-modal-title">
                            <i class="fas fa-exclamation-triangle" style="color: #d97706; margin-right: 8px;"></i>
                            Confirm Choice Updates
                        </div>
                        <button class="cdis-modal-close" id="btn-close-confirm-modal">&times;</button>
                    </div>
                    <div class="cdis-modal-body">
                        <p style="margin-top: 0; font-size: 0.95rem; color: #1e293b; line-height: 1.5;">
                            You are about to update multiple-choice options for <strong>${mismatchedFields.length}</strong> field(s) to match their selected ValueSets:
                        </p>

                        <div style="max-height: 220px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px; margin-bottom: 14px;">
                            <table class="cdis-table" style="margin: 0; font-size: 0.825rem;">
                                <thead>
                                    <tr>
                                        <th>Field</th>
                                        <th>Target FHIR ValueSet</th>
                                        <th>Concepts</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${mismatchedFields.map(f => `
                                        <tr>
                                            <td>
                                                <strong>${escapeHtml(f.field_name)}</strong>
                                                ${f.record_count > 0 ? `<div style="font-size:0.75rem; color:#b45309;"><i class="fas fa-database"></i> ${f.record_count} records</div>` : ''}
                                            </td>
                                            <td>
                                                <div style="font-weight: 600; color: #1e293b;">${escapeHtml(f.target_vs_title || f.selected_preset_id)}</div>
                                                <code style="font-size: 0.725rem;">${escapeHtml(f.selected_preset_id)}</code>
                                            </td>
                                            <td>
                                                <span class="cdis-badge cdis-badge-neutral">${(f.target_concepts || []).length} choices</span>
                                            </td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>

                        ${hasRecordsWithData ? `
                            <div class="cdis-alert cdis-alert-warning" style="margin-bottom: 0;">
                                <i class="fas fa-exclamation-triangle fa-lg"></i>
                                <div>
                                    <strong>Existing Data Advisory:</strong> Updating metadata choice definitions does not rewrite data values already stored in existing participant records.
                                </div>
                            </div>
                        ` : ''}
                    </div>
                    <div class="cdis-modal-footer">
                        <button class="cdis-btn cdis-btn-secondary" id="btn-cancel-confirm">Cancel</button>
                        <button class="cdis-btn cdis-btn-primary" id="btn-execute-confirm" ${state.isApplying ? 'disabled' : ''}>
                            <i class="fas fa-bolt"></i> ${state.isApplying ? 'Updating...' : 'Confirm & Update Choices'}
                        </button>
                    </div>
                </div>
            </div>
        `;

        document.getElementById('btn-close-confirm-modal')?.addEventListener('click', () => {
            state.confirmModalOpen = false;
            renderConfirmModal();
        });
        document.getElementById('btn-cancel-confirm')?.addEventListener('click', () => {
            state.confirmModalOpen = false;
            renderConfirmModal();
        });
        document.getElementById('btn-execute-confirm')?.addEventListener('click', async () => {
            state.confirmModalOpen = false;
            renderConfirmModal();
            await batchApplyMismatches();
        });
    };

    const handleAddCustomField = async (fieldName) => {
        const projField = (state.projectFields || []).find(f => f.field_name === fieldName);
        const label = projField ? projField.field_label : '';
        const form = projField ? projField.form_name : '';
        const elemType = projField ? projField.element_type : 'text';
        const choices = projField ? (projField.current_choices || {}) : {};
        const isMulti = ['radio', 'select', 'checkbox', 'advcheckbox'].includes(elemType);

        if (projField) {
            projField.is_already_added = true;
        }

        // Optimistically add field to state.fields immediately
        const existingIdx = state.fields.findIndex(f => f.field_name === fieldName);
        const newField = {
            map_id: 'custom_' + fieldName,
            field_name: fieldName,
            field_label: label,
            form_name: form,
            element_type: elemType,
            external_source: 'Project Field',
            is_custom: true,
            current_choices: choices,
            presets: [],
            selected_preset_id: null,
            target_vs_title: null,
            target_vs_package: 'FHIR R4',
            target_concepts: [],
            is_match: false,
            record_count: 0,
            is_multiple_choice: isMulti
        };

        if (existingIdx >= 0) {
            state.fields[existingIdx] = newField;
        } else {
            state.fields.push(newField);
        }

        // Close Add Field modal and update table instantly
        state.addFieldModalOpen = false;
        render();

        // Immediately open ValueSet selection modal for this field
        openDiffModal(fieldName);

        // Persist to server in background
        try {
            const res = await module.ajax('add_custom_field', { field_name: fieldName });
            if (!res || !res.success) {
                // Rollback if server failed
                state.fields = state.fields.filter(f => f.field_name !== fieldName);
                if (projField) projField.is_already_added = false;
                render();
                if (state.activeModalField && state.activeModalField.field_name === fieldName) {
                    closeModal();
                }
                alert('Failed to add field: ' + (res?.error || 'Unknown error'));
            }
        } catch (e) {
            state.fields = state.fields.filter(f => f.field_name !== fieldName);
            if (projField) projField.is_already_added = false;
            render();
            if (state.activeModalField && state.activeModalField.field_name === fieldName) {
                closeModal();
            }
            alert('Request error: ' + e.message);
        }
    };

    const renderAddFieldModal = () => {
        const container = document.getElementById('cdis-add-field-modal-container');
        if (!container) return;

        if (!state.addFieldModalOpen) {
            container.innerHTML = '';
            return;
        }

        const query = (state.addFieldSearchQuery || '').toLowerCase().trim();
        const filteredFields = (state.projectFields || []).filter(f => {
            if (!query) return true;
            return f.field_name.toLowerCase().includes(query) ||
                (f.field_label || '').toLowerCase().includes(query) ||
                (f.form_name || '').toLowerCase().includes(query) ||
                f.element_type.toLowerCase().includes(query);
        });

        let bodyContent = '';
        if (state.addFieldLoading) {
            bodyContent = `
                <div class="cdis-loading-centered" style="padding: 40px 20px; text-align: center;">
                    <i class="fas fa-spinner fa-spin fa-2x" style="color: #3b82f6; margin-bottom: 10px;"></i>
                    <div style="font-size: 0.9rem; color: #475569; font-weight: 600;">Loading project fields...</div>
                    <div style="font-size: 0.8rem; color: #94a3b8; margin-top: 4px;">Scanning text, radio, dropdown, and checkbox fields</div>
                </div>
            `;
        } else if (filteredFields.length === 0) {
            bodyContent = `
                <div style="padding: 32px 16px; text-align: center; color: #64748b;">
                    <i class="fas fa-search" style="font-size: 1.5rem; color: #94a3b8; margin-bottom: 8px;"></i>
                    <p style="margin: 0; font-size: 0.9rem;">No eligible fields found${query ? ` matching "${escapeHtml(state.addFieldSearchQuery)}"` : ''}.</p>
                </div>
            `;
        } else {
            const fieldRows = filteredFields.map(f => {
                const typeBadgeClass = f.element_type === 'text' ? 'cdis-badge-neutral' : 'cdis-badge-uscore';
                const typeLabel = f.element_type === 'select' ? 'dropdown' : f.element_type;
                const choiceInfo = ['radio', 'select', 'checkbox', 'advcheckbox'].includes(f.element_type)
                    ? `<span style="font-size: 0.75rem; color: #64748b; margin-left: 6px;">(${f.choice_count} choices)</span>`
                    : '';

                const actionHtml = f.is_already_added
                    ? `<span style="display: inline-flex; align-items: center; gap: 4px; color: #16a34a; font-size: 0.8rem; font-weight: 600; padding: 4px 10px; background: #f0fdf4; border-radius: 9999px; border: 1px solid #bbf7d0;">
                         <i class="fas fa-check" style="font-size: 0.7rem;"></i> Added
                       </span>`
                    : `<button class="cdis-btn cdis-btn-sm cdis-btn-primary btn-do-add-field" data-field="${escapeHtml(f.field_name)}" style="padding: 4px 12px; font-weight: 600;">
                         <i class="fas fa-plus"></i> Add
                       </button>`;

                return `
                    <div class="cdis-choice-item" style="display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; gap: 12px; border-bottom: 1px solid #f1f5f9;">
                        <div style="flex: 1; min-width: 0;">
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <strong style="color: #0f172a; font-size: 0.85rem;">${escapeHtml(f.field_name)}</strong>
                                <span class="cdis-badge ${typeBadgeClass}" style="text-transform: uppercase; font-size: 0.68rem; padding: 1px 6px;">${escapeHtml(typeLabel)}</span>
                                <span style="font-size: 0.75rem; color: #64748b;">Form: <strong>${escapeHtml(f.form_name)}</strong></span>
                                ${choiceInfo}
                            </div>
                            <div style="font-size: 0.8rem; color: #475569; margin-top: 2px; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;">
                                ${f.field_label ? escapeHtml(f.field_label) : '<em style="color:#94a3b8;">(No label defined)</em>'}
                            </div>
                        </div>
                        <div style="flex-shrink: 0;">
                            ${actionHtml}
                        </div>
                    </div>
                `;
            }).join('');

            bodyContent = `
                <div style="max-height: 420px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 6px;">
                    ${fieldRows}
                </div>
            `;
        }

        container.innerHTML = `
            <div class="cdis-modal-overlay">
                <div class="cdis-modal" style="max-width: 680px;">
                    <div class="cdis-modal-header">
                        <div class="cdis-modal-title">
                            <i class="fas fa-plus-circle" style="color: #2563eb; margin-right: 6px;"></i>
                            Add Field to Option Mapper
                        </div>
                        <button class="cdis-modal-close" id="btn-close-add-field-modal">&times;</button>
                    </div>
                    <div class="cdis-modal-body" style="padding: 18px;">
                        <p style="font-size: 0.85rem; color: #475569; margin-top: 0; margin-bottom: 12px;">
                            Select any <strong>text, radio, dropdown, or checkbox</strong> field in your project to map it to FHIR or LOINC ValueSets.
                        </p>
                        <div style="margin-bottom: 12px;">
                            <input type="text" id="input-add-field-search" class="cdis-search-input" 
                                   placeholder="Filter fields by name, label, or form..."
                                   value="${escapeHtml(state.addFieldSearchQuery || '')}" style="margin: 0; font-size: 0.85rem;">
                        </div>
                        ${bodyContent}
                    </div>
                    <div class="cdis-modal-footer" style="padding: 12px 18px; display: flex; justify-content: flex-end;">
                        <button class="cdis-btn cdis-btn-secondary" id="btn-cancel-add-field">Close</button>
                    </div>
                </div>
            </div>
        `;

        document.getElementById('btn-close-add-field-modal')?.addEventListener('click', () => {
            state.addFieldModalOpen = false;
            renderAddFieldModal();
        });
        document.getElementById('btn-cancel-add-field')?.addEventListener('click', () => {
            state.addFieldModalOpen = false;
            renderAddFieldModal();
        });

        const searchInput = document.getElementById('input-add-field-search');
        if (searchInput) {
            searchInput.focus();
            try {
                searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
            } catch (e) {}
            searchInput.addEventListener('input', (e) => {
                state.addFieldSearchQuery = e.target.value;
                renderAddFieldModal();
            });
        }

        container.querySelectorAll('.btn-do-add-field').forEach(btn => {
            btn.addEventListener('click', async () => {
                const fieldName = btn.getAttribute('data-field');
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
                await handleAddCustomField(fieldName);
            });
        });
    };

    const bindEvents = () => {
        document.getElementById('btn-add-field')?.addEventListener('click', async () => {
            state.addFieldModalOpen = true;
            state.addFieldSearchQuery = '';
            if (state.projectFieldsLoaded && state.projectFields.length > 0) {
                state.addFieldLoading = false;
                render();
            } else {
                state.addFieldLoading = true;
                render();
                await ensureProjectFields();
                state.addFieldLoading = false;
                renderAddFieldModal();
            }
        });

        document.querySelectorAll('.btn-remove-custom-field').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const fieldName = btn.getAttribute('data-field');
                if (!confirm(`Remove field "${fieldName}" from Option Mapper?\n\n(This only removes the field from Option Mapper; it does not delete the field from your REDCap project).`)) {
                    return;
                }

                // Optimistically remove from state.fields and reset added state
                const removedField = state.fields.find(f => f.field_name === fieldName);
                state.fields = state.fields.filter(f => f.field_name !== fieldName);
                const projField = (state.projectFields || []).find(f => f.field_name === fieldName);
                if (projField) projField.is_already_added = false;
                render();

                try {
                    const res = await module.ajax('remove_custom_field', { field_name: fieldName });
                    if (!res || !res.success) {
                        // Rollback if server fails
                        if (removedField) state.fields.push(removedField);
                        if (projField) projField.is_already_added = true;
                        render();
                        alert('Failed to remove field: ' + (res?.error || 'Unknown error'));
                    }
                } catch (err) {
                    if (removedField) state.fields.push(removedField);
                    if (projField) projField.is_already_added = true;
                    render();
                    alert('Request error: ' + err.message);
                }
            });
        });

        document.getElementById('btn-open-inspector')?.addEventListener('click', () => {
            state.inspectorModalOpen = true;
            renderInspectorModal();
        });

        document.getElementById('btn-open-confirm-update')?.addEventListener('click', () => {
            state.confirmModalOpen = true;
            renderConfirmModal();
        });

        document.querySelectorAll('.btn-edit-field').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const fieldName = e.currentTarget.getAttribute('data-field');
                openDiffModal(fieldName);
            });
        });
    };

    loadData();
});
