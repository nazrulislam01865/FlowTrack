/* FlowTrack generated lazy module: task-details. Source: resources/js/flowtrack/flowtrack-dynamic-source.js. Build: 6254af6ca40e4526. */
function flowtrackTaskTemplateSlug(value) {
        return String(value ?? 'task').replace(/[^a-z0-9]+/gi, '-').toLowerCase();
    }

    function flowtrackTaskTemplateCurrent(identifier) {
        return tasks.find((item) => String(item.id) === String(identifier) || Number(item.dbId) === Number(identifier)) || null;
    }

    function flowtrackTaskTemplateJob(task) {
        const loadedJob = jobs.find((item) => String(item.id) === String(task?.job) || Number(item.dbId) === Number(task?.jobDbId)) || null;
        if (task?.jobContext && typeof task.jobContext === 'object') {
            return { ...(loadedJob || {}), ...task.jobContext };
        }
        return loadedJob;
    }

    function flowtrackTaskTemplateDate(value, fallback = 'Not set') {
        if (!value) return fallback;
        const source = String(value).slice(0, 10);
        const date = new Date(`${source}T00:00:00`);
        if (Number.isNaN(date.getTime())) return fallback;
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function flowtrackTaskTemplateDateTime(value, fallback = '') {
        if (!value) return fallback;
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return fallback;
        return date.toLocaleString('en-US', {
            month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit',
        });
    }

    function flowtrackTaskTemplateRelative(value, fallback = '') {
        if (!value) return fallback;
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return fallback;
        const seconds = Math.max(0, Math.round((Date.now() - date.getTime()) / 1000));
        if (seconds < 60) return 'just now';
        const minutes = Math.round(seconds / 60);
        if (minutes < 60) return `${minutes} minute${minutes === 1 ? '' : 's'} ago`;
        const hours = Math.round(minutes / 60);
        if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`;
        const days = Math.round(hours / 24);
        if (days < 30) return `${days} day${days === 1 ? '' : 's'} ago`;
        return flowtrackTaskTemplateDate(String(value).slice(0, 10), fallback);
    }

    function flowtrackTaskTemplateStatusTone(status) {
        const value = normalizedStatusValue(status?.code || status?.name || status);
        if (['CMP', 'COMPLETED', 'COMPLETE', 'DONE', 'APPROVED'].includes(value)) return 'green';
        if (['BLK', 'BLOCKED', 'ATTN', 'ATTENTION', 'HOLD', 'ON HOLD', 'REV', 'REVISION REQUIRED'].includes(value)) return 'red';
        if (['WC', 'WAITING FOR CLIENT', 'WS', 'WAITING FOR SUPPLIER', 'WIA', 'WAITING FOR INTERNAL APPROVAL', 'WAITING', 'PENDING'].includes(value)) return 'orange';
        if (['IP', 'IN PROGRESS'].includes(value)) return 'indigo';
        if (['READY'].includes(value)) return 'blue';
        return 'gray';
    }

    function flowtrackTaskTemplatePriorityTone(priority) {
        const value = String(priority || '').trim().toLowerCase();
        if (value.includes('critical')) return 'red';
        if (value.includes('high') || value.includes('urgent')) return 'orange';
        if (value.includes('medium') || value.includes('normal')) return 'amber';
        if (value.includes('low')) return 'green';
        return 'gray';
    }

    function flowtrackTaskTemplateFileSize(bytes) {
        const size = Number(bytes || 0);
        if (!size) return 'Unknown size';
        if (size < 1024) return `${size} B`;
        if (size < 1024 * 1024) return `${Math.round(size / 1024)} KB`;
        return `${(size / (1024 * 1024)).toFixed(size >= 10 * 1024 * 1024 ? 0 : 1)} MB`;
    }

    function flowtrackTaskTemplateSvg(name) {
        const icons = {
            calendar: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3v3M17 3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z"/></svg>',
            paperclip: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8.5 12.5 6.8-6.8a3.2 3.2 0 0 1 4.5 4.5l-8.7 8.7a5 5 0 0 1-7.1-7.1l8.2-8.2M7.2 14.8l8.1-8.1"/></svg>',
            file: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h8l4 4v14H6zM14 3v5h5"/></svg>',
            flag: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 21V4m0 1h10l-1.5 3L15 11H5"/></svg>',
            pencil: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 20 4.2-1 10.4-10.4a2 2 0 0 0-2.8-2.8L5.4 16.2 4 20Zm10.6-12.6 2.8 2.8"/></svg>',
        };
        return icons[name] || '';
    }

    function flowtrackTaskTemplateDocumentVersions(task) {
        return flowtrackToArray(task?.documents).flatMap((documentRecord) => {
            const versions = flowtrackToArray(documentRecord?.versions);
            if (!versions.length) {
                return [{
                    document: documentRecord,
                    version: {
                        originalName: documentRecord?.name || 'Attachment',
                        uploadedBy: documentRecord?.uploadedBy || 'System',
                        uploadedAt: documentRecord?.uploadedAt || '',
                        size: 0,
                        previewUrl: documentRecord?.previewUrl || '',
                        downloadUrl: documentRecord?.downloadUrl || '',
                        version: documentRecord?.versionCount || 1,
                    },
                }];
            }
            return versions.map((version) => ({ document: documentRecord, version }));
        });
    }

    function flowtrackTaskTemplateMetaCard({ key, label, value, icon = '', tone = '', editable = false, editor = '', extraClass = '' }) {
        return `<div class="ft-task-meta-card ${editable ? 'is-editable' : ''} ${extraClass}" data-ft-task-inline="${html(key)}">
            <span class="ft-task-meta-label">${html(label)}</span>
            <div class="ft-task-meta-value">
                ${icon ? `<span class="ft-task-meta-icon ${tone ? `is-${tone}` : ''}" aria-hidden="true">${icon}</span>` : ''}
                <span class="ft-task-meta-text">${value}</span>
                ${editable ? `<button type="button" class="ft-task-pencil" data-ft-task-edit-field aria-label="Edit">${flowtrackTaskTemplateSvg('pencil')}</button>` : ''}
            </div>
            ${editable ? `<div class="ft-task-inline-editor" data-ft-task-editor>${editor}</div>` : ''}
        </div>`;
    }

    function flowtrackTaskTemplateMarkup(task, activeTab = 'all') {
        const job = flowtrackTaskTemplateJob(task);
        const completedLocked = taskIsCompleted(task) && !(isFlowAdmin || isFlowSuperAdmin);
        const canUpdate = !completedLocked && Boolean(task.canUpdate || isFlowAdmin || isFlowSuperAdmin);
        const canAssign = canUpdate && Boolean(task.canAssign || isFlowAdmin || isFlowSuperAdmin);
        const canChangeDue = boardCanChangeTaskDueDate(task);
        const canDelete = Boolean(task.canDelete || isFlowAdmin || isFlowSuperAdmin);
        const canManageChecklist = Boolean(task.canManageChecklist || isFlowAdmin || isFlowSuperAdmin);
        const status = taskStatusRecord(task.statusId || task.statusCode || task.status) || { id: task.statusId, name: task.status || 'Not Started', code: task.statusCode };
        const statuses = selectableTaskStatuses(status);
        const assigneeId = flowtrackResolveTaskAssigneeId(task);
        const assigneeName = flowtrackResolveTaskAssigneeName(task, assigneeId);
        const mentionableUsers = flowtrackToArray(task.mentionableUsers);
        const assigneeOptions = flowtrackTaskAssigneeOptions(task, mentionableUsers);
        const priorities = flowtrackToArray(masterData.priorities).filter((item) => String(item?.status || 'Active').toLowerCase() === 'active' || Number(item?.id) === Number(task.priorityId));
        const priorityRecord = priorities.find((item) => Number(item.id) === Number(task.priorityId))
            || priorities.find((item) => String(item.name || '').toLowerCase() === String(task.priority || '').toLowerCase());
        const priorityName = priorityRecord?.name || task.priority || 'Medium';
        const documentVersions = flowtrackTaskTemplateDocumentVersions(task);
        const requirement = task.documentRequirement || null;
        const hasRequiredEvidence = Boolean(documentVersions.length);
        const canUpload = Boolean(task.canUploadDocument && requirement);
        const checklist = flowtrackToArray(task.checklist);
        const checklistDone = checklist.filter((item) => item.done).length;
        const comments = flowtrackToArray(task.comments);
        const history = flowtrackToArray(task.history);
        const dependency = task.dependency || null;
        const activeBlocker = dependency && !dependency.completed ? dependency : null;
        const dependentTasks = flowtrackToArray(task.dependents);
        const health = job?.health || (task.attention ? 'Needs Attention' : 'On Track');
        const healthTone = /blocked|risk|delayed|attention/i.test(String(health)) ? 'red' : 'green';
        const taskProgress = Math.max(0, Math.min(100, Number(task.progress || taskProgressForStatus(status))));
        const jobProgress = Math.max(0, Math.min(100, Number(job?.progress || 0)));
        const createdLabel = flowtrackTaskTemplateDateTime(task.createdAt, 'Not recorded');
        const updatedLabel = flowtrackTaskTemplateRelative(task.updatedAt, 'Not recorded');
        const currentStatusTone = flowtrackTaskTemplateStatusTone(status);
        const currentPriorityTone = flowtrackTaskTemplatePriorityTone(priorityName);
        const attentionLockedByStatus = taskStatusMatches(status, ['BLK', 'BLOCKED', 'ATTN', 'ATTENTION', 'HOLD', 'ON HOLD']);
        const statusOptions = statuses.map((item) => {
            const tone = flowtrackTaskTemplateStatusTone(item);
            const selected = Number(item.id) === Number(status.id) || normalizedStatusValue(item.code || item.name) === normalizedStatusValue(status.code || status.name);
            return `<button type="button" class="ft-task-status-option ${selected ? 'is-selected' : ''}" data-ft-task-status-value="${html(item.id)}">
                <span class="ft-task-dot is-${tone}"></span><span>${html(item.name)}</span>${selected ? '<b>✓</b>' : ''}
            </button>`;
        }).join('');
        const assigneeEditor = `<select data-ft-task-assignee ${canAssign ? '' : 'disabled'}>
            <option value="" ${assigneeId ? '' : 'selected'}>Unassigned</option>
            ${assigneeOptions.map((user) => `<option value="${html(user.id || '')}" ${Number(user.id) === Number(assigneeId) ? 'selected' : ''}>${html(user.name || 'Unassigned')}</option>`).join('')}
        </select>`;
        const priorityEditor = `<select data-ft-task-priority ${canUpdate ? '' : 'disabled'}>
            <option value="">Use job priority</option>
            ${priorities.map((item) => `<option value="${html(item.id)}" ${Number(item.id) === Number(task.priorityId) ? 'selected' : ''}>${html(item.name)}</option>`).join('')}
        </select>`;
        const startDate = String(task.startDate || task.createdAt || '').slice(0, 10);
        const dueDate = String(task.due || '').slice(0, 10);
        const metadata = [
            flowtrackTaskTemplateMetaCard({
                key: 'assignee', label: 'Assignee', editable: canAssign,
                icon: `<span class="ft-task-avatar">${html(userInitials(assigneeName))}</span>`,
                value: html(assigneeName), editor: assigneeEditor,
            }),
            flowtrackTaskTemplateMetaCard({
                key: 'status', label: 'Status', editable: canUpdate,
                icon: `<span class="ft-task-dot is-${currentStatusTone}"></span>`, value: html(status.name || task.status || 'Not Started'),
                editor: `<div class="ft-task-status-menu">${statusOptions}</div>`, extraClass: 'ft-task-status-card',
            }),
            flowtrackTaskTemplateMetaCard({
                key: 'priority', label: 'Priority', editable: canUpdate,
                icon: `<span class="ft-task-dot is-${currentPriorityTone}"></span>`, value: html(priorityName), editor: priorityEditor,
            }),
            flowtrackTaskTemplateMetaCard({
                key: 'phase', label: 'Phase', editable: false,
                value: html(task.phaseName || task.phase || 'General'),
            }),
            flowtrackTaskTemplateMetaCard({
                key: 'start-date', label: 'Start date', editable: canUpdate,
                icon: `<span class="ft-task-calendar-icon">${flowtrackTaskTemplateSvg('calendar')}</span>`, value: html(flowtrackTaskTemplateDate(startDate)),
                editor: `<input type="date" value="${html(startDate)}" data-ft-task-start-date ${canUpdate ? '' : 'disabled'}>`,
            }),
            flowtrackTaskTemplateMetaCard({
                key: 'due-date', label: 'Due date', editable: canChangeDue,
                icon: `<span class="ft-task-calendar-icon ${dueDate ? '' : 'is-orange'}">${flowtrackTaskTemplateSvg('calendar')}</span>`, value: `<span class="${dueClass(dueDate)}">${html(flowtrackTaskTemplateDate(dueDate))}</span>`,
                editor: `<input type="date" value="${html(dueDate)}" data-ft-task-due-date ${canChangeDue ? '' : 'disabled'}>`,
            }),
        ].join('');

        const checklistHtml = checklist.length
            ? checklist.map((item) => `<div class="ft-task-check-row">
                <label><input type="checkbox" data-ft-task-checklist="${html(item.id)}" ${item.done ? 'checked' : ''} ${canUpdate ? '' : 'disabled'}><span>${html(item.text || 'Checklist item')}</span></label>
                ${canManageChecklist ? `<button type="button" class="ft-task-row-menu" data-ft-task-delete-checklist="${html(item.id)}" aria-label="Delete checklist item" title="Delete checklist item">⋮</button>` : '<span></span>'}
            </div>`).join('')
            : '<div class="ft-task-empty-row">No checklist items are configured for this task.</div>';

        const filesHtml = documentVersions.length
            ? `<div class="ft-task-file-list">${documentVersions.map(({ document: documentRecord, version }) => {
                const fileName = version.originalName || documentRecord.name || 'Attachment';
                const extension = String(fileName).split('.').pop()?.toUpperCase().slice(0, 4) || 'FILE';
                const openUrl = version.previewUrl || version.downloadUrl || '';
                return `<div class="ft-task-file-row">
                    <span class="ft-task-file-icon">${html(extension)}</span>
                    <div><b>${html(fileName)}</b><small>${html(documentRecord.category || requirement?.categoryName || 'Attachment')} · v${html(version.version || 1)} · ${html(flowtrackTaskTemplateFileSize(version.size))} · ${html(version.uploadedBy || documentRecord.uploadedBy || 'System')}</small></div>
                    <div class="ft-task-file-actions">${openUrl ? `<a href="${html(openUrl)}" target="_blank" rel="noopener" class="ft-task-file-open">Open</a>` : ''}${documentRecord.canDelete || canFlow('documents', 'delete') ? `<button type="button" class="ft-task-file-delete" data-ft-task-delete-document="${html(documentRecord.dbId)}">Delete</button>` : ''}</div>
                </div>`;
            }).join('')}</div>`
            : '';

        const historyHasCreated = history.some((entry) => String(entry?.event || '').includes('created'));
        const commentRows = comments.map((comment) => ({
            kind: 'comment',
            occurredAt: comment.occurredAt || '',
            html: `<div class="ft-task-activity-row" data-ft-task-activity-kind="comment" data-ft-task-occurred-at="${html(comment.occurredAt || '')}">
                <span class="ft-task-activity-avatar">${html(comment.userInitials || userInitials(comment.user || 'System'))}</span>
                <div><b>${html(comment.user || 'System')}</b><p>${html(comment.text || '')}</p></div>
                <time>${html(comment.time || flowtrackTaskTemplateDateTime(comment.occurredAt, ''))}</time>
            </div>`,
        }));
        const historyRows = history.map((entry) => ({
            kind: 'history',
            historyComment: Boolean(entry.commentAdded) && flowtrackToArray(entry.changes).length === 0,
            occurredAt: entry.occurredAt || '',
            html: `<div class="ft-task-activity-row" data-ft-task-activity-kind="history" data-ft-task-history-comment="${entry.commentAdded && flowtrackToArray(entry.changes).length === 0 ? '1' : '0'}" data-ft-task-occurred-at="${html(entry.occurredAt || '')}">
                <span class="ft-task-activity-icon">${entry.isCompletionEvent ? '✓' : '↻'}</span>
                <div><b>${html(entry.actor || 'System')}</b><p>${html(entry.description || entry.eventLabel || 'Task updated')}</p>
                    ${flowtrackToArray(entry.changes).length ? `<small>${flowtrackToArray(entry.changes).map((change) => `${html(change.field || 'Field')}: ${html(change.from || '—')} → ${html(change.to || '—')}`).join(' · ')}</small>` : (entry.commentText ? `<small>${html(entry.commentText)}</small>` : '')}
                </div>
                <time>${html(entry.time || flowtrackTaskTemplateDateTime(entry.occurredAt, ''))}</time>
            </div>`,
        }));
        const syntheticHistoryRows = historyHasCreated ? [] : [{
            kind: 'history',
            historyComment: false,
            occurredAt: task.createdAt || '',
            html: `<div class="ft-task-activity-row" data-ft-task-activity-kind="history" data-ft-task-history-comment="0" data-ft-task-occurred-at="${html(task.createdAt || '')}">
                <span class="ft-task-activity-avatar">${html(task.createdByInitials || userInitials(task.createdBy || 'System'))}</span>
                <div><p><b>${html(task.createdBy || 'System')}</b> created this task</p></div>
                <time>${html(createdLabel)}</time>
            </div>`,
        }];
        const activityRows = [...historyRows, ...commentRows, ...syntheticHistoryRows]
            .sort((left, right) => {
                const leftTime = Date.parse(left.occurredAt || '') || 0;
                const rightTime = Date.parse(right.occurredAt || '') || 0;
                return rightTime - leftTime;
            });
        const activityHtml = activityRows.map((row) => row.html).join('');

        return `<div class="ft-task-final-page" data-ft-task-id="${html(task.id || task.dbId)}">
            <nav class="ft-task-breadcrumb" aria-label="Breadcrumb"><button type="button" data-standalone-back>My Work</button><span>/</span><span>${html(task.id || 'Task')}</span></nav>
            <div class="ft-task-final-layout">
                <main class="ft-task-final-main">
                    <header class="ft-task-header">
                        <div class="ft-task-heading">
                            <div class="ft-task-code">${html(task.id || '')}</div>
                            <h1 data-ft-task-title-view>${html(task.title || 'Untitled task')}</h1>
                        </div>
                        <div class="ft-task-header-actions">
                            ${canUpdate && !taskIsCompleted(task) ? '<button type="button" class="ft-task-btn ft-task-btn-primary" data-ft-task-mark-complete>Mark complete</button>' : ''}
                            ${canDelete ? '<div class="ft-task-action-wrap"><button type="button" class="ft-task-icon-btn" data-ft-task-action-menu aria-label="Task actions">•••</button><div class="ft-task-action-menu" data-ft-task-action-popover><button type="button" data-ft-task-delete>Delete task</button></div></div>' : ''}
                            <button type="button" class="ft-task-icon-btn ft-task-close" data-standalone-back aria-label="Close task">×</button>
                        </div>
                    </header>

                    ${completedLocked ? '<div class="ft-task-lock-note">This completed task is read-only. Administrators can still update it.</div>' : ''}
                    <section class="ft-task-meta-grid">${metadata}</section>

                    <section class="ft-task-section ft-task-description-section">
                        <div class="ft-task-section-head"><h2>Description</h2>${canUpdate ? `<button type="button" class="ft-task-pencil" data-ft-task-edit-description aria-label="Edit description">${flowtrackTaskTemplateSvg('pencil')}</button>` : ''}</div>
                        <div class="ft-task-description-view" data-ft-task-description-view>
                            <p>${html(task.description || 'No description has been provided for this task.')}</p>
                            ${task.blockerReason ? `<small><b>Blocker:</b> ${html(task.blockerReason)}</small>` : ''}
                        </div>
                        ${canUpdate ? `<div class="ft-task-description-editor" data-ft-task-description-editor>
                            <textarea rows="4" maxlength="5000" data-ft-task-description-input>${html(task.description || '')}</textarea>
                            <div><button type="button" class="ft-task-btn ft-task-btn-secondary" data-ft-task-description-cancel>Cancel</button><button type="button" class="ft-task-btn ft-task-btn-primary" data-ft-task-description-save>Save</button></div>
                        </div>` : ''}
                    </section>

                    <section class="ft-task-section ft-task-checklist-section">
                        <div class="ft-task-section-head ft-task-checklist-head"><div><h2>Checklist</h2><span>${checklistDone} of ${checklist.length} complete</span><span class="ft-task-mini-progress"><i style="width:${checklist.length ? Math.round(checklistDone / checklist.length * 100) : 0}%"></i></span></div>${canManageChecklist ? '<button type="button" class="ft-task-link-btn" data-ft-task-add-checklist>＋ Add item</button>' : ''}</div>
                        <div class="ft-task-checklist-list">${checklistHtml}</div>
                    </section>

                    <section class="ft-task-section ft-task-attachments-section" data-ft-task-attachment-section>
                        <div class="ft-task-section-head"><div><h2>Attachments</h2><span class="ft-task-count">${documentVersions.length}</span></div></div>
                        <div class="ft-task-dropzone ${canUpload ? '' : 'is-disabled'}" data-ft-task-dropzone tabindex="${canUpload ? '0' : '-1'}">
                            <input type="file" data-ft-task-file-input accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.webp,.zip" ${canUpload ? '' : 'disabled'}>
                            <span class="ft-task-paperclip">${flowtrackTaskTemplateSvg('paperclip')}</span>
                            <div><b>Drop files here or <button type="button" data-ft-task-browse ${canUpload ? '' : 'disabled'}>browse</button></b><small>PDF, DOCX, XLSX, JPG, PNG or ZIP · Max 20 MB${requirement?.categoryName ? ` · ${html(requirement.categoryName)}` : ''}</small></div>
                            <button type="button" class="ft-task-btn ft-task-btn-secondary" data-ft-task-choose-documents>Choose from Documents</button>
                        </div>
                        ${!canUpload && !hasRequiredEvidence ? '<div class="ft-task-upload-note">File upload is available only for document tasks in the job’s current phase and for users with document upload access.</div>' : ''}
                        ${filesHtml}
                    </section>

                    <section class="ft-task-section ft-task-activity-section">
                        <div class="ft-task-activity-tabs">
                            <h2>Activity</h2>
                            <button type="button" class="${activeTab === 'all' ? 'is-active' : ''}" data-ft-task-activity-tab="all">All</button>
                            <button type="button" class="${activeTab === 'comments' ? 'is-active' : ''}" data-ft-task-activity-tab="comments">Comments</button>
                            <button type="button" class="${activeTab === 'history' ? 'is-active' : ''}" data-ft-task-activity-tab="history">History</button>
                        </div>
                        <div class="ft-task-comment-compose">
                            <span class="ft-task-activity-avatar">${html(currentUser?.initials || userInitials(currentUser?.name || 'User'))}</span>
                            <div class="ft-task-comment-input-wrap"><input type="text" data-ft-task-comment-input placeholder="Add an update or comment..." ${canUpdate ? '' : 'disabled'}><div class="mention-menu hidden" data-ft-task-mention-menu></div></div>
                            <button type="button" class="ft-task-comment-btn" data-ft-task-comment-submit ${canUpdate ? '' : 'disabled'}>Comment</button>
                        </div>
                        <div class="ft-task-activity-list" data-ft-task-activity-list>
                            ${activityHtml}
                            <div class="ft-task-empty-activity" data-ft-task-activity-empty hidden>No activity yet</div>
                        </div>
                    </section>
                </main>

                <aside class="ft-task-final-side">
                    <section class="ft-task-side-card">
                        <h2>Management attention</h2>
                        <div class="ft-task-side-line"><span>Required evidence</span><div class="ft-task-side-value"><i class="ft-task-evidence-icon ${hasRequiredEvidence ? 'is-complete' : 'is-missing'}">${flowtrackTaskTemplateSvg('file')}</i><b>${html(requirement?.categoryName || (hasRequiredEvidence ? 'Attachment available' : 'No required evidence'))}${requirement && !hasRequiredEvidence ? ' missing' : ''}</b></div>${canUpload && !hasRequiredEvidence ? '<button type="button" data-ft-task-upload-link>Upload</button>' : '<span></span>'}</div>
                        <div class="ft-task-side-line"><span>Attention</span><div class="ft-task-side-value"><i class="ft-task-flag-icon">${flowtrackTaskTemplateSvg('flag')}</i><b>${task.attention ? 'Flagged' : 'Not flagged'}</b></div>${canUpdate && !taskIsCompleted(task) && !attentionLockedByStatus ? `<button type="button" data-ft-task-toggle-attention>${task.attention ? 'Unflag task' : 'Flag task'}</button>` : '<span></span>'}</div>
                        ${task.blockerReason ? `<div class="ft-task-side-note"><b>Reason</b><p>${html(task.blockerReason)}</p></div>` : ''}
                    </section>

                    <section class="ft-task-side-card ft-task-job-card">
                        <h2>Job context</h2>
                        ${job ? `<button type="button" class="ft-task-job-code" data-ft-task-open-job>${html(job.id || task.job)} ↗</button>
                        <h3>${html(job.title || task.jobTitle || task.job || 'Job')}</h3>
                        <dl><dt>Client</dt><dd>${html(job.clientName || clientName(job.client) || 'Unknown client')}</dd><dt>Job health</dt><dd><span class="ft-task-health is-${healthTone}"><i></i>${html(health)}</span></dd><dt>Delivery</dt><dd>${html(flowtrackTaskTemplateDate(job.delivery))}</dd><dt>Job progress</dt><dd><b>${jobProgress}%</b><span class="ft-task-job-progress"><i style="width:${jobProgress}%"></i></span></dd></dl>
                        <button type="button" class="ft-task-open-job-link" data-ft-task-open-job>Open job details ↗</button>` : `<h3>${html(task.jobTitle || task.job || 'No linked job')}</h3>`}
                    </section>

                    <section class="ft-task-side-card ft-task-dependency-card">
                        <h2>Dependencies</h2>
                        <div class="ft-task-blocker-state ${activeBlocker ? 'is-blocked' : ''}"><i>${activeBlocker ? '!' : '✓'}</i><span>${activeBlocker ? '1 active blocker' : 'No active blockers'}</span></div>
                        ${dependency ? `<div class="ft-task-dependency-row"><span>Previous task:</span><button type="button" data-ft-task-open-dependency="${html(dependency.id || dependency.dbId)}">${html(dependency.title || dependency.id)} · ${html(dependency.status || (dependency.completed ? 'Completed' : 'Open'))}</button></div>` : '<div class="ft-task-dependency-row"><span>Previous task:</span><b>None</b></div>'}
                        ${dependentTasks.length ? `<div class="ft-task-dependent-list"><span>Following tasks:</span>${dependentTasks.map((item) => `<button type="button" data-ft-task-open-dependency="${html(item.id || item.dbId)}">${html(item.title || item.id)} · ${html(item.status || 'Open')}</button>`).join('')}</div>` : ''}
                        <button type="button" class="ft-task-link-btn" data-ft-task-add-dependency>Add dependency</button>
                    </section>

                    <div class="ft-task-side-footer">Created ${html(flowtrackTaskTemplateDate(String(task.createdAt || '').slice(0, 10), '—'))}<span>·</span>Updated ${html(updatedLabel || '—')}</div>
                </aside>
            </div>
        </div>`;
    }

    function flowtrackTaskTemplateActiveTab(page) {
        return page?.querySelector('[data-ft-task-activity-tab].is-active')?.dataset.ftTaskActivityTab || 'all';
    }

    function flowtrackTaskTemplateRender(identifier, activeTab = 'all') {
        const task = flowtrackTaskTemplateCurrent(identifier);
        if (!task) return false;
        const pageId = `record-task-${flowtrackTaskTemplateSlug(task.id || task.dbId)}`;
        const page = document.getElementById(`page-${pageId}`);
        if (!page) return false;
        page.__flowtrackTaskTemplateController?.abort();
        page.__flowtrackTaskTemplateController = new AbortController();
        page.classList.add('ft-task-final-host');
        page.innerHTML = flowtrackTaskTemplateMarkup(task, activeTab);
        bindStandaloneBack(page);
        flowtrackTaskTemplateBind(page, task);
        applyActionPermissions();
        return true;
    }

    async function flowtrackTaskTemplateSave(identifier, status, extras, message, activeTab = 'all') {
        const current = flowtrackTaskTemplateCurrent(identifier);
        if (!current) throw new Error('The selected task is no longer available.');
        const result = await persistTask(current, status || current.statusId || current.statusCode || current.status, extras || {});
        updateTaskAndJob(result);
        updateBadges();
        const updated = result.task || flowtrackTaskTemplateCurrent(identifier);
        flowtrackTaskTemplateRender(updated?.id || identifier, activeTab);
        if (message) showToast(message);
        return updated;
    }

    function flowtrackTaskTemplateBind(page, renderedTask) {
        const signal = page.__flowtrackTaskTemplateController?.signal;
        const identifier = renderedTask.id || renderedTask.dbId;
        const getCurrent = () => flowtrackTaskTemplateCurrent(identifier);
        const getTab = () => flowtrackTaskTemplateActiveTab(page);
        const closeEditors = (except = null) => {
            page.querySelectorAll('[data-ft-task-inline].is-open').forEach((card) => {
                if (card !== except) card.classList.remove('is-open');
            });
            page.querySelector('[data-ft-task-action-popover]')?.classList.remove('is-open');
        };

        page.querySelectorAll('[data-ft-task-edit-field]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                const card = button.closest('[data-ft-task-inline]');
                if (!card) return;
                const opening = !card.classList.contains('is-open');
                closeEditors(card);
                card.classList.toggle('is-open', opening);
                if (opening) setTimeout(() => card.querySelector('select,input,button[data-ft-task-status-value]')?.focus(), 0);
            });
        });
        page.querySelectorAll('[data-ft-task-inline].is-editable').forEach((card) => {
            card.addEventListener('click', (event) => {
                if (event.target.closest('select,input,button,a,[data-ft-task-editor]')) return;
                card.querySelector('[data-ft-task-edit-field]')?.click();
            });
        });
        document.addEventListener('pointerdown', (event) => {
            if (!page.isConnected) {
                page.__flowtrackTaskTemplateController?.abort();
                return;
            }
            const target = event.target instanceof Element ? event.target : null;
            if (target && page.contains(target) && target.closest('[data-ft-task-inline],.ft-task-action-wrap')) return;
            closeEditors();
        }, signal ? { signal } : undefined);

        page.querySelectorAll('[data-ft-task-status-value]').forEach((button) => {
            button.addEventListener('click', async () => {
                const current = getCurrent();
                const selected = taskStatusRecord(Number(button.dataset.ftTaskStatusValue));
                if (!current || !selected) return;
                button.disabled = true;
                const completed = taskStatusMatches(selected, ['CMP', 'COMPLETED', 'COMPLETE', 'DONE']);
                const blocked = taskStatusMatches(selected, ['BLK', 'BLOCKED', 'ATTN', 'ATTENTION', 'HOLD', 'ON HOLD']);
                if (completed && current.documentRequirement && flowtrackTaskTemplateDocumentVersions(current).length === 0) {
                    button.disabled = false;
                    page.querySelector('[data-ft-task-attachment-section]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return showToast(`Upload or provide the required ${current.documentRequirement.categoryName || 'document'} before completing this task.`);
                }
                try {
                    await flowtrackTaskTemplateSave(identifier, selected, {
                        attention_required: completed ? false : current.attention,
                        blocker_reason: completed ? null : (blocked ? (current.blockerReason || `Task status changed to ${selected.name}.`) : current.blockerReason),
                    }, 'Task status updated.', getTab());
                } catch (error) {
                    button.disabled = false;
                    showToast(error.message || 'Task status could not be updated.');
                }
            });
        });

        page.querySelector('[data-ft-task-assignee]')?.addEventListener('change', async (event) => {
            const select = event.currentTarget;
            select.disabled = true;
            try {
                await flowtrackTaskTemplateSave(identifier, null, { assignee_id: select.value ? Number(select.value) : null }, 'Assignee updated.', getTab());
            } catch (error) {
                select.disabled = false;
                showToast(error.message || 'Assignee could not be updated.');
            }
        });

        page.querySelector('[data-ft-task-priority]')?.addEventListener('change', async (event) => {
            const select = event.currentTarget;
            select.disabled = true;
            try {
                await flowtrackTaskTemplateSave(identifier, null, { priority_id: select.value ? Number(select.value) : null }, 'Priority updated.', getTab());
            } catch (error) {
                select.disabled = false;
                showToast(error.message || 'Priority could not be updated.');
            }
        });

        page.querySelector('[data-ft-task-start-date]')?.addEventListener('change', async (event) => {
            const input = event.currentTarget;
            const current = getCurrent();
            const due = String(current?.due || '').slice(0, 10);
            if (input.value && due && input.value > due) {
                showToast('Start date cannot be later than the due date.');
                input.value = String(current?.startDate || '').slice(0, 10);
                return;
            }
            input.disabled = true;
            try {
                await flowtrackTaskTemplateSave(identifier, null, { start_date: input.value || null }, 'Start date updated.', getTab());
            } catch (error) {
                input.disabled = false;
                showToast(error.message || 'Start date could not be updated.');
            }
        });

        page.querySelector('[data-ft-task-due-date]')?.addEventListener('change', async (event) => {
            const input = event.currentTarget;
            const current = getCurrent();
            const start = String(current?.startDate || '').slice(0, 10);
            if (input.value && start && input.value < start) {
                showToast('Due date cannot be earlier than the start date.');
                input.value = String(current?.due || '').slice(0, 10);
                return;
            }
            input.disabled = true;
            try {
                const result = await persistTaskDueDate(current, input.value || null);
                updateTaskAndJob(result);
                updateBadges();
                flowtrackTaskTemplateRender(result.task?.id || identifier, getTab());
                showToast('Due date updated.');
            } catch (error) {
                input.disabled = false;
                showToast(error.message || 'Due date could not be updated.');
            }
        });

        const setDescriptionEditing = (editing) => {
            page.querySelector('.ft-task-description-section')?.classList.toggle('is-editing', editing);
            if (editing) setTimeout(() => page.querySelector('[data-ft-task-description-input]')?.focus(), 0);
        };
        page.querySelector('[data-ft-task-edit-description]')?.addEventListener('click', () => setDescriptionEditing(true));
        page.querySelector('[data-ft-task-description-cancel]')?.addEventListener('click', () => setDescriptionEditing(false));
        page.querySelector('[data-ft-task-description-save]')?.addEventListener('click', async (event) => {
            const button = event.currentTarget;
            button.disabled = true;
            try {
                await flowtrackTaskTemplateSave(identifier, null, { description: page.querySelector('[data-ft-task-description-input]')?.value.trim() || null }, 'Description updated.', getTab());
            } catch (error) {
                button.disabled = false;
                showToast(error.message || 'Description could not be updated.');
            }
        });



        page.querySelector('[data-ft-task-mark-complete]')?.addEventListener('click', async (event) => {
            const button = event.currentTarget;
            const complete = taskStatusRecord('CMP') || taskStatusRecord('COMPLETED') || taskStatusRecord('Completed') || selectableTaskStatuses().find((item) => taskStatusMatches(item, ['CMP', 'COMPLETED', 'COMPLETE', 'DONE']));
            if (!complete) return showToast('A completed task status is not configured in Master Data.');
            const current = getCurrent();
            if (current?.documentRequirement && flowtrackTaskTemplateDocumentVersions(current).length === 0) {
                page.querySelector('[data-ft-task-attachment-section]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return showToast(`Upload or provide the required ${current.documentRequirement.categoryName || 'document'} before completing this task.`);
            }
            button.disabled = true;
            try {
                await flowtrackTaskTemplateSave(identifier, complete, { attention_required: false, blocker_reason: null }, 'Task marked complete.', getTab());
            } catch (error) {
                button.disabled = false;
                showToast(error.message || 'Task could not be completed.');
            }
        });

        page.querySelector('[data-ft-task-action-menu]')?.addEventListener('click', (event) => {
            event.stopPropagation();
            const menu = page.querySelector('[data-ft-task-action-popover]');
            menu?.classList.toggle('is-open');
        });
        page.querySelector('[data-ft-task-delete]')?.addEventListener('click', async (event) => {
            event.currentTarget.disabled = true;
            const current = getCurrent();
            if (!current || !confirm(`Delete task ${current.id}?`)) {
                event.currentTarget.disabled = false;
                return;
            }
            try {
                const result = await api(`/flowtrack/template-api/tasks/${current.dbId}`, { method: 'DELETE' });
                if (result.job) {
                    flowtrackUpsertRecord(jobs, result.job);
                    if (Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
                }
                flowtrackRemoveRecord(tasks, result.deletedTask || current);
                updateBadges();
                const destination = standaloneBackPage || 'mywork';
                standaloneActivePageId = null;
                navigate(destination);
                showToast('Task deleted successfully.');
            } catch (error) {
                event.currentTarget.disabled = false;
                showToast(error.message || 'Task could not be deleted.');
            }
        });

        page.querySelectorAll('[data-ft-task-checklist]').forEach((input) => {
            input.addEventListener('change', async () => {
                const current = getCurrent();
                if (!current) return;
                const completedIds = flowtrackToArray(current.checklist)
                    .filter((item) => item.done || Number(item.id) === Number(input.dataset.ftTaskChecklist))
                    .map((item) => Number(item.id));
                if (!input.checked) {
                    const index = completedIds.indexOf(Number(input.dataset.ftTaskChecklist));
                    if (index >= 0) completedIds.splice(index, 1);
                }
                page.querySelectorAll('[data-ft-task-checklist]').forEach((item) => { item.disabled = true; });
                try {
                    await flowtrackTaskTemplateSave(identifier, null, { completed_checklist_ids: completedIds }, 'Checklist updated.', getTab());
                } catch (error) {
                    showToast(error.message || 'Checklist could not be updated.');
                    flowtrackTaskTemplateRender(identifier, getTab());
                }
            });
        });
        const closeTaskChecklistModal = () => {
            $('#overlay')?.classList.remove('open');
            $('#jobModal')?.classList.remove('open');
        };
        const bindTaskChecklistModalClose = (modal) => {
            modal.querySelectorAll('[data-ft-task-checklist-modal-close]').forEach((button) => {
                button.addEventListener('click', closeTaskChecklistModal);
            });
        };

        page.querySelector('[data-ft-task-add-checklist]')?.addEventListener('click', () => {
            const modal = $('#jobModal');
            if (!modal) return;
            modal.innerHTML = `<div class="modal-head"><div><h2>Add checklist item</h2><div class="small muted" style="margin-top:5px">Add a new item to this task checklist.</div></div><button type="button" class="close-btn" data-ft-task-checklist-modal-close aria-label="Close">×</button></div>
                <div class="modal-body"><div class="field"><label>Checklist item *</label><input id="ftTaskChecklistItemLabel" maxlength="255" autocomplete="off" placeholder="Enter checklist item"></div></div>
                <div class="modal-foot"><button type="button" class="ghost" data-ft-task-checklist-modal-close>Cancel</button><button type="button" class="primary" id="ftTaskChecklistAddConfirm">Add item</button></div>`;
            openOverlay();
            modal.classList.add('open');
            bindTaskChecklistModalClose(modal);

            const input = modal.querySelector('#ftTaskChecklistItemLabel');
            const submit = modal.querySelector('#ftTaskChecklistAddConfirm');
            const saveItem = async () => {
                const cleaned = String(input?.value || '').trim();
                if (!cleaned) {
                    showToast('Checklist item text is required.');
                    input?.focus();
                    return;
                }
                if (submit) submit.disabled = true;
                if (input) input.disabled = true;
                try {
                    await flowtrackTaskTemplateSave(identifier, null, { checklist_add: cleaned }, 'Checklist item added.', getTab());
                    closeTaskChecklistModal();
                } catch (error) {
                    if (submit) submit.disabled = false;
                    if (input) input.disabled = false;
                    showToast(error.message || 'Checklist item could not be added.');
                    input?.focus();
                }
            };
            submit?.addEventListener('click', saveItem);
            input?.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') return;
                event.preventDefault();
                void saveItem();
            });
            setTimeout(() => input?.focus(), 0);
        });

        page.querySelectorAll('[data-ft-task-delete-checklist]').forEach((button) => button.addEventListener('click', () => {
            const current = getCurrent();
            const itemId = Number(button.dataset.ftTaskDeleteChecklist || 0);
            const item = flowtrackToArray(current?.checklist).find((entry) => Number(entry.id) === itemId);
            if (!itemId || !item) return;

            const modal = $('#jobModal');
            if (!modal) return;
            modal.innerHTML = `<div class="modal-head"><div><h2>Delete checklist item?</h2><div class="small muted" style="margin-top:5px">This removes the item from this task checklist.</div></div><button type="button" class="close-btn" data-ft-task-checklist-modal-close aria-label="Close">×</button></div>
                <div class="modal-body"><div class="card" style="padding:16px;border-color:#fecaca;background:#fff7f7"><div class="small muted">Checklist item</div><h3 style="margin:5px 0 0">${html(item.text || 'Checklist item')}</h3></div></div>
                <div class="modal-foot"><button type="button" class="ghost" data-ft-task-checklist-modal-close>Cancel</button><button type="button" class="danger-btn" id="ftTaskChecklistDeleteConfirm">Delete item</button></div>`;
            openOverlay();
            modal.classList.add('open');
            bindTaskChecklistModalClose(modal);

            const confirmButton = modal.querySelector('#ftTaskChecklistDeleteConfirm');
            confirmButton?.addEventListener('click', async () => {
                confirmButton.disabled = true;
                try {
                    await flowtrackTaskTemplateSave(identifier, null, { checklist_delete_id: itemId }, 'Checklist item deleted.', getTab());
                    closeTaskChecklistModal();
                } catch (error) {
                    confirmButton.disabled = false;
                    showToast(error.message || 'Checklist item could not be deleted.');
                }
            });
            setTimeout(() => confirmButton?.focus(), 0);
        }));

        const uploadFile = async (file) => {
            const current = getCurrent();
            const requirement = current?.documentRequirement;
            if (!file) return;
            if (!current?.canUploadDocument || !requirement) return showToast('File upload is not available for this task.');
            if (file.size > 20 * 1024 * 1024) return showToast('The document must not be larger than 20 MB.');
            const dropzone = page.querySelector('[data-ft-task-dropzone]');
            dropzone?.classList.add('is-uploading');
            try {
                const formData = new FormData();
                formData.append('job_id', String(current.jobDbId));
                formData.append('workflow_phase_id', String(requirement.phaseId || current.phaseId));
                if (requirement.taskPackItemId) formData.append('task_pack_item_id', String(requirement.taskPackItemId));
                formData.append('document_category_id', String(requirement.categoryId));
                formData.append('task_id', String(requirement.taskId || current.dbId));
                const existing = flowtrackToArray(current.documents)[0];
                if (existing?.dbId) formData.append('document_id', String(existing.dbId));
                formData.append('file', file);
                const result = await api('/flowtrack/template-api/documents', { method: 'POST', body: formData });
                if (result.document) flowtrackUpsertRecord(docs, result.document);
                if (result.job) {
                    flowtrackUpsertRecord(jobs, result.job);
                    if (Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
                }
                updateBadges();
                const updated = flowtrackTaskTemplateCurrent(identifier);
                flowtrackTaskTemplateRender(updated?.id || identifier, getTab());
                showToast(result.message || 'Attachment uploaded successfully.');
            } catch (error) {
                dropzone?.classList.remove('is-uploading');
                showToast(error.message || 'Attachment upload failed.');
            }
        };
        const fileInput = page.querySelector('[data-ft-task-file-input]');
        page.querySelector('[data-ft-task-browse]')?.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            fileInput?.click();
        });
        fileInput?.addEventListener('change', () => uploadFile(fileInput.files?.[0]));
        const dropzone = page.querySelector('[data-ft-task-dropzone]');
        dropzone?.addEventListener('dragover', (event) => { event.preventDefault(); dropzone.classList.add('is-dragging'); });
        dropzone?.addEventListener('dragleave', () => dropzone.classList.remove('is-dragging'));
        dropzone?.addEventListener('drop', (event) => {
            event.preventDefault();
            dropzone.classList.remove('is-dragging');
            uploadFile(event.dataTransfer?.files?.[0]);
        });
        dropzone?.addEventListener('keydown', (event) => {
            if (['Enter', ' '].includes(event.key) && !fileInput?.disabled) {
                event.preventDefault();
                fileInput.click();
            }
        });
        page.querySelectorAll('[data-ft-task-delete-document]').forEach((button) => button.addEventListener('click', () => {
            const current = getCurrent();
            const documentRecord = flowtrackToArray(current?.documents).find((item) => Number(item.dbId) === Number(button.dataset.ftTaskDeleteDocument))
                || docs.find((item) => Number(item.dbId) === Number(button.dataset.ftTaskDeleteDocument));
            if (!documentRecord) return showToast('The selected document could not be found.');
            flowtrackConfirmDeleteDocument(documentRecord, () => {
                const updated = flowtrackTaskTemplateCurrent(identifier);
                flowtrackTaskTemplateRender(updated?.id || identifier, getTab());
            });
        }));

        page.querySelector('[data-ft-task-choose-documents]')?.addEventListener('click', () => {
            const current = getCurrent();
            const requirement = current?.documentRequirement;
            const linkedJob = flowtrackTaskTemplateJob(current);
            if (!current?.canUploadDocument || !requirement || !linkedJob) return showToast('Stored-document linking is not available for this task.');
            flowtrackOpenStoredDocumentChooser(linkedJob, requirement, current, () => {
                const updated = flowtrackTaskTemplateCurrent(identifier);
                flowtrackTaskTemplateRender(updated?.id || identifier, getTab());
            });
        });
        page.querySelector('[data-ft-task-upload-link]')?.addEventListener('click', () => {
            page.querySelector('[data-ft-task-attachment-section]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            setTimeout(() => fileInput?.click(), 350);
        });

        page.querySelector('[data-ft-task-toggle-attention]')?.addEventListener('click', async (event) => {
            const current = getCurrent();
            if (!current) return;
            event.currentTarget.disabled = true;
            const next = !current.attention;
            try {
                await flowtrackTaskTemplateSave(identifier, null, {
                    attention_required: next,
                    blocker_reason: next ? (current.blockerReason || null) : null,
                }, next ? 'Task marked as attention needed.' : 'Attention mark cleared.', getTab());
            } catch (error) {
                event.currentTarget.disabled = false;
                showToast(error.message || 'Attention status could not be updated.');
            }
        });

        page.querySelectorAll('[data-ft-task-open-job]').forEach((button) => button.addEventListener('click', () => {
            const current = getCurrent();
            const job = flowtrackTaskTemplateJob(current);
            if (job) void openJob(job.id || job.dbId);
            else if (current?.jobDbId) flowtrackNavigateTo(`/flowtrack/jobs/${current.jobDbId}`);
        }));
        page.querySelectorAll('[data-ft-task-open-dependency]').forEach((button) => button.addEventListener('click', () => void openTask(button.dataset.ftTaskOpenDependency)));
        page.querySelector('[data-ft-task-add-dependency]')?.addEventListener('click', () => showToast('Dependencies are configured from task setup and workflow rules.'));

        const applyActivityTab = (tab) => {
            const normalizedTab = ['all', 'comments', 'history'].includes(tab) ? tab : 'all';
            page.querySelectorAll('[data-ft-task-activity-tab]').forEach((button) => {
                button.classList.toggle('is-active', button.dataset.ftTaskActivityTab === normalizedTab);
                button.setAttribute('aria-selected', button.dataset.ftTaskActivityTab === normalizedTab ? 'true' : 'false');
            });
            page.querySelectorAll('[data-ft-task-activity-kind]').forEach((row) => {
                const kind = row.dataset.ftTaskActivityKind;
                const commentAudit = row.dataset.ftTaskHistoryComment === '1';
                let shouldHide = false;

                if (normalizedTab === 'comments') {
                    // Comments must contain only actual user comments.
                    shouldHide = kind !== 'comment';
                } else if (normalizedTab === 'history') {
                    // History must contain only real task changes. Comment-only audit rows
                    // are intentionally excluded because they belong to the Comments tab.
                    shouldHide = kind !== 'history' || commentAudit;
                } else {
                    // All contains actual comments plus real task-change history. Hide the
                    // comment-only audit copy so a comment is never rendered twice.
                    shouldHide = kind === 'history' && commentAudit;
                }

                row.hidden = shouldHide;
                // Author CSS gives activity rows display:grid, which can override the
                // browser's native [hidden] rule. Keep an explicit class as the durable
                // visibility contract for tab filtering.
                row.classList.toggle('is-filtered-out', shouldHide);
                row.setAttribute('aria-hidden', shouldHide ? 'true' : 'false');
            });

            const visibleRows = [...page.querySelectorAll('[data-ft-task-activity-kind]')]
                .filter((row) => !row.hidden && !row.classList.contains('is-filtered-out'));
            const empty = page.querySelector('[data-ft-task-activity-empty]');
            if (empty) {
                const showEmpty = visibleRows.length === 0;
                empty.hidden = !showEmpty;
                empty.classList.toggle('is-filtered-out', !showEmpty);
                empty.textContent = normalizedTab === 'comments'
                    ? 'No comments yet'
                    : (normalizedTab === 'history' ? 'No task history yet' : 'No activity yet');
            }
        };
        page.querySelectorAll('[data-ft-task-activity-tab]').forEach((button) => button.addEventListener('click', () => applyActivityTab(button.dataset.ftTaskActivityTab)));
        applyActivityTab(getTab());

        const mentionedIds = new Set();
        const commentInput = page.querySelector('[data-ft-task-comment-input]');
        const mentionMenu = page.querySelector('[data-ft-task-mention-menu]');
        bindFlowtrackMentionInput(commentInput, mentionMenu, flowtrackToArray(renderedTask.mentionableUsers), mentionedIds);
        const submitComment = async () => {
            const comment = commentInput?.value.trim() || '';
            if (!comment) return;
            const current = getCurrent();
            const activeMentionIds = flowtrackToArray(renderedTask.mentionableUsers)
                .filter((user) => comment.toLowerCase().includes(`@${String(user.name || '').toLowerCase()}`))
                .map((user) => Number(user.id));
            flowtrackToArray(mentionedIds).forEach((id) => activeMentionIds.push(Number(id)));
            const button = page.querySelector('[data-ft-task-comment-submit]');
            button.disabled = true;
            try {
                await flowtrackTaskTemplateSave(identifier, null, {
                    comment,
                    mentioned_user_ids: [...new Set(activeMentionIds.filter(Number.isFinite))],
                }, 'Comment added.', 'all');
            } catch (error) {
                button.disabled = false;
                showToast(error.message || 'Comment could not be added.');
            }
        };
        page.querySelector('[data-ft-task-comment-submit]')?.addEventListener('click', submitComment);
        commentInput?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                submitComment();
            }
        });
    }

    
    /* CSS externalized: flowtrackTaskTemplateStyles. */

    const flowtrackFinalTaskTemplateBase = currentFlowtrackImplementation('openTask');
    registerFlowtrackExtension('openTask', 'openTask-stage-4-final-template', async function (id) {
        const result = await flowtrackFinalTaskTemplateBase(id);
        const task = flowtrackTaskTemplateCurrent(id);
        if (task) flowtrackTaskTemplateRender(task.id || task.dbId, 'all');
        return result;
    });

    /* Final Job Details reference template (v4): inline editing, all configured phase tasks,
       task-style attachments, functional filters/expanders, and job activity tabs. */
