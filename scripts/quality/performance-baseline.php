#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = realpath(__DIR__ . '/../..');
$input = $argv[1] ?? ($root . '/storage/logs/laravel.log');
$output = $argv[2] ?? ($root . '/quality/performance-baseline.json');

if (!is_file($input)) {
    fwrite(STDERR, "Performance log not found: {$input}\n");
    exit(2);
}

$rows = [];
$handle = fopen($input, 'rb');
while (($line = fgets($handle)) !== false) {
    if (!str_contains($line, 'FlowTrack request performance') && !str_contains($line, 'FlowTrack failed request performance')) {
        continue;
    }
    if (!preg_match('/FlowTrack (?:failed )?request performance\s+(\{.*\})\s*$/', trim($line), $match)) {
        continue;
    }
    $payload = json_decode($match[1], true);
    if (!is_array($payload)) {
        continue;
    }
    $rows[] = $payload;
}
fclose($handle);

function percentile(array $values, float $p): ?float
{
    if ($values === []) return null;
    sort($values, SORT_NUMERIC);
    $index = (int) ceil($p * count($values)) - 1;
    return round((float) $values[max(0, min($index, count($values) - 1))], 2);
}

$byRoute = [];
foreach ($rows as $row) {
    $route = (string) ($row['route'] ?? 'unknown');
    $byRoute[$route][] = $row;
}

$routes = [];
foreach ($byRoute as $route => $items) {
    $durations = array_map(static fn ($r) => (float) ($r['duration_ms'] ?? 0), $items);
    $queryTimes = array_map(static fn ($r) => (float) ($r['query_time_ms'] ?? 0), $items);
    $queryCounts = array_map(static fn ($r) => (int) ($r['queries'] ?? 0), $items);
    $memory = array_map(static fn ($r) => (float) ($r['memory_peak_mb'] ?? 0), $items);
    $routes[$route] = [
        'samples' => count($items),
        'statuses' => array_count_values(array_map(static fn ($r) => (string) ($r['status'] ?? 'unknown'), $items)),
        'duration_ms' => [
            'p50' => percentile($durations, 0.50),
            'p95' => percentile($durations, 0.95),
            'max' => $durations === [] ? null : round(max($durations), 2),
        ],
        'queries' => [
            'p50' => percentile($queryCounts, 0.50),
            'p95' => percentile($queryCounts, 0.95),
            'max' => $queryCounts === [] ? null : max($queryCounts),
        ],
        'query_time_ms' => [
            'p50' => percentile($queryTimes, 0.50),
            'p95' => percentile($queryTimes, 0.95),
            'max' => $queryTimes === [] ? null : round(max($queryTimes), 2),
        ],
        'memory_peak_mb' => [
            'p95' => percentile($memory, 0.95),
            'max' => $memory === [] ? null : round(max($memory), 2),
        ],
        'cache' => [
            'hits' => array_sum(array_map(static fn ($r) => (int) ($r['cache_hits'] ?? 0), $items)),
            'misses' => array_sum(array_map(static fn ($r) => (int) ($r['cache_misses'] ?? 0), $items)),
            'writes' => array_sum(array_map(static fn ($r) => (int) ($r['cache_writes'] ?? 0), $items)),
            'forgets' => array_sum(array_map(static fn ($r) => (int) ($r['cache_forgets'] ?? 0), $items)),
        ],
    ];
}
ksort($routes);

$payload = [
    'schema' => 1,
    'generated_at' => gmdate('c'),
    'source_log' => str_replace($root . '/', '', realpath($input) ?: $input),
    'sample_count' => count($rows),
    'note' => 'This is a historical-log baseline. Re-capture with PERFORMANCE_LOG_ALL_REQUESTS=true on a stable representative dataset before approving performance gates.',
    'routes' => $routes,
];

file_put_contents($output, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
echo "Wrote " . str_replace($root . '/', '', $output) . " with " . count($rows) . " samples.\n";
