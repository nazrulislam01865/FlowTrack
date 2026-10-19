#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

php scripts/quality/architecture-budget.php --write-baseline
node scripts/quality/bundle-baseline.mjs
php scripts/quality/performance-baseline.php || true
bash scripts/quality/php-lint.sh
bash scripts/quality/phpunit-baseline.sh || true

echo
cat <<'EOF'
Phase 0 static baseline captured.
Next environment-dependent gates:
  1. composer install --no-interaction --prefer-dist
  2. vendor/bin/pint --test
  3. php artisan test
  4. npm ci --ignore-scripts
  5. npm run build
  6. Capture approved visual baselines on a stable seeded environment.
EOF
