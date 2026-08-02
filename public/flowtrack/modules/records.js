/* FlowTrack generated lazy module: records. Source: resources/js/flowtrack/flowtrack-dynamic-source.js. Build: 6254af6ca40e4526. */
/* FlowTrack lazy module boundary: records. */

    function jobDocsFor(job) {
        return (docs || [])
            .filter((documentRecord) => String(documentRecord.job || '') === String(job?.id || ''))
            .slice()
            .sort((left, right) => String(right.date || '').localeCompare(String(left.date || '')) || String(right.id || '').localeCompare(String(left.id || '')));
    }

    function documentMatchesKeywords(documentRecord, keywords) {
        const haystack = normalizedStatusValue([
            documentRecord.type,
            documentRecord.name,
            documentRecord.phase,
            documentRecord.taskRequirement,
            documentRecord.categoryName,
            documentRecord.version,
        ].join(' '));
        return keywords.some((keyword) => haystack.includes(normalizedStatusValue(keyword)));
    }

    function currentDocumentCardsHtml(documentRows, emptyLabel) {
        if (!documentRows.length) return `<div class="job-doc-empty">${html(emptyLabel || 'No documents uploaded yet.')}</div>`;
        const seen = new Set();
        const rows = documentRows.filter((documentRecord) => {
            const key = String(documentRecord.dbId || documentRecord.id || documentRecord.name || Math.random());
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        });
        return `<div class="job-doc-list">${rows.map((documentRecord) => {
            const preview = documentRecord.previewUrl ? `<a class="mini-btn" href="${html(documentRecord.previewUrl)}" target="_blank" rel="noopener" onclick="event.stopPropagation()">Preview</a>` : '';
            const download = documentRecord.downloadUrl ? `<a class="mini-btn" href="${html(documentRecord.downloadUrl)}" onclick="event.stopPropagation()">Download</a>` : '';
            return `<div class="job-doc-row" data-doc="${html(documentRecord.id)}"><div class="doc-icon" style="width:40px;height:40px">FILE</div><div class="job-doc-main"><b>${html(documentRecord.name || 'Document')}</b><div class="job-doc-meta">${html(documentRecord.type || 'Document')} · ${html(documentRecord.version || 'Current')} · ${documentRecord.date ? formatDate(documentRecord.date) : 'No date'} · ${html(documentRecord.uploadedBy || 'System')}</div>${documentRecord.phase ? `<div class="job-doc-meta">${html(documentRecord.phase)}${documentRecord.taskRequirement ? ` · ${html(documentRecord.taskRequirement)}` : ''}</div>` : ''}</div><div class="job-doc-actions">${documentRecord.final ? '<span class="badge b-green">Final</span>' : '<span class="badge b-gray">Current</span>'}${preview}${download}</div></div>`;
        }).join('')}</div>`;
    }

    function documentPanelFor(job, label, keywords) {
        const matching = jobDocsFor(job).filter((documentRecord) => documentMatchesKeywords(documentRecord, keywords));
        return `<div class="section-head"><div><h3>${html(label)}</h3><div class="job-detail-doc-summary">Showing the latest uploaded version for each ${html(label.toLowerCase())} document linked to this Job.</div></div><button class="primary" data-upload-job="${html(job.id)}">＋ Upload</button></div>${currentDocumentCardsHtml(matching, `No ${label.toLowerCase()} document has been uploaded for this Job yet.`)}`;
    }

    function jobCurrentPhaseTasks(job, jobTasks) {
        const current = (typeof boardJobPhaseTasks === 'function') ? boardJobPhaseTasks(job) : [];
        if (current.length) return current;
        const currentPhaseName = normalizedStatusValue(phaseNameFor(job));
        return jobTasks.filter((task) => Number(task.phaseId) === Number(job.phaseId) || normalizedStatusValue(task.phase || '') === currentPhaseName);
    }

    function flowtrackFindJob(jobId) {
        return jobs.find((item) => String(item.id) === String(jobId) || Number(item.dbId) === Number(jobId));
    }

    function flowtrackActivePhases(job) {
        const workflow = getWorkflow(job?.workflow || 'standard') || { phases: [] };
        const excludedPhaseIds = new Set((job?.excludedPhaseIds || []).map((phaseId) => Number(phaseId)));
        return (workflow.phases || [])
            .filter((phase) => phase.active !== false && !excludedPhaseIds.has(Number(phase.id)))
            .slice()
            .sort((left, right) => Number(left.sequence || 0) - Number(right.sequence || 0));
    }

    function flowtrackPhaseFor(job, phaseId) {
        const activePhases = flowtrackActivePhases(job);
        return activePhases.find((phase) => Number(phase.id) === Number(phaseId))
            || activePhases.find((phase) => Number(phase.sequence) === Number(phaseId))
            || activePhases[0]
            || null;
    }

    function flowtrackTasksForJob(job) {
        return flowtrackVisibleTaskRows(tasks).filter((task) => String(task.job) === String(job?.id || '') || Number(task.jobDbId) === Number(job?.dbId || 0));
    }

    function flowtrackTasksForPhase(job, phaseId, sourceTasks = null) {
        const phase = flowtrackPhaseFor(job, phaseId);
        const all = Array.isArray(sourceTasks) ? sourceTasks : flowtrackTasksForJob(job);
        if (!phase) return all;
        const phaseName = normalizedStatusValue(phase.name || phase.short || '');
        return all.filter((task) => Number(task.phaseId) === Number(phase.id) || normalizedStatusValue(task.phase || '') === phaseName);
    }

    function flowtrackFormatDateTime(value) {
        if (!value) return 'Not recorded';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return String(value);
        return date.toLocaleString([], { year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' });
    }

    function flowtrackPhaseHistoryFor(job, phase) {
        const rows = Array.isArray(job?.phaseHistory) ? job.phaseHistory : [];
        return rows.find((row) => Number(row.phaseId) === Number(phase?.id))
            || rows.find((row) => Number(row.phaseIndex) === Math.max(0, Number(phase?.sequence || 1) - 1))
            || null;
    }

    function flowtrackPhaseTimelineStep(job, phase, index, currentPhase, skippedIndexes) {
        const workflow = getWorkflow(job?.workflow || 'standard') || { phases: [] };
        const originalIndex = (workflow.phases || []).findIndex((item) => Number(item.id) === Number(phase.id));
        const skippedPhase = skippedIndexes.includes(originalIndex);
        const beforeCurrent = currentPhase ? Number(phase.sequence) < Number(currentPhase.sequence) : index < Number(job?.phase || 0);
        const isCurrent = Number(phase.id) === Number(job?.phaseId) || (!job?.phaseId && index === Number(job?.phase || 0));
        const state = skippedPhase ? 'skipped' : beforeCurrent ? 'done' : isCurrent ? 'current' : '';
        const dot = skippedPhase ? '↷' : beforeCurrent ? '✓' : Number(phase.sequence || index + 1);
        return `<button type="button" class="step workflow-phase-step ${state}" data-flow-phase="${html(phase.id)}" data-flow-job="${html(job?.id || '')}" title="View ${html(phase.name)} task history"><div class="step-dot">${dot}</div><div class="step-label">${html(phase.name)}${skippedPhase ? '<br><small>Skipped / external</small>' : ''}</div></button>`;
    }

    function flowtrackRenderPhaseActivityEvent(task, entry) {
        const occurredAt = entry.occurredAt ? flowtrackFormatDateTime(entry.occurredAt) : html(entry.time || 'Not recorded');
        const changes = Array.isArray(entry.changes) ? entry.changes : [];
        const changeRows = changes.length
            ? `<ul class="task-change-list phase-change-list">${changes.map((change) => `<li><b>${html(change.field || 'Update')}:</b> ${html(change.from || '—')} → ${html(change.to || '—')}</li>`).join('')}</ul>`
            : '';
        const fileRow = entry.fileName
            ? `<div class="phase-event-detail"><span>Uploaded file</span><b>${html(entry.fileName)}</b></div>`
            : '';
        const commentRow = entry.commentAdded
            ? '<div class="phase-event-detail"><span>Comment</span><b>Comment added with this update</b></div>'
            : '';
        const requirementRow = entry.documentRequirement
            ? `<div class="phase-event-detail"><span>Document requirement</span><b>${html(entry.documentRequirement)}</b></div>`
            : '';
        const eventLabel = entry.eventLabel || String(entry.event || 'Task update').replace(/[._-]/g, ' ');
        const completionBadge = entry.isCompletionEvent
            ? '<span class="badge b-green">Completion record</span>'
            : `<span class="badge b-gray">${html(eventLabel)}</span>`;

        return `<div class="phase-history-event ${entry.isCompletionEvent ? 'is-completion' : ''}">
            <span class="avatar">${html(entry.actorInitials || userInitials(entry.actor || 'System'))}</span>
            <div class="phase-event-body">
                <div class="phase-event-head"><b>${html(entry.actor || 'System')}</b>${completionBadge}</div>
                <div class="phase-event-description">${html(entry.description || `Updated ${task.id}`)}</div>
                <div class="phase-event-grid">
                    <div class="phase-event-detail"><span>Task</span><b>${html(task.id)} · ${html(task.title || '')}</b></div>
                    <div class="phase-event-detail"><span>Time</span><b>${occurredAt}</b></div>
                    <div class="phase-event-detail"><span>Status now</span><b>${html(task.status || 'Not recorded')}</b></div>
                    ${fileRow}${commentRow}${requirementRow}
                </div>
                ${changeRows}
            </div>
        </div>`;
    }

    function flowtrackRenderPhaseHistoryPanel(job, phaseId, sourceTasks = null) {
        const phase = flowtrackPhaseFor(job, phaseId);
        if (!phase) return '<div class="empty-state">No workflow phase selected.</div>';
        const phaseTasks = flowtrackTasksForPhase(job, phase.id, sourceTasks).map((task) => flowtrackHydrateTaskAssignee(task));
        const history = flowtrackPhaseHistoryFor(job, phase);
        const completedTasks = phaseTasks.filter((task) => taskIsCompleted(task));
        const taskRows = phaseTasks.map((task) => {
            const completed = taskIsCompleted(task);
            const latestCompletion = (task.history || []).find((entry) => entry.isCompletionEvent) || null;
            const assigneeId = flowtrackResolveTaskAssigneeId(task);
            const assigneeName = flowtrackResolveTaskAssigneeName(task, assigneeId);
            const dueValue = task.due || task.dueDate || '';
            const uploadedFile = latestCompletion?.fileName ? ` · Uploaded: ${html(latestCompletion.fileName)}` : '';
            const completedMeta = completed
                ? `${html(task.completedBy || latestCompletion?.actor || assigneeName || 'System')} · ${flowtrackFormatDateTime(task.completedAt || latestCompletion?.occurredAt)}${uploadedFile}`
                : `${html(assigneeName || 'Unassigned')} · ${dueValue ? formatDate(dueValue) : 'No due date'}`;
            return `<div class="phase-history-task-row" data-task="${html(task.id)}"><span class="signal ${completed ? 'green' : task.attention ? 'red' : 'amber'}"></span><div><b>${html(task.title)}</b><div class="small muted" style="margin-top:3px">${completed ? 'Completed by ' : 'Assigned to '}${completedMeta}</div></div>${statusBadge(task.status)}</div>`;
        }).join('');
        const taskChangeRows = phaseTasks.flatMap((task) => (task.history || []).map((entry) => ({ task, entry })))
            .sort((left, right) => String(right.entry.occurredAt || '').localeCompare(String(left.entry.occurredAt || '')))
            .map(({ task, entry }) => flowtrackRenderPhaseActivityEvent(task, entry))
            .join('');
        return `<div class="phase-history-panel" data-current-phase="${html(phase.id)}"><div class="section-head"><div><h3>${html(phase.name)} Task History</h3><div class="small muted" style="margin-top:4px">Click a task to open its full update and change log.</div></div><span class="badge ${history?.status === 'completed' ? 'b-green' : history?.status === 'skipped' ? 'b-amber' : Number(phase.id) === Number(job.phaseId) ? 'b-blue' : 'b-gray'}">${html(history?.statusLabel || (Number(phase.id) === Number(job.phaseId) ? 'Current' : 'Pending'))}</span></div><div class="phase-history-summary"><div><span>Entered</span><b>${flowtrackFormatDateTime(history?.enteredAt)}</b></div><div><span>Completed</span><b>${flowtrackFormatDateTime(history?.completedAt)}</b></div><div><span>Completed Tasks</span><b>${completedTasks.length}/${phaseTasks.length}</b></div><div><span>Last Actor</span><b>${html(history?.changedBy || 'System')}</b></div></div><div class="phase-history-task-list">${taskRows || '<div class="empty-state">No tasks were generated for this phase yet.</div>'}</div><div class="section-head" style="margin-top:16px"><div><h3>Phase Activity</h3><div class="small muted" style="margin-top:4px">Full audit of task updates, completions, document uploads, comments, actor and time for this phase.</div></div></div><div class="phase-history-events">${taskChangeRows || '<div class="empty-state">No task history has been recorded for this phase yet.</div>'}</div></div>`;
    }

    function flowtrackOpenPhaseHistory(jobId, phaseId, root = document) {
        const job = flowtrackFindJob(jobId);
        if (!job) return;
        const scope = root?.closest?.('.drawer-body') || root?.closest?.('.standalone-record-content') || root || document;
        const panel = scope.querySelector('#flowtrackPhaseHistoryPanel');
        if (panel) panel.innerHTML = flowtrackRenderPhaseHistoryPanel(job, phaseId);
        scope.querySelectorAll('[data-flow-phase]').forEach((button) => button.classList.toggle('selected', Number(button.dataset.flowPhase) === Number(phaseId)));
        bindCommon();
    }

    async function flowtrackRefreshJobTasks(job) {
        if (!job?.dbId) return null;
        flowtrackSetLoadingProgress(22, 'Loading latest phase tasks…');
        const result = await api(`/flowtrack/template-api/jobs/${job.dbId}/tasks`);
        flowtrackSetLoadingProgress(72, 'Applying task setup assignments…');
        if (result.job) {
            const jobIndex = jobs.findIndex((item) => Number(item.dbId) === Number(result.job.dbId));
            if (jobIndex >= 0) jobs.splice(jobIndex, 1, result.job);
        }
        if (result.job && Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
        flowtrackFinishLoadingProgress('Phase tasks updated');
        return result;
    }

    function openJobTaskModal(jobId, phaseId = null) {
        const job = flowtrackFindJob(jobId);
        if (!job) return;
        const activePhases = flowtrackActivePhases(job);
        const defaultPhase = flowtrackPhaseFor(job, phaseId || job.phaseId) || activePhases[0];
        const phaseOptions = activePhases.map((phase) => `<option value="${html(phase.id)}" ${Number(phase.id) === Number(defaultPhase?.id) ? 'selected' : ''}>${html(phase.name)}</option>`).join('');
        const priorityOptions = (masterData.priorities || [])
            .filter((priority) => String(priority.status || 'Active').toLowerCase() === 'active')
            .map((priority) => `<option value="${html(priority.id)}" ${Number(priority.id) === Number(job.priorityId) ? 'selected' : ''}>${html(priority.name)}</option>`)
            .join('');
        $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>Add Sub Task</h2><div class="small muted">${html(job.id)} · ${html(job.title || '')}</div></div><button class="close-btn" data-close>×</button></div><div class="modal-body"><div class="form-grid"><div class="field full"><label>Sub task title *</label><input id="newJobTaskTitle" maxlength="255" placeholder="e.g. Verify client artwork version"></div><div class="field full"><label>Description</label><textarea id="newJobTaskDescription" rows="3" maxlength="5000" placeholder="Instruction, expected result, or handover details…"></textarea></div><div class="field"><label>Workflow phase *</label><select id="newJobTaskPhase">${phaseOptions}</select></div><div class="field"><label>Assignee</label><select id="newJobTaskAssignee"><option value="">Unassigned</option>${users.map((user) => `<option value="${html(user.id)}">${html(user.name)}</option>`).join('')}</select></div><div class="field"><label>Priority</label><select id="newJobTaskPriority"><option value="">Use job priority</option>${priorityOptions}</select></div><div class="field"><label>Due date</label><input type="date" id="newJobTaskDueDate"></div><div class="field full"><label>Checklist items</label><textarea id="newJobTaskChecklist" rows="3" placeholder="One checklist item per line"></textarea></div></div></div><div class="modal-foot"><button class="ghost" data-close>Cancel</button><button class="primary" id="createJobSubTask">Create Sub Task</button></div>`;
        openOverlay();
        $('#jobModal').classList.add('open');
        if (window.FlowtrackSearchableDropdown?.enhance) {
            window.FlowtrackSearchableDropdown.enhance($('#jobModal'));
        }
        $$('[data-close]').forEach((button) => button.addEventListener('click', closeAll));
        $('#createJobSubTask')?.addEventListener('click', async () => {
            const title = $('#newJobTaskTitle')?.value.trim();
            if (!title) { showToast('Enter a sub task title.'); $('#newJobTaskTitle')?.focus(); return; }
            const button = $('#createJobSubTask');
            button.disabled = true;
            button.textContent = 'Creating…';
            try {
                const checklist = String($('#newJobTaskChecklist')?.value || '').split('\n').map((line) => line.trim()).filter(Boolean);
                const result = await api(`/flowtrack/template-api/jobs/${job.dbId}/tasks`, {
                    method: 'POST',
                    body: {
                        workflow_phase_id: Number($('#newJobTaskPhase')?.value || defaultPhase?.id || job.phaseId),
                        title,
                        description: $('#newJobTaskDescription')?.value.trim() || null,
                        assignee_id: $('#newJobTaskAssignee')?.value ? Number($('#newJobTaskAssignee').value) : null,
                        priority_id: $('#newJobTaskPriority')?.value ? Number($('#newJobTaskPriority').value) : null,
                        due_date: $('#newJobTaskDueDate')?.value || null,
                        checklist,
                    },
                });
                if (result.job) {
                    const jobIndex = jobs.findIndex((item) => Number(item.dbId) === Number(result.job.dbId));
                    if (jobIndex >= 0) jobs.splice(jobIndex, 1, result.job);
                    if (Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
                    else if (result.task) flowtrackUpsertRecord(tasks, result.task);
                }
                closeAll();
                renderPage(currentPage);
                updateBadges();
                showToast(result.message || 'Sub task created successfully.');
                setTimeout(() => {
                    openJob(result.job?.id || job.id);
                    setTimeout(() => {
                        const page = document.querySelector(`#page-record-job-${flowtrackRecordSlug(result.job?.id || job.id)} .standalone-record-content`);
                        if (page) {
                            activateScopedDetailTab(page, 2);
                            flowtrackOpenPhaseHistory(result.job?.id || job.id, $('#newJobTaskPhase')?.value || defaultPhase?.id, page);
                        }
                    }, 40);
                }, 30);
            } catch (error) {
                showToast(error.message);
            } finally {
                button.disabled = false;
                button.textContent = 'Create Sub Task';
            }
        });
    }

    function compactJobTaskRows(taskRows, emptyLabel = 'No Tasks are configured for this phase yet.') {
        return taskRows.map((task) => `<button type="button" class="attention-item subtask-row-button" data-task="${html(task.id)}"><span class="signal ${task.attention ? 'red' : taskIsCompleted(task) ? 'green' : 'amber'}"></span><div><div class="item-title">${html(task.title)}</div><div class="item-meta">${html(task.assignee || 'Unassigned')} · ${task.due ? formatDate(task.due) : 'No due date'} · ${Number(task.progress || 0)}% · ${html(task.phase || '')}</div></div>${statusBadge(task.status)}</button>`).join('') || `<div class="empty-state">${html(emptyLabel)}</div>`;
    }

    const flowtrackOriginalJobPanels = jobPanels;
    jobPanels = function (j, jt, jd) {
        const wf = getWorkflow(j.workflow || 'standard') || { phases: [], name: 'Workflow' };
        const skipped = j.skippedPhases || [];
        const activity = j.activity || [];
        const currentTasks = jobCurrentPhaseTasks(j, jt || []);
        const openCurrentTasks = currentTasks.filter((task) => !taskIsCompleted(task));
        const activePhases = (wf.phases || []).filter((phase) => phase.active !== false && !(j.excludedPhaseIds || []).map(Number).includes(Number(phase.id))).slice().sort((left, right) => Number(left.sequence || 0) - Number(right.sequence || 0));
        const currentPhase = activePhases.find((phase) => Number(phase.id) === Number(j.phaseId));
        const totalQty = Number(j.qty || jobProductItems(j).reduce((sum, item) => sum + Number(item.qty || 0), 0));
        const allJobDocs = jobDocsFor(j);
        const latestUpdate = activity[0];
        const readiness = j.phaseReadinessDetails || {};
        return `<section id="detailPanel0" class="detail-panel active"><div class="summary-grid"><div class="summary-box"><span>Current Phase</span><b>${html(phaseNameFor(j))}</b></div><div class="summary-box"><span>Next Required Action</span><b>${html(j.next || 'Review current phase')}</b></div><div class="summary-box"><span>Delivery Deadline</span><b class="${j.delivery < today ? 'due overdue' : ''}">${formatDate(j.delivery)}</b></div><div class="summary-box"><span>Workflow Template</span><b>${html(wf.name || 'Workflow')}</b></div><div class="summary-box"><span>Job Started At</span><b>${html(phaseNameFor(j, j.createdFromPhase ?? 0))}</b></div><div class="summary-box"><span>Commercial Value</span><b>${j.price ? currency(j.price, j.currency) : 'Not recorded'}</b></div></div>${j.requestDescription ? `<div class="source-note" style="margin-top:14px"><b>Request description</b><div class="small muted" style="margin-top:5px;white-space:pre-wrap">${safeHtml(j.requestDescription)}</div></div>` : ''}${jobProductSummary(j)}${skipped.length ? `<div class="source-note"><b>Started after earlier phases</b><div class="small muted" style="margin-top:4px">${skipped.length} phase${skipped.length === 1 ? ' was' : 's were'} recorded as “${html(j.startHandling || 'Skipped')}”. ${html(j.startReason || '')}</div></div>` : ''}<div style="margin-top:16px"><div class="section-head"><h3>Overall Progress</h3><b>${Number(j.progress || 0)}%</b></div><div class="progress" style="height:9px"><span class="${Number(j.progress || 0) >= 100 ? 'complete' : ''}" style="width:${Math.max(0, Math.min(100, Number(j.progress || 0)))}%"></span></div></div><div class="card" style="margin-top:16px;padding:13px;background:#f8faff"><b>Latest update</b><div class="muted" style="margin-top:5px">${latestUpdate ? `${html(latestUpdate.title || 'Updated')}${latestUpdate.text ? ` · ${html(latestUpdate.text)}` : ''} · ${html(latestUpdate.time || '')}` : `${html(j.next || 'No update recorded yet.')}`}</div></div><div class="section-head" style="margin-top:18px"><h3>Current Phase Tasks</h3><button class="link-btn" data-open-job-tasks>View all</button></div>${compactJobTaskRows(openCurrentTasks.slice(0, 5), 'No open tasks in the current phase.')}</section>
<section id="detailPanel1" class="detail-panel"><div class="timeline clickable-workflow-timeline">${activePhases.map((phase, index) => flowtrackPhaseTimelineStep(j, phase, index, currentPhase, skipped)).join('') || '<div class="empty-state">No workflow phases configured.</div>'}</div><div id="flowtrackPhaseHistoryPanel" style="margin-top:14px">${flowtrackRenderPhaseHistoryPanel(j, currentPhase?.id || activePhases[0]?.id, jt || [])}</div>${skipped.length ? `<div class="skip-warning"><b>Historical phase handling</b><div style="margin-top:5px">${html(j.startHandling || '')}: ${html(j.startReason || '')}</div></div>` : ''}<div class="card section-card workflow-card-clean" style="margin-top:14px"><div class="section-head"><h3>Current Phase Controls</h3><div><span class="badge b-blue">${html(phasePackFor(j.workflow || 'standard', j.phase) || 'No Task Pack')}</span> <span class="badge ${j.phaseReadiness === 'ready' ? 'b-green' : j.phaseReadiness === 'blocked' ? 'b-red' : 'b-amber'}">${html(j.phaseReadinessLabel || 'In Progress')}</span></div></div><div class="source-note"><b>Phase readiness</b><div class="small muted" style="margin-top:4px">${html((readiness.blockers || []).join(' ') || 'All configured task, document and blocker gates are satisfied.')}</div></div><div class="workflow-readiness-grid"><label class="check-row"><input type="checkbox" disabled ${readiness.task_gate?.met ? 'checked' : ''}> <span>Required tasks completed (${Number(readiness.task_gate?.completed || 0)}/${Number(readiness.task_gate?.required || 0)})</span></label>${readiness.document_gate?.required ? `<label class="check-row"><input type="checkbox" disabled ${readiness.document_gate?.met ? 'checked' : ''}> <span>Required document(s) uploaded</span></label>` : ''}</div><div class="actions standalone-save-actions" style="margin-top:14px"><button class="secondary" data-open-job-tasks>View Phase Tasks</button>${j.phase < (wf.phases || []).length - 1 ? `<button class="primary" data-next-phase="${html(j.id)}" ${j.phaseReadiness === 'ready' ? '' : 'disabled'}>${j.phaseReadiness === 'ready' ? 'Complete Phase' : 'Complete Required Work'}</button>` : '<span class="badge b-green">Workflow completed</span>'}</div></div></section>
<section id="detailPanel2" class="detail-panel"><div class="section-head"><div><h3>Current Phase Tasks</h3><div class="small muted" style="margin-top:4px">${html(currentPhase?.name || phaseNameFor(j) || 'Current phase')}</div></div><button class="primary" data-add-task="${html(j.id)}">＋ Add Task</button></div>${compactJobTaskRows(currentTasks, `No Tasks are configured for ${currentPhase?.name || 'the current phase'} yet.`)}</section>
<section id="detailPanel3" class="detail-panel">${documentPanelFor(j, 'Quotation', ['quotation', 'quote', 'rfq', 'pricing', 'commercial'])}</section>
<section id="detailPanel4" class="detail-panel">${documentPanelFor(j, 'Artwork', ['artwork', 'art proof', 'proof', 'design', 'logo'])}</section>
<section id="detailPanel5" class="detail-panel"><div class="summary-grid"><div class="summary-box"><span>Quantity Ordered</span><b>${totalQty.toLocaleString()} units</b></div><div class="summary-box"><span>Progress</span><b>${Number(j.progress || 0)}%</b></div><div class="summary-box"><span>Current Phase</span><b>${html(phaseNameFor(j))}</b></div></div><div style="margin-top:18px">${documentPanelFor(j, 'Production Documents', ['production', 'inspection', 'quality', 'packing', 'sample', 'proof'])}</div></section>
<section id="detailPanel6" class="detail-panel"><div class="summary-grid"><div class="summary-box"><span>Delivery Deadline</span><b class="${j.delivery < today ? 'due overdue' : ''}">${formatDate(j.delivery)}</b></div><div class="summary-box"><span>Shipment Phase</span><b>${html(phaseNameFor(j))}</b></div><div class="summary-box"><span>Status</span><b>${html(j.status || 'Not started')}</b></div></div><div style="margin-top:18px">${documentPanelFor(j, 'Shipment Documents', ['shipment', 'shipping', 'delivery', 'tracking', 'packing list', 'bill of lading', 'air waybill'])}</div></section>
<section id="detailPanel7" class="detail-panel"><div class="summary-grid"><div class="summary-box"><span>Invoice Amount</span><b>${j.price ? currency(j.price, j.currency) : 'Not recorded'}</b></div><div class="summary-box"><span>Commercial Status</span><b>${html(boardJobCommercialLabel(j))}</b></div><div class="summary-box"><span>Client</span><b>${html(clientName(j.client))}</b></div></div><div style="margin-top:18px">${documentPanelFor(j, 'Invoice Documents', ['invoice', 'payment', 'receipt', 'commercial'])}</div></section>
<section id="detailPanel8" class="detail-panel"><div class="section-head"><div><h3>Documents</h3><div class="job-detail-doc-summary">All files currently linked to this Job.</div></div><button class="primary" data-upload-job="${html(j.id)}">＋ Upload</button></div>${currentDocumentCardsHtml(allJobDocs, 'No documents have been uploaded for this Job yet.')}</section>
<section id="detailPanel9" class="detail-panel">${activity.map((a) => `<div class="comment"><span class="avatar">${userInitials(a.actor || 'System')}</span><div><b>${html(a.title || 'Activity')}</b><div style="margin-top:4px">${html(a.text || '')}</div><div class="small muted" style="margin-top:4px">${html(a.actor || 'System')} · ${html(a.time || '')}</div></div></div>`).join('') || '<div class="empty-state">No Activity recorded yet.</div>'}</section>`;
    };

    function activateScopedDetailTab(root, index) {
        const scope = root?.closest('.drawer-body') || root?.closest('.standalone-record-content') || root || document;
        scope.querySelectorAll('[data-detailtab]').forEach((tab) => tab.classList.toggle('active', String(tab.dataset.detailtab) === String(index)));
        scope.querySelectorAll('.detail-panel').forEach((panel) => panel.classList.toggle('active', panel.id === `detailPanel${index}`));
    }

    function bindStandaloneDetailTabs(content) {
        if (!content) return;
        content.querySelectorAll('[data-detailtab]').forEach((button) => {
            if (button.dataset.scopedStandaloneTab === '1') return;
            button.dataset.scopedStandaloneTab = '1';
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopImmediatePropagation();
                activateScopedDetailTab(button, button.dataset.detailtab);
            }, true);
        });
        content.querySelectorAll('[data-open-job-tasks]').forEach((button) => {
            if (button.dataset.scopedOpenTasks === '1') return;
            button.dataset.scopedOpenTasks = '1';
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopImmediatePropagation();
                activateScopedDetailTab(button, 2);
            }, true);
        });
        content.querySelectorAll('[data-flow-phase]').forEach((button) => {
            if (button.dataset.scopedFlowPhase === '1') return;
            button.dataset.scopedFlowPhase = '1';
            button.addEventListener('click', async (event) => {
                event.preventDefault();
                event.stopImmediatePropagation();
                const job = flowtrackFindJob(button.dataset.flowJob);
                if (job) {
                    const scope = button.closest('.drawer-body') || button.closest('.standalone-record-content') || document;
                    const panel = scope.querySelector('#flowtrackPhaseHistoryPanel');
                    if (panel) panel.innerHTML = flowtrackInlineProgress('Loading selected phase tasks…');
                    try { await flowtrackRefreshJobTasks(job); } catch (error) {}
                    flowtrackOpenPhaseHistory(button.dataset.flowJob, button.dataset.flowPhase, button);
                }
            }, true);
        });
        content.querySelectorAll('[data-add-task]').forEach((button) => {
            if (button.dataset.scopedAddTask === '1') return;
            button.dataset.scopedAddTask = '1';
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopImmediatePropagation();
                openJobTaskModal(button.dataset.addTask, button.dataset.addTaskPhase || null);
            }, true);
        });
    }

    function polishStandaloneContent(content) {
        if (!content) return;
        ['saveTaskUpdate', 'saveTaskDraft', 'saveTaskDueDate', 'createJob', 'saveDraftJob', 'saveClient', 'createClient', 'saveWorkflow', 'saveTaskPack'].forEach((id) => {
            const element = content.querySelector(`#${id}`);
            const parent = element?.closest('.actions,.modal-foot,.task-document-upload');
            if (parent) parent.classList.add('standalone-save-actions');
        });
        const updateButton = content.querySelector('#saveTaskUpdate');
        if (updateButton) updateButton.removeAttribute('style');
    }






    
    /* CSS externalized: flowtrackClickablePhaseStyles. */

    
    /* CSS externalized: standalonePageStyles. */

    let standaloneBackPage = 'dashboard';
    let standaloneActivePageId = null;
    const baseCloseAllForStandalone = closeAll;
    closeAll = function () {
        baseCloseAllForStandalone();
        if (standaloneActivePageId && currentPage === standaloneActivePageId) {
            const destination = firstAllowedPage(standaloneBackPage);
            standaloneActivePageId = null;
            setTimeout(() => navigate(destination), 0);
        }
    };

    function flowtrackCloseModalOnly() {
        // Close an overlay/modal without leaving a standalone Job/Task detail route.
        baseCloseAllForStandalone();
    }

    function standaloneSourcePage(fallback = null) {
        const page = currentPage && !String(currentPage).startsWith('record-') ? currentPage : standaloneBackPage;
        return firstAllowedPage(page || fallback);
    }

    function ensureStandalonePage(pageId) {
        let page = document.getElementById(`page-${pageId}`);
        if (!page) {
            page = document.createElement('section');
            page.id = `page-${pageId}`;
            page.className = 'page standalone-record-host';
            document.querySelector('.content')?.appendChild(page);
        }
        return page;
    }

    function activateStandalonePage(pageId, backPage) {
        const page = ensureStandalonePage(pageId);
        standaloneActivePageId = pageId;
        standaloneBackPage = firstAllowedPage(backPage || standaloneBackPage);
        $$('.page').forEach((section) => section.classList.remove('active'));
        page.classList.add('active');
        $$('[data-page]').forEach((button) => button.classList.toggle('active', button.dataset.page === standaloneBackPage));
        $('#sidebar')?.classList.remove('open');
        $('#sidebarShade')?.classList.remove('open');
        currentPage = pageId;
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return page;
    }

    function standaloneBackButton(label = 'Back') {
        return `<button type="button" class="standalone-back-btn" data-standalone-back>← ${html(label)}</button>`;
    }

    function bindStandaloneBack(page) {
        page.querySelectorAll('[data-standalone-back]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                const destination = firstAllowedPage(standaloneBackPage);
                standaloneActivePageId = null;
                navigate(destination);
            });
        });
        page.querySelectorAll('[data-close]').forEach((button) => {
            button.addEventListener('click', (event) => {
                if (!standaloneActivePageId || currentPage !== standaloneActivePageId) return;
                event.preventDefault();
                event.stopImmediatePropagation();
                const destination = firstAllowedPage(standaloneBackPage);
                standaloneActivePageId = null;
                navigate(destination);
            }, true);
        });
    }

    function moveChildren(from, to) {
        while (from.firstChild) to.appendChild(from.firstChild);
    }

    function renderMovedRecordPage({ pageId, backPage, eyebrow, title, subtitle, sourceSelector, form = false }) {
        const source = document.querySelector(sourceSelector);
        if (!source || !source.childNodes.length) return false;
        document.querySelectorAll('.standalone-record-host').forEach((recordPage) => {
            if (recordPage.id !== `page-${pageId}`) recordPage.innerHTML = '';
        });
        const page = activateStandalonePage(pageId, backPage);
        page.innerHTML = `<div class="standalone-record-page ${form ? 'standalone-record-form' : ''}"><div class="standalone-record-head"><div class="standalone-record-title"><p>${html(eyebrow || 'FlowTrack')}</p><h1>${html(title || 'Details')}</h1>${subtitle ? `<p>${html(subtitle)}</p>` : ''}</div><div class="standalone-record-actions">${standaloneBackButton(`Back to ${backPageLabel(backPage)}`)}</div></div><div class="standalone-record-shell"><div class="standalone-record-content"></div></div></div>`;
        const content = page.querySelector('.standalone-record-content');
        moveChildren(source, content);
        source.classList.remove('open', 'document-job-chooser');
        source.innerHTML = '';
        $('#overlay')?.classList.remove('open');
        bindStandaloneBack(page);
        bindStandaloneDetailTabs(content);
        polishStandaloneContent(content);
        applyActionPermissions();
        window.FlowtrackSearchableDropdown?.enhance(page);
        return true;
    }

    function backPageLabel(page) {
        const map = { dashboard: 'Dashboard', mywork: 'My Work', jobs: 'Jobs', clients: 'Clients', board: 'Board', documents: 'Documents', workflowsetup: 'Workflow Setup', taskpacksetup: 'Task Pack Setup', masterdata: 'Master Data', setup: 'Setup' };
        return map[page] || 'Previous Page';
    }

    function detailSubtitleForJob(job) {
        if (!job) return '';
        return `${job.id}${job.order ? ` · ${job.order}` : ''} · ${clientName(job.client)} · ${phaseNameFor(job)}`;
    }

    function detailSubtitleForTask(task) {
        const job = tasks && task ? jobs.find((item) => item.id === task.job || Number(item.dbId) === Number(task.jobDbId)) : null;
        return `${task?.id || ''}${task?.job ? ` · ${task.job}` : ''}${job ? ` · ${job.title}` : ''}`;
    }

    const drawerJobDetailPage = currentFlowtrackImplementation('openJob');
    const drawerTaskDetailPage = currentFlowtrackImplementation('openTask');
    const drawerClientDetailPage = openClient;
    const modalClientFormPage = openClientModal;
    const modalJobFormPage = openJobModal;
    const modalEditJobFormPage = openEditJobModal;
    const modalWorkflowFormPage = openWorkflowModal;
    const modalTaskPackFormPage = openTaskPackModal;

    registerFlowtrackExtension('openJob', 'openJob-stage-2', function (id) {        const backPage = standaloneSourcePage('jobs');
        const job = jobs.find((item) => String(item.id) === String(id) || Number(item.dbId) === Number(id));
        if (!job) return;
        drawerJobDetailPage(job.id);
        const moved = renderMovedRecordPage({ pageId: `record-job-${String(job.id).replace(/[^a-z0-9]+/gi, '-').toLowerCase()}`, backPage, eyebrow: 'Job Details', title: job.title || job.id, subtitle: detailSubtitleForJob(job), sourceSelector: '#detailDrawer' });
        if (moved) {
            const content = document.querySelector(`#page-record-job-${String(job.id).replace(/[^a-z0-9]+/gi, '-').toLowerCase()} .standalone-record-content`);
            if (content && !content.querySelector('#jobDetailPanels')) {
                const drawerBody = content.querySelector('.drawer-body');
                if (drawerBody) drawerBody.insertAdjacentHTML('beforeend', sanitizeFlowtrackHtml(`<div id="jobDetailPanels">${jobPanels(job, tasks.filter((task) => String(task.job) === String(job.id) || Number(task.jobDbId) === Number(job.dbId)), docs.filter((documentRecord) => String(documentRecord.job) === String(job.id)))}</div>`));
                bindStandaloneDetailTabs(content);
                activateScopedDetailTab(content, 0);
            }
        }
    });

    registerFlowtrackExtension('openTask', 'openTask-stage-2', function (id) {        const backPage = standaloneSourcePage('mywork');
        const task = tasks.find((item) => item.id === id || Number(item.dbId) === Number(id));
        drawerTaskDetailPage(id);
        if (!task) return;
        renderMovedRecordPage({ pageId: `record-task-${String(task.id).replace(/[^a-z0-9]+/gi, '-').toLowerCase()}`, backPage, eyebrow: 'Task Details', title: task.title || task.id, subtitle: detailSubtitleForTask(task), sourceSelector: '#detailDrawer' });
    });

    openClient = function (id) {
        const backPage = standaloneSourcePage('clients');
        const client = clients.find((item) => Number(item.id) === Number(id));
        drawerClientDetailPage(id);
        if (!client) return;
        renderMovedRecordPage({ pageId: `record-client-${client.id}`, backPage, eyebrow: 'Client Details', title: client.name || 'Client', subtitle: client.country || '', sourceSelector: '#detailDrawer' });
    };

    openClientModal = function (existing = null) {
        const backPage = standaloneSourcePage('clients');
        modalClientFormPage(existing);
        renderMovedRecordPage({ pageId: existing ? `record-client-edit-${existing.id}` : 'record-client-new', backPage, eyebrow: existing ? 'Edit Client' : 'Add Client', title: existing ? `Edit ${existing.name}` : 'Add Client', subtitle: 'Client information is saved as a full page, not a drawer.', sourceSelector: '#jobModal', form: true });
    };

    openJobModal = function (prefillClient) {
        const backPage = standaloneSourcePage('jobs');
        modalJobFormPage(prefillClient);
        renderMovedRecordPage({ pageId: 'record-job-new', backPage, eyebrow: 'New Job', title: 'Create New Job', subtitle: 'Enter the job details, products, workflow stage and attachments.', sourceSelector: '#jobModal', form: true });
    };

    openEditJobModal = function (job) {
        if (!job) return;
        const backPage = standaloneSourcePage('jobs');
        modalEditJobFormPage(job);
        renderMovedRecordPage({ pageId: `record-job-edit-${String(job.id).replace(/[^a-z0-9]+/gi, '-').toLowerCase()}`, backPage, eyebrow: 'Edit Job', title: `Edit ${job.title || job.id}`, subtitle: detailSubtitleForJob(job), sourceSelector: '#jobModal', form: true });
    };

    openWorkflowModal = function (existing = null, duplicate = false) {
        const backPage = standaloneSourcePage('workflowsetup');
        modalWorkflowFormPage(existing, duplicate);
        const title = existing && !duplicate ? `Edit ${existing.name}` : duplicate ? `Duplicate ${existing?.name || 'Workflow'}` : 'Create New Workflow';
        renderMovedRecordPage({ pageId: existing && !duplicate ? `record-workflow-edit-${existing.id}` : duplicate ? `record-workflow-copy-${existing?.id || 'new'}` : 'record-workflow-new', backPage, eyebrow: existing && !duplicate ? 'Edit Workflow' : duplicate ? 'Duplicate Workflow' : 'New Workflow', title, subtitle: 'Configure workflow identity on a dedicated page.', sourceSelector: '#jobModal', form: true });
    };

    openTaskPackModal = function (existing = null) {
        const backPage = standaloneSourcePage('taskpacksetup');
        modalTaskPackFormPage(existing);
        const moveWhenReady = () => {
            const moved = renderMovedRecordPage({ pageId: existing ? `record-task-pack-edit-${existing.id}` : 'record-task-pack-new', backPage, eyebrow: existing ? 'Edit Task Pack' : 'Add Task Pack', title: existing ? `Edit ${existing.name}` : 'Add Task Pack', subtitle: 'Build the task sequence on a full page for easier editing.', sourceSelector: '#jobModal', form: true });
            if (moved) {
                const addButton = $('#addTaskPackItem');
                if (addButton && !addButton.dataset.pageScrollBound) {
                    addButton.dataset.pageScrollBound = '1';
                    addButton.addEventListener('click', () => setTimeout(() => window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' }), 30));
                }
            }
            return moved;
        };
        if (!moveWhenReady() && !existing) {
            const start = Date.now();
            const timer = setInterval(() => {
                if (moveWhenReady() || Date.now() - start > 4000) clearInterval(timer);
            }, 50);
        }
    };

    const standaloneRenderJobsBase = currentFlowtrackImplementation('renderJobs');
    registerFlowtrackExtension('renderJobs', 'renderJobs-stage-2', function () {        standaloneRenderJobsBase();
        $('#newJob')?.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopImmediatePropagation();
            openJobModal();
        }, true);
    });

    const permissionObserver = new MutationObserver(() => applyActionPermissions());
    ['.content', '#jobModal', '#detailDrawer'].forEach((selector) => {
        const target = document.querySelector(selector);
        if (target) permissionObserver.observe(target, { childList: true, subtree: true });
    });

    $('#logoutBtn')?.addEventListener('click', (event) => {
        event.preventDefault();
        logout();
    });

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) window.location.reload();
    });


    
    /* CSS externalized: flowtrackFinalPolishStyles. */

    const flowtrackPolishMovedRecordPage = renderMovedRecordPage;
    renderMovedRecordPage = function (options) {
        const moved = flowtrackPolishMovedRecordPage(options);
        if (!moved) return false;
        const page = document.getElementById(`page-${options.pageId}`);
        const content = page?.querySelector('.standalone-record-content');
        if (content) {
            bindStandaloneDetailTabs(content);
            const activeTab = content.querySelector('[data-detailtab].active');
            activateScopedDetailTab(content, activeTab?.dataset.detailtab || 0);
            content.querySelectorAll('.modal-foot,.actions').forEach((actions) => {
                if (actions.querySelector('#saveTaskUpdate,#saveTaskDraft,#saveTaskDueDate,#createJob,#saveDraftJob,#saveEditJob,#saveClient,#createClient,#saveWorkflow,#saveTaskPack')) {
                    actions.classList.add('standalone-save-actions');
                }
            });
        }
        return moved;
    };

    const flowtrackPolishOpenJobModal = openJobModal;
    openJobModal = function (prefillClient) {
        flowtrackPolishOpenJobModal(prefillClient);
        queueMicrotask(() => {
            const owner = document.querySelector('#newJobOwner');
            if (!owner) return;
            const host = owner.closest('.standalone-record-host') || document;
            flowtrackRefreshAssigneeSelect(host, '#newJobOwner', owner.value || currentUser?.id);
        });
    };

    const flowtrackPolishOpenEditJobModal = openEditJobModal;
    openEditJobModal = function (job) {
        flowtrackPolishOpenEditJobModal(job);
        queueMicrotask(() => {
            if (!job || !canFlow('jobs', 'assign')) return;
            ['#editJobOwner', '#editJobCoordinator'].forEach((selector) => {
                const select = document.querySelector(selector);
                if (!select) return;
                const selected = select.value;
                select.innerHTML = flowtrackWorkspaceMembers().map((user) => `<option value="${html(user.id)}" ${String(user.id) === String(selected) ? 'selected' : ''}>${html(user.name)}</option>`).join('');
            });
            window.FlowtrackSearchableDropdown?.enhance(document.querySelector('.standalone-record-host.active') || document);
        });
    };


    
    /* CSS externalized: flowtrackFinalFixStyles. */

    function flowtrackRecordSlug(value) {
        return String(value ?? 'record').replace(/[^a-z0-9]+/gi, '-').toLowerCase();
    }

    function flowtrackClearStandalonePage(pageId) {
        const page = document.getElementById(`page-${pageId}`);
        if (!page) return;
        page.innerHTML = '';
    }

    function flowtrackClearRecordFor(prefix, id) {
        flowtrackClearStandalonePage(`${prefix}-${flowtrackRecordSlug(id)}`);
    }

    function flowtrackEnsureJobPanels(job, preferredTab = null) {
        if (!job) return;
        const page = document.getElementById(`page-record-job-${flowtrackRecordSlug(job.id)}`);
        const content = page?.querySelector('.standalone-record-content');
        if (!content) return;
        let panels = content.querySelector('#jobDetailPanels');
        const body = content.querySelector('.drawer-body') || content;
        if (!panels) {
            panels = document.createElement('div');
            panels.id = 'jobDetailPanels';
            body.appendChild(panels);
        }
        panels.innerHTML = jobPanels(
            job,
            tasks.filter((task) => String(task.job) === String(job.id) || Number(task.jobDbId) === Number(job.dbId)),
            docs.filter((documentRecord) => String(documentRecord.job) === String(job.id) || Number(documentRecord.jobDbId) === Number(job.dbId))
        );
        bindStandaloneDetailTabs(content);
        const activeTab = preferredTab ?? content.querySelector('[data-detailtab].active')?.dataset.detailtab ?? 0;
        activateScopedDetailTab(content, activeTab);
        bindCommon();
        applyActionPermissions();
    }

    function flowtrackRefreshStartStages(root = document) {
        const scope = root || document;
        const workflowSelect = scope.querySelector('#newJobWorkflow');
        const stageSelect = scope.querySelector('#newJobStartPhase');
        if (!workflowSelect || !stageSelect) return;
        const workflow = getWorkflow(Number(workflowSelect.value));
        const phases = (workflow?.phases || [])
            .filter((phase) => phase.active !== false)
            .slice()
            .sort((left, right) => Number(left.sequence || 0) - Number(right.sequence || 0));
        const allowed = phases.filter((phase) => phase.allowStart !== false);
        const options = (allowed.length ? allowed : phases).map((phase, index) => {
            const sequence = Number(phase.sequence || index + 1);
            return `<option value="${html(phase.id)}">${sequence}. ${html(phase.name)}</option>`;
        }).join('');
        stageSelect.innerHTML = options || '<option value="">No active workflow stage configured</option>';
        stageSelect.disabled = !options;
        window.FlowtrackSearchableDropdown?.refresh?.(stageSelect);
    }

    const flowtrackStableOpenTask = currentFlowtrackImplementation('openTask');
    registerFlowtrackExtension('openTask', 'openTask-stage-3', function (id) {        const task = tasks.find((item) => item.id === id || Number(item.dbId) === Number(id));
        if (task) flowtrackClearRecordFor('record-task', task.id);
        return flowtrackStableOpenTask(id);
    });

    const flowtrackStableOpenJob = currentFlowtrackImplementation('openJob');
    registerFlowtrackExtension('openJob', 'openJob-stage-3', function (id) {        const job = jobs.find((item) => String(item.id) === String(id) || Number(item.dbId) === Number(id));
        if (job) flowtrackClearRecordFor('record-job', job.id);
        const result = flowtrackStableOpenJob(id);
        if (job) {
            flowtrackEnsureJobPanels(job);
            setTimeout(() => flowtrackEnsureJobPanels(job, document.querySelector('.standalone-record-content [data-detailtab].active')?.dataset.detailtab ?? null), 0);
        }
        return result;
    });

    const flowtrackStableOpenClient = openClient;
    openClient = function (id) {
        flowtrackClearRecordFor('record-client', id);
        return flowtrackStableOpenClient(id);
    };

    const flowtrackStableOpenClientModal = openClientModal;
    openClientModal = function (existing = null) {
        flowtrackClearStandalonePage(existing ? `record-client-edit-${existing.id}` : 'record-client-new');
        return flowtrackStableOpenClientModal(existing);
    };

    const flowtrackStableOpenJobModal = openJobModal;
    openJobModal = function (prefillClient) {
        flowtrackClearStandalonePage('record-job-new');
        const result = flowtrackStableOpenJobModal(prefillClient);
        queueMicrotask(() => {
            const host = document.querySelector('#page-record-job-new') || document;
            flowtrackRefreshAssigneeSelect(host, '#newJobOwner', host.querySelector('#newJobOwner')?.value || currentUser?.id);
            flowtrackRefreshStartStages(host);
            host.querySelector('#newJobWorkflow')?.addEventListener('change', () => flowtrackRefreshStartStages(host));
            window.FlowtrackSearchableDropdown?.enhance(host);
        });
        return result;
    };

    const flowtrackStableOpenEditJobModal = openEditJobModal;
    openEditJobModal = function (job) {
        if (job) flowtrackClearStandalonePage(`record-job-edit-${flowtrackRecordSlug(job.id)}`);
        const result = flowtrackStableOpenEditJobModal(job);
        queueMicrotask(() => {
            if (!job || !canFlow('jobs', 'assign')) return;
            const host = document.querySelector(`#page-record-job-edit-${flowtrackRecordSlug(job.id)}`) || document;
            ['#editJobOwner', '#editJobCoordinator'].forEach((selector) => {
                const select = host.querySelector(selector);
                if (!select) return;
                const selected = select.value;
                select.innerHTML = flowtrackWorkspaceMembers().map((user) => `<option value="${html(user.id)}" ${String(user.id) === String(selected) ? 'selected' : ''}>${html(user.name)}</option>`).join('');
            });
            window.FlowtrackSearchableDropdown?.enhance(host);
        });
        return result;
    };

    const flowtrackStableOpenWorkflowModal = openWorkflowModal;
    openWorkflowModal = function (existing = null, duplicate = false) {
        flowtrackClearStandalonePage(existing && !duplicate ? `record-workflow-edit-${existing.id}` : duplicate ? `record-workflow-copy-${existing?.id || 'new'}` : 'record-workflow-new');
        return flowtrackStableOpenWorkflowModal(existing, duplicate);
    };

    const flowtrackStableOpenTaskPackModal = openTaskPackModal;
    openTaskPackModal = function (existing = null) {
        flowtrackClearStandalonePage(existing ? `record-task-pack-edit-${existing.id}` : 'record-task-pack-new');
        return flowtrackStableOpenTaskPackModal(existing);
    };

    const flowtrackStablePersistTask = persistTask;
    persistTask = async function (task, status, extras = {}) {
        const result = await flowtrackStablePersistTask(task, status, extras);
        if (result?.task) flowtrackUpsertRecord(tasks, result.task);
        if (result?.job) flowtrackUpsertRecord(jobs, result.job);
        return result;
    };


    
    /* CSS externalized: flowtrackEmergencyFormFixStyles. */

    document.addEventListener('click', (event) => {
        if (event.target.closest('#createJob,#saveDraftJob,#saveEditJob,#saveTaskUpdate,#saveTaskDraft,#saveTaskDueDate,#saveClient,#createClient,#saveWorkflow,#saveTaskPack')) {
            sessionActivityPending = true;
        }
    }, true);


    
    /* CSS externalized: flowtrackTaskInteractionStyles. */

    
    /* CSS externalized: flowtrackBoardFilterCleanupStyles. */

    
    /* CSS externalized: flowtrackBoardAssigneeStickyFinalStyles. */


    
    /* CSS externalized: flowtrackMyWorkAdminStickyTaskBoardStyles. */

    let flowtrackRouteSyncSuppressed = false;

    function flowtrackRoutePath(pageId) {
        const page = String(pageId || 'dashboard');
        const base = '/flowtrack/view';
        if (page.startsWith('record-task-')) return `${base}/tasks/${encodeURIComponent(page.slice('record-task-'.length))}`;
        if (page.startsWith('record-job-edit-')) return `${base}/jobs/${encodeURIComponent(page.slice('record-job-edit-'.length))}/edit`;
        if (page === 'record-job-new') return `${base}/jobs/new`;
        if (page.startsWith('record-job-')) return `${base}/jobs/${encodeURIComponent(page.slice('record-job-'.length))}`;
        if (page.startsWith('record-client-edit-')) return `${base}/clients/${encodeURIComponent(page.slice('record-client-edit-'.length))}/edit`;
        if (page === 'record-client-new') return `${base}/clients/new`;
        if (page.startsWith('record-client-')) return `${base}/clients/${encodeURIComponent(page.slice('record-client-'.length))}`;
        if (page === 'record-workflow-new') return `${base}/workflows/new`;
        if (page.startsWith('record-workflow-edit-')) return `${base}/workflows/${encodeURIComponent(page.slice('record-workflow-edit-'.length))}/edit`;
        if (page === 'record-task-pack-new') return `${base}/task-packs/new`;
        if (page.startsWith('record-task-pack-edit-')) return `${base}/task-packs/${encodeURIComponent(page.slice('record-task-pack-edit-'.length))}/edit`;
        return `${base}/${encodeURIComponent(page)}`;
    }

    function flowtrackSyncRoute(pageId, replace = false) {
        if (flowtrackRouteSyncSuppressed || !window.history?.pushState) return;
        const path = flowtrackRoutePath(pageId);
        if (window.location.pathname === path) return;
        window.history[replace ? 'replaceState' : 'pushState']({ flowtrackPage: pageId }, '', path);
    }

    function flowtrackInitialRoute() {
        const prefix = '/flowtrack/view/';
        if (!window.location.pathname.startsWith(prefix)) return null;
        const parts = window.location.pathname.slice(prefix.length).split('/').filter(Boolean).map(decodeURIComponent);
        if (!parts.length) return { type: 'page', page: 'dashboard' };
        if (parts[0] === 'tasks' && parts[1]) return { type: 'task', slug: parts[1] };
        if (parts[0] === 'jobs' && parts[1] === 'new') return { type: 'job-new' };
        if (parts[0] === 'jobs' && parts[1] && parts[2] === 'edit') return { type: 'job-edit', slug: parts[1] };
        if (parts[0] === 'jobs' && parts[1]) return { type: 'job', slug: parts[1] };
        if (parts[0] === 'clients' && parts[1] === 'new') return { type: 'client-new' };
        if (parts[0] === 'clients' && parts[1] && parts[2] === 'edit') return { type: 'client-edit', slug: parts[1] };
        if (parts[0] === 'clients' && parts[1]) return { type: 'client', slug: parts[1] };
        if (parts[0] === 'workflows' && parts[1] === 'new') return { type: 'workflow-new' };
        if (parts[0] === 'workflows' && parts[1] && parts[2] === 'edit') return { type: 'workflow-edit', slug: parts[1] };
        if (parts[0] === 'task-packs' && parts[1] === 'new') return { type: 'task-pack-new' };
        if (parts[0] === 'task-packs' && parts[1] && parts[2] === 'edit') return { type: 'task-pack-edit', slug: parts[1] };
        return { type: 'page', page: parts[0] };
    }

    function flowtrackFindBySlug(collection, slug) {
        return (collection || []).find((item) => flowtrackRecordSlug(item.id ?? item.code ?? item.name) === flowtrackRecordSlug(slug)
            || String(item.dbId ?? item.id) === String(slug));
    }

    function flowtrackOpenInitialRoute(route) {
        if (!route) return false;
        flowtrackRouteSyncSuppressed = true;
        try {
            if (route.type === 'page') {
                const page = firstAllowedPage(route.page);
                navigate(page);
                flowtrackRouteSyncSuppressed = false;
                flowtrackSyncRoute(page, true);
                return true;
            }
            if (route.type === 'task') {
                const task = flowtrackFindBySlug(tasks, route.slug);
                navigate('board');
                void openTask(task?.id || route.slug);
            } else if (route.type === 'job') {
                const job = flowtrackFindBySlug(jobs, route.slug);
                navigate('jobs');
                void openJob(job?.id || route.slug);
            } else if (route.type === 'job-new') {
                navigate('jobs');
                openJobModal();
            } else if (route.type === 'job-edit') {
                const job = flowtrackFindBySlug(jobs, route.slug);
                navigate('jobs');
                void flowtrackEnsureJobDetail(job?.id || route.slug).then((resolved) => resolved && openEditJobModal(resolved));
            } else if (route.type === 'client') {
                const client = flowtrackFindBySlug(clients, route.slug);
                navigate('clients');
                void openClient(client?.id || route.slug);
            } else if (route.type === 'client-new') {
                navigate('clients');
                openClientModal();
            } else if (route.type === 'client-edit') {
                const client = flowtrackFindBySlug(clients, route.slug);
                navigate('clients');
                void flowtrackEnsureClientDetail(client?.id || route.slug).then((resolved) => resolved && openClientModal(resolved));
            } else if (route.type === 'workflow-new') {
                navigate('workflowsetup');
                openWorkflowModal();
            } else if (route.type === 'workflow-edit') {
                const workflow = flowtrackFindBySlug(workflowTemplates, route.slug);
                navigate('workflowsetup');
                if (workflow) openWorkflowModal(workflow);
            } else if (route.type === 'task-pack-new') {
                navigate('taskpacksetup');
                openTaskPackModal();
            } else if (route.type === 'task-pack-edit') {
                const pack = flowtrackFindBySlug(taskPacks, route.slug);
                navigate('taskpacksetup');
                if (pack) openTaskPackModal(pack);
            }
        } finally {
            flowtrackRouteSyncSuppressed = false;
        }
        flowtrackSyncRoute(currentPage || firstAllowedPage(), true);
        return true;
    }

    const flowtrackHistoryNavigate = navigate;
    navigate = function (page) {
        const result = flowtrackHistoryNavigate(page);
        if (currentPage === page) flowtrackSyncRoute(page);
        return result;
    };

    const flowtrackHistoryActivateStandalonePage = activateStandalonePage;
    activateStandalonePage = function (pageId, backPage) {
        const page = flowtrackHistoryActivateStandalonePage(pageId, backPage);
        flowtrackSyncRoute(pageId);
        return page;
    };

    const flowtrackHistoryShowApplication = showApplication;
    showApplication = function (requestedPage = null) {
        const initialRoute = flowtrackInitialRoute();
        flowtrackRouteSyncSuppressed = true;
        flowtrackHistoryShowApplication(requestedPage);
        flowtrackRouteSyncSuppressed = false;
        if (!flowtrackOpenInitialRoute(initialRoute)) {
            flowtrackSyncRoute(currentPage || firstAllowedPage(currentUser?.defaultLandingPage), true);
        }
    };

    window.addEventListener('popstate', () => window.location.reload());

    const expectedFlowtrackStages = {
        openJob: ['openJob-stage-1', 'openJob-stage-2', 'openJob-stage-3'],
        openTask: ['openTask-stage-1', 'openTask-stage-2', 'openTask-stage-3'],
        renderJobs: ['renderJobs-stage-1', 'renderJobs-stage-2'],
        renderKanban: ['renderKanban-stage-1', 'renderKanban-stage-2'],
    };
    const actualFlowtrackStages = Object.fromEntries(
        flowtrackToArray(flowtrackExtensionRegistry).map(([name, stages]) => [name, stages.map((entry) => entry.stage)]),
    );
    Object.entries(expectedFlowtrackStages).forEach(([name, expected]) => {
        const actual = actualFlowtrackStages[name] || [];
        if (actual.length !== expected.length || actual.some((stage, index) => stage !== expected[index])) {
            throw new Error(`Invalid FlowTrack extension chain for ${name}: ${actual.join(', ')}`);
        }
    });

    // Freeze the final public implementations after all deterministic stages have
    // been installed. This preserves the existing UI result while preventing any
    // later script from silently replacing core behavior.
    const flowtrackFinalImplementations = { openJob, openTask, renderJobs, renderKanban, bindCommon };
    Object.entries(flowtrackFinalImplementations).forEach(([name, implementation]) => {
        try {
            Object.defineProperty(window, name, {
                value: implementation,
                writable: false,
                configurable: false,
            });
        } catch (error) {
            if (window[name] !== implementation) throw error;
        }
    });
    window.__FLOWTRACK_FINALIZED__ = true;
    window.__FLOWTRACK_EXTENSION_STAGES__ = actualFlowtrackStages;
