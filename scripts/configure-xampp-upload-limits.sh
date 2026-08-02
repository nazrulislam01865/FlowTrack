#!/usr/bin/env bash
set -Eeuo pipefail

PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
[[ -n "$PHP_BIN" && -x "$PHP_BIN" ]] || { echo "PHP was not found in PATH." >&2; exit 1; }

PHP_INI="$($PHP_BIN --ini | sed -n 's|^[[:space:]]*Loaded Configuration File:[[:space:]]*||p' | head -n 1)"
[[ -n "$PHP_INI" && "$PHP_INI" != "(none)" && -f "$PHP_INI" ]] || {
  echo "No loaded php.ini was found. Run: php --ini" >&2
  exit 1
}

BACKUP_PATH="${PHP_INI}.flowtrack-backup-$(date +%Y%m%d-%H%M%S)"
cp "$PHP_INI" "$BACKUP_PATH"

"$PHP_BIN" -r '
$path = $argv[1];
$settings = [
    "upload_max_filesize" => "25M",
    "post_max_size" => "30M",
    "max_file_uploads" => "20",
    "max_execution_time" => "120",
    "max_input_time" => "120",
];
$contents = file_get_contents($path);
if ($contents === false) {
    fwrite(STDERR, "Unable to read {$path}.\n");
    exit(1);
}
foreach ($settings as $key => $value) {
    $pattern = "/^[;[:space:]]*".preg_quote($key, "/")."[[:space:]]*=.*$/mi";
    $replacement = "{$key}={$value}";
    if (preg_match($pattern, $contents) === 1) {
        $contents = preg_replace($pattern, $replacement, $contents);
    } else {
        $contents .= "\n{$replacement}\n";
    }
}
if (file_put_contents($path, $contents, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to update {$path}. Check its permissions.\n");
    exit(1);
}
' "$PHP_INI"

echo "Updated: $PHP_INI"
echo "Backup:  $BACKUP_PATH"
echo "Restart XAMPP Apache, or stop and restart php artisan serve."
echo "Then run: php artisan flowtrack:doctor"
