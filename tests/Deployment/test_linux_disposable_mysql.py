"""Fail-closed checks for the synthetic server guard; no database is used."""
import os
import pathlib
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

import linux_disposable_mysql as guard


class DisposableMysqlGuardTest(unittest.TestCase):
    def test_mysql_upgrade_stops_before_any_database_client(self):
        import pathlib
        import subprocess
        import sys

        entry = pathlib.Path(__file__).with_name('linux-upgrade.py')
        result = subprocess.run([sys.executable, str(entry), '--driver', 'mysql'],
                                capture_output=True, text=True, timeout=15)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('per-session identity verification is incomplete', result.stderr)

    def test_refused_identity_never_executes_create_or_drop(self):
        with patch.object(guard, 'assert_disposable_mysql', side_effect=RuntimeError('refuse')), \
             patch.object(guard.subprocess, 'run') as run:
            for ddl in ('CREATE DATABASE `fokus_upgrade_0123456789abcdef`',
                        'DROP DATABASE `fokus_upgrade_0123456789abcdef`'):
                with self.subTest(ddl=ddl), self.assertRaisesRegex(RuntimeError, 'refuse'):
                    guard.execute_disposable_sql(ddl, {})
            run.assert_not_called()

    def test_sql_client_disables_option_files(self):
        with patch.object(guard, 'assert_disposable_mysql', return_value='01234567-89ab-cdef-0123-456789abcdef') as identity, patch.object(guard.subprocess, 'run') as run:
            guard.execute_disposable_sql('CREATE DATABASE synthetic', {})
            identity.assert_called_once()
            command = run.call_args.args[0]
            self.assertEqual(command[:2], ['mysql84', '--no-defaults'])
            self.assertIn('--skip-reconnect', command)
            self.assertTrue(any(arg.startswith('--init-command=') and '01234567-89ab-cdef-0123-456789abcdef' in arg for arg in command))

    def test_failed_dump_does_not_publish_partial_backup(self):
        def fail_after_partial(*args, **kwargs):
            kwargs['stdout'].write(b'partial synthetic dump')
            raise subprocess.CalledProcessError(1, args[0])
        with tempfile.TemporaryDirectory() as directory:
            target = pathlib.Path(directory) / 'backup.sql'
            with patch.object(guard, 'assert_disposable_mysql', return_value='01234567-89ab-cdef-0123-456789abcdef'), \
                 patch.object(guard.subprocess, 'run', side_effect=fail_after_partial):
                with self.assertRaises(subprocess.CalledProcessError):
                    guard.dump_disposable_mysql('fokus_upgrade_0123456789abcdef', {}, target, subprocess.DEVNULL)
            self.assertFalse(target.exists())
            self.assertEqual(list(pathlib.Path(directory).iterdir()), [])

    def test_commands_reject_invalid_identity_and_database(self):
        with self.assertRaises(ValueError):
            guard.mysql84_command("invalid' OR 1=1")
        with self.assertRaises(ValueError):
            guard.mysqldump84_command('01234567-89ab-cdef-0123-456789abcdef', 'production')

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
            self.assertEqual(run.call_args.args[0][:2], ['mysql', '--no-defaults'])
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
