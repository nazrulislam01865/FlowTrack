import importlib.util
from pathlib import Path
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / 'order-release-ab-compare.py'
spec = importlib.util.spec_from_file_location('abcompare', SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ReleaseABComparisonTests(unittest.TestCase):
    def sample(self, run, order, queries, action='loadWorkflowSection'):
        return {'kind': 'http_request', 'request_kind': 'livewire_update',
                'run_id': run, 'order_id': order, 'db_query_count': queries,
                'duration_ms': 110, 'db_time_ms': 16,
                'components': [{'component': 'jobs.order-workflow-section', 'methods': [action]}]}

    def test_mismatched_orders_do_not_claim_improvement(self):
        events = [self.sample('before', 10, 99)] * 5 + [self.sample('after', 11, 20)] * 5
        report, complete = module.comparison(events, 'before', 'after')
        self.assertFalse(complete)
        self.assertIn('INCOMPLETE', report)
        self.assertIn('no adequately sampled', report)

    def test_same_order_with_five_samples_per_side_has_real_comparison(self):
        events = [self.sample('before', 10, 99)] * 5 + [self.sample('after', 10, 20)] * 5
        report, complete = module.comparison(events, 'before', 'after')
        self.assertTrue(complete)
        self.assertIn('99.00', report)
        self.assertIn('20.00', report)
        self.assertIn('MATCHED', report)

    def test_missing_after_run_is_not_silently_accepted(self):
        report, complete = module.comparison([self.sample('before', 10, 99)], 'before', 'after')
        self.assertFalse(complete)
        self.assertIn('NO COMPARABLE DATA', report)


if __name__ == '__main__':
    unittest.main()
