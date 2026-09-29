<?php

require_once __DIR__ . '/../config/central.php';
require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../modulos_evento.inc.php';

/*
|--------------------------------------------------------------------------
| Sincroniza módulos liberados por assessoria via Central (2026-09-29)
|--------------------------------------------------------------------------
| Único lugar que fala com a Central sobre módulos. hub_modulos.php e as
| demais páginas só leem central_modulos_liberados (nunca chamam a Central
| numa requisição web — mesmo princípio já usado pra avisos/heartbeat).
| Roda só via cron. Falha aqui nunca vira erro visível pro usuário — só log,
| tenta de novo no próximo ciclo; a tabela local mantém o último valor bom.
|
| Uma assessoria é identificada pra Central pelo próprio slug (identity_
| external_id) — precisa estar vinculada a um Cliente lá (mesma tela /identities
| já usada pra Avisos) pra /config resolver alguma coisa; sem vínculo, a
| Central responde "sem assinatura" e a assessoria fica só com o módulo
| padrão (Casamentos), nunca sem nenhum.
*/

function centralSyncModulesLog(string $mensagem): void
{
    file_put_contents(
        __DIR__ . '/central_sync_modules.log',
        date('Y-m-d H:i:s') . ' ' . $mensagem . PHP_EOL,
        FILE_APPEND
    );
}

$config = centralModulesConfig();

if ($config['base_url'] === '' || $config['token'] === '') {
    centralSyncModulesLog('Ignorado: config/central.local.php sem announcements_token.');
    exit;
}

$assessorias = $pdo->query("SELECT id, slug FROM assessorias WHERE status = 'ativa'")->fetchAll(PDO::FETCH_ASSOC);

$sincronizadas = 0;
$falhas = 0;

foreach ($assessorias as $assessoria) {
    $ch = curl_init($config['base_url'] . '/api/v1/config?identity_external_id=' . urlencode($assessoria['slug']));
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
        centralSyncModulesLog("assessoria={$assessoria['slug']}: falha ao contatar a Central: {$erroCurl}");
        $falhas++;
        continue;
    }

    if ($statusCode !== 200) {
        centralSyncModulesLog("assessoria={$assessoria['slug']}: HTTP {$statusCode}: resposta inesperada: {$resposta}");
        $falhas++;
        continue;
    }

    $corpo = json_decode($resposta, true) ?? [];
    $modulos = $corpo['data']['modules'] ?? [];

    if (!is_array($modulos)) {
        centralSyncModulesLog("assessoria={$assessoria['slug']}: campo modules ausente/inválido na resposta.");
        $falhas++;
        continue;
    }

    // Sem assinatura resolvida na Central (nunca vinculada, ou vínculo caiu),
    // a Central responde modules=[] — mas aqui isso significaria "bloquear
    // tudo", o oposto do padrão de segurança já estabelecido (nunca trancar
    // ninguém por causa de uma falha de integração). Só substitui a lista
    // local quando a Central mandou pelo menos um módulo de verdade.
    if (empty($modulos)) {
        centralSyncModulesLog("assessoria={$assessoria['slug']}: Central não retornou nenhum módulo (sem assinatura vinculada?) — mantendo o último valor local.");
        continue;
    }

    salvar_modulos_liberados_assessoria($pdo, (int) $assessoria['id'], $modulos);
    $sincronizadas++;
}

centralSyncModulesLog(
    'assessorias=' . count($assessorias) . " sincronizadas={$sincronizadas} falhas={$falhas}"
);
