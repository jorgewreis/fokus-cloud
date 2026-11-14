"""Exercise per-PDO init in disposable Laravel source copies; never production."""
import os
import pathlib
import subprocess
import sys

from linux_disposable_mysql import IDENTITY, assert_disposable_mysql
from probe_mysql_init_identity import init_statement


def main():
    env = dict(os.environ, MYSQL_PWD=os.environ.get('DB_PASSWORD', ''), DB_CONNECTION='mysql',
               APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', CACHE_STORE='array',
               SESSION_DRIVER='array', LOG_CHANNEL='stderr')
    assert_disposable_mysql(env)
    root = pathlib.Path(sys.argv[1]).resolve(strict=True)
    if root != pathlib.Path('/tmp/fokus-probe-app') or env.get('DB_DATABASE') != 'fokuscloud_security':
        raise RuntimeError('unexpected disposable Laravel source or database')
    config = root / 'config/database.php'
    if (root / '.env').exists() or (root / 'bootstrap/cache/config.php').exists():
        raise RuntimeError('unsafe inherited Laravel config')
    before = config.read_text(encoding='utf-8')
    marker = "                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),"
    if before.count(marker) != 2:
        raise RuntimeError('unrecognized mysql config layout')
    identity = IDENTITY.read_text(encoding='ascii').splitlines()[0]
    other = ('1' if identity[0] != '1' else '2') + identity[1:]

    def configured(value):
        command = init_statement(value)
        return before.replace(marker, '                Mysql::ATTR_INIT_COMMAND => "' + command + '",\n' + marker, 1)

    try:
        config.write_text(configured(identity), encoding='utf-8')
        subprocess.run(['php', '/tmp/probe_laravel_mysql.php', str(root), identity, other],
                       env=env, cwd=root, timeout=60, check=True)
        # artisan itself must attempt a PDO connection and reject an incorrect
        # identity by the expected server error, rather than any unrelated error.
        config.write_text(configured(other), encoding='utf-8')
        result = subprocess.run(['php', 'artisan', 'migrate:status', '--no-interaction'],
                                env=env, cwd=root, timeout=60, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
        if result.returncode == 0 or b'3141' not in result.stdout:
            raise RuntimeError('artisan mismatch did not fail with identity error')
        print('artisan incorrect identity rejected with expected error')
    finally:
        config.write_text(before, encoding='utf-8')


if __name__ == '__main__':
    main()
