import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('travel', Path(__file__).parents[1] / 'tools/classify-travel.py')
travel = importlib.util.module_from_spec(spec)
spec.loader.exec_module(travel)


class TravelTests(unittest.TestCase):
    def test_conservative_boundaries(self):
        self.assertEqual(travel.classify([3000]), 'within_range')
        self.assertEqual(travel.classify([3001]), 'borderline')
        self.assertEqual(travel.classify([3600]), 'borderline')
        self.assertEqual(travel.classify([4200]), 'borderline')
        self.assertEqual(travel.classify([4201]), 'too_far')
        self.assertEqual(travel.classify([2700, 4800]), 'borderline')

    def test_missing_is_not_far(self):
        self.assertEqual(travel.classify([]), 'unverified')
        self.assertEqual(travel.classify([None]), 'unverified')
        self.assertEqual(travel.classify([100, None]), 'unverified')

    def test_german_names(self):
        self.assertEqual(travel.normalize('Dömitz'), 'domitz')
        self.assertEqual(travel.normalize('Groß Pankow'), 'gross pankow')


if __name__ == '__main__':
    unittest.main()
