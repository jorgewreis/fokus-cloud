#!/usr/bin/env python3
"""Synthetic artifact provenance tests: actual bytes, fake rebuild and API."""
from pathlib import Path
import os
import tempfile
import threading
import unittest
from unittest.mock import patch
import artifact_provenance as provenance


class Evidence(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        base=Path(self.temp.name);self.source=base/'source';self.source.mkdir()
        self.artifact=base/'artifact.tar';self.artifact.write_bytes(b'synthetic deterministic archive')
        self.sha='a'*40
        self.environment={'GITHUB_EVENT_NAME':'workflow_dispatch','GITHUB_REF':'refs/heads/main',
            'EXPECTED_CANDIDATE':self.sha,'GITHUB_SHA':self.sha,'GITHUB_WORKFLOW_SHA':self.sha,
            'GITHUB_REPOSITORY':'example/project','GITHUB_REPOSITORY_ID':'123','GH_TOKEN':'synthetic'}
        self.patches=[patch.object(provenance,'EXECUTOR_ROOT',self.source),
                      patch.object(provenance.derived_assets,'source_snapshot',return_value=(self.sha,b'',{})),
                      patch.object(provenance.publication_gate,'GitHub',return_value=object()),
                      patch.object(provenance.publication_gate,'verify',return_value=(42,1)),
                      patch.object(provenance.os,'O_NOFOLLOW',getattr(provenance.os,'O_NOFOLLOW',0),create=True)]
        self.mocks=[p.start() for p in self.patches]
        for p in self.patches:self.addCleanup(p.stop)
        self.build_patch=patch.object(provenance.derived_assets,'build',side_effect=self.build)
        self.package_patch=patch.object(provenance.derived_assets,'package',side_effect=self.package)
        self.build_mock=self.build_patch.start();self.package_mock=self.package_patch.start()
        self.addCleanup(self.build_patch.stop);self.addCleanup(self.package_patch.stop)
    def build(self,source,candidate):
        candidate.mkdir()
        return {'source_sha':self.sha,'mode':'complete-runtime'}
    def package(self,source,candidate,proof,output):
        import hashlib
        value=b'synthetic deterministic archive'
        output.write_bytes(value)
        return {'source_sha':self.sha,'artifact_sha256':hashlib.sha256(value).hexdigest()}
    def compare(self):return provenance.compare(self.source,self.artifact,self.environment,expected_repository='example/project',expected_repository_id='123')
    def test_exact_bytes_and_validation_are_checked_twice(self):
        result=self.compare()
        self.assertEqual(result['state'],'independent_rebuild_match_not_authorized')
        self.assertEqual(result['source_sha'],self.sha)
        self.assertEqual(self.mocks[3].call_count,2)
    def test_changed_or_unapproved_workflow_is_rejected(self):
        self.mocks[3].side_effect=[(42,1),(43,1)]
        with self.assertRaisesRegex(ValueError,'validation_changed'):self.compare()
        self.environment['GITHUB_EVENT_NAME']='push'
        with self.assertRaises(provenance.publication_gate.GateError):self.compare()
    def test_untrusted_repository_context_is_rejected_before_network(self):
        self.environment['GITHUB_REPOSITORY']='attacker/project'
        with self.assertRaisesRegex(ValueError,'repository_policy_mismatch'):self.compare()
        self.mocks[2].assert_not_called()
        self.build_mock.assert_not_called()

    def test_tampered_artifact_and_wrong_rebuild_sha_are_rejected(self):
        self.artifact.write_bytes(b'tampered')
        with self.assertRaisesRegex(ValueError,'independent_artifact_mismatch'):self.compare()
        self.artifact.write_bytes(b'synthetic deterministic archive')
        self.build_mock.side_effect=lambda source,candidate:{'source_sha':'b'*40,'mode':'complete-runtime'}
        with self.assertRaisesRegex(ValueError,'build_identity'):self.compare()
    @unittest.skipUnless(hasattr(os,'mkfifo'), 'requires POSIX FIFO')
    def test_fifo_is_refused_before_open_or_build(self):
        self.artifact.unlink();os.mkfifo(self.artifact)
        with self.assertRaisesRegex(ValueError,'invalid_artifact'):self.compare()
        self.build_mock.assert_not_called()

    @unittest.skipIf(os.name == 'nt', 'Windows prevents replacing an open file')
    def test_artifact_path_replacement_during_rebuild_is_rejected(self):
        original=self.build
        def replace(source,candidate):
            result=original(source,candidate)
            replacement=self.artifact.with_name('replacement.tar')
            replacement.write_bytes(b'synthetic deterministic archive')
            replacement.replace(self.artifact)
            return result
        self.build_mock.side_effect=replace
        with self.assertRaisesRegex(ValueError,'artifact_rebound'):self.compare()

    def test_growth_during_hash_is_detected_with_bounded_reads(self):
        initial=self.artifact.stat().st_size
        fd=os.open(self.artifact,os.O_RDONLY)
        start=threading.Event();done=threading.Event();errors=[];requests=[]
        def append():
            try:
                if not start.wait(3):raise RuntimeError('writer was not signalled')
                with self.artifact.open('ab') as stream:stream.write(b'extra bytes')
            except Exception as error:errors.append(error)
            finally:done.set()
        thread=threading.Thread(target=append);thread.start()
        original_read=os.read
        def read(target,count):
            requests.append(count)
            if len(requests)==1:
                start.set()
                if not done.wait(3):raise RuntimeError('writer did not finish')
            return original_read(target,count)
        try:
            with patch.object(provenance.os,'read',side_effect=read):
                with self.assertRaisesRegex(ValueError,'artifact_grew'):
                    provenance.stable_hash(fd)
            self.assertFalse(errors)
            self.assertLessEqual(sum(requests),initial+1)
        finally:
            os.close(fd);thread.join(timeout=3)

    def test_unbounded_reader_cannot_exceed_initial_size_plus_probe(self):
        initial=self.artifact.stat().st_size;fd=os.open(self.artifact,os.O_RDONLY);requests=[]
        def endless(_fd,count):requests.append(count);return b'x'*count
        try:
            with patch.object(provenance.os,'read',side_effect=endless):
                with self.assertRaisesRegex(ValueError,'artifact_grew'):provenance.stable_hash(fd)
            self.assertEqual(sum(requests),initial+1)
        finally:os.close(fd)

    def test_hash_deadline_refuses_before_read(self):
        fd=os.open(self.artifact,os.O_RDONLY)
        try:
            with patch.object(provenance.time,'monotonic',side_effect=[0,31]), \
                 patch.object(provenance.os,'read') as read:
                with self.assertRaisesRegex(ValueError,'artifact_hash_timeout'):provenance.stable_hash(fd)
            read.assert_not_called()
        finally:os.close(fd)

    def test_artifact_mutation_during_rebuild_is_rejected(self):
        original=self.build
        def mutate(source,candidate):
            result=original(source,candidate)
            self.artifact.write_bytes(b'synthetic deterministic archivE')
            return result
        self.build_mock.side_effect=mutate
        with self.assertRaisesRegex(ValueError,'artifact_changed'):self.compare()


if __name__=='__main__':unittest.main()
