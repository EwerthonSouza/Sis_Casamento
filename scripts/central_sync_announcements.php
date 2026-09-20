<?php

require_once __DIR__ . '/../config/central.php';
require_once __DIR__ . '/../conexao.php';

/*
|--------------------------------------------------------------------------
| Sincroniza avisos da Central — Fase 3 (2026-09-15)
|--------------------------------------------------------------------------
| Único lugar que fala com a Central sobre avisos. Exibidos só em
| painel_admin.php, só pro usuário admin (decisão do dono — ver
| PROJECT_CONTEXT.md). Roda só via cron, nunca no caminho de request web.
| Falha aqui nunca vira erro visível — só log, tenta de novo no próximo ciclo.
|
| Achado real (2026-09-16): um aviso editado na Central DEPOIS do primeiro
| sync (ex.: restringir o alvo por cliente depois de publicar sem alvo)
| nunca propagava — o script só tratava "uuid novo" e "uuid que sumiu",
| nunca "uuid que já existe mas mudou". Agora todo aviso já sincronizado é
| comparado campo a campo a cada ciclo; se mudou, atualiza e reseta quem já
| tinha marcado como lido (pra dar a chance de ver a versão nova).
*/

function centralSyncAnnouncementsLog(string $mensagem): void
{
    file_put_contents(
        __DIR__ . '/central_sync_announcements.log',
        date('Y-m-d H:i:s') . ' ' . $mensagem . PHP_EOL,
        FILE_APPEND
    );
}

function centralAnnouncementDescricao(array $aviso): string
{
    $titulo = $aviso['title'] ?? 'Aviso da Central';

    $descricao = $aviso['body_html'] ?? null;
    if ($descricao !== null && trim($descricao) !== '') {
        return trim(strip_tags(html_entity_decode($descricao, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    return ! empty($aviso['summary']) ? $aviso['summary'] : $titulo;
}

$config = centralAnnouncementsConfig();

if ($config['base_url'] === '' || $config['token'] === '') {
    centralSyncAnnouncementsLog('Ignorado: config/central.local.php sem announcements_token.');
    exit;
}

$ch = curl_init($config['base_url'] . '/api/v1/announcements');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $config['token'],
    'Accept: application/json',
]);

$resposta = curl_exec($ch);
$erroCurl = curl_error($ch);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resposta === false) {
    centralSyncAnnouncementsLog('Falha ao contatar a Central: ' . $erroCurl);
    exit;
}

if ($statusCode !== 200) {
    centralSyncAnnouncementsLog("HTTP {$statusCode}: resposta inesperada: {$resposta}");
    exit;
}

$corpo = json_decode($resposta, true) ?? [];
$avisos = $corpo['data'] ?? [];

$admins = $pdo->query("SELECT id FROM usuarios WHERE tipo = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
$admins = array_map('strval', $admins);

$stmtJaSincronizado = $pdo->prepare('SELECT * FROM central_avisos WHERE central_uuid = ?');
$stmtInserir = $pdo->prepare(
    'INSERT INTO central_avisos (central_uuid, titulo, mensagem, modo, cor, alvo_admin_ids) VALUES (?, ?, ?, ?, ?, ?)'
);
$stmtAtualizar = $pdo->prepare(
    'UPDATE central_avisos SET titulo = ?, mensagem = ?, modo = ?, cor = ?, alvo_admin_ids = ?, ativo = 1 WHERE id = ?'
);
$stmtDesativar = $pdo->prepare('UPDATE central_avisos SET ativo = 0 WHERE id = ? AND ativo = 1');
$stmtLimparLidos = $pdo->prepare('DELETE FROM central_avisos_lidos WHERE aviso_id = ?');

$uuidsRecebidos = [];
$criados = 0;
$atualizados = 0;
$ignorados = 0;
$desativados = 0;

foreach ($avisos as $aviso) {
    $uuid = $aviso['uuid'] ?? null;

    if ($uuid === null) {
        continue;
    }

    $uuidsRecebidos[] = $uuid;

    $modo = $aviso['display_mode'] ?? 'popup';
    if (! in_array($modo, ['popup', 'banner', 'bell'], true)) {
        $modo = 'popup';
    }

    // null = qualquer admin vê; array = só os admins locais cujo id bate com
    // um external_id da lista (index.php manda external_id = usuarios.id no
    // login, então o match é direto).
    $targetIdentities = $aviso['target_identities'] ?? null;
    $alvoAdminIds = null;
    $semAlvoLocal = false;

    if ($targetIdentities !== null) {
        $intersecao = array_values(array_intersect($admins, $targetIdentities));

        if (count($intersecao) === 0) {
            $semAlvoLocal = true;
        } else {
            $alvoAdminIds = implode(',', $intersecao);
        }
    }

    $stmtJaSincronizado->execute([$uuid]);
    $existente = $stmtJaSincronizado->fetch();

    if ($semAlvoLocal) {
        // Restrito a um cliente sem nenhum admin local vinculado — se já
        // existia (o vínculo local mudou depois), desativa; se nunca
        // existiu, só loga e segue (nunca cria linha sem alvo válido).
        if ($existente) {
            $stmtDesativar->execute([$existente['id']]);
            if ($stmtDesativar->rowCount() > 0) {
                $desativados++;
            }
        } else {
            centralSyncAnnouncementsLog("Ignorado uuid={$uuid}: restrito a cliente sem nenhum admin local vinculado.");
            $ignorados++;
        }

        continue;
    }

    $titulo = $aviso['title'] ?? 'Aviso da Central';
    $mensagem = centralAnnouncementDescricao($aviso);
    $cor = $modo === 'banner' ? ($aviso['banner_color'] ?? null) : null;

    if ($existente) {
        $mudou = $existente['titulo'] !== $titulo
            || $existente['mensagem'] !== $mensagem
            || $existente['modo'] !== $modo
            || (string) ($existente['cor'] ?? '') !== (string) ($cor ?? '')
            || (string) ($existente['alvo_admin_ids'] ?? '') !== (string) ($alvoAdminIds ?? '')
            || (int) $existente['ativo'] !== 1;

        if ($mudou) {
            $stmtAtualizar->execute([$titulo, $mensagem, $modo, $cor, $alvoAdminIds, $existente['id']]);
            // Reseta quem já tinha marcado como lido — o conteúdo/alvo mudou,
            // então merece uma chance nova de aparecer pra quem se qualifica agora.
            $stmtLimparLidos->execute([$existente['id']]);
            $atualizados++;
        }

        continue;
    }

    $stmtInserir->execute([$uuid, $titulo, $mensagem, $modo, $cor, $alvoAdminIds]);
    $criados++;
}

// Avisos já sincronizados que sumiram inteiramente da lista de aplicáveis
// (despublicados, expirados) — desativa a linha local correspondente.
if (count($uuidsRecebidos) > 0) {
    $placeholders = implode(',', array_fill(0, count($uuidsRecebidos), '?'));
    $stmtOrfaos = $pdo->prepare(
        "SELECT id FROM central_avisos WHERE central_uuid NOT IN ({$placeholders}) AND ativo = 1"
    );
    $stmtOrfaos->execute($uuidsRecebidos);
} else {
    $stmtOrfaos = $pdo->query('SELECT id FROM central_avisos WHERE ativo = 1');
}

foreach ($stmtOrfaos->fetchAll(PDO::FETCH_COLUMN) as $orfaoId) {
    $stmtDesativar->execute([$orfaoId]);
    if ($stmtDesativar->rowCount() > 0) {
        $desativados++;
    }
}

centralSyncAnnouncementsLog(
    "HTTP {$statusCode}: recebidos=" . count($avisos) . " criados={$criados} atualizados={$atualizados} ignorados={$ignorados} desativados={$desativados}"
);
