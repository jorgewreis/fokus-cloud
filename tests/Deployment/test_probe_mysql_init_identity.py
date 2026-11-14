import unittest

from probe_mysql_init_identity import init_statement


class InitStatementTest(unittest.TestCase):
    def test_accepts_only_canonical_uuid(self):
        statement = init_statement('12345678-1234-1234-1234-123456789abc')
        self.assertIn("@@server_uuid = '12345678-1234-1234-1234-123456789abc'", statement)
        self.assertIn("JSON_EXTRACT('invalid json', '$')", statement)
        for identity in ('', "' OR 1=1 --", '12345678-1234-1234-1234-123456789ABC'):
            with self.subTest(identity=identity), self.assertRaises(ValueError):
                init_statement(identity)


if __name__ == '__main__':
    unittest.main()
