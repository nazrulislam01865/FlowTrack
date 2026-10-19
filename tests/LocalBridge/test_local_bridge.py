"""Run with: python3 -m unittest discover -s tests/LocalBridge -v"""
import subprocess
import tempfile
import unittest
from pathlib import Path

CONFIG = Path(__file__).resolve().parents[2] / 'scripts' / 'local_bridge_config.py'


class LocalBridgeTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root = Path(self.tmp.name)
        self.buyer = root / 'buyer'
        self.portal = root / 'supplier'
        self.buyer.mkdir()
        self.portal.mkdir()
        (self.buyer / '.env').write_text('APP_ENV=local\nAPP_KEY=buyer-key\nDB_DATABASE=buyer_db\nSESSION_COOKIE=old_cookie\n')
        (self.portal / '.env').write_text('APP_ENV="local"\nAPP_KEY=portal-key\nDB_DATABASE=portal_db\nSESSION_COOKIE=old_cookie\n')

    def run_config(self, mode, expect=0):
        result = subprocess.run(['python3', str(CONFIG), mode, str(self.buyer), str(self.portal)],
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        self.assertEqual(result.returncode, expect, result.stderr)
        return result

    def test_configure_is_idempotent_and_keeps_database_and_keys(self):
        self.run_config('configure')
        buyer = (self.buyer / '.env').read_text()
        portal = (self.portal / '.env').read_text()
        self.assertIn('APP_KEY=buyer-key', buyer)
        self.assertIn('DB_DATABASE=buyer_db', buyer)
        self.assertIn('DB_DATABASE=portal_db', portal)
        self.assertIn('SESSION_COOKIE=supplierportal_local_session', portal)
        self.assertIn('FLOWTRACK_BUYER_URL=http://127.0.0.1:8000', portal)
        self.assertEqual(len(list(self.buyer.glob('.env.local-bridge-backup-*'))), 1)
        self.assertEqual(len(list(self.portal.glob('.env.local-bridge-backup-*'))), 1)
        self.run_config('check')
        self.run_config('configure')
        self.assertEqual(len(list(self.buyer.glob('.env.local-bridge-backup-*'))), 1)

    def test_reject_nonlocal_without_changes(self):
        file = self.portal / '.env'
        old = file.read_text().replace('APP_ENV="local"', 'APP_ENV=production')
        file.write_text(old)
        result = self.run_config('configure', expect=1)
        self.assertIn('refusing', result.stderr)
        self.assertEqual(file.read_text(), old)
        self.assertEqual(len(list(self.buyer.glob('.env.local-bridge-backup-*'))), 0)

    def test_reject_differing_secrets(self):
        self.run_config('configure')
        path = self.portal / '.env'
        import re
        path.write_text(re.sub(r'FLOWTRACK_SUPPLIER_BRIDGE_SECRET=[^\n]+',
                               'FLOWTRACK_SUPPLIER_BRIDGE_SECRET=wrong' * 1, path.read_text()))
        result = self.run_config('check', expect=1)
        self.assertIn('same FLOWTRACK_SUPPLIER_BRIDGE_SECRET', result.stderr)

    def test_missing_env_is_not_created(self):
        (self.portal / '.env').unlink()
        result = self.run_config('configure', expect=1)
        self.assertIn('Missing', result.stderr)
        self.assertFalse((self.portal / '.env').exists())


if __name__ == '__main__':
    unittest.main()
