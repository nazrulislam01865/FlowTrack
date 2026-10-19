#!/usr/bin/env bash
set -euo pipefail

ROOT="${1:-.}"
ROOT="$(cd "$ROOT" && pwd)"
MARKER="FLOWTRACK_ORDER_LIST_CLIENT_PRODUCT_WIDTH_V2_2026_08_31"
PACKAGE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
CSS_SOURCE="$PACKAGE_DIR/resources/css/modules/orders/order-list-client-product-width-v2.css"
TARGET_CSS="$ROOT/resources/css/modules/orders/order-list-client-product-width-v2.css"
AFTER_CORE="$ROOT/resources/css/application/after-core.css"
MANIFEST="$ROOT/public/build/manifest.json"
IMPORT="@import '../modules/orders/order-list-client-product-width-v2.css';"

if [ ! -f "$CSS_SOURCE" ]; then
  echo "ERROR: package CSS not found: $CSS_SOURCE" >&2
  exit 1
fi
if [ ! -d "$ROOT/resources" ]; then
  echo "ERROR: $ROOT does not look like the FlowTrack project root." >&2
  exit 1
fi

mkdir -p "$(dirname "$TARGET_CSS")"
cp "$CSS_SOURCE" "$TARGET_CSS"

echo "[1/4] Source CSS installed: $TARGET_CSS"

if [ -f "$AFTER_CORE" ]; then
  if ! grep -Fq "$IMPORT" "$AFTER_CORE"; then
    printf '\n%s\n' "$IMPORT" >> "$AFTER_CORE"
  fi
  echo "[2/4] Source import ensured: $AFTER_CORE"
else
  echo "[2/4] after-core source entry not found; continuing with built asset patch."
fi

# Patch the active Vite CSS assets and update the manifest to a NEW filename.
# This avoids the exact failure mode where source CSS exists but production still
# serves an older fingerprinted public/build asset.
if [ -f "$MANIFEST" ]; then
  python3 - "$ROOT" "$MANIFEST" "$CSS_SOURCE" "$MARKER" <<'PY'
import json, pathlib, re, shutil, sys
root = pathlib.Path(sys.argv[1])
manifest_path = pathlib.Path(sys.argv[2])
css_source = pathlib.Path(sys.argv[3]).read_text()
marker = sys.argv[4]
manifest = json.loads(manifest_path.read_text())
changed = []

# Prefer the application CSS entries that FlowTrack uses. Patch both when present.
preferred = [
    'resources/css/application/after-core.css',
    'resources/css/app.css',
]
for key in preferred:
    entry = manifest.get(key)
    if not isinstance(entry, dict):
        continue
    rel = entry.get('file')
    if not rel or not str(rel).endswith('.css'):
        continue
    old_path = root / 'public' / 'build' / rel
    if not old_path.is_file():
        continue
    old_text = old_path.read_text(errors='ignore')
    if marker in old_text:
        changed.append((key, rel, 'already-patched'))
        continue
    stem = old_path.stem
    new_name = stem + '-order-width-v2.css'
    new_path = old_path.with_name(new_name)
    new_text = old_text.rstrip() + '\n\n' + css_source.rstrip() + '\n'
    new_path.write_text(new_text)
    entry['file'] = str(pathlib.PurePosixPath(rel).with_name(new_name))
    changed.append((key, entry['file'], 'patched'))

# Fallback: if neither preferred key existed, patch every CSS entry once.
if not changed:
    for key, entry in manifest.items():
        if not isinstance(entry, dict):
            continue
        rel = entry.get('file')
        if not rel or not str(rel).endswith('.css'):
            continue
        old_path = root / 'public' / 'build' / rel
        if not old_path.is_file():
            continue
        old_text = old_path.read_text(errors='ignore')
        if marker in old_text:
            changed.append((key, rel, 'already-patched'))
            continue
        new_name = old_path.stem + '-order-width-v2.css'
        new_path = old_path.with_name(new_name)
        new_path.write_text(old_text.rstrip() + '\n\n' + css_source.rstrip() + '\n')
        entry['file'] = str(pathlib.PurePosixPath(rel).with_name(new_name))
        changed.append((key, entry['file'], 'patched'))

if changed:
    backup = manifest_path.with_suffix('.json.order-width-v2.bak')
    if not backup.exists():
        shutil.copy2(manifest_path, backup)
    manifest_path.write_text(json.dumps(manifest, indent=2) + '\n')
    for row in changed:
        print('BUILT_ASSET', *row)
else:
    print('WARNING: no compiled CSS asset was found in manifest.json')
PY
  echo "[3/4] Active built CSS cache-busted through public/build/manifest.json"
else
  echo "[3/4] public/build/manifest.json not found; run npm run build after this script."
fi

# Clear Laravel caches when artisan/php are available. Failure here should not undo the CSS fix.
if [ -f "$ROOT/artisan" ] && command -v php >/dev/null 2>&1; then
  (cd "$ROOT" && php artisan optimize:clear >/dev/null 2>&1) || true
fi

echo "[4/4] Done. Hard-refresh the Orders page once (Ctrl/Cmd+Shift+R)."
echo "Marker: $MARKER"
