"""Readiness regression cases; no application, database, or network required."""
import io
import unittest
from email.message import Message
from readiness import require_csrf_ready

class Response(io.BytesIO):
    def __init__(self, body, status=200, content_type='application/json'):
        super().__init__(body)
        self.status=status
        self.headers=Message()
        self.headers['Content-Type']=content_type

class ReadinessTests(unittest.TestCase):
    def test_real_route_contract_shape(self):
        # routes/web.php returns response()->json(['token' => csrf_token()]).
        self.assertTrue(require_csrf_ready(Response(b'{"token":"a0123456789abcdef0123456789abcdef01234567"}',content_type='application/json; charset=utf-8')))
    def test_html_200(self):
        with self.assertRaises(ValueError):require_csrf_ready(Response(b'<html>Error</html>',content_type='text/html'))
    def test_php_fatal_200(self):
        for content_type in ['text/html','application/json']:
            with self.subTest(content_type=content_type), self.assertRaises(ValueError):
                require_csrf_ready(Response(b'<br />Fatal error: Cannot redeclare function',content_type=content_type))
    def test_invalid_json(self):
        with self.assertRaises(ValueError):require_csrf_ready(Response(b'{"token":'))
    def test_missing_or_invalid_token(self):
        for body in [b'{}',b'[]',b'null',b'{"token":null}',b'{"token":42}',b'{"token":""}',b'{"token":"   "}']:
            with self.subTest(body=body), self.assertRaises(ValueError):require_csrf_ready(Response(body))
    def test_non_200(self):
        with self.assertRaises(ValueError):require_csrf_ready(Response(b'{"token":"valid"}',status=500))
    def test_oversize(self):
        with self.assertRaises(ValueError):require_csrf_ready(Response(b'{"token":"'+b'a'*4096+b'"}'))
    def test_invalid_utf8(self):
        with self.assertRaises(ValueError):require_csrf_ready(Response(b'{"token":"\xff"}'))

if __name__=='__main__':unittest.main(verbosity=2)
