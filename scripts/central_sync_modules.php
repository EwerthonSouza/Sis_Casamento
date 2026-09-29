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
| Uma assessoria é identificada pra Central pelo usuario_id de algum membro
| dela (identity_external_id) — mesma convenção já usada pros eventos
| user.login/user.created (external_id = usuarios.id). Uma assessoria pode
| ter mais de um usuário tipo='admin' (achado real: existe uma conta "Admin"
| genérica além da conta pessoal da dona), e só um deles pode estar vinculado
| a um Cliente em /identities — por isso tenta TODOS os usuários da
| assessoria (admin antes de assistente, id crescente) até um resolver
| alguma coisa, em vez de assumir que o de menor id é o certo. Sem nenhum
| vinculado ainda (assessoria nova), a Central responde "sem assinatura" pra
| todos e a assessoria fica só com o módulo padrão (Casamentos), nunca sem
| nenhum.
*/

function centralSyncModulesLog(string $mensagem): void
{
    file_put_contents(
        __DIR__ . '/central_sync_modules.log',
        date('Y-m-d H:i:s') . ' ' . $mensagem . PHP_EOL,
        FILE_APPEND
    );
}

/**
 * Chama GET /config na Central pra um external_id específico. Retorna o
 * array de módulos em caso de sucesso com pelo menos 1 módulo, ou null se
 * não resolveu nada / deu erro (o chamador decide o que fazer com null).
 */
function centralBuscarModulos(array $config, string $identityExternalId, string $assessoriaSlug): ?array
{
    $ch = curl_init($config['base_url'] . '/api/v1/config?identity_external_id=' . urlencode($identityExternalId));
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
        centralSyncModulesLog("assessoria={$assessoriaSlug} identity={$identityExternalId}: falha ao contatar a Central: {$erroCurl}");
        return null;
    }

    if ($statusCode !== 200) {
        centralSyncModulesLog("assessoria={$assessoriaSlug} identity={$identityExternalId}: HTTP {$statusCode}: resposta inesperada: {$resposta}");
        return null;
    }

    $corpo = json_decode($resposta, true) ?? [];
    $modulos = $corpo['data']['modules'] ?? [];

    if (!is_array($modulos) || empty($modulos)) {
        return null;
    }

    return $modulos;
}

$config = centralModulesConfig();

if ($config['base_url'] === '' || $config['token'] === '') {
    centralSyncModulesLog('Ignorado: config/central.local.php sem announcements_token.');
    exit;
}

$assessorias = $pdo->query("SELECT id, slug FROM assessorias WHERE status = 'ativa'")->fetchAll(PDO::FETCH_ASSOC);

$stmtUsuarios = $pdo->prepare("
    SELECT id FROM usuarios
    WHERE assessoria_id = ? AND tipo IN ('admin', 'assistente')
    ORDER BY (tipo = 'admin') DESC, id ASC
");

$sincronizadas = 0;
$semResultado = 0;

foreach ($assessorias as $assessoria) {
    $stmtUsuarios->execute([$assessoria['id']]);
    $usuarioIds = $stmtUsuarios->fetchAll(PDO::FETCH_COLUMN);

    if (empty($usuarioIds)) {
        centralSyncModulesLog("assessoria={$assessoria['slug']}: nenhum usuário cadastrado ainda, pulando.");
        continue;
    }

    $modulos = null;
    foreach ($usuarioIds as $usuarioId) {
        $modulos = centralBuscarModulos($config, (string) $usuarioId, $assessoria['slug']);
        if ($modulos !== null) {
            break;
        }
    }

    // Nenhum usuário da assessoria tem identidade vinculada a um Cliente na
    // Central ainda (ou a Central está fora do ar) — isso aqui significaria
    // "bloquear tudo", o oposto do padrão de segurança já estabelecido
    // (nunca trancar ninguém por causa de uma falha de integração). Mantém
    // o último valor local em vez de zerar.
    if ($modulos === null) {
        centralSyncModulesLog("assessoria={$assessoria['slug']}: nenhum dos " . count($usuarioIds) . " usuário(s) resolveu módulo na Central — mantendo o último valor local.");
        $semResultado++;
        continue;
    }

    salvar_modulos_liberados_assessoria($pdo, (int) $assessoria['id'], $modulos);
    $sincronizadas++;
}

centralSyncModulesLog(
    'assessorias=' . count($assessorias) . " sincronizadas={$sincronizadas} sem_resultado={$semResultado}"
);
