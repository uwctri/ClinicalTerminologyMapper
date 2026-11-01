function initManagerScript() {
    const module = window.ExternalModules?.UWMadison?.ClinicalTerminologyMapper;
    const btn = document.getElementById('btn-build-db');
    if (!module || !btn) return;

    const escapeHtml = (str) => String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const spinner = document.getElementById('build-spinner');
    const icon = document.getElementById('build-icon');
    const statusText = document.getElementById('build-status-text');
    const checkAll = document.getElementById('check-all-packages');
    const pkgBoxes = document.querySelectorAll('.pkg-checkbox');
    const updateModal = document.getElementById('update-summary-modal');
    const modalConfirmBtn = document.getElementById('btn-modal-confirm-update');
    let pendingSelectedPackages = [];

    const isDbValid = btn.dataset.dbValid === '1';

    if (checkAll) {
        const syncCheckAll = () => {
            const allChecked = Array.from(pkgBoxes).every(c => c.checked);
            const someChecked = Array.from(pkgBoxes).some(c => c.checked);
            checkAll.checked = allChecked;
            checkAll.indeterminate = (!allChecked && someChecked);
        };
        syncCheckAll();
        checkAll.addEventListener('change', function() {
            pkgBoxes.forEach(cb => cb.checked = checkAll.checked);
        });
        pkgBoxes.forEach(cb => {
            cb.addEventListener('change', syncCheckAll);
        });
    }

    btn.addEventListener('click', function() {
        const checkedBoxes = Array.from(pkgBoxes).filter(c => c.checked);
        pendingSelectedPackages = checkedBoxes.map(c => c.value);

        if (pendingSelectedPackages.length === 0) {
            alert('Please select at least one package to include in the database.');
            return;
        }

        const headerIcon = document.getElementById('update-modal-header-icon');
        const headerTitle = document.getElementById('update-modal-header-title');
        const intro = document.getElementById('update-modal-intro');
        const addSec = document.getElementById('update-modal-add-section');
        const addHeader = document.getElementById('update-modal-add-header-title');
        const addList = document.getElementById('update-modal-add-list');
        const removeSec = document.getElementById('update-modal-remove-section');
        const removeHeader = document.getElementById('update-modal-remove-header-title');
        const removeList = document.getElementById('update-modal-remove-list');
        const unchangeSec = document.getElementById('update-modal-unchanged-section');
        const unchangeHeader = document.getElementById('update-modal-unchanged-header-title');
        const unchangeList = document.getElementById('update-modal-unchanged-list');
        const noChangesAlert = document.getElementById('update-modal-no-changes');
        const timingHint = document.getElementById('update-modal-timing-hint');
        const confirmBtnText = document.getElementById('update-modal-confirm-btn-text');

        if (!isDbValid) {
            // Initial build mode
            headerIcon.className = 'fas fa-database text-primary';
            headerTitle.textContent = 'Confirm Initial Database Build';
            intro.innerHTML = 'You are about to build the initial clinical terminology database with <strong>' + checkedBoxes.length + ' package(s)</strong>:';

            addSec.style.display = 'block';
            addHeader.textContent = 'Packages to Compile (' + checkedBoxes.length + ')';
            let addHtml = '';
            checkedBoxes.forEach(cb => {
                const count = parseInt(cb.dataset.count, 10) || 0;
                addHtml += '<div style="display: flex; justify-content: space-between; align-items: center; padding: 4px 0; border-bottom: 1px solid rgba(187,247,208,0.5);">' +
                    '<span><strong>' + escapeHtml(cb.dataset.name) + '</strong></span>' +
                    '<span class="badge" style="background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-weight: 600;">' + count.toLocaleString() + ' ValueSets</span>' +
                    '</div>';
            });
            addList.innerHTML = addHtml;
            removeSec.style.display = 'none';
            unchangeSec.style.display = 'none';
            noChangesAlert.style.display = 'none';
            timingHint.style.display = 'block';
            timingHint.innerHTML = '<i class="far fa-clock"></i> Initial database compilation takes approximately 30–60 seconds.';
            confirmBtnText.textContent = 'Confirm & Build Database';
            modalConfirmBtn.disabled = false;
        } else {
            // Differential update mode
            headerIcon.className = 'fas fa-layer-group text-primary';
            headerTitle.textContent = 'Confirm Terminology Database Update';
            intro.innerHTML = 'Review the planned changes to your clinical terminology database:';

            const toAdd = checkedBoxes.filter(c => c.dataset.installed !== '1');
            const toRemove = Array.from(pkgBoxes).filter(c => !c.checked && c.dataset.installed === '1');
            const unchanged = checkedBoxes.filter(c => c.dataset.installed === '1');

            if (toAdd.length === 0 && toRemove.length === 0) {
                addSec.style.display = 'none';
                removeSec.style.display = 'none';
                unchangeSec.style.display = 'none';
                noChangesAlert.style.display = 'block';
                timingHint.style.display = 'none';
                confirmBtnText.textContent = 'No Changes to Apply';
                modalConfirmBtn.disabled = true;
            } else {
                noChangesAlert.style.display = 'none';
                timingHint.style.display = 'block';
                timingHint.innerHTML = '<i class="far fa-clock"></i> In-place differential updates typically take 15–35 seconds.';
                confirmBtnText.textContent = 'Confirm & Update Database';
                modalConfirmBtn.disabled = false;

                // To Add
                if (toAdd.length > 0) {
                    addSec.style.display = 'block';
                    addHeader.textContent = 'Packages to Install (' + toAdd.length + ')';
                    let addHtml = '';
                    toAdd.forEach(cb => {
                        const count = parseInt(cb.dataset.count, 10) || 0;
                        addHtml += '<div style="display: flex; justify-content: space-between; align-items: center; padding: 4px 0; border-bottom: 1px solid rgba(187,247,208,0.5);">' +
                            '<span><i class="fas fa-plus" style="font-size: 0.75rem; margin-right: 6px; color: #16a34a;"></i><strong>' + escapeHtml(cb.dataset.name) + '</strong></span>' +
                            '<span class="badge" style="background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; font-weight: 600;">+' + count.toLocaleString() + ' ValueSets</span>' +
                            '</div>';
                    });
                    addList.innerHTML = addHtml;
                } else {
                    addSec.style.display = 'none';
                }

                // To Remove
                if (toRemove.length > 0) {
                    removeSec.style.display = 'block';
                    removeHeader.textContent = 'Packages to Remove (' + toRemove.length + ')';
                    let remHtml = '';
                    toRemove.forEach(cb => {
                        const count = parseInt(cb.dataset.count, 10) || 0;
                        remHtml += '<div style="display: flex; justify-content: space-between; align-items: center; padding: 4px 0; border-bottom: 1px solid rgba(254,202,202,0.5);">' +
                            '<span><i class="fas fa-minus" style="font-size: 0.75rem; margin-right: 6px; color: #dc2626;"></i><strong>' + escapeHtml(cb.dataset.name) + '</strong></span>' +
                            '<span class="badge" style="background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; font-weight: 600;">-' + count.toLocaleString() + ' ValueSets</span>' +
                            '</div>';
                    });
                    removeList.innerHTML = remHtml;
                } else {
                    removeSec.style.display = 'none';
                }

                // Unchanged
                if (unchanged.length > 0) {
                    unchangeSec.style.display = 'block';
                    unchangeHeader.textContent = 'Remaining Active (' + unchanged.length + ')';
                    let uncHtml = '';
                    unchanged.forEach(cb => {
                        const count = parseInt(cb.dataset.count, 10) || 0;
                        uncHtml += '<span style="display: inline-flex; align-items: center; gap: 4px; background: #e2e8f0; color: #334155; padding: 2px 8px; border-radius: 4px; font-size: 0.78rem;">' +
                            '<i class="fas fa-check" style="font-size: 0.65rem; color: #16a34a;"></i> ' + escapeHtml(cb.dataset.name) + ' (' + count + ')' +
                            '</span>';
                    });
                    unchangeList.innerHTML = uncHtml;
                } else {
                    unchangeSec.style.display = 'none';
                }
            }
        }

        showModal(updateModal);
    });

    modalConfirmBtn.addEventListener('click', async function() {
        closeModal(updateModal);

        if (pendingSelectedPackages.length === 0) return;

        btn.disabled = true;
        spinner.style.display = 'inline-block';
        spinner.classList.add('fa-spin');
        icon.style.display = 'none';
        const actionText = isDbValid ? 'Updating terminology database...' : 'Downloading and building database (approx. 20–60s)...';
        statusText.innerHTML = '<span style="color:#2563eb;"><i class="fas fa-spinner fa-spin"></i> ' + actionText + '</span>';

        try {
            const data = await module.ajax('build_terminology', { packages: pendingSelectedPackages });

            if (data && data.success) {
                const successMsg = isDbValid ? 'Database updated successfully' : 'Build successful';
                statusText.innerHTML = '<span style="color:#16a34a; font-weight:600;"><i class="fas fa-check-circle"></i> ' + successMsg + ' (' + escapeHtml(data.valueset_count || 0) + ' ValueSets, ' + escapeHtml(data.concept_count || 0) + ' concepts in ' + escapeHtml(data.elapsed_seconds || '') + 's)! Reloading...</span>';
                setTimeout(() => window.location.reload(), 1500);
            } else {
                statusText.innerHTML = '<span style="color:#ef4444; font-weight:600;"><i class="fas fa-times-circle"></i> ' + (isDbValid ? 'Update' : 'Build') + ' failed: ' + escapeHtml((data && data.error) || 'Unknown error') + '</span>';
                btn.disabled = false;
                spinner.style.display = 'none';
                icon.style.display = 'inline-block';
            }
        } catch (err) {
            statusText.innerHTML = '<span style="color:#ef4444; font-weight:600;"><i class="fas fa-times-circle"></i> Request error: ' + escapeHtml(err.message) + '</span>';
            btn.disabled = false;
            spinner.style.display = 'none';
            icon.style.display = 'inline-block';
        }
    });

    // Toggle Truncated ValueSets visibility
    const toggleBtn = document.getElementById('btn-toggle-truncated');
    const truncBody = document.getElementById('truncated-section-body');
    const truncIcon = document.getElementById('toggle-trunc-icon');
    const truncText = document.getElementById('toggle-trunc-text');

    if (toggleBtn && truncBody) {
        toggleBtn.addEventListener('click', function() {
            const isHidden = (truncBody.style.display === 'none' || truncBody.style.display === '');
            if (isHidden) {
                truncBody.style.display = 'block';
                truncIcon.className = 'fas fa-chevron-up';
                truncText.textContent = 'Hide ValueSets';
            } else {
                truncBody.style.display = 'none';
                truncIcon.className = 'fas fa-chevron-down';
                truncText.textContent = 'Show ValueSets';
            }
        });
    }

    // Unified Modal Helpers
    const expandModal = document.getElementById('expand-warning-modal');
    let currentExpandParams = null;

    function showModal(modalEl) {
        if (!modalEl) return;
        let shown = false;

        if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
            try {
                const bsModal = window.bootstrap.Modal.getOrCreateInstance(modalEl, { backdrop: 'static', keyboard: false });
                if (bsModal) {
                    bsModal.show();
                    shown = true;
                }
            } catch (e) {
                console.warn('Bootstrap 5 modal show error:', e);
            }
        } else if (window.jQuery && typeof window.jQuery(modalEl).modal === 'function') {
            try {
                window.jQuery(modalEl).modal('show');
                shown = true;
            } catch (e) {
                console.warn('jQuery modal show error:', e);
            }
        }

        if (!shown) {
            modalEl.style.cssText = 'display: block !important; position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 1055; overflow-x: hidden; overflow-y: auto; outline: 0; background: rgba(15, 23, 42, 0.6);';
            modalEl.classList.add('show');
            document.body.classList.add('modal-open');
        }
    }

    function closeModal(modalEl) {
        if (!modalEl) return;
        if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
            try {
                const bsModal = window.bootstrap.Modal.getInstance(modalEl);
                if (bsModal) bsModal.hide();
            } catch (e) {}
        } else if (window.jQuery && typeof window.jQuery(modalEl).modal === 'function') {
            try {
                window.jQuery(modalEl).modal('hide');
            } catch (e) {}
        }

        modalEl.style.display = 'none';
        modalEl.classList.remove('show');
        document.body.classList.remove('modal-open');
        document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
    }

    // Close event delegation
    document.addEventListener('click', function(e) {
        if (e.target.closest('.modal-close-trigger') || 
            e.target.closest('[data-dismiss="modal"]') || 
            e.target.closest('[data-bs-dismiss="modal"]')) {
            const modal = e.target.closest('.modal');
            if (modal) {
                closeModal(modal);
            } else {
                closeModal(expandModal);
                closeModal(updateModal);
            }
        } else if (e.target === expandModal) {
            closeModal(expandModal);
        } else if (e.target === updateModal) {
            closeModal(updateModal);
        }
    });

    // Expand single ValueSet button handler (delegated)
    document.addEventListener('click', function(e) {
        const expandBtn = e.target.closest('.btn-expand-vs');
        if (!expandBtn) return;
        e.preventDefault();

        const vsId = expandBtn.getAttribute('data-id');
        const pkg = expandBtn.getAttribute('data-package');
        const title = expandBtn.getAttribute('data-title') || vsId;
        const system = expandBtn.getAttribute('data-system');
        const countStr = expandBtn.getAttribute('data-count') || '0';
        const count = parseInt(countStr, 10);
        const total = expandBtn.getAttribute('data-total') || 'Unknown';
        const source = expandBtn.getAttribute('data-source');
        const notes = expandBtn.getAttribute('data-notes');
        const isLarge = expandBtn.getAttribute('data-large') === '1';

        currentExpandParams = { id: vsId, package: pkg, btn: expandBtn };

        const titleEl = document.getElementById('expand-modal-title');
        if (titleEl) titleEl.textContent = title;

        const idEl = document.getElementById('expand-modal-id');
        if (idEl) idEl.textContent = vsId;

        let pkgSysText = pkg;
        if (system) pkgSysText += ` (${system})`;
        const pkgEl = document.getElementById('expand-modal-pkg-system');
        if (pkgEl) pkgEl.textContent = pkgSysText;

        const currEl = document.getElementById('expand-modal-current');
        if (currEl) currEl.textContent = (!isNaN(count) && count > 0) ? count.toLocaleString() + ' concepts' : '0 concepts';

        const totEl = document.getElementById('expand-modal-total');
        if (totEl) {
            const numericTot = Number(total);
            if (!isNaN(numericTot) && total.trim() !== '') {
                totEl.textContent = numericTot.toLocaleString() + ' concepts';
            } else {
                totEl.textContent = total;
            }
        }

        const notesContainer = document.getElementById('expand-modal-notes-container');
        const notesElem = document.getElementById('expand-modal-notes');
        if (notesContainer && notesElem) {
            if (notes || source) {
                notesElem.textContent = notes ? (source ? `${notes} (${source})` : notes) : source;
                notesContainer.style.display = 'block';
            } else {
                notesContainer.style.display = 'none';
            }
        }

        // Normal vs. Danger alert
        const normalAlert = document.getElementById('expand-modal-normal-alert');
        const dangerAlert = document.getElementById('expand-modal-danger-alert');
        const confirmBtn = document.getElementById('btn-confirm-expand');
        const cancelBtn = document.getElementById('btn-cancel-expand');
        const headerIcon = document.getElementById('expand-modal-header-icon');

        if (isLarge) {
            if (normalAlert) normalAlert.style.display = 'none';
            if (dangerAlert) dangerAlert.style.display = 'block';
            if (confirmBtn) {
                confirmBtn.className = 'btn btn-sm btn-danger';
                confirmBtn.innerHTML = '<i class="fas fa-expand-arrows-alt"></i> Proceed with Expansion';
            }
            if (headerIcon) headerIcon.className = 'fas fa-exclamation-circle text-danger';
        } else {
            if (normalAlert) normalAlert.style.display = 'block';
            if (dangerAlert) dangerAlert.style.display = 'none';
            if (confirmBtn) {
                confirmBtn.className = 'btn btn-sm btn-primary';
                confirmBtn.innerHTML = '<i class="fas fa-expand-arrows-alt"></i> Proceed with Expansion';
            }
            if (headerIcon) headerIcon.className = 'fas fa-expand-arrows-alt text-primary';
        }

        // Reset progress & alerts
        const prog = document.getElementById('expand-modal-progress');
        if (prog) prog.style.display = 'none';
        const err = document.getElementById('expand-modal-error');
        if (err) err.style.display = 'none';
        const succ = document.getElementById('expand-modal-success');
        if (succ) succ.style.display = 'none';

        if (confirmBtn) confirmBtn.disabled = false;
        if (cancelBtn) cancelBtn.disabled = false;

        showModal(expandModal);
    });

    // Modal confirm button handler
    const btnConfirmExpand = document.getElementById('btn-confirm-expand');
    if (btnConfirmExpand) {
        btnConfirmExpand.addEventListener('click', async function() {
            if (!currentExpandParams) return;

            const { id, package: pkg } = currentExpandParams;
            const progress = document.getElementById('expand-modal-progress');
            const errorBox = document.getElementById('expand-modal-error');
            const successBox = document.getElementById('expand-modal-success');
            const cancelBtn = document.getElementById('btn-cancel-expand');

            btnConfirmExpand.disabled = true;
            if (cancelBtn) cancelBtn.disabled = true;
            if (progress) progress.style.display = 'block';
            if (errorBox) errorBox.style.display = 'none';
            if (successBox) successBox.style.display = 'none';

            try {
                const res = await module.ajax('expand_valueset', { id: id, package: pkg });
                if (res && res.success) {
                    if (progress) progress.style.display = 'none';
                    const prevCount = res.previous_count != null ? res.previous_count.toLocaleString() : '0';
                    const newCount = res.new_count != null ? res.new_count.toLocaleString() : '0';
                    if (successBox) {
                        successBox.innerHTML = `<strong><i class="fas fa-check-circle"></i> Success!</strong> ValueSet '${escapeHtml(id)}' expanded from ${escapeHtml(prevCount)} to ${escapeHtml(newCount)} concepts. Reloading page...`;
                        successBox.style.display = 'block';
                    }
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    if (progress) progress.style.display = 'none';
                    if (errorBox) {
                        errorBox.innerHTML = `<strong><i class="fas fa-times-circle"></i> Expansion Failed:</strong> ${escapeHtml(res?.error || 'Unknown error occurred')}`;
                        errorBox.style.display = 'block';
                    }
                    btnConfirmExpand.disabled = false;
                    if (cancelBtn) cancelBtn.disabled = false;
                }
            } catch (e) {
                if (progress) progress.style.display = 'none';
                if (errorBox) {
                    errorBox.innerHTML = `<strong><i class="fas fa-times-circle"></i> Request Error:</strong> ${escapeHtml(e.message)}`;
                    errorBox.style.display = 'block';
                }
                btnConfirmExpand.disabled = false;
                if (cancelBtn) cancelBtn.disabled = false;
            }
        });
    }

    // LOINC sync handler
    const btnSyncLoinc = document.getElementById('btn-sync-loinc');
    const loincUser = document.getElementById('loinc-username');
    const loincPass = document.getElementById('loinc-password');
    const loincMsg = document.getElementById('loinc-status-msg');
    const loincSyncIcon = document.getElementById('loinc-sync-icon');
    const loincSyncSpinner = document.getElementById('loinc-sync-spinner');

    if (btnSyncLoinc) {
        btnSyncLoinc.addEventListener('click', async function() {
            const username = loincUser ? loincUser.value.trim() : '';
            const password = loincPass ? loincPass.value : '';

            if (!username || !password) {
                alert('Please enter your LOINC username (or email) and password. A free account can be registered at https://loinc.org/join.');
                if (!username && loincUser) loincUser.focus();
                else if (loincPass) loincPass.focus();
                return;
            }

            btnSyncLoinc.disabled = true;
            if (loincSyncIcon) loincSyncIcon.style.display = 'none';
            if (loincSyncSpinner) loincSyncSpinner.style.display = 'inline-block';
            loincMsg.innerHTML = '<span style="color:#2563eb;"><i class="fas fa-spinner fa-spin"></i> Contacting fhir.loinc.org and downloading top lab subset...</span>';

            try {
                const res = await module.ajax('sync_loinc', { username: username, password: password });
                if (res && res.success) {
                    loincMsg.innerHTML = `<span style="color:#16a34a; font-weight:600;"><i class="fas fa-check-circle"></i> Success! Synced ${escapeHtml(res.concept_count)} LOINC concepts. Reloading...</span>`;
                    if (loincPass) loincPass.value = '';
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    loincMsg.innerHTML = `<span style="color:#ef4444; font-weight:600;"><i class="fas fa-times-circle"></i> Error: ${escapeHtml(res?.error || 'Failed to sync LOINC')}</span>`;
                    btnSyncLoinc.disabled = false;
                    if (loincSyncIcon) loincSyncIcon.style.display = 'inline-block';
                    if (loincSyncSpinner) loincSyncSpinner.style.display = 'none';
                }
            } catch (e) {
                loincMsg.innerHTML = `<span style="color:#ef4444; font-weight:600;"><i class="fas fa-times-circle"></i> Request error: ${escapeHtml(e.message)}</span>`;
                btnSyncLoinc.disabled = false;
                if (loincSyncIcon) loincSyncIcon.style.display = 'inline-block';
                if (loincSyncSpinner) loincSyncSpinner.style.display = 'none';
            }
        });
    }

    // LOINC purge handler
    const btnPurgeLoinc = document.getElementById('btn-purge-loinc');
    if (btnPurgeLoinc) {
        btnPurgeLoinc.addEventListener('click', async function() {
            if (!confirm('Are you sure you want to remove the LOINC Common Lab Observations package and all its concepts from the database?')) {
                return;
            }

            btnPurgeLoinc.disabled = true;
            loincMsg.innerHTML = '<span style="color:#dc2626;"><i class="fas fa-spinner fa-spin"></i> Removing LOINC package...</span>';

            try {
                const res = await module.ajax('purge_loinc', {});
                if (res && res.success) {
                    loincMsg.innerHTML = '<span style="color:#16a34a; font-weight:600;"><i class="fas fa-check-circle"></i> LOINC package removed. Reloading...</span>';
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    loincMsg.innerHTML = `<span style="color:#ef4444; font-weight:600;"><i class="fas fa-times-circle"></i> Error: ${escapeHtml(res?.error || 'Failed to remove LOINC')}</span>`;
                    btnPurgeLoinc.disabled = false;
                }
            } catch (e) {
                loincMsg.innerHTML = `<span style="color:#ef4444; font-weight:600;"><i class="fas fa-times-circle"></i> Request error: ${escapeHtml(e.message)}</span>`;
                btnPurgeLoinc.disabled = false;
            }
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initManagerScript);
} else {
    initManagerScript();
}
