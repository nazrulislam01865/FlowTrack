<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

final class DatabaseRestore extends Command
{
    protected $signature = 'flowtrack:database:restore {backup : Absolute path or filename inside the configured backup directory} {--force : Required acknowledgement for destructive restore}';
    protected $description = 'Restore a verified FlowTrack MySQL/MariaDB backup';

    public function handle(): int
    {
        if (! $this->option('force')) {
            $this->error('Restore is destructive. Re-run with --force after placing the application in maintenance mode.');
            return self::FAILURE;
        }

        $path = (string) $this->argument('backup');
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = rtrim((string) config('scalability.backup.directory'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
        }
        $real = realpath($path);
        if ($real === false || ! is_file($real)) {
            $this->error('Backup file not found: '.$path);
            return self::FAILURE;
        }

        $checksumPath = $real.'.sha256';
        if (is_file($checksumPath)) {
            $expected = strtok(trim((string) file_get_contents($checksumPath)), " \t");
            $actual = hash_file('sha256', $real);
            if (! is_string($expected) || $expected === '' || ! hash_equals($expected, $actual)) {
                $this->error('Backup SHA-256 verification failed. Restore aborted.');
                return self::FAILURE;
            }
            $this->info('Backup SHA-256 verified.');
        } else {
            $this->warn('No .sha256 sidecar found; integrity cannot be independently verified.');
        }

        $connectionName = (string) config('database.default');
        $config = (array) config("database.connections.{$connectionName}");
        $driver = (string) ($config['driver'] ?? '');
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->error('FlowTrack restore currently supports MySQL/MariaDB connections only.');
            return self::FAILURE;
        }

        $input = str_ends_with($real, '.gz') ? gzopen($real, 'rb') : fopen($real, 'rb');
        if ($input === false) {
            $this->error('Backup could not be opened.');
            return self::FAILURE;
        }

        $args = [
            (string) config('scalability.backup.mysql_binary', 'mysql'),
            '--host='.(string) ($config['host'] ?? '127.0.0.1'),
            '--port='.(string) ($config['port'] ?? '3306'),
            '--user='.(string) ($config['username'] ?? ''),
            '--default-character-set=utf8mb4',
            (string) ($config['database'] ?? ''),
        ];

        try {
            $process = new Process($args, base_path(), [
                'MYSQL_PWD' => (string) ($config['password'] ?? ''),
            ]);
            $process->setTimeout(null);
            $process->setInput($input);
            $process->run();

            if (is_resource($input)) fclose($input);
            if (! $process->isSuccessful()) {
                $this->error('Restore failed: '.trim($process->getErrorOutput()));
                return self::FAILURE;
            }

            $this->info('Database restore completed successfully.');
            $this->comment('Run php artisan optimize:clear, migrate --force, and the application smoke tests before returning traffic.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            if (is_resource($input)) fclose($input);
            $this->error('Restore failed: '.$exception->getMessage());
            return self::FAILURE;
        }
    }
}
