<?php

namespace App\Services\Reminders;

/** Summary of all configured reminder rules; no extra database reads. */
final class ReminderOverview
{
    public static function summarize(array $templates, array $events, bool $orderEmailEnabled): array
    {
        $active = array_values(array_filter($templates, static fn (array $item): bool =>
            ! empty($item['active']) && ! empty($item['published'])
        ));
        $paused = count(array_filter($templates, static fn (array $item): bool =>
            empty($item['active']) && ! empty($item['published'])
        ));
        $draft = count($templates) - count($active) - $paused;
        $portalKey = OrderReminderEventCatalog::PORTAL_ACCESS_EVENT;
        $orderActive = count(array_filter($active, static fn (array $item): bool =>
            ($item['config']['event_key'] ?? '') !== $portalKey
        ));
        $blocked = ! $orderEmailEnabled ? $orderActive : 0;
        $types = [];
        foreach ($active as $item) {
            $config = $item['config'];
            $type = ($config['event_key'] ?? '') === $portalKey ? 'Supplier portal login' : match ($config['recipient_type'] ?? 'supplier') {
                'supplier' => 'Supplier', 'owner' => 'Order owner', 'coordinator' => 'Order coordinator',
                'assignee' => 'Task assignee', 'client' => 'Client', 'client_contact' => 'Client contact',
                'user' => 'Selected user', default => 'Other',
            };
            $types[$type] = true;
        }
        $names = array_keys($types);
        return [
            'eventCount' => count($events),
            'eventHelp' => 'Available configured workflow and portal events',
            'status' => count($active).' active',
            'statusHelp' => count($active).' published & active · '.$paused.' paused · '.$draft.' draft'.($blocked ? ' · '.$blocked.' order email rule(s) disabled globally' : ''),
            'recipients' => $names === [] ? 'None yet' : implode(', ', $names),
            'recipientHelp' => count($names).' recipient type(s) across active reminders',
        ];
    }
}
