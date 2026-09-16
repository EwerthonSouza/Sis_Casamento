<?php

require_once __DIR__ . '/../config/central.php';

/*
|--------------------------------------------------------------------------
| Heartbeat para a Central — Fase 1 da integração (2026-09-11)
|--------------------------------------------------------------------------
| Roda só via cron, nunca no caminho de request web — nenhuma página deste
| sistema depende deste script nem espera por ele. Se a Central estiver
| fora do ar, isso nunca pode virar um erro visível pra ninguém: qualquer
| falha aqui só é registrada no log e o script termina normalmente.
*/

$config = centralConfig();

if ($config['base_url'] === '' || $config['token'] === '') {
    file_put_contents(
        __DIR__ . '/central_heartbeat.log',
        date('Y-m-d H:i:s') . " Ignorado: config/central.local.php ausente ou incompleto." . PHP_EOL,
        FILE_APPEND
    );
    exit;
}

/* Checagem rápida do próprio banco (meueventopro) — conexão isolada e
   descartável só pra este heartbeat, nunca toca em conexao.php. */
$databaseOk = false;
try {
    require_once __DIR__ . '/../conexao.php';
    $pdo->query('SELECT 1');
    $databaseOk = true;
} catch (Throwable $e) {
    $databaseOk = false;
}

$payload = json_encode([
    'environment' => 'production',
    'checks' => [
        'database' => $databaseOk,
    ],
]);

$ch = curl_init($config['base_url'] . '/api/v1/heartbeat');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $config['token'],
    'Content-Type: application/json',
    'Accept: application/json',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

$resposta = curl_exec($ch);
$erroCurl = curl_error($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resposta === false) {
    file_put_contents(
        __DIR__ . '/central_heartbeat.log',
        date('Y-m-d H:i:s') . " Falha ao contatar a Central: " . $erroCurl . PHP_EOL,
        FILE_APPEND
    );
    exit;
}

file_put_contents(
    __DIR__ . '/central_heartbeat.log',
    date('Y-m-d H:i:s') . " HTTP {$statusCode}: {$resposta}" . PHP_EOL,
    FILE_APPEND
);
