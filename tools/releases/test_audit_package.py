from pathlib import Path
import io,json,tarfile,tempfile,unittest
from unittest.mock import patch
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
  secret=b'-----BEGIN '+b'PRIVATE KEY'+b'-----';r=self.run_archive('public/a.txt',secret)
  self.assertEqual(r['state'],'blocked_by_screening');self.assertNotIn(secret.decode(),json.dumps(r))
 def test_fixture_source_has_no_literal_private_key_marker(self):
  marker=b'-----BEGIN '+b'PRIVATE KEY'+b'-----'
  self.assertNotIn(marker,Path(__file__).read_bytes())
 def test_categories_reveal_neither_filename_nor_value(self):
  value=b'-----BEGIN '+b'PRIVATE KEY'+b'-----'
  name='untrusted-private-folder/customer-secret-name.txt'
  result=self.run_archive(name,value)
  self.assertEqual(result['categories'],{'other:private_key_marker':1})
  encoded=json.dumps(result)
  self.assertNotIn('customer-secret-name',encoded);self.assertNotIn('untrusted-private-folder',encoded)
  self.assertNotIn(value.decode(),encoded);self.assertFalse(result['upload_allowed'])
 def test_restricted_sql_category_only(self):
  result=self.run_archive('docs/private-client-name.sql',b'not printed')
  self.assertEqual(result['categories'],{'docs:restricted_sql':1})
  self.assertEqual(result['state'],'blocked_by_screening')
  self.assertNotIn('private-client-name',json.dumps(result))
 def test_proof_hits_are_aggregated(self):
  self.proof.write_text(json.dumps({'source_sha':SHA,'sample':'password='+'z'*16}))
  result=self.run_archive('public/a.txt')
  self.assertEqual(result['categories'],{'proof:assignment_shape':1})
  self.assertEqual(result['state'],'blocked_by_screening')
  self.assertNotIn('z'*16,json.dumps(result))
 def test_pattern_label_mismatch_fails_closed(self):
  with patch.object(a,'PATTERNS',a.PATTERNS+[b'future_pattern']):
   with self.assertRaisesRegex(ValueError,'pattern_labels_mismatch'):self.run_archive('public/a.txt')
 def test_traversal(self):self.assertEqual(self.run_archive('../a')['counts']['unsafe_entries'],1)
 def test_symlink(self):self.assertEqual(self.run_archive('a',link=True)['counts']['unsafe_entries'],1)
 def test_env(self):self.assertEqual(self.run_archive('.env')['counts']['unsafe_entries'],1)
 def test_proof_mismatch(self):
  self.proof.write_text(json.dumps({'source_sha':'b'*40}))
  with self.assertRaisesRegex(ValueError,'proof_sha_mismatch'):self.run_archive('a')
if __name__=='__main__':unittest.main()
