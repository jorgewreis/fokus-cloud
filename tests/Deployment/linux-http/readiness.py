"""Fail closed on PHP error pages, even when the server returns HTTP 200."""
import json


def require_csrf_ready(response):
    """Validate the real /api/csrf-token contract without logging its token."""
    if response.status != 200:
        raise ValueError('Readiness requires HTTP 200')
    if response.headers.get_content_type() != 'application/json':
        raise ValueError('Readiness requires application/json')
    raw = response.read(4097)
    if len(raw) > 4096:
        raise ValueError('Readiness response exceeds contract size')
    try:
        payload = json.loads(raw)
    except (ValueError, UnicodeError) as error:
        raise ValueError('Readiness requires valid JSON') from None
    if not isinstance(payload, dict) or not isinstance(payload.get('token'), str) or not payload['token'].strip():
        raise ValueError('Readiness requires a nonempty string token')
    return True
