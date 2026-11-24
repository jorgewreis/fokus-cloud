"""Two real disposable servers behind a loopback proxy controlled by CI root."""
import os
import pathlib
import select
import socket
import socketserver
import subprocess
import sys
import tempfile
import time
from unittest.mock import patch

import linux_disposable_mysql as guard

NAME = 'fokus_upgrade_0123456789abcdef'


def proxy(allowed):
    class Handler(socketserver.BaseRequestHandler):
        def handle(self):
            target = pathlib.Path('/run/fokus-proxy-target').read_text().strip()
            if target not in allowed:
                return
            with socket.create_connection((target, 3306), timeout=5) as upstream:
                sockets = [self.request, upstream]
                while True:
                    ready, _, _ = select.select(sockets, [], [], 30)
                    if not ready:
                        return
                    for source in ready:
                        data = source.recv(65536)
                        if not data:
                            return
                        (upstream if source is self.request else self.request).sendall(data)
    class Server(socketserver.ThreadingTCPServer):
        allow_reuse_address = True
        daemon_threads = True
    with Server(('127.0.0.1', 3306), Handler) as server:
        server.serve_forever()


def main(case):
    env = dict(os.environ, MYSQL_PWD=os.environ.get('DB_PASSWORD', ''), DB_CONNECTION='mysql',
               APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', CACHE_STORE='array',
               SESSION_DRIVER='array', LOG_CHANNEL='stderr')
    identity = guard.assert_disposable_mysql(env)
    # Root controls both the proxy's destination and this read-only signal.
    pathlib.Path('/tmp/fokus-preflight-ready').touch()
    deadline = time.monotonic() + 30
    while not pathlib.Path('/run/fokus-proxy-switched').exists():
        if time.monotonic() > deadline:
            raise RuntimeError('switch not signalled')
        time.sleep(0.05)
    command = guard.mysql84_command(identity)
    if case == 'ddl':
        result = subprocess.run(command + ['-e', 'CREATE DATABASE fokus_swap_must_not_exist'], env=env,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    elif case == 'restore':
        result = subprocess.run(command + [NAME], env=env, input=b'CREATE TABLE fokus_swap_restore (id INT);\n',
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    elif case == 'pdo':
        php = r'''try {$p=new PDO('mysql:host=127.0.0.1;port=3306;dbname='.getenv('DB_DATABASE'),getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,Pdo\Mysql::ATTR_INIT_COMMAND=>$argv[1]]);$p->exec('CREATE TABLE fokus_swap_pdo (id INT)');} catch (PDOException $e) {fwrite(STDERR,(string)($e->errorInfo[1]??''));exit(1);}'''
        result = subprocess.run(['php', '-r', php, guard.init_statement(identity)], env=env,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    elif case == 'dump':
        with tempfile.TemporaryDirectory(prefix='fokus-switch-') as directory:
            path = pathlib.Path(directory) / 'backup.sql'
            with tempfile.TemporaryFile() as errors, patch.object(guard, 'assert_disposable_mysql', return_value=identity):
                try:
                    guard.dump_disposable_mysql(NAME, env, path, errors)
                    raise RuntimeError('switched server accepted dump')
                except subprocess.CalledProcessError:
                    errors.seek(0)
                    if b'3141' not in errors.read():
                        raise RuntimeError('dump rejected for wrong reason')
            if list(path.parent.iterdir()):
                raise RuntimeError('switched server published partial backup')
            print('real listener switch rejected for dump')
            return
    elif case in ('candidate', 'baseline'):
        root = pathlib.Path('/tmp/fokus-probe-app' if case == 'candidate' else '/tmp/fokus-probe-baseline')
        config = root / 'config/database.php'
        original = config.read_text(encoding='utf-8')
        marker = "                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),"
        if original.count(marker) != 2:
            raise RuntimeError('unrecognized configuration')
        try:
            config.write_text(original.replace(marker, "                Mysql::ATTR_INIT_COMMAND => getenv('RELEASE_MYSQL_INIT_COMMAND'),\n" + marker, 1), encoding='utf-8')
            env['RELEASE_MYSQL_INIT_COMMAND'] = guard.init_statement(identity)
            result = subprocess.run(['php', 'artisan', 'migrate:status', '--no-interaction'], cwd=root, env=env,
                                    stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
            result.stderr = result.stdout
        finally:
            config.write_text(original, encoding='utf-8')
    else:
        raise ValueError('unknown test')
    if result.returncode == 0 or b'3141' not in result.stderr:
        raise RuntimeError(case + ' switched server did not reject with identity error')
    print('real listener switch rejected for ' + case)


if __name__ == '__main__':
    if sys.argv[1] == 'proxy':
        proxy(set(sys.argv[2:]))
    else:
        main(sys.argv[1])
