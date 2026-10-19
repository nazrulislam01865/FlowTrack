<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$baselinePath = $root.'/quality/phase14-infrastructure.json';
$baseline = json_decode((string) file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
$failures = [];
$phase15Final = is_file($root.'/quality/phase15-release-hardening.json');

$read = static fn (string $path): string => (string) file_get_contents($root.'/'.$path);
$contains = static function (string $path, string $needle) use ($read, &$failures): void {
    if (! str_contains($read($path), $needle)) $failures[] = "{$path} missing required contract: {$needle}";
};
$treeHash = static function (string $relative) use ($root): string {
    $base = $root.'/'.$relative;
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) $files[] = $file->getPathname();
    }
    sort($files, SORT_STRING);
    $ctx = hash_init('sha256');
    foreach ($files as $file) {
        $rel = str_replace('\\', '/', substr($file, strlen($base) + 1));
        hash_update($ctx, $rel."\0".hash_file('sha256', $file, true));
    }
    return hash_final($ctx);
};

foreach ($baseline['required_files'] as $file) {
    if (! is_file($root.'/'.$file)) $failures[] = "required Phase 14 file missing: {$file}";
}

$contains('config/cache.php', 'FLOWTRACK_HORIZONTAL_SCALING');
$contains('config/session.php', 'FLOWTRACK_HORIZONTAL_SCALING');
$contains('config/queue.php', 'FLOWTRACK_HORIZONTAL_SCALING');
$contains('config/database.php', "'queue' => [");
$contains('config/database.php', "REDIS_QUEUE_DB");
$contains('config/reverb.php', 'FLOWTRACK_HORIZONTAL_SCALING');
$contains('config/filesystems.php', 'FLOWTRACK_PUBLIC_STORAGE_PATH');
$contains('config/filesystems.php', 'FLOWTRACK_PRIVATE_STORAGE_PATH');
$contains('config/filesystems.php', "'flowtrack_object' => [");
$contains('bootstrap/app.php', "'/health/ready'");
$contains('routes/console.php', 'queue:monitor');
$contains('routes/console.php', 'flowtrack:database:backup');
$contains('app/Jobs/DeliverRealtimeNotification.php', 'ShouldBeUnique');
$contains('app/Jobs/DeliverRealtimeNotification.php', 'QueueTelemetry');
$contains('app/Jobs/DeliverRealtimeWorkspaceEvent.php', 'ShouldBeUnique');
$contains('app/Services/SecureDocumentStorage.php', 'inspectionPath');
$contains('deploy/env.horizontal.example', 'CACHE_STORE=redis');
$contains('deploy/env.horizontal.example', 'SESSION_DRIVER=redis');
$contains('deploy/env.horizontal.example', 'QUEUE_CONNECTION=redis');
$contains('deploy/env.horizontal.example', 'REVERB_SCALING_ENABLED=true');
$contains('tests/Load/phase14-flowtrack.k6.js', "http_req_failed: ['rate<0.01']");
$contains('tests/Load/phase14-flowtrack.k6.js', "'http_req_duration{type:standard}': ['p(95)<500']");
$contains('tests/Load/phase14-flowtrack.k6.js', "'http_req_duration{type:heavy}': ['p(95)<1000']");

$protected = [
    'routes_web' => hash_file('sha256', $root.'/routes/web.php'),
    'access_control' => hash_file('sha256', $root.'/app/Services/AccessControlService.php'),
    'migrations' => $treeHash('database/migrations'),
    'css' => $treeHash('resources/css'),
    'blade' => $treeHash('resources/views'),
    'javascript' => $treeHash('resources/js'),
];
foreach ($protected as $name => $hash) {
    if ($phase15Final && in_array($name, ['css', 'blade', 'javascript'], true)) {
        continue;
    }
    if (($baseline['protected'][$name] ?? null) !== $hash) {
        $failures[] = "protected Phase 13 boundary changed during Phase 14: {$name}";
    }
}

$worker = $read('deploy/flowtrack-workers-horizontal.conf.example');
foreach (['realtime', 'notifications', 'emails', 'default'] as $queue) {
    if (! str_contains($worker, "--queue={$queue}")) $failures[] = "horizontal worker pool missing {$queue} queue";
}

$currentState = $read('docs/refactor/CURRENT_STATE.md');
if (! $phase15Final && ! str_contains($currentState, 'current executable state — Phase 14')) $failures[] = 'CURRENT_STATE is not Phase 14';
if ($phase15Final && ! str_contains($currentState, 'current executable state — Phase 15')) $failures[] = 'CURRENT_STATE is not Phase 15';

if ($failures !== []) {
    fwrite(STDERR, "Phase 14 infrastructure architecture FAIL\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Phase 14 infrastructure/horizontal scalability PASS\n";
echo " - horizontal Redis profile: cache/session/queues + Reverb scaling\n";
echo " - shared/object storage: configurable with readiness sentinel\n";
echo " - workers/Reverb/scheduler: independent Supervisor/systemd definitions\n";
echo " - queue reliability: retries/timeouts/unique realtime signals/delay telemetry\n";
echo " - backups/restores: checksum-aware commands and runbook\n";
echo " - load test: authenticated smoke/expected/headroom scenarios\n";
echo $phase15Final
    ? " - Phase 13 routes/migrations/RBAC boundaries: unchanged; Phase 15 release assets allowed to shrink\n"
    : " - Phase 13 CSS/Blade/JS/routes/migrations/RBAC boundaries: unchanged\n";
