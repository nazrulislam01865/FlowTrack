function normalizedStatusValue(value) {
        return String(value ?? '').trim().toUpperCase();
    }

    function taskStatusRecord(value) {
        const statuses = masterData.taskStatuses || [];
        if (value && typeof value === 'object') {
            if (value.id !== undefined && value.id !== null) {
                const byId = statuses.find((status) => Number(status.id) === Number(value.id));
                if (byId) return byId;
            }

            const objectCode = normalizedStatusValue(value.code);
            const objectName = normalizedStatusValue(value.name);
            const byObjectValue = statuses.find((status) => {
                const code = normalizedStatusValue(status.code);
                const name = normalizedStatusValue(status.name);
                return (objectCode && code === objectCode) || (objectName && name === objectName);
            });
            if (byObjectValue) return byObjectValue;
        }

        const numericValue = Number(value);
        if (Number.isFinite(numericValue)) {
            const byId = statuses.find((status) => Number(status.id) === numericValue);
            if (byId) return byId;
        }

        const normalized = normalizedStatusValue(value);
        return statuses.find((status) =>
            normalizedStatusValue(status.code) === normalized
            || normalizedStatusValue(status.name) === normalized
        );
    }

    function selectableTaskStatuses(currentStatus = null) {
        const current = taskStatusRecord(currentStatus);
        return (masterData.taskStatuses || []).filter((status) =>
            String(status.status || 'Active').toLowerCase() === 'active'
            || Number(status.id) === Number(current?.id)
        );
    }

    function taskStatusMatches(status, values) {
        const allowed = values.map((value) => normalizedStatusValue(value));
        if (status && typeof status === 'object') {
            return allowed.includes(normalizedStatusValue(status.code))
                || allowed.includes(normalizedStatusValue(status.name));
        }

        return allowed.includes(normalizedStatusValue(status));
    }

    function taskProgressForStatus(status) {
        if (taskStatusMatches(status, ['CMP', 'COMPLETED', 'COMPLETE', 'DONE'])) return 100;
        if (taskStatusMatches(status, ['WC', 'WAITING FOR CLIENT', 'WS', 'WAITING FOR SUPPLIER', 'WIA', 'WAITING FOR INTERNAL APPROVAL', 'WAITING FOR INTERNAL REVIEW'])) return 65;
        if (taskStatusMatches(status, ['IP', 'IN PROGRESS', 'BLK', 'BLOCKED', 'ATTN', 'ATTENTION', 'HOLD', 'ON HOLD'])) return 50;
        if (taskStatusMatches(status, ['REV', 'REVISION REQUIRED'])) return 35;
        if (taskStatusMatches(status, ['READY'])) return 10;
        return 0;
    }

    function flowtrackTaskFromActiveSurface(id, dbId = null) {
        const pools = (currentPage === 'mywork' && myWorkSnapshotLoaded)
            ? [myWorkSnapshotTasks, tasks]
            : [tasks, myWorkSnapshotTasks];
        for (const pool of pools) {
            const match = flowtrackToArray(pool).find((item) => (
                String(item?.id || '') === String(id || '')
                || (Number(dbId || 0) > 0 && Number(item?.dbId || 0) === Number(dbId))
                || (Number(id || 0) > 0 && Number(item?.dbId || 0) === Number(id))
            ));
            if (match) return flowtrackHydrateTaskAssignee(match);
        }
        return null;
    }

    function updateTaskAndJob(result) {
        if (result.task) {
            const taskIndex = tasks.findIndex((task) => Number(task.dbId) === Number(result.task.dbId));
            const hydratedTask = flowtrackHydrateTaskAssignee(result.task);
            if (taskIndex >= 0) tasks.splice(taskIndex, 1, hydratedTask);
            else tasks.push(hydratedTask);
            if (myWorkSnapshotLoaded) {
                flowtrackUpsertRecord(myWorkSnapshotTasks, hydratedTask, false);
                myWorkSnapshotSignature = flowtrackMyWorkSnapshotFingerprint();
            }
        }
        if (result.job) {
            const jobIndex = jobs.findIndex((job) => Number(job.dbId) === Number(result.job.dbId));
            if (jobIndex >= 0) jobs.splice(jobIndex, 1, result.job);
            if (myWorkSnapshotLoaded) {
                flowtrackUpsertRecord(myWorkSnapshotJobs, result.job, false);
                myWorkSnapshotSignature = flowtrackMyWorkSnapshotFingerprint();
            }
            if (Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
        }
    }

    function replaceTasksForJob(jobCode, newTasks) {
        const normalizedTasks = flowtrackToArray(newTasks).map((task) => flowtrackHydrateTaskAssignee({ ...task }));
        const firstTask = normalizedTasks[0] || null;
        const jobRecord = jobs.find((job) => String(job.id) === String(jobCode)
            || (firstTask && Number(job.dbId || 0) > 0 && Number(job.dbId) === Number(firstTask.jobDbId || 0)));
        const canonicalJobCode = String(jobRecord?.id || jobCode || '');
        const canonicalJobDbId = Number(jobRecord?.dbId || firstTask?.jobDbId || 0);

        for (let index = tasks.length - 1; index >= 0; index -= 1) {
            const task = tasks[index];
            if (String(task.job || '') === canonicalJobCode
                || (canonicalJobDbId > 0 && Number(task.jobDbId || 0) === canonicalJobDbId)) {
                tasks.splice(index, 1);
            }
        }
        normalizedTasks.forEach((task) => tasks.push(task));

        // If this Job already owns a complete Job Details snapshot, keep that
        // snapshot synchronized with mutations that return the Job's full Task set.
        // Background paginated list loaders do not call replaceTasksForJob, so they
        // cannot shrink this private snapshot.
        if (jobRecord?._completeTaskSetLoaded) {
            jobRecord._detailTasks = normalizedTasks.map((task) => ({ ...task }));
            jobRecord._detailTaskCount = normalizedTasks.length;
            jobRecord._detailLoadedAt = Date.now();
        }
    }

    async function persistTask(task, status, extras = {}) {
        if (!task?.canUpdate && !isFlowAdmin && !isFlowSuperAdmin) {
            throw new Error('Only the assigned user, an Administrator, or the Super Admin can update this Task.');
        }
        const statusRecord = taskStatusRecord(status);
        if (!statusRecord) throw new Error('The selected task status is not available in this workspace.');
        const payload = {
            title: extras.title ?? task.title,
            description: extras.description ?? task.description ?? null,
            task_status_id: statusRecord.id,
            assignee_id: Object.prototype.hasOwnProperty.call(extras, 'assignee_id') ? extras.assignee_id : (flowtrackResolveTaskAssigneeId(task) || null),
            priority_id: Object.prototype.hasOwnProperty.call(extras, 'priority_id') ? extras.priority_id : (task.priorityId ?? null),
            start_date: Object.prototype.hasOwnProperty.call(extras, 'start_date') ? extras.start_date : (task.startDate ?? (task.createdAt ? String(task.createdAt).split('T')[0] : null)),
            due_date: Object.prototype.hasOwnProperty.call(extras, 'due_date') ? extras.due_date : (task.due ?? null),
            progress: taskProgressForStatus(statusRecord),
            attention_required: extras.attention_required ?? task.attention ?? false,
            blocker_reason: extras.blocker_reason ?? task.blockerReason ?? null,
            completed_checklist_ids: extras.completed_checklist_ids ?? undefined,
            checklist_add: extras.checklist_add ?? undefined,
            checklist_delete_id: extras.checklist_delete_id ?? undefined,
            comment: extras.comment ?? '',
            mentioned_user_ids: extras.mentioned_user_ids ?? [],
        };
        const result = await api(`/flowtrack/template-api/tasks/${task.dbId}`, { method: 'PUT', body: payload });
        updateTaskAndJob(result);
        return result;
    }

    function boardCanChangeTaskDueDate(task) {
        if (!task) return false;
        const completed = taskIsCompleted(task) || boardTaskStatusBucket(task) === 'done';
        if (completed && !(isFlowAdmin || isFlowSuperAdmin)) return false;
        return Boolean(task.canChangeDueDate || task.canUpdate || isFlowAdmin || isFlowSuperAdmin);
    }

    function flowtrackCalendarIconHtml(className = 'ops-due-calendar') {
        // Keep every compact calendar mark identical to the Task Details icon.
        return `<span class="${html(className)}" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 3v3M17 3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z"/></svg></span>`;
    }

    function boardTaskDueHtml(task, overdueDays = 0) {
        const dueKey = boardDateOnly(task?.due);
        if (dueKey) {
            return `<div class="ops-due ${overdueDays ? 'overdue' : ''}">${flowtrackCalendarIconHtml()}<span>${boardShortDate(dueKey)}</span></div>`;
        }

        if (!boardCanChangeTaskDueDate(task)) {
            return `<div class="ops-due ops-due-missing ops-due-readonly-missing" title="No due date">${flowtrackCalendarIconHtml()}<span>No due date</span></div>`;
        }

        const taskId = html(task.id || '');
        const taskDbId = html(task.dbId || task.id || '');
        const title = html(task.title || task.id || 'Task');

        return `<div class="ops-due ops-due-missing"><label class="ops-set-due-label" data-board-set-due="${taskId}" title="Set due date for ${title}">${flowtrackCalendarIconHtml()}<span>Set due date</span><input type="date" class="ops-due-picker" data-board-due-picker="${taskId}" data-board-due-db="${taskDbId}" aria-label="Set due date for ${title}"></label></div>`;
    }

    async function persistTaskDueDate(task, dueDate) {
        if (!boardCanChangeTaskDueDate(task)) {
            throw new Error('Only the assigned user, an Administrator, or the Super Admin can set this due date.');
        }
        if (!task?.dbId) throw new Error('The selected task is missing its database ID. Refresh the board and try again.');
        const result = await api(`/flowtrack/template-api/tasks/${task.dbId}/due-date`, { method: 'PATCH', body: { due_date: dueDate || null } });
        updateTaskAndJob(result);
        return result;
    }

    function bindBoardDueDatePickers(scope, refreshCallback) {
        const root = typeof scope === 'string' ? $(scope) : (scope || document);
        if (!root?.querySelectorAll) return;
        root.querySelectorAll('[data-board-set-due], [data-board-due-picker]').forEach((element) => {
            element.addEventListener('click', (event) => event.stopPropagation());
            element.addEventListener('mousedown', (event) => event.stopPropagation());
            element.addEventListener('pointerdown', (event) => event.stopPropagation());
        });
        root.querySelectorAll('[data-board-due-picker]').forEach((input) => {
            input.addEventListener('change', async (event) => {
                event.preventDefault();
                event.stopPropagation();
                const selectedDate = input.value;
                if (!selectedDate) return;
                const task = flowtrackTaskFromActiveSurface(input.dataset.boardDuePicker, input.dataset.boardDueDb);
                if (!task) {
                    showToast('The selected task is no longer available.');
                    return;
                }
                input.disabled = true;
                const label = input.closest('.ops-set-due-label');
                label?.classList.add('saving');
                try {
                    await persistTaskDueDate(task, selectedDate);
                    updateBadges();
                    if (typeof refreshCallback === 'function') refreshCallback();
                    showToast('Due date set successfully.');
                } catch (error) {
                    input.disabled = false;
                    label?.classList.remove('saving');
                    input.value = '';
                    showToast(error.message || 'Could not set the due date.');
                }
            });
        });
    }

    registerFlowtrackExtension('openTask', 'openTask-stage-1', function (id) {
        const safeTaskId = String(id ?? '').trim();
        if (!safeTaskId) return null;
        const task = flowtrackTaskFromActiveSurface(safeTaskId, safeTaskId);
        if (!task) return;
        const job = jobs.find((item) => item.id === task.job || Number(item.dbId) === Number(task.jobDbId));
        const selectedStatus = taskStatusRecord(task.statusId || task.statusCode || task.status);
        const statuses = selectableTaskStatuses(selectedStatus);
        const priorityOptions = (masterData.priorities || [])
            .filter((priority) => String(priority.status || 'Active').toLowerCase() === 'active' || Number(priority.id) === Number(task.priorityId))
            .map((priority) => `<option value="${html(priority.id)}" ${Number(priority.id) === Number(task.priorityId) ? 'selected' : ''}>${html(priority.name)}</option>`)
            .join('');
        const taskAlreadyCompleted = taskIsCompleted(task) || taskStatusMatches(selectedStatus, ['CMP', 'COMPLETED', 'COMPLETE', 'DONE']);
        const completedTaskLocked = taskAlreadyCompleted && !(isFlowAdmin || isFlowSuperAdmin);
        const canEdit = !completedTaskLocked && (task.canUpdate || isFlowAdmin || isFlowSuperAdmin);
        const canDeleteTask = Boolean(task.canDelete || isFlowAdmin || isFlowSuperAdmin);
        const canChangeDue = !completedTaskLocked && (task.canChangeDueDate || isFlowAdmin || isFlowSuperAdmin);
        const initialCompleted = taskAlreadyCompleted;
        const progress = Math.max(0, Math.min(100, Number(task.progress) || 0));
        const history = Array.isArray(task.history) ? task.history : [];
        const mentionableUsers = flowtrackToArray(task.mentionableUsers).concat(flowtrackToArray(users))
            .filter((user, index, collection) => user && collection.findIndex((candidate) => Number(candidate.id) === Number(user.id)) === index);
        const taskAssigneeId = flowtrackResolveTaskAssigneeId(task);
        const taskAssigneeName = flowtrackResolveTaskAssigneeName(task, taskAssigneeId);
        const taskAssigneeOptions = flowtrackTaskAssigneeOptions(task, mentionableUsers);
        const taskAssigneeOptionsHtml = taskAssigneeOptions.map((user) => `<option value="${html(user.id)}" ${Number(user.id) === Number(taskAssigneeId) ? 'selected' : ''}>${html(user.name)}</option>`).join('');
        const documentRequirement = task.documentRequirement || null;
        const taskDocuments = Array.isArray(task.documents) ? task.documents : [];
        const formatTaskFileSize = (bytes) => {
            const value = Math.max(0, Number(bytes) || 0);
            if (value < 1024) return `${value} B`;
            if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;
            return `${(value / (1024 * 1024)).toFixed(1)} MB`;
        };
        const taskDocumentCategories = (masterData.documentCategories || []).filter((category) => String(category.status || 'Active').toLowerCase() === 'active');
        const genericTaskAttachment = Boolean(documentRequirement?.genericAttachment);
        const selectedTaskDocumentCategoryId = taskDocuments[0]?.categoryId || documentRequirement?.categoryId || null;
        const taskDocumentCategoryOptions = taskDocumentCategories.map((category) => `<option value="${html(category.id)}" ${Number(category.id) === Number(selectedTaskDocumentCategoryId) ? 'selected' : ''}>${html(category.name)}</option>`).join('');
        const documentRowsHtml = taskDocuments.flatMap((documentRecord) => {
            const versions = Array.isArray(documentRecord.versions) ? documentRecord.versions : [];
            return versions.map((version, versionIndex) => {
                const imagePreview = version.previewable && String(version.mimeType || '').startsWith('image/') && version.previewUrl
                    ? `<a href="${html(version.previewUrl)}" target="_blank" rel="noopener" title="Preview ${html(version.originalName)}"><img class="task-document-thumb" src="${html(version.previewUrl)}" alt="Preview of ${html(version.originalName)}"></a>`
                    : '<div class="doc-icon" style="flex:0 0 auto">FILE</div>';
                const currentBadge = versionIndex === 0 ? '<span class="badge b-green">Current</span>' : '';
                return `<div class="task-document-file">${imagePreview}<div class="task-document-file-copy"><b class="task-document-file-name">${html(version.originalName || documentRecord.name || 'Document')}</b><div class="small muted" style="margin-top:4px">Version ${version.version || 1} · ${formatTaskFileSize(version.size)} · ${html(version.uploadedBy || documentRecord.uploadedBy || 'System')}</div>${version.notes ? `<div class="small" style="margin-top:5px">${html(version.notes)}</div>` : ''}</div><div class="task-document-actions">${currentBadge}${version.previewUrl ? `<a class="secondary" href="${html(version.previewUrl)}" target="_blank" rel="noopener">Preview</a>` : ''}${version.downloadUrl ? `<a class="ghost" href="${html(version.downloadUrl)}">Download</a>` : ''}</div></div>`;
            });
        }).join('');
        const taskAttachmentVersions = taskDocuments.flatMap((documentRecord) => (Array.isArray(documentRecord.versions) ? documentRecord.versions : []).map((version, versionIndex) => ({ documentRecord, version, versionIndex })));
        const selectedTaskAttachment = taskAttachmentVersions[0] || null;
        const taskAttachmentRowsHtml = taskAttachmentVersions.map(({ documentRecord, version, versionIndex }, index) => {
            const fileName = version.originalName || documentRecord.name || 'Document';
            const owner = version.uploadedBy || documentRecord.uploadedBy || taskAssigneeName || 'System';
            const updated = version.uploadedAt ? flowtrackFormatDateTime(version.uploadedAt).split(',')[0] : (documentRecord.date || '—');
            const typeLabel = documentRecord.type || documentRequirement.categoryName || 'Attachment';
            const versionLabel = `v${version.version || documentRecord.version || versionIndex + 1}`;
            return `<tr class="${index === 0 ? 'selected' : ''}"><td><input type="checkbox" ${index === 0 ? 'checked' : ''} disabled></td><td><span class="job-v3-pdf">${String(fileName).toLowerCase().endsWith('.pdf') ? 'PDF' : 'FILE'}</span><b>${html(fileName)}</b><small>${html(typeLabel)} · ${formatTaskFileSize(version.size)}</small></td><td>${html(documentRequirement.sourceLabel || task.phase || 'Task')}</td><td>${html(typeLabel)}</td><td>${html(versionLabel)}</td><td><span class="avatar">${userInitials(owner)}</span> ${html(owner)}</td><td><span class="badge b-green">Approved</span></td><td>${html(updated)}</td><td>${version.previewUrl ? `<a class="mini-btn" href="${html(version.previewUrl)}" target="_blank" rel="noopener">Open</a>` : ''}</td></tr>`;
        }).join('');
        const taskAttachmentUploadHtml = task.canUploadDocument ? `<div class="task-v3-upload-panel task-document-attachment-card"><div class="task-attachment-template-head"><div class="task-attachment-icon">▧</div><div><h3>Document attachment</h3><p>${genericTaskAttachment ? `Task attachment: ${html(task.title || 'Task')}` : `Required for ${html(task.title || documentRequirement.sourceLabel || 'this task')}`}</p></div><span class="badge ${genericTaskAttachment ? 'b-blue' : 'b-amber'}">${genericTaskAttachment ? 'Attachment' : 'Required'}</span></div><div class="field"><label>Document type *</label><select id="taskDocumentCategory" ${genericTaskAttachment ? '' : 'disabled'}><option value="">Select document type</option>${taskDocumentCategoryOptions}</select>${genericTaskAttachment ? '' : `<input type="hidden" id="taskDocumentCategoryHidden" value="${html(documentRequirement.categoryId || '')}">`}</div><div class="field task-upload-field"><label>Upload file *</label><div class="task-attachment-dropzone" data-task-upload-dropzone><input id="taskDocumentFile" class="task-document-file-input" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.webp,.zip"><div class="task-upload-cloud">☁↑</div><b>Drag and drop your ${html(String(documentRequirement.categoryName || 'document').toLowerCase())} here</b><span>or</span><div class="task-upload-actions"><button type="button" class="primary" data-task-upload-browse>Browse files</button><button type="button" class="ghost" data-task-choose-documents>Choose from Documents</button></div><small>PDF, DOCX, XLSX, JPG, PNG or ZIP · Maximum 20 MB</small></div><div data-task-upload-preview-anchor></div></div><div class="task-attachment-info"><span>ⓘ</span><b>The file will be attached when you save this task.</b></div><div class="field"><label>Attachment note</label><textarea id="taskDocumentNotes" rows="2" placeholder="Add a version note, reference number or document description…"></textarea></div></div>` : (!taskDocuments.length ? '<div class="source-note" style="margin-top:12px"><b>Upload unavailable</b><div class="small muted" style="margin-top:4px">Documents can only be uploaded while this is the Job’s current phase and by users with Document Upload & Link access.</div></div>' : '');
        const taskAttachedFilesHtml = taskAttachmentVersions.length ? `<div class="task-attachment-existing-files"><h4>Attached files</h4>${taskAttachmentVersions.map(({ documentRecord, version, versionIndex }) => { const fileName = version.originalName || documentRecord.name || 'Document'; const owner = version.uploadedBy || documentRecord.uploadedBy || taskAssigneeName || 'System'; const typeLabel = documentRecord.type || documentRequirement.categoryName || 'Attachment'; const canDeleteDocument = Boolean(documentRecord.canDelete || canFlow('documents', 'delete')); return `<div class="task-attachment-existing-file"><span class="job-v3-pdf">${String(fileName).toLowerCase().endsWith('.pdf') ? 'PDF' : 'FILE'}</span><div><b>${html(fileName)}</b><small>${html(typeLabel)} · v${version.version || documentRecord.version || versionIndex + 1} · ${html(owner)}</small></div><div class="task-document-actions">${version.previewUrl ? `<a class="mini-btn" href="${html(version.previewUrl)}" target="_blank" rel="noopener">Open</a>` : ''}${versionIndex === 0 && canDeleteDocument ? `<button type="button" class="mini-btn task-attachment-delete" data-task-legacy-delete-document="${html(documentRecord.dbId)}">Delete</button>` : ''}</div></div>`; }).join('')}</div>` : '';
        const taskAttachmentReadonlyHtml = taskAttachedFilesHtml ? `<div class="task-document-attachment-card"><div class="task-attachment-template-head"><div class="task-attachment-icon">▧</div><div><h3>Document attachment</h3><p>${html(documentRequirement.categoryName)} · ${html(documentRequirement.sourceLabel || task.title || 'Task')}</p></div><span class="badge b-green">Uploaded</span></div>${taskAttachedFilesHtml}</div>` : '';
        const documentSectionHtml = documentRequirement ? `<section class="task-v3-doc-section task-attachment-system-section">${taskAttachmentUploadHtml || taskAttachmentReadonlyHtml}</section>` : '';
        const historyHtml = history.map((entry) => `<div class="task-history-entry">
            <div class="task-history-head"><span class="avatar">${html(entry.actorInitials || userInitials(entry.actor))}</span><div class="task-history-copy"><b>${html(entry.actor || 'System')}</b><div style="margin-top:3px">${html(entry.description || 'Task updated')}</div><div class="small muted" style="margin-top:3px">${html(entry.time || (entry.occurredAt ? new Date(entry.occurredAt).toLocaleString() : ''))}</div></div></div>
            ${(entry.changes || []).length ? `<ul class="task-change-list">${entry.changes.map((change) => `<li><b>${html(change.field)}:</b> ${html(change.from)} → ${html(change.to)}</li>`).join('')}</ul>` : ''}
        </div>`).join('');

        $('#detailDrawer').innerHTML = `<div class="drawer-head"><div><div class="small muted"><span class="flow-id-link-row">${flowtrackCopyIdentifierButton(task.id, 'Task ID', 'flow-id-copy-inline')}</span> · <span class="flow-id-link-row"><button type="button" class="task-job-link" data-task-job-link>${html(task.job)}</button>${flowtrackCopyIdentifierButton(task.job, 'Job ID', 'flow-id-copy-icon-only')}</span></div><h2>${html(task.title)}</h2><div>${statusBadge(task.status, task.statusColor)} ${priorityBadge(task.priority, task.priorityColor)}</div></div><div class="task-head-actions">${canDeleteTask ? `<div class="task-menu-wrap"><button class="task-menu-button" type="button" id="taskActionMenuButton" aria-label="Task actions" aria-expanded="false">•••</button><div class="task-action-menu hidden" id="taskActionMenu"><button type="button" class="danger-option" data-delete-task="${task.dbId}">Delete Task</button></div></div>` : ''}<button class="close-btn" data-close>×</button></div></div>
        <div class="drawer-body"><div class="summary-grid"><div class="summary-box"><span>Job</span><button type="button" class="task-job-link" data-task-job-link>${html(job?.title || task.jobTitle || task.job)}</button></div><div class="summary-box"><span>Assignee</span><b>${html(taskAssigneeName)}</b></div><div class="summary-box"><span>Due Date</span><b class="${dueClass(task.due)}">${formatDate(task.due)}</b></div></div>${completedTaskLocked ? '<div class="source-note" style="margin-top:14px"><b>Completed task locked</b><div class="small muted" style="margin-top:4px">Only Admin or Super Admin can edit/update a completed Task. You can still view documents, comments and history.</div></div>' : ''}
        <div class="form-grid" style="margin-top:18px"><div class="field full"><label>Task title</label><input id="taskUpdateTitle" value="${html(task.title || '')}" maxlength="255" ${canEdit ? '' : 'disabled'}></div>
        <div class="field full"><label>Description</label><textarea id="taskUpdateDescription" rows="3" maxlength="5000" placeholder="Add clear sub task details, expected output or instructions…" ${canEdit ? '' : 'disabled'}>${html(task.description || '')}</textarea><div class="inline-help">Admins can update completed tasks. Before completion, authorised task owners can update their assigned work without changing the workflow template.</div></div>
        <div class="field"><label>Status</label><select id="taskUpdateStatus" ${canEdit ? '' : 'disabled'}>${statuses.map((status) => `<option value="${status.id}" ${Number(status.id) === Number(selectedStatus?.id) ? 'selected' : ''}>${html(status.name)}</option>`).join('')}</select></div>
        <div class="field"><label>Priority</label><select id="taskUpdatePriority" ${canEdit ? '' : 'disabled'}><option value="">Use job priority</option>${priorityOptions}</select></div>
        <div class="field"><label>Assignee</label><select id="taskUpdateAssignee" data-original-assignee="${html(taskAssigneeId || '')}" ${task.canAssign || isFlowAdmin || isFlowSuperAdmin ? '' : 'disabled'}><option value="" ${taskAssigneeId ? '' : 'selected'}>Unassigned</option>${taskAssigneeOptionsHtml}</select><div class="inline-help">Default assignee from Task Pack Setup is selected automatically unless Admin/Super Admin changes it.</div></div>
        <div class="field"><label>Due Date</label><input type="date" id="taskUpdateDueDate" value="${task.due ? task.due.split('T')[0] : ''}" ${canChangeDue ? '' : 'disabled'}><div class="inline-help">Available for regular tasks and subtasks.</div></div>
        <div class="field full"><label>Progress: <b id="progressValue">${progress}%</b></label><div class="task-progress-track" aria-label="Task progress"><span id="taskProgressFill" class="task-progress-fill ${progress >= 100 ? 'complete' : ''}" style="width:${progress}%"></span></div><div class="inline-help">Progress is calculated automatically from the selected status.</div></div></div>
        ${documentSectionHtml}
        <div class="field mention-wrap" style="margin-top:18px"><label>Update comment</label><textarea id="taskComment" rows="3" placeholder="Add progress, decision or next action. Type @ to mention a Job team member…" ${canEdit ? '' : 'disabled'}></textarea><div id="taskMentionMenu" class="mention-menu hidden"></div><div class="inline-help">Type @ and choose a person. They will receive a notification that opens this Task.</div></div>
        <label class="check-row task-attention-row"><input id="taskAttention" type="checkbox" ${task.attention ? 'checked' : ''} ${canEdit && !initialCompleted ? '' : 'disabled'}><span class="task-attention-control" aria-hidden="true"></span><span class="task-attention-copy"><b>Mark as attention needed</b><small>Show this Task in Dashboard → Needs Attention until the flag is cleared.</small></span></label>
        <div class="section-head" style="margin-top:18px"><h3>Activity & Comments</h3></div>
        ${(task.comments || []).map((comment) => `<div class="comment"><span class="avatar">${userInitials(comment.user)}</span><div><b>${html(comment.user)}</b><div style="margin-top:4px">${html(comment.text)}</div><div class="small muted" style="margin-top:4px">${html(comment.time)}</div></div></div>`).join('') || '<div class="empty">No comments yet.</div>'}
        <div class="section-head" style="margin-top:22px"><h3>History & Change Log</h3></div><div class="task-history">${historyHtml || '<div class="empty">No recorded changes yet.</div>'}</div>
        ${canEdit ? '' : '<div class="source-note" style="margin-top:18px"><b>Limited Task Access</b><div class="small muted" style="margin-top:4px">Everyone assigned to this Job can view its Tasks. Full updates remain limited to the assignee or an Administrator; authorised Job managers can still change the due date.</div></div>'}
        <div class="actions" style="margin-top:18px">${(canEdit || canChangeDue) ? '<button class="secondary" id="saveTaskDraft">Draft</button>' : ''}${canEdit ? '<button class="primary" id="saveTaskUpdate" style="flex:1">Save</button>' : '<button class="ghost" data-close style="flex:1">Close</button>'}</div></div>`;

        openOverlay();
        $('#detailDrawer').classList.add('open');
        $$('[data-close]').forEach((button) => button.addEventListener('click', closeAll));

        const openLinkedJob = () => {
            closeAll();
            setTimeout(() => {
                if (job) void openJob(job.id);
                else if (task.jobDbId) flowtrackNavigateTo(`/flowtrack/jobs/${task.jobDbId}`);
            }, 30);
        };
        $$('[data-task-job-link]').forEach((button) => button.addEventListener('click', openLinkedJob));

        $('#taskActionMenuButton')?.addEventListener('click', (event) => {
            event.stopPropagation();
            const menu = $('#taskActionMenu');
            menu?.classList.toggle('hidden');
            $('#taskActionMenuButton')?.setAttribute('aria-expanded', menu?.classList.contains('hidden') ? 'false' : 'true');
        });
        const uploadSelectedTaskDocument = async () => {
            const fileInput = $('#taskDocumentFile');
            const file = fileInput?.files?.[0];
            if (!file) return null;
            if (!documentRequirement || !task.jobDbId) {
                throw new Error('The exact document requirement could not be resolved.');
            }
            if (file.size > 20 * 1024 * 1024) {
                throw new Error('The document must not be larger than 20 MB.');
            }

            const selectedDocumentCategoryId = genericTaskAttachment
                ? Number($('#taskDocumentCategory')?.value || 0)
                : Number(documentRequirement.categoryId || 0);
            if (!selectedDocumentCategoryId) {
                $('#taskDocumentCategory')?.focus();
                throw new Error('Choose the document type before saving.');
            }

            const formData = new FormData();
            formData.append('job_id', String(task.jobDbId));
            formData.append('workflow_phase_id', String(documentRequirement.phaseId));
            if (documentRequirement.taskPackItemId) {
                formData.append('task_pack_item_id', String(documentRequirement.taskPackItemId));
            }
            formData.append('document_category_id', String(selectedDocumentCategoryId));
            if (documentRequirement.taskId) formData.append('task_id', String(documentRequirement.taskId));
            const existingDocument = taskDocuments[0];
            if (existingDocument?.dbId) formData.append('document_id', String(existingDocument.dbId));
            formData.append('file', file);
            const notes = $('#taskDocumentNotes')?.value?.trim();
            if (notes) formData.append('notes', notes);

            const result = await api('/flowtrack/template-api/documents', { method: 'POST', body: formData });
            const documentIndex = docs.findIndex((item) => Number(item.dbId) === Number(result.document?.dbId));
            if (result.document && documentIndex >= 0) docs.splice(documentIndex, 1, result.document);
            else if (result.document) docs.unshift(result.document);

            if (result.job) {
                const jobIndex = jobs.findIndex((item) => Number(item.dbId) === Number(result.job.dbId));
                if (jobIndex >= 0) jobs.splice(jobIndex, 1, result.job);
                if (Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
            }

            return result;
        };

        const syncProgressPreview = () => {
            const status = taskStatusRecord($('#taskUpdateStatus').value);
            const nextProgress = taskProgressForStatus(status);
            const completed = taskStatusMatches(status, ['CMP', 'COMPLETED', 'COMPLETE', 'DONE']);
            $('#progressValue').textContent = `${nextProgress}%`;
            $('#taskProgressFill').style.width = `${nextProgress}%`;
            $('#taskProgressFill').classList.toggle('complete', nextProgress >= 100);
            const attentionInput = $('#taskAttention');
            if (completed) {
                attentionInput.checked = false;
                attentionInput.disabled = true;
            } else {
                attentionInput.disabled = !canEdit;
            }
        };
        $('#taskUpdateStatus')?.addEventListener('change', syncProgressPreview);
        syncProgressPreview();

        const selectedFilePreviewUrl = { value: null };
        const renderSelectedTaskDocumentPreview = (file, state = 'ready') => {
            const existingPreview = $('#taskDocumentSelectionPreview');
            if (selectedFilePreviewUrl.value) {
                URL.revokeObjectURL(selectedFilePreviewUrl.value);
                selectedFilePreviewUrl.value = null;
            }
            if (!file) {
                existingPreview?.remove();
                return;
            }

            const safeSize = formatTaskFileSize(file.size);
            const isImage = String(file.type || '').startsWith('image/');
            const previewUrl = isImage ? URL.createObjectURL(file) : '';
            if (previewUrl) selectedFilePreviewUrl.value = previewUrl;
            const statusText = state === 'uploading'
                ? 'Uploading on Save…'
                : 'Selected in browser cache. It will be stored on the server only after Save.';
            const progressWidth = state === 'uploading' ? 70 : 100;
            const previewHtml = `<div id="taskDocumentSelectionPreview" class="task-document-selected-preview" aria-live="polite">
                ${previewUrl ? `<img src="${previewUrl}" alt="Selected file preview">` : '<div class="doc-icon" style="flex:0 0 auto">FILE</div>'}
                <div class="task-document-file-copy"><b class="task-document-file-name">${html(file.name)}</b><div class="small muted">${safeSize} · ${html(statusText)}</div><div class="task-upload-progress"><span style="width:${progressWidth}%"></span></div></div>
            </div>`;
            if (existingPreview) existingPreview.outerHTML = previewHtml;
            else {
                const previewAnchor = $('[data-task-upload-preview-anchor]') || $('#taskDocumentFile');
                previewAnchor?.insertAdjacentHTML('afterend', previewHtml);
            }
        };
        const taskDocumentFileInput = $('#taskDocumentFile');
        const taskDropzone = $('[data-task-upload-dropzone]');
        $('[data-task-upload-browse]')?.addEventListener('click', () => taskDocumentFileInput?.click());
        $('[data-task-choose-documents]')?.addEventListener('click', () => {
            const selectedCategoryId = genericTaskAttachment
                ? Number($('#taskDocumentCategory')?.value || 0)
                : Number(documentRequirement?.categoryId || 0);
            if (!selectedCategoryId) return showToast('Choose the document type first.');
            const linkedRequirement = { ...documentRequirement, categoryId: selectedCategoryId };
            const linkedJob = job || jobs.find((item) => Number(item.dbId) === Number(task.jobDbId) || String(item.id) === String(task.job));
            if (!linkedJob) return showToast('The linked Job could not be loaded.');
            flowtrackOpenStoredDocumentChooser(linkedJob, linkedRequirement, task, () => {
                setTimeout(() => void openTask(task.id || task.dbId), 30);
            });
        });
        $$('[data-task-legacy-delete-document]').forEach((button) => button.addEventListener('click', () => {
            const documentId = Number(button.dataset.taskLegacyDeleteDocument || 0);
            const documentRecord = taskDocuments.find((item) => Number(item.dbId) === documentId);
            if (!documentRecord) return showToast('The selected document is no longer available.');
            flowtrackConfirmDeleteDocument(documentRecord, (result) => {
                if (result?.job && Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
                setTimeout(() => void openTask(task.id || task.dbId), 30);
            });
        }));
        taskDropzone?.addEventListener('dragover', (event) => {
            event.preventDefault();
            taskDropzone.classList.add('is-dragging');
        });
        taskDropzone?.addEventListener('dragleave', () => taskDropzone.classList.remove('is-dragging'));
        taskDropzone?.addEventListener('drop', (event) => {
            event.preventDefault();
            taskDropzone.classList.remove('is-dragging');
            const file = event.dataTransfer?.files?.[0];
            if (!file || !taskDocumentFileInput) return;
            const transfer = new DataTransfer();
            transfer.items.add(file);
            taskDocumentFileInput.files = transfer.files;
            taskDocumentFileInput.dispatchEvent(new Event('change', { bubbles: true }));
        });
        taskDocumentFileInput?.addEventListener('change', (event) => {
            renderSelectedTaskDocumentPreview(event.target.files?.[0] || null);
        });

        const mentionedIds = new Set();
        const commentBox = $('#taskComment');
        const mentionMenu = $('#taskMentionMenu');
        bindFlowtrackMentionInput(commentBox, mentionMenu, mentionableUsers, mentionedIds);

        $('[data-delete-task]')?.addEventListener('click', async () => {
            $('#taskActionMenu')?.classList.add('hidden');
            if (!confirm(`Delete task ${task.id}?`)) return;
            try {
                const result = await api(`/flowtrack/template-api/tasks/${task.dbId}`, { method: 'DELETE' });
                if (result.job) {
                    const jobIndex = jobs.findIndex((item) => Number(item.dbId) === Number(result.job.dbId));
                    if (jobIndex >= 0) jobs.splice(jobIndex, 1, result.job);
                    if (Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
                }
                flowtrackRemoveRecord(tasks, result.deletedTask || task);
                closeAll(); renderPage(currentPage); updateBadges(); showToast('Task deleted successfully.');
            } catch (error) { showToast(error.message); }
        });
        applyActionPermissions();

        const collectActiveMentionIds = (comment) => flowtrackToArray(new Set(
            flowtrackToArray(mentionedIds).concat(
                flowtrackToArray(mentionableUsers)
                    .filter((user) => String(comment || '').toLowerCase().includes(`@${String(user.name || '').toLowerCase()}`))
                    .map((user) => Number(user.id)),
            ),
        )).filter((userId) => Number.isFinite(Number(userId)));

        $('#saveTaskDraft')?.addEventListener('click', async () => {
            const button = $('#saveTaskDraft');
            const comment = $('#taskComment')?.value?.trim() || '';
            const selectedFile = $('#taskDocumentFile')?.files?.[0] || null;
            const draftStatus = selectedStatus || taskStatusRecord(task.statusId || task.statusCode || task.status) || taskStatusRecord(task.status) || taskStatusRecord('READY');
            button.disabled = true;
            button.textContent = 'Saving…';
            try {
                const result = await persistTask(task, draftStatus, {
                    title: $('#taskUpdateTitle')?.value?.trim() || task.title,
                    description: $('#taskUpdateDescription')?.value?.trim() || null,
                    assignee_id: $('#taskUpdateAssignee')?.value ? Number($('#taskUpdateAssignee').value) : null,
                    priority_id: $('#taskUpdatePriority')?.value ? Number($('#taskUpdatePriority').value) : null,
                    due_date: $('#taskUpdateDueDate')?.value || null,
                    attention_required: taskIsCompleted(task) ? false : Boolean($('#taskAttention')?.checked),
                    blocker_reason: task.blockerReason || null,
                    comment,
                    mentioned_user_ids: collectActiveMentionIds(comment),
                });
                updateTaskAndJob(result);
                renderPage(currentPage);
                updateBadges();
                showToast(selectedFile ? 'Draft saved. The selected file will upload only after Save.' : 'Draft saved.');
                openTask(result.task?.id || task.id);
            } catch (error) {
                showToast(error.message);
            } finally {
                button.disabled = false;
                button.textContent = 'Draft';
            }
        });

        $('#saveTaskUpdate')?.addEventListener('click', async () => {
            const status = taskStatusRecord($('#taskUpdateStatus').value);
            const comment = $('#taskComment').value.trim();
            const completed = taskStatusMatches(status, ['CMP', 'COMPLETED', 'COMPLETE', 'DONE']);
            const attention = completed ? false : $('#taskAttention').checked;
            const isBlocked = taskStatusMatches(status, ['BLK', 'BLOCKED', 'ATTN', 'ATTENTION', 'HOLD', 'ON HOLD']);
            if ((isBlocked || attention) && !comment && !task.blockerReason) {
                showToast('Please add a short reason for the blocker or attention flag.');
                $('#taskComment').focus();
                return;
            }

            const selectedFile = $('#taskDocumentFile')?.files?.[0] || null;
            if (selectedFile && selectedFile.size > 20 * 1024 * 1024) {
                showToast('The document must not be larger than 20 MB.');
                $('#taskDocumentFile')?.focus();
                return;
            }
            if (selectedFile && genericTaskAttachment && !Number($('#taskDocumentCategory')?.value || 0)) {
                showToast('Choose the document type before saving.');
                $('#taskDocumentCategory')?.focus();
                return;
            }

            const activeMentionIds = collectActiveMentionIds(comment);
            const button = $('#saveTaskUpdate');
            button.disabled = true;
            button.textContent = selectedFile ? 'Saving & Uploading…' : 'Saving…';
            try {
                let uploadResult = null;
                if (selectedFile) {
                    renderSelectedTaskDocumentPreview(selectedFile, 'uploading');
                    uploadResult = await uploadSelectedTaskDocument();
                }

                const completedStatus = taskStatusRecord('CMP') || taskStatusRecord('Completed') || taskStatusRecord('Complete') || taskStatusRecord('Done');
                const statusToSave = selectedFile && documentRequirement && !genericTaskAttachment && completedStatus
                    ? completedStatus
                    : status;
                const finalCompleted = taskStatusMatches(statusToSave, ['CMP', 'COMPLETED', 'COMPLETE', 'DONE']);
                const taskResult = await persistTask(task, statusToSave, {
                    title: $('#taskUpdateTitle')?.value?.trim() || task.title,
                    description: $('#taskUpdateDescription')?.value?.trim() || null,
                    assignee_id: $('#taskUpdateAssignee').value ? Number($('#taskUpdateAssignee').value) : null,
                    priority_id: $('#taskUpdatePriority')?.value ? Number($('#taskUpdatePriority').value) : null,
                    due_date: $('#taskUpdateDueDate').value || null,
                    attention_required: finalCompleted ? false : attention,
                    blocker_reason: (!finalCompleted && (isBlocked || attention)) ? (comment || task.blockerReason) : null,
                    comment,
                    mentioned_user_ids: activeMentionIds,
                });

                renderPage(currentPage);
                updateBadges();
                showToast(uploadResult?.message || (selectedFile
                    ? 'Task update and attachment saved successfully.'
                    : 'Task update saved. Progress and history were updated.'));
                const updatedTask = (uploadResult?.tasks || []).find((item) => Number(item.dbId) === Number(task.dbId));
                openTask(updatedTask?.id || taskResult.task?.id || task.id);
            } catch (error) {
                showToast(error.message);
            } finally {
                button.disabled = false;
                button.textContent = 'Save';
            }
        });
    });

    async function moveJobToPhase(job, phase, reason = null) {
        const result = await api(`/flowtrack/template-api/jobs/${job.dbId}/phase`, {
            method: 'PATCH',
            body: { phase_id: phase.id, reason },
        });
        const index = jobs.findIndex((item) => Number(item.dbId) === Number(result.job.dbId));
        if (index >= 0) jobs.splice(index, 1, result.job);
        replaceTasksForJob(result.job.id, result.tasks);
        return result;
    }

    registerFlowtrackExtension('renderKanban', 'renderKanban-stage-1', function () {        const area = $('#kanbanArea');
        if (boardMode === 'tasks') {
            const configuredStatuses = (masterData.taskStatuses || [])
                .filter((status) => String(status.status || 'Active').toLowerCase() === 'active')
                .map((status) => ({ id: status.id, name: status.name, code: status.code, configured: true }));
            const configuredIds = new Set(configuredStatuses.map((status) => Number(status.id)));
            const legacyStatuses = [];
            tasks.forEach((task) => {
                if (task.statusId && !configuredIds.has(Number(task.statusId)) && !legacyStatuses.some((status) => Number(status.id) === Number(task.statusId))) {
                    legacyStatuses.push({
                        id: task.statusId,
                        name: task.status || 'Inactive status',
                        code: task.statusCode || null,
                        configured: false,
                    });
                } else if (!task.statusId && !legacyStatuses.some((status) => status.id === null && status.name === (task.status || 'Not Started'))) {
                    legacyStatuses.push({
                        id: null,
                        name: task.status || 'Not Started',
                        code: task.statusCode || null,
                        configured: false,
                    });
                }
            });
            const columns = flowtrackToArray(configuredStatuses).concat(flowtrackToArray(legacyStatuses));
            if (!columns.length) columns.push({ id: null, name: 'No task statuses configured', code: null, configured: false });

            const tasksForColumn = (column) => tasks.filter((task) => column.id !== null
                ? Number(task.statusId) === Number(column.id)
                : !task.statusId && normalizedStatusValue(task.status || 'Not Started') === normalizedStatusValue(column.name));

            area.innerHTML = `<div class="kanban" style="grid-template-columns:repeat(${Math.max(1, columns.length)},minmax(230px,1fr))">${columns.map((column) => {
                const columnTasks = tasksForColumn(column);
                const dropValue = column.configured ? String(column.id) : '';
                const statusColor = flowtrackTaskStatusColor(column, column.color || '');
                return `<div class="kanban-col"><div class="kanban-head" style="${flowtrackMasterToneStyle(statusColor, { backgroundAlpha: 0.055, borderAlpha: 0.2 })}"><span>${html(column.name)}${column.configured ? '' : ' · Legacy'}</span><span class="count" style="${flowtrackMasterToneStyle(statusColor, { backgroundAlpha: 0.12, borderAlpha: 0.18 })}">${columnTasks.length}</span></div><div class="kanban-list" ${dropValue ? `data-drop-status="${html(dropValue)}"` : 'data-readonly-status="true"'}>${columnTasks.map((task) => `<article class="kanban-card" draggable="${task.canUpdate || isFlowAdmin || isFlowSuperAdmin ? 'true' : 'false'}" data-task="${task.id}" data-task-updatable="${task.canUpdate || isFlowAdmin || isFlowSuperAdmin ? '1' : '0'}">${priorityBadge(task.priority, task.priorityColor)}<div class="kanban-card-title">${html(task.title)}</div><div class="kanban-job"><button type="button" class="kanban-job-link" data-board-job-link="${html(task.job)}">${html(task.job)}</button> · ${html(task.phase)}</div><div class="kanban-meta"><span class="avatar" style="width:25px;height:25px;font-size:9px">${userInitials(task.assignee)}</span><span class="due ${dueClass(task.due)} small">${formatDate(task.due)}</span></div><div class="progress" style="margin-top:9px"><span class="${Number(task.progress) >= 100 ? 'complete' : ''}" style="width:${Math.max(0, Math.min(100, Number(task.progress) || 0))}%"></span></div></article>`).join('')}</div></div>`;
            }).join('')}</div>`;
        } else {
            const activeWorkflows = (workflowTemplates || []).filter((workflow) => workflow.active !== false);
            const selectedWorkflow = activeWorkflows.find((workflow) => Number(workflow.id) === Number(boardWorkflowId))
                || activeWorkflows[0]
                || workflowTemplates[0];
            if (selectedWorkflow) boardWorkflowId = selectedWorkflow.id;

            // Board columns are workflow-wide. Job-specific excluded phases are checked when a card is dropped.
            const phases = (selectedWorkflow?.phases || [])
                .filter((phase) => phase.active !== false)
                .slice()
                .sort((left, right) => Number(left.sequence || 0) - Number(right.sequence || 0));
            const workflowJobs = selectedWorkflow
                ? jobs.filter((job) => Number(job.workflow) === Number(selectedWorkflow.id)
                    && (isFlowAdmin || isFlowSuperAdmin || boardJobIds.has(Number(job.dbId))))
                : [];

            const workflowOptions = activeWorkflows.map((workflow) =>
                `<option value="${workflow.id}" ${Number(workflow.id) === Number(selectedWorkflow?.id) ? 'selected' : ''}>${html(workflow.name)}</option>`
            ).join('');

            area.innerHTML = `<div class="card" style="padding:12px;margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap"><b>Workflow</b><select id="boardWorkflowSelect" style="min-width:240px">${workflowOptions}</select><span class="small muted">The board uses the selected workflow's configured phases and IDs.</span></div>
            <div class="kanban" style="grid-template-columns:repeat(${Math.max(1, phases.length)},minmax(230px,1fr))">${phases.map((phase) => {
                const phaseJobs = workflowJobs.filter((job) => Number(job.phaseId) === Number(phase.id));
                return `<div class="kanban-col"><div class="kanban-head"><span>${html(phase.short || phase.shortName || phase.name)}</span><span class="count">${phaseJobs.length}</span></div><div class="kanban-list" data-drop-phase-id="${phase.id}">${phaseJobs.map((job) => `<article class="kanban-card" draggable="true" data-job="${job.id}">${healthBadge(job.health)}<div class="kanban-card-title">${html(job.title)}</div><div class="kanban-job">${html(job.id)} · ${html(clientName(job.client))}</div><div class="kanban-meta"><span class="avatar" style="width:25px;height:25px;font-size:9px">${userInitials(job.coordinator)}</span><span class="small">${job.progress}%</span></div><div class="progress" style="margin-top:9px"><span style="width:${job.progress}%"></span></div></article>`).join('')}</div></div>`;
            }).join('') || '<div class="card" style="padding:18px">No active phases are configured for this workflow.</div>'}</div>`;

            $('#boardWorkflowSelect')?.addEventListener('change', (event) => {
                boardWorkflowId = Number(event.target.value);
                renderKanban();
            });
        }

        let dragged = null;
        $$('.kanban-card').forEach((card) => {
            card.addEventListener('dragstart', (event) => {
                if (boardMode === 'tasks' && card.dataset.taskUpdatable === '0') {
                    event.preventDefault();
                    showToast('Only the assigned user, an Administrator, or the Super Admin can update this Task.');
                    return;
                }
                dragged = card;
                card.classList.add('dragging');
            });
            card.addEventListener('dragend', () => {
                card.classList.remove('dragging');
                dragged = null;
            });
        });
        $$('.kanban-list').forEach((list) => {
            list.addEventListener('dragover', (event) => {
                if (list.dataset.readonlyStatus === 'true') return;
                event.preventDefault();
            });
            list.addEventListener('drop', async () => {
                if (!dragged) return;
                try {
                    if (boardMode === 'tasks') {
                        if (!list.dataset.dropStatus) {
                            throw new Error('Move the task to an active configured status.');
                        }
                        const task = flowtrackTaskFromActiveSurface(dragged.dataset.task);
                        if (!task) throw new Error('The selected task is no longer available.');
                        const targetStatus = taskStatusRecord(list.dataset.dropStatus);
                        if (!targetStatus) throw new Error('The selected task status is not available in this workspace.');
                        if (taskStatusMatches(targetStatus, ['BLK', 'ATTN', 'BLOCKED', 'ATTENTION', 'HOLD', 'ON HOLD'])) {
                            showToast('Open the task and add a blocker reason before marking it blocked.');
                            openTask(task.id);
                            return;
                        }
                        await persistTask(task, targetStatus);
                    } else {
                        const job = jobs.find((item) => item.id === dragged.dataset.job);
                        if (!job) throw new Error('The selected Job is no longer available.');
                        const workflow = getWorkflow(Number(job.workflow));
                        const phaseId = Number(list.dataset.dropPhaseId);
                        const excludedPhaseIds = new Set((job.excludedPhaseIds || []).map((id) => Number(id)));
                        const phase = (workflow?.phases || []).find((item) => Number(item.id) === phaseId && item.active !== false && !excludedPhaseIds.has(Number(item.id)));
                        if (!phase) throw new Error('The selected workflow phase is not configured for this Job.');

                        const activePhases = (workflow.phases || [])
                            .filter((item) => item.active !== false && !excludedPhaseIds.has(Number(item.id)))
                            .slice()
                            .sort((left, right) => Number(left.sequence || 0) - Number(right.sequence || 0));
                        const currentIndex = activePhases.findIndex((item) => Number(item.id) === Number(job.phaseId));
                        const targetIndex = activePhases.findIndex((item) => Number(item.id) === Number(phase.id));
                        let reason = null;
                        if (currentIndex >= 0 && targetIndex > currentIndex + 1) {
                            reason = window.prompt('Enter the authorised reason for skipping workflow phases:')?.trim() || '';
                            if (!reason) {
                                showToast('A reason is required when skipping workflow phases.');
                                renderKanban();
                                return;
                            }
                        }
                        await moveJobToPhase(job, phase, reason);
                    }
                    renderKanban();
                    updateBadges();
                    showToast('Board updated. Workflow state and progress were recalculated.');
                } catch (error) {
                    showToast(error.message);
                    renderKanban();
                }
            });
        });
        bindCommon();
    });



    let boardSearchValue = '';
    let boardQuickFilter = 'all';
    let boardFilters = { job: '', client: '', assignee: '', status: '', due: '', priority: '' };
    let myWorkSearchValue = '';
    let myWorkQuickFilter = 'all';
    let myWorkFilters = { job: '', client: '', assignee: '', status: '', priority: '', due: '' };
    let jobSearchRenderTimer = null;
    const collapsedBoardJobs = new Set();
    const expandedBoardJobCards = new Set();
    let jobListFilters = { owner: '', delivery: '', invoice: '', more: '', saved: 'all' };
    let jobListPage = 1;
    let jobListPageSize = 10;
    try {
        const storedJobPageSize = Number(localStorage.getItem('flowtrackJobListPageSize') || 10);
        if ([10, 20, 30, 40].includes(storedJobPageSize)) jobListPageSize = storedJobPageSize;
    } catch (_) {}
    const selectedBulkJobIds = new Set();

    
    /* CSS externalized: operationsBoardStyles. */

    
    /* CSS externalized: myWorkBoardStyles. */

    
    /* CSS externalized: myWorkBottomActionStyles. */
    
    /* CSS externalized: metricClickableStyles. */

    
    /* CSS externalized: jobBulkActionStyles. */

    
    /* CSS externalized: jobBoardRedesignStyles. */


    
    /* CSS externalized: compactOperationsBoardStyles. */

    
    /* CSS externalized: denseWorkflowBoardStyles. */


    
    /* CSS externalized: boardScopeCorrectionStyles. */

    
    /* CSS externalized: myWorkBoardFinalStyles. */

    
    /* CSS externalized: boardFilterCompactFinalStyles. */


    try { if (typeof boardMode !== 'undefined' && !sessionStorage.getItem('flowtrackBoardModeTouched')) boardMode = 'jobs'; } catch (_) { boardMode = 'jobs'; }

    function boardDateOnly(value) {
        if (!value) return '';
        const raw = String(value);
        if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;
        const parsed = new Date(raw);
        if (Number.isNaN(parsed.getTime())) return '';
        return localDateKey(parsed);
    }

    function boardShortDate(value) {
        const key = boardDateOnly(value);
        if (!key) return '—';
        return new Date(`${key}T00:00:00`).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }

    function boardDaysOverdue(value) {
        const key = boardDateOnly(value);
        if (!key || key >= today) return 0;
        return Math.max(1, Math.round((new Date(`${today}T00:00:00`) - new Date(`${key}T00:00:00`)) / 86400000));
    }

    function boardDaysUntil(value) {
        const key = boardDateOnly(value);
        if (!key) return null;
        return Math.round((new Date(`${key}T00:00:00`) - new Date(`${today}T00:00:00`)) / 86400000);
    }

    function boardJobForTask(task) {
        const sourceJobs = currentPage === 'mywork' && myWorkSnapshotLoaded
            ? myWorkSnapshotJobs
            : jobs;
        return flowtrackToArray(sourceJobs).find((job) => String(job.id) === String(task.job) || Number(job.dbId) === Number(task.jobDbId))
            || flowtrackToArray(jobs).find((job) => String(job.id) === String(task.job) || Number(job.dbId) === Number(task.jobDbId))
            || null;
    }

    function boardActiveJobs() {
        return jobs.filter((job) => String(job.status || '').toLowerCase() !== 'completed' && String(job.health || '').toLowerCase() !== 'completed');
    }

    function boardActiveTaskStatuses() {
        return (masterData.taskStatuses || [])
            .filter((status) => String(status.status || 'Active').toLowerCase() === 'active')
            .slice()
            .sort((left, right) => {
                const leftOrder = Number(left.sortOrder ?? left.sort_order ?? left.order ?? left.sequence ?? left.id ?? 0);
                const rightOrder = Number(right.sortOrder ?? right.sort_order ?? right.order ?? right.sequence ?? right.id ?? 0);
                if (leftOrder !== rightOrder) return leftOrder - rightOrder;
                return String(left.name || left.code || '').localeCompare(String(right.name || right.code || ''));
            });
    }

    function boardColumnKeyForStatus(status) {
        if (!status) return 'status-unassigned';
        if (status.id !== undefined && status.id !== null && status.id !== '') return `status-${status.id}`;
        const fallback = String(status.code || status.name || 'unassigned').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'unassigned';
        return `status-${fallback}`;
    }

    function boardTaskStatusBucket(task) {
        const statusRecord = taskStatusRecord(task.statusId || { code: task.statusCode, name: task.status });
        const statusName = normalizedStatusValue(`${statusRecord?.name || ''} ${task.status || ''}`);
        const statusCode = normalizedStatusValue(statusRecord?.code || task.statusCode || '');
        if (['CMP', 'COMPLETED', 'COMPLETE', 'DONE'].includes(statusCode) || /COMPLET|DONE|PAID|APPROVED|DELIVERED/.test(statusName)) return 'done';
        if (['REV', 'REVIEW', 'REVISION'].includes(statusCode) || /REVIEW|REVISION|REJECT|RETURN/.test(statusName)) return 'review';
        if (['BLK', 'BLOCKED', 'ATTN', 'ATTENTION'].includes(statusCode) || /BLOCK|ATTENTION/.test(statusName)) return 'review';
        if (['WC', 'WS', 'WP', 'WIA', 'WAIT', 'WAITING', 'HOLD'].includes(statusCode) || /WAIT|HOLD|CLIENT|SUPPLIER|PAYMENT|EXTERNAL|APPROVAL/.test(statusName)) return 'waiting';
        if (['IP', 'IN_PROGRESS', 'PROGRESS'].includes(statusCode) || /IN PROGRESS|PROGRESS|STARTED|TRANSIT/.test(statusName)) return 'progress';
        return 'ready';
    }

    function boardColumnDefinitions() {
        const statuses = boardActiveTaskStatuses();
        if (statuses.length) {
            return statuses.map((status, index) => ({
                key: boardColumnKeyForStatus(status),
                label: status.name || status.code || `Status ${index + 1}`,
                statusId: status.id ?? null,
                statusRecord: status,
                code: status.code || null,
                bucket: boardTaskStatusBucket({ statusId: status.id, status: status.name, statusCode: status.code }),
                configured: true,
            }));
        }
        return [
            { key: 'ready', label: 'Ready', target: ['READY', 'NOT STARTED', 'NEW'], bucket: 'ready', configured: false },
            { key: 'progress', label: 'In Progress', target: ['IP', 'IN PROGRESS', 'PROGRESS'], bucket: 'progress', configured: false },
            { key: 'waiting', label: 'Waiting', target: ['WS', 'WC', 'WAITING FOR SUPPLIER', 'WAITING FOR CLIENT', 'WAITING'], bucket: 'waiting', configured: false },
            { key: 'review', label: 'Review', target: ['REV', 'REVISION REQUIRED', 'REVIEW', 'BLK', 'BLOCKED'], bucket: 'review', configured: false },
            { key: 'done', label: 'Done', target: ['CMP', 'COMPLETED', 'DONE'], bucket: 'done', configured: false },
        ];
    }

    function boardTaskColumnKey(task) {
        const columns = boardColumnDefinitions();
        const statusId = Number(task.statusId);
        if (Number.isFinite(statusId) && statusId > 0) {
            const byId = columns.find((column) => column.statusId !== undefined && column.statusId !== null && Number(column.statusId) === statusId);
            if (byId) return byId.key;
        }
        const statusRecord = taskStatusRecord(task.statusId || { code: task.statusCode, name: task.status });
        if (statusRecord) {
            const key = boardColumnKeyForStatus(statusRecord);
            if (columns.some((column) => column.key === key)) return key;
        }
        const normalizedName = normalizedStatusValue(task.status || '');
        const normalizedCode = normalizedStatusValue(task.statusCode || '');
        const byNameOrCode = columns.find((column) => normalizedStatusValue(column.label) === normalizedName || normalizedStatusValue(column.code) === normalizedCode || normalizedStatusValue(column.code) === normalizedName);
        if (byNameOrCode) return byNameOrCode.key;
        const bucket = boardTaskStatusBucket(task);
        const fallback = columns.find((column) => column.bucket === bucket) || columns[0];
        return fallback?.key || bucket;
    }

    function boardTargetStatusForColumn(columnKey) {
        const column = boardColumnDefinitions().find((item) => item.key === columnKey);
        if (!column) return null;
        if (column.statusRecord) return column.statusRecord;
        const statuses = boardActiveTaskStatuses();
        for (const wanted of (column.target || [])) {
            const normalizedWanted = normalizedStatusValue(wanted);
            const found = statuses.find((status) => String(status.code || '').toUpperCase() === String(wanted).toUpperCase()
                || normalizedStatusValue(status.name || '') === normalizedWanted
                || normalizedStatusValue(status.code || '') === normalizedWanted);
            if (found) return found;
        }
        return statuses[0] || null;
    }

    function boardPriorityClass(priority) {
        const normalized = String(priority || 'Medium').toLowerCase();
        if (normalized.includes('critical')) return 'critical';
        if (normalized.includes('high')) return 'high';
        if (normalized.includes('low')) return 'low';
        return 'medium';
    }

    function boardWaitingLabel(task) {
        const status = normalizedStatusValue(task.status || '');
        if (status.includes('SUPPLIER')) return 'Supplier costing';
        if (status.includes('CLIENT')) return 'Client approval';
        if (status.includes('PAYMENT')) return 'Client payment';
        if (status.includes('HOLD')) return 'External response';
        return task.blockerReason || task.title || 'External dependency';
    }

    function boardTaskUpdatedText(task) {
        const key = boardDateOnly(task.updatedAt || task.completedAt || task.createdAt);
        if (!key) return 'Updated recently';
        const days = boardDaysUntil(key);
        if (days === 0) return 'Updated today';
        if (days === -1) return 'Updated 1d ago';
        if (days < 0) return `Updated ${Math.abs(days)}d ago`;
        return `Updated ${boardShortDate(key)}`;
    }

    function boardChecklistCount(task) {
        if (task.isDocumentTask) {
            const uploaded = (task.documents || []).length;
            return `${uploaded ? 1 : 0}/1`;
        }
        const checklist = Array.isArray(task.checklist) ? task.checklist : [];
        if (!checklist.length) return '0/0';
        return `${checklist.filter((item) => item.done).length}/${checklist.length}`;
    }

    function boardAttachmentCount(task) {
        const explicit = Array.isArray(task.documents) ? task.documents.length : 0;
        if (explicit) return explicit;
        return docs.filter((doc) => String(doc.job) === String(task.job)).length;
    }

    function boardCommentCount(task) {
        const comments = Array.isArray(task.comments) ? task.comments.length : 0;
        const historyComments = Array.isArray(task.history) ? task.history.filter((entry) => entry.commentAdded).length : 0;
        return comments + historyComments;
    }

    function boardFilteredTasks() {
        let list = tasks.slice();
        const search = boardSearchValue.trim().toLowerCase();
        if (search) {
            list = list.filter((task) => {
                const job = boardJobForTask(task);
                const haystack = [task.id, task.title, task.job, task.phase, task.assignee, task.priority, task.status, job?.title, clientName(job?.client)].join(' ').toLowerCase();
                return haystack.includes(search);
            });
        }
        if (boardFilters.job) list = list.filter((task) => String(task.job) === String(boardFilters.job));
        if (boardFilters.client) list = list.filter((task) => String(boardJobForTask(task)?.client) === String(boardFilters.client));
        if (boardFilters.assignee) list = list.filter((task) => {
            const selected = String(boardFilters.assignee);
            const selectedUser = boardAssigneeFilterUsers().find((user) => String(user.id || '') === selected || String(user.name || '') === selected)
                || users.find((user) => String(user.id || '') === selected || String(user.name || '') === selected);
            const selectedName = selectedUser ? String(selectedUser.name || '') : selected;
            return boardTaskMatchesAssignee(task, selected, selectedName);
        });
        if (boardFilters.status) list = list.filter((task) => boardTaskColumnKey(task) === boardFilters.status || String(task.statusId) === String(boardFilters.status));
        if (boardFilters.priority) list = list.filter((task) => String(task.priorityId || task.priority) === String(boardFilters.priority));
        if (boardFilters.due === 'overdue') list = list.filter((task) => boardDaysOverdue(task.due) > 0 && boardTaskStatusBucket(task) !== 'done');
        if (boardFilters.due === 'today') list = list.filter((task) => boardDateOnly(task.due) === today);
        if (boardFilters.due === 'week') list = list.filter((task) => {
            const days = boardDaysUntil(task.due);
            return days !== null && days >= 0 && days <= 7;
        });
        if (boardFilters.more === 'document') list = list.filter((task) => task.isDocumentTask);
        if (boardFilters.more === 'attention') list = list.filter((task) => task.attention || task.blockerReason);
        if (boardQuickFilter === 'mine') list = list.filter((task) => boardCurrentUserTask(task));
        if (boardQuickFilter === 'overdue') list = list.filter((task) => boardDaysOverdue(task.due) > 0 && boardTaskStatusBucket(task) !== 'done');
        if (boardQuickFilter === 'week') list = list.filter((task) => {
            const days = boardDaysUntil(task.due);
            return days !== null && days >= 0 && days <= 7;
        });
        if (boardQuickFilter === 'blocked') list = list.filter((task) => /BLOCK|ATTENTION|HOLD/.test(normalizedStatusValue(task.status || '')) || task.attention || task.blockerReason);
        if (boardQuickFilter === 'waiting') list = list.filter((task) => boardTaskStatusBucket(task) === 'waiting');
        if (boardQuickFilter === 'unassigned') list = list.filter((task) => !task.assigneeId && (!task.assignee || String(task.assignee).toLowerCase() === 'unassigned'));
        return list;
    }

    function boardFilteredJobsForPhases() {
        let list = boardActiveJobs();
        const search = boardSearchValue.trim().toLowerCase();
        if (search) {
            list = list.filter((job) => [job.id, job.order, job.title, clientName(job.client), job.product, job.coordinator, job.owner, job.health, job.status].join(' ').toLowerCase().includes(search));
        }
        if (boardFilters.job) list = list.filter((job) => String(job.id) === String(boardFilters.job));
        if (boardFilters.client) list = list.filter((job) => String(job.client) === String(boardFilters.client));
        if (boardFilters.assignee) list = list.filter((job) => {
            const selected = String(boardFilters.assignee);
            const selectedUser = boardAssigneeFilterUsers().find((user) => String(user.id || '') === selected || String(user.name || '') === selected)
                || users.find((user) => String(user.id || '') === selected || String(user.name || '') === selected);
            const selectedName = selectedUser ? String(selectedUser.name || '') : selected;
            return boardJobMatchesAssignee(job, selected, selectedName);
        });
        if (boardFilters.priority) list = list.filter((job) => String(job.priorityId || job.priority) === String(boardFilters.priority));
        if (boardFilters.due === 'overdue') list = list.filter((job) => boardDaysOverdue(job.delivery) > 0);
        if (boardFilters.due === 'today') list = list.filter((job) => boardDateOnly(job.delivery) === today);
        if (boardFilters.due === 'week') list = list.filter((job) => {
            const days = boardDaysUntil(job.delivery);
            return days !== null && days >= 0 && days <= 7;
        });
        if (boardFilters.more === 'attention') list = list.filter((job) => job.attention || /RISK|BLOCK|DELAY|ATTENTION/.test(normalizedStatusValue(job.health || '')));
        if (boardQuickFilter === 'mine') list = list.filter((job) => boardCurrentUserJob(job));
        if (boardQuickFilter === 'overdue') list = list.filter((job) => boardDaysOverdue(job.delivery) > 0);
        if (boardQuickFilter === 'week') list = list.filter((job) => {
            const days = boardDaysUntil(job.delivery);
            return days !== null && days >= 0 && days <= 7;
        });
        if (boardQuickFilter === 'blocked') list = list.filter((job) => /BLOCK|RISK|DELAY|ATTENTION/.test(normalizedStatusValue(job.health || '')) || job.attention);
        if (boardQuickFilter === 'waiting') list = list.filter((job) => /WAIT/.test(normalizedStatusValue(job.status || '')));
        if (boardQuickFilter === 'unassigned') list = list.filter((job) => !job.coordinator && !job.owner);
        return list;
    }

    function boardColumnCounts(taskList) {
        return boardColumnDefinitions().reduce((acc, column) => {
            acc[column.key] = taskList.filter((task) => boardTaskColumnKey(task) === column.key).length;
            return acc;
        }, {});
    }

    function boardSelectOptions(items, getValue, getLabel, selected, allLabel) {
        const seen = new Set();
        const options = [`<option value="">${html(allLabel)}</option>`];
        items.forEach((item) => {
            const value = getValue(item);
            if (value === undefined || value === null || value === '' || seen.has(String(value))) return;
            seen.add(String(value));
            options.push(`<option value="${html(value)}" ${String(selected) === String(value) ? 'selected' : ''}>${html(getLabel(item))}</option>`);
        });
        return options.join('');
    }

    function boardCurrentUserOnlyOption() {
        if (!currentUser?.id && !currentUser?.name) return [];
        return [{
            id: currentUser.id || currentUser.name,
            name: currentUser.name || 'Me',
            initials: currentUser.initials || userInitials(currentUser.name || 'Me'),
            role: currentUser.role || '',
            dept: currentUser.department || currentUser.dept || '',
        }];
    }

    function boardAssigneeFilterUsers() {
        const canSeeEveryAssignee = Boolean(isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin);
        let source = boardCurrentUserOnlyOption();
        if (canSeeEveryAssignee) {
            const derivedFromTasks = flowtrackToArray(tasks).map((task) => {
                const id = flowtrackResolveTaskAssigneeId(task) || task.assigneeId || task.setupAssigneeId || '';
                const name = flowtrackResolveTaskAssigneeName(task, id) || task.assignee || '';
                return { id: id || name, name, initials: userInitials(name) };
            });
            const derivedFromJobs = flowtrackToArray(jobs).flatMap((job) => ([
                { id: job.ownerId || job.owner, name: job.owner, initials: userInitials(job.owner || '') },
                { id: job.coordinatorId || job.coordinator, name: job.coordinator, initials: userInitials(job.coordinator || '') },
            ]));
            source = []
                .concat(flowtrackToArray(boardAssigneeUsers))
                .concat(flowtrackToArray(users))
                .concat(typeof flowtrackWorkspaceMembers === 'function' ? flowtrackWorkspaceMembers() : [])
                .concat(derivedFromTasks)
                .concat(derivedFromJobs)
                .concat(boardCurrentUserOnlyOption());
        }
        const seen = new Set();
        return source.filter((user) => {
            const name = String(user?.name || '').trim();
            const key = String(user?.id || name || '').trim();
            if (!name || name.toLowerCase() === 'unassigned' || !key || seen.has(key)) return false;
            seen.add(key);
            return true;
        }).sort((left, right) => {
            if (Number(left.id || 0) === Number(currentUser?.id || 0)) return -1;
            if (Number(right.id || 0) === Number(currentUser?.id || 0)) return 1;
            return String(left.name || '').localeCompare(String(right.name || ''));
        });
    }

    function boardTaskMatchesAssignee(task, selectedId, selectedName) {
        const resolvedId = flowtrackResolveTaskAssigneeId(task);
        const resolvedName = flowtrackResolveTaskAssigneeName(task, resolvedId);
        return (selectedId && String(resolvedId || task.assigneeId || '') === String(selectedId))
            || (selectedName && String(resolvedName || task.assignee || '').trim().toLowerCase() === String(selectedName).trim().toLowerCase());
    }

    function boardJobMatchesAssignee(job, selectedId, selectedName) {
        const normalizedName = String(selectedName || '').trim().toLowerCase();
        const selectedIdText = String(selectedId || '').trim();
        const directIdMatch = selectedIdText && [job.coordinatorId, job.ownerId, job.createdById]
            .some((id) => String(id || '').trim() === selectedIdText);
        const directNameMatch = normalizedName && [job.coordinator, job.owner, job.createdBy]
            .some((name) => String(name || '').trim().toLowerCase() === normalizedName);
        if (directIdMatch || directNameMatch) return true;

        const memberIds = flowtrackToArray(job.memberIds || job.member_ids).map((id) => String(id || '').trim());
        const memberRecords = flowtrackToArray(job.members || job.team);
        const memberIdMatch = selectedIdText && (memberIds.includes(selectedIdText)
            || memberRecords.some((member) => String(member?.id || member?.userId || member?.user_id || '').trim() === selectedIdText));
        const memberNameMatch = normalizedName && memberRecords.some((member) => String(member?.name || member?.userName || member?.user_name || member || '').trim().toLowerCase() === normalizedName);
        if (memberIdMatch || memberNameMatch) return true;

        return tasks.some((task) => (String(task.job) === String(job.id) || Number(task.jobDbId) === Number(job.dbId))
            && boardTaskMatchesAssignee(task, selectedId, selectedName));
    }

    function boardCurrentUserTask(task) {
        return boardTaskMatchesAssignee(task, currentUser?.id, currentUser?.name);
    }

    function boardCurrentUserJob(job) {
        return boardJobMatchesAssignee(job, currentUser?.id, currentUser?.name);
    }

    function boardVisibleJobIdsForExpandToggle() {
        if (boardMode === 'tasks') {
            const visibleTasks = boardFilteredTasks();
            const ids = [];
            visibleTasks.forEach((task) => {
                const job = boardJobForTask(task);
                const id = String(job?.id || task.job || '').trim();
                if (id && !ids.includes(id)) ids.push(id);
            });
            return ids;
        }
        return boardFilteredJobsForPhases().map((job) => String(job.id || '').trim()).filter(Boolean);
    }

    function boardAllVisibleJobsExpanded() {
        const ids = boardVisibleJobIdsForExpandToggle();
        if (!ids.length) return false;
        if (boardMode === 'tasks') return ids.every((id) => !collapsedBoardJobs.has(id));
        return ids.every((id) => expandedBoardJobCards.has(id));
    }

    function boardClientFilterRecords() {
        const records = [];
        const seen = new Set();
        flowtrackToArray(clients).concat(flowtrackToArray(clientLabels)).forEach((client) => {
            const id = String(client?.id || '').trim();
            if (!id || seen.has(id)) return;
            seen.add(id);
            records.push(client);
        });
        return records;
    }

    function boardBulkToggleButton(id, extraClass = '') {
        const expanded = boardAllVisibleJobsExpanded();
        const label = expanded ? 'Collapse all jobs' : 'Expand all jobs';
        const icon = expanded
            ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 17 5-5 5 5"/><path d="m7 12 5-5 5 5"/></svg>'
            : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 7 5 5 5-5"/><path d="m7 12 5 5 5-5"/></svg>';
        return `<button type="button" class="ops-clear ops-expand-toggle flow-bulk-icon-btn ${extraClass}" id="${id}" title="${label}" aria-label="${label}">${icon}</button>`;
    }

    function operationsBoardToolbar(filteredCount, filteredJobCount) {
        const activeJobs = boardActiveJobs();
        const activeTasks = flowtrackVisibleTaskRows(tasks).filter((task) => boardActiveJobs().some((job) => String(job.id) === String(task.job)));
        const jobOptions = boardSelectOptions(activeJobs, (job) => job.id, (job) => `${job.id} · ${clientName(job.client)}`, boardFilters.job, 'Job');
        const activeClientIds = new Set(activeJobs.map((job) => String(job?.client || '')).filter(Boolean));
        const clientOptions = boardSelectOptions(
            boardClientFilterRecords().filter((client) => activeClientIds.has(String(client?.id || ''))),
            (client) => client.id,
            (client) => client.name,
            boardFilters.client,
            'Client',
        );
        const userOptions = boardSelectOptions(boardAssigneeFilterUsers(), (user) => user.id || user.name, (user) => user.name, boardFilters.assignee, 'Assignee');
        const quickMineLabel = boardMode === 'tasks' ? 'My task' : 'My job';
        const quickCounts = {
            mine: boardMode === 'tasks'
                ? activeTasks.filter((task) => boardCurrentUserTask(task)).length
                : activeJobs.filter((job) => boardCurrentUserJob(job)).length,
            overdue: activeTasks.filter((task) => boardDaysOverdue(task.due) > 0 && boardTaskStatusBucket(task) !== 'done').length,
            week: activeTasks.filter((task) => { const days = boardDaysUntil(task.due); return days !== null && days >= 0 && days <= 7; }).length,
            blocked: activeTasks.filter((task) => /BLOCK|ATTENTION|HOLD/.test(normalizedStatusValue(task.status || '')) || task.attention || task.blockerReason).length,
            waiting: activeTasks.filter((task) => boardTaskStatusBucket(task) === 'waiting').length,
            unassigned: activeTasks.filter((task) => !task.assigneeId && (!task.assignee || String(task.assignee).toLowerCase() === 'unassigned')).length,
        };
        return `<div class="ops-toolbar">
            <label class="ops-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg><input id="opsBoardSearch" value="${html(boardSearchValue)}" placeholder="Search job ID, task or client"></label>
            <select class="ops-filter" data-board-filter="job">${jobOptions}</select>
            <select class="ops-filter" data-board-filter="client">${clientOptions}</select>
            <select class="ops-filter" data-board-filter="assignee">${userOptions}</select>
            <select class="ops-filter" data-board-filter="status"><option value="">Status</option>${boardColumnDefinitions().map((column) => `<option value="${column.key}" ${boardFilters.status === column.key ? 'selected' : ''}>${column.label}</option>`).join('')}</select>
            <select class="ops-filter" data-board-filter="due"><option value="" ${!boardFilters.due ? 'selected' : ''}>Due date</option><option value="overdue" ${boardFilters.due === 'overdue' ? 'selected' : ''}>Overdue</option><option value="today" ${boardFilters.due === 'today' ? 'selected' : ''}>Due today</option><option value="week" ${boardFilters.due === 'week' ? 'selected' : ''}>Due this week</option></select>
            <button type="button" class="ops-clear" id="opsBoardClear">Clear</button>
        </div>
        <div class="ops-quick-row"><div class="ops-quick-left"><span class="ops-quick-label">Quick filters</span>${[
            ['mine', quickMineLabel, quickCounts.mine, ''],
            ['overdue', 'Overdue', quickCounts.overdue, 'danger'],
            ['week', 'Due this week', quickCounts.week, ''],
            ['blocked', 'Blocked', quickCounts.blocked, 'danger'],
            ['waiting', 'Waiting external', quickCounts.waiting, ''],
            ['unassigned', 'Unassigned', quickCounts.unassigned, 'warn'],
        ].map(([key, label, count, tone]) => `<button type="button" class="ops-chip ${tone} ${boardQuickFilter === key ? 'active' : ''}" data-board-quick="${key}">${label} <span class="ops-count">${count}</span></button>`).join('')}</div><div class="ops-quick-right">${boardBulkToggleButton('opsBoardExpandAll')}</div></div>
        <div class="ops-status-summary"><span>Showing <b>${filteredCount}</b> ${boardMode === 'tasks' ? 'of ' + flowtrackVisibleTaskRows(tasks).length + ' tasks' : 'Jobs'} across <b>${filteredJobCount}</b> ${filteredJobCount === 1 ? 'job' : 'jobs'}</span></div>`;
    }

    function boardTaskInvoiceBadgeHtml(task, job = null) {
        const requirement = task?.documentRequirement || task?.document_requirement || null;
        const categoryText = [
            requirement?.categoryName,
            requirement?.category_name,
            requirement?.name,
            requirement?.code,
            task?.documentCategoryName,
            task?.document_category_name,
            task?.documentCategoryCode,
            task?.document_category_code,
        ].join(' ');
        const phaseText = [
            task?.phase,
            task?.phaseName,
            task?.phaseShortName,
            job ? phaseNameFor(job) : '',
            job ? phaseShortFor(job) : '',
        ].join(' ');
        const taskPackText = [task?.taskPackName, task?.taskPackItemTitle].join(' ');
        const isInvoiceDocument = /(^|\b)(INV|INVOICE|PAYMENT)(\b|$)/.test(normalizedStatusValue(categoryText));
        const isInvoicePhase = /(^|\b)(INV|INVOICE|PAYMENT)(\b|$)/.test(normalizedStatusValue(phaseText));
        const isInvoicePack = /(^|\b)(INV|INVOICE|PAYMENT)(\b|$)/.test(normalizedStatusValue(taskPackText));
        if (!isInvoiceDocument && !isInvoicePhase && !isInvoicePack) return '';
        return '<span class="ops-task-doc-badge ops-task-invoice-badge" title="Invoice phase/task">Invoice</span>';
    }

    function boardTaskPhaseHtml(task, job = null) {
        const phaseLabel = task?.phase || task?.phaseName || phaseNameFor(job || {}, job?.phase) || 'General';
        return `<div class="ops-card-phase"><span>${html(phaseLabel)}</span>${boardTaskInvoiceBadgeHtml(task, job)}</div>`;
    }

    function boardTaskCardHtml(task) {
        const job = boardJobForTask(task);
        const overdueDays = boardTaskStatusBucket(task) !== 'done' ? boardDaysOverdue(task.due) : 0;
        const attachments = boardAttachmentCount(task);
        const comments = boardCommentCount(task);
        const canDrag = task.canUpdate || isFlowAdmin || isFlowSuperAdmin;
        const waiting = boardTaskStatusBucket(task) === 'waiting' || task.blockerReason;
        return `<article class="ops-task-card" draggable="${canDrag ? 'true' : 'false'}" data-task="${html(task.id)}" data-task-updatable="${canDrag ? '1' : '0'}">
            <div class="ops-task-card-top"><span class="ops-priority" style="${flowtrackMasterToneStyle(flowtrackPriorityColor(task, task.priorityColor || ''))}">${html(task.priority || 'Medium')}</span><span class="ops-task-alerts">${overdueDays ? `<span class="ops-overdue">Overdue · ${overdueDays}d</span>` : ''}<span class="ops-board-card-menu-wrap"><button type="button" class="ops-task-menu" data-board-card-menu="${html(task.id)}" aria-label="Task actions" aria-expanded="false">⋮</button><span class="ops-board-card-action-menu hidden">${task.canDelete || isFlowAdmin || isFlowSuperAdmin ? `<button type="button" data-board-delete-task="${html(task.dbId)}" data-board-delete-task-code="${html(task.id)}">Delete Task</button>` : '<span class="ops-menu-disabled">No available actions</span>'}</span></span></span></div>
            <h4 class="ops-card-title">${html(task.title)}</h4>
            <div class="ops-card-meta"><span class="flow-id-link-row"><button type="button" class="job-link" data-board-job-link="${html(task.job)}">${html(task.job)}</button>${flowtrackCopyIdentifierButton(task.job, 'Job ID', 'flow-id-copy-icon-only')}</span> <span>· ${html(clientName(job?.client))}</span>${boardTaskPhaseHtml(task, job)}</div>
            ${waiting ? `<div class="ops-waiting-box"><span class="ops-wait-icon">🔗</span><span><div class="ops-wait-title">Waiting on: ${html(boardWaitingLabel(task))}</div><div class="ops-wait-date">Since ${boardShortDate(task.updatedAt || task.createdAt || task.due)}</div></span></div>` : ''}
            <div class="ops-card-sep"></div>
            <div class="ops-assignee-row"><div class="ops-assignee"><span class="avatar">${userInitials(task.assignee || 'Unassigned')}</span><span class="truncate">${html(task.assignee || 'Unassigned')}</span></div>${boardTaskDueHtml(task, overdueDays)}</div>
            ${taskIsCompleted(task) ? boardCompletedMetaHtml(task, 'Completed') : ''}
            <div class="ops-card-sep"></div>
            <div class="ops-card-footer"><div class="ops-card-stats"><span class="ops-stat">☑ ${html(boardChecklistCount(task))}</span><span class="ops-stat">📎 ${attachments}</span><span class="ops-stat">◯ ${comments}</span></div><span class="ops-updated">${html(boardTaskUpdatedText(task))}</span></div>
        </article>`;
    }

    function renderOperationsTaskBoard(taskList, explicitJobList = null) {
        const columns = boardColumnDefinitions();
        const counts = boardColumnCounts(taskList);
        const jobsWithTasks = [];
        const seen = new Set();
        const scopedJobs = Array.isArray(explicitJobList) ? explicitJobList : null;
        if (scopedJobs) {
            scopedJobs.forEach((job) => {
                const key = String(job?.id || `db:${Number(job?.dbId || 0)}`);
                if (!key || seen.has(key)) return;
                seen.add(key);
                jobsWithTasks.push(job);
            });
        } else taskList.forEach((task) => {
            const job = boardJobForTask(task);
            const key = String(job?.id || task.job);
            if (!seen.has(key)) {
                seen.add(key);
                jobsWithTasks.push(job || { id: task.job, title: task.jobTitle || task.job, client: null, delivery: task.due, coordinator: task.assignee });
            }
        });
        jobsWithTasks.sort((a, b) => String(a.delivery || '').localeCompare(String(b.delivery || '')) || String(a.id).localeCompare(String(b.id)));
        const header = `<div class="ops-status-grid">${columns.map((column) => {
            const color = flowtrackTaskStatusColor(column.statusRecord || column.label);
            return `<div class="ops-status-head" style="${flowtrackMasterToneStyle(color, { backgroundAlpha: 0.055, borderAlpha: 0.22 })}"><span>${html(column.label)}</span><span class="count" style="${flowtrackMasterToneStyle(color, { backgroundAlpha: 0.12, borderAlpha: 0.18 })}">${counts[column.key] || 0}</span>${['progress','review'].includes(column.bucket || column.key) && (counts[column.key] || 0) ? '<span class="warn-mark">⚠</span>' : ''}</div>`;
        }).join('')}</div>`;
        const body = jobsWithTasks.map((job) => {
            const jobTasks = taskList.filter((task) => String(task.job) === String(job.id) || Number(task.jobDbId) === Number(job.dbId));
            const completedCount = jobTasks.filter((task) => boardTaskStatusBucket(task) === 'done').length;
            const totalCount = flowtrackVisibleTaskRows(tasks).filter((task) => String(task.job) === String(job.id) || Number(task.jobDbId) === Number(job.dbId)).length || jobTasks.length;
            const collapsed = collapsedBoardJobs.has(String(job.id));
            return `<section class="ops-job-group" data-board-job-group="${html(job.id)}"><div class="ops-job-row-head"><div class="ops-job-left"><button type="button" class="ops-collapse" data-board-collapse="${html(job.id)}">${collapsed ? '›' : '⌄'}</button><span class="ops-job-title"><span class="flow-id-link-row"><button type="button" data-board-job-link="${html(job.id)}">${html(job.id)}</button>${flowtrackCopyIdentifierButton(job.id, 'Job ID', 'flow-id-copy-icon-only')}</span> · ${html(clientName(job.client))}</span><span class="ops-client-pill">${html(clientName(job.client))}</span></div><div class="ops-job-right"><span>${completedCount} of ${totalCount} tasks</span><span>▣ ${boardShortDate(job.delivery)}</span><button type="button" class="ops-job-kebab" data-board-row-menu>⋮</button></div></div>${collapsed ? '' : `<div class="ops-task-row">${columns.map((column) => {
                const columnTasks = jobTasks.filter((task) => boardTaskColumnKey(task) === column.key);
                return `<div class="ops-task-cell kanban-list ${columnTasks.length ? '' : 'is-empty'}" data-drop-column="${column.key}" data-column-label="${html(column.label)}"><div class="ops-mobile-column-head"><span>${html(column.label)}</span><span class="count">${columnTasks.length}</span></div>${columnTasks.map(boardTaskCardHtml).join('') || '<div class="ops-job-empty">No tasks</div>'}</div>`;
            }).join('')}</div>`}</section>`;
        }).join('');
        const minWidth = Math.max(900, columns.length * 190);
        return `<div class="ops-board-mobile-note">Scroll horizontally to see all board columns.</div><div class="ops-board-canvas" style="--task-status-cols:${columns.length};--task-board-min:${minWidth}px">${header}${body || '<div class="ops-board-empty">No task cards match the selected board filters.</div>'}</div>`;
    }

    function boardJobAllTasks(job) {
        return flowtrackVisibleTaskRows(tasks).filter((task) => String(task.job) === String(job.id) || Number(task.jobDbId) === Number(job.dbId));
    }

    function boardJobPhaseTasks(job) {
        const allTasks = boardJobAllTasks(job);
        const phaseId = Number(job.phaseId || 0);
        const phaseIndex = Number(job.phase || 0);
        const currentPhaseName = normalizedStatusValue(phaseNameFor(job) || '');
        const currentPhaseShort = normalizedStatusValue(phaseShortFor(job) || '');
        const matched = allTasks.filter((task) => {
            if (phaseId && Number(task.phaseId) === phaseId) return true;
            if (Number.isFinite(phaseIndex) && Number(task.phaseIndex) === phaseIndex) return true;
            const taskPhase = normalizedStatusValue(task.phase || '');
            return Boolean(taskPhase && (taskPhase === currentPhaseName || taskPhase === currentPhaseShort || currentPhaseName.includes(taskPhase) || taskPhase.includes(currentPhaseShort)));
        });
        return matched.length ? matched : allTasks.filter((task) => !taskIsCompleted(task));
    }

    function boardJobHealthTone(job) {
        const normalized = normalizedStatusValue(job.health || job.status || 'On Track');
        if (/BLOCK/.test(normalized)) return 'blocked';
        if (/RISK|ATTENTION/.test(normalized)) return 'risk';
        if (/DELAY|OVERDUE/.test(normalized)) return 'delayed';
        if (/WAIT|HOLD/.test(normalized)) return 'waiting';
        if (/COMPLET|DONE|PAID/.test(normalized)) return 'completed';
        return 'track';
    }

    function boardJobDaysInPhase(job) {
        const key = boardDateOnly(job.phaseStartedAt || job.phase_started_at || job.updatedAt || job.createdAt);
        if (!key) return '—';
        const days = Math.max(0, Math.round((new Date(`${today}T00:00:00`) - new Date(`${key}T00:00:00`)) / 86400000));
        return `${days || 1}d in phase`;
    }

    function boardCompletedDateTime(record) {
        const value = record?.completedAt || record?.completed_at || null;
        if (!value) return '';
        return flowtrackFormatDateTime(value);
    }

    function boardCompletedMetaHtml(record, label = 'Completed') {
        const completed = boardCompletedDateTime(record);
        if (!completed) return '';
        return `<div class="ops-completed-meta"><span>✓</span><span><b>${html(label)}</b> ${html(completed)}</span></div>`;
    }

    function boardJobUpdatedText(job) {
        const key = boardDateOnly(job.updatedAt || job.createdAt);
        if (!key) return 'Updated recently';
        const days = boardDaysUntil(key);
        if (days === 0) return `Updated today by ${html(job.coordinator || job.owner || 'FlowTrack')}`;
        if (days === -1) return `Updated 1d ago by ${html(job.coordinator || job.owner || 'FlowTrack')}`;
        if (days < 0) return `Updated ${Math.abs(days)}d ago by ${html(job.coordinator || job.owner || 'FlowTrack')}`;
        return `Updated ${boardShortDate(key)} by ${html(job.coordinator || job.owner || 'FlowTrack')}`;
    }

    function boardJobCommercialLabel(job) {
        if (!canSensitive('invoice_amount')) return 'Restricted';
        const phaseText = normalizedStatusValue(`${phaseNameFor(job)} ${phaseShortFor(job)} ${job.status || ''}`);
        if (Number(job.price || 0) > 0 && /PAID|COMPLETED/.test(phaseText)) return `Paid ${currency(job.price, job.currency)}`;
        if (Number(job.price || 0) > 0 && /INVOICE|PAYMENT|COLLECT/.test(phaseText)) return `Invoice ${currency(job.price, job.currency)}`;
        if (Number(job.price || 0) > 0) return `Draft ${currency(job.price, job.currency)}`;
        return 'Quotation pending';
    }

    function boardPhaseTaskStatusClass(task) {
        const key = boardTaskStatusBucket(task);
        if (key === 'done') return 'done';
        if (key === 'waiting') return 'waiting';
        if (key === 'review') return 'review';
        return 'ready';
    }

    function boardJobTeam(job, phaseTasks) {
        const names = [];
        [job.coordinator, job.owner].concat(flowtrackToArray(phaseTasks).map((task) => task.assignee)).forEach((name) => {
            const clean = String(name || '').trim();
            if (clean && clean.toLowerCase() !== 'unassigned' && !names.includes(clean)) names.push(clean);
        });
        return names.length ? names : ['Unassigned'];
    }

    function boardJobCardHtml(job) {
        const phaseTasks = boardJobPhaseTasks(job);
        const allJobTasks = boardJobAllTasks(job);
        const expanded = expandedBoardJobCards.has(String(job.id));
        const completedPhaseTasks = phaseTasks.filter(taskIsCompleted).length;
        const openPhaseTasks = phaseTasks.filter((task) => !taskIsCompleted(task));
        const nextTask = openPhaseTasks.slice().sort((left, right) => String(left.due || '').localeCompare(String(right.due || '')))[0] || phaseTasks[0] || null;
        const overdueJob = boardDaysOverdue(job.delivery);
        const dueSoon = phaseTasks.filter((task) => {
            const days = boardDaysUntil(task.due);
            return !taskIsCompleted(task) && days !== null && days >= 0 && days <= 7;
        }).length;
        const blockedCount = phaseTasks.filter((task) => taskIsBlocked(task) || /BLOCK|HOLD/.test(normalizedStatusValue(task.status || ''))).length;
        const productCount = Number(job.productCount || (Array.isArray(job.products) ? job.products.length : (job.product ? 1 : 0)) || 0);
        const unitCount = Number(job.qty || (Array.isArray(job.products) ? job.products.reduce((sum, item) => sum + Number(item.qty || item.quantity || 0), 0) : 0) || 0);
        const progress = Math.max(0, Math.min(100, Number(job.progress) || 0));
        const tone = boardJobHealthTone(job);
        const team = boardJobTeam(job, phaseTasks);
        const lead = job.coordinator || job.owner || team[0] || 'Unassigned';
        const phaseRows = phaseTasks.slice(0, 5).map((task, index) => `<div class="ops-phase-task-row" data-task="${html(task.id)}">
                <span>${index === 0 && !taskIsCompleted(task) ? '<span class="ops-task-next-pill">NEXT</span>' : ''}</span>
                <div><div class="ops-phase-task-title">${html(task.title)}</div><div class="ops-phase-task-person"><span class="avatar">${userInitials(task.assignee || 'Unassigned')}</span><span>${html(task.assignee || 'Unassigned')}</span></div></div>
                <span class="ops-task-status-pill" style="${flowtrackMasterToneStyle(flowtrackTaskStatusColor(task, task.statusColor || ''))}">${html(task.status || 'Ready')}</span>
                <span class="ops-task-date ${boardDaysOverdue(task.due) && !taskIsCompleted(task) ? 'due overdue' : ''}">${boardShortDate(task.due)}</span>
            </div>`).join('');
        const nextHtml = nextTask
            ? `<div class="ops-next-action"><div class="ops-card-section-label">Next Action</div><div class="ops-next-title">${html(nextTask.title || job.next || 'Review current phase')}</div><div class="ops-next-meta"><span class="avatar">${userInitials(nextTask.assignee || lead)}</span><span>${html(nextTask.assignee || lead)}</span><span class="ops-next-due">${flowtrackCalendarIconHtml()}<span>Due ${boardShortDate(nextTask.due || job.delivery)}</span></span></div></div>`
            : `<div class="ops-next-action"><div class="ops-card-section-label">Next Action</div><div class="ops-next-title">${html(job.next || 'Review current phase')}</div><div class="ops-next-meta"><span class="avatar">${userInitials(lead)}</span><span>${html(lead)}</span><span class="ops-next-due">${flowtrackCalendarIconHtml()}<span>Due ${boardShortDate(job.delivery)}</span></span></div></div>`;
        const phaseTasksHtml = `<div class="ops-phase-tasks-box"><div class="ops-phase-tasks-head"><span>Phase Tasks</span><b>${completedPhaseTasks} of ${phaseTasks.length} complete</b><span>⌃</span></div>${phaseRows || '<div class="ops-job-empty">No current phase tasks</div>'}</div>`;
        return `<article class="ops-phase-card ops-job-card health-${tone}" draggable="true" data-job="${html(job.id)}">
            <div class="ops-job-card-top"><span class="ops-health-pill ${tone}">${html(job.health || 'On Track')}</span><span class="ops-phase-age">◷ ${html(boardJobDaysInPhase(job))}</span><button type="button" class="ops-job-card-toggle" data-board-job-card-toggle="${html(job.id)}" aria-label="${expanded ? 'Show next action' : 'Show phase tasks'}">•••</button></div>
            <h4>${html(job.title)}</h4>
            <div class="ops-phase-meta"><span class="flow-id-link-row"><button type="button" class="kanban-job-link" data-board-job-link="${html(job.id)}">${html(job.id)}</button>${flowtrackCopyIdentifierButton(job.id, 'Job ID', 'flow-id-copy-icon-only')}</span> <span>· ${html(clientName(job.client))}</span></div>
            <div class="ops-product-row"><span class="cube">▧</span><span>${productCount || 1} product${(productCount || 1) === 1 ? '' : 's'} · ${unitCount.toLocaleString()} units</span></div>
            <div class="ops-job-progress-head"><span>Overall progress</span><b>${progress}%</b></div><div class="ops-progress"><span class="${progress >= 100 ? 'complete' : ''}" style="width:${progress}%"></span></div>
            <div class="ops-job-metrics"><div class="ops-job-metric">☷ ${openPhaseTasks.length} open</div><div class="ops-job-metric warn">◷ ${dueSoon} due soon</div><div class="ops-job-metric ok">✓ ${blockedCount} blocked</div></div>
            ${expanded ? phaseTasksHtml : nextHtml}
            <div class="ops-team-row"><span>Team</span><span class="ops-team-avatars">${team.slice(0, 3).map((name) => `<span class="avatar">${userInitials(name)}</span>`).join('')}${team.length > 3 ? `<span class="ops-team-more">+${team.length - 3}</span>` : ''}</span><span class="ops-lead">Lead: ${html(lead)}</span></div>
            <div class="ops-job-bottom"><div class="ops-bottom-item">${flowtrackCalendarIconHtml('ops-bottom-icon ops-due-calendar')}<span><div class="ops-bottom-label">Delivery</div><div class="ops-bottom-value ${overdueJob ? 'due overdue' : ''}">${boardShortDate(job.delivery)}</div></span></div><div class="ops-bottom-item"><span class="ops-bottom-icon">▤</span><span><div class="ops-bottom-label">Commercial</div><div class="ops-bottom-value">${html(boardJobCommercialLabel(job))}</div></span></div></div>
            ${jobIsCompleted(job) ? boardCompletedMetaHtml(job, 'Job completed') : ''}
            <div class="ops-job-card-updated">${boardJobUpdatedText(job)}</div>
        </article>`;
    }

    function renderOperationsJobBoard(jobList) {
        const activeWorkflows = (workflowTemplates || []).filter((workflow) => workflow.active !== false);
        const selectedWorkflow = activeWorkflows.find((workflow) => Number(workflow.id) === Number(boardWorkflowId))
            || activeWorkflows.find((workflow) => String(workflow.id) === String(boardWorkflowId))
            || activeWorkflows[0]
            || workflowTemplates[0];
        if (selectedWorkflow) boardWorkflowId = selectedWorkflow.id;
        const phases = (selectedWorkflow?.phases || []).filter((phase) => phase.active !== false).slice().sort((left, right) => Number(left.sequence || 0) - Number(right.sequence || 0));
        const workflowJobs = selectedWorkflow ? jobList.filter((job) => !job.workflow || String(job.workflow) === String(selectedWorkflow.id) || Number(job.workflow) === Number(selectedWorkflow.id)) : jobList;
        const workflowOptions = activeWorkflows.map((workflow) => `<option value="${html(workflow.id)}" ${String(workflow.id) === String(selectedWorkflow?.id) ? 'selected' : ''}>${html(workflow.name)}</option>`).join('');
        const board = `<div class="card" style="padding:12px;margin-bottom:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap"><b>Workflow</b><select id="boardWorkflowSelect" style="min-width:240px">${workflowOptions}</select><span class="small muted">Job cards show current phase progress, next action and expandable phase tasks.</span></div>
        <div class="ops-phase-kanban" style="--phase-cols:${Math.max(1, phases.length)}">${phases.map((phase, phaseIndex) => {
            const phaseId = phase.id ?? phase.phaseId ?? phase.dbId ?? phaseIndex;
            const phaseSequenceIndex = Number(phase.sequence ? phase.sequence - 1 : phaseIndex);
            const phaseJobs = workflowJobs.filter((job) => Number(job.phaseId) === Number(phaseId) || (!job.phaseId && Number(job.phase) === phaseSequenceIndex));
            return `<div class="ops-phase-column"><div class="kanban-head"><span>${html(phase.short || phase.shortName || phase.name)}</span><span class="count">${phaseJobs.length}</span></div><div class="kanban-list" data-drop-phase-id="${html(phaseId)}">${phaseJobs.map(boardJobCardHtml).join('') || '<div class="ops-job-empty">No Jobs</div>'}</div></div>`;
        }).join('') || '<div class="card" style="padding:18px">No active phases are configured for this workflow.</div>'}</div>`;
        setTimeout(() => {
            $('#boardWorkflowSelect')?.addEventListener('change', (event) => { boardWorkflowId = event.target.value; renderKanban(); });
        }, 0);
        return board;
    }

    function jobIsInactive(job) {
        return job?.active === false || ['inactive', 'deactivated', 'archived'].includes(String(job?.status || '').toLowerCase()) || ['inactive', 'deactivated', 'archived'].includes(String(job?.health || '').toLowerCase());
    }

    function jobListActiveJobs() {
        return jobs.filter((job) => !jobIsCompleted(job) && !jobIsInactive(job));
    }

    function jobListHasWaitingClient(job) {
        const jobText = normalizedStatusValue(`${job.health || ''} ${job.status || ''} ${job.next || ''}`);
        if (/WAIT.*CLIENT|CLIENT.*WAIT|CLIENT APPROVAL/.test(jobText)) return true;
        return boardJobAllTasks(job).some((task) => /CLIENT/.test(normalizedStatusValue(`${task.status || ''} ${task.title || ''} ${task.blockerReason || ''}`)) && !taskIsCompleted(task));
    }

    function jobListInvoiceState(job) {
        const phaseText = normalizedStatusValue(`${phaseNameFor(job)} ${phaseShortFor(job)} ${job.status || ''}`);
        if (!canSensitive('invoice_amount')) return { label: 'Restricted', tone: 'blue' };
        if (/PAID|COMPLETED/.test(phaseText)) return { label: 'Paid', tone: 'green' };
        if (boardDaysOverdue(job.delivery) > 0 && /INVOICE|PAYMENT|COLLECT/.test(phaseText)) return { label: `Overdue ${currency(Number(job.price || 0), job.currency)}`, tone: 'red' };
        if (/INVOICE|PAYMENT|COLLECT/.test(phaseText)) return { label: `Pending ${currency(Number(job.price || 0), job.currency)}`, tone: 'amber' };
        if (Number(job.price || 0) > 0) return { label: `Draft ${currency(Number(job.price || 0), job.currency)}`, tone: 'blue' };
        return { label: 'Quotation pending', tone: 'amber' };
    }

    function jobListNextTask(job) {
        const phaseTasks = boardJobPhaseTasks(job).filter((task) => !taskIsCompleted(task));
        return phaseTasks.slice().sort((left, right) => String(left.due || '').localeCompare(String(right.due || '')))[0] || null;
    }

    function jobListPhaseFilterValues(job) {
        const workflow = getWorkflow(Number(job?.workflow));
        const workflowPhase = (workflow?.phases || []).find((phase) => Number(phase.id) === Number(job?.phaseId));
        return [
            job?.phaseId,
            job?.phase,
            job?.status,
            workflowPhase?.name,
            workflowPhase?.short,
            workflowPhase?.shortName,
            phaseNameFor(job || {}),
            phaseShortFor(job || {}),
        ].map((value) => String(value ?? '').trim()).filter(Boolean);
    }

    function jobListPhaseMatchesFilter(job, filterValue) {
        const wantedRaw = String(filterValue ?? '').trim();
        if (!wantedRaw) return true;
        const wanted = normalizedStatusValue(wantedRaw);
        return jobListPhaseFilterValues(job).some((candidate) => String(candidate) === wantedRaw || normalizedStatusValue(candidate) === wanted);
    }

    function jobListFilteredJobs() {
        let list = jobs.slice();
        if (jobListFilters.saved !== 'completed') list = list.filter((job) => !jobIsInactive(job));
        const search = String(jobFilter.search || '').trim().toLowerCase();
        if (search) {
            list = list.filter((job) => [job.id, job.order, job.title, clientName(job.client), job.product, job.coordinator, job.owner, job.next].join(' ').toLowerCase().includes(search));
        }
        if (jobFilter.phase) list = list.filter((job) => jobListPhaseMatchesFilter(job, jobFilter.phase));
        if (jobFilter.health) list = list.filter((job) => String(job.health) === String(jobFilter.health) || normalizedStatusValue(job.health) === normalizedStatusValue(jobFilter.health));
        if (jobFilter.client) list = list.filter((job) => String(job.client) === String(jobFilter.client));
        if (jobListFilters.owner) list = list.filter((job) => String(job.ownerId || job.coordinatorId || job.owner || job.coordinator) === String(jobListFilters.owner));
        if (jobListFilters.delivery === 'overdue') list = list.filter((job) => boardDaysOverdue(job.delivery) > 0 && !jobIsCompleted(job));
        if (jobListFilters.delivery === 'week') list = list.filter((job) => { const days = boardDaysUntil(job.delivery); return days !== null && days >= 0 && days <= 7; });
        if (jobListFilters.invoice === 'unpaid') list = list.filter((job) => /PENDING|OVERDUE|INVOICE/.test(normalizedStatusValue(jobListInvoiceState(job).label)));
        if (jobListFilters.more === 'documents') list = list.filter((job) => jobDocumentRequirements(job).some((req) => !req.present));
        if (jobListFilters.saved === 'all') list = list.filter((job) => !jobIsCompleted(job));
        if (jobListFilters.saved === 'attention') list = list.filter((job) => job.attention || !['ON TRACK', 'COMPLETED'].includes(normalizedStatusValue(job.health || '')) || boardJobPhaseTasks(job).some(taskIsBlocked));
        if (jobListFilters.saved === 'week') list = list.filter((job) => { const days = boardDaysUntil(job.delivery); return !jobIsCompleted(job) && days !== null && days >= 0 && days <= 7; });
        if (jobListFilters.saved === 'waiting') list = list.filter(jobListHasWaitingClient);
        if (jobListFilters.saved === 'unpaid') list = list.filter((job) => /PENDING|OVERDUE|INVOICE/.test(normalizedStatusValue(jobListInvoiceState(job).label)));
        if (jobListFilters.saved === 'completed') list = list.filter(jobIsCompleted);
        return list.slice().sort((left, right) => {
            const createdComparison = String(right.createdAt || '').localeCompare(String(left.createdAt || ''));
            if (createdComparison) return createdComparison;
            const idComparison = Number(right.dbId || 0) - Number(left.dbId || 0);
            if (idComparison) return idComparison;
            return String(right.id || '').localeCompare(String(left.id || ''));
        });
    }

    function jobListPhaseOptions() {
        const options = new Map();
        const addPhaseOption = (label, fallbackValue = '') => {
            const cleanLabel = String(label ?? '').trim();
            if (!cleanLabel) return;
            const key = normalizedStatusValue(cleanLabel);
            if (!key || options.has(key)) return;
            options.set(key, { value: key, label: cleanLabel, fallbackValue });
        };

        (workflowTemplates || []).forEach((workflow) => (workflow.phases || []).forEach((phase) => {
            addPhaseOption(phase.name || phase.short || phase.shortName, phase.id ?? phase.phaseId ?? '');
        }));

        jobs.forEach((job) => {
            addPhaseOption(phaseNameFor(job), job.phaseId || job.phase || '');
            addPhaseOption(phaseShortFor(job), job.phaseId || job.phase || '');
        });

        if (!options.size && typeof phases !== 'undefined') phases.forEach((label, index) => addPhaseOption(label, index));

        const selected = String(jobFilter.phase || '').trim();
        const selectedNormalized = normalizedStatusValue(selected);
        return Array.from(options.values()).map((phase) => {
            const isSelected = selectedNormalized === normalizedStatusValue(phase.value)
                || selectedNormalized === normalizedStatusValue(phase.label)
                || String(phase.fallbackValue || '') === selected;
            return `<option value="${html(phase.value)}" ${isSelected ? 'selected' : ''}>${html(phase.label)}</option>`;
        }).join('');
    }

    function jobListOwnerOptions() {
        return users.map((user) => `<option value="${html(user.id || user.name)}" ${String(jobListFilters.owner) === String(user.id || user.name) ? 'selected' : ''}>${html(user.name)}</option>`).join('');
    }

    function resetJobListPage() {
        jobListPage = 1;
    }

    function jobListPageSizeOptions() {
        return [10, 20, 30, 40].map((size) => `<option value="${size}" ${Number(jobListPageSize) === size ? 'selected' : ''}>${size}</option>`).join('');
    }

    function jobListPaginationState(filteredJobs) {
        const total = filteredJobs.length;
        const safePageSize = [10, 20, 30, 40].includes(Number(jobListPageSize)) ? Number(jobListPageSize) : 10;
        if (safePageSize !== Number(jobListPageSize)) jobListPageSize = safePageSize;
        const pageCount = Math.max(1, Math.ceil(total / safePageSize));
        jobListPage = Math.min(Math.max(1, Number(jobListPage) || 1), pageCount);
        const start = total ? (jobListPage - 1) * safePageSize : 0;
        const end = Math.min(start + safePageSize, total);
        return { total, page: jobListPage, pageSize: safePageSize, pageCount, start, end, items: filteredJobs.slice(start, end) };
    }

    function jobListPaginationHtml(pagination) {
        const showingStart = pagination.total ? pagination.start + 1 : 0;
        const showingEnd = pagination.end;
        return `<div class="jobs-v2-pagination"><div class="jobs-v2-page-count">Showing <b>${showingStart}</b>–<b>${showingEnd}</b> of <b>${pagination.total}</b> Jobs</div><label class="jobs-v2-page-size">Show <select id="jobPageSize">${jobListPageSizeOptions()}</select> per page</label><div class="jobs-v2-page-buttons"><button type="button" id="jobPrevPage" ${pagination.page <= 1 ? 'disabled' : ''}>Previous</button><span>Page ${pagination.page} of ${pagination.pageCount}</span><button type="button" id="jobNextPage" ${pagination.page >= pagination.pageCount ? 'disabled' : ''}>Next</button></div></div>`;
    }

    function jobListTableColumnsHtml() {
        return '<colgroup><col class="jobs-v2-col-select"><col class="jobs-v2-col-code"><col class="jobs-v2-col-client"><col class="jobs-v2-col-product"><col class="jobs-v2-col-phase"><col class="jobs-v2-col-next"><col class="jobs-v2-col-health"><col class="jobs-v2-col-owner"><col class="jobs-v2-col-delivery"><col class="jobs-v2-col-progress"><col class="jobs-v2-col-invoice"><col class="jobs-v2-col-menu"></colgroup>';
    }

    function jobListOwnerName(job) {
        return job?.owner || job?.coordinator || 'Unassigned';
    }

    function jobListCanEditOwner() {
        return canEditJobRecord() && canFlow('jobs', 'assign');
    }

    function jobListCanEditDelivery() {
        return canEditJobRecord();
    }

    function jobListOwnerCell(job) {
        const ownerName = jobListOwnerName(job);
        const edit = jobListCanEditOwner()
            ? `<button type="button" class="jobs-v2-inline-edit" data-job-list-edit-owner="${html(job.id)}" aria-label="Edit owner" title="Edit owner">✎</button><span class="jobs-v2-inline-caret">⌄</span>`
            : '';
        return `<div class="jobs-v2-owner-cell"><div class="jobs-v2-owner"><span class="avatar">${userInitials(ownerName)}</span><span class="jobs-v2-owner-name">${html(ownerName)}</span></div>${edit}</div>`;
    }

    function jobListDeliveryCell(job, overdue = false) {
        const edit = jobListCanEditDelivery()
            ? `<button type="button" class="jobs-v2-inline-edit" data-job-list-edit-delivery="${html(job.id)}" aria-label="Edit delivery date" title="Edit delivery date">✎</button>`
            : '';
        return `<div class="jobs-v2-delivery-cell ${overdue ? 'overdue' : ''}">${flowtrackCalendarIconHtml('jobs-v2-calendar')}<span class="jobs-v2-delivery-label">${html(boardShortDate(job.delivery))}</span>${edit}</div>`;
    }

    function jobListCloseInlineEditors(except = null) {
        $$('.jobs-v2-inline-popover,.jobs-v2-delivery-editor').forEach((node) => {
            if (node !== except) node.remove();
        });
        $$('.jobs-v2-owner-cell.is-editing,.jobs-v2-delivery-cell.is-editing').forEach((node) => node.classList.remove('is-editing'));
    }

    function jobListOpenOwnerEditor(button, job) {
        const cell = button.closest('td');
        const ownerCell = button.closest('.jobs-v2-owner-cell');
        if (!cell || !ownerCell) return;
        const existing = cell.querySelector('.jobs-v2-inline-popover');
        if (existing) { existing.remove(); ownerCell.classList.remove('is-editing'); return; }
        jobListCloseInlineEditors();
        ownerCell.classList.add('is-editing');
        const members = (typeof flowtrackWorkspaceMembers === 'function' ? flowtrackWorkspaceMembers() : flowtrackToArray(users))
            .filter((member) => String(member.status || 'active').toLowerCase() !== 'inactive');
        const currentOwnerId = Number(job.ownerId || 0);
        const popup = document.createElement('div');
        popup.className = 'jobs-v2-inline-popover';
        popup.innerHTML = `<div class="jobs-v2-owner-search"><span>⌕</span><input type="search" placeholder="Search people" aria-label="Search people"></div><div class="jobs-v2-owner-options"><button type="button" class="jobs-v2-owner-option ${!currentOwnerId ? 'selected' : ''}" data-owner-id=""><span class="avatar jobs-v2-unassigned-avatar">♙</span><span>Unassigned</span>${!currentOwnerId ? '<b>✓</b>' : ''}</button>${members.map((member) => `<button type="button" class="jobs-v2-owner-option ${Number(member.id) === currentOwnerId ? 'selected' : ''}" data-owner-id="${html(member.id)}"><span class="avatar">${userInitials(member.name)}</span><span>${html(member.name)}</span>${Number(member.id) === currentOwnerId ? '<b>✓</b>' : ''}</button>`).join('')}</div>`;
        document.body.appendChild(popup);
        const anchorRect = ownerCell.getBoundingClientRect();
        const popupWidth = 190;
        popup.style.left = `${Math.max(8, Math.min(window.innerWidth - popupWidth - 8, anchorRect.left))}px`;
        popup.style.top = `${Math.min(window.innerHeight - 330, anchorRect.bottom + 6)}px`;
        const search = popup.querySelector('input');
        search?.addEventListener('input', () => {
            const term = String(search.value || '').trim().toLowerCase();
            popup.querySelectorAll('.jobs-v2-owner-option').forEach((option) => {
                option.hidden = term && !String(option.textContent || '').toLowerCase().includes(term);
            });
        });
        popup.querySelectorAll('[data-owner-id]').forEach((option) => option.addEventListener('click', async (event) => {
            event.preventDefault();
            event.stopPropagation();
            const ownerId = Number(option.dataset.ownerId || 0) || null;
            popup.classList.add('is-saving');
            popup.querySelectorAll('button,input').forEach((control) => { control.disabled = true; });
            try {
                await flowtrackJobV4SaveJob(job, { owner_id: ownerId }, 'Job owner updated.');
                renderJobs();
            } catch (error) {
                showToast(error.message || 'Unable to update Job owner.');
                popup.querySelectorAll('button,input').forEach((control) => { control.disabled = false; });
                popup.classList.remove('is-saving');
            }
        }));
        requestAnimationFrame(() => search?.focus({ preventScroll: true }));
        const closeOnOutside = (event) => {
            if (popup.contains(event.target) || event.target === button) return;
            popup.remove();
            ownerCell.classList.remove('is-editing');
            document.removeEventListener('click', closeOnOutside);
        };
        setTimeout(() => document.addEventListener('click', closeOnOutside), 0);
    }

    function jobListOpenDeliveryEditor(button, job) {
        const cell = button.closest('td');
        const deliveryCell = button.closest('.jobs-v2-delivery-cell');
        if (!cell || !deliveryCell) return;
        if (cell.querySelector('.jobs-v2-delivery-editor')) return;
        jobListCloseInlineEditors();
        deliveryCell.classList.add('is-editing');
        const editor = document.createElement('div');
        editor.className = 'jobs-v2-delivery-editor';
        editor.innerHTML = `<input type="date" value="${html(job.delivery || '')}" aria-label="Delivery date"><button type="button" class="jobs-v2-date-save" title="Save">✓</button><button type="button" class="jobs-v2-date-cancel" title="Cancel">×</button>`;
        document.body.appendChild(editor);
        const anchorRect = deliveryCell.getBoundingClientRect();
        const editorWidth = 212;
        editor.style.left = `${Math.max(8, Math.min(window.innerWidth - editorWidth - 8, anchorRect.left))}px`;
        editor.style.top = `${Math.min(window.innerHeight - 60, anchorRect.bottom + 6)}px`;
        const input = editor.querySelector('input');
        let closeOnOutside = null;
        const cancel = () => { editor.remove(); deliveryCell.classList.remove('is-editing'); if (closeOnOutside) document.removeEventListener('click', closeOnOutside); };
        editor.querySelector('.jobs-v2-date-cancel')?.addEventListener('click', cancel);
        const save = async () => {
            const value = input?.value || null;
            editor.querySelectorAll('button,input').forEach((control) => { control.disabled = true; });
            try {
                await flowtrackJobV4SaveJob(job, { delivery_date: value }, 'Delivery date updated.');
                renderJobs();
            } catch (error) {
                showToast(error.message || 'Unable to update delivery date.');
                editor.querySelectorAll('button,input').forEach((control) => { control.disabled = false; });
            }
        };
        editor.querySelector('.jobs-v2-date-save')?.addEventListener('click', save);
        input?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') { event.preventDefault(); save(); }
            if (event.key === 'Escape') { event.preventDefault(); cancel(); }
        });
        requestAnimationFrame(() => { input?.focus({ preventScroll: true }); try { input?.showPicker?.(); } catch (_) {} });
        closeOnOutside = (event) => { if (!editor.contains(event.target) && event.target !== button) cancel(); };
        setTimeout(() => document.addEventListener('click', closeOnOutside), 0);
    }

    function jobListMoreFilterMenuHtml() {
        return `<div class="jobs-v2-more-menu hidden" id="jobMoreFilterMenu"><button type="button" data-job-more-option="documents" class="${jobListFilters.more === 'documents' ? 'active' : ''}"><span>Missing documents</span>${jobListFilters.more === 'documents' ? '<b>✓</b>' : ''}</button><button type="button" data-job-more-option="" class="${!jobListFilters.more ? 'active' : ''}"><span>Any document state</span>${!jobListFilters.more ? '<b>✓</b>' : ''}</button></div>`;
    }

    function jobListActiveFilterLabel() {
        const labels = { all: 'All active', attention: 'Needs attention', week: 'Due this week', waiting: 'Waiting for client', unpaid: 'Unpaid invoices', completed: 'Completed' };
        return labels[jobListFilters.saved] || 'All active';
    }

    function jobListRow(job) {
        const nextTask = jobListNextTask(job);
        const invoice = jobListInvoiceState(job);
        const productCount = Number(job.productCount || (Array.isArray(job.products) ? job.products.length : (job.product ? 1 : 0)) || 0);
        const unitCount = Number(job.qty || (Array.isArray(job.products) ? job.products.reduce((sum, item) => sum + Number(item.qty || item.quantity || 0), 0) : 0) || 0);
        const progress = Math.max(0, Math.min(100, Number(job.progress) || 0));
        const totalPhases = (getWorkflow(Number(job.workflow))?.phases || []).filter((phase) => phase.active !== false).length || 11;
        const phaseNumber = Math.min(totalPhases, Math.max(1, Number(job.phase || 0) + 1));
        const overdue = boardDaysOverdue(job.delivery) > 0 && !jobIsCompleted(job);
        const checked = selectedBulkJobIds.has(Number(job.dbId)) ? 'checked' : '';
        return `<tr class="${checked ? 'jobs-v2-row-selected' : ''}">
            <td class="jobs-v2-cell-select"><input type="checkbox" data-job-bulk-checkbox="${html(job.dbId)}" aria-label="Select ${html(job.id)}" ${checked}></td>
            <td class="jobs-v2-cell-code"><div class="flow-id-link-row"><button type="button" class="jobs-v2-job-code" data-board-job-link="${html(job.id)}">${html(job.id)}</button>${flowtrackCopyIdentifierButton(job.id, 'Job ID', 'flow-id-copy-icon-only')}</div><div class="jobs-v2-order">${html(job.order || 'RFQ-' + String(job.id || '').slice(-5))}</div></td>
            <td class="jobs-v2-cell-client"><div class="jobs-v2-client">${html(clientName(job.client))}</div><div class="jobs-v2-brief truncate">${html(job.title || '')}</div></td>
            <td class="jobs-v2-cell-product"><div class="jobs-v2-product">${html(job.product || 'Unspecified product')}</div><div class="muted small">${productCount || 1} product${(productCount || 1) === 1 ? '' : 's'} · ${unitCount.toLocaleString()} units</div></td>
            <td class="jobs-v2-cell-phase"><span class="jobs-v2-phase">${html(phaseShortFor(job))}</span><div class="muted small" style="margin-top:4px">${phaseNumber} of ${totalPhases}</div></td>
            <td class="jobs-v2-cell-next"><div class="jobs-v2-next">${html(nextTask?.title || job.next || 'Review current phase')}</div><div class="jobs-v2-due-note">${boardDaysOverdue(nextTask?.due || job.delivery) ? 'Overdue' : 'Due'} ${boardShortDate(nextTask?.due || job.delivery)}</div></td>
            <td class="jobs-v2-cell-health">${healthBadge(job.health, job.healthReason)}</td>
            <td class="jobs-v2-cell-owner">${jobListOwnerCell(job)}</td>
            <td class="jobs-v2-cell-delivery">${jobListDeliveryCell(job, overdue)}${jobIsCompleted(job) ? `<div class="jobs-v2-completed-at"><b>Completed</b><span>${html(boardCompletedDateTime(job) || '—')}</span></div>` : ''}</td>
            <td><div class="jobs-v2-progress"><div class="progress"><span class="${progress >= 100 ? 'complete' : ''}" style="width:${progress}%"></span></div><span>${progress}%</span></div></td>
            <td><span class="jobs-v2-invoice ${invoice.tone}">${html(invoice.label)}</span></td>
            <td><button type="button" class="jobs-v2-menu" data-job-list-menu="${html(job.id)}" aria-label="Job actions">•••</button></td>
        </tr>`;
    }

    function selectedVisibleBulkJobs(filteredJobs) {
        const selected = new Set(Array.from(selectedBulkJobIds).map(Number));
        return filteredJobs.filter((job) => selected.has(Number(job.dbId)));
    }

    function jobBulkActionBar(filteredJobs) {
        const selected = selectedVisibleBulkJobs(filteredJobs);
        const count = selected.length;
        if (!count) return '<div class="jobs-v2-bulkbar hidden"></div>';
        const canDeactivate = canFlow('jobs', 'edit_all') || canFlow('jobs', 'override') || canFlow('jobs', 'manage');
        const canDelete = (isFlowAdmin || isFlowSuperAdmin) && canFlow('jobs', 'delete');
        return `<div class="jobs-v2-bulkbar"><div><strong>${count}</strong> selected · bulk action available</div><div class="jobs-v2-bulk-actions"><button type="button" id="jobBulkClear">Clear selection</button>${canDeactivate ? '<button type="button" class="primary" id="jobBulkDeactivate">Deactivate selected</button>' : ''}${canDelete ? '<button type="button" class="danger-btn" id="jobBulkDelete">Delete selected</button>' : ''}</div></div>`;
    }

    async function runJobBulkAction(action, filteredJobs) {
        const selected = selectedVisibleBulkJobs(filteredJobs);
        if (!selected.length) {
            showToast('Select at least one Job first.');
            return;
        }
        const verb = action === 'delete' ? 'delete permanently' : 'deactivate';
        if (!window.confirm(`Are you sure you want to ${verb} ${selected.length} selected Job${selected.length === 1 ? '' : 's'}?`)) return;
        const button = action === 'delete' ? $('#jobBulkDelete') : $('#jobBulkDeactivate');
        const originalText = button?.textContent || '';
        if (button) {
            button.disabled = true;
            button.textContent = action === 'delete' ? 'Deleting…' : 'Deactivating…';
        }
        try {
            const payload = await api('/flowtrack/template-api/jobs/bulk', {
                method: 'PATCH',
                body: { action, job_ids: selected.map((job) => Number(job.dbId)) },
            });
            if (action === 'delete') {
                const deletedDbIds = new Set((payload.deleted || []).map((job) => Number(job.dbId)));
                const deletedCodes = new Set((payload.deleted || []).map((job) => String(job.id)));
                for (let i = jobs.length - 1; i >= 0; i -= 1) if (deletedDbIds.has(Number(jobs[i].dbId)) || deletedCodes.has(String(jobs[i].id))) jobs.splice(i, 1);
                for (let i = tasks.length - 1; i >= 0; i -= 1) if (deletedCodes.has(String(tasks[i].job)) || selected.some((job) => String(job.id) === String(tasks[i].job))) tasks.splice(i, 1);
                for (let i = docs.length - 1; i >= 0; i -= 1) if (deletedCodes.has(String(docs[i].job)) || selected.some((job) => String(job.id) === String(docs[i].job))) docs.splice(i, 1);
                deletedDbIds.forEach((id) => selectedBulkJobIds.delete(id));
            } else {
                (payload.deactivated || []).forEach((updated) => {
                    const job = jobs.find((item) => Number(item.dbId) === Number(updated.dbId) || String(item.id) === String(updated.id));
                    if (job) {
                        job.status = updated.status || 'Inactive';
                        job.health = updated.health || 'Inactive';
                        job.active = false;
                        job.attention = false;
                        job.next = 'Deactivated by bulk action';
                    }
                    selectedBulkJobIds.delete(Number(updated.dbId));
                });
            }
            selected.forEach((job) => selectedBulkJobIds.delete(Number(job.dbId)));
            updateBadges();
            renderJobs();
            showToast(payload.message || `Selected Jobs ${action === 'delete' ? 'deleted' : 'deactivated'} successfully.`);
        } catch (error) {
            showToast(error.message);
            if (button) {
                button.disabled = false;
                button.textContent = originalText;
            }
        }
    }

    registerFlowtrackExtension('renderJobs', 'renderJobs-stage-1', function () {        jobListCloseInlineEditors();
        const activeJobs = jobListActiveJobs();
        const stats = {
            active: activeJobs.length,
            attention: activeJobs.filter((job) => job.attention || !['ON TRACK', 'COMPLETED'].includes(normalizedStatusValue(job.health || ''))).length,
            week: activeJobs.filter((job) => { const days = boardDaysUntil(job.delivery); return days !== null && days >= 0 && days <= 7; }).length,
            waiting: activeJobs.filter(jobListHasWaitingClient).length,
            unpaid: activeJobs.filter((job) => /PENDING|OVERDUE|INVOICE/.test(normalizedStatusValue(jobListInvoiceState(job).label))).length,
            completed: jobs.filter(jobIsCompleted).length,
        };
        const filtered = jobListFilteredJobs();
        const pagination = jobListPaginationState(filtered);
        const visibleJobs = pagination.items;
        $('#page-jobs').innerHTML = `<div class="jobs-v2"><div class="page-head"><div><h1>Jobs</h1><p>Manage active jobs from request to collection</p></div><div class="jobs-v2-actions"><button class="ghost" id="jobExport">Export</button><button class="ghost" id="jobColumns">Columns</button><button class="primary" id="jobCreate">＋ New Job</button></div></div>
        <div class="jobs-v2-saved">${[
            ['all', 'All active', stats.active, ''], ['attention', 'Needs attention', stats.attention, 'danger'], ['week', 'Due this week', stats.week, ''], ['waiting', 'Waiting for client', stats.waiting, ''], ['unpaid', 'Unpaid invoices', stats.unpaid, 'warn'], ['completed', 'Completed', stats.completed, '']
        ].map(([key, label, count, tone]) => `<button type="button" class="jobs-v2-chip ${tone} ${jobListFilters.saved === key ? 'active' : ''}" data-job-saved="${key}">${label} <span class="ops-count">${count}</span></button>`).join('')}</div>
        <div class="card jobs-v2-toolbar"><div class="jobs-v2-toolbar-main"><input id="jobSearch" class="jobs-v2-search" placeholder="Search Job ID, order, client or product" value="${html(jobFilter.search || '')}"><select id="jobPhase"><option value="">Phase</option>${jobListPhaseOptions()}</select><select id="jobHealth"><option value="">Health</option>${['On Track','Needs Attention','At Risk','Delayed','Blocked','Completed'].map((health) => `<option value="${html(health)}" ${normalizedStatusValue(jobFilter.health || '') === normalizedStatusValue(health) ? 'selected' : ''}>${html(health)}</option>`).join('')}</select><select id="jobOwner"><option value="">Owner</option>${jobListOwnerOptions()}</select><select id="jobClient"><option value="">Client</option>${clients.map((client) => `<option value="${html(client.id)}" ${String(jobFilter.client || '') === String(client.id) ? 'selected' : ''}>${html(client.name)}</option>`).join('')}</select><select id="jobDelivery"><option value="">Delivery</option><option value="week" ${jobListFilters.delivery === 'week' ? 'selected' : ''}>Due this week</option><option value="overdue" ${jobListFilters.delivery === 'overdue' ? 'selected' : ''}>Overdue</option></select><select id="jobInvoice"><option value="">Invoice</option><option value="unpaid" ${jobListFilters.invoice === 'unpaid' ? 'selected' : ''}>Unpaid</option></select><div class="jobs-v2-more-wrap"><button type="button" id="jobMoreFilterBtn" class="${jobListFilters.more ? 'active' : ''}"><span class="jobs-v2-filter-icon">▽</span> More filters</button>${jobListMoreFilterMenuHtml()}</div><button type="button" id="clearJobFilter" class="jobs-v2-clear-filter">× Clear filters</button></div><div class="jobs-v2-active-filters"><button type="button" id="jobSavedFilterToken">${html(jobListActiveFilterLabel())} <span>×</span></button>${jobListFilters.more === 'documents' ? '<button type="button" data-clear-job-more>Missing documents <span>×</span></button>' : ''}</div></div>
        ${jobBulkActionBar(visibleJobs)}
        <div class="card jobs-v2-table table-wrap"><table class="data-table jobs-v2-data-table">${jobListTableColumnsHtml()}<thead><tr><th><input type="checkbox" id="jobBulkSelectAll" aria-label="Select all shown jobs"></th><th>Job / Order</th><th>Client / Brief</th><th>Product / Qty</th><th>Phase</th><th>Next Action</th><th>Health</th><th>Owner</th><th>Delivery ↓</th><th>Progress</th><th>Invoice</th><th></th></tr></thead><tbody>${visibleJobs.map(jobListRow).join('') || '<tr><td colspan="12"><div class="jobs-v2-empty">No jobs match the selected filters.</div></td></tr>'}</tbody></table></div>${jobListPaginationHtml(pagination)}</div>`;
        $('#jobCreate')?.addEventListener('click', openJobModal);
        $('#jobExport')?.addEventListener('click', () => showToast('Job list export prepared.'));
        $('#jobColumns')?.addEventListener('click', () => showToast('Compact columns are enabled for this view.'));
        $('#jobSearch')?.addEventListener('input', (event) => {
            const input = event.currentTarget;
            jobFilter.search = input.value;
            resetJobListPage();
            if (jobSearchRenderTimer) window.clearTimeout(jobSearchRenderTimer);
            // Do not replace the Jobs page DOM on every keystroke. Re-render only
            // after the user pauses typing so the input never loses keyboard focus.
            jobSearchRenderTimer = window.setTimeout(() => {
                jobSearchRenderTimer = null;
                const caret = input.selectionStart ?? jobFilter.search.length;
                renderJobs();
                requestAnimationFrame(() => {
                    const nextInput = $('#jobSearch');
                    if (!nextInput) return;
                    nextInput.focus({ preventScroll: true });
                    const nextCaret = Math.min(caret, nextInput.value.length);
                    nextInput.setSelectionRange(nextCaret, nextCaret);
                });
            }, 400);
        });
        $('#jobPhase')?.addEventListener('change', (event) => { jobFilter.phase = event.target.value; resetJobListPage(); renderJobs(); });
        $('#jobHealth')?.addEventListener('change', (event) => { jobFilter.health = event.target.value; resetJobListPage(); renderJobs(); });
        $('#jobOwner')?.addEventListener('change', (event) => { jobListFilters.owner = event.target.value; resetJobListPage(); renderJobs(); });
        $('#jobClient')?.addEventListener('change', (event) => { jobFilter.client = event.target.value; resetJobListPage(); renderJobs(); });
        $('#jobDelivery')?.addEventListener('change', (event) => { jobListFilters.delivery = event.target.value; resetJobListPage(); renderJobs(); });
        $('#jobInvoice')?.addEventListener('change', (event) => { jobListFilters.invoice = event.target.value; resetJobListPage(); renderJobs(); });
        $('#jobMoreFilterBtn')?.addEventListener('click', (event) => { event.stopPropagation(); $('#jobMoreFilterMenu')?.classList.toggle('hidden'); });
        $$('[data-job-more-option]').forEach((button) => button.addEventListener('click', (event) => { event.stopPropagation(); jobListFilters.more = button.dataset.jobMoreOption || ''; resetJobListPage(); renderJobs(); }));
        $('[data-clear-job-more]')?.addEventListener('click', () => { jobListFilters.more = ''; resetJobListPage(); renderJobs(); });
        $('#jobSavedFilterToken')?.addEventListener('click', () => { jobListFilters.saved = 'all'; resetJobListPage(); renderJobs(); });
        $('#clearJobFilter')?.addEventListener('click', () => { jobFilter = { search: '', phase: '', health: '', client: '' }; jobListFilters = { owner: '', delivery: '', invoice: '', more: '', saved: 'all' }; resetJobListPage(); renderJobs(); });
        $('#jobPageSize')?.addEventListener('change', (event) => {
            const nextSize = Number(event.target.value) || 10;
            jobListPageSize = [10, 20, 30, 40].includes(nextSize) ? nextSize : 10;
            try { localStorage.setItem('flowtrackJobListPageSize', String(jobListPageSize)); } catch (_) {}
            resetJobListPage();
            renderJobs();
        });
        $('#jobPrevPage')?.addEventListener('click', () => { if (jobListPage > 1) { jobListPage -= 1; renderJobs(); } });
        $('#jobNextPage')?.addEventListener('click', () => { if (jobListPage < pagination.pageCount) { jobListPage += 1; renderJobs(); } });
        const selectAll = $('#jobBulkSelectAll');
        if (selectAll) {
            const selectable = visibleJobs.filter((job) => Number(job.dbId));
            const selectedVisibleCount = selectedVisibleBulkJobs(visibleJobs).length;
            selectAll.checked = selectable.length > 0 && selectedVisibleCount === selectable.length;
            selectAll.indeterminate = selectedVisibleCount > 0 && selectedVisibleCount < selectable.length;
            selectAll.addEventListener('change', (event) => {
                selectable.forEach((job) => {
                    if (event.target.checked) selectedBulkJobIds.add(Number(job.dbId));
                    else selectedBulkJobIds.delete(Number(job.dbId));
                });
                renderJobs();
            });
        }
        $$('[data-job-bulk-checkbox]').forEach((checkbox) => checkbox.addEventListener('change', (event) => {
            const id = Number(event.target.dataset.jobBulkCheckbox);
            if (event.target.checked) selectedBulkJobIds.add(id); else selectedBulkJobIds.delete(id);
            renderJobs();
        }));
        $('#jobBulkClear')?.addEventListener('click', () => { selectedBulkJobIds.clear(); renderJobs(); });
        $('#jobBulkDeactivate')?.addEventListener('click', () => runJobBulkAction('deactivate', visibleJobs));
        $('#jobBulkDelete')?.addEventListener('click', () => runJobBulkAction('delete', visibleJobs));
        $$('[data-job-saved]').forEach((button) => button.addEventListener('click', () => { jobListFilters.saved = button.dataset.jobSaved; resetJobListPage(); renderJobs(); }));
        $$('[data-job-list-edit-owner]').forEach((button) => button.addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); const job = jobs.find((item) => String(item.id) === String(button.dataset.jobListEditOwner)); if (job) jobListOpenOwnerEditor(button, job); }));
        $$('[data-job-list-edit-delivery]').forEach((button) => button.addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); const job = jobs.find((item) => String(item.id) === String(button.dataset.jobListEditDelivery)); if (job) jobListOpenDeliveryEditor(button, job); }));
        $$('[data-job-list-menu]').forEach((button) => button.addEventListener('click', (event) => { event.stopPropagation(); openJob(button.dataset.jobListMenu); }));
        bindCommon();
        applyActionPermissions();
        requestAnimationFrame(flowtrackFitWorkSurfacesToViewport);
    });

    renderBoard = function () {
        const taskList = boardFilteredTasks();
        const jobList = boardMode === 'tasks' ? Array.from(new Set(taskList.map((task) => boardJobForTask(task)?.id || task.job))).map((id) => jobs.find((job) => String(job.id) === String(id)) || { id }).filter(Boolean) : boardFilteredJobsForPhases();
        const filteredCount = boardMode === 'tasks' ? taskList.length : jobList.length;
        const filteredJobCount = boardMode === 'tasks' ? jobList.length : jobList.length;
        $('#page-board').innerHTML = `<div class="ops-board-page ops-mode-${boardMode === 'tasks' ? 'tasks' : 'jobs'}"><div class="page-head"><div><h1>Operations Board</h1><p>Track work across all active Jobs</p></div><div class="ops-board-actions">${boardBulkToggleButton('opsBoardExpandAllTop', 'ghost')}<button class="primary" id="boardCreate">＋ New Job</button></div></div><div class="ops-board-tabs"><button class="tab-btn ${boardMode === 'jobs' ? 'active' : ''}" data-boardmode="jobs">Job Board</button><button class="tab-btn ${boardMode === 'tasks' ? 'active' : ''}" data-boardmode="tasks">Task Board</button></div>${operationsBoardToolbar(filteredCount, filteredJobCount)}<div id="kanbanArea"></div></div>`;
        renderKanban();
        $$('[data-boardmode]').forEach((button) => button.addEventListener('click', () => { boardMode = button.dataset.boardmode; try { sessionStorage.setItem('flowtrackBoardModeTouched', '1'); } catch (_) {} renderBoard(); }));
        $('#boardCreate')?.addEventListener('click', openJobModal);
        $('#opsBoardSearch')?.addEventListener('input', (event) => {
            boardSearchValue = event.currentTarget.value;
            // Keep the toolbar/search input mounted. Only refresh the board body
            // and its result summary so typing remains uninterrupted.
            renderKanban();
            const taskList = boardFilteredTasks();
            const jobList = boardMode === 'tasks'
                ? Array.from(new Set(taskList.map((task) => boardJobForTask(task)?.id || task.job)))
                    .map((id) => jobs.find((job) => String(job.id) === String(id)) || { id })
                    .filter(Boolean)
                : boardFilteredJobsForPhases();
            const summary = $('#page-board .ops-status-summary span');
            if (summary) {
                const filteredCount = boardMode === 'tasks' ? taskList.length : jobList.length;
                const filteredJobCount = jobList.length;
                summary.innerHTML = `Showing <b>${filteredCount}</b> ${boardMode === 'tasks' ? 'of ' + flowtrackVisibleTaskRows(tasks).length + ' tasks' : 'Jobs'} across <b>${filteredJobCount}</b> ${filteredJobCount === 1 ? 'job' : 'jobs'}`;
            }
        });
        $$('[data-board-filter]').forEach((select) => select.addEventListener('change', () => { boardFilters[select.dataset.boardFilter] = select.value; renderBoard(); }));
        $$('[data-board-quick]').forEach((button) => button.addEventListener('click', () => { boardQuickFilter = boardQuickFilter === button.dataset.boardQuick ? 'all' : button.dataset.boardQuick; renderBoard(); }));
        $('#opsBoardClear')?.addEventListener('click', () => { boardSearchValue = ''; boardQuickFilter = 'all'; boardFilters = { job: '', client: '', assignee: '', status: '', due: '', priority: '' }; renderBoard(); });
        const toggleAllBoardJobs = () => {
            const ids = boardVisibleJobIdsForExpandToggle();
            if (boardMode === 'tasks') {
                const allOpen = ids.length && ids.every((id) => !collapsedBoardJobs.has(id));
                ids.forEach((id) => { if (allOpen) collapsedBoardJobs.add(id); else collapsedBoardJobs.delete(id); });
            } else {
                const allOpen = ids.length && ids.every((id) => expandedBoardJobCards.has(id));
                ids.forEach((id) => { if (allOpen) expandedBoardJobCards.delete(id); else expandedBoardJobCards.add(id); });
            }
            renderBoard();
        };
        $('#opsBoardExpandAll')?.addEventListener('click', toggleAllBoardJobs);
        $('#opsBoardExpandAllTop')?.addEventListener('click', toggleAllBoardJobs);
        $('[data-board-save-view]')?.addEventListener('click', () => showToast('Board view saved for quick access.'));
        applyActionPermissions();
    };

    registerFlowtrackExtension('renderKanban', 'renderKanban-stage-2', function () {        const area = $('#kanbanArea');
        if (!area) return;
        if (boardMode === 'tasks') {
            area.innerHTML = renderOperationsTaskBoard(boardFilteredTasks());
        } else {
            area.innerHTML = renderOperationsJobBoard(boardFilteredJobsForPhases());
        }

        let dragged = null;
        $$('.ops-task-card,.ops-phase-card').forEach((card) => {
            card.addEventListener('dragstart', (event) => {
                if (card.dataset.task && card.dataset.taskUpdatable === '0') {
                    event.preventDefault();
                    showToast('Only the assigned user, an Administrator, or the Super Admin can update this Task.');
                    return;
                }
                dragged = card;
                card.classList.add('dragging');
            });
            card.addEventListener('dragend', () => {
                card.classList.remove('dragging');
                dragged = null;
            });
        });

        $$('.ops-task-cell').forEach((cell) => {
            cell.addEventListener('dragover', (event) => {
                event.preventDefault();
                cell.classList.add('drop-hover');
            });
            cell.addEventListener('dragleave', () => cell.classList.remove('drop-hover'));
            cell.addEventListener('drop', async () => {
                cell.classList.remove('drop-hover');
                if (!dragged?.dataset.task) return;
                try {
                    const task = flowtrackTaskFromActiveSurface(dragged.dataset.task);
                    if (!task) throw new Error('The selected task is no longer available.');
                    const targetStatus = boardTargetStatusForColumn(cell.dataset.dropColumn);
                    if (!targetStatus) throw new Error('The selected task status is not available in this workspace.');
                    if (taskStatusMatches(targetStatus, ['BLK', 'ATTN', 'BLOCKED', 'ATTENTION', 'HOLD', 'ON HOLD'])) {
                        showToast('Open the task and add a blocker reason before marking it blocked.');
                        openTask(task.id);
                        return;
                    }
                    await persistTask(task, targetStatus);
                    renderBoard();
                    updateBadges();
                    showToast('Task status updated on the board.');
                } catch (error) {
                    showToast(error.message);
                    renderBoard();
                }
            });
        });

        $$('.ops-phase-column .kanban-list').forEach((list) => {
            list.addEventListener('dragover', (event) => event.preventDefault());
            list.addEventListener('drop', async () => {
                if (!dragged?.dataset.job) return;
                try {
                    const job = jobs.find((item) => item.id === dragged.dataset.job);
                    if (!job) throw new Error('The selected Job is no longer available.');
                    const workflow = getWorkflow(Number(job.workflow)) || getWorkflow(job.workflow);
                    const phaseId = Number(list.dataset.dropPhaseId);
                    const excludedPhaseIds = new Set((job.excludedPhaseIds || []).map((id) => Number(id)));
                    const phase = (workflow?.phases || []).find((item) => Number(item.id) === phaseId && item.active !== false && !excludedPhaseIds.has(Number(item.id)));
                    if (!phase) throw new Error('The selected workflow phase is not configured for this Job.');
                    const activePhases = (workflow.phases || []).filter((item) => item.active !== false && !excludedPhaseIds.has(Number(item.id))).slice().sort((left, right) => Number(left.sequence || 0) - Number(right.sequence || 0));
                    const currentIndex = activePhases.findIndex((item) => Number(item.id) === Number(job.phaseId));
                    const targetIndex = activePhases.findIndex((item) => Number(item.id) === Number(phase.id));
                    let reason = null;
                    if (currentIndex >= 0 && targetIndex > currentIndex + 1) {
                        reason = window.prompt('Enter the authorised reason for skipping workflow phases:')?.trim() || '';
                        if (!reason) {
                            showToast('A reason is required when skipping workflow phases.');
                            renderBoard();
                            return;
                        }
                    }
                    await moveJobToPhase(job, phase, reason);
                    renderBoard();
                    updateBadges();
                    showToast('Job phase updated on the board.');
                } catch (error) {
                    showToast(error.message);
                    renderBoard();
                }
            });
        });

        $$('[data-board-collapse]').forEach((button) => button.addEventListener('click', (event) => {
            event.stopPropagation();
            const id = button.dataset.boardCollapse;
            if (collapsedBoardJobs.has(id)) collapsedBoardJobs.delete(id); else collapsedBoardJobs.add(id);
            renderKanban();
        }));
        $$('[data-board-job-card-toggle]').forEach((button) => button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const id = button.dataset.boardJobCardToggle;
            if (expandedBoardJobCards.has(id)) expandedBoardJobCards.delete(id); else expandedBoardJobCards.add(id);
            renderKanban();
        }));
        $$('[data-board-card-menu]').forEach((button) => button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const currentMenu = button.closest('.ops-board-card-menu-wrap')?.querySelector('.ops-board-card-action-menu');
            $$('.ops-board-card-action-menu').forEach((menu) => {
                if (menu !== currentMenu) menu.classList.add('hidden');
            });
            currentMenu?.classList.toggle('hidden');
            button.setAttribute('aria-expanded', currentMenu?.classList.contains('hidden') ? 'false' : 'true');
        }));
        $$('[data-board-row-menu]').forEach((button) => button.addEventListener('click', (event) => {
            event.stopPropagation();
            showToast('Open the Job to manage its actions.');
        }));
        $$('[data-board-delete-task]').forEach((button) => button.addEventListener('click', async (event) => {
            event.preventDefault();
            event.stopPropagation();
            const taskCode = button.dataset.boardDeleteTaskCode || 'this Task';
            if (!window.confirm(`Delete ${taskCode}?`)) return;
            button.disabled = true;
            try {
                const result = await api(`/flowtrack/template-api/tasks/${button.dataset.boardDeleteTask}`, { method: 'DELETE' });
                if (result.job) {
                    const jobIndex = jobs.findIndex((item) => Number(item.dbId) === Number(result.job.dbId));
                    if (jobIndex >= 0) jobs.splice(jobIndex, 1, result.job);
                    if (Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
                }
                flowtrackRemoveRecord(tasks, result.deletedTask || { dbId: Number(button.dataset.boardDeleteTask) });
                renderBoard();
                updateBadges();
                showToast(result.message || 'Task deleted successfully.');
            } catch (error) {
                button.disabled = false;
                showToast(error.message);
            }
        }));
        bindBoardDueDatePickers('#kanbanArea', renderBoard);
        bindCommon();
    });

    const flowtrackBoundHandlers = new WeakMap();
    function flowtrackBindOnce(element, key, eventName, handler, options) {
        if (!element) return;
        const keys = flowtrackBoundHandlers.get(element) || new Set();
        if (keys.has(key)) return;
        keys.add(key);
        flowtrackBoundHandlers.set(element, keys);
        element.addEventListener(eventName, handler, options);
    }

    bindCommon = function () {
        flowtrackBindCopyIdentifiers(document);
        const newClientJob = $('[data-new-client-job]');
        flowtrackBindOnce(newClientJob, 'new-client-job', 'click', () => {
            const id = newClientJob.dataset.newClientJob;
            closeAll();
            setTimeout(() => openJobModal(id), 50);
        });
        $$('[data-go]').forEach((button) => flowtrackBindOnce(button, 'go', 'click', () => navigate(button.dataset.go)));
        $$('[data-job]').forEach((element) => flowtrackBindOnce(element, 'open-job', 'click', (event) => {
            event.stopPropagation();
            openJob(element.dataset.job);
        }));
        $$('[data-task]').forEach((element) => {
            const taskIdentifier = String(element.dataset.task || '').trim();
            if (!taskIdentifier) return;
            flowtrackBindOnce(element, 'open-task', 'click', (event) => {
                if (element.classList.contains('dragging')) return;
                if (event.target.closest('button, a, input, select, textarea, label')) return;
                event.stopPropagation();
                openTask(taskIdentifier);
            });
        });
        $$('[data-board-job-link]').forEach((button) => flowtrackBindOnce(button, 'board-job-link', 'click', (event) => {
            event.stopPropagation();
            openJob(button.dataset.boardJobLink);
        }));
        $$('[data-client]').forEach((element) => flowtrackBindOnce(element, 'open-client', 'click', () => openClient(element.dataset.client)));
        $$('[data-doc]').forEach((element) => flowtrackBindOnce(element, 'open-document', 'click', () => openDoc(element.dataset.doc)));
        $$('[data-metric]').forEach((metricElement) => {
            if (!metricElement.dataset.metric) return;
            metricElement.setAttribute('role', 'button');
            metricElement.setAttribute('tabindex', '0');
            metricElement.setAttribute('title', 'Click to filter the related records');
            flowtrackBindOnce(metricElement, 'metric', 'click', (event) => {
                event.preventDefault();
                if (flowtrackHandleMetricFilter(metricElement)) return;
                const filter = metricElement.dataset.metric;
                navigate(filter === 'done' ? 'reports' : filter === 'blocked' || filter === 'overdue' ? 'mywork' : 'jobs');
                if (filter === 'risk') { jobListFilters.saved = 'attention'; resetJobListPage(); renderJobs(); }
                if (filter === 'shipment') { const phase = flowtrackShippingPhaseFilterValue(); if (phase !== '') jobFilter.phase = String(phase); resetJobListPage(); renderJobs(); }
                if (filter === 'payment') { jobListFilters.invoice = 'unpaid'; resetJobListPage(); renderJobs(); }
            });
            flowtrackBindOnce(metricElement, 'metric-keyboard', 'keydown', (event) => {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                metricElement.click();
            });
        });

        $$('[data-next-phase]').forEach((next) => flowtrackBindOnce(next, 'next-phase', 'click', async () => {
            const job = jobs.find((item) => item.id === next.dataset.nextPhase);
            if (!job) return;
            const phases = flowtrackActivePhases(job);
            const currentIndex = phases.findIndex((phase) => Number(phase.id) === Number(job.phaseId));
            const target = currentIndex >= 0 ? phases[currentIndex + 1] : null;
            if (!target) {
                showToast('No next active workflow phase is configured.');
                return;
            }
            next.disabled = true;
            try {
                const result = await moveJobToPhase(job, target);
                showToast(`${phaseNameFor(result.job)} activated. Progress recalculated automatically.`);
                openJob(result.job.id);
            } catch (error) {
                showToast(error.message);
            } finally {
                next.disabled = false;
            }
        }));
        $$('[data-flow-phase]').forEach((button) => flowtrackBindOnce(button, 'flow-phase-history', 'click', async (event) => {
            event.preventDefault();
            event.stopPropagation();
            const job = flowtrackFindJob(button.dataset.flowJob);
            if (!job) return;
            const scope = button.closest('.drawer-body') || button.closest('.standalone-record-content') || document;
            const panel = scope.querySelector('#flowtrackPhaseHistoryPanel');
            if (panel) panel.innerHTML = flowtrackInlineProgress('Loading selected phase tasks…');
            try {
                await flowtrackRefreshJobTasks(job);
            } catch (error) {
                // Keep the current in-memory data visible if the refresh fails.
            }
            flowtrackOpenPhaseHistory(button.dataset.flowJob, button.dataset.flowPhase, button);
        }));
        $$('[data-add-task]').forEach((button) => flowtrackBindOnce(button, 'add-job-task', 'click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            openJobTaskModal(button.dataset.addTask, button.dataset.addTaskPhase || null);
        }));
        $$('[data-open-job-tasks]').forEach((viewTasks) => flowtrackBindOnce(viewTasks, 'view-job-tasks', 'click', () => $('[data-detailtab="2"]')?.click()));
    };

    function metricDateKey(value = new Date()) {
        const date = value instanceof Date ? value : new Date(value);
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function taskIsCompleted(task) {
        return Boolean(task.completedAt) || String(task.status || '').toLowerCase() === 'completed';
    }

    function jobIsCompleted(job) {
        return String(job.status || '').toLowerCase() === 'completed' || String(job.health || '').toLowerCase() === 'completed';
    }

    function taskIsBlocked(task) {
        const status = String(task.status || '').toLowerCase();
        return !taskIsCompleted(task) && (Boolean(task.attention) || Boolean(task.blockerReason) || status === 'blocked');
    }

    function jobNeedsAttentionFromTasks(job) {
        return tasks.some((task) => {
            const sameJob = String(task.job) === String(job?.id) || Number(task.jobDbId) === Number(job?.dbId);
            if (!sameJob || taskIsCompleted(task)) return false;
            const status = normalizedStatusValue(task.status || task.statusCode || '');
            return Boolean(task.attention)
                || Boolean(task.blockerReason)
                || ['BLOCKED', 'BLK', 'ATTENTION', 'ATTN', 'HOLD', 'ON HOLD', 'REVISION REQUIRED'].includes(status);
        });
    }

    function dynamicDashboardMetrics() {
        const date = summaryMetrics.asOf?.date || metricDateKey();
        const activeJobs = jobs.filter((job) => !jobIsCompleted(job));
        return {
            activeJobs: activeJobs.length,
            needsAttention: activeJobs.filter((job) => jobNeedsAttentionFromTasks(job) || job.attention || ['needs attention', 'at risk', 'delayed', 'blocked'].includes(String(job.health || '').toLowerCase())).length,
            overdueTasks: tasks.filter((task) => !taskIsCompleted(task) && task.due && task.due < date).length,
            blockedTasks: tasks.filter(taskIsBlocked).length,
            shippingNow: activeJobs.filter((job) => /ship/i.test(phaseNameFor(job)) || /ship/i.test(phaseShortFor(job))).length,
            outstanding: canSensitive('invoice_amount') ? clients.reduce((sum, client) => sum + Number(client.outstanding || 0), 0) : null,
            canViewFinancials: canSensitive('invoice_amount'),
        };
    }

    function resetFlowtrackJobFilters() {
        jobFilter = { search: '', phase: '', health: '', client: '' };
        jobListFilters = { owner: '', delivery: '', invoice: '', more: '', saved: 'all' };
        selectedBulkJobIds.clear();
        resetJobListPage();
    }

    function resetFlowtrackMyWorkFilters() {
        myWorkSearchValue = '';
        myWorkQuickFilter = 'all';
        myWorkFilters = { job: '', client: '', assignee: '', status: '', priority: '', due: '' };
    }

    function flowtrackShippingPhaseFilterValue() {
        for (const workflow of flowtrackToArray(workflowTemplates)) {
            const phasesForWorkflow = flowtrackToArray(workflow?.phases);
            for (let index = 0; index < phasesForWorkflow.length; index += 1) {
                const phase = phasesForWorkflow[index] || {};
                const label = [phase.name, phase.short, phase.shortName, phase.taskPack, phase.document, phase.entry, phase.exit].join(' ');
                if (/ship|shipping|shipment|delivery|dispatch/i.test(label)) {
                    return phase.id ?? phase.phaseId ?? index;
                }
            }
        }
        return '';
    }

    function flowtrackApplyDashboardMetricFilter(filter) {
        resetFlowtrackJobFilters();
        resetFlowtrackMyWorkFilters();
        if (filter === 'jobs') {
            jobListFilters.saved = 'all';
            navigate('jobs');
            showToast('Showing active Jobs.');
            return true;
        }
        if (filter === 'risk') {
            jobListFilters.saved = 'attention';
            navigate('jobs');
            showToast('Showing Jobs that need attention.');
            return true;
        }
        if (filter === 'overdue') {
            myWorkFilters.due = 'overdue';
            navigate('mywork');
            showToast('Showing overdue Tasks.');
            return true;
        }
        if (filter === 'blocked') {
            myWorkQuickFilter = 'blocked';
            navigate('mywork');
            showToast('Showing blocked and attention Tasks.');
            return true;
        }
        if (filter === 'shipment') {
            const shippingPhase = flowtrackShippingPhaseFilterValue();
            if (shippingPhase !== '') jobFilter.phase = String(shippingPhase);
            else jobFilter.search = 'ship';
            navigate('jobs');
            showToast('Showing Jobs currently in shipping or delivery phases.');
            return true;
        }
        if (filter === 'payment') {
            jobListFilters.invoice = 'unpaid';
            navigate('jobs');
            showToast('Showing Jobs with open balances or unpaid invoices.');
            return true;
        }
        return false;
    }

    function flowtrackApplyMyWorkMetricFilter(filter) {
        resetFlowtrackMyWorkFilters();
        if (filter === 'today') {
            myWorkFilters.due = 'today';
            navigate('mywork');
            showToast('Showing Tasks due today.');
            return true;
        }
        if (filter === 'overdue') {
            myWorkFilters.due = 'overdue';
            navigate('mywork');
            showToast('Showing overdue Tasks.');
            return true;
        }
        if (filter === 'blocked') {
            myWorkQuickFilter = 'blocked';
            navigate('mywork');
            showToast('Showing blocked and attention Tasks.');
            return true;
        }
        if (filter === 'waiting') {
            myWorkQuickFilter = 'waiting';
            navigate('mywork');
            showToast('Showing Tasks waiting for review.');
            return true;
        }
        if (filter === 'done-week') {
            myWorkQuickFilter = 'done-week';
            navigate('mywork');
            showToast('Showing Tasks completed this week.');
            return true;
        }
        if (filter === 'upcoming') {
            myWorkQuickFilter = 'upcoming';
            navigate('mywork');
            showToast('Showing upcoming Tasks.');
            return true;
        }
        if (filter === 'done') {
            myWorkQuickFilter = 'done';
            navigate('mywork');
            showToast('Showing completed Tasks.');
            return true;
        }
        return false;
    }

    function flowtrackHandleMetricFilter(metricElement) {
        const filter = metricElement?.dataset?.metric || '';
        if (!filter) return false;
        const sourcePage = metricElement.closest('#page-mywork') ? 'mywork' : metricElement.closest('#page-dashboard') ? 'dashboard' : '';
        if (sourcePage === 'dashboard') return flowtrackApplyDashboardMetricFilter(filter);
        if (sourcePage === 'mywork') return flowtrackApplyMyWorkMetricFilter(filter);
        return false;
    }

    function recentTaskActivities() {
        const apiActivities = (Array.isArray(dashboardActivities) ? dashboardActivities : []).map((item) => ({
            ...item,
            createdAt: item.createdAt || item.created_at || '',
        }));
        const derivedActivities = tasks.slice()
            .filter((task) => task.createdAt)
            .map((task) => ({
                id: `task-created-${task.dbId || task.id}`,
                title: 'Task created',
                text: `${task.id} · ${task.title}${task.job ? ` · ${task.job}` : ''}`,
                time: new Date(task.createdAt).toLocaleString(),
                createdAt: task.createdAt,
                event: 'task.created',
                task: task.id,
                job: task.job,
                user: { name: task.assignee || 'System', initials: userInitials(task.assignee || 'System') },
            }));

        const seenTasks = new Set();
        return flowtrackToArray(apiActivities).concat(flowtrackToArray(derivedActivities))
            .sort((a, b) => String(b.createdAt || '').localeCompare(String(a.createdAt || '')))
            .filter((item) => {
                const key = String(item.task || item.id || '');
                if (!key || seenTasks.has(key)) return false;
                seenTasks.add(key);
                return true;
            })
            .slice(0, 6);
    }

    renderDashboard = function () {
        // Server aggregates stay authoritative even though Dashboard only loads
        // a bounded operational preview instead of every Job/Task at login.
        const metrics = { ...dynamicDashboardMetrics(), ...(summaryMetrics.dashboard || {}) };
        const activeJobs = jobs.filter((job) => !jobIsCompleted(job));
        const attentionJobs = activeJobs
            .filter((job) => jobNeedsAttentionFromTasks(job) || job.attention || ['needs attention', 'at risk', 'delayed', 'blocked'].includes(String(job.health || '').toLowerCase()))
            .sort((a, b) => String(a.delivery || '9999-12-31').localeCompare(String(b.delivery || '9999-12-31')))
            .slice(0, 6);
        const phaseMap = new Map();
        activeJobs.forEach((job) => {
            const label = phaseShortFor(job) || phaseNameFor(job) || 'Unassigned';
            phaseMap.set(label, (phaseMap.get(label) || 0) + 1);
        });
        const phaseCounts = Array.from(phaseMap.entries())
            .filter((entry) => Array.isArray(entry) && entry.length >= 2)
            .sort((left, right) => Number(right[1] || 0) - Number(left[1] || 0));
        const maxPhase = Math.max(1, ...phaseCounts.map((entry) => Number(entry?.[1] || 0)));
        const workloadMap = new Map();
        tasks.filter((task) => !taskIsCompleted(task)).forEach((task) => {
            const assignee = task.assignee || 'Unassigned';
            workloadMap.set(assignee, (workloadMap.get(assignee) || 0) + 1);
        });
        const workload = Array.from(workloadMap.entries())
            .filter((entry) => Array.isArray(entry) && entry.length >= 2)
            .sort((left, right) => Number(right[1] || 0) - Number(left[1] || 0))
            .slice(0, 6);
        const maxWorkload = Math.max(1, ...workload.map((entry) => Number(entry?.[1] || 0)));
        const displayDate = summaryMetrics.asOf?.displayDate || new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
        const outstandingValue = metrics.canViewFinancials ? currency(metrics.outstanding || 0) : 'Restricted';
        const recentActivities = recentTaskActivities();

        $('#page-dashboard').innerHTML = `<div class="page-head"><div><h1>Management Dashboard</h1><p>${html(displayDate)} · Exceptions, workload and delivery health</p></div><div class="actions"><button class="ghost" data-dashboard-action="export">Export summary</button><button class="primary" data-dashboard-action="create">＋ New Job</button></div></div>
        <div class="metrics">${metric('Active Jobs', metrics.activeJobs, 'Across visible workflow phases', 'jobs')}${metric('Needs Attention', metrics.needsAttention, 'Risk, delay or blocker', 'risk')}${metric('Overdue Tasks', metrics.overdueTasks, 'Require immediate update', 'overdue')}${metric('Blocked Tasks', metrics.blockedTasks, 'Require resolution', 'blocked')}${metric('Shipping Now', metrics.shippingNow, 'Ready or in transit', 'shipment')}${metric('Outstanding', outstandingValue, metrics.canViewFinancials ? 'Open client balance' : 'Financial access required', 'payment')}</div>
        <div class="grid-2"><div class="card section-card"><div class="section-head"><h3>Needs Attention</h3><button class="link-btn" data-go="jobs">View all Jobs</button></div><div class="attention-list">${attentionJobs.map((job) => `<div class="attention-item" data-job="${job.id}"><span class="signal red"></span><div><div class="item-title">${html(job.next || job.title)}</div><div class="item-meta">${html(job.id)} · ${html(clientName(job.client))} · ${html(phaseShortFor(job))}</div></div>${healthBadge(job.health)}</div>`).join('') || '<div class="empty-state">No visible Jobs currently require attention.</div>'}</div></div>
        <div class="card section-card"><div class="section-head"><h3>Jobs by Phase</h3><button class="link-btn" data-go="board">Open board</button></div><div class="phase-list">${phaseCounts.map((entry) => { const label = entry?.[0] ?? 'Unassigned'; const count = Number(entry?.[1] || 0); return `<div class="phase-row"><span>${html(label)}</span><div class="progress"><span style="width:${Math.max(8, count / maxPhase * 100)}%"></span></div><b>${count}</b></div>`; }).join('') || '<div class="empty-state">No active Jobs are visible.</div>'}</div></div></div>
        <div class="grid-3"><div class="card section-card"><div class="section-head"><h3>Team Workload</h3><button class="link-btn" data-go="reports">Details</button></div>${workload.map((entry) => { const name = String(entry?.[0] || 'Unassigned'); const count = Number(entry?.[1] || 0); return `<div class="workload-row"><div class="person"><span class="avatar">${html(userInitials(name))}</span>${html(name)}</div><div class="progress"><span style="width:${Math.max(8, count / maxWorkload * 100)}%"></span></div><b>${count}</b></div>`; }).join('') || '<div class="empty-state">No open Tasks are visible.</div>'}</div>
        <div class="card section-card"><div class="section-head"><h3>Upcoming Deliveries</h3><button class="link-btn" data-go="jobs">View Jobs</button></div><table class="mini-table"><thead><tr><th>Job</th><th>Client</th><th>Delivery</th></tr></thead><tbody>${activeJobs.filter((job) => job.delivery).sort((a, b) => a.delivery.localeCompare(b.delivery)).slice(0, 6).map((job) => `<tr data-job="${job.id}" style="cursor:pointer"><td><b>${html(String(job.id).slice(-5))}</b><div class="muted small truncate" style="max-width:125px">${html(job.title)}</div></td><td>${html(String(clientName(job.client) || '—').trim().split(/\s+/).filter(Boolean)[0] || '—')}</td><td class="${job.delivery < (summaryMetrics.asOf?.date || metricDateKey()) ? 'due overdue' : ''}">${formatDate(job.delivery)}</td></tr>`).join('') || '<tr><td colspan="3"><div class="empty-state">No upcoming deliveries.</div></td></tr>'}</tbody></table></div>
        <div class="card section-card"><div class="section-head"><h3>Recent Activity</h3><button class="link-btn" data-go="mywork">View Tasks</button></div>${recentActivities.map((item) => `<div class="activity" ${item.task ? `data-task="${html(item.task)}"` : item.job ? `data-job="${html(item.job)}"` : ''} style="cursor:pointer"><span class="avatar">${html(item.user?.initials || userInitials(item.user?.name || 'System'))}</span><div><div class="activity-text"><b>${html(item.title || 'Task created')}</b><br>${html(item.text || '')}</div><div class="activity-time">${html(item.time || '')}</div></div></div>`).join('') || '<div class="empty-state">No recently created tasks.</div>'}</div></div>`;
        bindCommon();
        $$('[data-dashboard-action="create"]').forEach((button) => button.addEventListener('click', openJobModal));
        $('[data-dashboard-action="export"]')?.addEventListener('click', () => window.print());
        applyActionPermissions();
    };

    function taskAssignedToCurrentUser(task) {
        const userId = Number(currentUser?.id || 0);
        const taskAssigneeId = Number(task?.assigneeId || task?.assignee_id || 0);
        if (userId && taskAssigneeId && userId === taskAssigneeId) return true;
        const userName = String(currentUser?.name || '').trim().toLowerCase();
        const taskAssigneeName = String(task?.assignee || task?.assigneeName || '').trim().toLowerCase();
        return Boolean(userName && taskAssigneeName && userName === taskAssigneeName);
    }

    function myWorkIsAdministrativeView() {
        return Boolean(isFlowAdmin || isFlowSuperAdmin);
    }

    function myWorkBaseTasks() {
        // My Work is strictly server-snapshot driven. Never fall back to the
        // shared Tasks array: it can contain a different page's partial data or
        // legacy prototype state while the real snapshot is still loading.
        if (!myWorkSnapshotLoaded) return [];
        const visibleTasks = flowtrackVisibleTaskRows(myWorkSnapshotTasks);
        return myWorkIsAdministrativeView() ? visibleTasks : visibleTasks.filter(taskAssignedToCurrentUser);
    }

    function myWorkBaseJobs() {
        // My Work is task-driven for every role, including Administrators.
        // Do not render unrelated/stale Job records just because they still
        // exist in the shared Jobs cache. This also prevents deleted/demo Jobs
        // from remaining as empty rows after their Tasks are gone.
        const taskRows = myWorkBaseTasks();
        if (!taskRows.length) return [];

        const taskJobKeys = new Set();
        taskRows.forEach((task) => {
            if (task?.job) taskJobKeys.add(String(task.job));
            if (task?.jobDbId) taskJobKeys.add(`db:${Number(task.jobDbId)}`);
        });

        if (!myWorkSnapshotLoaded) return [];
        return flowtrackToArray(myWorkSnapshotJobs).filter((job) => (
            taskJobKeys.has(String(job?.id || ''))
            || taskJobKeys.has(`db:${Number(job?.dbId || 0)}`)
        ));
    }

    function myWorkVisibleJobIdsForExpandToggle() {
        return myWorkFilteredJobs(myWorkFilteredTasks())
            .map((job) => String(job?.id || '').trim())
            .filter(Boolean);
    }

    function myWorkAllVisibleJobsExpanded() {
        const ids = myWorkVisibleJobIdsForExpandToggle();
        return Boolean(ids.length) && ids.every((id) => !collapsedBoardJobs.has(id));
    }

    function myWorkBulkToggleButton() {
        const ids = myWorkVisibleJobIdsForExpandToggle();
        const expanded = myWorkAllVisibleJobsExpanded();
        const label = expanded ? 'Collapse all jobs' : 'Expand all jobs';
        const icon = expanded
            ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 17 5-5 5 5"/><path d="m7 12 5-5 5 5"/></svg>'
            : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 7 5 5 5-5"/><path d="m7 12 5 5 5-5"/></svg>';
        return `<button type="button" class="ops-clear flow-bulk-icon-btn" id="myWorkExpandAll" title="${label}" aria-label="${label}" ${ids.length ? '' : 'disabled'}>${icon}</button>`;
    }

    function myWorkOpenTaskCount() {
        return myWorkBaseTasks().filter((task) => !taskIsCompleted(task)).length;
    }

    function myWorkHasTaskScopedFilter() {
        return Boolean(
            myWorkFilters.assignee
            || myWorkFilters.status
            || myWorkFilters.priority
            || myWorkFilters.due
            || (myWorkQuickFilter && myWorkQuickFilter !== 'all')
        );
    }

    function myWorkFilteredJobs(filteredTasks = null) {
        const taskList = Array.isArray(filteredTasks) ? filteredTasks : myWorkFilteredTasks();
        let list = myWorkBaseJobs();
        const matchingTaskJobs = new Set();
        taskList.forEach((task) => {
            if (task?.job) matchingTaskJobs.add(String(task.job));
            if (task?.jobDbId) matchingTaskJobs.add(`db:${Number(task.jobDbId)}`);
        });
        const matchesTaskJob = (job) => matchingTaskJobs.has(String(job?.id)) || matchingTaskJobs.has(`db:${Number(job?.dbId)}`);

        if (myWorkFilters.job) list = list.filter((job) => String(job.id) === String(myWorkFilters.job));
        if (myWorkFilters.client) list = list.filter((job) => String(job.client) === String(myWorkFilters.client));

        const search = myWorkSearchValue.trim().toLowerCase();
        if (search) {
            list = list.filter((job) => {
                const directHaystack = [job?.id, job?.order, job?.title, job?.product, job?.owner, job?.coordinator, phaseNameFor(job), clientName(job?.client)].join(' ').toLowerCase();
                return directHaystack.includes(search) || matchesTaskJob(job);
            });
        }

        if (myWorkHasTaskScopedFilter()) list = list.filter(matchesTaskJob);

        return list.slice().sort((left, right) => String(left?.delivery || '9999-12-31').localeCompare(String(right?.delivery || '9999-12-31')) || String(left?.id || '').localeCompare(String(right?.id || '')));
    }

    function myWorkFilteredTasks() {
        let list = myWorkBaseTasks();
        const date = summaryMetrics.asOf?.date || metricDateKey();
        const weekStart = summaryMetrics.asOf?.weekStart || metricDateKey(new Date(new Date().setDate(new Date().getDate() - new Date().getDay() + 1)));
        const weekEnd = summaryMetrics.asOf?.weekEnd || metricDateKey(new Date(new Date().setDate(new Date().getDate() - new Date().getDay() + 7)));
        const search = myWorkSearchValue.trim().toLowerCase();
        if (search) {
            list = list.filter((task) => {
                const job = boardJobForTask(task);
                const haystack = [task.id, task.title, task.job, task.phase, task.assignee, task.priority, task.status, job?.title, clientName(job?.client)].join(' ').toLowerCase();
                return haystack.includes(search);
            });
        }
        if (myWorkFilters.job) list = list.filter((task) => String(task.job) === String(myWorkFilters.job));
        if (myWorkFilters.client) list = list.filter((task) => String(boardJobForTask(task)?.client) === String(myWorkFilters.client));
        if (myWorkFilters.assignee) {
            const selectedAssignee = boardAssigneeFilterUsers().find((user) => String(user?.id || user?.name || '') === String(myWorkFilters.assignee));
            const selectedId = selectedAssignee?.id || myWorkFilters.assignee;
            const selectedName = selectedAssignee?.name || myWorkFilters.assignee;
            list = list.filter((task) => boardTaskMatchesAssignee(task, selectedId, selectedName));
        }
        if (myWorkFilters.status) list = list.filter((task) => boardTaskColumnKey(task) === myWorkFilters.status || String(task.statusId) === String(myWorkFilters.status));
        if (myWorkFilters.priority) list = list.filter((task) => String(task.priorityId || task.priority) === String(myWorkFilters.priority));
        if (myWorkFilters.due === 'overdue') list = list.filter((task) => boardDaysOverdue(task.due) > 0 && boardTaskStatusBucket(task) !== 'done');
        if (myWorkFilters.due === 'today') list = list.filter((task) => boardDateOnly(task.due) === today);
        if (myWorkFilters.due === 'week') list = list.filter((task) => {
            const days = boardDaysUntil(task.due);
            return days !== null && days >= 0 && days <= 7;
        });
        if (myWorkQuickFilter === 'open') list = list.filter((task) => !taskIsCompleted(task));
        if (myWorkQuickFilter === 'overdue') list = list.filter((task) => boardDaysOverdue(task.due) > 0 && boardTaskStatusBucket(task) !== 'done');
        if (myWorkQuickFilter === 'week') list = list.filter((task) => { const days = boardDaysUntil(task.due); return days !== null && days >= 0 && days <= 7; });
        if (myWorkQuickFilter === 'blocked') list = list.filter((task) => /BLOCK|ATTENTION|HOLD/.test(normalizedStatusValue(task.status || '')) || task.attention || task.blockerReason);
        if (myWorkQuickFilter === 'waiting') list = list.filter((task) => boardTaskStatusBucket(task) === 'waiting');
        if (myWorkQuickFilter === 'done') list = list.filter(taskIsCompleted);
        if (myWorkQuickFilter === 'done-week') list = list.filter((task) => taskIsCompleted(task) && task.completedAt && task.completedAt.slice(0, 10) >= weekStart && task.completedAt.slice(0, 10) <= weekEnd);
        if (myWorkQuickFilter === 'upcoming') list = list.filter((task) => !taskIsCompleted(task) && task.due && task.due > date);
        return list;
    }

    function myWorkToolbar(filteredCount, filteredJobCount) {
        const ownTasks = myWorkBaseTasks();
        const ownJobs = myWorkBaseJobs();
        const jobOptions = boardSelectOptions(ownJobs, (job) => job.id, (job) => `${job.id} · ${clientName(job.client)}`, myWorkFilters.job, 'Job');
        const clientIds = new Set(ownJobs.map((job) => String(job.client)).filter(Boolean));
        const clientOptions = boardSelectOptions(
            boardClientFilterRecords().filter((client) => clientIds.has(String(client.id))),
            (client) => client.id,
            (client) => client.name,
            myWorkFilters.client,
            'Client',
        );
        const assigneeOptions = boardSelectOptions(
            boardAssigneeFilterUsers().filter((user) => ownTasks.some((task) => boardTaskMatchesAssignee(task, user.id || user.name, user.name))),
            (user) => user.id || user.name,
            (user) => user.name,
            myWorkFilters.assignee,
            'Assignee'
        );
        const priorityOptions = boardSelectOptions(masterData.priorities || [], (priority) => priority.id || priority.name, (priority) => priority.name, myWorkFilters.priority, 'Priority');
        const serverCounts = summaryMetrics.myWork || {};
        const quickCounts = {
            open: Number.isFinite(Number(serverCounts.open)) ? Number(serverCounts.open) : myWorkOpenTaskCount(),
            overdue: Number.isFinite(Number(serverCounts.overdue)) ? Number(serverCounts.overdue) : ownTasks.filter((task) => boardDaysOverdue(task.due) > 0 && boardTaskStatusBucket(task) !== 'done').length,
            week: Number.isFinite(Number(serverCounts.dueThisWeek)) ? Number(serverCounts.dueThisWeek) : ownTasks.filter((task) => { const days = boardDaysUntil(task.due); return days !== null && days >= 0 && days <= 7; }).length,
            blocked: Number.isFinite(Number(serverCounts.blockedAttention)) ? Number(serverCounts.blockedAttention) : ownTasks.filter((task) => /BLOCK|ATTENTION|HOLD/.test(normalizedStatusValue(task.status || '')) || task.attention || task.blockerReason).length,
            waiting: Number.isFinite(Number(serverCounts.waitingReview)) ? Number(serverCounts.waitingReview) : ownTasks.filter((task) => boardTaskStatusBucket(task) === 'waiting').length,
            done: Number.isFinite(Number(serverCounts.completed)) ? Number(serverCounts.completed) : ownTasks.filter(taskIsCompleted).length,
        };
        return `<div class="ops-toolbar my-work-toolbar">
            <label class="ops-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg><input id="myWorkBoardSearch" value="${html(myWorkSearchValue)}" placeholder="${myWorkIsAdministrativeView() ? 'Search jobs, tasks, clients or assignees' : 'Search my assigned tasks, job ID or client'}"></label>
            <select class="ops-filter" data-mywork-filter="job">${jobOptions}</select>
            <select class="ops-filter" data-mywork-filter="client">${clientOptions}</select>
            <select class="ops-filter" data-mywork-filter="assignee">${assigneeOptions}</select>
            <select class="ops-filter" data-mywork-filter="status"><option value="">Status</option>${boardColumnDefinitions().map((column) => `<option value="${column.key}" ${myWorkFilters.status === column.key ? 'selected' : ''}>${column.label}</option>`).join('')}</select>
            <select class="ops-filter" data-mywork-filter="priority">${priorityOptions}</select>
            <select class="ops-filter" data-mywork-filter="due"><option value="" ${!myWorkFilters.due ? 'selected' : ''}>Due date</option><option value="overdue" ${myWorkFilters.due === 'overdue' ? 'selected' : ''}>Overdue</option><option value="today" ${myWorkFilters.due === 'today' ? 'selected' : ''}>Due today</option><option value="week" ${myWorkFilters.due === 'week' ? 'selected' : ''}>Due this week</option></select>
            <button type="button" class="ops-clear" id="myWorkBoardClear">Clear</button>
        </div>
        <div class="ops-quick-row"><div class="ops-quick-left"><span class="ops-quick-label">Quick filters</span>${[
            ['open', myWorkIsAdministrativeView() ? 'Open' : 'My open', quickCounts.open, ''],
            ['overdue', 'Overdue', quickCounts.overdue, 'danger'],
            ['week', 'Due this week', quickCounts.week, ''],
            ['blocked', 'Blocked', quickCounts.blocked, 'danger'],
            ['waiting', 'Waiting external', quickCounts.waiting, ''],
            ['done', 'Completed', quickCounts.done, ''],
        ].map(([key, label, count, tone]) => `<button type="button" class="ops-chip ${tone} ${myWorkQuickFilter === key ? 'active' : ''}" data-mywork-quick="${key}">${label} <span class="ops-count">${count}</span></button>`).join('')}</div><div class="ops-quick-right">${myWorkBulkToggleButton()}</div></div>`;
    }

    function myWorkStatusSummary(filteredCount, filteredJobCount) {
        const taskLabel = myWorkIsAdministrativeView() ? 'visible task' : 'assigned task';
        const unfiltered = !myWorkSearchValue.trim()
            && !myWorkFilters.job
            && !myWorkFilters.client
            && !myWorkFilters.assignee
            && !myWorkFilters.status
            && !myWorkFilters.priority
            && !myWorkFilters.due
            && (!myWorkQuickFilter || myWorkQuickFilter === 'all');
        const totalVisible = Number(summaryMetrics.myWork?.totalVisible);
        const totalSuffix = unfiltered && Number.isFinite(totalVisible) && totalVisible > filteredCount
            ? ` of <b>${totalVisible}</b>`
            : '';
        return `<div class="ops-status-summary my-work-footer-summary"><span>Showing <b>${filteredCount}</b>${totalSuffix} ${taskLabel}${(Number.isFinite(totalVisible) ? totalVisible : filteredCount) === 1 ? '' : 's'} across <b>${filteredJobCount}</b> ${filteredJobCount === 1 ? 'job' : 'jobs'}</span></div>`;
    }

    function renderMyWorkBoard() {
        const area = $('#myWorkKanbanArea');
        if (!area) return;
        const taskList = myWorkFilteredTasks();
        const jobList = myWorkFilteredJobs(taskList);
        area.innerHTML = renderOperationsTaskBoard(taskList, jobList);
        let dragged = null;
        $$('#myWorkKanbanArea .ops-task-card').forEach((card) => {
            card.addEventListener('dragstart', (event) => {
                if (card.dataset.task && card.dataset.taskUpdatable === '0') {
                    event.preventDefault();
                    showToast('Only the assigned user, an Administrator, or the Super Admin can update this Task.');
                    return;
                }
                dragged = card;
                card.classList.add('dragging');
            });
            card.addEventListener('dragend', () => {
                card.classList.remove('dragging');
                dragged = null;
            });
        });
        $$('#myWorkKanbanArea .ops-task-cell').forEach((cell) => {
            cell.addEventListener('dragover', (event) => {
                event.preventDefault();
                cell.classList.add('drop-hover');
            });
            cell.addEventListener('dragleave', () => cell.classList.remove('drop-hover'));
            cell.addEventListener('drop', async () => {
                cell.classList.remove('drop-hover');
                if (!dragged?.dataset.task) return;
                try {
                    const task = flowtrackTaskFromActiveSurface(dragged.dataset.task);
                    if (!task) throw new Error('The selected task is no longer available.');
                    if (!taskAssignedToCurrentUser(task) && !(isFlowAdmin || isFlowSuperAdmin)) throw new Error('This Task is not assigned to your account.');
                    const targetStatus = boardTargetStatusForColumn(cell.dataset.dropColumn);
                    if (!targetStatus) throw new Error('The selected task status is not available in this workspace.');
                    if (taskStatusMatches(targetStatus, ['BLK', 'ATTN', 'BLOCKED', 'ATTENTION', 'HOLD', 'ON HOLD'])) {
                        showToast('Open the task and add a blocker reason before marking it blocked.');
                        openTask(task.id);
                        return;
                    }
                    await persistTask(task, targetStatus);
                    renderMyWorkBoard();
                    updateBadges();
                    showToast('Task status updated.');
                } catch (error) {
                    showToast(error.message);
                    renderMyWorkBoard();
                }
            });
        });
        $$('#myWorkKanbanArea [data-board-collapse]').forEach((button) => button.addEventListener('click', (event) => {
            event.stopPropagation();
            const id = button.dataset.boardCollapse;
            if (collapsedBoardJobs.has(id)) collapsedBoardJobs.delete(id); else collapsedBoardJobs.add(id);
            renderMyWorkBoard();
        }));
        $$('#myWorkKanbanArea [data-board-card-menu]').forEach((button) => button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const currentMenu = button.closest('.ops-board-card-menu-wrap')?.querySelector('.ops-board-card-action-menu');
            $$('#myWorkKanbanArea .ops-board-card-action-menu').forEach((menu) => {
                if (menu !== currentMenu) menu.classList.add('hidden');
            });
            currentMenu?.classList.toggle('hidden');
            button.setAttribute('aria-expanded', currentMenu?.classList.contains('hidden') ? 'false' : 'true');
        }));
        $$('#myWorkKanbanArea [data-board-row-menu]').forEach((button) => button.addEventListener('click', (event) => {
            event.stopPropagation();
            showToast('Open the Job to manage its actions.');
        }));
        $$('#myWorkKanbanArea [data-board-delete-task]').forEach((button) => button.addEventListener('click', async (event) => {
            event.preventDefault();
            event.stopPropagation();
            const taskCode = button.dataset.boardDeleteTaskCode || 'this Task';
            if (!window.confirm(`Delete ${taskCode}?`)) return;
            button.disabled = true;
            try {
                const result = await api(`/flowtrack/template-api/tasks/${button.dataset.boardDeleteTask}`, { method: 'DELETE' });
                if (result.job) {
                    const jobIndex = jobs.findIndex((item) => Number(item.dbId) === Number(result.job.dbId));
                    if (jobIndex >= 0) jobs.splice(jobIndex, 1, result.job);
                    if (Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
                }
                flowtrackRemoveRecord(tasks, result.deletedTask || { dbId: Number(button.dataset.boardDeleteTask) });
                renderMyWork();
                updateBadges();
                showToast(result.message || 'Task deleted successfully.');
            } catch (error) {
                button.disabled = false;
                showToast(error.message);
            }
        }));
        bindBoardDueDatePickers('#myWorkKanbanArea', renderMyWorkBoard);
        bindCommon();
    }

    function flowtrackFitWorkSurfacesToViewport() {
        const desktop = window.matchMedia ? window.matchMedia('(min-width: 901px)').matches : window.innerWidth >= 901;
        const fit = (surface, footer, minimum = 300) => {
            if (!surface) return;
            if (!desktop) {
                surface.style.removeProperty('height');
                surface.style.removeProperty('max-height');
                surface.style.removeProperty('min-height');
                return;
            }
            const top = surface.getBoundingClientRect().top;
            const footerHeight = footer?.getBoundingClientRect().height || 0;
            const available = Math.max(minimum, Math.floor(window.innerHeight - top - footerHeight - 18));
            surface.style.setProperty('height', `${available}px`, 'important');
            surface.style.setProperty('max-height', `${available}px`, 'important');
            surface.style.setProperty('min-height', `${Math.min(minimum, available)}px`, 'important');
        };
        fit($('#page-mywork #myWorkKanbanArea .ops-board-canvas'), $('#page-mywork .my-work-footer-summary'), 300);
        fit($('#page-jobs .jobs-v2-table'), $('#page-jobs .jobs-v2-pagination'), 300);
    }

    if (!window.__flowtrackWorkSurfaceResizeBound) {
        window.__flowtrackWorkSurfaceResizeBound = true;
        let fitTimer = null;
        window.addEventListener('resize', () => {
            clearTimeout(fitTimer);
            fitTimer = setTimeout(flowtrackFitWorkSurfacesToViewport, 80);
        }, { passive: true });
    }

    renderMyWork = function () {
        const filteredTasks = myWorkFilteredTasks();
        const filteredJobs = myWorkFilteredJobs(filteredTasks);
        const filteredJobCount = filteredJobs.length;
        const myWorkSubtitle = myWorkIsAdministrativeView() ? 'All visible jobs and tasks across the workspace' : 'All tasks assigned to you across jobs';
        $('#page-mywork').innerHTML = `<div class="ops-board-page ops-mode-tasks my-work-board"><div class="page-head"><div><h1>My Work</h1><p>${myWorkSubtitle}</p></div><div class="ops-board-actions"><button type="button" class="primary" data-go="jobs">Jobs</button></div></div>
        ${myWorkToolbar(filteredTasks.length, filteredJobCount)}
        <div id="myWorkKanbanArea"></div>
        ${myWorkStatusSummary(filteredTasks.length, filteredJobCount)}</div>`;

        renderMyWorkBoard();
        requestAnimationFrame(flowtrackFitWorkSurfacesToViewport);
        $('#myWorkBoardSearch')?.addEventListener('input', (event) => {
            myWorkSearchValue = event.currentTarget.value;
            // Keep the search/filter toolbar mounted while filtering the board.
            renderMyWorkBoard();
            const filteredTasks = myWorkFilteredTasks();
            const filteredJobs = myWorkFilteredJobs(filteredTasks);
            const footer = $('#page-mywork .my-work-footer-summary');
            if (footer) footer.outerHTML = myWorkStatusSummary(filteredTasks.length, filteredJobs.length);
            requestAnimationFrame(flowtrackFitWorkSurfacesToViewport);
        });
        $$('[data-mywork-filter]').forEach((select) => select.addEventListener('change', () => { myWorkFilters[select.dataset.myworkFilter] = select.value; renderMyWork(); }));
        $$('[data-mywork-quick]').forEach((button) => button.addEventListener('click', () => { myWorkQuickFilter = myWorkQuickFilter === button.dataset.myworkQuick ? 'all' : button.dataset.myworkQuick; renderMyWork(); }));
        $('#myWorkExpandAll')?.addEventListener('click', () => {
            const ids = myWorkVisibleJobIdsForExpandToggle();
            const allOpen = ids.length && ids.every((id) => !collapsedBoardJobs.has(id));
            ids.forEach((id) => {
                if (allOpen) collapsedBoardJobs.add(id);
                else collapsedBoardJobs.delete(id);
            });
            renderMyWork();
        });
        $('#myWorkBoardClear')?.addEventListener('click', () => { myWorkSearchValue = ''; myWorkQuickFilter = 'all'; myWorkFilters = { job: '', client: '', assignee: '', status: '', priority: '', due: '' }; renderMyWork(); });
        bindCommon();
        applyActionPermissions();
    };

    renderReports = function () {
        const report = summaryMetrics.reports || {};
        const activeJobs = jobs.filter((job) => !jobIsCompleted(job));
        const phaseMap = new Map();
        activeJobs.forEach((job) => {
            const label = phaseShortFor(job) || phaseNameFor(job) || 'Unassigned';
            phaseMap.set(label, (phaseMap.get(label) || 0) + 1);
        });
        const phaseData = flowtrackToArray(phaseMap).map(([label, value]) => ({ label, value }));
        const workloadMap = new Map();
        tasks.filter((task) => !taskIsCompleted(task)).forEach((task) => workloadMap.set(task.assignee || 'Unassigned', (workloadMap.get(task.assignee || 'Unassigned') || 0) + 1));
        const workload = flowtrackToArray(workloadMap).map(([label, value]) => ({ label, value })).sort((a, b) => b.value - a.value).slice(0, 10);
        const maxPhase = Math.max(1, ...phaseData.map((item) => item.value));
        const maxWorkload = Math.max(1, ...workload.map((item) => item.value));
        const ageing = Array.isArray(report.ageing) ? report.ageing : [];
        const maxAgeing = Math.max(1, ...ageing.map((item) => Number(item.value)));
        const receivables = report.financialVisible === false ? 'Restricted' : currency(report.openReceivables || 0);

        $('#page-reports').innerHTML = `<div class="page-head"><div><h1>Reports</h1><p>Operational performance, conversion, workload and cash collection</p></div><div class="actions"><button class="ghost" id="reportPdf">Print / PDF</button><button class="primary" id="reportExcel">Export CSV</button></div></div>
        <div class="metrics">${metric('Quotation Conversion', `${Number(report.quotationConversion || 0)}%`, 'Visible Jobs', '')}${metric('On-time Jobs', `${Number(report.onTimeJobs || 0)}%`, 'Completed deliveries', '')}${metric('Task Completion', `${Number(report.taskCompletion || 0)}%`, 'Within target date', '')}${metric('Avg. Artwork Cycle', `${Number(report.artworkCycle || 0)}d`, 'Request to completion', '')}${metric('Shipment On Time', `${Number(report.shipmentOnTime || 0)}%`, 'Completed shipment phases', '')}${metric('Open Receivables', receivables, report.financialVisible === false ? 'Financial access required' : 'Across visible clients', '')}</div>
        <div class="report-grid"><div class="card section-card"><div class="section-head"><h3>Active Jobs by Phase</h3><span class="small muted">Current portfolio</span></div><div class="bars">${phaseData.map((item) => barRow(item.label, item.value, maxPhase)).join('') || '<div class="empty-state">No active Jobs.</div>'}</div></div>
        <div class="card section-card"><div class="section-head"><h3>Team Workload</h3><span class="small muted">Open Tasks</span></div><div class="bars">${workload.map((item) => barRow(item.label, item.value, maxWorkload)).join('') || '<div class="empty-state">No open Tasks.</div>'}</div></div>
        <div class="card section-card"><div class="section-head"><h3>Delivery Performance</h3></div><div class="kpi-list"><div class="kpi"><b>${Number(report.deliveredThisMonth || 0)}</b><span>Delivered this month</span></div><div class="kpi"><b>${Number(report.deliveredOnTimeThisMonth || 0)}</b><span>Delivered on time</span></div><div class="kpi"><b>${Number(report.supplierDelays || 0)}</b><span>Supplier delays</span></div><div class="kpi"><b>${Number(report.clientDependencies || 0)}</b><span>Client dependencies</span></div></div></div>
        <div class="card section-card"><div class="section-head"><h3>Receivable Ageing</h3></div><div class="bars">${report.financialVisible === false ? '<div class="empty-state">Financial access is restricted.</div>' : ageing.map((item) => barRow(item.label, Number(item.value), maxAgeing, currency(item.value))).join('') || '<div class="empty-state">No open receivables.</div>'}</div></div></div>`;
        $('#reportPdf')?.addEventListener('click', () => window.print());
        $('#reportExcel')?.addEventListener('click', () => {
            const rows = [['Metric', 'Value'], ['Quotation Conversion', `${report.quotationConversion || 0}%`], ['On-time Jobs', `${report.onTimeJobs || 0}%`], ['Task Completion', `${report.taskCompletion || 0}%`], ['Average Artwork Cycle', `${report.artworkCycle || 0} days`], ['Shipment On Time', `${report.shipmentOnTime || 0}%`], ['Open Receivables', report.openReceivables ?? 'Restricted']];
            const csv = rows.map((row) => row.map((value) => `"${String(value).replaceAll('"', '""')}"`).join(',')).join('\n');
            const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
            const link = document.createElement('a'); link.href = url; link.download = `flowtrack-report-${metricDateKey()}.csv`; link.click(); URL.revokeObjectURL(url);
        });
    };

    clientCards = function (items) {
        return items.map((client) => {
            const clientJobs = jobs.filter((job) => Number(job.client) === Number(client.id));
            const activeJobs = Number(client.active_jobs_count ?? client.jobs ?? clientJobs.filter((job) => !jobIsCompleted(job)).length);
            const attention = Number(client.attention_jobs_count ?? clientJobs.filter((job) => !jobIsCompleted(job) && (job.attention || ['needs attention', 'at risk', 'delayed', 'blocked'].includes(String(job.health || '').toLowerCase()))).length);
            const outstanding = canSensitive('invoice_amount') ? currency(client.outstanding || 0).replace('$', '') : 'Restricted';
            return `<div class="card client-card" data-client="${client.id}"><div class="client-head"><div class="company-logo">${html(client.code)}</div><div><h3>${html(client.name)}</h3><div class="small muted">${html(client.country || '—')} · ${html(client.contact || 'No contact')}</div></div></div><div class="client-stats"><div class="client-stat"><b>${activeJobs}</b><span>Active Jobs</span></div><div class="client-stat"><b>${attention}</b><span>Attention</span></div><div class="client-stat"><b>${html(outstanding)}</b><span>Outstanding</span></div></div><div class="small muted" style="margin-top:12px">Account manager: ${html(client.manager || 'Unassigned')}</div></div>`;
        }).join('') || '<div class="card empty-state">No clients found.</div>';
    };

    updateBadges = function () {
        const unread = Math.max(0, Number(notificationUnread) || 0);
        if ($('#notificationBadge')) $('#notificationBadge').textContent = unread;
        // The My Work navigation badge represents everything shown by the
        // unfiltered My Work page (open + completed), while the Open quick
        // filter has its own separate count. Keeping these semantics explicit
        // avoids a misleading 33-vs-50 style mismatch on the same screen.
        if ($('#myWorkBadge')) {
            const serverTotal = Number(summaryMetrics.myWork?.totalVisible);
            $('#myWorkBadge').textContent = Number.isFinite(serverTotal) ? serverTotal : myWorkBaseTasks().length;
        }
    };
