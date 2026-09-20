<?php

/**
 * Config da integração com a Central (sistema-mãe), mesmo padrão já
 * validado no Demand's (config/google_oauth.php de lá, reaproveitado aqui
 * mesmo sem precedente local — consistência entre os sistemas filhos).
 *
 * Fase 1 (2026-09-11): heartbeat, via scripts/central_heartbeat.php.
 * Fase 2 (2026-09-11): eventos de uso. centralQueueEvent() é chamada a
 * partir de páginas reais (login, criação de usuário/evento), mas só faz
 * um INSERT local — nunca uma chamada de rede. O único lugar que fala com a
 * Central pra eventos é scripts/central_sync_events.php, via cron. Uma
 * falha em qualquer função deste arquivo nunca deve afetar nenhuma página
 * que usuário vê.
 * Fase 3 (2026-09-15): avisos. scripts/central_sync_announcements.php (cron)
 * puxa os avisos aplicáveis e alimenta central_avisos — exibidos só em
 * painel_admin.php, só pro usuário admin (ver PROJECT_CONTEXT.md).
 */

function centralReadVar(string $key): string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }

    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }

    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return $_SERVER[$key];
    }

    if (defined($key)) {
        return (string) constant($key);
    }

    return '';
}

function centralReadLocalConfig(): array
{
    $path = __DIR__ . '/central.local.php';

    if (! file_exists($path)) {
        return [];
    }

    return require $path;
}

function centralConfig(): array
{
    $local = centralReadLocalConfig();

    $baseUrl = centralReadVar('CENTRAL_API_BASE_URL') ?: ($local['base_url'] ?? '');
    $token = centralReadVar('CENTRAL_API_TOKEN') ?: ($local['token'] ?? '');

    return [
        'base_url' => rtrim($baseUrl, '/'),
        'token' => $token,
    ];
}

/**
 * Token separado do de heartbeat, só com a ability events:write — mesmo
 * princípio de menor privilégio: cada script só tem a permissão que
 * realmente usa.
 */
function centralEventsConfig(): array
{
    $local = centralReadLocalConfig();

    $baseUrl = centralReadVar('CENTRAL_API_BASE_URL') ?: ($local['base_url'] ?? '');
    $token = centralReadVar('CENTRAL_API_EVENTS_TOKEN') ?: ($local['events_token'] ?? '');

    return [
        'base_url' => rtrim($baseUrl, '/'),
        'token' => $token,
    ];
}

/**
 * Token separado, só com a ability announcements:read — mesmo princípio de
 * menor privilégio dos outros. Usado só por
 * scripts/central_sync_announcements.php (Fase 3, 2026-09-15).
 */
function centralAnnouncementsConfig(): array
{
    $local = centralReadLocalConfig();

    $baseUrl = centralReadVar('CENTRAL_API_BASE_URL') ?: ($local['base_url'] ?? '');
    $token = centralReadVar('CENTRAL_API_ANNOUNCEMENTS_TOKEN') ?: ($local['announcements_token'] ?? '');

    return [
        'base_url' => rtrim($baseUrl, '/'),
        'token' => $token,
    ];
}

/**
 * UUID v4 canônico (com hífen, 8-4-4-4-12) — a Central valida esse formato
 * exato. Não existe gerador de UUID neste codebase; formatado a partir de
 * random_bytes(16), mesma base já usada em outros lugares do projeto.
 */
function centralGerarUuidV4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Enfileira um evento de uso pra Central — só um INSERT local rápido,
 * nunca uma chamada de rede. Chamada a partir de páginas reais (login,
 * criação de usuário/evento); scripts/central_sync_events.php (cron) é
 * quem de fato envia pra Central depois. Nunca propaga exceção — uma
 * falha aqui não pode quebrar a ação real do usuário.
 */
function centralQueueEvent(PDO $pdo, string $type, array $data): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO central_outbox_events (uuid, type, payload) VALUES (?, ?, ?)'
        );
        $stmt->execute([centralGerarUuidV4(), $type, json_encode($data)]);
    } catch (Throwable $e) {
        // Silencioso de propósito — ver docblock.
    }
}
