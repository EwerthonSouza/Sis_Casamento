<?php
// Liberação do "Meu Site do Casamento" (item pago), feita pela Central depois de confirmar o pagamento.
//
// 1) API para a Central:  POST /site_liberar.php   Authorization: Bearer <site_token>
//      corpo (JSON ou formulário): { "evento_id": 12, "acao": "liberar" | "bloquear" }
//    O token fica em config/central.local.php ('site_token') ou na variável de ambiente SITE_TOKEN.
//    Sem token configurado a API fica desligada (503): nunca libera por padrão.
// 2) Tela do desenvolvedor (sessão 'desenvolvedor'): lista pedidos e libera/bloqueia manualmente,
//    enquanto a Central não chama a API.
session_start();
require_once 'conexao.php';
require_once 'site_convite.inc.php';
site_garantir_schema($pdo);

function site_aplicar_acesso(PDO $pdo, int $evento_id, string $acao, string $por): bool {
    $existe = $pdo->prepare("SELECT 1 FROM eventos WHERE id = ?"); $existe->execute([$evento_id]);
    if (!$existe->fetchColumn()) return false;
    if ($acao === 'liberar') {
        $pdo->prepare("INSERT INTO site_convite_acesso (evento_id, status, liberado_em, liberado_por) VALUES (?, 'liberado', NOW(), ?)
                       ON DUPLICATE KEY UPDATE status = 'liberado', liberado_em = NOW(), liberado_por = VALUES(liberado_por)")->execute([$evento_id, $por]);
    } else {
        $pdo->prepare("UPDATE site_convite_acesso SET status = 'solicitado', liberado_em = NULL, liberado_por = NULL WHERE evento_id = ?")->execute([$evento_id]);
    }
    return true;
}

/* ---------- 1) API (Central) ---------- */
$cab = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($cab === '' && function_exists('getallheaders')) {   // o Apache nem sempre repassa o Authorization ao PHP
    $cab = array_change_key_case(getallheaders(), CASE_LOWER)['authorization'] ?? '';
}
if ($cab !== '' || ($_SERVER['REQUEST_METHOD'] === 'POST' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json'))) {
    header('Content-Type: application/json; charset=utf-8');
    $local = @include __DIR__ . '/config/central.local.php';
    $token = (is_array($local) ? (string)($local['site_token'] ?? '') : '') ?: (string)getenv('SITE_TOKEN');
    if ($token === '') { http_response_code(503); echo json_encode(['ok' => false, 'erro' => 'API de liberação não configurada.']); exit; }
    if (!hash_equals('Bearer ' . $token, $cab)) { http_response_code(401); echo json_encode(['ok' => false, 'erro' => 'Não autorizado.']); exit; }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'erro' => 'Use POST.']); exit; }
    $dados = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $acao = in_array($dados['acao'] ?? '', ['liberar', 'bloquear'], true) ? $dados['acao'] : '';
    $evento_id = (int)($dados['evento_id'] ?? 0);
    if ($acao === '' || $evento_id <= 0 || !site_aplicar_acesso($pdo, $evento_id, $acao, 'central')) {
        http_response_code(422); echo json_encode(['ok' => false, 'erro' => 'Informe evento_id válido e acao = liberar ou bloquear.']); exit;
    }
    echo json_encode(['ok' => true, 'evento_id' => $evento_id, 'status' => $acao === 'liberar' ? 'liberado' : 'solicitado']);
    exit;
}

/* ---------- 2) Tela do desenvolvedor ---------- */
if (($_SESSION['usuario_tipo'] ?? '') !== 'desenvolvedor') { http_response_code(403); exit('Acesso restrito ao desenvolvedor.'); }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '') && in_array($_POST['acao'] ?? '', ['liberar', 'bloquear'], true)) {
        site_aplicar_acesso($pdo, (int)($_POST['evento_id'] ?? 0), $_POST['acao'], 'desenvolvedor');
    }
    header('Location: site_liberar.php'); exit;
}
$linhas = $pdo->query("
    SELECT a.*, c.nome AS casal, c.nome_secundario, e.data_evento, ass.nome AS assessoria
    FROM site_convite_acesso a
    INNER JOIN eventos e ON e.id = a.evento_id
    INNER JOIN clientes c ON c.id = e.cliente_id
    LEFT JOIN assessorias ass ON ass.id = e.assessoria_id
    ORDER BY (a.status = 'solicitado') DESC, a.solicitado_em DESC, a.evento_id DESC
")->fetchAll();
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html><html lang="pt-br"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Liberação do Meu Site</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><div class="container py-4" style="max-width:980px">
<h4 class="fw-bold mb-1">Liberação do "Meu Site do Casamento"</h4>
<p class="text-muted">Valor atual: <strong>R$ <?= number_format(site_preco(), 2, ',', '.') ?></strong>. Libere depois de confirmar o pagamento. A Central também pode liberar por API (veja o topo de <code>site_liberar.php</code>).</p>
<div class="table-responsive bg-white rounded shadow-sm"><table class="table align-middle mb-0">
<thead><tr><th>Casal</th><th>Assessoria</th><th>Evento</th><th>Status</th><th>Pedido</th><th></th></tr></thead><tbody>
<?php foreach ($linhas as $l): ?>
<tr><td class="fw-bold"><?= $h($l['casal'] . ($l['nome_secundario'] ? ' & ' . $l['nome_secundario'] : '')) ?><div class="small text-muted">evento #<?= (int)$l['evento_id'] ?></div></td>
<td><?= $h($l['assessoria'] ?: '—') ?></td><td><?= $h($l['data_evento']) ?></td>
<td><?= $l['status'] === 'liberado' ? '<span class="badge bg-success">liberado</span><div class="small text-muted">' . $h($l['liberado_em']) . ' · ' . $h($l['liberado_por']) . '</div>' : '<span class="badge bg-warning text-dark">aguardando pagamento</span>' ?></td>
<td class="small"><?= $h($l['solicitado_em'] ?: '—') ?><?= $l['solicitado_por'] ? ' · ' . $h($l['solicitado_por']) : '' ?></td>
<td class="text-end"><form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= $h($_SESSION['csrf_token']) ?>"><input type="hidden" name="evento_id" value="<?= (int)$l['evento_id'] ?>">
<?php if ($l['status'] === 'liberado'): ?><button name="acao" value="bloquear" class="btn btn-sm btn-outline-danger" onclick="return confirm('Bloquear o acesso deste casal?')">Bloquear</button>
<?php else: ?><button name="acao" value="liberar" class="btn btn-sm btn-success" onclick="return confirm('Confirma que o pagamento foi recebido?')">Liberar</button><?php endif; ?></form></td></tr>
<?php endforeach; if (!$linhas): ?><tr><td colspan="6" class="text-center text-muted py-4">Nenhum pedido ainda.</td></tr><?php endif; ?>
</tbody></table></div></div></body></html>
