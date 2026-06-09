<?php
session_start();
require_once 'conexao.php';

// ============================================================
// AUTO-CRIAR TABELA DE USUÁRIOS (CASO NÃO EXISTA)
// ============================================================
try {
    $pdo->query("SELECT 1 FROM usuarios LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE usuarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            email VARCHAR(100) NOT NULL UNIQUE,
            senha VARCHAR(255) NOT NULL,
            tipo VARCHAR(20) DEFAULT 'assistente',
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}

// ============================================================
// TRAVA DE SEGURANÇA: Apenas ADMIN acessa esta tela
// ============================================================
if (!isset($_SESSION['usuario_tipo']) || $_SESSION['usuario_tipo'] !== 'admin') {
    header("Location: painel_admin.php");
    exit;
}

$msg_sucesso = '';
$msg_erro = '';

// ============================================================
// ADICIONAR USUÁRIO
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adicionar_usuario'])) {
    $nome  = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $senha = trim($_POST['senha'] ?? '');
    $tipo  = $_POST['tipo'] ?? 'assistente';

    if (!empty($nome) && !empty($email) && !empty($senha)) {
        $senha_hash = password_hash($senha, PASSWORD_BCRYPT);
        try {
            $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, senha, tipo) VALUES (?, ?, ?, ?)");
            $stmt->execute([$nome, $email, $senha_hash, $tipo]);
            $msg_sucesso = "Usuário '$nome' cadastrado com sucesso!";
        } catch (Exception $e) {
            $msg_erro = "Erro ao cadastrar. O e-mail '$email' já pode estar em uso.";
        }
    } else {
        $msg_erro = "Preencha todos os campos.";
    }
}

// ============================================================
// EXCLUIR USUÁRIO
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['excluir_usuario'])) {
    $id_excluir = (int)$_POST['id_usuario'];
    
    try {
        $pdo->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id_excluir]);
        $msg_sucesso = "Usuário removido do sistema!";
    } catch (Exception $e) {
        $msg_erro = "Erro ao excluir o usuário.";
    }
}

// ============================================================
// BUSCAR LISTA DE USUÁRIOS
// ============================================================
$lista_usuarios = $pdo->query("SELECT id, nome, email, tipo FROM usuarios ORDER BY nome ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Equipe</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark shadow-sm">
    <div class="container">
        <span class="navbar-brand fw-bold text-primary">
            <i class="bi bi-people-fill text-warning"></i> Gestão de Equipe
        </span>
        <a href="painel_admin.php" class="btn btn-sm btn-outline-light">
            <i class="bi bi-arrow-left"></i> Voltar ao Painel
        </a>
    </div>
</nav>

<div class="container my-5">
    
    <?php if ($msg_sucesso): ?>
        <div class="alert alert-success fw-bold shadow-sm"><i class="bi bi-check-circle-fill me-2"></i><?= $msg_sucesso ?></div>
    <?php endif; ?>
    <?php if ($msg_erro): ?>
        <div class="alert alert-danger fw-bold shadow-sm"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= $msg_erro ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-bottom p-4 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold"><i class="bi bi-person-lines-fill text-secondary me-2"></i>Usuários do Sistema</h5>
            <button class="btn btn-success fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalAdicionar">
                <i class="bi bi-plus-circle me-1"></i> Novo Usuário
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light text-muted small text-uppercase">
                        <tr>
                            <th class="ps-4">Nome</th>
                            <th>E-mail (Login)</th>
                            <th>Nível de Acesso</th>
                            <th class="text-center pe-4">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lista_usuarios as $user): ?>
                        <tr>
                            <td class="ps-4 fw-bold text-dark"><?= htmlspecialchars($user['nome']) ?></td>
                            <td class="text-muted"><?= htmlspecialchars($user['email']) ?></td>
                            <td>
                                <?php if ($user['tipo'] === 'admin'): ?>
                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-3 py-2 rounded-pill">Admin</span>
                                <?php else: ?>
                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary px-3 py-2 rounded-pill">Assistente</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center pe-4">
                                <form method="POST" onsubmit="return confirm('Tem certeza que deseja excluir o acesso de <?= htmlspecialchars($user['nome']) ?>?');">
                                    <input type="hidden" name="excluir_usuario" value="1">
                                    <input type="hidden" name="id_usuario" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir Usuário">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($lista_usuarios)): ?>
                        <tr><td colspan="4" class="text-center py-4 text-muted">Nenhum usuário encontrado. Adicione o primeiro no botão acima!</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAdicionar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-dark text-white border-0 rounded-top-4">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-plus text-warning me-2"></i>Cadastrar Membro</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="adicionar_usuario" value="1">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold text-secondary small">Nome Completo</label>
                        <input type="text" name="nome" class="form-control bg-light" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-secondary small">E-mail de Login</label>
                        <input type="email" name="email" class="form-control bg-light" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-secondary small">Senha</label>
                            <input type="password" name="senha" class="form-control bg-light" required minlength="6">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-secondary small">Nível de Acesso</label>
                            <select name="tipo" class="form-select bg-light" required>
                                <option value="assistente">Assistente</option>
                                <option value="admin">Administrador</option>
                            </select>
                        </div>
                    </div>
                    <p class="small text-muted mb-0"><i class="bi bi-info-circle me-1"></i>O Assistente não visualiza informações financeiras e valores.</p>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success fw-bold rounded-pill px-4 shadow-sm">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>