#!/usr/bin/env python3
"""Real npm builds, exact inventories and adversarial provenance checks. No VPS."""
import copy, importlib.util, json, os, shutil, subprocess, sys, tempfile, unittest
from pathlib import Path
spec=importlib.util.spec_from_file_location('derived',Path(__file__).with_name('derived_assets.py'));d=importlib.util.module_from_spec(spec);spec.loader.exec_module(d)
SOURCE=Path(os.environ.get('FOKUS_TEST_SOURCE',Path(__file__).resolve().parents[2]))

class RealBuildTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp=tempfile.TemporaryDirectory(prefix='rei25-tests-');cls.base=Path(cls.temp.name)
        cls.a=cls.base/'a';cls.b=cls.base/'b'
        cls.first=d.build(SOURCE,cls.a);cls.reference=d.build(SOURCE,cls.b)
        cls.evidence=cls.base/'proof.json';cls.evidence.write_bytes(d.encoded(cls.first))
    @classmethod
    def tearDownClass(cls): cls.temp.cleanup()
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(dir=self.base);self.addCleanup(self.tmp.cleanup)
        self.candidate=Path(self.tmp.name)/'candidate';shutil.copytree(self.a,self.candidate)
        self.proof=Path(self.tmp.name)/'proof.json';self.proof.write_bytes(self.evidence.read_bytes())
    def verify(self): return d.compare_reference(SOURCE,self.candidate,self.proof,self.reference)
    def test_01_real_builds_equivalent_and_complete(self):
        self.assertEqual(self.first,self.reference);self.verify()
        self.assertEqual(d.git(SOURCE,'status','--porcelain','--untracked-files=no'),b'')
        self.assertIn(d.LAW,self.reference['outputs']);self.assertIn('public/build/manifest.json',self.reference['outputs'])
    def test_missing_output(self):
        (self.candidate/d.LAW).unlink()
        with self.assertRaises(ValueError):self.verify()
    def test_additional_unmanifested_output(self):
        (self.candidate/'public/injected.js').write_text('evil')
        with self.assertRaises(ValueError):self.verify()
    def test_tamper_and_self_attest_manifest(self):
        p=self.candidate/'public/index.php';p.write_text('evil')
        proof=json.loads(self.proof.read_bytes());proof['outputs']['public/index.php']['sha256']=d.sha(p.read_bytes());proof['outputs']['public/index.php']['size']=4;self.proof.write_bytes(d.encoded(proof))
        with self.assertRaisesRegex(ValueError,'provenance_mismatch'):self.verify()
    def test_generator_binding(self):
        proof=json.loads(self.proof.read_bytes());proof['inputs']['tools/generate-fokus-law-assets.mjs']='0'*64;self.proof.write_bytes(d.encoded(proof))
        with self.assertRaises(ValueError):self.verify()
    def test_lock_binding(self):
        proof=json.loads(self.proof.read_bytes());proof['locks']['package-lock.json']='0'*64;self.proof.write_bytes(d.encoded(proof))
        with self.assertRaises(ValueError):self.verify()
    def test_wrong_destination(self):
        (self.candidate/d.LAW).rename(self.candidate/'config/wrong.json')
        with self.assertRaises(ValueError):self.verify()
    def test_incompatible_law_manifest(self):
        (self.candidate/d.LAW).write_text('{}')
        with self.assertRaises(ValueError):self.verify()
    def test_law_asset_mutation(self):
        law=json.loads((self.candidate/d.LAW).read_bytes());name=next(iter(law['assets'].values()))['path'];(self.candidate/'public'/name).write_text('bad')
        with self.assertRaises(ValueError):self.verify()
    def test_html_outside_cache_query(self):
        p=self.candidate/'public/styles/docs/forms/index.html';p.write_text(p.read_text()+'<script>evil</script>')
        with self.assertRaises(ValueError):self.verify()
    def test_symlink_output(self):
        (self.candidate/'public/unexpected').symlink_to('/tmp')
        with self.assertRaises(ValueError):self.verify()
    def test_source_change_rejected(self):
        # Separate clone, never mutate the canonical checkout or reset source changes.
        clone=Path(self.tmp.name)/'source';subprocess.run(['git','clone','--quiet','--no-hardlinks',str(SOURCE),str(clone)],check=True)
        (clone/'package.json').write_text('{}')
        with self.assertRaisesRegex(ValueError,'dirty_source'):d.source_snapshot(clone)
    def test_incomplete_package_rejected(self):
        (self.candidate/'public/build/manifest.json').unlink()
        # Exercise the pre-packaging integrity gate with real-build authority; CLI also independently rebuilds.
        artifact=Path(self.tmp.name)/'rejected.tar'
        with self.assertRaises(ValueError):d.package(SOURCE,self.candidate,self.proof,artifact)
        self.assertFalse(artifact.exists())

if __name__=='__main__':unittest.main()
