/* FlowTrack lazy module boundary: job-shared. */

    /* Final Job Detail Redesign: matches the supplied Overview, Workflow and Documents reference screens. */
    function flowtrackJobDetailSlug(job) {
        return String(job?.id || '').replace(/[^a-z0-9]+/gi, '-').toLowerCase();
    }

    function flowtrackJobAllTasks(job) {
        const phaseOrder = new Map(flowtrackActivePhases(job || {}).map((phase, index) => [Number(phase.id), Number(phase.sequence || index + 1)]));
        // Standalone Job Details owns an authoritative complete Task snapshot.
        // Never rebuild it from the shared paginated tasks[] array because Board,
        // My Work and other pages are allowed to replace that array as they lazy-load.
        const source = job?._completeTaskSetLoaded && Array.isArray(job?._detailTasks)
            ? job._detailTasks
            : flowtrackTasksForJob(job || {});
        return flowtrackToArray(source)
            .map((task) => flowtrackHydrateTaskAssignee(task))
            .sort((left, right) => {
                const leftPhase = phaseOrder.get(Number(left.phaseId)) ?? Number(left.phaseIndex ?? 999);
                const rightPhase = phaseOrder.get(Number(right.phaseId)) ?? Number(right.phaseIndex ?? 999);
                if (leftPhase !== rightPhase) return leftPhase - rightPhase;
                const leftSort = Number(left.sortOrder ?? left.sort_order ?? left.taskPackItemSortOrder ?? 9999);
                const rightSort = Number(right.sortOrder ?? right.sort_order ?? right.taskPackItemSortOrder ?? 9999);
                if (leftSort !== rightSort) return leftSort - rightSort;
                return String(left.id || '').localeCompare(String(right.id || ''), undefined, { numeric: true });
            });
    }

    function flowtrackJobTeamMembers(job, jobTasks = null) {
        const pool = [];
        const addByName = (name) => {
            const label = String(name || '').trim();
            if (!label || label.toLowerCase() === 'unassigned') return;
            if (!pool.some((item) => item.name.toLowerCase() === label.toLowerCase())) pool.push({ name: label, initials: userInitials(label) });
        };
        // The header team bubbles must represent only the people assigned to Tasks
        // on this Job, not every workspace member, owner, coordinator or cached team.
        (jobTasks || flowtrackJobAllTasks(job)).forEach((task) => addByName(flowtrackResolveTaskAssigneeName(task, flowtrackResolveTaskAssigneeId(task)) || task.assignee));
        return pool.slice(0, 5);
    }

    function flowtrackCurrentUserAssignedToJob(job, jobTasks = null) {
        if (isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin) return true;
        return (jobTasks || flowtrackJobAllTasks(job)).some((task) => boardTaskMatchesAssignee(task, currentUser?.id, currentUser?.name));
    }

    function flowtrackPhaseProgress(phaseTasks) {
        const total = phaseTasks.length;
        const completed = phaseTasks.filter((task) => taskIsCompleted(task)).length;
        return { total, completed, percent: total ? Math.round((completed / total) * 100) : 0 };
    }

    function flowtrackTaskStatusTone(status) {
        const value = String(status || '').toLowerCase();
        if (/complete|approved|passed/.test(value)) return 'b-green';
        if (/block|overdue|risk|attention/.test(value)) return 'b-red';
        if (/progress|transit|ready/.test(value)) return 'b-blue';
        if (/wait|pending|revision/.test(value)) return 'b-amber';
        return 'b-gray';
    }

    function flowtrackJobHealthPill(job) {
        const label = job.health || job.status || 'On Track';
        const tone = /block|risk|overdue|attention|delay/i.test(label) ? 'b-red' : /complete/i.test(label) ? 'b-green' : /wait|pending/i.test(label) ? 'b-amber' : 'b-blue';
        return `<span class="badge ${tone}">${html(label)}</span>`;
    }

    function flowtrackJobPhaseDocumentRequirements(job, activePhases = null) {
        const phases = activePhases || flowtrackActivePhases(job);
        return phases
            .filter((phase) => String(phase.document || '').trim())
            .map((phase) => {
                const label = String(phase.document || '').trim();
                const present = jobDocsFor(job).some((documentRecord) => documentMatchesKeywords(documentRecord, [label, phase.name, phase.short]));
                return { phase, label, present };
            });
    }

    function flowtrackJobMetricCard(icon, label, title, foot = '', extra = '') {
        return `<div class="job-v3-metric"><div class="job-v3-metric-icon">${icon}</div><div><span>${html(label)}</span><b>${title}</b>${foot ? `<small>${foot}</small>` : ''}</div>${extra}</div>`;
    }

    function flowtrackMiniTimeline(job, activePhases, currentPhase) {
        if (!activePhases.length) return '';
        return `<div class="job-v3-mini-timeline">${activePhases.map((phase, index) => {
            const isCurrent = Number(phase.id) === Number(job.phaseId);
            const isDone = currentPhase ? Number(phase.sequence || index + 1) < Number(currentPhase.sequence || 0) : index < Number(job.phase || 0);
            const state = isDone ? 'done' : isCurrent ? 'current' : '';
            return `<button type="button" class="job-v3-mini-step ${state}" data-detailtab="1" data-flow-phase="${html(phase.id)}" data-flow-job="${html(job.id)}"><span>${isDone ? '✓' : Number(phase.sequence || index + 1)}</span><b>${html(phase.short || phase.name)}</b></button>`;
        }).join('')}</div>`;
    }

    function flowtrackTaskRowCompact(rawTask, index = null) {
        const task = flowtrackHydrateTaskAssignee(rawTask || {});
        const assigneeId = flowtrackResolveTaskAssigneeId(task);
        const assigneeName = flowtrackResolveTaskAssigneeName(task, assigneeId);
        const dueValue = task.due || task.dueDate || '';
        const dueClassName = dueValue && dueValue < today ? 'due overdue' : '';
        const progressValue = Math.max(0, Math.min(100, Number(task.progress || 0)));
        return `<tr class="job-v3-task-line" data-task="${html(task.id)}"><td>${index !== null ? html(index + 1) : ''}</td><td><b>${html(task.title || task.id)}</b><small>${html(task.id)}${task.documentRequirement ? ` · ${html(task.documentRequirement.categoryName || task.documentRequirement.name || '')}` : ''}</small></td><td><span class="avatar">${userInitials(assigneeName || 'U')}</span> ${html(assigneeName || 'Unassigned')}</td><td class="${dueClassName}">${dueValue ? formatDate(dueValue) : '—'}</td><td><div class="job-v3-task-progress"><span style="width:${progressValue}%"></span></div>${progressValue}%</td><td>${statusBadge(task.status)}</td></tr>`;
    }


    function flowtrackPhaseAccordion(job, allTasks, activePhases, currentPhase, expandedCurrent = true) {
        return `<div class="job-v3-phase-stack">${activePhases.map((phase, index) => {
            const phaseTasks = flowtrackTasksForPhase(job, phase.id, allTasks).map((task) => flowtrackHydrateTaskAssignee(task));
            const progress = flowtrackPhaseProgress(phaseTasks);
            const current = Number(phase.id) === Number(job.phaseId);
            const done = progress.total > 0 && progress.completed >= progress.total;
            const open = expandedCurrent ? current : false;
            return `<section class="job-v3-phase-block ${current ? 'current' : ''} ${done ? 'done' : ''}"><button type="button" class="job-v3-phase-head" data-job-v3-toggle-phase><span class="job-v3-phase-caret">${open ? '⌄' : '›'}</span><span class="job-v3-phase-dot">${done ? '✓' : Number(phase.sequence || index + 1)}</span><b>${html(phase.name)}</b><small>${progress.completed} of ${progress.total} complete</small><i><span style="width:${progress.percent}%"></span></i>${current ? '<em>Current phase</em>' : ''}</button><div class="job-v3-phase-body ${open ? '' : 'hidden'}"><table class="job-v3-task-table"><thead><tr><th>#</th><th>Task</th><th>Assignee</th><th>Due date</th><th>Progress</th><th>Status</th></tr></thead><tbody>${phaseTasks.map((task, taskIndex) => flowtrackTaskRowCompact(task, taskIndex)).join('') || '<tr><td colspan="6"><div class="empty-state">No tasks configured in Task Pack Setup for this phase.</div></td></tr>'}</tbody></table></div></section>`;
        }).join('')}</div>`;
    }


    function flowtrackWorkflowHistoryTable(job, activePhases, allTasks) {
        return `<div class="job-v3-panel-card"><div class="job-v3-panel-head"><div><h3>Phase history</h3><p>Progress, ownership and gate results</p></div></div><table class="job-v3-history-table"><thead><tr><th>Phase</th><th>Status</th><th>Owner</th><th>Dates</th><th>Duration</th><th>Tasks</th><th>Gates</th><th></th></tr></thead><tbody>${activePhases.map((phase, index) => {
            const history = flowtrackPhaseHistoryFor(job, phase) || {};
            const phaseTasks = flowtrackTasksForPhase(job, phase.id, allTasks);
            const progress = flowtrackPhaseProgress(phaseTasks);
            const current = Number(phase.id) === Number(job.phaseId);
            const passed = progress.total ? progress.completed >= progress.total : Number(phase.sequence || index + 1) < Number((activePhases.find((item) => Number(item.id) === Number(job.phaseId)) || {}).sequence || 0);
            const statusLabel = history.statusLabel || (current ? (job?.phaseReadinessLabel || 'Current') : passed ? 'Completed' : 'Not started');
            const statusTone = /complete|passed/i.test(statusLabel) ? 'b-green' : /block|overdue/i.test(statusLabel) ? 'b-red' : current ? 'b-purple' : 'b-gray';
            const dates = history.enteredAt || history.completedAt ? `${history.enteredAt ? flowtrackFormatDateTime(history.enteredAt).split(',')[0] : '—'} – ${history.completedAt ? flowtrackFormatDateTime(history.completedAt).split(',')[0] : current ? 'Now' : '—'}` : current ? 'Started' : '—';
            return `<tr class="${current ? 'current' : ''}" data-flow-phase="${html(phase.id)}" data-flow-job="${html(job.id)}"><td><b>${Number(phase.sequence || index + 1)}</b> ${html(phase.short || phase.name)}</td><td><span class="badge ${statusTone}">${html(statusLabel)}</span></td><td>${html(history.changedBy || job.owner || '—')}</td><td>${dates}</td><td>${history.duration || (current ? 'In progress' : '—')}</td><td>${progress.completed}/${progress.total}</td><td>${passed ? '<span class="badge b-green">Passed</span>' : current ? '<span class="badge b-amber">Pending</span>' : '<span class="badge b-gray">Pending</span>'}</td><td>›</td></tr>`;
        }).join('')}</tbody></table><div id="flowtrackPhaseHistoryPanel" class="job-v3-phase-history-inline">${flowtrackRenderPhaseHistoryPanel(job, job.phaseId || activePhases[0]?.id, allTasks)}</div></div>`;
    }

    function flowtrackCurrentGatesCard(job, currentTasks, requirements) {
        const taskProgress = flowtrackPhaseProgress(currentTasks);
        const missing = requirements.filter((item) => !item.present);
        const shippingConfirmed = Number(job.phase || 0) >= 8 || /ship|invoice|complete/i.test(phaseNameFor(job));
        const finalInvoiceApproved = jobDocsFor(job).some((documentRecord) => documentMatchesKeywords(documentRecord, ['invoice', 'final invoice']));
        return `<div class="job-v3-panel-card job-v3-gates-card"><div class="job-v3-panel-head"><div><h3>Current phase gates</h3><p>${html(phaseNameFor(job))}</p></div></div><div class="job-v3-gate-list"><div class="job-v3-gate ${shippingConfirmed ? 'passed' : ''}"><span>${shippingConfirmed ? '✓' : '○'}</span><b>Shipment confirmed</b><small>${shippingConfirmed ? 'Entry requirement passed' : 'Waiting confirmation'}</small></div><div class="job-v3-gate ${finalInvoiceApproved ? 'passed' : ''}"><span>${finalInvoiceApproved ? '✓' : '○'}</span><b>Final invoice approved</b><small>${finalInvoiceApproved ? 'Requirement satisfied' : 'Invoice is pending'}</small></div>${missing.slice(0, 2).map((item) => `<div class="job-v3-gate blocked"><span>!</span><b>${html(item.label)}</b><small>Missing required document</small></div>`).join('')}<div class="job-v3-gate ${taskProgress.total && taskProgress.completed >= taskProgress.total ? 'passed' : ''}"><span>${taskProgress.total && taskProgress.completed >= taskProgress.total ? '✓' : '○'}</span><b>All phase tasks completed</b><small>${taskProgress.completed} of ${taskProgress.total} complete</small></div></div>${job.phaseReadiness === 'ready' ? `<button type="button" class="primary" data-next-phase="${html(job.id)}">Complete phase</button>` : '<button type="button" class="ghost" disabled>Resolve requirements to continue</button>'}</div>`;
    }

    function flowtrackDocumentSizeLabel(size) {
        const bytes = Number(size || 0);
        if (!bytes) return '0 KB';
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(bytes < 100 * 1024 ? 1 : 0)} KB`;
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }

    function flowtrackDocumentFileBadge(documentRecord) {
        const name = String(documentRecord?.name || '').toLowerCase();
        if (name.endsWith('.pdf') || /pdf/i.test(String(documentRecord?.mimeType || ''))) return 'PDF';
        const extension = (name.split('.').pop() || '').trim();
        return extension && extension.length <= 4 ? extension.toUpperCase() : 'FILE';
    }

    function flowtrackDocumentStatusMeta(documentRecord) {
        const raw = String(documentRecord?.status || documentRecord?.state || '').trim();
        const status = raw || (documentRecord?.final ? 'Approved' : 'Approved');
        const tone = /missing|reject|block|action/i.test(status) ? 'b-red' : /wait|pending|review/i.test(status) ? 'b-amber' : /approved|final|satisfied|received/i.test(status) ? 'b-green' : 'b-gray';
        return { status, tone };
    }

    function flowtrackJobDocumentPreviewCard(documentRecord, job) {
        if (!documentRecord) {
            return '<div class="job-v3-doc-preview" data-job-doc-preview-card><b>No document selected</b></div>';
        }
        const statusMeta = flowtrackDocumentStatusMeta(documentRecord);
        const fileBadge = flowtrackDocumentFileBadge(documentRecord);
        const uploadedDate = documentRecord.date ? flowtrackFormatDateTime(documentRecord.date).replace(' 00:00', '') : '—';
        const fileMeta = `${fileBadge} · ${flowtrackDocumentSizeLabel(documentRecord.size)} · ${html(documentRecord.version || 'v1')}`;
        const openAction = documentRecord.previewUrl
            ? `<a class="primary" href="${html(documentRecord.previewUrl)}" target="_blank" rel="noopener">Open</a>`
            : `<button type="button" class="primary" data-doc="${html(documentRecord.id)}">Open</button>`;
        const downloadAction = documentRecord.downloadUrl
            ? `<a class="icon-btn" href="${html(documentRecord.downloadUrl)}" title="Download">⇩</a>`
            : '<button type="button" class="icon-btn" disabled>⇩</button>';
        return `<div class="job-v3-doc-preview" data-job-doc-preview-card>
            <div class="job-v3-doc-preview-top"><span class="job-v3-pdf">${html(fileBadge)}</span><div><b>${html(documentRecord.name || 'Document')}</b><small>${fileMeta}</small></div></div>
            <div class="job-v3-doc-preview-actions"><span class="badge ${statusMeta.tone}">${html(statusMeta.status)}</span>${openAction}${downloadAction}<button type="button" class="icon-btn" data-doc="${html(documentRecord.id)}">•••</button></div>
            <dl>
                <dt>Phase</dt><dd>${html(documentRecord.phase || phaseNameFor(job) || '—')}</dd>
                <dt>Document type</dt><dd>${html(documentRecord.type || 'Document')}</dd>
                <dt>Owner</dt><dd><span class="avatar">${userInitials(documentRecord.uploadedBy || job?.owner || 'U')}</span> ${html(documentRecord.uploadedBy || job?.owner || '—')}</dd>
                <dt>Uploaded</dt><dd>${html(uploadedDate)}</dd>
                <dt>Requirement</dt><dd>${documentRecord.taskRequirement ? html(documentRecord.taskRequirement) : 'Satisfied'}</dd>
                ${documentRecord.taskRequirement ? `<dt>Related task</dt><dd>${html(documentRecord.taskRequirement)}</dd>` : ''}
            </dl>
            ${documentRecord.versionNumber && Number(documentRecord.versionNumber) > 1 ? `<button type="button" class="job-v3-version-link" data-doc="${html(documentRecord.id)}">View version history (${html(documentRecord.versionNumber)}) ›</button>` : ''}
        </div>`;
    }

    function flowtrackNormalizeJobDocumentRequirementsForView(job, activePhases = null) {
        const configured = flowtrackToArray(jobDocumentRequirements(job));
        if (configured.length) {
            const seen = new Set();
            return configured.filter((requirement) => {
                const key = String(requirement.key || `${requirement.phaseId}:${requirement.taskPackItemId || 0}:${requirement.categoryId}`);
                if (seen.has(key)) return false;
                seen.add(key);
                return true;
            }).map((requirement) => ({
                key: requirement.key,
                phase: { id: requirement.phaseId, name: requirement.phaseName || phaseNameFor(job) },
                label: requirement.categoryName || 'Required document',
                sourceLabel: requirement.sourceLabel || 'Workflow requirement',
                present: Boolean(requirement.present),
                requiredForReadiness: requirement.requiredForReadiness !== false,
            }));
        }
        return flowtrackJobPhaseDocumentRequirements(job, activePhases).map((requirement) => ({
            ...requirement,
            sourceLabel: requirement.phase?.name || 'Workflow requirement',
            requiredForReadiness: true,
        }));
    }

    function flowtrackJobDocumentPurposeOptions(job, activePhases = null) {
        const phases = activePhases || flowtrackActivePhases(job);
        const configured = flowtrackToArray(jobDocumentRequirements(job));
        if (configured.length) {
            return configured.map((requirement) => ({
                key: String(requirement.key || requirement.requirement_key || `${requirement.taskPackItemId || requirement.task_pack_item_id ? 'task' : 'phase'}:${requirement.taskPackItemId || requirement.task_pack_item_id || requirement.phaseId || requirement.workflow_phase_id}:${requirement.categoryId || requirement.category_id}`),
                phaseId: Number(requirement.phaseId || requirement.workflow_phase_id || job.phaseId || 0),
                phaseName: requirement.phaseName || requirement.workflow_phase_name || phaseNameFor(job),
                taskPackItemId: Number(requirement.taskPackItemId || requirement.task_pack_item_id || 0) || null,
                taskId: Number(requirement.taskId || requirement.task_id || requirement.flow_task_id || 0) || null,
                taskCode: requirement.taskCode || requirement.task_code || requirement.flow_task_code || null,
                taskTitle: requirement.taskTitle || requirement.task_title || requirement.flow_task_title || null,
                categoryId: Number(requirement.categoryId || requirement.category_id || 0),
                categoryName: requirement.categoryName || requirement.category_name || 'Required document',
                sourceLabel: requirement.sourceLabel || requirement.source_label || 'Workflow requirement',
                present: Boolean(requirement.present),
                requiredForReadiness: requirement.requiredForReadiness !== false && requirement.required_for_readiness !== false,
            })).filter((item) => item.phaseId && item.categoryId);
        }
        const currentPhase = phases.find((phase) => Number(phase.id) === Number(job.phaseId)) || phases[0] || null;
        return activeMaster('documentCategories').map((category) => ({
            key: `generic:${currentPhase?.id || job.phaseId || 0}:${category.id}`,
            phaseId: Number(currentPhase?.id || job.phaseId || 0),
            phaseName: currentPhase?.name || phaseNameFor(job),
            taskPackItemId: null,
            categoryId: Number(category.id),
            categoryName: category.name,
            sourceLabel: 'Job document',
            present: false,
            requiredForReadiness: false,
        })).filter((item) => item.phaseId && item.categoryId);
    }

    function flowtrackJobDocumentTask(documentRecord, allTasks) {
        const linkedTaskId = Number(documentRecord?.taskId || 0);
        if (linkedTaskId) {
            const exactById = allTasks.find((task) => Number(task.dbId || 0) === linkedTaskId);
            if (exactById) return exactById;
        }
        const taskPackItemId = Number(documentRecord?.taskPackItemId || 0);
        if (taskPackItemId) {
            const exact = allTasks.find((task) => Number(task.taskPackItemId || 0) === taskPackItemId && Number(task.phaseId || 0) === Number(documentRecord.phaseId || 0));
            if (exact) return exact;
        }
        const taskRequirement = normalizedStatusValue(documentRecord?.taskRequirement || '');
        if (taskRequirement) {
            const exactByTitle = allTasks.find((task) => normalizedStatusValue(task.title || task.taskPackItemTitle || '') === taskRequirement && Number(task.phaseId || 0) === Number(documentRecord.phaseId || 0));
            if (exactByTitle) return exactByTitle;
        }
        return null;
    }

    function flowtrackJobDocumentViewStatus(documentRecord) {
        const explicit = String(documentRecord?.status || documentRecord?.state || '').trim();
        if (explicit) return flowtrackDocumentStatusMeta(documentRecord);
        if (documentRecord?.final) return { status: 'Approved', tone: 'b-green' };
        return { status: 'In review', tone: 'b-blue' };
    }

    function flowtrackJobDocumentRefreshView(updatedJob) {
        const current = flowtrackFindJob(updatedJob?.id || updatedJob?.dbId) || updatedJob;
        if (!current) return;
        flowtrackEnsureJobPanels(current, 8);
        if (typeof flowtrackJobV4Apply === 'function') flowtrackJobV4Apply(current, 8);
    }

    function flowtrackDocumentsPanel(job, allJobDocs, activePhases) {
        const actualTasks = flowtrackJobAllTasks(job);
        const requirements = flowtrackJobDocumentPurposeOptions(job, activePhases).filter((item) => item.requiredForReadiness);
        const missing = requirements.filter((item) => !item.present);
        const statusRows = allJobDocs.map((documentRecord) => ({ documentRecord, meta: flowtrackJobDocumentViewStatus(documentRecord) }));
        const approved = statusRows.filter((item) => item.meta.tone === 'b-green').length;
        const inReview = statusRows.filter((item) => item.meta.tone === 'b-blue' || item.meta.tone === 'b-amber').length;
        const needsActionDocuments = statusRows.filter((item) => item.meta.tone === 'b-red').length;
        const receivedCount = Math.max(0, requirements.length - missing.length);
        const completionPercent = requirements.length ? Math.round((receivedCount / requirements.length) * 100) : 100;
        const recentlyUpdatedCount = Math.min(3, allJobDocs.length);
        const recentIds = new Set(allJobDocs.slice(0, recentlyUpdatedCount).map((item) => String(item.id)));
        const latestDocument = allJobDocs[0] || null;
        const currentPhase = activePhases.find((phase) => Number(phase.id) === Number(job.phaseId)) || activePhases[0] || null;
        const phasePalette = ['blue','violet','orange','green','cyan','purple','teal','amber','rose','brown','slate'];
        const ownerOptions = [...new Set(allJobDocs.map((item) => item.uploadedBy).filter(Boolean))].sort();
        const typeOptions = [...new Set([...allJobDocs.map((item) => item.type), ...requirements.map((item) => item.categoryName)].filter(Boolean))].sort();
        const configuredUploadRequirements = flowtrackToArray(jobDocumentRequirements(job));
        const uploadPurposes = configuredUploadRequirements.length ? flowtrackJobDocumentPurposeOptions(job, activePhases) : [];
        const uploadTypeOptions = [...new Map(uploadPurposes.map((item) => [Number(item.categoryId), { id: Number(item.categoryId), name: item.categoryName }])).values()]
            .filter((item) => item.id && item.name);
        const taskOptions = [...new Map(actualTasks.map((task) => [String(task.id), task])).values()];
        const firstExpandedPhaseId = (activePhases.find((phase) => requirements.some((requirement) => Number(requirement.phaseId) === Number(phase.id) && !requirement.present)) || activePhases.find((phase) => allJobDocs.some((documentRecord) => Number(documentRecord.phaseId || 0) === Number(phase.id))) || currentPhase || activePhases[0])?.id;

        const phaseGroups = activePhases.map((phase, phaseIndex) => {
            const phaseDocs = allJobDocs.filter((documentRecord) => Number(documentRecord.phaseId || 0) === Number(phase.id) || (!documentRecord.phaseId && normalizedStatusValue(documentRecord.phase) === normalizedStatusValue(phase.name)));
            const phaseRequirements = requirements.filter((item) => Number(item.phaseId) === Number(phase.id));
            const phaseMissing = phaseRequirements.filter((item) => !item.present);
            const phaseReceived = phaseRequirements.length - phaseMissing.length;
            const isCurrent = Number(phase.id) === Number(job.phaseId);
            const expanded = isCurrent || phaseRequirements.length > 0 || Number(phase.id) === Number(firstExpandedPhaseId);
            const phaseTasks = actualTasks.filter((task) => Number(task.phaseId || 0) === Number(phase.id));
            const matchedDocIds = new Set();
            const taskGroups = phaseTasks.map((task) => {
                const taskDocs = phaseDocs.filter((documentRecord) => {
                    const linkedTask = flowtrackJobDocumentTask(documentRecord, actualTasks);
                    return linkedTask && String(linkedTask.id) === String(task.id);
                });
                if (!taskDocs.length) return '';
                taskDocs.forEach((item) => matchedDocIds.add(String(item.id)));
                const taskOwner = flowtrackResolveTaskAssigneeName(task, flowtrackResolveTaskAssigneeId(task)) || task.assignee || 'Unassigned';
                const documentRows = taskDocs.map((documentRecord) => {
                    const statusMeta = flowtrackJobDocumentViewStatus(documentRecord);
                    const fileBadge = flowtrackDocumentFileBadge(documentRecord);
                    const isRequired = requirements.some((item) => Number(item.phaseId) === Number(documentRecord.phaseId || phase.id) && Number(item.categoryId) === Number(documentRecord.categoryId) && Number(item.taskPackItemId || 0) === Number(documentRecord.taskPackItemId || 0));
                    return `<div class="job-v6-doc-row" data-job-doc-row="${html(documentRecord.id)}" data-phase="${html(phase.id)}" data-task="${html(task.id)}" data-type="${html(normalizedStatusValue(documentRecord.type || ''))}" data-status="${html(normalizedStatusValue(statusMeta.status))}" data-owner="${html(normalizedStatusValue(documentRecord.uploadedBy || ''))}" data-required="${isRequired ? '1' : '0'}" data-recent="${recentIds.has(String(documentRecord.id)) ? '1' : '0'}">
                        <div class="job-v6-doc-file"><span class="job-v6-file-icon ${html(fileBadge.toLowerCase())}">${html(fileBadge)}</span><div>${documentRecord.previewUrl ? `<a href="${html(documentRecord.previewUrl)}" target="_blank" rel="noopener">${html(documentRecord.name || 'Document')}</a>` : `<b>${html(documentRecord.name || 'Document')}</b>`}<small>${html(documentRecord.id || '')}</small></div></div>
                        <span>${html(documentRecord.type || fileBadge)}</span><span>${html(documentRecord.version || 'v1')}</span><span>${html(documentRecord.uploadedBy || job.owner || '—')}</span><span><em class="job-v6-status ${statusMeta.tone}">${html(statusMeta.status)}</em></span><span>${documentRecord.date ? html(formatDate(documentRecord.date)) : '—'}</span><span class="job-v6-row-actions"><button type="button" class="job-v6-row-more" data-doc="${html(documentRecord.id)}">•••</button>${canFlow('documents', 'delete') ? `<button type="button" class="job-v6-row-delete" data-job-doc-delete="${html(documentRecord.dbId)}" title="Delete document">×</button>` : ''}</span>
                    </div>`;
                }).join('');
                return `<div class="job-v6-task-group" data-job-doc-task-group data-task-id="${html(task.id)}"><button type="button" class="job-v6-task-head" data-job-doc-task-toggle><span class="job-v6-caret">⌄</span><span class="job-v6-task-icon">▧</span><b>${html(task.title || task.taskPackItemTitle || task.id)}</b><small>${html(task.id)} · ${html(taskOwner)}</small><span>${taskDocs.length} document${taskDocs.length === 1 ? '' : 's'}</span></button><div class="job-v6-task-body">${documentRows}</div></div>`;
            }).join('');
            const phaseRequirementRows = phaseRequirements.length ? `<div class="job-v6-phase-requirements"><div class="job-v6-phase-requirements-title"><b>Required documents</b><span>${phaseReceived} of ${phaseRequirements.length} received</span></div>${phaseRequirements.map((item) => {
                const taskForRequirement = item.taskPackItemId ? phaseTasks.find((task) => Number(task.taskPackItemId || 0) === Number(item.taskPackItemId || 0)) : null;
                const requirementStatus = item.present ? 'approved' : 'needs action';
                const taskAttribute = taskForRequirement?.id ? ` data-requirement-task="${html(taskForRequirement.id)}"` : '';
                return `<div class="job-v6-requirement-row ${item.present ? 'is-complete' : 'is-missing'}" data-job-doc-requirement-row data-phase="${html(phase.id)}"${taskAttribute} data-type="${html(normalizedStatusValue(item.categoryName))}" data-status="${html(requirementStatus)}" data-purpose-key="${html(item.key)}"><span class="job-v6-requirement-icon">${item.present ? '✓' : '!'}</span><div><b>${html(item.categoryName)}</b><small>${html(item.sourceLabel)}${item.present ? ' · Document received' : ' · Required document missing'}</small></div><em>${item.present ? 'Received' : 'Needs action'}</em>${item.present ? '' : `<button type="button" class="job-v6-upload-required" data-job-doc-upload-requirement="${html(item.key)}">Upload</button>`}</div>`;
            }).join('')}</div>` : '';
            const ungroupedDocs = phaseDocs.filter((item) => !matchedDocIds.has(String(item.id)));
            const ungroupedRows = ungroupedDocs.length ? `<div class="job-v6-task-group" data-job-doc-task-group data-task-id="general-${html(phase.id)}"><button type="button" class="job-v6-task-head" data-job-doc-task-toggle><span class="job-v6-caret">⌄</span><span class="job-v6-task-icon">▧</span><b>Phase documents</b><small>${html(phase.name)} · ${html(job.owner || 'Unassigned')}</small><span>${ungroupedDocs.length} document${ungroupedDocs.length === 1 ? '' : 's'}</span></button><div class="job-v6-task-body">${ungroupedDocs.map((documentRecord) => {
                const statusMeta = flowtrackJobDocumentViewStatus(documentRecord);
                const fileBadge = flowtrackDocumentFileBadge(documentRecord);
                const isRequired = requirements.some((item) => Number(item.phaseId) === Number(documentRecord.phaseId || phase.id) && Number(item.categoryId) === Number(documentRecord.categoryId) && !item.taskPackItemId);
                return `<div class="job-v6-doc-row" data-job-doc-row="${html(documentRecord.id)}" data-phase="${html(phase.id)}" data-type="${html(normalizedStatusValue(documentRecord.type || ''))}" data-status="${html(normalizedStatusValue(statusMeta.status))}" data-owner="${html(normalizedStatusValue(documentRecord.uploadedBy || ''))}" data-required="${isRequired ? '1' : '0'}" data-recent="${recentIds.has(String(documentRecord.id)) ? '1' : '0'}"><div class="job-v6-doc-file"><span class="job-v6-file-icon ${html(fileBadge.toLowerCase())}">${html(fileBadge)}</span><div>${documentRecord.previewUrl ? `<a href="${html(documentRecord.previewUrl)}" target="_blank" rel="noopener">${html(documentRecord.name || 'Document')}</a>` : `<b>${html(documentRecord.name || 'Document')}</b>`}<small>${html(documentRecord.id || '')}</small></div></div><span>${html(documentRecord.type || fileBadge)}</span><span>${html(documentRecord.version || 'v1')}</span><span>${html(documentRecord.uploadedBy || job.owner || '—')}</span><span><em class="job-v6-status ${statusMeta.tone}">${html(statusMeta.status)}</em></span><span>${documentRecord.date ? html(formatDate(documentRecord.date)) : '—'}</span><span class="job-v6-row-actions"><button type="button" class="job-v6-row-more" data-doc="${html(documentRecord.id)}">•••</button>${canFlow('documents', 'delete') ? `<button type="button" class="job-v6-row-delete" data-job-doc-delete="${html(documentRecord.dbId)}" title="Delete document">×</button>` : ''}</span></div>`;
            }).join('')}</div></div>` : '';
            const emptyBody = !taskGroups && !ungroupedRows && !phaseRequirementRows ? '<div class="job-v6-phase-empty">No documents or document requirements</div>' : '';
            const requirementSummary = phaseRequirements.length ? `${phaseReceived} of ${phaseRequirements.length} required` : 'No requirements';
            return `<section class="job-v6-phase-group ${expanded ? 'is-expanded' : ''}" data-job-doc-phase-group data-phase-id="${html(phase.id)}" data-has-requirements="${phaseRequirements.length ? '1' : '0'}" data-missing-count="${phaseMissing.length}"><button type="button" class="job-v6-phase-head" data-job-doc-phase-toggle><span class="job-v6-phase-caret">${expanded ? '⌄' : '›'}</span><span class="job-v6-phase-number tone-${phasePalette[phaseIndex % phasePalette.length]}">${html(phase.sequence || phaseIndex + 1)}</span><b>${html(phase.name)}</b><small>${phaseDocs.length} document${phaseDocs.length === 1 ? '' : 's'} · <em class="${phaseRequirements.length && !phaseMissing.length ? 'is-complete' : ''}">${html(requirementSummary)}</em>${isCurrent ? ' · <strong>Current phase</strong>' : ''}</small><span class="job-v6-phase-count">${phaseDocs.length}</span></button><div class="job-v6-phase-body">${phaseRequirementRows}${taskGroups}${ungroupedRows}${emptyBody}</div></section>`;
        }).join('');

        const requiredRows = requirements.map((item) => `<div class="job-v6-required-item ${item.present ? 'is-complete' : 'is-missing'}"><span>${item.present ? '✓' : '!'}</span><div><b>${html(item.categoryName)}</b><small>${html(item.phaseName)} · ${html(item.sourceLabel)} · ${item.present ? 'Received' : 'Needs action'}</small>${item.present ? '' : '<p>Required document missing.</p>'}</div>${item.present ? '' : `<button type="button" class="job-v6-required-upload" data-job-doc-upload-requirement="${html(item.key)}">Upload</button>`}</div>`).join('') || '<div class="job-v6-side-empty">No required documents configured.</div>';

        return `<section id="detailPanel8" class="detail-panel job-v3-doc-template job-v6-doc-template">
            <div class="job-v6-doc-shell">
                <main class="job-v6-doc-main">
                    <div class="job-v6-title-row"><div><h3>Documents</h3><p>${allJobDocs.length} files · ${receivedCount} of ${requirements.length} required received</p></div><div class="job-v6-title-actions"><button type="button" class="job-v6-icon-button" data-job-doc-more>•••</button></div></div>
                    <div class="job-v6-chips" data-job-doc-chips><button type="button" class="is-active" data-job-doc-chip="all">All files <span>${allJobDocs.length}</span></button><button type="button" data-job-doc-chip="required">Required <span>${requirements.length}</span></button><button type="button" class="needs" data-job-doc-chip="needs-action">Needs action <span>${missing.length + needsActionDocuments}</span></button><button type="button" class="approved" data-job-doc-chip="approved">Approved <span>${approved}</span></button><button type="button" data-job-doc-chip="recent">Recently updated <span>${recentlyUpdatedCount}</span></button></div>
                    <section class="job-v6-add-card"><div class="job-v6-card-title"><b>Add documents</b><span>Select the document type first. FlowTrack will automatically link the upload to the matching Workflow/Task Pack document requirement and Task.</span></div><label class="job-v6-purpose job-v6-upload-type"><span>Document type</span><select data-job-doc-upload-type ${uploadTypeOptions.length ? '' : 'disabled'}><option value="">${uploadTypeOptions.length ? 'Select document type' : 'No document types configured'}</option>${uploadTypeOptions.map((item) => `<option value="${html(item.id)}">${html(item.name)}</option>`).join('')}</select><small>The matching phase and Task Pack requirement are resolved automatically.</small></label><div class="job-v6-dropzone" data-job-doc-dropzone tabindex="0"><input type="file" data-job-doc-file-input accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.webp,.zip"><span class="job-v6-paperclip">⌕</span><div><b>Drop files here or <button type="button" data-job-doc-browse>browse</button></b><small>PDF, DOCX, XLSX, JPG, PNG or ZIP · Max 20 MB</small></div><button type="button" class="job-v6-outline" data-job-doc-choose>Choose from Documents</button></div><div class="job-v6-upload-progress is-hidden" data-job-doc-upload-progress><span></span><b data-job-doc-upload-name></b><em data-job-doc-upload-state>Uploading…</em></div></section>
                    <section class="job-v6-list-card"><div class="job-v6-list-head"><div><b>Job documents</b><span>Grouped by phase and related task</span></div></div><div class="job-v6-filters job-v6-filters-simple"><label class="job-v6-search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg><input type="search" placeholder="Search documents or file names" data-job-doc-search data-no-searchbar></label><select data-job-doc-type-filter aria-label="Filter documents"><option value="">Document</option>${typeOptions.map((type) => `<option value="${html(normalizedStatusValue(type))}">${html(type)}</option>`).join('')}</select></div><div class="job-v6-doc-columns"><span>Document</span><span>Type</span><span>Version</span><span>Owner</span><span>Status</span><span>Updated</span><span>Actions</span></div><div class="job-v6-phase-list" data-job-doc-phase-list>${phaseGroups || '<div class="job-v6-side-empty">No workflow phases configured.</div>'}</div><div class="job-v6-filter-empty is-hidden" data-job-doc-filter-empty>No documents match these filters.</div></section>
                </main>
                <aside class="job-v6-doc-side"><section class="job-v6-side-card"><div class="job-v6-required-head"><b>Required documents</b><span>${receivedCount} of ${requirements.length} received</span></div><div class="job-v6-required-progress"><span style="width:${completionPercent}%"></span></div><div class="job-v6-required-percent">${completionPercent}%</div><div class="job-v6-required-list">${requiredRows}</div><button type="button" class="job-v6-side-link" data-job-doc-view-requirements>View all requirements</button></section><section class="job-v6-side-card job-v6-health"><b>Document health</b><dl><dt>Approved</dt><dd class="green">${approved}</dd><dt>In review</dt><dd class="blue">${inReview}</dd><dt>Draft / needs action</dt><dd class="orange">${missing.length + needsActionDocuments}</dd></dl><div class="job-v6-health-divider"></div><dl><dt>Latest update</dt><dd>${latestDocument?.date ? html(formatDate(latestDocument.date)) : '—'}</dd></dl><button type="button" class="job-v6-side-link" data-job-doc-activity>View document activity</button></section></aside>
            </div>
        </section>`;
    }

    function flowtrackActivityPanel(job, activity, allTasks) {
        const events = [
            ...activity.map((item) => ({ actor: item.actor || 'System', title: item.title || 'Activity', text: item.text || '', time: item.time || '' })),
            ...allTasks.flatMap((task) => (task.history || []).map((entry) => ({ actor: entry.actor || 'System', title: entry.description || `Task ${task.id} updated`, text: `${task.id} · ${task.title || ''}`, time: entry.occurredAt ? flowtrackFormatDateTime(entry.occurredAt) : entry.time || '' })))
        ].slice(0, 25);
        return `<section id="detailPanel9" class="detail-panel"><div class="job-v3-panel-card"><div class="job-v3-panel-head"><div><h3>Activity</h3><p>Recent task, phase and document changes</p></div></div><div class="job-v3-activity-list">${events.map((a) => `<div class="job-v3-activity-item"><span class="avatar">${userInitials(a.actor)}</span><div><b>${html(a.actor)}</b><p>${html(a.title)}</p><small>${html(a.text)}${a.time ? ` · ${html(a.time)}` : ''}</small></div></div>`).join('') || '<div class="empty-state">No Activity recorded yet.</div>'}</div></div></section>`;
    }

    jobPanels = function (j, jt, jd) {
        const wf = getWorkflow(j.workflow || 'standard') || { phases: [], name: 'Workflow' };
        const allTasks = Array.isArray(jt) && jt.length ? jt : flowtrackJobAllTasks(j);
        const activePhases = flowtrackActivePhases(j);
        const currentPhase = activePhases.find((phase) => Number(phase.id) === Number(j.phaseId)) || activePhases[Math.max(0, Number(j.phase || 0))] || activePhases[0] || null;
        const currentTasks = jobCurrentPhaseTasks(j, allTasks);
        const allDocs = jobDocsFor(j);
        const requirements = flowtrackJobPhaseDocumentRequirements(j, activePhases);
        const missingRequirements = requirements.filter((item) => !item.present);
        const latestUpdate = (j.activity || [])[0];
        const totalQty = Number(j.qty || jobProductItems(j).reduce((sum, item) => sum + Number(item.qty || 0), 0));
        const completedTasks = allTasks.filter((task) => taskIsCompleted(task)).length;
        const openTasks = allTasks.length - completedTasks;
        const overdue = j.delivery && j.delivery < today;
        const currentProduct = j.product || jobProductItems(j)[0]?.name || 'Product not recorded';
        const nextTask = currentTasks.find((task) => !taskIsCompleted(task)) || allTasks.find((task) => !taskIsCompleted(task));
        const alertHtml = overdue || /block|risk|attention|delay/i.test(`${j.health || ''} ${j.status || ''}`)
            ? `<div class="job-v3-alert"><span>!</span><div><b>${overdue ? `Delivery overdue by ${Math.max(1, Math.ceil((new Date(today).getTime() - new Date(j.delivery).getTime()) / 86400000))} days` : `${html(j.health || 'Needs attention')}`}</b><small>${overdue ? `Deadline was ${formatDate(j.delivery)}` : html(j.next || 'This Job needs attention.')}</small></div><div><b>Next required action</b><small>${html(j.next || nextTask?.title || 'Review current phase')}</small></div>${nextTask ? `<button type="button" class="primary" data-task="${html(nextTask.id)}">Open task</button>` : ''}</div>`
            : '';
        const team = flowtrackJobTeamMembers(j, allTasks);
        const overviewTasks = flowtrackPhaseAccordion(j, allTasks, activePhases, currentPhase, true);
        const commercialStatus = typeof boardJobCommercialLabel === 'function' ? boardJobCommercialLabel(j) : (j.price ? 'Invoice pending' : 'Quotation pending');
        return `<section id="detailPanel0" class="detail-panel active job-v3-overview">${alertHtml}<div class="job-v3-metric-grid">${flowtrackJobMetricCard('▣', 'Current phase', `<span>${html(phaseNameFor(j))}</span>`, `Phase ${Number(currentPhase?.sequence || j.phase || 0)} of ${activePhases.length}`)}${flowtrackJobMetricCard('↗', 'Overall progress', `<span>${Number(j.progress || 0)}%</span>`, `<div class="job-v3-progress"><span style="width:${Math.max(0, Math.min(100, Number(j.progress || 0)))}%"></span></div>`)}${flowtrackJobMetricCard('$', 'Commercial', `<span>${j.price ? currency(j.price, j.currency) : 'Not recorded'}</span>`, `<em>${html(commercialStatus)}</em>`)}${flowtrackJobMetricCard('⬡', 'Product', `<span>${html(currentProduct)}</span>`, `${totalQty.toLocaleString()} units`)}</div>${flowtrackMiniTimeline(j, activePhases, currentPhase)}<div class="job-v3-overview-grid"><main class="job-v3-main-panel"><div class="job-v3-panel-head"><div><h3>All phase tasks</h3><p>${allTasks.length} tasks across ${activePhases.length} phases</p></div><div class="job-v3-summary-pills"><span>${completedTasks} completed</span><span>${openTasks} open</span><span>${currentTasks.length} current</span></div></div><div class="job-v3-task-source-note">Tasks are generated from Task Pack Setup and reflect the latest configured assignee, due date and status.</div>${overviewTasks}</main><aside class="job-v3-side-panel"><div class="job-v3-next-card"><h3>Next required action</h3><b>${html(nextTask?.title || j.next || 'Review current phase')}</b><div>${nextTask ? `<span class="avatar">${userInitials(nextTask.assignee || 'U')}</span> ${html(nextTask.assignee || 'Unassigned')} · <span class="due ${nextTask.due && nextTask.due < today ? 'overdue' : ''}">${nextTask.due ? formatDate(nextTask.due) : 'No due date'}</span>` : html(j.next || 'No pending task')}</div>${nextTask ? `<button type="button" class="link-btn" data-task="${html(nextTask.id)}">View task details</button>` : ''}</div><div class="job-v3-side-card"><h3>Job details</h3><dl><dt>Workflow</dt><dd>${html(wf.name || 'Workflow')}</dd><dt>Job started</dt><dd>${html(phaseNameFor(j, j.createdFromPhase ?? 0))}</dd><dt>Job lead</dt><dd>${html(j.owner || 'Unassigned')}</dd><dt>Delivery</dt><dd class="${overdue ? 'due overdue' : ''}">${j.delivery ? formatDate(j.delivery) : '—'}</dd>${jobIsCompleted(j) ? `<dt>Completed</dt><dd>${html(boardCompletedDateTime(j) || 'Not recorded')}</dd>` : ''}</dl></div><div class="job-v3-side-card"><h3>Latest update</h3><p>${latestUpdate ? `${html(latestUpdate.title || 'Updated')} · ${html(latestUpdate.time || '')}` : html(j.next || 'No update recorded yet.')}</p><button type="button" class="link-btn" data-detailtab="9">View activity</button></div></aside></div></section>
<section id="detailPanel2" class="detail-panel job-v3-tasks"><div class="job-v3-panel-card"><div class="job-v3-panel-head"><div><h3>All phase tasks</h3><p>Generated from Task Pack Setup with the latest assignee, due date, progress and status.</p></div></div>${flowtrackPhaseAccordion(j, allTasks, activePhases, currentPhase, true)}</div></section>
<section id="detailPanel1" class="detail-panel job-v3-workflow"><div class="job-v3-workflow-title"><div><h3>Workflow</h3><p>${html(wf.name || 'Workflow')} · Version ${html(wf.version || '3')} · <button type="button" class="link-btn" data-detailtab="2">View phase tasks</button></p></div></div>${/block|risk|attention|overdue|delay/i.test(`${j.health || ''} ${j.status || ''}`) || missingRequirements.length ? `<div class="job-v3-alert"><span>!</span><div><b>${html(phaseNameFor(j))} is blocked</b><small>${html((j.phaseReadinessDetails?.blockers || []).join(' ') || missingRequirements[0]?.label || j.next || 'Resolve open requirements before completion.')}</small></div><div><b>${missingRequirements.length} blocker${missingRequirements.length === 1 ? '' : 's'}</b><small>${openTasks} open tasks</small></div></div>` : ''}<div class="job-v3-workflow-metrics">${flowtrackJobMetricCard(Number(currentPhase?.sequence || j.phase || 0), 'Current phase', `<span>${html(phaseNameFor(j))}</span>`, `Phase ${Number(currentPhase?.sequence || j.phase || 0)} of ${activePhases.length}`)}${flowtrackJobMetricCard('▣', 'Phase started', `<span>${flowtrackFormatDateTime(flowtrackPhaseHistoryFor(j, currentPhase)?.enteredAt).split(',')[0]}</span>`, `${currentTasks.length} tasks in phase`)}${flowtrackJobMetricCard('□', 'Target completion', `<span class="${overdue ? 'due overdue' : ''}">${j.delivery ? formatDate(j.delivery) : 'No target'}</span>`, overdue ? 'Overdue' : 'On schedule')}${flowtrackJobMetricCard('⌁', 'Overall progress', `<span>${Number(j.progress || 0)}%</span>`, `<div class="job-v3-progress"><span style="width:${Number(j.progress || 0)}%"></span></div>`)}</div><div class="timeline clickable-workflow-timeline job-v3-workflow-line">${activePhases.map((phase, index) => flowtrackPhaseTimelineStep(j, phase, index, currentPhase, j.skippedPhases || [])).join('')}</div><div class="job-v3-workflow-grid">${flowtrackWorkflowHistoryTable(j, activePhases, allTasks)}${flowtrackCurrentGatesCard(j, currentTasks, requirements)}</div></section>
${flowtrackDocumentsPanel(j, allDocs, activePhases)}
${flowtrackActivityPanel(j, j.activity || [], allTasks)}`;
    };

    function flowtrackBindJobDocumentTemplate(content, job) {
        if (!content || !job) return;
        const panel = content.querySelector('.job-v6-doc-template');
        if (!panel) return;
        const configuredUploadRequirements = flowtrackToArray(jobDocumentRequirements(job));
        const purposes = configuredUploadRequirements.length ? flowtrackJobDocumentPurposeOptions(job, flowtrackActivePhases(job)) : [];
        const uploadTypeSelect = panel.querySelector('[data-job-doc-upload-type]');
        const purposeForPhase = (phaseId) => {
            const phasePurposes = purposes.filter((item) => Number(item.phaseId) === Number(phaseId));
            return phasePurposes.find((item) => item.requiredForReadiness && !item.present)
                || phasePurposes.find((item) => !item.present)
                || phasePurposes.find((item) => item.requiredForReadiness)
                || phasePurposes[0]
                || null;
        };
        const purposeForCategory = (categoryId, preferredPhaseId = null) => {
            const categoryPurposes = purposes.filter((item) => Number(item.categoryId) === Number(categoryId));
            if (!categoryPurposes.length) return null;
            const preferred = preferredPhaseId
                ? categoryPurposes.filter((item) => Number(item.phaseId) === Number(preferredPhaseId))
                : [];
            return preferred.find((item) => item.requiredForReadiness && !item.present)
                || preferred.find((item) => !item.present)
                || categoryPurposes.find((item) => item.requiredForReadiness && !item.present)
                || categoryPurposes.find((item) => !item.present)
                || preferred.find((item) => item.requiredForReadiness)
                || preferred[0]
                || categoryPurposes.find((item) => item.requiredForReadiness)
                || categoryPurposes[0]
                || null;
        };
        let activeUploadPhaseId = Number(job.phaseId || 0);
        let activePurposeKey = '';
        let activeUploadCategoryId = 0;
        const setAutomaticPurpose = (purpose) => {
            if (!purpose) return false;
            activeUploadPhaseId = Number(purpose.phaseId || activeUploadPhaseId || 0);
            activePurposeKey = String(purpose.key || '');
            activeUploadCategoryId = Number(purpose.categoryId || 0);
            if (uploadTypeSelect && activeUploadCategoryId) uploadTypeSelect.value = String(activeUploadCategoryId);
            return true;
        };
        const setAutomaticPurposeForPhase = (phaseId) => {
            activeUploadPhaseId = Number(phaseId || 0);
            const selectedCategoryId = Number(uploadTypeSelect?.value || activeUploadCategoryId || 0);
            const purpose = selectedCategoryId
                ? purposeForCategory(selectedCategoryId, activeUploadPhaseId)
                : purposeForPhase(activeUploadPhaseId);
            if (purpose) setAutomaticPurpose(purpose);
            return purpose;
        };
        const currentPurpose = () => {
            const exact = purposes.find((item) => String(item.key) === activePurposeKey);
            if (exact && (!activeUploadCategoryId || Number(exact.categoryId) === Number(activeUploadCategoryId))) return exact;
            const selectedCategoryId = Number(uploadTypeSelect?.value || activeUploadCategoryId || 0);
            if (!selectedCategoryId) return null;
            const resolved = purposeForCategory(selectedCategoryId, activeUploadPhaseId || job.phaseId);
            if (resolved) setAutomaticPurpose(resolved);
            return resolved;
        };
        uploadTypeSelect?.addEventListener('change', () => {
            activeUploadCategoryId = Number(uploadTypeSelect.value || 0);
            activePurposeKey = '';
            if (!activeUploadCategoryId) return;
            const resolved = purposeForCategory(activeUploadCategoryId, activeUploadPhaseId || job.phaseId);
            if (!resolved) {
                uploadTypeSelect.value = '';
                activeUploadCategoryId = 0;
                return showToast('This document type is not configured for the Job workflow or Task Pack.');
            }
            setAutomaticPurpose(resolved);
        });
        const refresh = (updatedJob = job) => flowtrackJobDocumentRefreshView(updatedJob);

        const uploadExistingResult = (result, successMessage) => {
            if (result?.document) {
                const documentIndex = docs.findIndex((item) => Number(item.dbId) === Number(result.document.dbId));
                if (documentIndex >= 0) docs.splice(documentIndex, 1, result.document); else docs.unshift(result.document);
            }
            if (result?.job) flowtrackUpsertRecord(jobs, result.job);
            if (result?.job && Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
            const updated = flowtrackFindJob(result?.job?.id || job.id) || result?.job || job;
            refresh(updated);
            updateBadges();
            showToast(result?.message || successMessage);
        };

        const uploadInline = async (file) => {
            if (!file) return;
            if (!(canFlow('documents', 'create') && canFlow('documents', 'link'))) return showToast('Your role does not have Document Upload & Link access.');
            const purpose = currentPurpose();
            if (!purpose) {
                showToast('Choose the document type before uploading. FlowTrack will link it to the matching Task Pack or Workflow requirement automatically.');
                return;
            }
            if (Number(file.size || 0) > 20 * 1024 * 1024) return showToast('The document must be 20 MB or smaller.');
            const progress = panel.querySelector('[data-job-doc-upload-progress]');
            const progressBar = progress?.querySelector('span');
            const progressName = panel.querySelector('[data-job-doc-upload-name]');
            const progressState = panel.querySelector('[data-job-doc-upload-state]');
            progress?.classList.remove('is-hidden');
            if (progressName) progressName.textContent = file.name;
            if (progressState) progressState.textContent = 'Uploading…';
            if (progressBar) progressBar.style.width = '35%';
            const formData = new FormData();
            formData.append('job_id', String(job.dbId));
            formData.append('workflow_phase_id', String(purpose.phaseId));
            if (purpose.taskPackItemId) formData.append('task_pack_item_id', String(purpose.taskPackItemId));
            if (purpose.taskId) formData.append('task_id', String(purpose.taskId));
            formData.append('document_category_id', String(purpose.categoryId));
            formData.append('file', file);
            try {
                const result = await api('/flowtrack/template-api/documents', { method: 'POST', body: formData });
                if (progressBar) progressBar.style.width = '100%';
                if (progressState) progressState.textContent = 'Uploaded';
                window.setTimeout(() => uploadExistingResult(result, 'Document uploaded and linked.'), 160);
            } catch (error) {
                if (progressState) progressState.textContent = 'Upload failed';
                if (progressBar) progressBar.style.width = '0%';
                showToast(error.message || 'Document could not be uploaded.');
            }
        };

        const fileInput = panel.querySelector('[data-job-doc-file-input]');
        const dropzone = panel.querySelector('[data-job-doc-dropzone]');
        panel.querySelectorAll('[data-job-doc-upload-requirement]').forEach((button) => button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (!(canFlow('documents', 'create') && canFlow('documents', 'link'))) return showToast('Your role does not have Document Upload & Link access.');
            const purposeKey = String(button.dataset.jobDocUploadRequirement || '');
            const purpose = purposes.find((item) => String(item.key) === purposeKey);
            if (!purpose) return showToast('This document requirement is no longer available. Refresh the Job and try again.');
            setAutomaticPurpose(purpose);
            panel.querySelector('.job-v6-add-card')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (fileInput) fileInput.value = '';
            fileInput?.click();
        }));
        panel.querySelector('[data-job-doc-browse]')?.addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); if (!currentPurpose()) return showToast('Choose the document type first.'); if (fileInput) fileInput.value = ''; fileInput?.click(); });
        fileInput?.addEventListener('change', () => { const file = fileInput.files?.[0]; if (file) void uploadInline(file); });
        dropzone?.addEventListener('dragover', (event) => { event.preventDefault(); dropzone.classList.add('is-dragging'); });
        dropzone?.addEventListener('dragleave', () => dropzone.classList.remove('is-dragging'));
        dropzone?.addEventListener('drop', (event) => { event.preventDefault(); dropzone.classList.remove('is-dragging'); const file = event.dataTransfer?.files?.[0]; if (file) void uploadInline(file); });
        dropzone?.addEventListener('keydown', (event) => { if (['Enter', ' '].includes(event.key) && !event.target.closest('button,select,input')) { event.preventDefault(); if (!currentPurpose()) return showToast('Choose the document type first.'); if (fileInput) fileInput.value = ''; fileInput?.click(); } });

        const openExistingChooser = async () => {
            if (!(canFlow('documents', 'create') && canFlow('documents', 'link'))) return showToast('Your role does not have Document Upload & Link access.');
            const purpose = currentPurpose();
            if (!purpose) return showToast('Choose the document type first.');
            let available;
            try {
                available = (await flowtrackEnsureStoredDocumentsForChooser())
                    .filter((item) => Number(item.dbId) && String(item.job || '') !== String(job.id));
            } catch (error) {
                return showToast(error.message || 'Stored documents could not be loaded.');
            }
            if (!available.length) return showToast('There are no other stored documents available to link.');
            $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>Choose from Documents</h2><div class="small muted">Link an existing stored file to ${html(job.id)} · ${html(purpose.phaseName)} · ${html(purpose.categoryName)}</div></div><button class="close-btn" data-doc-chooser-close>×</button></div><div class="modal-body"><label class="field full"><span>Search documents</span><input type="search" placeholder="Search by file, type or Job" data-existing-doc-search></label><div class="job-v6-existing-list" data-existing-doc-list>${available.map((item) => `<div class="job-v6-existing-row" data-existing-doc-row data-search="${html(normalizedStatusValue(`${item.name} ${item.type} ${item.job} ${item.uploadedBy}`))}"><span class="job-v6-file-icon ${html(flowtrackDocumentFileBadge(item).toLowerCase())}">${html(flowtrackDocumentFileBadge(item))}</span><div><b>${html(item.name || 'Document')}</b><small>${html(item.job || '')} · ${html(item.type || 'Document')} · ${html(item.uploadedBy || 'System')}</small></div><button type="button" class="primary" data-existing-doc-link="${html(item.dbId)}">Link</button></div>`).join('')}</div></div><div class="modal-foot"><button class="ghost" data-doc-chooser-close>Cancel</button></div>`;
            openOverlay(); $('#jobModal').classList.add('open');
            $$('[data-doc-chooser-close]').forEach((button) => button.addEventListener('click', flowtrackCloseModalOnly));
            $('[data-existing-doc-search]')?.addEventListener('input', (event) => { const term = normalizedStatusValue(event.target.value); $$('[data-existing-doc-row]').forEach((row) => row.classList.toggle('is-hidden', Boolean(term) && !String(row.dataset.search || '').includes(term))); });
            $$('[data-existing-doc-link]').forEach((button) => button.addEventListener('click', async () => {
                button.disabled = true; button.textContent = 'Linking…';
                try {
                    const result = await api(`/flowtrack/template-api/jobs/${job.dbId}/documents/link-existing`, { method: 'POST', body: { source_document_id: Number(button.dataset.existingDocLink), workflow_phase_id: purpose.phaseId, task_pack_item_id: purpose.taskPackItemId, task_id: purpose.taskId, document_category_id: purpose.categoryId } });
                    flowtrackCloseModalOnly(); uploadExistingResult(result, 'Stored document linked.');
                } catch (error) { button.disabled = false; button.textContent = 'Link'; showToast(error.message || 'Stored document could not be linked.'); }
            }));
        };
        panel.querySelector('[data-job-doc-choose]')?.addEventListener('click', openExistingChooser);

        const openRequestDialog = () => {
            const requestable = purposes.filter((item) => !item.present).length ? purposes.filter((item) => !item.present) : purposes;
            if (!requestable.length) return showToast('No document requirements are configured for this Job.');
            $('#jobModal').innerHTML = `<div class="modal-head"><div><h2>Request document</h2><div class="small muted">Record a document request against this Job.</div></div><button class="close-btn" data-doc-request-close>×</button></div><div class="modal-body"><div class="field full"><label>Document *</label><select data-doc-request-purpose>${requestable.map((item) => `<option value="${html(item.key)}">${html(item.categoryName)} · ${html(item.phaseName)} · ${html(item.sourceLabel)}</option>`).join('')}</select></div><div class="field full"><label>Request note</label><textarea rows="3" maxlength="2000" data-doc-request-note placeholder="Add instructions or context…"></textarea></div></div><div class="modal-foot"><button class="ghost" data-doc-request-close>Cancel</button><button class="primary" data-doc-request-submit>Send request</button></div>`;
            openOverlay(); $('#jobModal').classList.add('open');
            $$('[data-doc-request-close]').forEach((button) => button.addEventListener('click', flowtrackCloseModalOnly));
            $('[data-doc-request-submit]')?.addEventListener('click', async () => {
                const chosen = requestable.find((item) => String(item.key) === String($('[data-doc-request-purpose]')?.value || '')) || requestable[0];
                const note = $('[data-doc-request-note]')?.value.trim();
                const comment = `Document request: ${chosen.categoryName} · ${chosen.phaseName}${chosen.sourceLabel ? ` · ${chosen.sourceLabel}` : ''}${note ? `. ${note}` : '.'}`;
                const button = $('[data-doc-request-submit]'); button.disabled = true; button.textContent = 'Sending…';
                try {
                    const result = await api(`/flowtrack/template-api/jobs/${job.dbId}/comments`, { method: 'POST', body: { comment } });
                    if (result.job) flowtrackUpsertRecord(jobs, result.job);
                    flowtrackCloseModalOnly(); showToast('Document request recorded in Job activity.');
                } catch (error) { button.disabled = false; button.textContent = 'Send request'; showToast(error.message || 'Document request could not be recorded.'); }
            });
        };
        panel.querySelectorAll('[data-job-doc-request]').forEach((button) => button.addEventListener('click', openRequestDialog));
        panel.querySelector('[data-job-doc-more]')?.addEventListener('click', () => showToast('Use the document actions in the list for file-specific options.'));

        panel.querySelectorAll('[data-job-doc-delete]').forEach((button) => button.addEventListener('click', (event) => {
            event.preventDefault(); event.stopPropagation();
            const documentRecord = docs.find((item) => Number(item.dbId) === Number(button.dataset.jobDocDelete));
            if (!documentRecord) return showToast('The selected document could not be found.');
            flowtrackConfirmDeleteDocument(documentRecord, (result) => {
                const updated = flowtrackFindJob(result?.job?.id || job.id) || result?.job || job;
                refresh(updated);
            });
        }));
        panel.querySelectorAll('[data-job-doc-phase-toggle]').forEach((button) => button.addEventListener('click', () => { const group = button.closest('[data-job-doc-phase-group]'); if (group?.dataset.phaseId) setAutomaticPurposeForPhase(Number(group.dataset.phaseId)); group?.classList.toggle('is-expanded'); const caret = button.querySelector('.job-v6-phase-caret'); if (caret) caret.textContent = group?.classList.contains('is-expanded') ? '⌄' : '›'; }));
        panel.querySelectorAll('[data-job-doc-task-toggle]').forEach((button) => button.addEventListener('click', () => { const group = button.closest('[data-job-doc-task-group]'); group?.classList.toggle('is-collapsed'); const caret = button.querySelector('.job-v6-caret'); if (caret) caret.textContent = group?.classList.contains('is-collapsed') ? '›' : '⌄'; }));

        let chip = 'all';
        const applyFilters = () => {
            const term = normalizedStatusValue(panel.querySelector('[data-job-doc-search]')?.value || '');
            const phase = '';
            const task = '';
            const type = String(panel.querySelector('[data-job-doc-type-filter]')?.value || '');
            const status = '';
            const owner = '';
            const currentOnly = false;
            let visibleRows = 0;
            panel.querySelectorAll('[data-job-doc-phase-group]').forEach((phaseGroup) => {
                const phaseMatchesBase = (!phase || String(phaseGroup.dataset.phaseId) === phase) && (!currentOnly || Number(phaseGroup.dataset.phaseId) === Number(job.phaseId));
                let phaseVisibleRows = 0;
                let visibleRequirementRows = 0;
                phaseGroup.querySelectorAll('[data-job-doc-row]').forEach((row) => {
                    let matches = phaseMatchesBase && (!term || normalizedStatusValue(row.textContent).includes(term)) && (!task || String(row.dataset.task || '') === task) && (!type || row.dataset.type === type) && (!status || row.dataset.status === status) && (!owner || row.dataset.owner === owner);
                    if (chip === 'required') matches = matches && row.dataset.required === '1';
                    if (chip === 'approved') matches = matches && row.dataset.status === 'approved';
                    if (chip === 'recent') matches = matches && row.dataset.recent === '1';
                    if (chip === 'needs-action') matches = false;
                    row.classList.toggle('is-filtered-out', !matches);
                    if (matches) { phaseVisibleRows++; visibleRows++; }
                });
                phaseGroup.querySelectorAll('[data-job-doc-requirement-row]').forEach((row) => {
                    let matches = phaseMatchesBase
                        && (!term || normalizedStatusValue(row.textContent).includes(term))
                        && (!task || String(row.dataset.task || '') === task)
                        && (!type || row.dataset.type === type)
                        && (!status || row.dataset.status === status)
                        && !owner;
                    if (chip === 'needs-action') matches = matches && row.dataset.status === 'needs action';
                    if (chip === 'approved') matches = matches && row.dataset.status === 'approved';
                    if (chip === 'recent') matches = false;
                    row.classList.toggle('is-filtered-out', !matches);
                    if (matches) visibleRequirementRows++;
                });
                phaseGroup.querySelectorAll('[data-job-doc-task-group]').forEach((taskGroup) => {
                    const taskRows = [...taskGroup.querySelectorAll('[data-job-doc-row]')];
                    const visibleTaskRows = taskRows.filter((row) => !row.classList.contains('is-filtered-out')).length;
                    taskGroup.classList.toggle('is-filtered-out', taskRows.length ? visibleTaskRows === 0 : true);
                });
                const showEmptyInitial = chip === 'all' && !term && !phase && !task && !type && !status && !owner && !currentOnly;
                const showPhase = phaseMatchesBase && (phaseVisibleRows > 0 || visibleRequirementRows > 0 || showEmptyInitial);
                phaseGroup.classList.toggle('is-filtered-out', !showPhase);
                if ((chip === 'required' || chip === 'needs-action') && visibleRequirementRows > 0) phaseGroup.classList.add('is-expanded');
            });
            const anyPhase = [...panel.querySelectorAll('[data-job-doc-phase-group]')].some((group) => !group.classList.contains('is-filtered-out'));
            panel.querySelector('[data-job-doc-filter-empty]')?.classList.toggle('is-hidden', anyPhase);
        };
        ['[data-job-doc-search]','[data-job-doc-type-filter]'].forEach((selector) => {
            const input = panel.querySelector(selector); if (!input) return; input.addEventListener(input.tagName === 'INPUT' && input.type === 'search' ? 'input' : 'change', applyFilters);
        });
        panel.querySelectorAll('[data-job-doc-chip]').forEach((button) => button.addEventListener('click', () => { chip = button.dataset.jobDocChip || 'all'; panel.querySelectorAll('[data-job-doc-chip]').forEach((other) => other.classList.toggle('is-active', other === button)); applyFilters(); }));
        panel.querySelector('[data-job-doc-view-requirements]')?.addEventListener('click', () => { const button = panel.querySelector('[data-job-doc-chip="required"]'); button?.click(); panel.querySelector('.job-v6-list-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
        panel.querySelector('[data-job-doc-activity]')?.addEventListener('click', () => { const activityTab = content.querySelector('[data-detailtab="9"]'); if (activityTab) activityTab.click(); else showToast('Document uploads and requests are recorded in Job activity.'); });
        applyFilters();
    }

    function flowtrackApplyJobDetailReferenceDesign(job) {
        const page = document.querySelector(`#page-record-job-${flowtrackJobDetailSlug(job)} .standalone-record-page`);
        const content = page?.querySelector('.standalone-record-content');
        if (!page || !content) return;
        page.classList.add('job-detail-v3-page');
        content.classList.add('job-detail-v3-content');
        const allTasks = flowtrackJobAllTasks(job);
        const allDocs = jobDocsFor(job);
        const header = content.querySelector('.drawer-head');
        if (header) {
            const team = flowtrackJobTeamMembers(job, allTasks);
            header.className = 'drawer-head job-v3-header';
            const canShowJobHeaderActions = flowtrackCurrentUserAssignedToJob(job, allTasks);
            const teamHtml = team.length ? `<div class="job-v3-team">${team.slice(0, 4).map((member) => `<span class="avatar" title="${html(member.name)}">${html(member.initials)}</span>`).join('')}${team.length > 4 ? `<span class="job-v3-more">+${team.length - 4}</span>` : ''}</div>` : '<div class="job-v3-team job-v3-team-empty"></div>';
            header.innerHTML = `<div class="job-v3-head-main"><div class="job-v3-breadcrumb">Jobs / ${html(job.id)}</div><h2>${html(job.title || job.id)}</h2><div class="job-v3-subtitle">${html(clientName(job.client))}${job.order ? ` · ${html(job.order)}` : ''}</div><div class="job-v3-head-meta">${flowtrackJobHealthPill(job)}${statusBadge(job.status || 'In Progress')}<span class="job-v3-lead-label">Job lead</span><span class="avatar">${userInitials(job.owner || 'U')}</span><b>${html(job.owner || 'Unassigned')}</b></div></div>${teamHtml}<div class="job-v3-header-actions">${canShowJobHeaderActions && canEditJobRecord() ? '<button type="button" class="ghost" data-job-v3-edit>✎ Edit job</button>' : ''}${canShowJobHeaderActions && canFlow('jobs', 'delete') ? '<button type="button" class="ghost" data-job-v3-actions>••• Job actions</button>' : ''}<button type="button" class="close-btn" data-standalone-back>×</button></div>`;
            header.querySelector('[data-job-v3-edit]')?.addEventListener('click', () => openEditJobModal(job));
            header.querySelector('[data-job-v3-actions]')?.addEventListener('click', () => openDeleteJobConfirmation(job));
        }
        const tabs = content.querySelector('.detail-tabs');
        if (tabs) {
            tabs.innerHTML = `<button class="detail-tab active" data-detailtab="0">Overview</button><button class="detail-tab" data-detailtab="2">Tasks <span>${allTasks.length}</span></button><button class="detail-tab" data-detailtab="1">Workflow</button><button class="detail-tab" data-detailtab="8">Documents <span>${allDocs.length}</span></button><button class="detail-tab" data-detailtab="9">Activity</button>`;
        }
        bindStandaloneBack(page);
        bindStandaloneDetailTabs(content);
        activateScopedDetailTab(content, 0);
        content.querySelectorAll('[data-job-v3-toggle-phase]').forEach((button) => {
            if (button.dataset.boundToggle === '1') return;
            button.dataset.boundToggle = '1';
            button.addEventListener('click', (event) => {
                event.preventDefault();
                const body = button.closest('.job-v3-phase-block')?.querySelector('.job-v3-phase-body');
                body?.classList.toggle('hidden');
                const caret = button.querySelector('.job-v3-phase-caret');
                if (caret) caret.textContent = body?.classList.contains('hidden') ? '›' : '⌄';
            });
        });
        content.querySelector('[data-job-v3-expand-all]')?.addEventListener('click', (event) => {
            event.preventDefault();
            content.querySelectorAll('.job-v3-phase-body').forEach((body) => body.classList.remove('hidden'));
            content.querySelectorAll('.job-v3-phase-caret').forEach((caret) => { caret.textContent = '⌄'; });
        });
        bindJobDocumentUploadButtons(content, job, 8);
        flowtrackBindJobDocumentTemplate(content, job);
        applyActionPermissions();
    }

    const flowtrackJobDetailPreviousOpenJob = currentFlowtrackImplementation('openJob');
    registerFlowtrackExtension('openJob', 'openJob-reference-design-final', function (id) {
        flowtrackJobDetailPreviousOpenJob(id);
        const job = flowtrackFindJob(id);
        if (!job) return;
        // The v3 reference design is only a compatibility fallback. When the v4
        // final module is present it must never schedule a repaint after v4.
        if (window.FLOWTRACK_FINAL_JOB_RENDERER_READY === true) return job;
        setTimeout(() => flowtrackApplyJobDetailReferenceDesign(job), 0);
        if (job._detailLoaded) return;
        window.setTimeout(async () => {
            const content = document.querySelector(`#page-record-job-${flowtrackJobDetailSlug(job)} .standalone-record-content`);
            if (content) {
                const activePanel = content.querySelector('.detail-panel.active');
                activePanel?.insertAdjacentHTML('afterbegin', flowtrackInlineProgress('Refreshing task setup values…'));
            }
            try {
                const result = await flowtrackRefreshJobTasks(job);
                const updatedJob = flowtrackFindJob(result?.job?.id || id) || job;
                flowtrackEnsureJobPanels(updatedJob);
                flowtrackApplyJobDetailReferenceDesign(updatedJob);
            } catch (error) {
                document.querySelectorAll('.flowtrack-inline-progress').forEach((node) => node.remove());
            }
        }, 80);
    });


    const flowtrackJobDetailPreviousEnsurePanels = flowtrackEnsureJobPanels;
    flowtrackEnsureJobPanels = function (job, preferredTab = null) {
        flowtrackJobDetailPreviousEnsurePanels(job, preferredTab);
        if (window.FLOWTRACK_FINAL_JOB_RENDERER_READY === true && typeof flowtrackJobV4Apply === 'function') {
            flowtrackJobV4Apply(job, preferredTab ?? 0);
            return;
        }
        flowtrackApplyJobDetailReferenceDesign(job);
        const content = document.querySelector(`#page-record-job-${flowtrackJobDetailSlug(job)} .standalone-record-content`);
        if (content && preferredTab !== null && preferredTab !== undefined) activateScopedDetailTab(content, preferredTab);
    };

    
    /* CSS externalized: flowtrackJobDetailReferenceStyles. */

    
    /* CSS externalized: flowtrackJobDetailCompactFinalStyles. */

    
    /* CSS externalized: flowtrackTaskDocumentAndPhaseHistoryStyles. */



    
    /* CSS externalized: flowtrackLatestJobsAndBoardExpandStyles. */


    
    /* CSS externalized: flowtrackAttachmentTemplateStyles. */


    function flowtrackReplaceDocumentsForJob(job, newDocuments) {
        if (!job) return;
        const jobCode = String(job.id || '');
        const jobDbId = Number(job.dbId || 0);
        for (let index = docs.length - 1; index >= 0; index -= 1) {
            const documentRecord = docs[index];
            if (String(documentRecord?.job || '') === jobCode || (jobDbId > 0 && Number(documentRecord?.jobDbId || 0) === jobDbId)) {
                docs.splice(index, 1);
            }
        }
        flowtrackToArray(newDocuments).forEach((documentRecord) => docs.push(documentRecord));
    }

    function flowtrackMergeDetailPayload(payload, options = {}) {
        if (!payload || typeof payload !== 'object') return payload;
        const mergedJob = payload.job ? flowtrackUpsertRecord(jobs, payload.job) : null;
        if (payload.client) flowtrackUpsertRecord(clients, payload.client);
        if (payload.task) flowtrackUpsertRecord(tasks, payload.task);
        if (payload.document) flowtrackUpsertRecord(docs, payload.document);

        const completeJob = mergedJob || payload.job || null;
        const completeTaskSet = Boolean(options.completeJobSnapshot && payload?.taskSet?.complete && completeJob && Array.isArray(payload.tasks));
        if (completeTaskSet) {
            // Job Details must own one authoritative task snapshot. Keep a private
            // copy on the Job as well as updating the shared cache; later paginated
            // page loads are then unable to make phase Tasks disappear underneath an
            // already-open Job Details screen.
            const completeTasks = payload.tasks.map((task) => flowtrackHydrateTaskAssignee({ ...task }));
            completeJob._detailTasks = completeTasks;
            completeJob._completeTaskSetLoaded = true;
            replaceTasksForJob(completeJob.id, completeTasks);
        } else if (Array.isArray(payload.tasks)) {
            payload.tasks.forEach((task) => flowtrackUpsertRecord(tasks, task, false));
        }

        if (options.completeJobSnapshot && completeJob && Array.isArray(payload.documents)) {
            flowtrackReplaceDocumentsForJob(completeJob, payload.documents);
        } else if (Array.isArray(payload.documents)) {
            payload.documents.forEach((document) => flowtrackUpsertRecord(docs, document, false));
        }
        if (Array.isArray(payload.jobs)) {
            payload.jobs.forEach((job) => flowtrackUpsertRecord(jobs, job, false));
        }
        return { ...payload, job: mergedJob || payload.job };
    }

    const flowtrackJobDetailRequests = new Map();

    async function flowtrackEnsureJobDetail(identifier, forceFresh = true) {
        const current = jobs.find((item) => String(item.id) === String(identifier) || Number(item.dbId) === Number(identifier));
        if (!forceFresh && current?._detailLoaded && current?._completeTaskSetLoaded) return current;

        const requestIdentifier = current?.dbId || current?.id || identifier;
        const requestKey = String(requestIdentifier);
        if (flowtrackJobDetailRequests.has(requestKey)) return flowtrackJobDetailRequests.get(requestKey);

        const request = (async () => {
            let payload = flowtrackMergeDetailPayload(await api(
                `/flowtrack/template-api/jobs/${encodeURIComponent(requestIdentifier)}?_=${Date.now()}`,
                { cache: 'no-store' },
            ), { completeJobSnapshot: true });

            const completePersistedTaskSet = (candidate) => Boolean(
                candidate?.taskSet?.complete
                && candidate?.taskSet?.persisted
                && Array.isArray(candidate?.tasks)
                && Number(candidate.taskSet.count) === candidate.tasks.length
            );

            // Older/cached API responses may not carry the persisted completeness
            // marker. In that case fetch the dedicated authoritative Task endpoint
            // before allowing Job Details to render.
            if (!completePersistedTaskSet(payload)) {
                const refreshedJob = payload?.job || current;
                const jobTaskIdentifier = refreshedJob?.dbId || requestIdentifier;
                const taskPayload = await api(
                    `/flowtrack/template-api/jobs/${encodeURIComponent(jobTaskIdentifier)}/tasks?_=${Date.now()}`,
                    { cache: 'no-store' },
                );
                payload = flowtrackMergeDetailPayload(taskPayload, { completeJobSnapshot: true }) || payload;
            }

            if (!completePersistedTaskSet(payload)) {
                throw new Error('The complete phase Task set could not be loaded. Please try again.');
            }

            const job = payload?.job || jobs.find((item) => String(item.id) === String(identifier) || Number(item.dbId) === Number(identifier)) || current || null;
            if (job) {
                job._detailLoaded = true;
                job._completeTaskSetLoaded = true;
                job._detailTaskCount = Number(payload.taskSet.count);
                job._detailLoadedAt = Date.now();
            }
            return job;
        })().finally(() => flowtrackJobDetailRequests.delete(requestKey));

        flowtrackJobDetailRequests.set(requestKey, request);
        return request;
    }

    const flowtrackTaskDetailRequests = new Map();

    async function flowtrackEnsureTaskDetail(identifier, forceFresh = true) {
        const normalizedIdentifier = String(identifier ?? '').trim();
        if (!normalizedIdentifier) return null;
        const current = tasks.find((item) => String(item.id) === normalizedIdentifier || Number(item.dbId) === Number(identifier));
        if (!forceFresh && current?._detailLoaded) return current;

        const requestIdentifier = current?.dbId || current?.id || identifier;
        const requestKey = String(requestIdentifier);
        if (flowtrackTaskDetailRequests.has(requestKey)) return flowtrackTaskDetailRequests.get(requestKey);

        const request = (async () => {
            const payload = flowtrackMergeDetailPayload(await api(
                `/flowtrack/template-api/tasks/${encodeURIComponent(requestIdentifier)}`,
                { cache: 'no-store' },
            ));
            return payload?.task || current || null;
        })().finally(() => flowtrackTaskDetailRequests.delete(requestKey));
        flowtrackTaskDetailRequests.set(requestKey, request);
        return request;
    }

    async function flowtrackEnsureClientDetail(identifier) {
        const current = clients.find((item) => String(item.id) === String(identifier) || Number(item.dbId) === Number(identifier) || String(item.code) === String(identifier));
        if (current?._detailLoaded) return current;
        const payload = flowtrackMergeDetailPayload(await api(`/flowtrack/template-api/clients/${encodeURIComponent(identifier)}`, { cache: 'no-store' }));
        return payload?.client || current || null;
    }

    async function flowtrackEnsureDocumentDetail(identifier) {
        const current = docs.find((item) => String(item.id) === String(identifier) || Number(item.dbId) === Number(identifier));
        if (current?._detailLoaded) return current;
        const payload = flowtrackMergeDetailPayload(await api(`/flowtrack/template-api/documents/${encodeURIComponent(identifier)}`, { cache: 'no-store' }));
        return payload?.document || current || null;
    }

    const flowtrackPerformanceOpenJob = currentFlowtrackImplementation('openJob');
    let flowtrackJobOpenSequence = 0;
    registerFlowtrackExtension('openJob', 'openJob-performance-detail-loader', async function (id) {
        const sequence = ++flowtrackJobOpenSequence;
        try {
            // Always refresh the full Job snapshot before rendering. Jobs, Board and
            // My Work may each hold different paginated task slices; none of those
            // slices is authoritative for the standalone Job Details screen.
            const job = await flowtrackEnsureJobDetail(id, true);
            if (sequence !== flowtrackJobOpenSequence) return null;
            if (!job) return showToast('Job could not be loaded.');
            return flowtrackPerformanceOpenJob(job.id || job.dbId);
        } catch (error) {
            if (sequence === flowtrackJobOpenSequence) showToast(error.message || 'Job could not be loaded.');
        }
    });

    const flowtrackPerformanceOpenTask = currentFlowtrackImplementation('openTask');
    let flowtrackTaskOpenSequence = 0;
    registerFlowtrackExtension('openTask', 'openTask-performance-detail-loader', async function (id) {
        const sequence = ++flowtrackTaskOpenSequence;
        try {
            // Always fetch Task Details from its authoritative endpoint. Job Details can
            // preload a task snapshot, but that snapshot must never become a permanent
            // cache for the standalone Task Details screen.
            const task = await flowtrackEnsureTaskDetail(id, true);
            if (sequence !== flowtrackTaskOpenSequence) return null;
            if (!task) return showToast('Task could not be loaded.');
            return flowtrackPerformanceOpenTask(task.id || task.dbId);
        } catch (error) {
            if (sequence === flowtrackTaskOpenSequence) showToast(error.message || 'Task could not be loaded.');
        }
    });

    const flowtrackPerformanceOpenClient = openClient;
    openClient = async function (id) {
        try {
            const client = await flowtrackEnsureClientDetail(id);
            if (!client) return showToast('Client could not be loaded.');
            return flowtrackPerformanceOpenClient(client.id || client.code);
        } catch (error) {
            showToast(error.message || 'Client could not be loaded.');
        }
    };

    const flowtrackPerformanceOpenDocument = openDoc;
    openDoc = async function (id) {
        try {
            const documentRecord = await flowtrackEnsureDocumentDetail(id);
            if (!documentRecord) return showToast('Document could not be loaded.');
            return flowtrackPerformanceOpenDocument(documentRecord.id || documentRecord.dbId);
        } catch (error) {
            showToast(error.message || 'Document could not be loaded.');
        }
    };

    function flowtrackSegmentCollection(segment) {
        return ({ clients, jobs, tasks, documents: docs })[segment] || [];
    }

    async function flowtrackLoadNextSegment(segment) {
        const pagination = segmentPagination[segment];
        const nextUrl = pagination?.nextPageUrl;
        if (!nextUrl || segmentLoading.has(segment)) return;
        segmentLoading.add(segment);
        try {
            const page = await api(nextUrl, { cache: 'no-store' });
            const target = flowtrackSegmentCollection(segment);
            flowtrackToArray(page?.data).forEach((record) => flowtrackUpsertRecord(target, record, false));
            flowtrackToArray(page?.relatedJobs).forEach((job) => flowtrackUpsertRecord(jobs, job, false));
            flowtrackToArray(page?.relatedClients).forEach((client) => flowtrackUpsertRecord(clientLabels, client, false));
            segmentPagination[segment] = page?.pagination || null;
            if (!segmentPagination[segment]?.nextPageUrl) segmentLoadState[segment] = 'all';
            else if (segmentLoadState[segment] === 'none') segmentLoadState[segment] = 'page';
            if (segment === 'tasks' && typeof updateBadges === 'function') updateBadges();
            renderPage(currentPage);
            applyActionPermissions();
        } catch (error) {
            showToast(error.message || 'More records could not be loaded.');
        } finally {
            segmentLoading.delete(segment);
        }
    }

    function flowtrackPaginationSegmentsForPage(page) {
        if (page === 'clients') return ['clients'];
        if (page === 'documents') return ['documents'];
        if (page === 'mywork') return [];
        if (page === 'board') return ['jobs', 'tasks'];
        if (page === 'jobs') return ['jobs'];
        if (page === 'reports') return ['jobs', 'tasks'];
        return [];
    }

    let flowtrackAutoLoadScheduled = false;
    let flowtrackAutoLoadRunning = false;
    const flowtrackAutoScrollTargets = new WeakSet();

    function flowtrackPageHasNextSegments(page) {
        return flowtrackPaginationSegmentsForPage(page)
            .filter((segment) => Boolean(segmentPagination[segment]?.nextPageUrl));
    }

    function flowtrackIsNearVerticalEnd(target, threshold = 280) {
        if (target && target !== window && target !== document) {
            return target.scrollHeight - target.scrollTop - target.clientHeight <= threshold;
        }
        const doc = document.documentElement;
        const scrollTop = window.scrollY || doc.scrollTop || document.body.scrollTop || 0;
        const viewport = window.innerHeight || doc.clientHeight || 0;
        const scrollHeight = Math.max(doc.scrollHeight || 0, document.body.scrollHeight || 0);
        return scrollHeight - scrollTop - viewport <= Math.max(threshold, 520);
    }

    function flowtrackBindAutoPaginationScrollers(page) {
        if (page !== 'board' && page !== 'mywork') return;
        if (!flowtrackAutoScrollTargets.has(window)) {
            flowtrackAutoScrollTargets.add(window);
            window.addEventListener('scroll', flowtrackScheduleAutoPaginationCheck, { passive: true });
            window.addEventListener('resize', flowtrackScheduleAutoPaginationCheck, { passive: true });
        }
        document.querySelectorAll(`#page-${page} .ops-board-canvas`).forEach((canvas) => {
            if (flowtrackAutoScrollTargets.has(canvas)) return;
            flowtrackAutoScrollTargets.add(canvas);
            canvas.addEventListener('scroll', flowtrackScheduleAutoPaginationCheck, { passive: true });
        });
    }

    function flowtrackScheduleAutoPaginationCheck() {
        if (flowtrackAutoLoadScheduled) return;
        flowtrackAutoLoadScheduled = true;
        requestAnimationFrame(() => {
            flowtrackAutoLoadScheduled = false;
            void flowtrackMaybeAutoLoadCurrentPageSegments();
        });
    }

    function flowtrackAutoLoadStatusText(segments) {
        const labels = segments.map((segment) => segment === 'jobs' ? 'jobs' : segment === 'tasks' ? 'tasks' : segment);
        if (labels.length <= 1) return labels[0] || 'records';
        return `${labels.slice(0, -1).join(', ')} and ${labels[labels.length - 1]}`;
    }

    async function flowtrackMaybeAutoLoadCurrentPageSegments() {
        if (flowtrackAutoLoadRunning || (currentPage !== 'board' && currentPage !== 'mywork')) return;
        const page = currentPage;
        const segments = flowtrackPageHasNextSegments(page);
        if (!segments.length) return;
        const boardCanvas = document.querySelector(`#page-${page}.active .ops-board-canvas`);
        const nearPageEnd = flowtrackIsNearVerticalEnd(window);
        const nearCanvasEnd = boardCanvas ? flowtrackIsNearVerticalEnd(boardCanvas) : false;
        if (!nearPageEnd && !nearCanvasEnd) return;

        flowtrackAutoLoadRunning = true;
        const status = document.querySelector(`#page-${page} .flowtrack-auto-load span`);
        if (status) status.textContent = `Loading more ${flowtrackAutoLoadStatusText(segments)}…`;
        try {
            for (const segment of segments) {
                if (segmentPagination[segment]?.nextPageUrl) {
                    await flowtrackLoadNextSegment(segment);
                }
            }
        } finally {
            flowtrackAutoLoadRunning = false;
            // Board may keep filling its viewport automatically. My Work must
            // wait for another real scroll event so it loads one page at a time
            // instead of silently fetching the entire task dataset.
            if (page === 'board') setTimeout(flowtrackScheduleAutoPaginationCheck, 120);
        }
    }

    function flowtrackRenderPaginationControls(page) {
        const section = document.getElementById(`page-${page}`);
        if (!section) return;
        section.querySelectorAll('.flowtrack-load-more').forEach((node) => node.remove());
        const segments = flowtrackPageHasNextSegments(page);
        if (!segments.length) return;
        const controls = document.createElement('div');
        controls.className = page === 'board' ? 'flowtrack-load-more flowtrack-auto-load' : 'flowtrack-load-more';
        if (page === 'board') {
            controls.innerHTML = `<span>Scroll vertically to load more ${flowtrackAutoLoadStatusText(segments)}.</span>`;
            section.appendChild(controls);
            flowtrackBindAutoPaginationScrollers(page);
            flowtrackScheduleAutoPaginationCheck();
            return;
        }
        if (page === 'mywork') {
            // Bind the scroll listener, but do not immediately schedule another
            // fetch after render. This keeps My Work genuinely page-by-page.
            flowtrackBindAutoPaginationScrollers(page);
            return;
        }
        controls.innerHTML = segments.map((segment) => `<button type="button" class="ghost" data-load-segment="${segment}">Load more ${segment === 'documents' ? 'documents' : segment}</button>`).join('');
        section.appendChild(controls);
        controls.querySelectorAll('[data-load-segment]').forEach((button) => button.addEventListener('click', async () => {
            button.disabled = true;
            button.textContent = 'Loading…';
            await flowtrackLoadNextSegment(button.dataset.loadSegment);
        }));
    }

    const flowtrackPerformanceRenderPage = renderPage;
    renderPage = function (page) {
        const result = flowtrackPerformanceRenderPage(page);
        queueMicrotask(() => flowtrackRenderPaginationControls(page));
        return result;
    };

    
    /* CSS externalized: flowtrackPaginationStyles. */

    // Startup is owned by the route-aware asset loader.

    /* CSS externalized: flowtrackDocumentTemplateEverywhereStyles. */

    
    /* CSS externalized: flowtrackJobDocumentReferenceExactStyles. */


    
    /* CSS externalized: flowtrackJobDocumentsFinalPrototypeStyles. */


    
    /* CSS externalized: flowtrackJobDocumentsReadableScaleStyles. */

    
    /* CSS externalized: flowtrackJobDocumentRequirementsAllPhasesStyles. */
