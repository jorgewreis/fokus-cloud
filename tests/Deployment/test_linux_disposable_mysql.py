"""Fail-closed checks for the synthetic server guard; no database is used."""
import os
from types import SimpleNamespace
import unittest
from unittest.mock import patch

import linux_disposable_mysql as guard


class DisposableMysqlGuardTest(unittest.TestCase):
    def test_refused_identity_never_executes_create_or_drop(self):
        with patch.object(guard, 'assert_disposable_mysql', side_effect=RuntimeError('refuse')), \
             patch.object(guard.subprocess, 'run') as run:
            for ddl in ('CREATE DATABASE `fokus_upgrade_0123456789abcdef`',
                        'DROP DATABASE `fokus_upgrade_0123456789abcdef`'):
                with self.subTest(ddl=ddl), self.assertRaisesRegex(RuntimeError, 'refuse'):
                    guard.execute_disposable_sql(ddl, {})
            run.assert_not_called()

    def test_missing_identity_never_invokes_mysql(self):
        with patch.object(guard.os, 'name', 'posix'), patch.object(guard.os, 'geteuid', return_value=1000, create=True), \
             patch.object(guard.IDENTITY.__class__, 'lstat', side_effect=FileNotFoundError), \
             patch.object(guard.subprocess, 'run') as run:
            with self.assertRaisesRegex(RuntimeError, 'refuse non-disposable'):
                guard.assert_disposable_mysql({})
            run.assert_not_called()

    def test_wrong_server_and_namespace_never_execute_ddl(self):
        valid_uuid = '01234567-89ab-cdef-0123-456789abcdef'
        env = {'DB_HOST': '127.0.0.1', 'DB_PORT': '3306', 'DB_USERNAME': 'root'}
        with patch.object(guard.os, 'name', 'posix'), patch.object(guard.os, 'geteuid', return_value=1000, create=True), \
             patch.object(guard.IDENTITY.__class__, 'lstat', side_effect=[SimpleNamespace(st_mode=0o100444, st_uid=0), SimpleNamespace(st_mode=0o040755, st_uid=0)]), \
             patch.object(guard.IDENTITY.__class__, 'read_text', return_value=valid_uuid + '\nnet:[123]\n'), \
             patch.object(guard.os, 'readlink', return_value='net:[123]'), \
             patch.object(guard.subprocess, 'run', return_value=SimpleNamespace(stdout='ffffffff-ffff-ffff-ffff-ffffffffffff\n')) as run:
            with self.assertRaisesRegex(RuntimeError, 'refuse non-disposable'):
                guard.assert_disposable_mysql(env)
            self.assertEqual(run.call_count, 1)
            self.assertIn('SELECT @@server_uuid', run.call_args.args[0])
            self.assertFalse(any('CREATE' in str(arg) or 'DROP' in str(arg) for arg in run.call_args.args[0]))
        with patch.object(guard.os, 'name', 'posix'), patch.object(guard.os, 'geteuid', return_value=1000, create=True), \
             patch.object(guard.IDENTITY.__class__, 'lstat', side_effect=[SimpleNamespace(st_mode=0o100444, st_uid=0), SimpleNamespace(st_mode=0o040755, st_uid=0)]), \
             patch.object(guard.IDENTITY.__class__, 'read_text', return_value=valid_uuid + '\nnet:[123]\n'), \
             patch.object(guard.os, 'readlink', return_value='net:[999]'), \
             patch.object(guard.subprocess, 'run') as run:
            with self.assertRaisesRegex(RuntimeError, 'refuse non-disposable'):
                guard.assert_disposable_mysql(env)
            run.assert_not_called()


if __name__ == '__main__':
    unittest.main()
