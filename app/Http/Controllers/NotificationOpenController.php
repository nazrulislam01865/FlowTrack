<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\FlowJob;
use App\Models\FlowNotification;
use App\Models\FlowTaskComment;
use App\Models\InquiryTask;
use App\Services\AccessControlService;
use App\Services\JobService;
use App\Services\NotificationService;
use App\Services\InquiryService;
use App\Services\TaskService;
use Illuminate\Http\RedirectResponse;

class NotificationOpenController extends Controller
{
    public function __invoke(FlowNotification $notification): RedirectResponse
    {
        $user = auth()->user();
        abort_unless((int) $notification->user_id === (int) $user->id, 403);
        if ((string) $notification->type === 'mention_admin') {
            abort_unless(app(AccessControlService::class)->isAdministrator($user), 403);
        }

        // Route model binding can still resolve a historical notification row.
        // Re-check the live-context query before marking it read or generating a
        // deep link so deleted Orders/Tasks/Inquiries are never visitable.
        $notification = app(\App\Services\NotificationService::class)
            ->visibleQuery($user)
            ->whereKey($notification->id)
            ->first();

        if (! $notification) {
            return redirect()
                ->route('notifications')
                ->with('warning', 'The record linked to this notification is no longer available.');
        }

        if ($notification->read_at === null) {
            app(NotificationService::class)->markRead($user, $notification);
        }

        $access = app(AccessControlService::class);

        if ($notification->inquiry_task_id || $notification->inquiry_id) {
            $inquiryId = (int) ($notification->inquiry_id ?: InquiryTask::query()->whereKey($notification->inquiry_task_id)->value('inquiry_id'));
            $inquiry = $inquiryId ? app(InquiryService::class)->visibleQuery($user)->select(['inquiries.id'])->find($inquiryId) : null;
            if ($inquiry) {
                $params = ['open' => (int) $inquiry->id];
                if ($notification->inquiry_task_id) {
                    $params['task'] = (int) $notification->inquiry_task_id;
                } elseif (in_array((string) $notification->type, ['mention', 'mention_admin', 'comment'], true)) {
                    $params['tab'] = 'activity';
                }
                return redirect()->route('inquiries.index', $params);
            }
        }

        if ($notification->flow_task_id) {
            $task = app(TaskService::class)
                ->visibleQuery($user)
                ->select(['tasks.id', 'tasks.flow_job_id'])
                ->find((int) $notification->flow_task_id);

            if ($task && $access->can($user, 'jobs', 'view')) {
                $params = [
                    'open' => (int) $task->flow_job_id,
                    'task' => (int) $task->id,
                ];
                $commentId = $this->taskCommentId($notification, (int) $task->id);
                if ($commentId) $params['comment'] = 'task-'.$commentId;

                $url = route('jobs.index', $params);
                if ($commentId) $url .= '#task-comment-'.$commentId;

                return redirect()->to($url);
            }

            if ($task) {
                return redirect()
                    ->route('my-work', ['filter' => 'mentions'])
                    ->with('warning', 'This mention belongs to a task you can view, but your role cannot open Order details.');
            }
        }

        if ($notification->flow_job_id && $access->can($user, 'jobs', 'view')) {
            $job = app(JobService::class)
                ->visibleQuery($user)
                ->select(['flow_jobs.id'])
                ->find((int) $notification->flow_job_id);

            if ($job) {
                $params = ['open' => (int) $job->id];
                $activityId = $this->jobCommentActivityId($notification, (int) $job->id);
                if ($activityId) $params['comment'] = 'job-'.$activityId;

                $url = route('jobs.index', $params);
                if ($activityId) $url .= '#job-comment-'.$activityId;

                return redirect()->to($url);
            }
        }

        return redirect()
            ->route('notifications')
            ->with('warning', 'The comment or record linked to this notification is no longer available.');
    }

    private function taskCommentId(FlowNotification $notification, int $taskId): ?int
    {
        if (!in_array((string) $notification->type, ['mention', 'mention_admin', 'comment'], true)) return null;

        $query = FlowTaskComment::query()
            ->where('flow_task_id', $taskId)
            ->where('body', (string) $notification->message);

        if ($notification->created_at) {
            $query->where('created_at', '<=', $notification->created_at);
        }

        $id = $query->latest('created_at')->latest('id')->value('id');
        return $id ? (int) $id : null;
    }

    private function jobCommentActivityId(FlowNotification $notification, int $jobId): ?int
    {
        if (!in_array((string) $notification->type, ['mention', 'mention_admin', 'comment'], true)) return null;

        $query = Activity::query()
            ->where('subject_type', FlowJob::class)
            ->where('subject_id', $jobId)
            ->where('event', 'job.comment')
            ->where('description', (string) $notification->message);

        if ($notification->created_at) {
            $query->where('created_at', '<=', $notification->created_at);
        }

        $id = $query->latest('created_at')->latest('id')->value('id');
        return $id ? (int) $id : null;
    }
}
