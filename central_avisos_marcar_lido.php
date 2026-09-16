<?php
session_start();
require_once 'conexao.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_tipo']) || $_SESSION['usuario_tipo'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
$avisoId = (int) ($_POST['id'] ?? 0);

if ($usuarioId > 0 && $avisoId > 0) {
    $pdo->prepare("
        INSERT INTO central_avisos_lidos (aviso_id, usuario_id, lido_em)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE lido_em = NOW()
    ")->execute([$avisoId, $usuarioId]);
}

echo json_encode(['ok' => true]);
