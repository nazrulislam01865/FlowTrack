<?php

namespace App\Observers;

use App\Models\Activity;
use App\Models\FlowJob;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\OrderActivityNotice;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;

/** Deliver only previously uncovered Order events using the existing private,
 * permission-checked notification fanout, after the underlying write commits. */
final class OrderActivityNotificationObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Activity $activity): void
    {
        $event = (string) $activity->event;
        if (! OrderActivityNotice::sendFromActivity($event)) return;
        if (! in_array((string) $activity->subject_type, [FlowJob::class, 'flow_job'], true)) return;

        try {
            $job = FlowJob::query()->select(['id', 'job_number', 'owner_id', 'coordinator_id'])->find((int) $activity->subject_id);
            if (! $job) return;

            // The activity identifies its actor already. No additional user read
            // is needed just to pass the actor ID to the notification service.
            $actor = null;
            if ((int) $activity->user_id > 0) {
                $actor = new User();
                $actor->id = (int) $activity->user_id;
            }
            $label = OrderActivityNotice::label($event, (array) ($activity->meta ?? []));
            app(NotificationService::class)->notifyJobParticipants(
                $job,
                $label,
                $job->displayOrderNumber().' · '.$label.'. Open the order for details.',
                'order_activity',
                $actor,
            );
        } catch (\Throwable $exception) {
            // Notification delivery must never invalidate a committed Order edit.
            Log::warning('Order activity notification could not be queued.', [
                'activity_id' => $activity->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
