from pathlib import Path
import io,json,tarfile,tempfile,unittest
import audit_package as a
SHA='a'*40
class AuditTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.root=Path(self.tmp.name)
  self.proof=self.root/'proof.json';self.proof.write_text(json.dumps({'source_sha':SHA}))
 def run_archive(self,name,content=b'hello',link=False):
  path=self.root/'a.tar'
  with tarfile.open(path,'w') as t:
   m=tarfile.TarInfo(name);m.size=len(content)
   if link:m.type=tarfile.SYMTYPE;m.linkname='/etc/passwd';m.size=0;t.addfile(m)
   else:t.addfile(m,io.BytesIO(content))
  return a.audit(path,self.proof,SHA)
 def test_clean_not_upload_approved(self):
  r=self.run_archive('public/index.html');self.assertEqual(r['state'],'screened_not_approved_for_upload');self.assertFalse(r['upload_allowed'])
 def test_secret_is_blocked_without_leak(self):
  secret=b'-----BEGIN PRIVATE KEY-----';r=self.run_archive('public/a.txt',secret)
  self.assertEqual(r['state'],'blocked_by_screening');self.assertNotIn(secret.decode(),json.dumps(r))
 def test_traversal(self):self.assertEqual(self.run_archive('../a')['counts']['unsafe_entries'],1)
 def test_symlink(self):self.assertEqual(self.run_archive('a',link=True)['counts']['unsafe_entries'],1)
 def test_env(self):self.assertEqual(self.run_archive('.env')['counts']['unsafe_entries'],1)
 def test_proof_mismatch(self):
  self.proof.write_text(json.dumps({'source_sha':'b'*40}))
  with self.assertRaisesRegex(ValueError,'proof_sha_mismatch'):self.run_archive('a')
if __name__=='__main__':unittest.main()
