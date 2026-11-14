import tempfile
import unittest
from pathlib import Path

from idempotency_suite_evidence import validate_selection


SHA = "a" * 40
REQUIRED = {"Tests\\Feature\\CompanyIdempotencyStoreTest::test_one": 2,
            "Tests\\Feature\\CompanyIdempotencyStoreTest::test_two": 1}
ONE = '<testcase class="Tests\\Feature\\CompanyIdempotencyStoreTest" name="test_one" assertions="2"/>'
TWO = '<testcase class="Tests\\Feature\\CompanyIdempotencyStoreTest" name="test_two" assertions="1"/>'


class IdempotencyEvidenceTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = Path(self.tmp.name) / "result.xml"

    def evidence(self, cases=ONE + TWO, **kwargs):
        self.path.write_text('<testsuites><testsuite failures="0" errors="0" skipped="0">'
                             + cases + '</testsuite></testsuites>', encoding="utf-8")
        args = dict(profile="sqlite", source_sha=SHA, observed_sha=SHA, required=REQUIRED)
        args.update(kwargs)
        return validate_selection(self.path, **args)

    def test_complete_nominal_selection_and_engine_are_recorded(self):
        result = self.evidence()
        self.assertEqual((result["profile"], result["cases"], result["assertions"]), ("sqlite", 2, 3))
        self.assertEqual(len(result["junit_sha256"]), 64)
        self.assertEqual(self.evidence(profile="mysql")["profile"], "mysql")

    def test_missing_duplicate_unexpected_or_weakened_case_fails(self):
        for cases in (ONE, ONE + ONE, ONE + TWO.replace('test_two', 'other'),
                      ONE.replace('assertions="2"', 'assertions="1"') + TWO):
            with self.subTest(cases=cases), self.assertRaises(ValueError):
                self.evidence(cases)

    def test_failures_errors_skips_and_missing_assertions_fail(self):
        for kind in ("failure", "error", "skipped"):
            case = TWO.replace('/>', '><' + kind + '/></testcase>')
            with self.subTest(kind=kind), self.assertRaises(ValueError):
                self.evidence(ONE + case)
        with self.assertRaises(ValueError):
            self.evidence(ONE + TWO.replace(' assertions="1"', ''))

    def test_mismatched_source_invalid_engine_and_manifest_fail(self):
        for options in (dict(profile="unknown"), dict(observed_sha="b" * 40),
                        dict(source_sha="short"), dict(required={}),
                        dict(required={"Test::case": 0})):
            with self.subTest(options=options), self.assertRaises(ValueError):
                self.evidence(**options)

    def test_suite_level_error_fails_even_without_failed_testcase(self):
        self.path.write_text('<testsuite errors="1">' + ONE + TWO + '</testsuite>', encoding="utf-8")
        with self.assertRaises(ValueError):
            validate_selection(self.path, profile="sqlite", source_sha=SHA,
                               observed_sha=SHA, required=REQUIRED)


if __name__ == "__main__":
    unittest.main()
