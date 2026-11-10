<?php

require dirname(__DIR__).'/safe-network.php';
$dir = getenv('HOMOLOGATION_RUN');
$f = json_decode(file_get_contents($dir.'/fixture.json'), true);
$table = $argv[1];
$id = $argv[2];
$label = $argv[3];
if (! in_array($table, ['subscriptions', 'company_memberships', 'law_tasks', 'law_cases'], true) || ! preg_match('/^[a-z0-9-]{1,60}$/', $label)) {
    throw new RuntimeException('Unsafe lock target');
}
$db = new PDO('mysql:host=127.0.0.1;port='.getenv('DB_PORT').';dbname=fokus_synthetic', getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->beginTransaction();
$query = $db->prepare("SELECT id FROM `$table` WHERE id=? AND company_id=? FOR UPDATE");
$query->execute([$id, $f['company']]);
if (! $query->fetchColumn()) {
    throw new RuntimeException('Missing synthetic target');
}
file_put_contents($dir.'/'.$label.'-holder-ready', (string) microtime(true));
$until = microtime(true) + 30;
while (! is_file($dir.'/'.$label.'-release')) {
    if (microtime(true) > $until) {
        throw new RuntimeException('Holder timeout');
    }usleep(10000);
}
$db->rollBack();
echo "Released synthetic row lock\n";
