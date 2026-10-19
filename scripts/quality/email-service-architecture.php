<?php

$root = dirname(__DIR__, 2);
$failures = [];

$read = static fn (string $path): string => (string) file_get_contents($root.'/'.$path);
$contains = static function (string $path, string $needle) use ($read, &$failures): void {
    if (! str_contains($read($path), $needle)) {
        $failures[] = $path.' missing '.$needle;
    }
};

$contains('bootstrap/providers.php', 'EmailServiceProvider::class');
$contains('config/flowtrack_email.php', "'name' => env('FLOWTRACK_EMAIL_QUEUE', 'emails')");
$contains('deploy/flowtrack-workers-horizontal.conf.example', '--queue=emails');
$contains('scripts/queue-worker.sh', '--queue=realtime,notifications,emails,default');
$contains('app/Actions/Orders/EmailOrderInvoice.php', 'EmailService');
$contains('app/Livewire/Jobs/Concerns/ManagesOrderFinance.php', 'EmailService');

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app'));
foreach ($iterator as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') continue;

    $path = $file->getPathname();
    $relative = str_replace($root.'/', '', $path);
    $source = (string) file_get_contents($path);

    if (str_contains($source, 'Illuminate\\Support\\Facades\\Mail') || preg_match('/\bMail::(?:raw|html|send|to|queue)\b/', $source)) {
        $failures[] = $relative.' bypasses the central EmailService';
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Central email architecture FAIL\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Central email architecture PASS\n";
echo " - provider-neutral service and transport contract registered\n";
echo " - invoice/reminder paths migrated\n";
echo " - dedicated emails queue configured for single/horizontal workers\n";
echo " - feature modules contain no direct Laravel Mail facade usage\n";
