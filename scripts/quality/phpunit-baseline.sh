#!/usr/bin/env bash
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
mkdir -p quality

TEST_FILES=$(find tests -type f -name '*.php' 2>/dev/null | wc -l | tr -d ' ')
STAMP=$(date -u +%Y-%m-%dT%H:%M:%SZ)
STATUS_FILE=quality/phpunit-baseline.json
LOG_FILE=quality/phpunit-baseline.log

write_status() {
  local status="$1"
  local exit_code="$2"
  local reason="$3"
  php -r '
    $payload = [
      "schema" => 1,
      "generated_at" => $argv[1],
      "status" => $argv[2],
      "exit_code" => (int) $argv[3],
      "test_files" => (int) $argv[4],
      "command" => "php artisan test --colors=never",
      "log_file" => $argv[5],
      "reason" => $argv[6],
      "classification_rule" => "Failures present before refactor are baseline failures; failures introduced after this approved snapshot are regressions.",
    ];
    file_put_contents($argv[7], json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
  ' "$STAMP" "$status" "$exit_code" "$TEST_FILES" "$LOG_FILE" "$reason" "$STATUS_FILE"
}

if [ ! -f vendor/autoload.php ]; then
  : > "$LOG_FILE"
  write_status "blocked" 2 "vendor/autoload.php is absent; install locked Composer dependencies before executing the suite."
  echo "PHPUnit baseline blocked: vendor/autoload.php is absent."
  exit 2
fi

set +e
php artisan test --colors=never 2>&1 | tee "$LOG_FILE"
code=${PIPESTATUS[0]}
set -e

if [ "$code" -eq 0 ]; then
  write_status "passed" 0 "Full PHP test suite passed on the Phase 0 snapshot."
else
  write_status "failed" "$code" "Full PHP test suite contains one or more failures; classify and document them before structural refactoring."
fi

exit "$code"
