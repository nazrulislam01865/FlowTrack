/* FlowTrack runtime module: job-details. Build: f400609959067571. */
function flowtrackJobV4Workflow(job) {
        return getWorkflow(job?.workflow || 'standard') || { phases: [], name: job?.workflowName || 'Workflow' };
    }
    function flowtrackJobV4BulkIcon(kind) {
        if (kind === 'collapse') {
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 17 5-5 5 5"/><path d="m7 12 5-5 5 5"/></svg>';
        }
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 7 5 5 5-5"/><path d="m7 12 5 5 5-5"/></svg>';
    }


    function flowtrackJobV4ConfiguredPhaseTasks(job, phase, actualTasks = null) {
        const actual = flowtrackTasksForPhase(job, phase.id, actualTasks || flowtrackJobAllTasks(job))
            .map((task) => ({ ...flowtrackHydrateTaskAssignee(task), planned: false }));
        const actualPackItems = new Set(actual.map((task) => Number(task.taskPackItemId || 0)).filter(Boolean));
        const packId = Number(phase.taskPackId || phase.task_pack_id || 0);
        const pack = flowtrackToArray(taskPacks).find((item) => Number(item.id) === packId);
        const configured = flowtrackToArray(pack?.items)
            .filter((item) => !actualPackItems.has(Number(item.id)))
            .sort((left, right) => Number(left.sort_order ?? 9999) - Number(right.sort_order ?? 9999))
            .map((item) => ({
                planned: true,
                id: `setup-${phase.id}-${item.id}`,
                dbId: null,
                job: job.id,
                jobDbId: job.dbId,
                phaseId: phase.id,
                phase: phase.name,
                taskPackItemId: Number(item.id),
                title: item.title,
                description: item.description || '',
                assigneeId: item.default_assignee_id ? Number(item.default_assignee_id) : null,
                assignee: item.default_assignee_name || item.default_department_name || job.coordinator || job.owner || 'Unassigned',
                due: '',
                status: 'Not started',
                statusId: (masterData.taskStatuses || []).find((status) => /^(ready|not started|new)$/i.test(String(status.name || '')))?.id || (masterData.taskStatuses || [])[0]?.id || null,
                progress: 0,
                canUpdate: Boolean(isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin || canFlow('tasks', 'create')),
                canAssign: Boolean(isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin || canFlow('tasks', 'assign')),
                canChangeDueDate: Boolean(isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin || canFlow('tasks', 'edit_all') || canFlow('tasks', 'edit_own')),
                documentRequirement: item.document_category_id ? {
                    source: 'task_pack_item',
                    phaseId: Number(phase.id),
                    taskPackItemId: Number(item.id),
                    taskId: null,
                    categoryId: Number(item.document_category_id),
                    categoryName: item.document_category_name || 'Required document',
                } : null,
                setupLabel: pack?.name || 'Task Setup',
            }));
        return [...actual, ...configured].sort((left, right) => {
            const leftSort = Number(left.sortOrder ?? left.sort_order ?? 9999);
            const rightSort = Number(right.sortOrder ?? right.sort_order ?? 9999);
            if (leftSort !== rightSort) return leftSort - rightSort;
            return String(left.title || '').localeCompare(String(right.title || ''));
        });
    }

    function flowtrackJobV4AllConfiguredTasks(job, actualTasks = null) {
        const phases = flowtrackActivePhases(job);
        const actual = actualTasks || flowtrackJobAllTasks(job);
        return phases.flatMap((phase) => flowtrackJobV4ConfiguredPhaseTasks(job, phase, actual));
    }

    function flowtrackJobV4TaskStatusOptions(task) {
        const statuses = (masterData.taskStatuses || []).filter((status) => String(status.status || 'Active').toLowerCase() === 'active');
        return statuses.map((status) => `<option value="${html(status.id)}" ${Number(status.id) === Number(task.statusId) ? 'selected' : ''}>${html(status.name)}</option>`).join('');
    }

    function flowtrackJobV4AssigneeOptions(task) {
        const members = typeof flowtrackWorkspaceMembers === 'function' ? flowtrackWorkspaceMembers() : flowtrackToArray(users);
        return `<option value="">Unassigned</option>${members.map((member) => `<option value="${html(member.id)}" ${Number(member.id) === Number(task.assigneeId) ? 'selected' : ''}>${html(member.name)}</option>`).join('')}`;
    }

    function flowtrackJobV4StatusIsComplete(statusId) {
        const status = flowtrackToArray(masterData.taskStatuses).find((item) => Number(item.id) === Number(statusId));
        const key = normalizedStatusValue(status?.code || status?.name || '');
        return ['CMP', 'COMPLETED', 'COMPLETE', 'DONE'].includes(key);
    }

    function flowtrackJobV4TaskDocumentRequirement(task, phaseId = null, packItemId = null) {
        const requirement = task?.documentRequirement || task?.document_requirement || null;
        if (requirement?.categoryId || requirement?.category_id) {
            return {
                phaseId: Number(requirement.phaseId || requirement.phase_id || phaseId || task?.phaseId || 0),
                taskPackItemId: Number(requirement.taskPackItemId || requirement.task_pack_item_id || packItemId || task?.taskPackItemId || 0) || null,
                taskId: Number(requirement.taskId || requirement.task_id || task?.dbId || 0) || null,
                categoryId: Number(requirement.categoryId || requirement.category_id || 0),
                categoryName: requirement.categoryName || requirement.category_name || 'required document',
            };
        }
        return null;
    }

    function flowtrackJobV4TaskHasRequiredEvidence(job, task, requirement) {
        if (!requirement?.categoryId) return true;
        const jobCode = String(job?.id || '');
        return flowtrackToArray(docs).some((documentRecord) => {
            if (jobCode && String(documentRecord?.job || '') !== jobCode) return false;
            if (Number(documentRecord?.phaseId || 0) !== Number(requirement.phaseId || task?.phaseId || 0)) return false;
            if (Number(documentRecord?.categoryId || 0) !== Number(requirement.categoryId || 0)) return false;
            const exactTaskId = Number(requirement.taskId || task?.dbId || 0);
            const linkedTaskId = Number(documentRecord?.taskId || 0);
            if (exactTaskId && linkedTaskId) return linkedTaskId === exactTaskId;
            const packItemId = Number(requirement.taskPackItemId || task?.taskPackItemId || 0);
            if (packItemId) return Number(documentRecord?.taskPackItemId || 0) === packItemId;
            return !linkedTaskId && !Number(documentRecord?.taskPackItemId || 0);
        });
    }

    function flowtrackJobV4TaskRow(job, phase, rawTask, index) {
        const task = rawTask.planned ? rawTask : flowtrackHydrateTaskAssignee(rawTask || {});
        const canUpdate = Boolean(task.canUpdate || task.planned && (isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin || canFlow('tasks', 'create')));
        // All Phase Tasks stays in view mode by default. Only Admin/Super Admin
        // gets the inline assignee edit affordance in this Job Details table.
        // Other assignment paths keep their existing backend/Role Matrix rules.
        const canAssign = canUpdate && Boolean(isFlowAdmin || isFlowSuperAdmin || currentUser?.isAdmin || currentUser?.isSuperAdmin);
        const canDue = canUpdate && Boolean(task.canChangeDueDate || task.planned || isFlowAdmin || isFlowSuperAdmin);
        const assigneeName = task.assignee || 'Unassigned';
        const status = task.status || 'Not started';
        const due = task.due || task.dueDate || '';
        const sequence = `${Number(phase.sequence || 0)}.${index + 1}`;
        const documentRequirement = flowtrackJobV4TaskDocumentRequirement(task, phase.id, task.taskPackItemId);
        const evidencePresent = documentRequirement ? flowtrackJobV4TaskHasRequiredEvidence(job, task, documentRequirement) : true;
        const requirementNote = documentRequirement
            ? `<small class="job-v4-required-doc ${evidencePresent ? 'is-present' : 'is-missing'}">${evidencePresent ? '✓' : '!'} ${html(documentRequirement.categoryName)} ${evidencePresent ? 'uploaded' : 'required before completion'}</small>`
            : (task.planned ? `<small>Planned from ${html(task.setupLabel || 'Task Setup')}</small>` : '');
        return `<tr class="job-v4-task-row" data-job-v4-task-row data-task-id="${html(task.id)}" data-task-db-id="${html(task.dbId || '')}" data-phase-id="${html(phase.id)}" data-pack-item-id="${html(task.taskPackItemId || '')}" data-status="${html(normalizedStatusValue(status))}" data-assignee="${html(normalizedStatusValue(assigneeName))}" data-assignee-id="${html(task.assigneeId || '')}" data-planned="${task.planned ? '1' : '0'}" data-requires-document="${documentRequirement ? '1' : '0'}">
            <td class="job-v4-task-seq">${html(sequence)}</td>
            <td><button type="button" class="job-v4-task-title" data-job-v4-open-task ${task.planned ? 'disabled' : ''}>${html(task.title || task.id)}</button>${requirementNote}</td>
            <td><div class="job-v4-assignee-cell"><span class="job-v4-person" data-job-v4-assignee-label><span class="avatar">${userInitials(assigneeName)}</span><span>${html(assigneeName)}</span></span>${canAssign ? `<button type="button" class="job-v4-icon-btn" data-job-v4-edit-assignee title="Edit assignee" aria-label="Edit assignee">✎</button>` : ''}</div></td>
            <td><div class="job-v4-due-cell"><span data-job-v4-due-label>${due ? formatDate(due) : 'Not set'}</span>${canDue ? `<button type="button" class="job-v4-icon-btn" data-job-v4-edit-due title="Edit due date">✎</button><input type="date" class="job-v4-hidden-date" data-job-v4-due-input value="${html(due)}">` : ''}</div></td>
            <td>${canUpdate ? `<select class="job-v4-task-inline-select job-v4-status-select" data-job-v4-task-status data-native-select aria-label="Task status" data-previous-value="${html(task.statusId || '')}">${flowtrackJobV4TaskStatusOptions(task)}</select>` : statusBadge(status)}</td>
            <td class="job-v4-task-actions"><button type="button" class="job-v4-row-menu" data-job-v4-open-task ${task.planned ? 'disabled' : ''} aria-label="Open task">•••</button></td>
        </tr>`;
    }

    function flowtrackJobV4PhaseAccordion(job, actualTasks, activePhases, currentPhase) {
        return `<div class="job-v4-phase-stack">${activePhases.map((phase, phaseIndex) => {
            const rows = flowtrackJobV4ConfiguredPhaseTasks(job, phase, actualTasks);
            const completed = rows.filter((task) => !task.planned && taskIsCompleted(task)).length;
            const percent = rows.length ? Math.round((completed / rows.length) * 100) : 0;
            const isCurrent = Number(phase.id) === Number(job.phaseId);
            const open = isCurrent;
            return `<section class="job-v4-phase" data-job-v4-phase data-phase-id="${html(phase.id)}">
                <div class="job-v4-phase-head" data-job-v4-phase-head>
                    <button type="button" class="job-v4-phase-toggle" data-job-v4-toggle-phase aria-expanded="${open ? 'true' : 'false'}"><span class="job-v4-caret">${open ? '⌄' : '›'}</span><span class="job-v4-phase-number ${isCurrent ? 'is-current' : completed === rows.length && rows.length ? 'is-done' : ''}">${completed === rows.length && rows.length ? '✓' : Number(phase.sequence || phaseIndex + 1)}</span><b>${html(phase.name)}</b></button>
                    <div class="job-v4-phase-summary"><span>${completed} of ${rows.length} complete</span><i><span style="width:${percent}%"></span></i>${canFlow('tasks', 'create') ? `<button type="button" class="job-v4-phase-add" data-job-v4-add-task="${html(phase.id)}" title="Add task to ${html(phase.name)}">＋</button>` : ''}</div>
                </div>
                <div class="job-v4-phase-body ${open ? '' : 'is-collapsed'}">
                    <table class="job-v4-task-table"><thead><tr><th></th><th>Task</th><th>Assignee</th><th>Due date</th><th>Status</th><th>Action</th></tr></thead><tbody>${rows.map((task, index) => flowtrackJobV4TaskRow(job, phase, task, index)).join('') || '<tr><td colspan="6"><div class="empty-state">No tasks configured for this phase in Task Setup.</div></td></tr>'}</tbody></table>
                </div>
            </section>`;
        }).join('')}</div>`;
    }

    function flowtrackJobV4UpdateExpandedCount(scope) {
        const content = scope?.closest?.('.job-detail-v4-content') || scope?.querySelector?.('.job-detail-v4-content') || scope;
        if (!content?.querySelectorAll) return;
        const count = [...content.querySelectorAll('.job-v4-phase-body')].filter((body) => !body.classList.contains('is-collapsed') && !body.closest('.job-v4-phase')?.classList.contains('is-filtered-out')).length;
        const label = content.querySelector('[data-job-v4-expanded-count]');
        if (label) label.textContent = `${count} phase${count === 1 ? '' : 's'} expanded`;
    }

    function flowtrackJobV4SetPhaseExpanded(phase, expanded) {
        if (!phase) return;
        const body = phase.querySelector('.job-v4-phase-body');
        if (!body) return;
        body.classList.toggle('is-collapsed', !expanded);
        const toggle = phase.querySelector('[data-job-v4-toggle-phase]');
        toggle?.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        const caret = phase.querySelector('.job-v4-caret');
        if (caret) caret.textContent = expanded ? '⌄' : '›';
    }

    function flowtrackInstallJobV4PhaseAccordionInteraction() {
        if (window.__flowtrackJobV4PhaseAccordionInstalled) return;
        window.__flowtrackJobV4PhaseAccordionInstalled = true;
        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            if (!target) return;

            const expandAll = target.closest('[data-job-v4-expand-all]');
            const collapseAll = target.closest('[data-job-v4-collapse-all]');
            if (expandAll || collapseAll) {
                const content = target.closest('.job-detail-v4-content');
                if (!content) return;
                const expand = Boolean(expandAll);
                content.querySelectorAll('[data-job-v4-phase]').forEach((phase) => flowtrackJobV4SetPhaseExpanded(phase, expand));
                flowtrackJobV4UpdateExpandedCount(content);
                return;
            }

            const phaseHead = target.closest('[data-job-v4-phase-head]');
            if (!phaseHead) return;
            if (target.closest('[data-job-v4-add-task],a,input,select,textarea')) return;
            const phase = phaseHead.closest('[data-job-v4-phase]');
            const body = phase?.querySelector('.job-v4-phase-body');
            if (!phase || !body) return;
            flowtrackJobV4SetPhaseExpanded(phase, body.classList.contains('is-collapsed'));
            flowtrackJobV4UpdateExpandedCount(phaseHead);
        });
    }

    flowtrackInstallJobV4PhaseAccordionInteraction();

    function flowtrackJobV4ProductTable(job) {
        const products = flowtrackToArray(job.products);
        const rows = products.length ? products : [{ category: job.category || '', product: job.product || '', productCode: '', categoryId: job.categoryId, productId: job.productId, qty: job.qty || 1 }];
        const totalQty = rows.reduce((sum, item) => sum + Number(item.qty || 0), 0);
        return `<div class="job-v4-products-head"><b>Products & quantities</b><span>${rows.length} product${rows.length === 1 ? '' : 's'} · ${totalQty.toLocaleString()} total units</span></div>
            <table class="job-v4-products-table"><thead><tr><th>Category</th><th>Product</th><th>Quantity</th></tr></thead><tbody>${rows.map((item, index) => `<tr data-job-v4-product-row="${index}"><td>${html(item.category || '—')}</td><td><span>${item.productCode ? `${html(item.productCode)} · ` : ''}${html(item.product || 'Unspecified')}</span>${canEditJobRecord() ? `<button type="button" class="job-v4-icon-btn" data-job-v4-edit-product="${index}">✎</button>` : ''}</td><td><span>${Number(item.qty || 0).toLocaleString()}</span>${canEditJobRecord() ? `<button type="button" class="job-v4-icon-btn" data-job-v4-edit-product="${index}">✎</button>` : ''}</td></tr>`).join('')}</tbody></table>
            ${canEditJobRecord() ? '<button type="button" class="job-v4-link" data-job-v4-add-product>＋ Add product</button>' : ''}`;
    }

    function flowtrackJobV4Attachments(job) {
        const jobDocs = jobDocsFor(job);
        const canUpload = canFlow('documents', 'create') && canFlow('documents', 'link');
        return `<section class="job-v4-card job-v4-attachments" data-job-v4-attachments>
            <div class="job-v4-section-title"><div><h3>Attachments</h3><span class="job-v4-count">${jobDocs.length}</span></div></div>
            <div class="ft-task-dropzone ${canUpload ? '' : 'is-disabled'}" data-job-v4-dropzone tabindex="${canUpload ? '0' : '-1'}">
                <input type="file" data-job-v4-file-input accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.webp,.zip" ${canUpload ? '' : 'disabled'}>
                <span class="ft-task-paperclip">⌕</span>
                <div><b>Drop files here or <button type="button" data-job-v4-browse ${canUpload ? '' : 'disabled'}>browse</button></b><small>PDF, DOCX, XLSX, JPG, PNG or ZIP · Max 20 MB</small></div>
                <button type="button" class="ft-task-btn ft-task-btn-secondary" data-job-v4-choose-documents>Choose from Documents</button>
            </div>
            ${jobDocs.length ? `<div class="ft-task-file-list">${jobDocs.slice(0, 6).map((documentRecord) => `<div class="ft-task-file-row"><span class="ft-task-file-icon">${html(flowtrackDocumentFileBadge(documentRecord))}</span><div><b>${html(documentRecord.name || documentRecord.id)}</b><small>${html(documentRecord.category || 'Document')} · ${html(documentRecord.uploadedBy || 'System')} · ${html(documentRecord.date ? flowtrackFormatDateTime(documentRecord.date) : '')}</small></div><div class="ft-task-file-actions">${documentRecord.downloadUrl ? `<a class="ft-task-file-open" href="${html(documentRecord.downloadUrl)}">Open</a>` : ''}${canFlow('documents', 'delete') ? `<button type="button" class="ft-task-file-delete" data-job-v4-delete-document="${html(documentRecord.dbId)}">Delete</button>` : ''}</div></div>`).join('')}</div>` : ''}
        </section>`;
    }

    function flowtrackJobV4Activity(job) {
        const events = flowtrackToArray(job.activity);
        const comments = events.filter((item) => item.kind === 'comment' || item.event === 'job.commented' || item.event === 'task.commented');
        return `<section class="job-v4-card job-v4-activity" data-job-v4-activity>
            <div class="job-v4-activity-tabs"><h3>Activity</h3><button type="button" class="is-active" data-job-v4-activity-tab="comments">Comments <span>${comments.length}</span></button><button type="button" data-job-v4-activity-tab="history">History</button><button type="button" data-job-v4-activity-tab="worklog">Work log</button></div>
            <div class="job-v4-comment-compose" data-job-v4-comment-compose><span class="avatar">${userInitials(currentUser?.name || 'U')}</span><div class="job-v4-comment-input"><input type="text" maxlength="5000" placeholder="Add a comment..." data-job-v4-comment-input><small>Use @ to mention a team member.</small></div><button type="button" class="primary" data-job-v4-comment-submit>Comment</button></div>
            <div class="job-v4-activity-list">${events.map((item) => {
                const kind = item.kind || (item.event === 'job.commented' || item.event === 'task.commented' ? 'comment' : String(item.event || '').startsWith('task.') ? 'worklog' : 'history');
                const displayText = kind === 'comment' ? (item.comment || item.text || item.title || '') : (item.text || item.title || 'Updated');
                return `<div class="job-v4-activity-row" data-job-v4-activity-kind="${html(kind)}"><span class="avatar">${html(item.actorInitials || userInitials(item.actor || 'System'))}</span><div><b>${html(item.actor || 'System')}</b><p>${html(displayText)}</p>${kind !== 'comment' && item.title && item.text !== item.title ? `<small>${html(item.title)}</small>` : ''}</div><time>${html(item.time || '')}</time></div>`;
            }).join('')}<div class="job-v4-empty is-hidden" data-job-v4-activity-empty>No comments yet</div></div>
        </section>`;
    }

    function flowtrackJobV4Overview(job, actualTasks, allDocs, activePhases, currentPhase) {
        const wf = flowtrackJobV4Workflow(job);
        const configuredTasks = flowtrackJobV4AllConfiguredTasks(job, actualTasks);
        const completed = configuredTasks.filter((task) => !task.planned && taskIsCompleted(task)).length;
        const currentRows = currentPhase ? flowtrackJobV4ConfiguredPhaseTasks(job, currentPhase, actualTasks) : [];
        const currentCompleted = currentRows.filter((task) => !task.planned && taskIsCompleted(task)).length;
        const nextTask = currentRows.find((task) => task.planned || !taskIsCompleted(task)) || configuredTasks.find((task) => task.planned || !taskIsCompleted(task));
        const commercialStatus = typeof boardJobCommercialLabel === 'function' ? boardJobCommercialLabel(job) : (job.price ? 'Invoice pending' : 'Quotation pending');
        return `<section id="detailPanel0" class="detail-panel active job-v4-overview">
            <div class="job-v4-metrics">
                <div class="job-v4-metric"><span class="job-v4-metric-icon">▣</span><div><small>Current phase</small><b>${html(phaseNameFor(job))} · Phase ${Number(currentPhase?.sequence || job.phase + 1 || 1)} of ${activePhases.length}</b><em>${currentRows.length} tasks · ${currentCompleted} of ${currentRows.length} complete</em></div></div>
                <div class="job-v4-metric"><span class="job-v4-metric-icon">↗</span><div><small>Overall progress</small><b>${Number(job.progress || 0)}%</b><i><span style="width:${Math.max(0, Math.min(100, Number(job.progress || 0)))}%"></span></i></div></div>
                <div class="job-v4-metric"><span class="job-v4-metric-icon">⌘</span><div><small>Next required action</small><b>${html(nextTask?.title || job.next || 'Review current phase')}</b><em>${html(nextTask?.assignee || job.coordinator || 'Unassigned')}</em></div></div>
                <div class="job-v4-metric job-v4-commercial-metric"><span class="job-v4-metric-icon">$</span><div><small>Commercial</small><b>${job.price ? currency(job.price, job.currency) : 'Not recorded'}</b><em>${html(commercialStatus)}</em></div></div>
            </div>
            <div class="job-v4-info-grid">
                <section class="job-v4-card job-v4-overview-card"><h3>Job overview</h3><div class="job-v4-description-row"><span data-job-v4-description-text>${html(job.requestDescription || 'No job description recorded.')}</span>${canEditJobRecord() ? '<button type="button" class="job-v4-icon-btn" data-job-v4-edit-description>✎</button>' : ''}</div>${flowtrackJobV4ProductTable(job)}</section>
                <section class="job-v4-card job-v4-planning"><h3>Planning & ownership</h3>
                    <div class="job-v4-plan-row"><span>Required delivery</span><b>${job.delivery ? formatDate(job.delivery) : 'Not set'}</b>${canEditJobRecord() ? '<button type="button" class="job-v4-icon-btn" data-job-v4-edit-field="delivery">✎</button>' : ''}</div>
                    <div class="job-v4-plan-row"><span>Priority</span><b>${html(job.priority || 'Medium')}</b>${canEditJobRecord() ? '<button type="button" class="job-v4-icon-btn" data-job-v4-edit-field="priority">✎</button>' : ''}</div>
                    <div class="job-v4-plan-row"><span>Job owner</span><b><span class="avatar">${userInitials(job.owner || 'U')}</span>${html(job.owner || 'Unassigned')}</b>${canEditJobRecord() ? '<button type="button" class="job-v4-icon-btn" data-job-v4-edit-field="owner">✎</button>' : ''}</div>
                    <div class="job-v4-plan-row"><span>Coordinator</span><b><span class="avatar">${userInitials(job.coordinator || 'U')}</span>${html(job.coordinator || 'Unassigned')}</b>${canEditJobRecord() ? '<button type="button" class="job-v4-icon-btn" data-job-v4-edit-field="coordinator">✎</button>' : ''}</div>
                    <div class="job-v4-plan-row"><span>Workflow</span><b>▣ ${html(job.workflowName || wf.name || 'Workflow')}</b><span></span></div>
                    <div class="job-v4-plan-row"><span>Created</span><b>${job.createdAt ? flowtrackFormatDateTime(job.createdAt) : 'Not recorded'}</b><span></span></div>
                    ${jobIsCompleted(job) ? `<div class="job-v4-plan-row job-v4-completed-row"><span>Completed</span><b>${html(boardCompletedDateTime(job) || 'Not recorded')}</b><span></span></div>` : ''}
                </section>
            </div>
            ${flowtrackMiniTimeline(job, activePhases, currentPhase)}
            <section class="job-v4-card job-v4-all-tasks">
                <div class="job-v4-tasks-toolbar"><div><h3>All phase tasks</h3><p>${configuredTasks.length} tasks across ${activePhases.length} phases</p></div><div class="job-v4-task-actions-bar"><label class="job-v4-search"><span>⌕</span><input type="search" placeholder="Search tasks" data-job-v4-task-search></label><div class="job-v4-filter-wrap"><button type="button" class="ghost" data-job-v4-filter-toggle>☰ Filter</button><div class="job-v4-filter-popover is-hidden" data-job-v4-filter-popover><label>Status<select data-job-v4-status-filter><option value="">All statuses</option>${(masterData.taskStatuses || []).filter((item) => String(item.status || 'Active').toLowerCase() === 'active').map((item) => `<option value="${html(normalizedStatusValue(item.name))}">${html(item.name)}</option>`).join('')}</select></label><label>Assignee<select data-job-v4-assignee-filter><option value="">All assignees</option>${(typeof flowtrackWorkspaceMembers === 'function' ? flowtrackWorkspaceMembers() : users).map((member) => `<option value="${html(normalizedStatusValue(member.name))}">${html(member.name)}</option>`).join('')}</select></label><button type="button" class="job-v4-link" data-job-v4-clear-filters>Clear filters</button></div></div><button type="button" class="ghost flow-bulk-icon-btn job-v4-bulk-icon-btn" data-job-v4-collapse-all title="Collapse all phases" aria-label="Collapse all phases">${flowtrackJobV4BulkIcon('collapse')}</button><button type="button" class="primary flow-bulk-icon-btn job-v4-bulk-icon-btn" data-job-v4-expand-all title="Expand all phases" aria-label="Expand all phases">${flowtrackJobV4BulkIcon('expand')}</button></div></div>
                <div class="job-v4-task-meta"><span>ⓘ All ${configuredTasks.length} tasks are loaded</span><span data-job-v4-expanded-count>1 phase expanded</span><span>Changes save automatically</span></div>
                ${flowtrackJobV4PhaseAccordion(job, actualTasks, activePhases, currentPhase)}
            </section>
            ${flowtrackJobV4Attachments(job)}
            ${flowtrackJobV4Activity(job)}
        </section>`;
    }

    function flowtrackJobV5DateOnly(value) {
        if (!value) return '—';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return String(value);
        return date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function flowtrackJobV5DaysBetween(startValue, endValue = null) {
        if (!startValue) return '—';
        const start = new Date(startValue);
        const end = endValue ? new Date(endValue) : new Date();
        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return '—';
        const days = Math.max(0, Math.floor((end.getTime() - start.getTime()) / 86400000));
        return `${days} day${days === 1 ? '' : 's'}`;
    }

    function flowtrackJobV5HealthLabel(value) {
        const normalized = String(value || 'on_track').trim().toLowerCase();
        return normalized.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
    }

    function flowtrackJobV5CurrentPhaseState(job, activePhases, currentPhase, actualTasks) {
        const history = flowtrackPhaseHistoryFor(job, currentPhase) || {};
        const rows = currentPhase ? flowtrackJobV4ConfiguredPhaseTasks(job, currentPhase, actualTasks) : [];
        const completed = rows.filter((task) => !task.planned && taskIsCompleted(task)).length;
        const total = rows.length;
        const progress = total ? Math.round((completed / total) * 100) : 0;
        const readiness = job.phaseReadinessDetails || history.readinessDetails || {};
        const taskGate = readiness.task_gate || {};
        const documentGate = readiness.document_gate || {};
        const documentCategories = flowtrackToArray(documentGate.categories);
        const missingDocuments = flowtrackToArray(documentGate.missing);
        const requiredDocuments = documentGate.required ? Math.max(1, documentCategories.length) : 0;
        const receivedDocuments = Math.max(0, requiredDocuments - missingDocuments.length);
        const blockingTask = rows.find((task) => !task.planned && (task.attention || task.blockerReason || /block|attention|hold/i.test(String(task.status || ''))));
        const firstOpenTask = blockingTask || rows.find((task) => !task.planned && !taskIsCompleted(task)) || null;
        const remaining = Math.max(0, total - completed);
        const blockerMessages = flowtrackToArray(readiness.blockers);
        const blockerCount = Math.max(0, (remaining > 0 ? 1 : 0) + missingDocuments.length + (readiness.has_blocking_task ? 1 : 0));
        const targetDate = history.targetDate || job.delivery || '';
        const phaseOwner = history.phaseOwner || job.coordinator || job.owner || 'Unassigned';
        const phaseOwnerId = history.phaseOwnerId || job.coordinatorId || job.ownerId || null;
        const automationMode = history.automationMode || (currentPhase?.autoAdvance ? 'automatic' : 'manual');
        const healthValue = history.healthOverride || String(job.health || 'On Track').toLowerCase().replaceAll(' ', '_');
        const currentIndex = activePhases.findIndex((phase) => Number(phase.id) === Number(currentPhase?.id));
        const nextPhase = currentIndex >= 0 ? activePhases[currentIndex + 1] || null : null;
        return { history, rows, completed, total, progress, readiness, taskGate, documentGate, requiredDocuments, receivedDocuments, missingDocuments, blockingTask, firstOpenTask, remaining, blockerMessages, blockerCount, targetDate, phaseOwner, phaseOwnerId, automationMode, healthValue, currentIndex, nextPhase };
    }

    function flowtrackJobV5Timeline(job, activePhases, currentPhase) {
        return `<div class="job-v5-timeline" style="grid-template-columns:repeat(${Math.max(1, activePhases.length)},minmax(72px,1fr))">${activePhases.map((phase, index) => {
            const isCurrent = Number(phase.id) === Number(currentPhase?.id);
            const isDone = currentPhase && Number(phase.sequence) < Number(currentPhase.sequence);
            const circle = isDone ? '✓' : Number(phase.sequence || index + 1);
            return `<div class="job-v5-timeline-step ${isDone ? 'is-done' : ''} ${isCurrent ? 'is-current' : ''}"><div class="job-v5-timeline-track"><span class="job-v5-timeline-dot">${circle}</span>${index < activePhases.length - 1 ? '<i></i>' : ''}</div><b>${html(phase.short || phase.name)}</b>${isCurrent ? '<small>Current</small>' : ''}</div>`;
        }).join('')}</div>`;
    }

    function flowtrackJobV5PhaseHistoryTable(job, activePhases, actualTasks, currentPhase) {
        return `<section class="job-v5-card job-v5-history"><div class="job-v5-card-title"><div><h3>Phase history</h3><p>One row per workflow phase; future phases remain planned.</p></div></div><table><thead><tr><th></th><th>Phase</th><th>Status</th><th>Entered</th><th>Completed</th><th>Time in phase</th><th>Outcome</th></tr></thead><tbody>${activePhases.map((phase, index) => {
            const history = flowtrackPhaseHistoryFor(job, phase) || {};
            const isCurrent = Number(phase.id) === Number(currentPhase?.id);
            const isPast = currentPhase ? Number(phase.sequence) < Number(currentPhase.sequence) : false;
            const skipped = String(history.status || '').toLowerCase() === 'skipped';
            const completed = String(history.status || '').toLowerCase() === 'completed' || (isPast && !skipped);
            const status = skipped ? 'Skipped' : isCurrent ? 'Current' : completed ? 'Completed' : 'Planned';
            const tone = skipped ? 'is-muted' : isCurrent ? 'is-current' : completed ? 'is-complete' : 'is-planned';
            const phaseRows = flowtrackJobV4ConfiguredPhaseTasks(job, phase, actualTasks);
            const doneCount = phaseRows.filter((task) => !task.planned && taskIsCompleted(task)).length;
            const outcome = skipped ? 'Skipped' : completed ? 'Passed' : isCurrent ? (job.phaseReadiness === 'ready' ? 'Ready' : `${Math.max(0, phaseRows.length - doneCount)} task${Math.max(0, phaseRows.length - doneCount) === 1 ? '' : 's'} remaining`) : '—';
            const entered = history.enteredAt ? flowtrackJobV5DateOnly(history.enteredAt) : '—';
            const completedAt = history.completedAt ? flowtrackJobV5DateOnly(history.completedAt) : '—';
            const duration = history.enteredAt ? (history.completedAt ? flowtrackJobV5DaysBetween(history.enteredAt, history.completedAt) : isCurrent ? flowtrackJobV5DaysBetween(history.enteredAt) : '—') : '—';
            return `<tr class="${isCurrent ? 'is-current-row' : ''}"><td>${Number(phase.sequence || index + 1)}</td><td><b>${html(phase.short || phase.name)}</b></td><td><span class="job-v5-status ${tone}">${html(status)}</span></td><td>${html(entered)}</td><td>${html(completedAt)}</td><td>${html(duration)}</td><td><span class="job-v5-outcome ${completed ? 'is-pass' : isCurrent && job.phaseReadiness !== 'ready' ? 'is-warning' : ''}">${html(outcome)}</span></td></tr>`;
        }).join('')}</tbody></table></section>`;
    }

    function flowtrackJobV5WorkflowPanel(job, actualTasks, activePhases, currentPhase) {
        const wf = flowtrackJobV4Workflow(job);
        const state = flowtrackJobV5CurrentPhaseState(job, activePhases, currentPhase, actualTasks);
        const enteredAt = state.history.enteredAt || job.createdAt || null;
        const taskGateMet = Boolean(state.taskGate.met) || (state.total > 0 && state.completed >= state.total);
        const documentGateMet = state.documentGate.required ? Boolean(state.documentGate.met) : true;
        const approvalGateMet = true;
        const blockingSummary = state.blockerMessages[0] || (state.remaining ? `Complete ${state.remaining} remaining phase task${state.remaining === 1 ? '' : 's'}.` : state.missingDocuments.length ? `Upload ${state.missingDocuments.length} required document${state.missingDocuments.length === 1 ? '' : 's'}.` : 'Resolve the current phase requirements.');
        const nextName = state.nextPhase?.name || 'Completed';
        const canEdit = canEditJobRecord();
        const healthLabel = flowtrackJobV5HealthLabel(state.healthValue);
        const healthTone = /block|risk|delay|attention/i.test(healthLabel) ? 'is-warning' : 'is-good';
        const phaseNumber = Number(currentPhase?.sequence || state.currentIndex + 1 || 1);
        const totalPhases = activePhases.length;
        return `<section id="detailPanel1" class="detail-panel job-v5-workflow">
            <div class="job-v5-workflow-head"><div><h3>Workflow</h3><p>${html(job.workflowName || wf.name || 'Workflow')} · Version ${html(wf.version || 1)}</p></div><button type="button" class="job-v5-link" data-job-v5-workflow-setup>View workflow setup ↗</button></div>
            ${job.phaseReadiness !== 'ready' && state.nextPhase ? `<div class="job-v5-blocker"><span>!</span><div><b>${Math.max(1, state.blockerCount)} ${Math.max(1, state.blockerCount) === 1 ? 'item blocks' : 'items block'} the next phase</b><p>${html(blockingSummary)}</p></div><button type="button" class="job-v5-link" data-job-v5-open-blocker>${state.firstOpenTask ? 'View blocking task' : 'View phase tasks'}</button></div>` : ''}
            ${flowtrackJobV5Timeline(job, activePhases, currentPhase)}
            <div class="job-v5-grid">
                <div class="job-v5-left">
                    <section class="job-v5-card job-v5-current-phase">
                        <div class="job-v5-current-head"><div><h3>Current phase · ${html(currentPhase?.name || phaseNameFor(job))}</h3><div class="job-v5-progress-line"><span>${state.completed} of ${state.total} tasks complete</span><i><b style="width:${state.progress}%"></b></i><strong>${state.progress}%</strong></div></div><span class="job-v5-phase-pill">Phase ${phaseNumber} of ${totalPhases}</span></div>
                        <div class="job-v5-phase-meta"><span class="avatar">${userInitials(state.phaseOwner)}</span><b>${html(state.phaseOwner)}</b><span>·</span><span>Entered ${html(flowtrackJobV5DateOnly(enteredAt))}</span><span>·</span><span>Target ${html(flowtrackJobV5DateOnly(state.targetDate))}</span><span>·</span><span>${html(flowtrackJobV5DaysBetween(enteredAt))} in phase</span><button type="button" class="job-v5-link" data-job-v5-view-phase-tasks>View ${state.total} phase tasks</button></div>
                        <div class="job-v5-gate-table">
                            <div class="job-v5-gate-row ${taskGateMet ? 'is-complete' : 'is-warning'}"><span class="job-v5-gate-icon">${taskGateMet ? '✓' : '!'}</span><b>1</b><span>Tasks</span><strong>${state.completed} of ${state.total} complete</strong><em>${state.remaining ? `${state.remaining} remaining` : 'Complete'}</em><button type="button" class="job-v5-link" data-job-v5-view-phase-tasks>${state.firstOpenTask ? 'Open task' : 'View tasks'}</button></div>
                            <div class="job-v5-gate-row ${documentGateMet ? 'is-complete' : 'is-warning'}"><span class="job-v5-gate-icon">${documentGateMet ? '✓' : '!'}</span><b>2</b><span>Required documents</span><strong>${state.requiredDocuments ? `${state.receivedDocuments} of ${state.requiredDocuments} received` : 'Not required'}</strong><em>${documentGateMet ? 'Complete' : `${state.missingDocuments.length} missing`}</em><button type="button" class="job-v5-link" data-job-v5-view-documents>View documents</button></div>
                            <div class="job-v5-gate-row is-complete"><span class="job-v5-gate-icon">✓</span><b>3</b><span>Approvals</span><strong>Not required</strong><em>Complete</em><button type="button" class="job-v5-link" data-job-v5-view-approvals>View approvals</button></div>
                            <div class="job-v5-gate-row ${state.readiness.has_blocking_task ? 'is-warning' : 'is-complete'}"><span class="job-v5-gate-icon">${state.readiness.has_blocking_task ? '!' : '✓'}</span><b>4</b><span>Other blockers</span><strong>${state.readiness.has_blocking_task ? 'Active blocker' : 'None'}</strong><em>${state.readiness.has_blocking_task ? 'Review' : 'Clear'}</em><button type="button" class="job-v5-link" data-job-v5-view-blockers>View details</button></div>
                        </div>
                        ${state.nextPhase ? `<div class="job-v5-next-phase"><span class="job-v5-lock">▣</span><div><b>Next phase: ${html(nextName)}</b><p>${job.phaseReadiness === 'ready' ? 'All transition requirements are satisfied.' : html(blockingSummary)}</p></div>${job.phaseReadiness === 'ready' ? '' : '<button type="button" class="job-v5-outline" data-job-v5-open-blocker>Open blocking task</button>'}<button type="button" class="job-v5-move" data-job-v5-move-next ${job.phaseReadiness === 'ready' ? '' : 'disabled'}>Move to ${html(nextName)}</button><button type="button" class="job-v5-more" data-job-v5-transition-rules>•••</button></div>` : `<div class="job-v5-next-phase"><span class="job-v5-lock is-done">✓</span><div><b>Workflow completed</b><p>This is the final active workflow phase.</p></div></div>`}
                    </section>
                    ${flowtrackJobV5PhaseHistoryTable(job, activePhases, actualTasks, currentPhase)}
                </div>
                <aside class="job-v5-right">
                    <section class="job-v5-card job-v5-controls"><h3>Phase controls</h3>
                        <div class="job-v5-control-row"><span>Phase owner</span><b><span class="avatar">${userInitials(state.phaseOwner)}</span>${html(state.phaseOwner)}</b>${canEdit ? '<button type="button" data-job-v5-edit-control="owner">✎</button>' : '<span></span>'}</div>
                        <div class="job-v5-control-row"><span>Target date</span><b>▣ ${html(flowtrackJobV5DateOnly(state.targetDate))}</b>${canEdit ? '<button type="button" data-job-v5-edit-control="target">✎</button>' : '<span></span>'}</div>
                        <div class="job-v5-control-row"><span>Health</span><b><i class="job-v5-health-dot ${healthTone}"></i><span class="job-v5-health-pill ${healthTone}">${html(healthLabel)}</span></b>${canEdit ? '<button type="button" data-job-v5-edit-control="health">✎</button>' : '<span></span>'}</div>
                        <div class="job-v5-control-row"><span>Automation</span><b>${state.automationMode === 'automatic' ? 'Automatic' : 'Manual'}</b>${canEdit ? '<button type="button" data-job-v5-edit-control="automation">✎</button>' : '<span></span>'}</div>
                        <div class="job-v5-transition-policy"><h4>Transition policy</h4><p class="${taskGateMet ? 'is-pass' : 'is-warn'}"><span>${taskGateMet ? '✓' : '!'}</span> All phase tasks complete</p><p class="${documentGateMet ? 'is-pass' : 'is-warn'}"><span>${documentGateMet ? '✓' : '!'}</span> Required documents received</p><p class="${approvalGateMet ? 'is-pass' : 'is-warn'}"><span>${approvalGateMet ? '✓' : '!'}</span> Required approvals completed</p><button type="button" class="job-v5-link" data-job-v5-transition-rules>View transition rules</button></div>
                    </section>
                    <section class="job-v5-card job-v5-details"><h3>Workflow details</h3><dl><dt>Template</dt><dd>${html(job.workflowName || wf.name || 'Workflow')}</dd><dt>Version</dt><dd>${html(wf.version || 1)}</dd><dt>Started at</dt><dd>${html(activePhases[Math.max(0, Number(job.createdFromPhase || 0))]?.name || activePhases[0]?.name || '—')}</dd><dt>Started</dt><dd>${html(flowtrackJobV5DateOnly(job.createdAt))}</dd></dl><button type="button" class="job-v5-link" data-job-v5-workflow-setup>Open workflow configuration ↗</button></section>
                </aside>
            </div>
        </section>`;
    }

    function flowtrackJobV4Panels(job) {
        const actualTasks = flowtrackJobAllTasks(job);
        const allDocs = jobDocsFor(job);
        const activePhases = flowtrackActivePhases(job);
        const currentPhase = activePhases.find((phase) => Number(phase.id) === Number(job.phaseId)) || activePhases[Math.max(0, Number(job.phase || 0))] || activePhases[0] || null;
        const currentTasks = currentPhase ? flowtrackTasksForPhase(job, currentPhase.id, actualTasks) : [];
        const requirements = flowtrackJobPhaseDocumentRequirements(job, activePhases);
        const wf = flowtrackJobV4Workflow(job);
        const commercialStatus = typeof boardJobCommercialLabel === 'function' ? boardJobCommercialLabel(job) : (job.price ? 'Invoice pending' : 'Quotation pending');
        return `${flowtrackJobV4Overview(job, actualTasks, allDocs, activePhases, currentPhase)}
            ${flowtrackJobV5WorkflowPanel(job, actualTasks, activePhases, currentPhase)}
            <section id="detailPanel7" class="detail-panel job-v3-commercial"><div class="job-v3-panel-card"><div class="job-v3-panel-head"><div><h3>Commercial</h3><p>Quotation, invoice and payment status.</p></div></div><div class="job-v3-metric-grid">${flowtrackJobMetricCard('$', 'Commercial value', `<span>${job.price ? currency(job.price, job.currency) : 'Not recorded'}</span>`, html(commercialStatus))}${flowtrackJobMetricCard('▣', 'Client', `<span>${html(clientName(job.client))}</span>`, html(job.order || job.id))}${flowtrackJobMetricCard('○', 'Invoice', `<span>${html(commercialStatus)}</span>`, `${allDocs.filter((doc) => documentMatchesKeywords(doc, ['invoice', 'payment', 'receipt'])).length} commercial documents`)}</div><div style="margin-top:16px">${documentPanelFor(job, 'Invoice Documents', ['invoice', 'payment', 'receipt', 'commercial'])}</div></div></section>
            ${flowtrackDocumentsPanel(job, allDocs, activePhases)}`;
    }

    async function flowtrackJobV4SaveJob(job, patch, successMessage = 'Job updated.') {
        const current = flowtrackFindJob(job.id || job.dbId) || job;
        const products = (patch.products || flowtrackToArray(current.products).map((item) => ({
            product_category_id: Number(item.categoryId || 0),
            product_id: Number(item.productId || 0),
            quantity: Math.max(1, Number(item.qty || 1)),
        }))).filter((item) => item.product_category_id && item.product_id);
        if (!products.length && current.productId && current.categoryId) {
            products.push({ product_category_id: Number(current.categoryId), product_id: Number(current.productId), quantity: Math.max(1, Number(current.qty || 1)) });
        }
        const body = {
            client_id: Number(current.client),
            products,
            priority_id: patch.priority_id !== undefined ? patch.priority_id : (current.priorityId ? Number(current.priorityId) : null),
            owner_id: patch.owner_id !== undefined ? patch.owner_id : (current.ownerId ? Number(current.ownerId) : null),
            coordinator_id: patch.coordinator_id !== undefined ? patch.coordinator_id : (current.coordinatorId ? Number(current.coordinatorId) : null),
            title: patch.title !== undefined ? patch.title : current.title,
            request_description: patch.request_description !== undefined ? patch.request_description : (current.requestDescription || null),
            delivery_date: patch.delivery_date !== undefined ? patch.delivery_date : (current.delivery || null),
        };
        if (current.price !== null && current.price !== undefined) {
            body.quoted_amount = Number(current.price || 0);
            body.currency_id = current.currencyId ? Number(current.currencyId) : null;
        }
        const result = await api(`/flowtrack/template-api/jobs/${current.dbId}`, { method: 'PUT', body });
        if (result.job) flowtrackUpsertRecord(jobs, result.job);
        const updated = flowtrackFindJob(result.job?.id || current.id) || result.job || current;
        flowtrackEnsureJobPanels(updated, 0);
        flowtrackJobV4Apply(updated, 0);
        updateBadges();
        showToast(successMessage);
        return updated;
    }

    async function flowtrackJobV4EnsureActualTask(job, phaseId, packItemId) {
        let task = flowtrackTasksForJob(job).find((item) => Number(item.phaseId) === Number(phaseId) && Number(item.taskPackItemId) === Number(packItemId));
        if (task) return task;
        const result = await api(`/flowtrack/template-api/jobs/${job.dbId}/phases/${phaseId}/tasks/sync`, { method: 'POST', body: {} });
        if (result.job) flowtrackUpsertRecord(jobs, result.job);
        if (result.job && Array.isArray(result.tasks)) replaceTasksForJob(result.job.id, result.tasks);
        task = flowtrackTasksForJob(flowtrackFindJob(job.id) || job).find((item) => Number(item.phaseId) === Number(phaseId) && Number(item.taskPackItemId) === Number(packItemId));
        if (!task) throw new Error('This Task Setup item could not be activated for the Job.');
        return task;
    }

    async function flowtrackJobV4UpdateTask(job, row, patch) {
        const planned = row.dataset.planned === '1';
        let task = planned
            ? await flowtrackJobV4EnsureActualTask(job, Number(row.dataset.phaseId), Number(row.dataset.packItemId))
            : tasks.find((item) => Number(item.dbId) === Number(row.dataset.taskDbId) || String(item.id) === String(row.dataset.taskId));
        if (!task) throw new Error('Task could not be loaded.');
        task = flowtrackHydrateTaskAssignee(task);
        const statusId = patch.task_status_id !== undefined ? Number(patch.task_status_id) : Number(task.statusId || 0);
        if (!statusId) throw new Error('Configure an active Task Status before editing tasks.');
        const body = {
            task_status_id: statusId,
            assignee_id: patch.assignee_id !== undefined ? patch.assignee_id : (task.assigneeId ? Number(task.assigneeId) : null),
            start_date: task.startDate || null,
            due_date: patch.due_date !== undefined ? patch.due_date : (task.due || null),
            priority_id: task.priorityId ? Number(task.priorityId) : null,
            attention_required: Boolean(task.attention),
            blocker_reason: task.blockerReason || null,
        };
        const result = await api(`/flowtrack/template-api/tasks/${task.dbId}`, { method: 'PUT', body });
        updateTaskAndJob(result);
        const updatedJob = flowtrackFindJob(result.job?.id || job.id) || job;
        flowtrackEnsureJobPanels(updatedJob, 0);
        flowtrackJobV4Apply(updatedJob, 0);
        updateBadges();
        return result.task;
    }

    async function flowtrackJobV5SavePhaseControls(job, patch, successMessage = 'Phase controls updated.') {
        const result = await api(`/flowtrack/template-api/jobs/${job.dbId}/phase-controls`, { method: 'PATCH', body: patch });
        if (result.job) flowtrackUpsertRecord(jobs, result.job);
        const updated = flowtrackFindJob(result.job?.id || job.id) || result.job || job;
        flowtrackEnsureJobPanels(updated, 1);
        flowtrackJobV4Apply(updated, 1);
        updateBadges();
        showToast(successMessage);
        return updated;
    }

    function flowtrackJobV5OpenWorkflowSetup(job) {
        const workflowId = Number(job?.workflow || 0);
        if (!workflowId) return showToast('Workflow configuration could not be resolved.');
        window.location.href = `/flowtrack/view/workflows/${encodeURIComponent(workflowId)}/edit`;
    }

    function flowtrackJobV5BindWorkflow(content, job) {
        const activePhases = flowtrackActivePhases(job);
        const currentPhase = activePhases.find((phase) => Number(phase.id) === Number(job.phaseId)) || activePhases[Math.max(0, Number(job.phase || 0))] || activePhases[0] || null;
        if (!currentPhase) return;
        const actualTasks = flowtrackJobAllTasks(job);
        const state = flowtrackJobV5CurrentPhaseState(job, activePhases, currentPhase, actualTasks);
        const history = state.history || {};

        content.querySelectorAll('[data-job-v5-workflow-setup],[data-job-v5-transition-rules]').forEach((button) => button.addEventListener('click', () => flowtrackJobV5OpenWorkflowSetup(job)));

        const showPhaseTasks = () => {
            activateScopedDetailTab(content, 0);
            window.setTimeout(() => content.querySelector('.job-v4-all-tasks')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
        };
        content.querySelectorAll('[data-job-v5-view-phase-tasks]').forEach((button) => button.addEventListener('click', () => {
            if (/open task/i.test(button.textContent || '') && state.firstOpenTask?.id) void openTask(state.firstOpenTask.id);
            else showPhaseTasks();
        }));
        content.querySelector('[data-job-v5-view-documents]')?.addEventListener('click', () => activateScopedDetailTab(content, 8));
        content.querySelector('[data-job-v5-view-approvals]')?.addEventListener('click', () => showToast('This workflow does not require a separate approval operation.'));
        content.querySelector('[data-job-v5-view-blockers]')?.addEventListener('click', () => showToast(state.blockerMessages.join(' ') || (state.readiness.has_blocking_task ? 'A task blocker is active in this phase.' : 'No active blockers.')));
        content.querySelectorAll('[data-job-v5-open-blocker]').forEach((button) => button.addEventListener('click', () => {
            if (state.firstOpenTask?.id) void openTask(state.firstOpenTask.id); else showPhaseTasks();
        }));

        content.querySelectorAll('[data-job-v5-edit-control]').forEach((button) => button.addEventListener('click', () => {
            const field = button.dataset.jobV5EditControl;
            const row = button.closest('.job-v5-control-row');
            if (!row) return;
            if (field === 'owner') {
                const members = typeof flowtrackWorkspaceMembers === 'function' ? flowtrackWorkspaceMembers() : users;
                const selected = Number(history.phaseOwnerId || job.coordinatorId || job.ownerId || 0);
                const options = `<option value="">Unassigned</option>${members.map((member) => `<option value="${html(member.id)}" ${Number(member.id) === selected ? 'selected' : ''}>${html(member.name)}</option>`).join('')}`;
                flowtrackJobV4InlineEditor(row, `<select data-job-v4-inline-value data-searchable>${options}</select>`, async (target) => flowtrackJobV5SavePhaseControls(job, { phase_owner_id: Number(target.querySelector('[data-job-v4-inline-value]')?.value || 0) || null }, 'Phase owner updated.'));
            } else if (field === 'target') {
                flowtrackJobV4InlineEditor(row, `<input type="date" value="${html(history.targetDate || job.delivery || '')}" data-job-v4-inline-value>`, async (target) => flowtrackJobV5SavePhaseControls(job, { target_date: target.querySelector('[data-job-v4-inline-value]')?.value || null }, 'Phase target date updated.'));
            } else if (field === 'health') {
                const selected = String(history.healthOverride || '');
                const options = [['', 'Automatic (derived)'], ['on_track', 'On Track'], ['needs_attention', 'Needs Attention'], ['at_risk', 'At Risk'], ['delayed', 'Delayed'], ['blocked', 'Blocked']].map(([value, label]) => `<option value="${value}" ${value === selected ? 'selected' : ''}>${label}</option>`).join('');
                flowtrackJobV4InlineEditor(row, `<select data-job-v4-inline-value>${options}</select>`, async (target) => flowtrackJobV5SavePhaseControls(job, { health_override: target.querySelector('[data-job-v4-inline-value]')?.value || null }, 'Phase health updated.'));
            } else if (field === 'automation') {
                const selected = String(history.automationMode || '');
                const defaultLabel = currentPhase.autoAdvance ? 'Workflow default (Automatic)' : 'Workflow default (Manual)';
                const options = `<option value="" ${selected === '' ? 'selected' : ''}>${defaultLabel}</option><option value="manual" ${selected === 'manual' ? 'selected' : ''}>Manual</option><option value="automatic" ${selected === 'automatic' ? 'selected' : ''}>Automatic</option>`;
                flowtrackJobV4InlineEditor(row, `<select data-job-v4-inline-value>${options}</select>`, async (target) => flowtrackJobV5SavePhaseControls(job, { automation_mode: target.querySelector('[data-job-v4-inline-value]')?.value || null }, 'Phase automation updated.'));
            }
        }));

        content.querySelector('[data-job-v5-move-next]')?.addEventListener('click', async (event) => {
            if (!state.nextPhase || job.phaseReadiness !== 'ready') return;
            const button = event.currentTarget;
            button.disabled = true;
            try {
                const result = await moveJobToPhase(job, state.nextPhase);
                const updated = flowtrackFindJob(result.job?.id || job.id) || result.job;
                flowtrackEnsureJobPanels(updated, 1);
                flowtrackJobV4Apply(updated, 1);
                updateBadges();
                showToast(`${state.nextPhase.name} activated.`);
            } catch (error) {
                button.disabled = false;
                showToast(error.message || 'The phase could not be moved.');
            }
        });
    }

    function flowtrackJobV4InlineEditor(target, editorHtml, onSave) {
        if (!target || target.dataset.editing === '1') return;
        target.dataset.editing = '1';
        const original = target.innerHTML;
        target.innerHTML = `<div class="job-v4-inline-editor">${editorHtml}<div class="job-v4-inline-actions"><button type="button" class="job-v4-link" data-job-v4-inline-save>Save</button><button type="button" class="job-v4-link muted" data-job-v4-inline-cancel>Cancel</button></div></div>`;
        target.querySelector('[data-job-v4-inline-cancel]')?.addEventListener('click', () => { target.innerHTML = original; target.dataset.editing = '0'; });
        target.querySelector('[data-job-v4-inline-save]')?.addEventListener('click', async (event) => {
            event.currentTarget.disabled = true;
            try { await onSave(target); } catch (error) { event.currentTarget.disabled = false; showToast(error.message || 'Update failed.'); }
        });
        window.FlowtrackSearchableDropdown?.enhance(target);
    }

    function flowtrackJobV4ProductEditor(job, index = null) {
        const card = document.querySelector(`#page-record-job-${flowtrackJobDetailSlug(job)} .job-v4-overview-card`);
        if (!card || card.querySelector('.job-v4-product-editor')) return;
        const currentProducts = flowtrackToArray(job.products).map((item) => ({ product_category_id: Number(item.categoryId), product_id: Number(item.productId), quantity: Number(item.qty || 1) }));
        const editingIndex = index === null ? currentProducts.length : Number(index);
        const existing = currentProducts[editingIndex] || { product_category_id: '', product_id: '', quantity: 1 };
        const categories = activeMaster('productCategories');
        const productOptions = (categoryId, selected) => activeMaster('products').filter((product) => Number(product.parent_id) === Number(categoryId)).map((product) => `<option value="${html(product.id)}" ${Number(product.id) === Number(selected) ? 'selected' : ''}>${html(product.code)} · ${html(product.name)}</option>`).join('');
        const editor = document.createElement('div');
        editor.className = 'job-v4-product-editor';
        editor.innerHTML = `<select data-job-v4-product-category data-searchable><option value="">Select category</option>${categories.map((category) => `<option value="${html(category.id)}" ${Number(category.id) === Number(existing.product_category_id) ? 'selected' : ''}>${html(category.name)}</option>`).join('')}</select><select data-job-v4-product-select data-searchable><option value="">Select product</option>${productOptions(existing.product_category_id, existing.product_id)}</select><input type="number" min="1" max="1000000000" value="${Math.max(1, Number(existing.quantity || 1))}" data-job-v4-product-qty><button type="button" class="primary" data-job-v4-product-save>Save</button><button type="button" class="ghost" data-job-v4-product-cancel>Cancel</button>`;
        card.appendChild(editor);
        window.FlowtrackSearchableDropdown?.enhance(editor);
        const categorySelect = editor.querySelector('[data-job-v4-product-category]');
        categorySelect?.addEventListener('change', () => {
            const productSelect = editor.querySelector('[data-job-v4-product-select]');
            productSelect.innerHTML = `<option value="">Select product</option>${productOptions(categorySelect.value, '')}`;
            window.FlowtrackSearchableDropdown?.refresh(productSelect);
            window.FlowtrackSearchableDropdown?.enhance(editor);
        });
        editor.querySelector('[data-job-v4-product-cancel]')?.addEventListener('click', () => editor.remove());
        editor.querySelector('[data-job-v4-product-save]')?.addEventListener('click', async (event) => {
            const categoryId = Number(editor.querySelector('[data-job-v4-product-category]')?.value || 0);
            const productId = Number(editor.querySelector('[data-job-v4-product-select]')?.value || 0);
            const quantity = Math.max(1, Number(editor.querySelector('[data-job-v4-product-qty]')?.value || 1));
            if (!categoryId || !productId) return showToast('Select a category and product.');
            const nextProducts = currentProducts.slice();
            const next = { product_category_id: categoryId, product_id: productId, quantity };
            if (editingIndex >= nextProducts.length) nextProducts.push(next); else nextProducts[editingIndex] = next;
            if (new Set(nextProducts.map((item) => Number(item.product_id))).size !== nextProducts.length) return showToast('The same product cannot be added more than once.');
            event.currentTarget.disabled = true;
            try { await flowtrackJobV4SaveJob(job, { products: nextProducts }, 'Products updated.'); } catch (error) { event.currentTarget.disabled = false; showToast(error.message || 'Products could not be updated.'); }
        });
    }

    function flowtrackJobV4ApplyActivityFilter(content, tab = 'comments') {
        const normalized = ['comments', 'history', 'worklog'].includes(tab) ? tab : 'comments';
        content.querySelectorAll('[data-job-v4-activity-tab]').forEach((button) => button.classList.toggle('is-active', button.dataset.jobV4ActivityTab === normalized));
        let visible = 0;
        const wantedKind = normalized === 'comments' ? 'comment' : (normalized === 'history' ? 'history' : 'worklog');
        content.querySelectorAll('[data-job-v4-activity-kind]').forEach((row) => {
            const hide = row.dataset.jobV4ActivityKind !== wantedKind;
            row.classList.toggle('is-hidden', hide);
            if (!hide) visible++;
        });
        const compose = content.querySelector('[data-job-v4-comment-compose]');
        compose?.classList.toggle('is-hidden', normalized !== 'comments');
        const empty = content.querySelector('[data-job-v4-activity-empty]');
        if (empty) {
            empty.classList.toggle('is-hidden', visible > 0);
            empty.textContent = normalized === 'comments' ? 'No comments yet' : normalized === 'history' ? 'No job history yet' : 'No work log entries yet';
        }
    }

    function flowtrackJobV4Apply(job, preferredTab = 0) {
        const page = document.querySelector(`#page-record-job-${flowtrackJobDetailSlug(job)} .standalone-record-page`);
        const content = page?.querySelector('.standalone-record-content');
        if (!page || !content) return;
        page.classList.add('job-detail-v4-page');
        content.classList.add('job-detail-v4-content');
        const allTasks = flowtrackJobAllTasks(job);
        const allDocs = jobDocsFor(job);
        const team = flowtrackJobTeamMembers(job, allTasks);
        const header = content.querySelector('.drawer-head');
        if (header) {
            header.className = 'drawer-head job-v4-header';
            header.innerHTML = `<div class="job-v4-head-main"><div class="job-v4-breadcrumb">Jobs / ${html(job.id)}</div><div class="job-v4-title-row"><h2 data-job-v4-title-text>${html(job.title || job.id)}</h2>${canEditJobRecord() ? '<button type="button" class="job-v4-icon-btn job-v4-title-edit" data-job-v4-edit-title>✎</button>' : ''}</div><div class="job-v4-head-meta"><span>${html(clientName(job.client))}</span>${flowtrackJobHealthPill(job)}<span class="badge b-red">${html(job.priority || 'Medium')}</span><span class="badge b-purple">${html(phaseNameFor(job))}</span><button type="button" class="job-v4-code job-v4-code-copy" data-job-v4-copy-id="${html(job.id)}" title="Copy Job ID" aria-label="Copy Job ID ${html(job.id)}"><span>${html(job.id)}</span><span class="job-v4-copy-icon" aria-hidden="true">⧉</span></button></div></div><div class="job-v4-team">${team.slice(0, 4).map((member) => `<span class="avatar" title="${html(member.name)}">${html(member.initials)}</span>`).join('')}${team.length > 4 ? `<span class="job-v4-more">+${team.length - 4}</span>` : ''}</div><button type="button" class="close-btn" data-standalone-back aria-label="Close">×</button>`;
        }
        const tabs = content.querySelector('.detail-tabs');
        if (tabs) tabs.innerHTML = `<button class="detail-tab active" data-detailtab="0">Overview</button><button class="detail-tab" data-detailtab="1">Workflow</button><button class="detail-tab" data-detailtab="7">Commercial</button><button class="detail-tab" data-detailtab="8">Documents <span>${allDocs.length}</span></button>`;
        const panels = content.querySelector('#jobDetailPanels');
        if (panels) panels.innerHTML = flowtrackJobV4Panels(job);
        bindStandaloneBack(page);
        bindStandaloneDetailTabs(content);
        activateScopedDetailTab(content, preferredTab ?? 0);

        header?.querySelector('[data-job-v4-copy-id]')?.addEventListener('click', async (event) => {
            const button = event.currentTarget;
            const jobId = String(button?.dataset?.jobV4CopyId || job.id || '').trim();
            if (!jobId) return showToast('Job ID could not be copied.');
            let copied = false;
            try {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(jobId);
                    copied = true;
                }
            } catch (_) {}
            if (!copied) {
                const textarea = document.createElement('textarea');
                textarea.value = jobId;
                textarea.setAttribute('readonly', '');
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                textarea.style.pointerEvents = 'none';
                document.body.appendChild(textarea);
                textarea.select();
                try { copied = document.execCommand('copy'); } catch (_) { copied = false; }
                textarea.remove();
            }
            showToast(copied ? `${jobId} copied.` : 'Copy failed. Select the Job ID and copy it manually.');
        });

        header?.querySelector('[data-job-v4-edit-title]')?.addEventListener('click', () => {
            const row = header.querySelector('.job-v4-title-row');
            flowtrackJobV4InlineEditor(row, `<input type="text" maxlength="255" value="${html(job.title || '')}" data-job-v4-inline-value>`, async (target) => {
                const value = target.querySelector('[data-job-v4-inline-value]')?.value.trim();
                if (!value) throw new Error('Job title is required.');
                await flowtrackJobV4SaveJob(job, { title: value }, 'Job title updated.');
            });
        });

        content.querySelector('[data-job-v4-edit-description]')?.addEventListener('click', () => {
            const row = content.querySelector('.job-v4-description-row');
            flowtrackJobV4InlineEditor(row, `<textarea rows="2" maxlength="10000" data-job-v4-inline-value>${html(job.requestDescription || '')}</textarea>`, async (target) => {
                await flowtrackJobV4SaveJob(job, { request_description: target.querySelector('[data-job-v4-inline-value]')?.value.trim() || null }, 'Job overview updated.');
            });
        });
        content.querySelectorAll('[data-job-v4-edit-product]').forEach((button) => button.addEventListener('click', () => flowtrackJobV4ProductEditor(job, Number(button.dataset.jobV4EditProduct))));
        content.querySelector('[data-job-v4-add-product]')?.addEventListener('click', () => flowtrackJobV4ProductEditor(job, null));

        content.querySelectorAll('[data-job-v4-edit-field]').forEach((button) => button.addEventListener('click', () => {
            const field = button.dataset.jobV4EditField;
            const row = button.closest('.job-v4-plan-row');
            if (!row) return;
            if (field === 'delivery') {
                flowtrackJobV4InlineEditor(row, `<input type="date" value="${html(job.delivery || '')}" data-job-v4-inline-value>`, async (target) => flowtrackJobV4SaveJob(job, { delivery_date: target.querySelector('[data-job-v4-inline-value]')?.value || null }, 'Delivery date updated.'));
            } else if (field === 'priority') {
                const options = activeMaster('priorities').map((item) => `<option value="${html(item.id)}" ${Number(item.id) === Number(job.priorityId) ? 'selected' : ''}>${html(item.name)}</option>`).join('');
                flowtrackJobV4InlineEditor(row, `<select data-job-v4-inline-value data-searchable>${options}</select>`, async (target) => flowtrackJobV4SaveJob(job, { priority_id: Number(target.querySelector('[data-job-v4-inline-value]')?.value || 0) || null }, 'Priority updated.'));
            } else if (field === 'owner' || field === 'coordinator') {
                const selected = field === 'owner' ? job.ownerId : job.coordinatorId;
                const members = typeof flowtrackWorkspaceMembers === 'function' ? flowtrackWorkspaceMembers() : users;
                const options = members.map((member) => `<option value="${html(member.id)}" ${Number(member.id) === Number(selected) ? 'selected' : ''}>${html(member.name)}</option>`).join('');
                flowtrackJobV4InlineEditor(row, `<select data-job-v4-inline-value data-searchable>${options}</select>`, async (target) => flowtrackJobV4SaveJob(job, { [field === 'owner' ? 'owner_id' : 'coordinator_id']: Number(target.querySelector('[data-job-v4-inline-value]')?.value || 0) || null }, `${field === 'owner' ? 'Job owner' : 'Coordinator'} updated.`));
            }
        }));

        flowtrackJobV5BindWorkflow(content, job);

        // Phase accordion interaction is delegated at document level so it keeps
        // working after task/status updates re-render the Job Details panels.
        flowtrackJobV4UpdateExpandedCount(content);
        content.querySelectorAll('[data-job-v4-add-task]').forEach((button) => button.addEventListener('click', () => openJobTaskModal(job.id, Number(button.dataset.jobV4AddTask))));

        const applyTaskFilters = () => {
            const term = normalizedStatusValue(content.querySelector('[data-job-v4-task-search]')?.value || '');
            const status = normalizedStatusValue(content.querySelector('[data-job-v4-status-filter]')?.value || '');
            const assignee = normalizedStatusValue(content.querySelector('[data-job-v4-assignee-filter]')?.value || '');
            content.querySelectorAll('[data-job-v4-phase]').forEach((phase) => {
                let visible = 0;
                phase.querySelectorAll('[data-job-v4-task-row]').forEach((row) => {
                    const matches = (!term || normalizedStatusValue(row.textContent).includes(term)) && (!status || row.dataset.status === status) && (!assignee || row.dataset.assignee === assignee);
                    row.classList.toggle('is-filtered-out', !matches);
                    if (matches) visible++;
                });
                phase.classList.toggle('is-filtered-out', visible === 0);
            });
            flowtrackJobV4UpdateExpandedCount(content);
        };
        content.querySelector('[data-job-v4-task-search]')?.addEventListener('input', applyTaskFilters);
        content.querySelector('[data-job-v4-status-filter]')?.addEventListener('change', applyTaskFilters);
        content.querySelector('[data-job-v4-assignee-filter]')?.addEventListener('change', applyTaskFilters);
        content.querySelector('[data-job-v4-filter-toggle]')?.addEventListener('click', (event) => { event.stopPropagation(); content.querySelector('[data-job-v4-filter-popover]')?.classList.toggle('is-hidden'); });
        content.querySelector('[data-job-v4-clear-filters]')?.addEventListener('click', () => {
            const search = content.querySelector('[data-job-v4-task-search]'); const status = content.querySelector('[data-job-v4-status-filter]'); const assignee = content.querySelector('[data-job-v4-assignee-filter]');
            if (search) search.value = ''; if (status) status.value = ''; if (assignee) assignee.value = ''; applyTaskFilters();
        });

        content.querySelectorAll('[data-job-v4-open-task]').forEach((button) => button.addEventListener('click', () => {
            const row = button.closest('[data-job-v4-task-row]');
            if (!row || row.dataset.planned === '1') return;
            void openTask(row.dataset.taskId);
        }));
        const closeAssigneeEditor = (select) => {
            if (!select || select.disabled) return;
            const cell = select.closest('.job-v4-assignee-cell');
            select.remove();
            cell?.classList.remove('is-editing');
        };
        const bindAssigneeEditor = (select) => {
            if (!select || select.dataset.boundAssigneeEditor === '1') return;
            select.dataset.boundAssigneeEditor = '1';
            select.addEventListener('blur', () => window.setTimeout(() => {
                if ((select.value || '') === (select.dataset.previousValue || '')) closeAssigneeEditor(select);
            }, 0));
            select.addEventListener('change', async () => {
                const row = select.closest('[data-job-v4-task-row]');
                const previous = select.dataset.previousValue ?? '';
                select.disabled = true;
                try {
                    await flowtrackJobV4UpdateTask(job, row, { assignee_id: select.value ? Number(select.value) : null });
                    showToast('Task assignee updated.');
                } catch (error) {
                    select.value = previous;
                    select.disabled = false;
                    closeAssigneeEditor(select);
                    showToast(error.message || 'Assignee could not be updated.');
                }
            });
        };
        content.querySelectorAll('[data-job-v4-edit-assignee]').forEach((button) => button.addEventListener('click', () => {
            const cell = button.closest('.job-v4-assignee-cell');
            const row = button.closest('[data-job-v4-task-row]');
            if (!cell || !row || cell.querySelector('[data-job-v4-task-assignee]')) return;
            const select = document.createElement('select');
            select.className = 'job-v4-task-inline-select job-v4-assignee-editor';
            select.setAttribute('data-job-v4-task-assignee', '');
            // This editor is intentionally a single native select. The global searchable-select
            // enhancer wraps dynamic selects, which previously left orphan wrappers behind after
            // blur and made every click appear to add another assignee dropdown.
            select.setAttribute('data-native-select', '');
            select.setAttribute('aria-label', 'Task assignee');
            const currentAssigneeId = row.dataset.assigneeId || '';
            select.innerHTML = flowtrackJobV4AssigneeOptions({ assigneeId: currentAssigneeId ? Number(currentAssigneeId) : null });
            select.value = currentAssigneeId;
            select.dataset.previousValue = currentAssigneeId;
            cell.appendChild(select);
            cell.classList.add('is-editing');
            bindAssigneeEditor(select);
            window.requestAnimationFrame(() => {
                select.focus();
                if (typeof select.showPicker === 'function') {
                    try { select.showPicker(); } catch (_) {}
                }
            });
        }));
        content.querySelectorAll('[data-job-v4-task-status]').forEach((select) => select.addEventListener('change', async () => {
            const row = select.closest('[data-job-v4-task-row]');
            const previousValue = select.dataset.previousValue || '';
            let task = row?.dataset.planned === '1'
                ? flowtrackJobV4ConfiguredPhaseTasks(job, flowtrackActivePhases(job).find((phase) => Number(phase.id) === Number(row.dataset.phaseId)) || { id: Number(row.dataset.phaseId), sequence: 0, name: '' }, flowtrackJobAllTasks(job)).find((item) => String(item.id) === String(row.dataset.taskId) || Number(item.taskPackItemId || 0) === Number(row.dataset.packItemId || 0))
                : tasks.find((item) => Number(item.dbId) === Number(row?.dataset.taskDbId) || String(item.id) === String(row?.dataset.taskId));
            const requirement = flowtrackJobV4TaskDocumentRequirement(task, Number(row?.dataset.phaseId || 0), Number(row?.dataset.packItemId || 0));
            if (flowtrackJobV4StatusIsComplete(select.value) && requirement && !flowtrackJobV4TaskHasRequiredEvidence(job, task, requirement)) {
                select.value = previousValue;
                showToast(`Upload or provide the required ${requirement.categoryName || 'document'} before completing this task.`);
                if (task && !task.planned && task.id) window.setTimeout(() => void openTask(task.id), 120);
                return;
            }
            select.disabled = true;
            try {
                await flowtrackJobV4UpdateTask(job, row, { task_status_id: Number(select.value) });
                select.dataset.previousValue = select.value;
                showToast('Task status updated.');
            } catch (error) {
                select.disabled = false;
                select.value = previousValue;
                showToast(error.message || 'Status could not be updated. Open the task if a blocker reason is required.');
            }
        }));
        content.querySelectorAll('[data-job-v4-edit-due]').forEach((button) => button.addEventListener('click', () => {
            const input = button.closest('.job-v4-due-cell')?.querySelector('[data-job-v4-due-input]');
            if (!input) return;
            if (typeof input.showPicker === 'function') input.showPicker(); else input.click();
        }));
        content.querySelectorAll('[data-job-v4-due-input]').forEach((input) => input.addEventListener('change', async () => {
            const row = input.closest('[data-job-v4-task-row]'); input.disabled = true;
            try { await flowtrackJobV4UpdateTask(job, row, { due_date: input.value || null }); showToast('Task due date updated.'); } catch (error) { input.disabled = false; showToast(error.message || 'Due date could not be updated.'); }
        }));

        const dropzone = content.querySelector('[data-job-v4-dropzone]');
        const fileInput = content.querySelector('[data-job-v4-file-input]');
        const uploadJobAttachment = async (file) => {
            if (!file) return;
            if (!(canFlow('documents', 'create') && canFlow('documents', 'link'))) return showToast('Your role does not have Document Upload & Link access.');
            if (Number(file.size || 0) > 20 * 1024 * 1024) return showToast('The attachment must be 20 MB or smaller.');
            const formData = new FormData(); formData.append('file', file);
            try {
                const result = await api(`/flowtrack/template-api/jobs/${job.dbId}/attachments`, { method: 'POST', body: formData });
                flowtrackApplyLinkedDocumentResult(result);
                const updatedJob = flowtrackFindJob(result?.job?.id || job.id) || result?.job || job;
                flowtrackEnsureJobPanels(updatedJob, 0); flowtrackJobV4Apply(updatedJob, 0);
                showToast(result.message || 'Attachment uploaded successfully.');
            } catch (error) { showToast(error.message || 'Attachment upload failed.'); }
        };
        content.querySelector('[data-job-v4-browse]')?.addEventListener('click', (event) => { event.preventDefault(); event.stopPropagation(); fileInput?.click(); });
        fileInput?.addEventListener('change', () => { const file = fileInput.files?.[0]; if (file) void uploadJobAttachment(file); fileInput.value = ''; });
        content.querySelector('[data-job-v4-choose-documents]')?.addEventListener('click', () => flowtrackOpenExistingJobAttachmentChooser(job, (result) => {
            const updatedJob = flowtrackFindJob(result?.job?.id || job.id) || result?.job || job;
            flowtrackEnsureJobPanels(updatedJob, 0); flowtrackJobV4Apply(updatedJob, 0);
        }));
        content.querySelectorAll('[data-job-v4-delete-document]').forEach((button) => button.addEventListener('click', () => {
            const documentRecord = docs.find((item) => Number(item.dbId) === Number(button.dataset.jobV4DeleteDocument));
            if (!documentRecord) return showToast('The selected document could not be found.');
            flowtrackConfirmDeleteDocument(documentRecord, (result) => {
                const updatedJob = flowtrackFindJob(result?.job?.id || job.id) || result?.job || job;
                flowtrackEnsureJobPanels(updatedJob, 0); flowtrackJobV4Apply(updatedJob, 0);
            });
        }));
        dropzone?.addEventListener('dragover', (event) => { event.preventDefault(); dropzone.classList.add('is-dragging'); });
        dropzone?.addEventListener('dragleave', () => dropzone.classList.remove('is-dragging'));
        dropzone?.addEventListener('drop', (event) => { event.preventDefault(); dropzone.classList.remove('is-dragging'); const file = event.dataTransfer?.files?.[0]; if (file) void uploadJobAttachment(file); });
        dropzone?.addEventListener('keydown', (event) => { if (['Enter', ' '].includes(event.key) && !event.target.closest('button,input')) { event.preventDefault(); fileInput?.click(); } });

        content.querySelectorAll('[data-job-v4-activity-tab]').forEach((button) => button.addEventListener('click', () => flowtrackJobV4ApplyActivityFilter(content, button.dataset.jobV4ActivityTab)));
        flowtrackJobV4ApplyActivityFilter(content, 'comments');
        const commentInput = content.querySelector('[data-job-v4-comment-input]');
        const submitComment = async () => {
            const comment = commentInput?.value.trim(); if (!comment) return showToast('Enter a comment.');
            const button = content.querySelector('[data-job-v4-comment-submit]'); if (button) button.disabled = true;
            try {
                const result = await api(`/flowtrack/template-api/jobs/${job.dbId}/comments`, { method: 'POST', body: { comment } });
                if (result.job) flowtrackUpsertRecord(jobs, result.job);
                const updatedJob = flowtrackFindJob(result.job?.id || job.id) || job;
                flowtrackEnsureJobPanels(updatedJob, 0); flowtrackJobV4Apply(updatedJob, 0); showToast('Comment added.');
            } catch (error) { if (button) button.disabled = false; showToast(error.message || 'Comment could not be added.'); }
        };
        content.querySelector('[data-job-v4-comment-submit]')?.addEventListener('click', submitComment);
        commentInput?.addEventListener('keydown', (event) => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); submitComment(); } });

        bindJobDocumentUploadButtons(content, job, 8);
        flowtrackBindJobDocumentTemplate(content, job);
        applyActionPermissions();
        flowtrackJobV4UpdateExpandedCount(content);
    }

    jobPanels = function (job) { return flowtrackJobV4Panels(job); };

    const flowtrackJobV4PreviousOpenJob = currentFlowtrackImplementation('openJob');
    registerFlowtrackExtension('openJob', 'openJob-stage-5-final-reference-template', async function (id) {
        const result = await flowtrackJobV4PreviousOpenJob(id);
        const job = flowtrackFindJob(id) || flowtrackFindJob(result?.job?.id || result?.id);
        if (job) {
            setTimeout(() => flowtrackJobV4Apply(flowtrackFindJob(job.id) || job, 0), 0);
            setTimeout(() => flowtrackJobV4Apply(flowtrackFindJob(job.id) || job, 0), 180);
        }
        return result;
    });

    
    /* CSS externalized: flowtrackJobV4Styles. */

    // Workflow readability pass: preserve the approved layout and interactions,
    // but remove the overly compressed 8–10px typography from the prototype.
    
    /* CSS externalized: flowtrackWorkflowReadabilityStyles. */


    // Create Job — final prototype layout.
    // This is a presentation-only reflow of the existing create-job controls: the
    // original IDs, event handlers, validation and POST payload remain intact.
    function flowtrackCreateJobPrototypePriority(host) {
        const priorityField = host.querySelector('input[name="newJobPriority"]')?.closest('.field');
        if (!priorityField || priorityField.querySelector('#newJobPrioritySelect')) return;
        const radios = Array.from(priorityField.querySelectorAll('input[name="newJobPriority"]'));
        if (!radios.length) return;
        const selected = radios.find((radio) => radio.checked) || radios[0];
        const select = document.createElement('select');
        select.id = 'newJobPrioritySelect';
        select.className = 'job-create-prototype-priority-select';
        select.setAttribute('aria-label', 'Priority');
        select.innerHTML = radios.map((radio) => {
            const label = radio.closest('label')?.querySelector('span:last-child')?.textContent?.trim() || `Priority ${radio.value}`;
            return `<option value="${html(radio.value)}" ${String(radio.value) === String(selected?.value) ? 'selected' : ''}>${html(label)}</option>`;
        }).join('');
        select.addEventListener('change', () => {
            radios.forEach((radio) => { radio.checked = String(radio.value) === String(select.value); });
        });
        const options = priorityField.querySelector('.job-priority-options');
        if (options) options.classList.add('job-create-prototype-priority-source');
        options?.insertAdjacentElement('beforebegin', select);
    }

    function flowtrackCreateJobPrototypeWorkflowSummary(host) {
        const workflowSelect = host.querySelector('#newJobWorkflow');
        const phaseSelect = host.querySelector('#newJobStartPhase');
        const summary = host.querySelector('[data-job-create-workflow-summary]');
        const preview = host.querySelector('[data-job-create-workflow-preview-panel]');
        if (!workflowSelect || !phaseSelect || !summary) return;

        const workflow = getWorkflow(Number(workflowSelect.value));
        const phases = flowtrackToArray(workflow?.phases)
            .filter((phase) => phase && phase.active !== false)
            .slice()
            .sort((left, right) => Number(left.sequence || 0) - Number(right.sequence || 0));
        const totalTasks = phases.reduce((sum, phase) => sum + Number(phase.taskCount || 0), 0);
        summary.innerHTML = `<span class="job-create-info-icon">ⓘ</span><b>${phases.length} phase${phases.length === 1 ? '' : 's'}</b><span>·</span><b>${totalTasks} task${totalTasks === 1 ? '' : 's'} will be created</b>`;

        if (preview) {
            preview.innerHTML = phases.length
                ? phases.map((phase, index) => `<div class="job-create-preview-step"><span>${index + 1}</span><div><b>${html(phase.name || `Phase ${index + 1}`)}</b><small>${Number(phase.taskCount || 0)} task${Number(phase.taskCount || 0) === 1 ? '' : 's'}${phase.taskPack ? ` · ${html(phase.taskPack)}` : ''}</small></div></div>`).join('')
                : '<div class="small muted">No active phases are configured for this workflow.</div>';
        }
    }

    function flowtrackCreateJobPrototypeProductSummary(host) {
        const summary = host.querySelector('[data-job-create-product-summary]');
        if (!summary) return;
        const rows = Array.from(host.querySelectorAll('#newJobProducts .job-product-row'));
        const total = rows.reduce((sum, row) => sum + Math.max(0, Number(row.querySelector('.new-job-quantity')?.value || 0)), 0);
        summary.textContent = `${rows.length} product${rows.length === 1 ? '' : 's'} · ${total.toLocaleString()} total units`;
    }

    function flowtrackApplyCreateJobPrototypeLayout() {
        const page = document.querySelector('#page-record-job-new');
        const content = page?.querySelector('.standalone-record-content');
        const body = content?.querySelector('.modal-body');
        const grid = body?.querySelector('.job-form-stack');
        if (!page || !content || !body || !grid || content.dataset.jobCreatePrototypeApplied === '1') return false;
        content.dataset.jobCreatePrototypeApplied = '1';
        page.classList.add('job-create-prototype-page');
        content.classList.add('job-create-prototype-content');

        const title = page.querySelector('.standalone-record-title');
        if (title) {
            const paragraphs = title.querySelectorAll('p');
            if (paragraphs[0]) paragraphs[0].textContent = 'Jobs / Create job';
            const heading = title.querySelector('h1');
            if (heading) heading.textContent = 'Create new job';
            if (paragraphs[1]) paragraphs[1].textContent = 'Set the job scope, products, ownership and workflow.';
        }

        const modalHead = content.querySelector('.modal-head');
        if (modalHead) modalHead.classList.add('job-create-prototype-hidden-head');
        const footer = content.querySelector('.modal-foot');
        if (footer) {
            footer.classList.add('job-create-prototype-footer');
            const createButton = footer.querySelector('#createJob');
            const draftButton = footer.querySelector('#saveDraftJob');
            if (createButton) createButton.textContent = 'Create job';
            if (draftButton) draftButton.textContent = 'Save draft';
        }

        const fieldFor = (selector) => hostField(content.querySelector(selector));
        function hostField(node) { return node?.closest('.field') || null; }

        const clientField = fieldFor('#newJobClient');
        const contactField = fieldFor('#newJobContact');
        const titleField = fieldFor('#newJobTitle');
        const descriptionField = fieldFor('#newJobDesc');
        const productField = content.querySelector('#newJobProducts')?.closest('.job-products-field');
        const deliveryField = fieldFor('#newJobDelivery');
        const ownerField = fieldFor('#newJobOwner');
        const workflowField = fieldFor('#newJobWorkflow');
        const phaseField = fieldFor('#newJobStartPhase');
        const attachmentField = fieldFor('#newJobAttachments');
        const priorityField = content.querySelector('input[name="newJobPriority"]')?.closest('.field');

        const layout = document.createElement('div');
        layout.className = 'job-create-prototype';
        layout.innerHTML = `
            <section class="job-create-prototype-card" data-job-create-section="basics">
                <div class="job-create-prototype-section-title"><span>1</span><h2>Job basics</h2></div>
                <div class="job-create-prototype-basics-grid">
                    <div class="job-create-prototype-code-field field"><label>Job code</label><div class="job-create-prototype-readonly"><input value="Generated automatically" readonly aria-readonly="true"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg></div></div>
                    <div data-job-create-client-slot></div>
                    <div data-job-create-title-slot></div>
                    <div data-job-create-description-slot></div>
                </div>
            </section>
            <section class="job-create-prototype-card" data-job-create-section="products">
                <div class="job-create-prototype-section-title"><span>2</span><h2>Products &amp; quantities</h2></div>
                <div data-job-create-products-slot></div>
            </section>
            <section class="job-create-prototype-card" data-job-create-section="schedule">
                <div class="job-create-prototype-section-title"><span>3</span><h2>Schedule &amp; owner</h2></div>
                <div class="job-create-prototype-schedule-grid"><div data-job-create-delivery-slot></div><div data-job-create-priority-slot></div><div data-job-create-owner-slot></div></div>
            </section>
            <section class="job-create-prototype-card" data-job-create-section="workflow">
                <div class="job-create-prototype-section-title"><span>4</span><h2>Workflow</h2></div>
                <div class="job-create-prototype-workflow-grid"><div data-job-create-workflow-slot></div><div data-job-create-phase-slot></div></div>
                <div class="job-create-prototype-info-row"><div data-job-create-workflow-summary></div><button type="button" data-job-create-preview-workflow>Preview workflow ↗</button></div>
                <div class="job-create-prototype-preview" data-job-create-workflow-preview-panel hidden></div>
                <p class="job-create-prototype-note">Workflow and starting phase are fixed after creation; transitions are managed from the Workflow tab.</p>
            </section>
            <section class="job-create-prototype-card job-create-prototype-attachments" data-job-create-section="attachments">
                <div class="job-create-prototype-section-title"><span>5</span><h2>Attachments</h2></div>
                <div data-job-create-attachment-slot></div>
            </section>`;

        const move = (node, selector) => {
            const slot = layout.querySelector(selector);
            if (node && slot) slot.appendChild(node);
        };
        move(clientField, '[data-job-create-client-slot]');
        if (clientField && contactField) {
            contactField.classList.add('job-create-prototype-contact');
            layout.querySelector('[data-job-create-client-slot]')?.appendChild(contactField);
        }
        move(titleField, '[data-job-create-title-slot]');
        move(descriptionField, '[data-job-create-description-slot]');
        move(productField, '[data-job-create-products-slot]');
        move(deliveryField, '[data-job-create-delivery-slot]');
        move(priorityField, '[data-job-create-priority-slot]');
        move(ownerField, '[data-job-create-owner-slot]');
        move(workflowField, '[data-job-create-workflow-slot]');
        move(phaseField, '[data-job-create-phase-slot]');
        move(attachmentField, '[data-job-create-attachment-slot]');
        grid.replaceChildren(layout);

        titleField?.classList.add('job-create-prototype-full');
        descriptionField?.classList.add('job-create-prototype-full');
        productField?.classList.add('job-create-prototype-product-field');
        deliveryField?.classList.add('job-create-prototype-compact-field');
        priorityField?.classList.add('job-create-prototype-compact-field');
        ownerField?.classList.add('job-create-prototype-compact-field');
        workflowField?.classList.add('job-create-prototype-compact-field');
        phaseField?.classList.add('job-create-prototype-compact-field');
        attachmentField?.classList.add('job-create-prototype-attachment-field');

        const ownerLabel = ownerField?.querySelector('label');
        const workflowLabel = workflowField?.querySelector('label');
        const phaseLabel = phaseField?.querySelector('label');
        if (ownerLabel) ownerLabel.textContent = 'Job owner *';
        if (workflowLabel) workflowLabel.textContent = 'Workflow';
        if (phaseLabel) phaseLabel.textContent = 'Starting phase';
        const ownerHelp = ownerField?.querySelector('.inline-help');
        if (ownerHelp) ownerHelp.textContent = 'Accountable for overall delivery.';
        workflowField?.querySelector('.inline-help')?.classList.add('job-create-prototype-hide-help');
        phaseField?.querySelector('.inline-help')?.classList.add('job-create-prototype-hide-help');

        const productLabels = productField?.querySelectorAll('.job-product-labels span');
        if (productLabels?.[3]) productLabels[3].textContent = 'Action';
        const productTitle = productField?.querySelector('.job-products-title');
        if (productTitle) productTitle.classList.add('job-create-prototype-hide-product-title');
        const addProduct = productField?.querySelector('#addJobProduct');
        if (addProduct) addProduct.textContent = '＋ Add product';
        if (productField && !productField.querySelector('[data-job-create-product-summary]')) {
            const bottom = document.createElement('div');
            bottom.className = 'job-create-prototype-product-bottom';
            bottom.innerHTML = '<span data-job-create-product-button-slot></span><span data-job-create-product-summary></span>';
            const buttonSlot = bottom.querySelector('[data-job-create-product-button-slot]');
            if (addProduct && buttonSlot) buttonSlot.appendChild(addProduct);
            productField.appendChild(bottom);
        }

        flowtrackCreateJobPrototypePriority(content);
        flowtrackCreateJobPrototypeWorkflowSummary(content);
        flowtrackCreateJobPrototypeProductSummary(content);
        window.FlowtrackSearchableDropdown?.enhance(page);

        const updateWorkflow = () => setTimeout(() => flowtrackCreateJobPrototypeWorkflowSummary(content), 0);
        content.querySelector('#newJobWorkflow')?.addEventListener('change', updateWorkflow);
        content.querySelector('#newJobStartPhase')?.addEventListener('change', updateWorkflow);
        content.querySelector('[data-job-create-preview-workflow]')?.addEventListener('click', () => {
            const preview = content.querySelector('[data-job-create-workflow-preview-panel]');
            const button = content.querySelector('[data-job-create-preview-workflow]');
            if (!preview || !button) return;
            preview.hidden = !preview.hidden;
            button.textContent = preview.hidden ? 'Preview workflow ↗' : 'Hide workflow ↑';
        });

        const productList = content.querySelector('#newJobProducts');
        if (productList) {
            const observer = new MutationObserver(() => flowtrackCreateJobPrototypeProductSummary(content));
            observer.observe(productList, { childList: true, subtree: true });
            productList.addEventListener('input', () => flowtrackCreateJobPrototypeProductSummary(content));
            productList.addEventListener('change', () => setTimeout(() => flowtrackCreateJobPrototypeProductSummary(content), 0));
        }

        const delivery = content.querySelector('#newJobDelivery');
        delivery?.addEventListener('click', () => {
            if (typeof delivery.showPicker === 'function') {
                try { delivery.showPicker(); } catch (_) {}
            }
        });
        return true;
    }

    const flowtrackCreateJobPrototypeOpen = openJobModal;
    openJobModal = function (prefillClient) {
        const result = flowtrackCreateJobPrototypeOpen(prefillClient);
        queueMicrotask(() => flowtrackApplyCreateJobPrototypeLayout());
        setTimeout(() => flowtrackApplyCreateJobPrototypeLayout(), 0);
        setTimeout(() => flowtrackApplyCreateJobPrototypeLayout(), 80);
        return result;
    };

    
    /* CSS externalized: flowtrackCreateJobPrototypeStyles. */

    
    /* CSS externalized: flowtrackJobListPrototypeFinalStyles. */

    
    /* CSS externalized: flowtrackJobListFilterConsistencyStyles. */


/* FlowTrack consistency/readability and document action hardening. */
(() => {
    if (document.getElementById('flowtrack-overview-consistency-document-actions')) return;
    
    /* CSS externalized: style. */
})();

/* Job title edit/readability, copy affordance, and required-document task cues. */
(() => {
    if (document.getElementById('flowtrack-job-title-edit-copy-required-doc')) return;
    
    /* CSS externalized: style. */
})();

/* Job Details small-device compatibility hardening — Overview / Workflow / Commercial / Documents. */
(() => {
    if (document.getElementById('flowtrack-job-detail-small-device-fix')) return;
    
    /* CSS externalized: style. */
})();

/* Tablet-width task/product/history layouts: avoid clipped job-detail tables around 768–820px. */
(() => {
    if (document.getElementById('flowtrack-job-detail-tablet-card-fix')) return;
    
    /* CSS externalized: style. */
})();
