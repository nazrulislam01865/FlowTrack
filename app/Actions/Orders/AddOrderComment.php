<?php

namespace App\Actions\Orders;

use App\Models\User;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\AccessControlService;
use App\Services\MentionService;
use App\Services\NotificationService;
use App\Services\Orders\OrderHoldService;
use App\Services\RichTextService;

/** Persist an Order comment and its existing participant/mention notifications. */
final class AddOrderComment
{
    public function __construct(
        private readonly VisibleOrderQuery $orders,
        private readonly AccessControlService $access,
        private readonly RichTextService $richText,
        private readonly MentionService $mentions,
        private readonly NotificationService $notifications,
    ) {
    }

    public function handle(User $actor, int $orderId, string $comment): bool
    {
        $body = $this->richText->normalize($comment, 5000, 'jobComment');
        if (!$body) return false;

        $job = $this->orders->detail($actor, $orderId);
        abort_unless($this->access->canEditJob($actor, $job), 403);
        app(OrderHoldService::class)->assertNotHeld($job);
        $mentionIds = $this->mentions->userIdsFromText($body);

        $job->activities()->create([
            'user_id' => $actor->id,
            'event' => 'job.comment',
            'description' => $body,
            'meta' => ['body' => $body, 'mention_user_ids' => $mentionIds],
        ]);

        $this->notifications->notifyJobParticipants(
            $job,
            'New comment on '.$job->displayOrderNumber(),
            $body,
            'comment',
            $actor,
            [],
            $mentionIds,
        );
        $this->notifications->notifyMentionedUsers(
            $mentionIds,
            $actor->name.' mentioned you in '.$job->displayOrderNumber(),
            $body,
            $job,
            null,
            $actor,
        );

        return true;
    }
}
