"""Serve public assets and the Styles file routes used by Laravel, for browser QA."""
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import re
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[2]
routes = dict(re.findall(
    r"Route::get\('([^']+)', function \(\) \{\s*return response\(\)->file\(public_path\('(styles/[^']+)'\)\);",
    (ROOT / 'routes/web.php').read_text(),
))


class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(ROOT / 'public'), **kwargs)

    def translate_path(self, path):
        route = urlsplit(path).path
        return super().translate_path('/' + routes[route] if route in routes else path)


if __name__ == '__main__':
    ThreadingHTTPServer(('127.0.0.1', 8874), Handler).serve_forever()
