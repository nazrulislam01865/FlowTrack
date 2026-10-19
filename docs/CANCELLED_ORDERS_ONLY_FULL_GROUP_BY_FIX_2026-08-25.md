# Cancelled Orders MySQL ONLY_FULL_GROUP_BY Fix

## Error

The Cancelled Orders page failed in `CancelledOrderService::metrics()` with MySQL error 1055 because the common-cancellation-reason metric grouped by a CASE expression that referenced `flow_jobs.cancellation_reason` while `ONLY_FULL_GROUP_BY` was enabled.

## File changed

`app/Services/CancelledOrderService.php`

## Previous code

```php
$reasonCase = $this->reasonCaseSql();
$reasonCounts = (clone $base)
    ->reorder()
    ->selectRaw($reasonCase.' as reason_key, COUNT(flow_jobs.id) as aggregate')
    ->groupByRaw($reasonCase)
    ->pluck('aggregate', 'reason_key');
```

## Updated code

```php
$reasonCounts = (clone $base)
    ->reorder()
    ->select('flow_jobs.cancellation_reason')
    ->pluck('flow_jobs.cancellation_reason')
    ->map(function ($reason): string {
        $plainReason = app(RichTextService::class)
            ->plainText((string) $reason);

        return $this->classifyReason($plainReason);
    })
    ->countBy();
```

This removes the strict-mode GROUP BY failure while keeping the same permission-scoped cancelled-order source and the same reason-classification rules.

No database migration is required.
