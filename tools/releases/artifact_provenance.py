#!/usr/bin/env python3
"""Independent local rebuild comparison against an exact validated commit.

This is evidence only: the validation workflow does not attest the artifact
hash, and an environment approval is not established by this comparison.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import stat
import sys
import tempfile
import time

sys.path.insert(0,str(Path(__file__).resolve().parents[2]/'deploy'))
import publication_gate
import derived_assets

EXECUTOR_ROOT = Path(__file__).resolve().parents[2]
MAX_ARTIFACT_BYTES = 576 * 1024 * 1024
HASH_TIMEOUT_SECONDS = 30
# Cleanup is deliberately bounded too.  A process in uninterruptible kernel I/O
# (for example a wedged NFS/FUSE mount) may not process SIGTERM or SIGKILL until
# the kernel returns.  We fail closed rather than letting that condition pin the
# caller forever; a daemon child is left for the OS/runtime to reap once it can.
HASH_WORKER_CLEANUP_SECONDS = 0.2


def require(condition, reason):
    if not condition: raise ValueError(reason)


def _stable_hash(fd):
    deadline=time.monotonic()+HASH_TIMEOUT_SECONDS
    before=os.fstat(fd)
    require(stat.S_ISREG(before.st_mode) and before.st_nlink==1 and 0<before.st_size<=MAX_ARTIFACT_BYTES,'invalid_artifact')
    h=hashlib.sha256();os.lseek(fd,0,os.SEEK_SET)
    remaining=before.st_size
    while remaining:
        require(time.monotonic()<deadline,'artifact_hash_timeout')
        chunk=os.read(fd,min(1024*1024,remaining))
        require(time.monotonic()<deadline,'artifact_hash_timeout')
        require(bool(chunk) and len(chunk)<=remaining,'artifact_changed')
        h.update(chunk);remaining-=len(chunk)
    require(time.monotonic()<deadline,'artifact_hash_timeout')
    extra=os.read(fd,1)
    require(time.monotonic()<deadline,'artifact_hash_timeout')
    require(not extra,'artifact_grew')
    after=os.fstat(fd)
    require(time.monotonic()<deadline,'artifact_hash_timeout')
    require((before.st_dev,before.st_ino,before.st_size,before.st_mtime_ns,before.st_ctime_ns)==
            (after.st_dev,after.st_ino,after.st_size,after.st_mtime_ns,after.st_ctime_ns),'artifact_changed')
    return h.hexdigest(),(after.st_dev,after.st_ino,after.st_size,after.st_mtime_ns,after.st_ctime_ns)


def _hash_worker(fd, result_fd):
    try:
        result=('ok',_stable_hash(fd))
    except ValueError as error:
        result=('error',str(error))
    except BaseException:
        result=('error','artifact_hash_failed')
    finally:
        try:
            os.write(result_fd,json.dumps(result,separators=(',',':')).encode('utf-8'))
        except BaseException:
            pass
        os.close(result_fd)
        os._exit(0)


def _stop_hash_worker(pid):
    """Best-effort bounded cleanup for an untracked fork child.

    A child stuck in uninterruptible kernel I/O may ignore both signals.  It is
    intentionally created with :func:`os.fork`, not ``multiprocessing.Process``:
    the latter registers active children and Python's atexit hook performs an
    unbounded join.  This function never waits past its cleanup window.  A
    surviving D-state child is reparented when this CLI exits and must be dealt
    with by host monitoring; the artifact is already rejected.
    """
    for sig in (signal.SIGTERM,signal.SIGKILL):
        try:
            os.kill(pid,sig)
        except ProcessLookupError:
            return
        deadline=time.monotonic()+HASH_WORKER_CLEANUP_SECONDS
        while time.monotonic()<deadline:
            child,_=os.waitpid(pid,os.WNOHANG)
            if child==pid:
                return
            time.sleep(min(0.01,max(0,deadline-time.monotonic())))


def stable_hash(fd):
    """Hash in a killable POSIX worker so a blocked read cannot outlive its deadline.

    This evidence path is intentionally Linux/POSIX-only.  The CI contract runs
    on Ubuntu; Windows has no equivalent fork/kill supervision here and must
    fail closed instead of silently using the unsupervised primitive.
    """
    if os.name == 'nt':
        raise ValueError('artifact_hash_linux_required')
    received,sent=os.pipe()
    worker=os.fork()
    if worker==0:
        os.close(received)
        _hash_worker(fd,sent)
    os.close(sent)
    reader=os.fdopen(received,'rb',buffering=0)
    completed=False
    cleanup_started=False
    try:
        deadline=time.monotonic()+HASH_TIMEOUT_SECONDS
        while time.monotonic()<deadline:
            child,status=os.waitpid(worker,os.WNOHANG)
            if child==worker:
                completed=True
                require(os.WIFEXITED(status) and os.WEXITSTATUS(status)==0,'artifact_hash_failed')
                raw=reader.read(4096)
                require(bool(raw),'artifact_hash_failed')
                state,payload=json.loads(raw.decode('utf-8'))
                require(state=='ok',payload)
                require(isinstance(payload,list) and len(payload)==2,'artifact_hash_failed')
                return payload[0],tuple(payload[1])
            time.sleep(min(0.01,max(0,deadline-time.monotonic())))
        cleanup_started=True
        _stop_hash_worker(worker)
        raise ValueError('artifact_hash_timeout')
    finally:
        reader.close()
        if not completed and not cleanup_started:
            _stop_hash_worker(worker)


def compare(source, artifact, environment, *, expected_repository, expected_repository_id):
    """Rebuild from clean executor SHA and compare immutable archive bytes."""
    source=Path(source).resolve(strict=True)
    require(source==EXECUTOR_ROOT,'executor_source_mismatch')
    revision,_,_=derived_assets.source_snapshot(source)
    context=publication_gate.context(environment,revision)
    require(context.repository==expected_repository and str(context.repository_id)==expected_repository_id,'repository_policy_mismatch')
    api=publication_gate.GitHub(context,environment.get('GH_TOKEN',''))
    validation=publication_gate.verify(context,api)
    artifact=Path(artifact).absolute()
    require(artifact==artifact.resolve(strict=True) and not artifact.is_relative_to(source),'external_artifact_required')
    initial=artifact.lstat()
    require(stat.S_ISREG(initial.st_mode) and initial.st_nlink==1,'invalid_artifact')
    fd=os.open(artifact,os.O_RDONLY|os.O_NOFOLLOW|getattr(os,'O_NONBLOCK',0))
    try:
        opened=os.fstat(fd)
        require((opened.st_dev,opened.st_ino)==(initial.st_dev,initial.st_ino),'artifact_rebound')
        digest,identity=stable_hash(fd)
        info=artifact.lstat()
        require(stat.S_ISREG(info.st_mode) and (info.st_dev,info.st_ino)==identity[:2],'artifact_rebound')
        with tempfile.TemporaryDirectory(prefix='fokus-independent-proof-') as directory:
            temp=Path(directory);candidate=temp/'candidate';proof_path=temp/'proof.json';rebuilt=temp/'rebuilt.tar'
            proof=derived_assets.build(source,candidate)
            require(proof.get('source_sha')==context.sha and proof.get('mode')=='complete-runtime','build_identity')
            with proof_path.open('xb') as out:out.write(derived_assets.encoded(proof))
            result=derived_assets.package(source,candidate,proof_path,rebuilt)
            require(result.get('source_sha')==context.sha and re.fullmatch(r'[a-f0-9]{64}',result.get('artifact_sha256','')),'package_identity')
            rebuilt_fd=os.open(rebuilt,os.O_RDONLY|os.O_NOFOLLOW)
            try:rebuilt_digest,_=stable_hash(rebuilt_fd)
            finally:os.close(rebuilt_fd)
            require(rebuilt_digest==result['artifact_sha256'] and rebuilt_digest==digest,'independent_artifact_mismatch')
        info=artifact.lstat()
        require(stat.S_ISREG(info.st_mode) and (info.st_dev,info.st_ino)==identity[:2],'artifact_rebound')
        require(stable_hash(fd)==(digest,identity),'artifact_changed')
        require(derived_assets.source_snapshot(source)[0]==context.sha,'source_changed')
        require(publication_gate.verify(context,api)==validation,'validation_changed')
        return {'state':'independent_rebuild_match_not_authorized','source_sha':context.sha,
                'artifact_sha256':digest,'validation_run':validation[0],'validation_attempt':validation[1]}
    finally:
        os.close(fd)
