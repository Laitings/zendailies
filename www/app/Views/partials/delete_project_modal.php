<!-- UPDATED Deletion Modal for Projects -->
<!-- Includes: Split source/proxies, Database safety logic, New folder structure -->

<style>
    .zd-modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.85);
        z-index: 9998;
        animation: fadeIn 0.2s ease;
    }

    .zd-modal-overlay.is-open {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .zd-modal {
        background: var(--zd-bg-panel);
        border: 1px solid var(--zd-border-subtle);
        border-radius: 8px;
        width: 90%;
        max-width: 580px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        animation: slideUp 0.3s ease;
    }

    .zd-modal-header {
        padding: 20px 24px;
        border-bottom: 1px solid var(--zd-border-subtle);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .zd-modal-header h2 {
        font-size: 18px;
        font-weight: 700;
        color: var(--zd-text-main);
        margin: 0;
    }

    .zd-modal-close {
        background: transparent;
        border: none;
        color: var(--zd-text-muted);
        font-size: 24px;
        cursor: pointer;
        padding: 0;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 4px;
        transition: all 0.2s;
    }

    .zd-modal-close:hover {
        background: rgba(255, 255, 255, 0.05);
        color: var(--zd-text-main);
    }

    .zd-modal-body {
        padding: 24px;
    }

    .zd-modal-warning {
        background: rgba(231, 76, 60, 0.1);
        border: 1px solid rgba(231, 76, 60, 0.3);
        border-radius: 6px;
        padding: 16px;
        margin-bottom: 20px;
        display: flex;
        gap: 12px;
        align-items: flex-start;
    }

    .zd-modal-warning-icon {
        font-size: 24px;
        line-height: 1;
    }

    .zd-modal-warning-text {
        flex: 1;
        font-size: 13px;
        line-height: 1.5;
        color: #ffb0b0;
    }

    .zd-modal-section {
        margin-bottom: 24px;
    }

    .zd-modal-section-title {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--zd-text-muted);
        margin-bottom: 12px;
    }

    .zd-checkbox-group {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .zd-checkbox-item {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 10px;
        background: var(--zd-bg-input);
        border: 1px solid var(--zd-border-subtle);
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .zd-checkbox-item:hover {
        border-color: var(--zd-text-muted);
    }

    .zd-checkbox-item.is-disabled {
        opacity: 0.5;
        cursor: not-allowed;
        background: rgba(31, 35, 45, 0.5);
    }

    .zd-checkbox-item.is-disabled:hover {
        border-color: var(--zd-border-subtle);
    }

    .zd-checkbox-item input[type="checkbox"] {
        width: 18px;
        height: 18px;
        margin-top: 2px;
        cursor: pointer;
        accent-color: var(--zd-danger);
    }

    .zd-checkbox-item input[type="checkbox"]:disabled {
        cursor: not-allowed;
    }

    .zd-checkbox-item-content {
        flex: 1;
    }

    .zd-checkbox-item-label {
        font-weight: 600;
        font-size: 13px;
        color: var(--zd-text-main);
        margin-bottom: 4px;
    }

    .zd-checkbox-item-desc {
        font-size: 11px;
        color: var(--zd-text-muted);
        line-height: 1.4;
    }

    .zd-modal-footer {
        padding: 16px 24px;
        border-top: 1px solid var(--zd-border-subtle);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
    }

    .zd-modal-footer-info {
        font-size: 11px;
        color: var(--zd-text-muted);
        flex: 1;
    }

    .zd-modal-actions {
        display: flex;
        gap: 10px;
    }

    .zd-btn-danger {
        background: var(--zd-danger);
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 4px;
        font-weight: 600;
        font-size: 13px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .zd-btn-danger:hover {
        opacity: 0.9;
    }

    .zd-btn-danger:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }

    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
</style>

<!-- The Modal -->
<div id="deleteProjectModal" class="zd-modal-overlay">
    <div class="zd-modal">
        <div class="zd-modal-header">
            <h2>Delete Project</h2>
            <button type="button" class="zd-modal-close" onclick="zdCloseDeleteModal()">×</button>
        </div>

        <form id="deleteProjectForm" method="post">
            <input type="hidden" name="_csrf" value="<?= \App\Support\Csrf::token() ?>">
            <input type="hidden" name="project_uuid" id="deleteProjectUuid">

            <div class="zd-modal-body">
                <div class="zd-modal-warning">
                    <span class="zd-modal-warning-icon">⚠️</span>
                    <div class="zd-modal-warning-text">
                        <strong>This action is permanent.</strong> You're about to delete <strong id="deleteProjectTitle"></strong>.
                        Choose what to delete below. Unchecked items will be preserved.
                    </div>
                </div>

                <div class="zd-modal-section">
                    <div class="zd-modal-section-title">What should be deleted?</div>

                    <div class="zd-checkbox-group">
                        <label class="zd-checkbox-item" id="checkbox-source">
                            <input type="checkbox" name="delete_source" value="1" id="delete_source">
                            <div class="zd-checkbox-item-content">
                                <div class="zd-checkbox-item-label">Source Files (Original)</div>
                                <div class="zd-checkbox-item-desc">
                                    Original uploaded video files. This frees up the most storage space.
                                </div>
                            </div>
                        </label>

                        <label class="zd-checkbox-item" id="checkbox-proxies">
                            <input type="checkbox" name="delete_proxies" value="1" id="delete_proxies">
                            <div class="zd-checkbox-item-content">
                                <div class="zd-checkbox-item-label">Proxy Files (Web)</div>
                                <div class="zd-checkbox-item-desc">
                                    Web-optimized H.264 versions for playback. Keep these for continued viewing.
                                </div>
                            </div>
                        </label>

                        <label class="zd-checkbox-item" id="checkbox-posters">
                            <input type="checkbox" name="delete_posters" value="1" id="delete_posters">
                            <div class="zd-checkbox-item-content">
                                <div class="zd-checkbox-item-label">Poster Images</div>
                                <div class="zd-checkbox-item-desc">
                                    Thumbnail posters for each clip. Keep these for visual reference.
                                </div>
                            </div>
                        </label>

                        <label class="zd-checkbox-item" id="checkbox-waveforms">
                            <input type="checkbox" name="delete_waveforms" value="1" id="delete_waveforms">
                            <div class="zd-checkbox-item-content">
                                <div class="zd-checkbox-item-label">Waveforms</div>
                                <div class="zd-checkbox-item-desc">
                                    Audio waveform visualizations. Small files, useful for reference.
                                </div>
                            </div>
                        </label>

                        <label class="zd-checkbox-item" id="checkbox-metadata">
                            <input type="checkbox" name="delete_metadata" value="1" id="delete_metadata">
                            <div class="zd-checkbox-item-content">
                                <div class="zd-checkbox-item-label">Clip Metadata</div>
                                <div class="zd-checkbox-item-desc">
                                    Scene numbers, takes, durations, FPS. Keep for historical reference.
                                </div>
                            </div>
                        </label>

                        <label class="zd-checkbox-item" id="checkbox-comments">
                            <input type="checkbox" name="delete_comments" value="1" id="delete_comments">
                            <div class="zd-checkbox-item-content">
                                <div class="zd-checkbox-item-label">Comments & Reviews</div>
                                <div class="zd-checkbox-item-desc">
                                    All feedback and annotations left by team members.
                                </div>
                            </div>
                        </label>

                        <label class="zd-checkbox-item" id="checkbox-database">
                            <input type="checkbox" name="delete_database" value="1" id="delete_database">
                            <div class="zd-checkbox-item-content">
                                <div class="zd-checkbox-item-label">Database Records</div>
                                <div class="zd-checkbox-item-desc">
                                    Remove project, days, clips from database. Forces deletion of all above items.
                                </div>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="zd-modal-section">
                    <div class="zd-modal-section-title">Confirmation</div>
                    <input
                        type="text"
                        id="confirmProjectCode"
                        name="confirm_code"
                        class="zd-input"
                        placeholder="Type project code to confirm"
                        autocomplete="off"
                        style="width: 100%;">
                </div>
            </div>

            <div class="zd-modal-footer">
                <div class="zd-modal-footer-info">
                    Type the project code above to enable deletion.
                </div>
                <div class="zd-modal-actions">
                    <button type="button" class="zd-btn zd-btn-ghost" onclick="zdCloseDeleteModal()">Cancel</button>
                    <button type="submit" class="zd-btn zd-btn-danger" id="confirmDeleteBtn" disabled>
                        Delete Project
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    let zdCurrentProjectCode = '';

    function zdOpenDeleteModal(projectUuid, projectTitle, projectCode) {
        zdCurrentProjectCode = projectCode;
        document.getElementById('deleteProjectUuid').value = projectUuid;
        document.getElementById('deleteProjectTitle').textContent = projectTitle;
        document.getElementById('deleteProjectModal').classList.add('is-open');
        document.getElementById('confirmProjectCode').value = '';
        document.getElementById('confirmDeleteBtn').disabled = true;

        // Reset all checkboxes to unchecked and enabled
        const checkboxes = ['delete_source', 'delete_proxies', 'delete_posters', 'delete_waveforms', 'delete_metadata', 'delete_comments', 'delete_database'];
        checkboxes.forEach(id => {
            const checkbox = document.getElementById(id);
            checkbox.checked = false;
            checkbox.disabled = false;
            document.getElementById('checkbox-' + id.replace('delete_', '')).classList.remove('is-disabled');
        });

        document.body.style.overflow = 'hidden';
    }

    function zdCloseDeleteModal() {
        document.getElementById('deleteProjectModal').classList.remove('is-open');
        document.body.style.overflow = '';
    }

    // Enable delete button only when project code matches
    document.getElementById('confirmProjectCode')?.addEventListener('input', function(e) {
        const matches = e.target.value.toUpperCase() === zdCurrentProjectCode.toUpperCase();
        document.getElementById('confirmDeleteBtn').disabled = !matches;
    });

    // Database checkbox logic: when checked, force all others checked and disabled
    document.getElementById('delete_database')?.addEventListener('change', function(e) {
        const isChecked = e.target.checked;
        const checkboxes = ['delete_source', 'delete_proxies', 'delete_posters', 'delete_waveforms', 'delete_metadata', 'delete_comments'];

        checkboxes.forEach(id => {
            const checkbox = document.getElementById(id);
            const container = document.getElementById('checkbox-' + id.replace('delete_', ''));

            if (isChecked) {
                // Database is checked: force all others checked and disabled
                checkbox.checked = true;
                checkbox.disabled = true;
                container.classList.add('is-disabled');
            } else {
                // Database unchecked: enable all others
                checkbox.disabled = false;
                container.classList.remove('is-disabled');
            }
        });
    });

    // Close modal on overlay click
    document.getElementById('deleteProjectModal')?.addEventListener('click', function(e) {
        if (e.target === this) {
            zdCloseDeleteModal();
        }
    });

    // Handle form submission
    document.getElementById('deleteProjectForm')?.addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(this);
        const projectUuid = formData.get('project_uuid');

        // Show loading state
        const btn = document.getElementById('confirmDeleteBtn');
        btn.disabled = true;
        btn.textContent = 'Deleting...';

        // Submit via fetch
        fetch(`/admin/projects/${projectUuid}/delete`, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    // Success - redirect to index
                    window.location.href = '/admin/projects';
                } else {
                    return response.text().then(text => {
                        throw new Error(text || 'Delete failed');
                    });
                }
            })
            .catch(error => {
                alert('Error deleting project: ' + error.message);
                btn.disabled = false;
                btn.textContent = 'Delete Project';
            });
    });

    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('deleteProjectModal').classList.contains('is-open')) {
            zdCloseDeleteModal();
        }
    });
</script>