import hashlib
import json
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch
import retained_package as r

SHA='a'*40

class RetainedPackageTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.base=Path(self.tmp.name);self.source=self.base/'source';self.source.mkdir();self.output=self.base/'output'
        self.calls=[]
        def build(source,candidate):
            self.calls.append('build');candidate.mkdir();return {'source_sha':SHA}
        def package(source,candidate,evidence,artifact):
            self.calls.append('package');artifact.write_bytes(b'synthetic package');return {'source_sha':SHA}
        self.builder=SimpleNamespace(source_snapshot=lambda _: (SHA,None,None),build=build,package=package,encoded=lambda x:json.dumps(x).encode())
        spec=SimpleNamespace(loader=SimpleNamespace(exec_module=lambda _:None))
        for target,value in [('spec_from_file_location',spec),('module_from_spec',self.builder)]:
            p=patch.object(r.importlib.util,target,return_value=value);p.start();self.addCleanup(p.stop)
    def test_exact_outputs_and_digest_no_receipt(self):
        out=r.build_package(self.source,self.output,SHA)
        self.assertEqual(self.calls,['build','package'])
        self.assertEqual({p.name for p in self.output.iterdir()},{'package.tar','build-proof.json','package-summary.json'})
        self.assertEqual(out['artifact_sha256'],hashlib.sha256(b'synthetic package').hexdigest())
        self.assertEqual(out['state'],'packaged_not_deployed')
    def test_wrong_sha_creates_no_output(self):
        with self.assertRaisesRegex(ValueError,'requested_sha_mismatch'):r.build_package(self.source,self.output,'b'*40)
        self.assertFalse(self.output.exists());self.assertEqual(self.calls,[])
    def test_bad_sha(self):
        with self.assertRaisesRegex(ValueError,'full_sha_required'):r.build_package(self.source,self.output,'main')
    def test_no_overwrite_or_inside_source(self):
        for output in [self.source,self.source/'output']:
            with self.assertRaisesRegex(ValueError,'new_external_output_required'):r.build_package(self.source,output,SHA)
    def test_build_sha_mismatch(self):
        self.builder.build=lambda *_:{'source_sha':'b'*40}
        with self.assertRaisesRegex(ValueError,'build_sha_mismatch'):r.build_package(self.source,self.output,SHA)
        self.assertFalse((self.output/'package-summary.json').exists())
    def test_package_sha_mismatch(self):
        self.builder.package=lambda *_:{'source_sha':'b'*40}
        with self.assertRaisesRegex(ValueError,'package_sha_mismatch'):r.build_package(self.source,self.output,SHA)
        self.assertFalse((self.output/'package-summary.json').exists())
    def test_failed_rebuild_has_no_success_summary(self):
        def fail(*_):raise ValueError('provenance_mismatch')
        self.builder.package=fail
        with self.assertRaisesRegex(ValueError,'provenance_mismatch'):r.build_package(self.source,self.output,SHA)
        self.assertFalse((self.output/'package-summary.json').exists())

if __name__=='__main__':unittest.main()
