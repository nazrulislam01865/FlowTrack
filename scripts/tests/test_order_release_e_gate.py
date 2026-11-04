"""No-database Release E gate correctness tests (including refusal to false-sign-off)."""
import importlib.util
from pathlib import Path
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / 'order-release-e-gate.py'
spec = importlib.util.spec_from_file_location('release_e_gate', SCRIPT)
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


class OrderReleaseEGateTests(unittest.TestCase):
    def sample(self, run, section, order_id=333, user=58, sql=20, db_ms=10, latency_ms=100, status=200):
        event = {
            'run_id': run, 'kind': 'http_request', 'order_id': order_id,
            'user_id': user, 'db_query_count': sql, 'db_time_ms': db_ms,
            'duration_ms': latency_ms, 'status': status,
        }
        if section == 'initial':
            event['request_kind'] = 'initial_get'
        else:
            event['request_kind'] = 'livewire_update'
            component, method = next((key, value[1]) for key, value in gate.METHODS.items() if value[0] == section)
            event['components'] = [{'component': component, 'methods': [method]}]
        return event

    def complete_manual(self):
        return {
            'reviewer': 'Staging reviewer', 'environment': 'isolated staging', 'tested_at': '2026-11-05',
            'checks': {key: {'passed': True, 'evidence': 'Verified on isolated staging'} for key in gate.MANUAL_GATES},
        }

    def full_events(self, before=20, after=15, user=58):
        return [self.sample(run, section, user=user, sql=sql, db_ms=10 if run == 'before' else 9,
                            latency_ms=100 if run == 'before' else 95)
                for run, sql in (('before', before), ('after', after))
                for section in gate.SECTIONS for _ in range(5)]

    def evaluate(self, events, manual=None):
        return gate.evaluate(events, 'before', 'after', [333], manual, min_orders=1)

    def test_complete_matched_same_user_samples_and_manual_evidence_can_pass(self):
        report, status = self.evaluate(self.full_events(), self.complete_manual())
        self.assertEqual(status, 'PASS')
        self.assertIn('Overall release gate: PASS', report)
        self.assertIn('SQL p50 B0', report)

    def test_no_manual_evidence_is_never_signed_off(self):
        report, status = self.evaluate(self.full_events())
        self.assertEqual(status, 'INCOMPLETE')
        self.assertIn('Manual/functional gate: INCOMPLETE', report)

    def test_three_representative_orders_required_for_full_release(self):
        report, status = gate.evaluate(self.full_events(), 'before', 'after', [333], self.complete_manual())
        self.assertEqual(status, 'INCOMPLETE')
        self.assertIn('require at least 3 distinct orders', report)

    def test_missing_after_samples_cannot_pass(self):
        events = [event for event in self.full_events() if event['run_id'] == 'before']
        report, status = self.evaluate(events, self.complete_manual())
        self.assertEqual(status, 'INCOMPLETE')
        self.assertIn('only 5 before / 0 after', report)

    def test_mixed_user_run_does_not_claim_performance_success(self):
        events = self.full_events()
        events.append(self.sample('before', 'workflow', user=1))
        report, status = self.evaluate(events, self.complete_manual())
        self.assertEqual(status, 'INCOMPLETE')
        self.assertIn('baseline users differ or are unknown', report)

    def test_sql_regression_blocks_release(self):
        report, status = self.evaluate(self.full_events(before=20, after=21), self.complete_manual())
        self.assertEqual(status, 'FAIL')
        self.assertIn('SQL p50 20.00 -> 21.00', report)

    def test_significant_latency_regression_blocks_release(self):
        events = self.full_events()
        for e in events:
            if e['run_id'] == 'after' and gate.identify_request(e) == 'workflow':
                e['duration_ms'] = 160
        report, status = self.evaluate(events, self.complete_manual())
        self.assertEqual(status, 'FAIL')
        self.assertIn('latency p95 100.00 -> 160.00 ms', report)

    def test_high_query_workflow_request_blocks_release_even_if_median_improves(self):
        events = self.full_events()
        for e in events:
            if e['run_id'] == 'after' and gate.identify_request(e) == 'workflow':
                e['db_query_count'] = 500
                break
        report, status = self.evaluate(events, self.complete_manual())
        self.assertEqual(status, 'FAIL')
        self.assertIn('exceeded 499 SQL', report)

    def test_failed_http_request_blocks_release(self):
        events = self.full_events()
        events.append(self.sample('after', 'initial', status=500))
        report, status = self.evaluate(events, self.complete_manual())
        self.assertEqual(status, 'FAIL')
        self.assertIn('Failed http_request event', report)

    def test_bundled_livewire_requests_are_not_misclassified_as_a_single_section(self):
        e = self.sample('after', 'workflow')
        e['components'].append({'component': 'jobs.index', 'methods': []})
        self.assertIsNone(gate.identify_request(e))
        self.assertIsNone(gate.identify_request({'kind': 'service_span', 'span': 'workflow_sync'}))

    def test_manual_attestation_requires_explicit_pass_and_evidence(self):
        manual = self.complete_manual()
        manual['checks']['permission_revocation'] = {'passed': None, 'evidence': ''}
        status, issues = gate.manual_result(manual)
        self.assertEqual(status, 'INCOMPLETE')
        self.assertTrue(any('permission_revocation' in v for v in issues))
        manual['checks']['permission_revocation'] = {'passed': False, 'evidence': 'Access not revoked'}
        status, issues = gate.manual_result(manual)
        self.assertEqual(status, 'FAIL')
        self.assertTrue(any('permission_revocation' in v for v in issues))

    def test_order_and_run_mismatch_are_not_combined(self):
        events = self.full_events()
        for e in events:
            if e['run_id'] == 'after':
                e['order_id'] = 334
        report, status = self.evaluate(events, self.complete_manual())
        self.assertEqual(status, 'INCOMPLETE')
        self.assertIn('only 5 before / 0 after', report)


if __name__ == '__main__':
    unittest.main()
