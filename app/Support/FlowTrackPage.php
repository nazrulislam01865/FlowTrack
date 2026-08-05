<?php

namespace App\Support;

final class FlowTrackPage
{
    public const DASHBOARD = 'dashboard';

    /** @var array<string, string> */
    public const ROUTE_PAGE_MAP = [
        'flowtrack.view.dashboard' => 'dashboard',
        'flowtrack.view.jobs.index' => 'jobs',
        'flowtrack.view.jobs.create' => 'jobs',
        'flowtrack.view.jobs.edit' => 'jobs',
        'flowtrack.view.jobs.show' => 'jobs',
        'flowtrack.view.tasks.show' => 'board',
        'flowtrack.view.clients.index' => 'clients',
        'flowtrack.view.clients.create' => 'clients',
        'flowtrack.view.clients.edit' => 'clients',
        'flowtrack.view.clients.show' => 'clients',
        'flowtrack.view.board' => 'board',
        'flowtrack.view.mywork' => 'mywork',
        'flowtrack.view.documents' => 'documents',
        'flowtrack.view.reports' => 'reports',
        'flowtrack.view.notifications' => 'notifications',
        'flowtrack.view.workflows.index' => 'workflowsetup',
        'flowtrack.view.workflows.create' => 'workflowsetup',
        'flowtrack.view.workflows.edit' => 'workflowsetup',
        'flowtrack.view.task-packs.index' => 'taskpacksetup',
        'flowtrack.view.task-packs.create' => 'taskpacksetup',
        'flowtrack.view.task-packs.edit' => 'taskpacksetup',
        'flowtrack.view.master-data' => 'masterdata',
        'flowtrack.view.administration' => 'setup',
        'flowtrack.view.profile' => 'profile',
    ];

    /** @var array<string, string> */
    public const PATHS = [
        'dashboard' => '/flowtrack/view/dashboard',
        'mywork' => '/flowtrack/view/mywork',
        'jobs' => '/flowtrack/view/jobs',
        'clients' => '/flowtrack/view/clients',
        'board' => '/flowtrack/view/board',
        'documents' => '/flowtrack/view/documents',
        'reports' => '/flowtrack/view/reports',
        'notifications' => '/flowtrack/view/notifications',
        'workflowsetup' => '/flowtrack/view/workflowsetup',
        'taskpacksetup' => '/flowtrack/view/taskpacksetup',
        'masterdata' => '/flowtrack/view/masterdata',
        'setup' => '/flowtrack/view/setup',
        'profile' => '/flowtrack/view/profile',
    ];

    public static function fromRoute(?string $routeName): string
    {
        return self::ROUTE_PAGE_MAP[$routeName ?? ''] ?? self::DASHBOARD;
    }

    public static function path(string $page): string
    {
        return self::PATHS[$page] ?? self::PATHS[self::DASHBOARD];
    }

    /** @return array<string, string> */
    public static function paths(): array
    {
        return self::PATHS;
    }
}
