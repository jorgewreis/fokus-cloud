"""Manual, read-only experiment; not a replacement for the blocked upgrade gate.

Run only inside the existing host-orchestrated disposable release environment.
This intentionally does not change CI or enable the MySQL upgrade harness.
"""
import os
import subprocess

from linux_disposable_mysql import IDENTITY, UUID, assert_disposable_mysql


def init_statement(identity):
    if not UUID.fullmatch(identity):
        raise ValueError('invalid MySQL UUID')
    return ("SET @fokus_identity_guard = IF(@@server_uuid = '" + identity
            + "', 1, JSON_EXTRACT('invalid json', '$'))")


def main():
    env = dict(os.environ, MYSQL_PWD=os.environ.get('DB_PASSWORD', ''))
    assert_disposable_mysql(env)
    if env.get('DB_DATABASE') != 'fokuscloud_security':
        raise RuntimeError('unexpected synthetic database')
    identity = IDENTITY.read_text(encoding='ascii').splitlines()[0]
    other = ('1' if identity[0] != '1' else '2') + identity[1:]
    pdo = r'''$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, Pdo\Mysql::ATTR_INIT_COMMAND=>$argv[1]];
try {$pdo=new PDO('mysql:host=127.0.0.1;port=3306;dbname=fokuscloud_security',getenv('DB_USERNAME'),getenv('DB_PASSWORD'),$options); $pdo->query('SELECT 1'); exit(0);} catch (PDOException $e) {fwrite(STDERR, (string)($e->errorInfo[1] ?? '')."\n"); exit(1);}'''
    failures = []
    for label, command in (
        ('pdo', lambda sql: ['php', '-r', pdo, sql]),
        ('mysql', lambda sql: ['mysql', '--no-defaults', '--protocol=TCP', '-h127.0.0.1', '-P3306', '-uroot', '--skip-reconnect', '--init-command=' + sql, '-Nse', 'SELECT 1']),
        ('mysqldump', lambda sql: ['mysqldump', '--no-defaults', '--protocol=TCP', '-h127.0.0.1', '-P3306', '-uroot', '--init-command=' + sql, '--no-data', '--no-tablespaces', 'fokuscloud_security']),
    ):
        for expected, value in ((True, identity), (False, other)):
            result = subprocess.run(command(init_statement(value)), env=env, stdout=subprocess.DEVNULL,
                                    stderr=subprocess.PIPE, timeout=30, check=False)
            if (result.returncode == 0) != expected:
                failures.append(label + ' identity experiment did not meet expected outcome')
                print(label, 'unexpected exit', result.returncode, result.stderr.decode('utf-8', errors='replace')[:500])
                continue
            if not expected and b'3141' not in result.stderr:
                failures.append(label + ' mismatch failed for an unexpected reason')
                print(label, 'unexpected mismatch error', result.stderr.decode('utf-8', errors='replace')[:500])
                continue
            print(label, 'matching' if expected else 'mismatch', 'ok')

    # Kill only a session created by this very process in the synthetic server.
    # PDO must not silently resume work after that connection is lost. A newly
    # constructed connection must re-run its own initialization command.
    reconnect = r'''$dsn='mysql:host=127.0.0.1;port=3306;dbname=fokuscloud_security';
$connect=fn($init)=>new PDO($dsn,getenv('DB_USERNAME'),getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,Pdo\Mysql::ATTR_INIT_COMMAND=>$init]);
$first=$connect($argv[1]); $admin=$connect($argv[1]); $id=$first->query('SELECT CONNECTION_ID()')->fetchColumn();
if (!ctype_digit((string)$id)) exit(2); $admin->exec('KILL CONNECTION '.(int)$id);
try {$first->query('SELECT 1'); exit(3);} catch (PDOException $e) {}
$new=$connect($argv[1]); $new->query('SELECT 1');
try {$unexpected=$connect($argv[2]); exit(4);} catch (PDOException $e) {if ((int)($e->errorInfo[1] ?? 0) !== 3141) exit(5);}'''
    subprocess.run(['php', '-r', reconnect, init_statement(identity), init_statement(other)],
                   env=env, timeout=30, check=True)
    print('pdo killed session and new connections ok')
    if failures:
        raise RuntimeError('; '.join(failures))


if __name__ == '__main__':
    main()
