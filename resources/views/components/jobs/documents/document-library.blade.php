            <section class="ft-detail-card ft-job-documents-table ft-doc-library-card">
                <div class="ft-doc-library-heading">
                    <div>
                        <h3>Job documents</h3>
                        <p>Organized by workflow phase so you can see what is missing without scanning a table.</p>
                    </div>
                    <span class="ft-soft-pill {{ $missingRequired ? 'amber' : 'green' }}">{{ $missingRequired ? $missingRequired.' required missing' : 'All requirements satisfied' }}</span>
                </div>

                <div class="ft-doc-phase-list">
                    @foreach($job->workflow->phases as $phase)
                        @php
                            $phaseRequirements = $required->filter(fn($item)=>(int)$item->phase->id===(int)$phase->id)->values();
                            $phaseTaskIds = $job->tasks->where('workflow_phase_id',$phase->id)->pluck('id');
                            $phaseDocuments = $job->documents->whereIn('task_id',$phaseTaskIds)->values();
                            $phaseRequiredReceived = $phaseRequirements->where('complete', true)->count();
                            $phaseMissing = $phaseRequirements->where('complete', false)->count();
                            $requiredDocumentIds = $phaseRequirements->flatMap(function($requirement) use ($job) {
                                // task_id is the authoritative requirement link; the
                                // stored category label may be generic on legacy files.
                                return $job->documents
                                    ->where('task_id', $requirement->task->id)
                                    ->pluck('id');
                            })->map(fn($id)=>(int)$id)->unique();
                            $phaseAttachments = $phaseDocuments->reject(fn($doc)=>$requiredDocumentIds->contains((int)$doc->id))->values();
                            $openPhase = (int)($job->workflow_phase_id ?? 0) === (int)$phase->id || ((int)($job->workflow_phase_id ?? 0) === 0 && (int)$phase->sequence === 1);
                        @endphp

                        <details class="ft-doc-phase-group" style="{{ \App\Support\MasterColor::style($phase->color) }}" <?php if ($openPhase): ?>open<?php endif; ?>>
                            <summary class="ft-doc-phase-summary">
                                <span class="ft-doc-phase-chevron">›</span>
                                <b class="ft-doc-phase-number">{{ $phase->sequence }}</b>
                                <span class="ft-doc-phase-copy">
                                    <strong>{{ $phase->name }}</strong>
                                    <small>{{ $phaseRequiredReceived }} of {{ $phaseRequirements->count() }} requirements satisfied · {{ $phaseDocuments->count() }} file{{ $phaseDocuments->count()===1?'':'s' }}</small>
                                </span>
                                <?php if ($phaseMissing): ?>
                                    <span class="ft-soft-pill amber">{{ $phaseMissing }} needs action</span>
                                <?php elseif ($phaseRequirements->isNotEmpty()): ?>
                                    <span class="ft-soft-pill green">Complete</span>
                                <?php else: ?>
                                    <span class="ft-soft-pill gray">No requirements</span>
                                <?php endif; ?>
                            </summary>

                            <div class="ft-doc-phase-body">
                                @forelse($phaseRequirements as $requirement)
                                    @php
                                        $docs = $job->documents->where('task_id',$requirement->task->id)->values();
                                        $links = \App\Support\JobDetailPresenter::taskLinks($job, $requirement->task);
                                    @endphp
                                    <article class="ft-doc-requirement-card {{ $requirement->complete ? 'is-complete' : 'needs-action' }}">
                                        <div class="ft-doc-requirement-main">
                                            <span class="ft-doc-requirement-state">{{ $requirement->complete ? '✓' : '!' }}</span>
                                            <div class="ft-doc-requirement-copy">
                                                <div class="ft-doc-requirement-title-line">
                                                    <b>{{ $requirement->name }}</b>
                                                    <span class="ft-soft-pill {{ $requirement->complete ? 'green' : 'amber' }}">{{ $requirement->complete ? 'Satisfied' : 'Required' }}</span>
                                                </div>
                                                <small>Task: {{ $requirement->task->title }}</small>
                                                <?php if ($docs->isEmpty() && $links->isEmpty()): ?>
                                                    <p>No file or document link has been submitted for this requirement yet.</p>
                                                <?php else: ?>
                                                    <p>
                                                        @if($docs->isNotEmpty()){{ $docs->count() }} file{{ $docs->count()===1?'':'s' }}@endif
                                                        @if($docs->isNotEmpty() && $links->isNotEmpty()) · @endif
                                                        @if($links->isNotEmpty()){{ $links->count() }} link{{ $links->count()===1?'':'s' }}@endif
                                                        submitted for this requirement.
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (! $requirement->complete && $canUploadDocument): ?>
                                                <button class="ft-outline-btn ft-doc-requirement-upload" type="button" x-on:click="await $wire.set('jobDocumentTaskId', {{ $requirement->task->id }}); document.getElementById('jobDocumentUpload-{{ $job->id }}').click()">Upload file</button>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($docs->isNotEmpty()): ?>
                                            <div class="ft-doc-linked-files">
                                                @foreach($docs as $doc)
                                                    @php $extension = strtoupper(pathinfo($doc->name, PATHINFO_EXTENSION) ?: 'FILE'); @endphp
                                                    <div class="ft-doc-linked-file">
                                                        <span class="ft-file-icon {{ str_contains(strtolower($doc->mime_type ?? ''),'pdf') ? 'pdf' : 'sheet' }}">▣</span>
                                                        <div class="ft-doc-linked-file-copy">
                                                            <a href="{{ route('documents.open',$doc) }}" target="_blank" rel="noopener">{{ $doc->name }}</a>
                                                            <small>{{ $extension }} · v{{ $doc->version }} · {{ $doc->uploader?->name ?? 'FlowTrack' }} · {{ \App\Support\UserLocalTime::isToday($doc->updated_at) ? 'Today '.\App\Support\UserLocalTime::format($doc->updated_at, 'g:i A') : \App\Support\UserLocalTime::format($doc->updated_at, 'M j, Y') }}</small>
                                                        </div>
                                                        <span class="ft-soft-pill green">Linked</span>
                                                        <div class="ft-doc-linked-actions">
                                                            <a class="ft-link-blue" href="{{ route('documents.open',$doc) }}" target="_blank" rel="noopener">Open</a>
                                                            <?php if (auth()->user()->canModule('documents','delete')): ?>
                                                                <button class="ft-doc-delete-button" type="button" wire:click="deleteJobDocument({{ $doc->id }})" wire:confirm="Delete this document link?" aria-label="Delete {{ $doc->name }}">×</button>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($links->isNotEmpty()): ?>
                                            <div class="ft-doc-linked-files">
                                                @foreach($links as $taskLink)
                                                    <div class="ft-doc-linked-file">
                                                        <span class="ft-file-icon sheet">↗</span>
                                                        <div class="ft-doc-linked-file-copy">
                                                            <a href="{{ $taskLink->url }}" target="_blank" rel="noopener noreferrer">{{ \Illuminate\Support\Str::limit($taskLink->url, 100) }}</a>
                                                            <small>External document link · {{ $taskLink->created_at ? \App\Support\UserLocalTime::format($taskLink->created_at, 'M j, Y, g:i A') : '—' }}</small>
                                                        </div>
                                                        <span class="ft-soft-pill green">Accepted</span>
                                                        <div class="ft-doc-linked-actions">
                                                            <a class="ft-link-blue" href="{{ $taskLink->url }}" target="_blank" rel="noopener noreferrer">Open ↗</a>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        <?php endif; ?>
                                    </article>
                                @empty
                                    <div class="ft-doc-empty-phase">No Task Pack document requirements in this phase.</div>
                                @endforelse

                                <?php if ($phaseAttachments->isNotEmpty()): ?>
                                    <div class="ft-doc-attachments-block">
                                        <div class="ft-doc-subsection-label"><span>Attachments</span><small>Files linked to tasks but not counted as required documents</small></div>
                                        @foreach($phaseAttachments as $doc)
                                            @php $extension = strtoupper(pathinfo($doc->name, PATHINFO_EXTENSION) ?: 'FILE'); @endphp
                                            <div class="ft-doc-linked-file is-attachment">
                                                <span class="ft-file-icon">▣</span>
                                                <div class="ft-doc-linked-file-copy">
                                                    <a href="{{ route('documents.open',$doc) }}" target="_blank" rel="noopener">{{ $doc->name }}</a>
                                                    <small>{{ $extension }} · v{{ $doc->version }} · Task: {{ $doc->task?->title }} · {{ \App\Support\UserLocalTime::format($doc->updated_at, 'M j, Y') }}</small>
                                                </div>
                                                <span class="ft-soft-pill gray">Attachment</span>
                                                <div class="ft-doc-linked-actions"><a class="ft-link-blue" href="{{ route('documents.open',$doc) }}" target="_blank" rel="noopener">Open</a></div>
                                            </div>
                                        @endforeach
                                    </div>
                                <?php endif; ?>
                            </div>
                        </details>
                    @endforeach

                    <?php if ($unlinkedDocs->isNotEmpty()): ?>
                        <details class="ft-doc-phase-group ft-doc-unlinked-group">
                            <summary class="ft-doc-phase-summary">
                                <span class="ft-doc-phase-chevron">›</span>
                                <b class="ft-doc-phase-number">—</b>
                                <span class="ft-doc-phase-copy"><strong>Existing Job attachments</strong><small>Files not linked to a Task Pack requirement</small></span>
                                <span class="ft-soft-pill gray">{{ $unlinkedDocs->count() }} file{{ $unlinkedDocs->count()===1?'':'s' }}</span>
                            </summary>
                            <div class="ft-doc-phase-body">
                                @foreach($unlinkedDocs as $doc)
                                    @php $extension = strtoupper(pathinfo($doc->name, PATHINFO_EXTENSION) ?: 'FILE'); @endphp
                                    <div class="ft-doc-linked-file is-attachment">
                                        <span class="ft-file-icon">▣</span>
                                        <div class="ft-doc-linked-file-copy">
                                            <a href="{{ route('documents.open',$doc) }}" target="_blank" rel="noopener">{{ $doc->name }}</a>
                                            <small>{{ $extension }} · v{{ $doc->version }} · {{ $doc->uploader?->name ?? 'FlowTrack' }} · {{ \App\Support\UserLocalTime::format($doc->updated_at, 'M j, Y') }}</small>
                                        </div>
                                        <span class="ft-soft-pill gray">Attachment</span>
                                        <div class="ft-doc-linked-actions"><a class="ft-link-blue" href="{{ route('documents.open',$doc) }}" target="_blank" rel="noopener">Open</a></div>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            </section>
