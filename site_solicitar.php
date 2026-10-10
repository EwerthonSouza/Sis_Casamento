<?php
// Pedido de contratação do site do casamento (botão "Quero contratar" do modal "Meu site").
// Só registra o pedido e avisa a Central; quem libera o acesso é a Central, depois do pagamento
// (ver site_liberar.php). Responde sempre em JSON.
session_start();
require_once 'sessao_timeout.inc.php';
verificar_sessao_ativa();
require_once 'conexao.php';
require_once 'tenant.php';
require_once 'config/central.php';
require_once 'site_convite.inc.php';

header('Content-Type: application/json; charset=utf-8');
function resp(bool $ok, string $msg, array $extra = []): void { echo json_encode(['ok' => $ok, 'msg' => $msg] + $extra, JSON_UNESCAPED_UNICODE); exit; }

$tipo = $_SESSION['usuario_tipo'] ?? '';
$eh_equipe = in_array($tipo, ['admin', 'assistente', 'desenvolvedor'], true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || (!$eh_equipe && $tipo !== 'noivos')) resp(false, 'Acesso negado.');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) resp(false, 'Sessão expirada. Recarregue a página.');

$evento_id = $eh_equipe ? (int)($_POST['evento_id'] ?? 0) : (int)($_SESSION['evento_id'] ?? 0);
$stmt = $pdo->prepare("SELECT e.*, c.nome AS nome_cliente FROM eventos e INNER JOIN clientes c ON c.id = e.cliente_id WHERE e.id = ?");
$stmt->execute([$evento_id]);
$evento = $stmt->fetch();
if (!$evento) resp(false, 'Evento não encontrado.');
if ($eh_equipe) {
    $modulo_ativo = $_SESSION['modulo_ativo'] ?? null;
    if (!$modulo_ativo || $evento['tipo_evento'] !== $modulo_ativo || !eh_registro_da_assessoria_atual($evento['assessoria_id'] ?? null)) resp(false, 'Acesso negado a este evento.');
} elseif ((int)$evento['cliente_id'] !== (int)($_SESSION['usuario_id'] ?? 0)) {
    resp(false, 'Acesso negado a este evento.');
}

site_garantir_schema($pdo);
$acesso = site_acesso($pdo, $evento_id);
if ($acesso['status'] === 'liberado') resp(true, 'O site já está liberado.', ['status' => 'liberado']);
if ($acesso['status'] === 'solicitado') resp(true, 'O pedido já foi enviado e está aguardando a confirmação do pagamento.', ['status' => 'solicitado']);

$quem = $eh_equipe ? 'assessoria' : 'noivos';
$pdo->prepare("INSERT INTO site_convite_acesso (evento_id, status, preco, solicitado_em, solicitado_por) VALUES (?, 'solicitado', ?, NOW(), ?)
               ON DUPLICATE KEY UPDATE status = 'solicitado', preco = VALUES(preco), solicitado_em = NOW(), solicitado_por = VALUES(solicitado_por)")
    ->execute([$evento_id, site_preco(), $quem]);

// Avisa a Central (só um INSERT local na fila; o cron de eventos entrega)
centralQueueEvent($pdo, 'site.requested', ['evento_id' => $evento_id, 'cliente' => $evento['nome_cliente'], 'solicitado_por' => $quem, 'preco' => site_preco()]);

resp(true, 'Pedido enviado! Assim que o pagamento for confirmado, o botão "Meu site" será liberado.', ['status' => 'solicitado']);
