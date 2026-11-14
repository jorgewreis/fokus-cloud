#!/usr/bin/env python3
"""Synthetic OIDC challenge protocol; no activation and no production listener.

The state directory is a server-owned bootstrap argument, not request data. A
real deployment must additionally enforce a fixed trusted forced command, SSH
peer identity, GitHub environment protections and independent artifact binding.
"""
import argparse
import json
import os
import select
import signal
from contextlib import contextmanager
import sys
import time

import github_oidc as oidc
import oidc_challenge_store as store
import protocol_slots

MAX_REQUEST = 32768
REQUEST_TIMEOUT_SECONDS = 5
TOTAL_TIMEOUT_SECONDS = 30


@contextmanager
def total_deadline():
    """Bound a standalone Linux process, including work after frame reception."""
    if signal.getitimer(signal.ITIMER_REAL)[0] != 0:
        raise ValueError('existing_timer')
    previous = signal.getsignal(signal.SIGALRM)
    def expire(signum, frame):
        raise TimeoutError('operation_timeout')
    signal.signal(signal.SIGALRM, expire)
    signal.setitimer(signal.ITIMER_REAL, TOTAL_TIMEOUT_SECONDS)
    try:
        yield
    finally:
        signal.setitimer(signal.ITIMER_REAL, 0)
        signal.signal(signal.SIGALRM, previous)


def read_frame(fd):
    """Read one big-endian length-prefixed frame within one absolute deadline."""
    deadline = time.monotonic() + REQUEST_TIMEOUT_SECONDS
    def read_exact(size):
        chunks = []
        remaining = size
        while remaining:
            timeout = deadline - time.monotonic()
            if timeout <= 0 or not select.select([fd], [], [], timeout)[0]:
                raise ValueError('request_timeout')
            chunk = os.read(fd, remaining)
            if not chunk:
                raise ValueError('incomplete_request')
            chunks.append(chunk)
            remaining -= len(chunk)
        return b''.join(chunks)
    length = int.from_bytes(read_exact(4), 'big')
    if not 0 < length <= MAX_REQUEST:
        raise ValueError('invalid_request_length')
    return read_exact(length)


def run_supervised(operation):
    """Run one request in a child killed and reaped on the parent's deadline."""
    read_fd, write_fd = os.pipe()
    try:
        pid = os.fork()
    except BaseException:
        os.close(read_fd); os.close(write_fd)
        raise
    if pid == 0:
        os.close(read_fd)
        try:
            payload=json.dumps(operation(),sort_keys=True).encode()
            if len(payload)>4096: raise ValueError('oversized_response')
            if os.write(write_fd,payload)!=len(payload): raise ValueError('incomplete_response')
            os.close(write_fd)
            os._exit(0)
        except BaseException:
            os.close(write_fd)
            os._exit(1)
    os.close(write_fd)
    reaped=False
    try:
        chunks=[];total=0
        while True:
            chunk=os.read(read_fd,4097-total)
            if not chunk:break
            total+=len(chunk)
            if total>4096:raise ValueError('oversized_response')
            chunks.append(chunk)
        _,status=os.waitpid(pid,0);reaped=True
        if not os.WIFEXITED(status) or os.WEXITSTATUS(status)!=0:raise ValueError('worker_failed')
        result=json.loads(b''.join(chunks),object_pairs_hook=oidc.unique)
        if not isinstance(result,dict):raise ValueError('invalid_response')
        return result
    finally:
        os.close(read_fd)
        if not reaped:
            try: os.kill(pid,signal.SIGKILL)
            except ProcessLookupError: pass
            try: os.waitpid(pid,0)
            except ChildProcessError: pass


def handle(directory, raw):
    if not isinstance(raw, bytes) or len(raw) > MAX_REQUEST:
        raise ValueError('invalid_request')
    try:
        value = json.loads(raw, object_pairs_hook=oidc.unique)
    except (ValueError, UnicodeError) as error:
        raise ValueError('invalid_request') from error
    if not isinstance(value, dict):
        raise ValueError('invalid_request')
    kind = value.get('operation')
    common = {'operation', 'action', 'candidate', 'artifact'}
    expected = common if kind == 'challenge' else common | {'audience', 'token'} if kind == 'consume' else set()
    if not expected or set(value) != expected:
        raise ValueError('invalid_request')
    if kind == 'challenge':
        audience = store.challenge(directory, value['action'], value['candidate'], value['artifact'])
        return {'state': 'challenge_issued', 'audience': audience}
    result = store.consume(directory, value['audience'], value['token'], value['action'], value['candidate'], value['artifact'])
    return {'state': result['state'], 'action': result['action'], 'candidate': result['candidate'],
            'artifact': result['artifact'], 'run_id': result['run_id'], 'run_attempt': result['run_attempt']}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--state-dir', required=True)
    parser.add_argument('--slot-dir', required=True)
    args = parser.parse_args()
    try:
        with total_deadline():
            with protocol_slots.acquire(args.slot_dir):
                result = run_supervised(lambda: handle(args.state_dir, read_frame(sys.stdin.buffer.fileno())))
        print(json.dumps(result, sort_keys=True))
        return 0
    except Exception:
        # Never echo the request, token, server paths or exception details.
        print('{"state":"blocked","reason":"authorization_protocol_failed"}', file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
