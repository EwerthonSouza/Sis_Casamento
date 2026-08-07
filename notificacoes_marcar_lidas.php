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

if ($usuario_id > 0) {
    $pdo->prepare("
        INSERT INTO notificacoes_lidas (usuario_tipo, usuario_id, ultima_visualizacao)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE ultima_visualizacao = NOW()
    ")->execute([$usuario_tipo, $usuario_id]);
}

echo json_encode(['ok' => true]);
