#!/usr/bin/env python3
"""Configure/verify localhost FlowTracker <-> Supplier Portal bridge (no Laravel writes)."""
import re
import secrets
import shutil
import sys
from datetime import datetime
from pathlib import Path

KEY_RE = re.compile(r'^\s*([A-Z][A-Z0-9_]*)\s*=')


def parse(path):
    if not path.is_file():
        raise ValueError(f'Missing {path}. Restore your existing local .env before continuing.')
    raw = path.read_text(encoding='utf-8')
    values = {}
    for line in raw.splitlines():
        match = KEY_RE.match(line)
        if match:
            value = line[match.end():].strip()
            if (value.startswith('"') and value.endswith('"')) or (value.startswith("'") and value.endswith("'")):
                value = value[1:-1]
            values[match.group(1)] = value
    return raw, values


def assert_local(path, values):
    if values.get('APP_ENV') != 'local':
        raise ValueError(f'{path} must explicitly contain APP_ENV=local; refusing to configure or launch a non-local environment.')


def update(path, raw, changes):
    lines = raw.splitlines(keepends=True)
    touched = set()
    result = []
    for line in lines:
        match = KEY_RE.match(line)
        key = match.group(1) if match else None
        if key in changes:
            if key in touched:
                raise ValueError(f'{path} has duplicate {key} keys. Fix the duplicate before continuing.')
            result.append(f'{key}={changes[key]}\n')
            touched.add(key)
        else:
            result.append(line)
    if any(key not in touched for key in changes):
        if result and not result[-1].endswith('\n'):
            result[-1] += '\n'
        for key, value in changes.items():
            if key not in touched:
                result.append(f'{key}={value}\n')
    new = ''.join(result)
    if new == raw:
        return False
    stamp = datetime.now().strftime('%Y%m%d-%H%M%S-%f')
    backup = path.with_name(f'.env.local-bridge-backup-{stamp}')
    shutil.copy2(path, backup)
    # write in place only after the preflight; keep existing .env permissions.
    path.write_text(new, encoding='utf-8')
    print(f'Updated {path} (backup: {backup.name})')
    return True


def check(buyer_values, portal_values):
    expected = [
        ('FlowTracker', buyer_values, 'FLOWTRACK_SUPPLIER_BRIDGE_ENABLED', 'true'),
        ('Supplier Portal', portal_values, 'FLOWTRACK_BRIDGE_ENABLED', 'true'),
        ('FlowTracker', buyer_values, 'FLOWTRACK_SUPPLIER_PORTAL_URL', 'http://127.0.0.1:8001'),
        ('Supplier Portal', portal_values, 'FLOWTRACK_BUYER_URL', 'http://127.0.0.1:8000'),
        ('FlowTracker', buyer_values, 'APP_URL', 'http://127.0.0.1:8000'),
        ('Supplier Portal', portal_values, 'APP_URL', 'http://127.0.0.1:8001'),
    ]
    errors = [f'{who}: {name} must be {wanted}' for who, vals, name, wanted in expected
              if vals.get(name, '').lower() != wanted.lower()]
    left = buyer_values.get('FLOWTRACK_SUPPLIER_BRIDGE_SECRET', '')
    right = portal_values.get('FLOWTRACK_SUPPLIER_BRIDGE_SECRET', '')
    if len(left) < 32 or not secrets.compare_digest(left, right):
        errors.append('The two applications must have the same FLOWTRACK_SUPPLIER_BRIDGE_SECRET of at least 32 characters.')
    if buyer_values.get('SESSION_COOKIE') == portal_values.get('SESSION_COOKIE'):
        errors.append('SESSION_COOKIE must differ across the applications on localhost.')
    if errors:
        raise ValueError('\n'.join(errors) + '\nRun the launcher with --configure to set localhost bridge variables safely.')


def main():
    if len(sys.argv) != 4 or sys.argv[1] not in ('configure', 'check'):
        raise ValueError('Usage: local_bridge_config.py configure|check FLOWTRACK_DIR SUPPLIER_DIR')
    mode = sys.argv[1]
    bpath = Path(sys.argv[2]).resolve() / '.env'
    ppath = Path(sys.argv[3]).resolve() / '.env'
    braw, buyer = parse(bpath)
    praw, portal = parse(ppath)
    assert_local(bpath, buyer)
    assert_local(ppath, portal)
    if mode == 'configure':
        # Preserve an existing local FlowTracker secret where possible, otherwise the portal secret.
        secret = buyer.get('FLOWTRACK_SUPPLIER_BRIDGE_SECRET', '')
        if len(secret) < 32:
            secret = portal.get('FLOWTRACK_SUPPLIER_BRIDGE_SECRET', '')
        if len(secret) < 32:
            secret = secrets.token_hex(32)
        # Reuse the app's existing Reverb credentials. Generate local-only
        # credentials only when explicitly running --configure.
        reverb_key = buyer.get('REVERB_APP_KEY', '') or secrets.token_hex(12)
        reverb_secret = buyer.get('REVERB_APP_SECRET', '')
        if len(reverb_secret) < 16:
            reverb_secret = secrets.token_hex(32)
        reverb_id = buyer.get('REVERB_APP_ID', '') or '2048'
        common = {
            'FLOWTRACK_SUPPLIER_BRIDGE_SECRET': secret,
            'SESSION_DOMAIN': 'null',
            'SESSION_SECURE_COOKIE': 'false',
            'SESSION_SAME_SITE': 'lax',
        }
        update(bpath, braw, common | {
            'APP_URL': 'http://127.0.0.1:8000',
            'FLOWTRACK_SUPPLIER_BRIDGE_ENABLED': 'true',
            'FLOWTRACK_SUPPLIER_PORTAL_URL': 'http://127.0.0.1:8001',
            'SESSION_COOKIE': 'flowtracker_local_session',
            'REVERB_APP_ID': reverb_id,
            'REVERB_APP_KEY': reverb_key,
            'REVERB_APP_SECRET': reverb_secret,
            'REVERB_ENABLED': 'true',
            'REVERB_HOST': '127.0.0.1',
            'REVERB_PORT': '8080',
            'REVERB_SCHEME': 'http',
            'REVERB_API_HOST': '127.0.0.1',
            'REVERB_API_PORT': '8080',
            'REVERB_API_SCHEME': 'http',
            'REVERB_SERVER_PORT': '8080',
            'REVERB_ALLOWED_ORIGINS': 'http://127.0.0.1:8000,http://127.0.0.1:8001',
        })
        update(ppath, praw, common | {
            'APP_URL': 'http://127.0.0.1:8001',
            'FLOWTRACK_BRIDGE_ENABLED': 'true',
            'FLOWTRACK_BUYER_URL': 'http://127.0.0.1:8000',
            'SESSION_COOKIE': 'supplierportal_local_session',
            'FLOWTRACK_REALTIME_KEY': reverb_key,
            'FLOWTRACK_REALTIME_HOST': '127.0.0.1',
            'FLOWTRACK_REALTIME_PORT': '8080',
            'FLOWTRACK_REALTIME_SCHEME': 'http',
        })
        _, buyer = parse(bpath)
        _, portal = parse(ppath)
    check(buyer, portal)
    print('Local signed bridge configuration verified (secret not printed).')
    print('Database settings and APP_KEY have not been modified.')
    if buyer.get('REVERB_APP_KEY') and buyer.get('REVERB_APP_SECRET'):
        if (buyer.get('REVERB_APP_KEY') == portal.get('FLOWTRACK_REALTIME_KEY')
                and len(buyer.get('REVERB_APP_SECRET', '')) >= 16):
            print('Supplier realtime Reverb public key matches (signing secret stays on FlowTracker).')
        else:
            print('Realtime channel credentials differ. Run --configure for local Reverb or align production settings.')


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError) as exc:
        print('Local bridge: ' + str(exc), file=sys.stderr)
        sys.exit(1)
