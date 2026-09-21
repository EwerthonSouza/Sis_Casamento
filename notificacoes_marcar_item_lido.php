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
$chave        = trim($_POST['chave'] ?? '');

if ($chave === '' || strlen($chave) > 60) {
    echo json_encode(['ok' => false]);
    exit;
}

marcar_item_lido_notificacao($pdo, $usuario_tipo, $usuario_id, $chave);

echo json_encode(['ok' => true]);
