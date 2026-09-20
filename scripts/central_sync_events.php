<?php

require_once __DIR__ . '/../config/central.php';
require_once __DIR__ . '/../conexao.php';

/*
|--------------------------------------------------------------------------
| Dreno da fila de eventos pra Central — Fase 2 da integração (2026-09-11)
|--------------------------------------------------------------------------
| Único lugar que fala com a Central sobre eventos de uso. As páginas reais
| (login, criação de usuário/evento) só fazem um INSERT local rápido em
| central_outbox_events (via centralQueueEvent(), config/central.php) —
| nunca uma chamada de rede. Roda só via cron, nunca no caminho de request
| web. Falha aqui nunca pode virar erro visível: fica só no log, e as linhas
| não enviadas são tentadas de novo no próximo ciclo.
*/

$config = centralEventsConfig();

if ($config['base_url'] === '' || $config['token'] === '') {
    file_put_contents(
        __DIR__ . '/central_sync_events.log',
        date('Y-m-d H:i:s') . " Ignorado: config/central.local.php sem events_token." . PHP_EOL,
        FILE_APPEND
    );
    exit;
}

$stmt = $pdo->prepare(
    'SELECT id, uuid, type, payload, created_at FROM central_outbox_events
     WHERE sent_at IS NULL ORDER BY id ASC LIMIT 100'
);
$stmt->execute();
$linhas = $stmt->fetchAll();

if (count($linhas) === 0) {
    exit;
}

$events = array_map(function (array $linha): array {
    return [
        'uuid' => $linha['uuid'],
        'type' => $linha['type'],
        // created_at é gerado pelo MySQL sob a sessão com time_zone='-04:00'
        // (ver conexao.php) — é hora local de Boa Vista, não UTC. strtotime()
        // sozinho assume o timezone padrão do PHP (UTC neste container), o
        // que gerava um occurred_at 4h adiantado em relação ao horário real
        // do evento (mesmo achado feito no Demand's em 2026-09-12).
        'occurred_at' => (new DateTime($linha['created_at'], new DateTimeZone('America/Boa_Vista')))->format('c'),
        'data' => json_decode($linha['payload'], true) ?? [],
    ];
}, $linhas);

$ch = curl_init($config['base_url'] . '/api/v1/events');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $config['token'],
    'Content-Type: application/json',
    'Accept: application/json',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['events' => $events]));

$resposta = curl_exec($ch);
$erroCurl = curl_error($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resposta === false) {
    file_put_contents(
        __DIR__ . '/central_sync_events.log',
        date('Y-m-d H:i:s') . " Falha ao contatar a Central: " . $erroCurl . PHP_EOL,
        FILE_APPEND
    );
    exit;
}

$corpo = json_decode($resposta, true) ?? [];
$confirmados = array_merge($corpo['accepted'] ?? [], $corpo['duplicated'] ?? []);

if (count($confirmados) > 0) {
    $placeholders = implode(',', array_fill(0, count($confirmados), '?'));
    $pdo->prepare(
        "UPDATE central_outbox_events SET sent_at = NOW() WHERE uuid IN ({$placeholders})"
    )->execute($confirmados);
}

file_put_contents(
    __DIR__ . '/central_sync_events.log',
    date('Y-m-d H:i:s') . " HTTP {$statusCode}: enviados=" . count($events)
        . " confirmados=" . count($confirmados) . " resposta={$resposta}" . PHP_EOL,
    FILE_APPEND
);
