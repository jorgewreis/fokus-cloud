#!/usr/bin/env python3
"""Experimental server-owned one-shot OIDC challenge store; not wired to activation.

Policy and directory must be selected by a trusted server bootstrap, never by an
untrusted request. Operator/root, host clock and OS CA bundle remain trusted.
"""
from contextlib import contextmanager
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import stat
import time

import github_oidc as oidc
from preparation_guard import metadata


def require(condition, reason):
    if not condition: raise ValueError(reason)


def data(value): return json.dumps(value, sort_keys=True, separators=(',', ':')).encode()


def regular(fd, path, max_size=8192):
    info = metadata(path, os.geteuid(), -1, direct=True, regular=True)
    require(not info.st_mode & 0o077 and info.st_size <= max_size, 'unsafe_control_file')
    opened = os.fstat(fd)
    require((info.st_dev, info.st_ino) == (opened.st_dev, opened.st_ino), 'control_rebound')
    return opened


@contextmanager
def locked(directory):
    directory = Path(directory).absolute()
    require(directory == directory.resolve(), 'control_symlink')
    snapshots = {}
    for path in (directory, *directory.parents):
        info = metadata(path, os.geteuid(), -1, direct=path == directory)
        snapshots[path] = (info.st_dev, info.st_ino)
    require(not directory.stat().st_mode & 0o077, 'control_not_private')
    dfd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    lockfd = None
    try:
        opened = os.fstat(dfd)
        require((opened.st_dev, opened.st_ino) == snapshots[directory], 'control_rebound')
        lockfd = os.open('authorization.lock', os.O_RDWR | os.O_NOFOLLOW, dir_fd=dfd)
        info = regular(lockfd, directory / 'authorization.lock')
        fcntl.flock(lockfd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        def checkpoint():
            for path, identity in snapshots.items():
                current = metadata(path, os.geteuid(), -1, direct=path == directory)
                require((current.st_dev, current.st_ino) == identity, 'control_rebound')
            current = regular(lockfd, directory / 'authorization.lock')
            require((current.st_dev, current.st_ino) == (info.st_dev, info.st_ino), 'lock_rebound')
        checkpoint()
        yield dfd, checkpoint
        checkpoint()
    finally:
        if lockfd is not None: os.close(lockfd)
        os.close(dfd)


def read_record(directory, dfd, name):
    fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW, dir_fd=dfd)
    try:
        info = regular(fd, Path(directory) / name)
        raw = os.read(fd, info.st_size + 1)
        require(len(raw) == info.st_size, 'control_changed')
        value = json.loads(raw, object_pairs_hook=oidc.unique)
        require(isinstance(value, dict), 'invalid_control')
        return value, hashlib.sha256(raw).hexdigest()
    finally:
        os.close(fd)


def create_file(dfd, name, value):
    fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=dfd)
    with os.fdopen(fd, 'wb') as stream:
        stream.write(data(value)); stream.flush(); os.fsync(stream.fileno())
    os.fsync(dfd)


def policy(directory, dfd):
    value, digest = read_record(directory, dfd, 'policy.json')
    require(set(value) == {'repository', 'repository_id', 'workflow_sha'}, 'invalid_policy')
    require(isinstance(value['repository'], str) and re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', value['repository']), 'invalid_policy')
    require(isinstance(value['repository_id'], str) and re.fullmatch(r'[1-9][0-9]*', value['repository_id']), 'invalid_policy')
    require(isinstance(value['workflow_sha'], str) and re.fullmatch(r'[a-f0-9]{40}', value['workflow_sha']), 'invalid_policy')
    return value, digest


def environment_policy(directory, dfd):
    value, digest = read_record(directory, dfd, 'environment.json')
    require(set(value) == {'environment_id', 'reviewer_ids'}, 'invalid_environment_policy')
    reviewers = value['reviewer_ids']
    require(type(value['environment_id']) is int and value['environment_id'] > 0
            and isinstance(reviewers, list) and 0 < len(reviewers) <= 6
            and all(type(item) is int and item > 0 for item in reviewers)
            and len(set(reviewers)) == len(reviewers), 'invalid_environment_policy')
    return value, digest


def valid_binding(action, candidate, artifact):
    require(action in ('activate', 'rollback'), 'invalid_action')
    require(isinstance(candidate, str) and re.fullmatch(r'[a-f0-9]{40}', candidate), 'invalid_candidate')
    require(isinstance(artifact, str) and re.fullmatch(r'[a-f0-9]{64}', artifact), 'invalid_artifact')


MAX_CHALLENGES = 128
MAX_JTIS = 4096
RETENTION_GRACE = 300


def trusted_time(directory, dfd):
    """Durably reject a backward host clock before any expiry-based deletion."""
    now = int(time.time())
    try:
        fd = os.open('clock.json', os.O_RDWR | os.O_NOFOLLOW, dir_fd=dfd)
    except FileNotFoundError:
        fd = os.open('clock.json', os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=dfd)
        previous = now
    else:
        try:
            info = regular(fd, Path(directory) / 'clock.json')
            value = json.loads(os.read(fd, info.st_size + 1), object_pairs_hook=oidc.unique)
            require(isinstance(value, dict) and set(value) == {'time'} and type(value['time']) is int, 'invalid_clock')
            previous = value['time']
        except BaseException:
            os.close(fd)
            raise
    try:
        require(now >= previous, 'clock_moved_backwards')
        os.lseek(fd, 0, os.SEEK_SET)
        os.ftruncate(fd, 0)
        os.write(fd, data({'time': now}))
        os.fsync(fd); os.fsync(dfd)
        return now
    finally:
        os.close(fd)


def prune(directory, dfd, now):
    """Delete only validated, expired control records inside the private store."""
    entries = os.listdir(dfd)
    require(len(entries) <= MAX_CHALLENGES * 2 + MAX_JTIS + 8, 'store_entry_limit')
    challenge_names = [name for name in entries if re.fullmatch(r'challenge-[a-f0-9]{64}\.json', name)]
    consumed_names = [name for name in entries if re.fullmatch(r'consumed-[a-f0-9]{64}\.json', name)]
    jti_names = [name for name in entries if re.fullmatch(r'jti-[a-f0-9]{64}\.json', name)]
    allowed = set(challenge_names + consumed_names + jti_names + ['policy.json', 'environment.json', 'authorization.lock', 'clock.json'])
    require(set(entries) <= allowed, 'unexpected_store_entry')
    for name in challenge_names:
        record, _ = read_record(directory, dfd, name)
        require(type(record.get('expires')) is int, 'invalid_challenge')
        if record['expires'] + RETENTION_GRACE <= now:
            os.unlink(name, dir_fd=dfd)
    remaining = set(os.listdir(dfd))
    for name in consumed_names + jti_names:
        record, _ = read_record(directory, dfd, name)
        require(set(record) == {'state', 'retain_until'} and record['state'] == 'consumed'
                and type(record['retain_until']) is int, 'invalid_consumption_marker')
        challenge_name = 'challenge-' + name.removeprefix('consumed-')
        if record['retain_until'] <= now and (name.startswith('jti-') or challenge_name not in remaining):
            os.unlink(name, dir_fd=dfd)
    os.fsync(dfd)
    remaining = os.listdir(dfd)
    return (sum(name.startswith('challenge-') for name in remaining),
            sum(name.startswith('jti-') for name in remaining))


def validate_record(record, settings, policy_digest, environment_digest, now, action, candidate, artifact):
    require(set(record) == {'action', 'candidate', 'artifact', 'workflow_sha', 'policy_digest', 'environment_digest', 'created', 'expires'}, 'invalid_challenge')
    require(record['action'] == action and record['candidate'] == candidate and record['artifact'] == artifact
            and record['workflow_sha'] == settings['workflow_sha'] and record['policy_digest'] == policy_digest
            and record['environment_digest'] == environment_digest, 'challenge_binding')
    require(type(record['created']) is int and type(record['expires']) is int
            and record['created'] <= now < record['expires'] and record['expires'] - record['created'] == 120, 'challenge_expired')


def challenge(directory, action, candidate, artifact):
    valid_binding(action, candidate, artifact)
    with locked(directory) as (dfd, checkpoint):
        settings, policy_digest = policy(directory, dfd)
        _, environment_digest = environment_policy(directory, dfd)
        now = trusted_time(directory, dfd)
        challenge_count, _ = prune(directory, dfd, now)
        require(challenge_count < MAX_CHALLENGES, 'challenge_quota')
        nonce = secrets.token_hex(32)
        record = {'action': action, 'candidate': candidate, 'artifact': artifact,
                  'workflow_sha': settings['workflow_sha'], 'policy_digest': policy_digest, 'environment_digest': environment_digest,
                  'created': now, 'expires': now + 120}
        checkpoint()
        create_file(dfd, 'challenge-' + nonce + '.json', record)
        return 'urn:fokus:publication:' + nonce


def consume(directory, audience, token, action, candidate, artifact):
    valid_binding(action, candidate, artifact)
    require(isinstance(audience, str) and re.fullmatch(r'urn:fokus:publication:[a-f0-9]{64}', audience), 'invalid_challenge')
    nonce = audience.rsplit(':', 1)[-1]
    with locked(directory) as (dfd, checkpoint):
        settings, policy_digest = policy(directory, dfd)
        _, environment_digest = environment_policy(directory, dfd)
        record, record_digest = read_record(directory, dfd, 'challenge-' + nonce + '.json')
        now = trusted_time(directory, dfd)
        validate_record(record, settings, policy_digest, environment_digest, now, action, candidate, artifact)
        checkpoint()
    # Never hold the server lock while consulting the network or verifying a signature.
    keys = oidc.fetch_jwks()
    now = int(time.time())
    result = oidc.verify(token, keys, audience=audience, repository=settings['repository'],
                         repository_id=settings['repository_id'], sha=settings['workflow_sha'], now=now)
    with locked(directory) as (dfd, checkpoint):
        now = trusted_time(directory, dfd)
        current_settings, policy_after = policy(directory, dfd)
        _, environment_after = environment_policy(directory, dfd)
        current_record, record_after = read_record(directory, dfd, 'challenge-' + nonce + '.json')
        require(policy_after == policy_digest and environment_after == environment_digest and record_after == record_digest, 'authorization_changed')
        validate_record(current_record, current_settings, policy_after, environment_after, now, action, candidate, artifact)
        oidc.validate_window(result['issued'], result['not_before'], result['expires'], now)
        _, jti_count = prune(directory, dfd, now)
        require(jti_count < MAX_JTIS, 'jti_quota')
        checkpoint()
        try:
            os.stat('consumed-' + nonce + '.json', dir_fd=dfd, follow_symlinks=False)
        except FileNotFoundError:
            pass
        else:
            raise FileExistsError('challenge_already_consumed')
        jti_hash = hashlib.sha256(result['jti'].encode()).hexdigest()
        create_file(dfd, 'jti-' + jti_hash + '.json', {'state':'consumed','retain_until':result['expires'] + RETENTION_GRACE})
        # A pre-existing marker fails closed even if a previous operation crashed.
        create_file(dfd, 'consumed-' + nonce + '.json', {'state':'consumed','retain_until':record['expires'] + RETENTION_GRACE})
        final_time = trusted_time(directory, dfd)
        require(record['created'] <= final_time < record['expires'], 'authorization_expired')
        oidc.validate_window(result['issued'], result['not_before'], result['expires'], final_time)
        return {'state':'verified_once', 'action':action, 'candidate':candidate, 'artifact':artifact,
                'run_id':result['run_id'], 'run_attempt':result['run_attempt'],
                'identity':result['identity'], 'policy_digest':policy_digest, 'environment_digest':environment_digest}
