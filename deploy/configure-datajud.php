<?php

declare(strict_types=1);

// Receives the deployment secret through stdin, never through arguments or logs.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$key = trim((string) stream_get_contents(STDIN, 4096));
if ($key === '') {
    fwrite(STDOUT, "Chave Datajud não fornecida pelo deploy; configuração existente preservada.\n");
    exit(0);
}
if (! preg_match('/\A[A-Za-z0-9+\/=\-_]{16,2048}\z/', $key)) {
    fwrite(STDERR, "Formato da chave Datajud inválido.\n");
    exit(1);
}

$path = dirname(__DIR__).'/.env';
$contents = is_file($path) ? file_get_contents($path) : false;
if ($contents === false) {
    fwrite(STDERR, "Arquivo de ambiente não encontrado ou ilegível.\n");
    exit(1);
}
$line = 'DATAJUD_API_KEY="'.$key.'"';
$updated = preg_match('/^[ \t]*DATAJUD_API_KEY[ \t]*=/m', $contents)
    ? preg_replace('/^[ \t]*DATAJUD_API_KEY[ \t]*=.*$/m', $line, $contents)
    : rtrim($contents).PHP_EOL.$line.PHP_EOL;
$temporary = tempnam(dirname($path), '.datajud-env-');
if ($temporary === false || ! chmod($temporary, fileperms($path) & 0777) || file_put_contents($temporary, $updated) === false || ! rename($temporary, $path)) {
    if ($temporary !== false && is_file($temporary)) unlink($temporary);
    fwrite(STDERR, "Não foi possível atualizar a configuração Datajud.\n");
    exit(1);
}
fwrite(STDOUT, "Chave Datajud configurada no ambiente, sem exibir seu conteúdo.\n");
