<?php

namespace App\Services\SupplierBridge;

use App\Models\Document;
use App\Models\Task;
use App\Services\DocumentService;
use Illuminate\Support\Collection;

/** A single source of truth for artwork shown in the catalogue and permitted for download. */
final class ApprovedOrderArtwork
{
    /** @param Collection<int, object> $tasks Order-scoped task-pack rows, latest first. */
    public function confirmed(int $sequence, Collection $tasks, bool $orderCompleted = false): bool
    {
        $byKey = $tasks->unique('automation_key')->keyBy('automation_key');
        $internal = $byKey->get('ART_INTERNAL_REVIEW');
        // When the review task is present, a later revision must not expose a new upload.
        if ($internal && ! $this->done($internal)) return false;
        $client = $byKey->get('ART_CLIENT_ERP_DECISION');
        $sample = $byKey->get('ART_SAMPLE_APPROVAL');
        $sampleStatus = strtolower(trim((string) ($sample?->status ?? '')));
        $sampleSatisfied = ! $sample || $this->done($sample)
            || in_array($sampleStatus, ['skipped', 'not applicable', 'n/a'], true);
        $clientWaitingForSample = strcasecmp(trim((string) ($client?->status ?? '')), 'Waiting for Sample Approval') === 0;
        // A pre-created optional Sample Approval row can be Ready without the
        // client ever requesting a sample. Only the actual branch transition
        // must block release of the internally confirmed artwork.
        $sampleAwaitingDecision = $clientWaitingForSample && $sample && ! $sampleSatisfied;
        // Artwork confirmed by the internal reviewer and sent to the order
        // team is the version the supplier may use. Client ERP / Approval is
        // a separate, later stage task; it must not hide internally confirmed
        // artwork while the ERP handoff is still Ready. Never release a newer
        // revision under review or one held for a required sample approval.
        $sent = $byKey->get('ART_SEND_ORDER_TEAM');
        $internallyConfirmed = $internal && $this->done($internal)
            && $sent && $this->done($sent)
            && ! $sampleAwaitingDecision && ! $clientWaitingForSample;

        return $internallyConfirmed || $sequence > 2 || $orderCompleted
            || ($client && $this->done($client) && $sampleSatisfied)
            || ($sample && $this->done($sample));
    }

    /**
     * Select only the latest confirmed, non-cancelled upload batch. Uploaded
     * artwork normally has is_final=false; confirmation is a *task* event.
     * Also expose the generated tracking PDF when it belongs to this version.
     *
     * @param Collection<int,object> $taskRows
     * @return Collection<int,Document>
     */
    public function documents(int $orderId, Collection $taskRows): Collection
    {
        $byKey = $taskRows->unique('automation_key')->keyBy('automation_key');
        $upload = $byKey->get('ART_PREPARE_UPLOAD');
        if (! $upload) return collect();
        $task = Task::query()->with(['setupTemplate', 'job'])->find((int) $upload->id);
        if (! $task) return collect();

        $current = app(DocumentService::class)->currentArtworkDocuments($task)
            ->filter(static fn (Document $file): bool => (int) $file->size > 0
                && (int) $file->size <= 419430400 && (string) $file->path !== '')
            ->values();
        if ($current->isEmpty()) return collect();
        $version = max(1, (int) $current->max('version'));
        $pdf = Document::query()->where('flow_job_id', $orderId)
            ->where('category', Document::CATEGORY_ARTWORK_TRACKING_PDF)
            ->where('is_final', true)->orderByDesc('version')->orderByDesc('id')->first();

        // A tracking PDF for a different version must never be shared. Keep
        // the currently confirmed uploaded files instead of hiding all artwork
        // because an older PDF has not yet been regenerated.
        if ($pdf && (int) $pdf->version !== $version) $pdf = null;
        $files = $current->take($pdf ? 19 : 20);
        if ($pdf && (int) $pdf->size > 0 && (int) $pdf->size <= 419430400
            && (string) $pdf->path !== '') {
            $files = collect([$pdf])->concat($files);
        }
        return $files->take(20)->values();
    }

    private function done(object $task): bool
    {
        return ($task->completed_at ?? null) !== null
            || strcasecmp((string) ($task->status ?? ''), 'Completed') === 0;
    }
}
