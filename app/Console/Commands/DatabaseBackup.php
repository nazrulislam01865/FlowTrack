<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

final class DatabaseBackup extends Command
{
    protected $signature = 'flowtrack:database:backup {--directory=} {--retain=}';
    protected $description = 'Create a consistent compressed MySQL/MariaDB backup with SHA-256 verification metadata';

    public function handle(): int
    {
        $connectionName = (string) config('database.default');
        $config = (array) config("database.connections.{$connectionName}");
        $driver = (string) ($config['driver'] ?? '');
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->error('FlowTrack production backup currently supports MySQL/MariaDB connections only.');
            return self::FAILURE;
        }

        $directory = (string) ($this->option('directory') ?: config('scalability.backup.directory'));
        if ($directory === '') {
            $this->error('Backup directory is not configured.');
            return self::FAILURE;
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            $this->error('Backup directory could not be created: '.$directory);
            return self::FAILURE;
        }

        $database = (string) ($config['database'] ?? '');
        if ($database === '') {
            $this->error('Database name is empty.');
            return self::FAILURE;
        }

        $stamp = now()->format('Ymd_His');
        $safeDatabase = Str::slug($database, '_') ?: 'flowtrack';
        $filename = "{$safeDatabase}_{$stamp}.sql.gz";
        $finalPath = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
        $partialPath = $finalPath.'.part';
        $gzip = gzopen($partialPath, 'wb9');
        if ($gzip === false) {
            $this->error('Unable to open backup output: '.$partialPath);
            return self::FAILURE;
        }

        $args = [
            (string) config('scalability.backup.dump_binary', 'mysqldump'),
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--hex-blob',
            '--default-character-set=utf8mb4',
            '--host='.(string) ($config['host'] ?? '127.0.0.1'),
            '--port='.(string) ($config['port'] ?? '3306'),
            '--user='.(string) ($config['username'] ?? ''),
            $database,
        ];

        $stderr = '';
        try {
            $process = new Process($args, base_path(), [
                'MYSQL_PWD' => (string) ($config['password'] ?? ''),
            ]);
            $process->setTimeout(null);
            $process->run(function (string $type, string $buffer) use ($gzip, &$stderr): void {
                if ($type === Process::OUT) {
                    gzwrite($gzip, $buffer);
                } else {
                    $stderr .= $buffer;
                }
            });
            gzclose($gzip);

            if (! $process->isSuccessful()) {
                @unlink($partialPath);
                $this->error('Database dump failed: '.trim($stderr ?: $process->getErrorOutput()));
                return self::FAILURE;
            }

            if (! @rename($partialPath, $finalPath)) {
                @unlink($partialPath);
                $this->error('Unable to finalize backup file.');
                return self::FAILURE;
            }

            $hash = hash_file('sha256', $finalPath);
            file_put_contents($finalPath.'.sha256', $hash.'  '.$filename.PHP_EOL, LOCK_EX);
            @chmod($finalPath, 0640);
            @chmod($finalPath.'.sha256', 0640);

            $this->purgeOldBackups($directory, max(1, (int) ($this->option('retain') ?: config('scalability.backup.retain_days', 14))));

            $this->info('Backup created: '.$finalPath);
            $this->line('SHA-256: '.$hash);
            return self::SUCCESS;
        } catch (Throwable $exception) {
            if (is_resource($gzip)) @gzclose($gzip);
            @unlink($partialPath);
            $this->error('Database backup failed: '.$exception->getMessage());
            return self::FAILURE;
        }
    }

    private function purgeOldBackups(string $directory, int $retainDays): void
    {
        $cutoff = time() - ($retainDays * 86400);
        foreach (glob(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.sql.gz') ?: [] as $path) {
            if ((int) @filemtime($path) < $cutoff) {
                @unlink($path);
                @unlink($path.'.sha256');
            }
        }
    }
}
