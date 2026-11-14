"""Exercise the actual guarded dump consumer only in the disposable CI server."""
import os
import pathlib
import secrets
import subprocess
import tempfile
import threading
import time
from unittest.mock import patch

import linux_disposable_mysql as guard


def main():
    env = dict(os.environ, MYSQL_PWD=os.environ.get('DB_PASSWORD', ''))
    identity = guard.assert_disposable_mysql(env)
    other = ('1' if identity[0] != '1' else '2') + identity[1:]
    name = 'fokus_upgrade_' + secrets.token_hex(8)
    guard.execute_disposable_sql('CREATE DATABASE `' + name + '`', env)
    try:
        guard.execute_disposable_sql('CREATE TABLE `' + name + '`.fokus_session_probe (id INT PRIMARY KEY)', env)
        guard.execute_disposable_sql('INSERT INTO `' + name + '`.fokus_session_probe VALUES (1)', env)
        with tempfile.TemporaryDirectory(prefix='fokus-consumer-') as directory:
            path = pathlib.Path(directory) / 'backup.sql'
            # Model a different server at session establishment after preflight:
            # this checks the consumer's real init command, not a listener swap.
            with patch.object(guard, 'assert_disposable_mysql', return_value=other):
                try:
                    guard.dump_disposable_mysql(name, env, path, subprocess.DEVNULL)
                    raise RuntimeError('wrong identity accepted')
                except subprocess.CalledProcessError:
                    pass
            if path.exists() or list(path.parent.iterdir()):
                raise RuntimeError('wrong identity published dump bytes')

            original = guard.mysqldump84_command
            def delayed(uuid, database):
                command = original(uuid, database)
                return command[:-1] + ['--where=SLEEP(30)=0', command[-1]]
            errors = []
            def dump():
                try:
                    guard.dump_disposable_mysql(name, env, path, subprocess.DEVNULL)
                except BaseException as error:
                    errors.append(error)
            with patch.object(guard, 'mysqldump84_command', side_effect=delayed):
                thread = threading.Thread(target=dump, daemon=True)
                thread.start()
                try:
                    deadline = time.monotonic() + 15
                    victim = None
                    query = ("SELECT ID FROM information_schema.PROCESSLIST WHERE DB='" + name + "' "
                             "AND ID<>CONNECTION_ID() AND INFO LIKE 'SELECT%SLEEP(30)=0%'")
                    while time.monotonic() < deadline:
                        ids = subprocess.check_output(guard.mysql84_command(identity) + ['-Nse', query], env=env, timeout=5, text=True).split()
                        if len(ids) == 1 and ids[0].isascii() and ids[0].isdigit():
                            victim = ids[0]
                            break
                        if len(ids) > 1 or not thread.is_alive():
                            raise RuntimeError('could not uniquely identify consumer session')
                        time.sleep(0.2)
                    if victim is None:
                        raise RuntimeError('consumer did not enter synthetic delayed query')
                    guard.execute_disposable_sql('KILL CONNECTION ' + victim, env)
                    thread.join(timeout=15)
                    if thread.is_alive() or len(errors) != 1 or not isinstance(errors[0], subprocess.CalledProcessError):
                        raise RuntimeError('interrupted dump did not fail as expected')
                    if path.exists() or list(path.parent.iterdir()):
                        raise RuntimeError('interrupted consumer retained partial dump')
                finally:
                    thread.join(timeout=35)
            print('actual consumer rejects mismatched identity and removes interrupted partial dump')
    finally:
        guard.execute_disposable_sql('DROP DATABASE `' + name + '`', env)


if __name__ == '__main__':
    main()
