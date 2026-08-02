<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Models\User;

class FlowLandingPageResolver
{
    public function __construct(private readonly FlowAuthorizationService $authorization) {}

    /**
     * @return array{page:string,url:string}
     */
    public function resolve(User $user): array
    {
        $pages = $this->pages();
        $preferred = trim((string) $user->default_landing_page);

        if ($preferred !== '' && isset($pages[$preferred]) && $this->allowed($user, $preferred, $pages[$preferred])) {
            return ['page' => $preferred, 'url' => $pages[$preferred]['url']];
        }

        foreach ($pages as $page => $definition) {
            if ($this->allowed($user, $page, $definition)) {
                return ['page' => $page, 'url' => $definition['url']];
            }
        }

        // Notifications and Profile are authenticated utility surfaces rather
        // than Role Matrix modules. Keep a safe signed-in destination even for
        // a role that intentionally has no business-module page access.
        return ['page' => 'notifications', 'url' => '/flowtrack/view/notifications'];
    }

    /** @return array<string, array{url:string,module?:string,action?:string,special?:string}> */
    private function pages(): array
    {
        return [
            'dashboard' => ['url' => '/flowtrack/view/dashboard', 'module' => 'dashboard', 'action' => 'view'],
            'mywork' => ['url' => '/flowtrack/view/mywork', 'module' => 'tasks', 'action' => 'view'],
            'jobs' => ['url' => '/flowtrack/view/jobs', 'module' => 'jobs', 'action' => 'view'],
            'clients' => ['url' => '/flowtrack/view/clients', 'module' => 'clients', 'action' => 'view'],
            'board' => ['url' => '/flowtrack/view/board', 'special' => 'board'],
            'documents' => ['url' => '/flowtrack/view/documents', 'module' => 'documents', 'action' => 'view'],
            'reports' => ['url' => '/flowtrack/view/reports', 'module' => 'reports', 'action' => 'view'],
            'workflowsetup' => ['url' => '/flowtrack/view/workflowsetup', 'module' => 'workflow', 'action' => 'view'],
            'taskpacksetup' => ['url' => '/flowtrack/view/taskpacksetup', 'module' => 'workflow', 'action' => 'view'],
            'masterdata' => ['url' => '/flowtrack/view/masterdata', 'module' => 'masterdata', 'action' => 'view'],
            'setup' => ['url' => '/flowtrack/view/setup', 'module' => 'users', 'action' => 'view'],
        ];
    }

    /** @param array{url:string,module?:string,action?:string,special?:string} $definition */
    private function allowed(User $user, string $page, array $definition): bool
    {
        if (($definition['special'] ?? null) === 'board') {
            return $this->authorization->allows($user, 'jobs', 'view')
                || $this->authorization->allows($user, 'tasks', 'view');
        }

        return $this->authorization->allows(
            $user,
            (string) ($definition['module'] ?? $page),
            (string) ($definition['action'] ?? 'view'),
        );
    }
}
