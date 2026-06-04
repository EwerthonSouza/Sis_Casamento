<?php
session_start();
require_once 'conexao.php';

$erro = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario_input = trim($_POST['usuario']);
    $senha_input = trim($_POST['senha']);

    if (!empty($usuario_input) && !empty($senha_input)) {
        
        // 1. TESTA SE É O ADMINISTRADOR
        $stmt = $pdo->prepare("SELECT * FROM administradores WHERE usuario = ?");
        $stmt->execute([$usuario_input]);
        $admin = $stmt->fetch();

        if ($admin && ($senha_input === $admin['senha'] || password_verify($senha_input, $admin['senha']))) { 
            $_SESSION['usuario_tipo'] = 'admin';
            $_SESSION['usuario_id'] = $admin['id'];
            $_SESSION['usuario_nome'] = 'Assessoria Geral';
            
            header("Location: painel_admin.php");
            exit;
        }

        // 2. TESTA SE É UM CASAL DE NOIVOS (Com Debug de Sênior)
        $stmt = $pdo->prepare("SELECT c.*, e.id AS evento_id FROM clientes c 
                               LEFT JOIN eventos e ON e.cliente_id = c.id 
                               WHERE c.email = ?");
        $stmt->execute([$usuario_input]);
        $cliente = $stmt->fetch();

        if (!$cliente) {
            // Falhou na Etapa 1: O e-mail não existe no banco
            $erro = "DEBUG: E-mail não encontrado na tabela 'clientes'.";
        } elseif (!($senha_input === $cliente['senha'] || password_verify($senha_input, $cliente['senha']))) {
            // Falhou na Etapa 2: Achou o e-mail, mas a senha está errada
            $erro = "DEBUG: E-mail encontrado, mas a SENHA não confere.";
        } elseif (empty($cliente['evento_id'])) {
            // Falhou na Etapa 3: Logou, mas não tem evento
            $erro = "DEBUG: Login correto, mas o cliente ID " . $cliente['id'] . " NÃO possui nenhum evento vinculado na tabela 'eventos'.";
        } else {
            // SUCESSO!
            $_SESSION['usuario_tipo'] = 'noivos';
            $_SESSION['usuario_id'] = $cliente['id'];
            $_SESSION['evento_id'] = $cliente['evento_id'];
            $_SESSION['usuario_nome'] = $cliente['nome'] ?? 'Casal';

            header("Location: noivos.php?id=" . $cliente['evento_id']);
            exit;
        }
    } else {
        $erro = "Por favor, preencha todos os campos.";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso ao Sistema - Gestão de Eventos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <style>
        /* Força o fundo marrom escuro liso e remove qualquer imagem nesta tela */
        body {
            background-color: #140c79 !important;
            background-image: none !important;
            background-repeat: no-repeat !important;
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            
            <div class="text-center text-white mb-4">
                <i class="bi bi-calendar-heart-fill text-danger" style="font-size: 3.5rem;"></i>
                <h3 class="mt-2 fw-bold">Cerimonial & Assessoria</h3>
                <p class="opacity-75 small">Painel de Eventos Integrado</p>
            </div>

            <div class="card border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="card-body p-4 bg-white">
                    
                    <h5 class="text-center text-dark fw-bold mb-3">Identifique-se para entrar</h5>

                    <?php if (!empty($erro)): ?>
                        <div class="alert alert-danger py-2 small text-center">
                            <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($erro) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary">E-mail ou Usuário</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light text-muted"><i class="bi bi-person-fill"></i></span>
                                <input type="text" name="usuario" class="form-control" placeholder="admin ou o email dos noivos" required autofocus>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-bold text-secondary">Senha de Acesso</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light text-muted"><i class="bi bi-lock-fill"></i></span>
                                <input type="password" name="senha" class="form-control" placeholder="••••••••" required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 fw-bold shadow-sm py-2" style="border-radius: 8px;">
                            Entrar no Sistema <i class="bi bi-box-arrow-in-right ms-1"></i>
                        </button>
                    </form>

                </div>
            </div>
            
            <div class="text-center mt-4 text-white-50 small" style="font-size: 0.75rem;">
                Dica para testes locais:<br>
                <strong>Admin:</strong> usar <span class="badge bg-dark">admin</span> e senha <span class="badge bg-dark">admin123</span><br>
                <strong>Noivos:</strong> usar o e-mail cadastrado deles e senha padrão <span class="badge bg-dark">123456</span>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>