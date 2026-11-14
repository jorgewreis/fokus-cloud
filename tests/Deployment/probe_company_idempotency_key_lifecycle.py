"""In-memory model of a proposed key lifecycle, not production cryptography.

No environment, network, database or user data is accessed. Keys and actors
are generated synthetically and never printed. This cannot validate Laravel,
deployment configuration, durable revocation or MySQL transaction behavior.
"""

import base64
import hashlib
import hmac
import secrets
import unittest


TTL = 24 * 60 * 60
STATUS_GRACE = 7 * 24 * 60 * 60
SKEW = 5 * 60
SALT = hashlib.sha256(b'fokus/cloud/backoffice/company-idempotency/hkdf/v1').digest()
TOKEN_INFO = b'fokus/cloud/backoffice/company-idempotency/token/v1'
PAYLOAD_INFO = b'fokus/cloud/backoffice/company-idempotency/payload/v1'


def derive(master, purpose, salt=SALT):
    """RFC 5869 extract/expand for exactly one 32-byte output block."""
    prk = hmac.new(salt, master, hashlib.sha256).digest()
    return hmac.new(prk, purpose + b'\x01', hashlib.sha256).digest()


def encode(value):
    return base64.urlsafe_b64encode(value).rstrip(b'=').decode('ascii')


class Rejected(Exception):
    pass


class Unavailable(Exception):
    pass


class Model:
    def __init__(self):
        self.keys = {'k1': secrets.token_bytes(32)}
        self.active = 'k1'
        self.revoked_kids = set()  # Separately restored trusted registry.
        self.rows = {}

    def issue(self, actor, action, now):
        kid = self.active
        if kid not in self.keys or kid in self.revoked_kids:
            raise Unavailable('active key unavailable')
        fields = f'v1.{kid}.{now}.{now + TTL}.{secrets.token_hex(16)}'
        signature = self._sign(kid, fields, actor, action)
        token = f'{fields}.{signature}'
        self.rows[self._token_hash(token)] = {'actor': actor, 'action': action, 'revoked': False, 'request_mac': None, 'completed': False}
        return token

    def _sign(self, kid, fields, actor, action):
        message = f'{fields}\0{actor}\0{action}'.encode()
        return encode(hmac.new(derive(self.keys[kid], TOKEN_INFO), message, hashlib.sha256).digest())

    @staticmethod
    def _token_hash(token):
        return hashlib.sha256(token.encode()).hexdigest()

    def authenticate(self, token, actor, action, now, *, status=False, authorized=True):
        if not authorized:
            raise Rejected('unauthorized')
        parts = token.split('.')
        if len(parts) != 6 or len(token) > 512 or parts[0] != 'v1':
            raise Rejected('invalid token')
        _, kid, issued, expires, nonce, signature = parts
        if kid in self.revoked_kids:
            raise Rejected('revoked key version')
        if kid not in self.keys:
            raise Unavailable('verification key unavailable')
        if not issued.isascii() or not issued.isdigit() or not expires.isascii() or not expires.isdigit():
            raise Rejected('invalid time')
        issued, expires = int(issued), int(expires)
        if len(nonce) != 32 or any(char not in '0123456789abcdef' for char in nonce):
            raise Rejected('invalid nonce')
        if not hmac.compare_digest(signature, self._sign(kid, '.'.join(parts[:5]), actor, action)):
            raise Rejected('invalid signature')
        if expires - issued != TTL or issued > now + SKEW:
            raise Rejected('invalid lifetime')
        if now >= expires + (STATUS_GRACE if status else 0):
            raise Rejected('expired')
        row = self.rows.get(self._token_hash(token))
        if row is None:
            return None
        if row['actor'] != actor or row['action'] != action or row['revoked']:
            raise Rejected('revoked or different owner')
        return row

    def payload_mac(self, kid, canonical):
        if kid not in self.keys or kid in self.revoked_kids:
            raise Unavailable('payload key unavailable')
        return hmac.new(derive(self.keys[kid], PAYLOAD_INFO), canonical, hashlib.sha256).digest()

    def bind_and_complete(self, token, actor, action, now, canonical):
        row = self.authenticate(token, actor, action, now)
        if row is None:
            raise Rejected('not observed: do not mutate')
        mac = self.payload_mac(token.split('.')[1], canonical)
        if row['request_mac'] is not None and not hmac.compare_digest(row['request_mac'], mac):
            raise Rejected('different payload')
        if row['completed']:
            return 'replay'
        row['request_mac'] = mac
        row['completed'] = True  # Represents one atomic database commit only.
        return 'committed'


class KeyLifecycleProbe(unittest.TestCase):
    def setUp(self):
        self.model = Model()
        self.now = 1_800_000_000
        self.token = self.model.issue('admin-a', 'company-create', self.now)

    def test_domains_and_matching_replay(self):
        master = self.model.keys['k1']
        self.assertNotEqual(derive(master, TOKEN_INFO), derive(master, PAYLOAD_INFO))
        self.assertEqual(self.model.bind_and_complete(self.token, 'admin-a', 'company-create', self.now, b'synthetic'), 'committed')
        self.assertEqual(self.model.bind_and_complete(self.token, 'admin-a', 'company-create', self.now, b'synthetic'), 'replay')
        with self.assertRaises(Rejected):
            self.model.bind_and_complete(self.token, 'admin-a', 'company-create', self.now, b'different')

    def test_hkdf_sha256_rfc5869_vector_first_32_bytes(self):
        master = bytes.fromhex('0b' * 22)
        salt = bytes.fromhex('000102030405060708090a0b0c')
        info = bytes.fromhex('f0f1f2f3f4f5f6f7f8f9')
        expected = '3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf'
        self.assertEqual(derive(master, info, salt).hex(), expected)

    def test_actor_action_permission_and_tampering(self):
        for actor, action in [('admin-b', 'company-create'), ('admin-a', 'company-update')]:
            with self.assertRaises(Rejected):
                self.model.authenticate(self.token, actor, action, self.now)
        with self.assertRaises(Rejected):
            self.model.authenticate(self.token, 'admin-a', 'company-create', self.now, authorized=False)
        parts = self.token.split('.')
        parts[3] = str(int(parts[3]) + 1)
        with self.assertRaises(Rejected):
            self.model.authenticate('.'.join(parts), 'admin-a', 'company-create', self.now)

    def test_expiration_and_status_grace_even_after_record_cleanup(self):
        with self.assertRaises(Rejected):
            self.model.authenticate(self.token, 'admin-a', 'company-create', self.now + TTL)
        self.assertIsNotNone(self.model.authenticate(self.token, 'admin-a', 'company-create', self.now + TTL, status=True))
        self.model.rows.clear()
        self.assertIsNone(self.model.authenticate(self.token, 'admin-a', 'company-create', self.now + TTL, status=True))
        with self.assertRaises(Rejected):
            self.model.bind_and_complete(self.token, 'admin-a', 'company-create', self.now + TTL, b'synthetic')
        with self.assertRaises(Rejected):
            self.model.authenticate(self.token, 'admin-a', 'company-create', self.now + TTL + STATUS_GRACE, status=True)

    def test_rotation_uses_original_version_and_loss_fails_closed(self):
        self.model.keys['k2'] = secrets.token_bytes(32)
        self.model.active = 'k2'
        self.assertEqual(self.model.bind_and_complete(self.token, 'admin-a', 'company-create', self.now, b'synthetic'), 'committed')
        self.assertEqual(self.model.bind_and_complete(self.token, 'admin-a', 'company-create', self.now, b'synthetic'), 'replay')
        del self.model.keys['k1']
        with self.assertRaises(Unavailable):
            self.model.authenticate(self.token, 'admin-a', 'company-create', self.now)

    def test_token_and_version_revocations_survive_stale_key_restore(self):
        row = self.model.rows[self.model._token_hash(self.token)]
        row['revoked'] = True
        with self.assertRaises(Rejected):
            self.model.authenticate(self.token, 'admin-a', 'company-create', self.now)
        row['revoked'] = False  # A stale DB backup must not override the trusted registry.
        self.model.revoked_kids.add('k1')
        with self.assertRaises(Rejected):
            self.model.authenticate(self.token, 'admin-a', 'company-create', self.now)
        with self.assertRaises(Unavailable):
            self.model.issue('admin-a', 'company-create', self.now)

    def test_future_clock_and_missing_record_fail_closed(self):
        with self.assertRaises(Rejected):
            self.model.authenticate(self.token, 'admin-a', 'company-create', self.now - SKEW - 1)
        self.model.rows.clear()
        self.assertIsNone(self.model.authenticate(self.token, 'admin-a', 'company-create', self.now, status=True))
        with self.assertRaises(Rejected):
            self.model.bind_and_complete(self.token, 'admin-a', 'company-create', self.now, b'synthetic')


if __name__ == '__main__':
    unittest.main(verbosity=2)
