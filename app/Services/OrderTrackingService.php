<?php

namespace App\Services;

use App\Models\Client;
use App\Models\FlowJob;
use App\Models\Invoice;
use App\Models\OrderHold;
use App\Models\OrderShipment;
use App\Models\Payment;
use App\Models\Task;
use App\Models\WorkflowPhase;
use App\Support\OrderStageResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class OrderTrackingService
{
    public const STAGES = [
        1 => ['key' => 'new', 'name' => 'New Order', 'color' => '#2563eb', 'short' => 'New Order'],
        2 => ['key' => 'art', 'name' => 'Artwork', 'color' => '#9333ea', 'short' => 'Artwork'],
        3 => ['key' => 'prod', 'name' => 'Production', 'color' => '#f97316', 'short' => 'Production'],
        4 => ['key' => 'qc', 'name' => 'QC', 'color' => '#059669', 'short' => 'QC'],
        5 => ['key' => 'ship', 'name' => 'Shipment', 'color' => '#0284c7', 'short' => 'Shipment'],
        6 => ['key' => 'bill', 'name' => 'Billing', 'color' => '#db2777', 'short' => 'Billing'],
        7 => ['key' => 'pay', 'name' => 'Payment', 'color' => '#10b981', 'short' => 'Payment'],
    ];

    /**
     * Look up an order by order/reference number and matching client/contact email.
     * Anti-enumeration: returns null if not matched.
     *
     * @return Collection<int, FlowJob>
     */
    public function lookup(string $identifier, string $email): Collection
    {
        $identifier = trim($identifier);
        $email = Str::lower(trim($email));

        if ($identifier === '' || $email === '') {
            return new Collection();
        }

        $identifierLower = Str::lower($identifier);

        return FlowJob::query()
            ->with(['client', 'phase', 'tasks', 'shipments', 'holds', 'invoices', 'payments', 'sourceInquiry'])
            ->where(function (Builder $query) use ($identifier, $identifierLower) {
                $query->whereRaw('LOWER(TRIM(job_number)) = ?', [$identifierLower])
                    ->orWhereRaw('LOWER(TRIM(order_number)) = ?', [$identifierLower])
                    ->orWhereRaw('LOWER(TRIM(repeat_order_number)) = ?', [$identifierLower])
                    ->orWhereRaw('LOWER(TRIM(source_row_id)) = ?', [$identifierLower])
                    ->orWhereHas('sourceInquiry', function (Builder $sq) use ($identifierLower) {
                        $sq->whereRaw('LOWER(TRIM(reference_number)) = ?', [$identifierLower])
                            ->orWhereRaw('LOWER(TRIM(inquiry_number)) = ?', [$identifierLower]);
                    })
                    ->orWhere('job_number', 'like', '%' . $identifier)
                    ->orWhere('order_number', 'like', '%' . $identifier);
            })
            ->whereHas('client', function (Builder $query) use ($email) {
                $query->whereRaw('LOWER(TRIM(email)) = ?', [$email])
                    ->orWhereHas('contacts', function (Builder $sub) use ($email) {
                        $sub->whereRaw('LOWER(TRIM(email)) = ?', [$email]);
                    });
            })
            ->latest('id')
            ->get();
    }

    /**
     * Find order by opaque tracking token.
     */
    public function findByToken(string $token): ?FlowJob
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        return FlowJob::query()
            ->with(['client', 'phase', 'tasks', 'shipments', 'holds', 'invoices', 'payments'])
            ->where('tracking_token', $token)
            ->first();
    }

    /**
     * Compile public redacted customer tracking data payload.
     *
     * @return array<string, mixed>
     */
    public function getCustomerTrackingData(FlowJob $job): array
    {
        // Ensure relationships are loaded
        $job->loadMissing(['client', 'phase', 'tasks', 'shipments', 'holds', 'invoices', 'payments', 'sourceInquiry']);

        $tasks = $job->tasks->keyBy('automation_key');
        $currentStageNumber = $this->determineCurrentStageNumber($job);
        $heroStatus = $this->resolveHeroStatus($job, $tasks, $currentStageNumber);
        $metrics = $this->resolveThreeMetrics($job, $tasks);
        $stepper = $this->buildStepper($job, $currentStageNumber);
        $timeline = $this->buildTimeline($job, $tasks);
        $shipmentInfo = $this->resolveShipmentInfo($job);
        $nextStep = $this->resolveNextStep($job, $tasks, $currentStageNumber, $heroStatus);
        $banner = $this->resolveBanner($job, $tasks, $currentStageNumber);

        $rawOrderNum = $job->job_number ?: $job->order_number;
        $orderNumber = $job->displayOrderNumber() ?: $rawOrderNum;
        $referenceNumber = $job->reference_number ?: $rawOrderNum;

        return [
            'order_number' => $orderNumber,
            'raw_order_number' => $rawOrderNum,
            'reference_number' => $referenceNumber,
            'last_updated' => $job->updated_at ? $job->updated_at->format('M d, Y, h:i A') : 'just now',
            'is_sample' => (bool) ($job->category === 'Sampling' || str_contains(Str::lower($job->title ?? ''), 'sample') || ($job->is_repeat_order === false && str_contains(Str::lower($job->description ?? ''), 'sample'))),
            'current_stage_number' => $currentStageNumber,
            'hero' => $heroStatus,
            'metrics' => $metrics,
            'stepper' => $stepper,
            'timeline' => $timeline,
            'shipment' => $shipmentInfo,
            'next_step' => $nextStep,
            'banner' => $banner,
        ];
    }

    /**
     * Resolve the active stage number (1..7).
     * Synchronized with FlowTrack's internal OrderStageResolver so internal Order list
     * and external Order tracking always match 100%.
     */
    public function determineCurrentStageNumber(FlowJob $job): int
    {
        if ($job->completed_at || strtolower((string) $job->status) === 'completed') {
            return 7;
        }

        $phaseId = (int) ($job->workflow_phase_id ?: 0);
        $phaseTasks = $job->tasks->filter(fn (Task $task) => (int) $task->workflow_phase_id === $phaseId)->values();
        $nextTask = $phaseTasks->first(fn (Task $task) => !in_array($task->status, ['Completed', 'Skipped'], true))
            ?: $phaseTasks->first();

        $automationKey = $nextTask?->setupTemplate?->automation_key 
            ?? $nextTask?->template?->automation_key 
            ?? $nextTask?->automation_key;

        $resolved = OrderStageResolver::resolve(
            $job->phase?->name,
            $job->phase?->short_name,
            $job->phase?->sequence,
            $job->status,
            $automationKey,
        );

        $stage = (int) ($resolved['sequence'] ?? 1);

        return ($stage >= 1 && $stage <= 7) ? $stage : 1;
    }

    /**
     * Resolve the hero status card.
     */
    protected function resolveHeroStatus(FlowJob $job, Collection $tasks, int $stage): array
    {
        $activeHold = $job->activeHold;
        if ($activeHold) {
            return [
                'status' => 'Production on hold',
                'badge' => 'On hold',
                'badge_color' => 'red',
                'message' => 'Production is temporarily on hold. We will share an update shortly.',
                'icon' => 'alert-circle',
            ];
        }

        // Check shipment events first if in stage >= 5
        if ($stage >= 5) {
            $shipment = $job->shipments->first();
            if ($shipment && $shipment->dispatched_at) {
                if ($shipment->delivered_at) {
                    return [
                        'status' => 'Delivered',
                        'badge' => 'Delivered',
                        'badge_color' => 'blue',
                        'message' => 'Your shipment has been delivered.',
                        'icon' => 'check-circle',
                    ];
                }
                return [
                    'status' => 'Dispatched',
                    'badge' => 'Dispatched',
                    'badge_color' => 'teal',
                    'message' => 'Your order has been handed to the courier.',
                    'icon' => 'truck',
                ];
            }
        }

        // Stage 7: Payment
        if ($stage === 7) {
            $totalInvoiced = (float) $job->invoices->sum('total_amount');
            $totalPaid = (float) $job->payments->sum('amount');
            if ($totalPaid > 0 && $totalPaid >= $totalInvoiced) {
                return [
                    'status' => 'Order completed',
                    'badge' => 'Completed',
                    'badge_color' => 'emerald',
                    'message' => 'Your order is delivered and payment is confirmed.',
                    'icon' => 'check-circle',
                ];
            }
            if ($totalPaid > 0) {
                return [
                    'status' => 'Partially paid',
                    'badge' => 'Partially paid',
                    'badge_color' => 'yellow',
                    'message' => 'A payment has been received. A balance remains.',
                    'icon' => 'credit-card',
                ];
            }
            return [
                'status' => 'Awaiting payment',
                'badge' => 'Awaiting payment',
                'badge_color' => 'blue',
                'message' => 'Payment is pending for your order.',
                'icon' => 'credit-card',
            ];
        }

        // Stage 6: Billing
        if ($stage === 6) {
            $latestInvoice = $job->invoices->first();
            if ($latestInvoice && $latestInvoice->sent_at) {
                return [
                    'status' => 'Invoice sent',
                    'badge' => 'Invoice sent',
                    'badge_color' => 'pink',
                    'message' => 'Your invoice has been sent to your registered email.',
                    'icon' => 'file-text',
                ];
            }
            return [
                'status' => 'Preparing invoice',
                'badge' => 'Preparing invoice',
                'badge_color' => 'pink',
                'message' => 'We are preparing your invoice.',
                'icon' => 'file-text',
            ];
        }

        // Stage 5: Shipment
        if ($stage === 5) {
            return [
                'status' => 'Ready for shipment',
                'badge' => 'Ready for shipment',
                'badge_color' => 'teal',
                'message' => 'Your order is ready to be handed to the courier.',
                'icon' => 'package',
            ];
        }

        // Stage 4: QC
        if ($stage === 4) {
            $qcCheck = $tasks->get('QC_CHECK');
            if ($qcCheck && $qcCheck->completed_at) {
                return [
                    'status' => 'Quality check passed',
                    'badge' => 'QC passed',
                    'badge_color' => 'emerald',
                    'message' => 'Your order has passed quality checking.',
                    'icon' => 'check-circle',
                ];
            }
            return [
                'status' => 'Quality check in progress',
                'badge' => 'In progress',
                'badge_color' => 'teal',
                'message' => 'We are checking the quality of your order.',
                'icon' => 'search',
            ];
        }

        // Stage 3: Production
        if ($stage === 3) {
            $prodStart = $tasks->get('PROD_START');
            if ($prodStart && $prodStart->completed_at) {
                return [
                    'status' => 'In production',
                    'badge' => 'In production',
                    'badge_color' => 'orange',
                    'message' => 'Your order is being made.',
                    'icon' => 'settings',
                ];
            }
            if ($job->estimated_delivery_date) {
                return [
                    'status' => 'Ready for production',
                    'badge' => 'Scheduled',
                    'badge_color' => 'purple',
                    'message' => 'Your order is scheduled for production.',
                    'icon' => 'clock',
                ];
            }
            return [
                'status' => 'Scheduling production',
                'badge' => 'Scheduling',
                'badge_color' => 'blue',
                'message' => 'We are confirming the production schedule.',
                'icon' => 'calendar',
            ];
        }

        // Stage 2: Artwork
        if ($stage === 2) {
            $erp = $tasks->get('ART_CLIENT_ERP_DECISION');
            if ($erp && $erp->completed_at) {
                return [
                    'status' => 'Artwork approved',
                    'badge' => 'Approved',
                    'badge_color' => 'emerald',
                    'message' => 'Your artwork is approved. We are preparing for production.',
                    'icon' => 'check-circle',
                ];
            }
            $review = $tasks->get('ART_INTERNAL_REVIEW');
            if ($review && $review->completed_at) {
                return [
                    'status' => 'Awaiting artwork approval',
                    'badge' => 'Approval needed',
                    'badge_color' => 'yellow',
                    'message' => 'Please review and approve your artwork.',
                    'icon' => 'alert-triangle',
                ];
            }
            return [
                'status' => 'Artwork in progress',
                'badge' => 'In progress',
                'badge_color' => 'purple',
                'message' => 'We are preparing your artwork.',
                'icon' => 'edit-3',
            ];
        }

        // Stage 1: New Order
        $sendPo = $tasks->get('NEW_SEND_PO_ARTWORK');
        if ($sendPo && $sendPo->completed_at) {
            return [
                'status' => 'Preparing artwork',
                'badge' => 'Preparing artwork',
                'badge_color' => 'purple',
                'message' => 'Your order is being sent to our artwork team.',
                'icon' => 'file-text',
            ];
        }
        $uploadPo = $tasks->get('NEW_UPLOAD_PO');
        if ($uploadPo && $uploadPo->completed_at) {
            return [
                'status' => 'Order being reviewed',
                'badge' => 'Reviewing',
                'badge_color' => 'blue',
                'message' => 'We are reviewing your order details.',
                'icon' => 'eye',
            ];
        }

        return [
            'status' => 'Order received',
            'badge' => 'Order received',
            'badge_color' => 'emerald',
            'message' => 'We have received your order.',
            'icon' => 'file-text',
        ];
    }

    /**
     * Resolve the three independent statuses (Delivery, Billing, Payment).
     */
    protected function resolveThreeMetrics(FlowJob $job, Collection $tasks): array
    {
        $canonicalStage = $this->determineCurrentStageNumber($job);

        // 1. Delivery Metric
        $deliveryStatus = 'Awaiting update';
        $shipment = $job->shipments->first();
        if ($shipment) {
            if ($shipment->delivered_at) {
                $deliveryStatus = 'Delivered';
            } elseif ($shipment->dispatched_at) {
                $deliveryStatus = 'Dispatched';
            } elseif ($shipment->tracking_number) {
                $deliveryStatus = 'With courier';
            } else {
                $deliveryStatus = 'Preparing shipment';
            }
        } elseif ($canonicalStage >= 5) {
            $deliveryStatus = 'With courier';
        }

        // 2. Billing Metric
        $billingStatus = 'Upcoming';
        $invoice = $job->invoices->first();
        if ($invoice) {
            if ($invoice->sent_at) {
                $billingStatus = 'Invoice sent';
            } else {
                $billingStatus = 'Invoice ready';
            }
        } elseif ($canonicalStage >= 6 || ($tasks->has('SHIP_PACKAGE') && $tasks['SHIP_PACKAGE']->completed_at)) {
            $billingStatus = 'Preparing invoice';
        }

        // 3. Payment Metric
        $paymentStatus = 'Upcoming';
        $totalInvoiced = (float) $job->invoices->sum('total_amount');
        $totalPaid = (float) $job->payments->sum('amount');
        if ($totalPaid > 0 && $totalPaid >= $totalInvoiced) {
            $paymentStatus = 'Paid';
        } elseif ($totalPaid > 0) {
            $paymentStatus = 'Partially paid';
        } elseif ($canonicalStage === 7 || $billingStatus === 'Invoice sent') {
            $paymentStatus = 'Awaiting payment';
        }

        return [
            'delivery' => [
                'label' => 'Delivery',
                'status' => $deliveryStatus,
                'icon' => 'truck',
            ],
            'billing' => [
                'label' => 'Billing',
                'status' => $billingStatus,
                'icon' => 'file-text',
            ],
            'payment' => [
                'label' => 'Payment',
                'status' => $paymentStatus,
                'icon' => 'credit-card',
            ],
        ];
    }

    /**
     * Build the 7-stage horizontal stepper array.
     */
    protected function buildStepper(FlowJob $job, int $currentStage): array
    {
        $stepper = [];
        foreach (self::STAGES as $number => $config) {
            $isCompleted = $number < $currentStage || ($number === 7 && (bool) $job->completed_at);
            $isCurrent = $number === $currentStage && ! $job->completed_at;

            $statusText = 'Upcoming';
            $stateClass = 'upcoming';

            if ($isCompleted) {
                $statusText = 'Completed';
                $stateClass = 'completed';
            } elseif ($isCurrent) {
                $statusText = 'Current · In progress';
                $stateClass = 'current';
            }

            $stepper[] = [
                'number' => $number,
                'key' => $config['key'],
                'name' => $config['name'],
                'color' => $config['color'],
                'state' => $stateClass,
                'status_text' => $statusText,
                'is_completed' => $isCompleted,
                'is_current' => $isCurrent,
            ];
        }

        return $stepper;
    }

    /**
     * Build clean, redacted milestone timeline.
     */
    protected function buildTimeline(FlowJob $job, Collection $tasks): array
    {
        $events = [];

        // Order received
        $events[] = [
            'title' => 'New Order',
            'description' => 'Your order has been received.',
            'timestamp' => $job->created_at,
        ];

        // Artwork approval
        $erp = $tasks->get('ART_CLIENT_ERP_DECISION');
        if ($erp && $erp->completed_at) {
            $events[] = [
                'title' => 'Artwork approved',
                'description' => 'Your artwork has been approved for production.',
                'timestamp' => $erp->completed_at,
            ];
        }

        // Production finished
        $prodFinish = $tasks->get('PROD_FINISH');
        if ($prodFinish && $prodFinish->completed_at) {
            $events[] = [
                'title' => 'Production completed',
                'description' => 'Your order has completed production.',
                'timestamp' => $prodFinish->completed_at,
            ];
        }

        // QC passed
        $qcApprove = $tasks->get('QC_APPROVE_SHIPMENT');
        if ($qcApprove && $qcApprove->completed_at) {
            $events[] = [
                'title' => 'Quality check passed',
                'description' => 'Items have passed quality inspection.',
                'timestamp' => $qcApprove->completed_at,
            ];
        }

        // Dispatched
        $shipment = $job->shipments->first();
        if ($shipment && $shipment->dispatched_at) {
            $events[] = [
                'title' => 'Dispatched',
                'description' => 'Your order has been handed to the courier.',
                'timestamp' => $shipment->dispatched_at,
            ];
        }

        // Delivered
        if ($shipment && $shipment->delivered_at) {
            $events[] = [
                'title' => 'Delivered',
                'description' => 'Your shipment has been delivered.',
                'timestamp' => $shipment->delivered_at,
            ];
        }

        // Sort descending (latest on top)
        return collect($events)
            ->filter(fn ($e) => ! empty($e['timestamp']))
            ->sortByDesc('timestamp')
            ->values()
            ->map(function ($e, $idx) {
                $ts = Carbon::parse($e['timestamp']);
                return [
                    'title' => $e['title'],
                    'description' => $e['description'],
                    'date' => $ts->format('M d, Y'),
                    'time' => $ts->format('h:i A'),
                    'is_latest' => $idx === 0,
                ];
            })
            ->all();
    }

    /**
     * Resolve courier shipment details.
     */
    protected function resolveShipmentInfo(FlowJob $job): ?array
    {
        $shipment = $job->shipments->first();
        if (! $shipment && $job->phase?->sequence < 5) {
            return null;
        }

        $courier = $shipment?->courier ?: 'FedEx';
        $trackingNumber = $shipment?->tracking_number ?: '-';
        $trackingUrl = $shipment?->tracking_url ?: ($trackingNumber !== '-' ? 'https://www.google.com/search?q=' . urlencode($courier . ' tracking ' . $trackingNumber) : null);

        return [
            'has_shipment' => (bool) $shipment,
            'courier' => $courier,
            'tracking_number' => $trackingNumber,
            'tracking_url' => $trackingUrl,
            'is_dispatched' => (bool) ($shipment && $shipment->dispatched_at),
        ];
    }

    /**
     * Resolve "What happens next?" or CTA card.
     */
    protected function resolveNextStep(FlowJob $job, Collection $tasks, int $stage, array $hero): array
    {
        // If artwork approval is pending, show actionable CTA
        $review = $tasks->get('ART_INTERNAL_REVIEW');
        $erp = $tasks->get('ART_CLIENT_ERP_DECISION');
        if ($stage === 2 && $review && $review->completed_at && (! $erp || ! $erp->completed_at)) {
            return [
                'type' => 'action',
                'title' => 'Your approval is needed',
                'description' => 'Please sign in to review your artwork and approve it so we can move to production.',
                'action_label' => 'Sign in to review artwork',
                'action_url' => route('login'),
                'icon' => 'alert-circle',
            ];
        }

        if ($stage === 3) {
            return [
                'type' => 'info',
                'title' => 'Next step: Quality check',
                'description' => 'Once production is complete, your order will be quality checked.',
                'subtext' => 'No action is needed. We will notify you when there is an update.',
                'action_label' => null,
                'action_url' => null,
                'icon' => 'search',
            ];
        }

        if ($stage === 4) {
            return [
                'type' => 'info',
                'title' => 'Next step: Shipment preparation',
                'description' => 'Your order will be dispatched once quality checking is complete.',
                'subtext' => 'No action is needed. We will update you once quality checking is complete.',
                'action_label' => null,
                'action_url' => null,
                'icon' => 'package',
            ];
        }

        if ($stage >= 5 && $job->completed_at) {
            return [
                'type' => 'completed',
                'title' => 'No further action is needed.',
                'description' => 'Your order is complete. Thank you for your business.',
                'subtext' => null,
                'action_label' => null,
                'action_url' => null,
                'icon' => 'check-circle',
            ];
        }

        return [
            'type' => 'info',
            'title' => 'What happens next?',
            'description' => 'No action is needed. We will update this page as your order progresses.',
            'subtext' => null,
            'action_label' => null,
            'action_url' => null,
            'icon' => 'info',
        ];
    }

    /**
     * Resolve bottom banner notice.
     */
    protected function resolveBanner(FlowJob $job, Collection $tasks, int $stage): ?array
    {
        if ($stage === 6 || ($tasks->has('SHIP_PACKAGE') && $tasks['SHIP_PACKAGE']->completed_at && ! $job->completed_at)) {
            return [
                'type' => 'billing',
                'title' => 'Billing in progress',
                'message' => 'We are preparing your invoice. No action is needed right now.',
            ];
        }

        return null;
    }
}
