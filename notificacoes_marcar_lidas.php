<?php
session_start();
require_once 'conexao.php';
require_once 'notificacoes.inc.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_tipo']) || !in_array($_SESSION['usuario_tipo'], ['admin', 'assistente', 'noivos'])) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$usuario_tipo = $_SESSION['usuario_tipo'];
$usuario_id   = (int)($_SESSION['usuario_id'] ?? 0);

// Controle item a item (equipe): marca só a(s) chave(s) informada(s) como vista,
// deixando as demais notificações intactas pra continuar aparecendo.
// 'chave' = uma notificação só (clique num item); 'chaves' = várias de uma vez,
// separadas por vírgula (botão "Marcar todas lidas" daquele módulo/evento).
if ($usuario_id > 0) {
    $chaves = [];
    if (!empty($_REQUEST['chave'])) {
        $chaves[] = trim($_REQUEST['chave']);
    }
    if (!empty($_REQUEST['chaves'])) {
        $chaves = array_merge($chaves, explode(',', $_REQUEST['chaves']));
    }
    if (!empty($chaves)) {
        marcar_notificacoes_vistas($pdo, $usuario_tipo, $usuario_id, $chaves);
    }
}

// Escopo (compat): usado só pelo sino do portal do cliente, que ainda funciona
// por "última data vista" em vez de item a item.
if ($usuario_id > 0 && !empty($_REQUEST['escopo'])) {
    $escopo = trim($_REQUEST['escopo']);
    if ($escopo !== '' && strlen($escopo) <= 50) {
        $pdo->prepare("
            INSERT INTO notificacoes_lidas (usuario_tipo, usuario_id, escopo, ultima_visualizacao)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE ultima_visualizacao = NOW()
        ")->execute([$usuario_tipo, $usuario_id, $escopo]);
    }
}

echo json_encode(['ok' => true]);
