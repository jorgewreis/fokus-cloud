#!/usr/bin/env python3
"""Real npm builds, exact inventories and adversarial provenance checks. No VPS."""
import copy, importlib.util, json, os, shutil, subprocess, sys, tempfile, unittest
from pathlib import Path
from unittest.mock import patch
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
    def test_tracked_private_content_rejected(self):
        clone=Path(self.tmp.name)/'source';subprocess.run(['git','clone','--quiet','--no-hardlinks',str(SOURCE),str(clone)],check=True)
        (clone/'.env').write_text('SYNTHETIC_SECRET=not-real')
        subprocess.run(['git','-C',str(clone),'add','-f','.env'],check=True)
        subprocess.run(['git','-C',str(clone),'-c','user.name=Test','-c','user.email=test@example.invalid','commit','-qm','synthetic private fixture'],check=True)
        with self.assertRaisesRegex(ValueError,'private_or_mutable_source'):d.source_snapshot(clone)
    def test_candidate_changes_after_rebuild_before_package_snapshot(self):
        # Complementary interleaving injection; reference came from real builds above.
        artifact=Path(self.tmp.name)/'rejected.tar'
        def verified(*args):
            (self.candidate/'public/index.php').write_text('changed after verification')
            return self.reference
        with patch.object(d,'verified_build',verified):
            with self.assertRaisesRegex(ValueError,'candidate_changed_during_packaging'):
                d.package(SOURCE,self.candidate,self.proof,artifact)
        self.assertFalse(artifact.exists())

    def test_identity_and_extra_file_change_during_seal_rejected(self):
        # State-machine race injection supplements, never substitutes, real package/HTTP test.
        for mutation in ('identity','extra'):
            with self.subTest(mutation=mutation):
                folder=Path(self.tmp.name)/mutation;folder.mkdir();artifact=folder/'release.tar'
                with patch.object(d,'verified_build',return_value=self.reference):
                    result=d.package(SOURCE,self.candidate,self.proof,artifact)
                    c=d.contract(SOURCE);root=folder/self.reference['source_sha'];transport=folder/'transport.json'
                    c.unpack(artifact,result['artifact_sha256'],root,transport)
                    original=c.protect
                    def corrupt(path):
                        original(path)
                        directory=path/'.release';directory.chmod(0o700)
                        target=directory/('identity.php' if mutation=='identity' else 'unexpected.php')
                        if target.exists():target.chmod(0o600)
                        target.write_text('<?php return [];')
                        directory.chmod(0o500)
                    c.protect=corrupt
                    receipt=folder/'receipt.json'
                    try:
                        with patch.object(d,'contract',return_value=c):
                            with self.assertRaisesRegex(ValueError,'release_changed_during_seal'):
                                d.seal(SOURCE,self.candidate,self.proof,root,receipt,transport)
                        self.assertFalse(receipt.exists())
                    finally:
                        # Only discardable test fixtures regain permissions for cleanup.
                        for item in root.rglob('*'):
                            if not item.is_symlink():item.chmod(0o700 if item.is_dir() else 0o600)
                        root.chmod(0o700)

    def test_generator_cannot_rewrite_php_or_add_unexpected_public_file(self):
        # Exercise allowlist gate with already real-built bytes; command execution is injected.
        for name in ('public/index.php','public/unexpected.js'):
            with self.subTest(name=name):
                output=Path(self.tmp.name)/('out-'+Path(name).name)
                def generate(command,cwd,home):
                    shutil.copytree(self.a,cwd,dirs_exist_ok=True)
                    (cwd/name).write_text('unapproved generated bytes')
                with patch.object(d,'run',generate):
                    with self.assertRaisesRegex(ValueError,'build_modified_code|unexpected_build_output'):
                        d.build(SOURCE,output)
                self.assertFalse(output.exists())

    def test_incomplete_package_rejected(self):
        (self.candidate/'public/build/manifest.json').unlink()
        # Exercise the pre-packaging integrity gate with real-build authority; CLI also independently rebuilds.
        artifact=Path(self.tmp.name)/'rejected.tar'
        with self.assertRaises(ValueError):d.package(SOURCE,self.candidate,self.proof,artifact)
        self.assertFalse(artifact.exists())

if __name__=='__main__':unittest.main()
