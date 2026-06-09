<?php
session_start();
require_once 'conexao.php';

$erro = "";

function validarSenha($senhaDigitada, $senhaBanco)
{
    return $senhaDigitada === $senhaBanco || password_verify($senhaDigitada, $senhaBanco);
}

function criarSessao($tipo, $id, $nome)
{
    $_SESSION['usuario_tipo'] = $tipo;
    $_SESSION['usuario_id'] = $id;
    $_SESSION['usuario_nome'] = $nome;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $usuario_input = trim($_POST['usuario'] ?? '');
    $senha_input = trim($_POST['senha'] ?? '');

    if (!empty($usuario_input) && !empty($senha_input)) {

        // EQUIPE (ADMIN / ASSISTENTE)
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ?");
        $stmt->execute([$usuario_input]);
        $equipe = $stmt->fetch();

        if ($equipe) {

            if (validarSenha($senha_input, $equipe['senha'])) {

                session_regenerate_id(true);

                criarSessao(
                    $equipe['tipo'],
                    $equipe['id'],
                    $equipe['nome']
                );

                header("Location: painel_admin.php");
                exit;
            }

            $erro = "Senha incorreta.";
        } else {

            // ADMINISTRADOR ANTIGO
            $stmt = $pdo->prepare("SELECT * FROM administradores WHERE usuario = ?");
            $stmt->execute([$usuario_input]);
            $admin = $stmt->fetch();

            if ($admin) {

                if (validarSenha($senha_input, $admin['senha'])) {

                    session_regenerate_id(true);

                    criarSessao(
                        'admin',
                        $admin['id'],
                        'Assessoria Geral'
                    );

                    header("Location: painel_admin.php");
                    exit;
                }

                $erro = "Senha incorreta.";
            } else {

                // NOIVOS
                $stmt = $pdo->prepare("
                    SELECT c.*, e.id AS evento_id
                    FROM clientes c
                    LEFT JOIN eventos e ON e.cliente_id = c.id
                    WHERE c.email = ?
                ");

                $stmt->execute([$usuario_input]);
                $cliente = $stmt->fetch();

                if ($cliente) {

                    if (validarSenha($senha_input, $cliente['senha'])) {

                        if (empty($cliente['evento_id'])) {

                            $erro = "Nenhum evento vinculado ao cadastro.";

                        } else {

                            session_regenerate_id(true);

                            $_SESSION['usuario_tipo'] = 'noivos';
                            $_SESSION['usuario_id'] = $cliente['id'];
                            $_SESSION['evento_id'] = $cliente['evento_id'];
                            $_SESSION['usuario_nome'] = $cliente['nome'] ?? 'Casal';

                            header("Location: noivos.php?id=" . $cliente['evento_id']);
                            exit;
                        }

                    } else {
                        $erro = "Senha incorreta.";
                    }

                } else {
                    $erro = "Usuário não encontrado.";
                }
            }
        }

    } else {
        $erro = "Preencha todos os campos.";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Login - Gestão de Eventos</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<style>

body{
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    background:
    linear-gradient(
        135deg,
        #7a1308 0%,
        #aa2710 50%,
        #d63f21 100%
    );
    overflow:hidden;
}

.bg-shape{
    position:absolute;
    width:500px;
    height:500px;
    border-radius:50%;
    background:rgba(255,255,255,.05);
    filter:blur(20px);
}

.shape1{
    top:-200px;
    left:-100px;
}

.shape2{
    bottom:-250px;
    right:-100px;
}

.login-card{
    width:100%;
    max-width:450px;
    border:none;
    border-radius:25px;
    overflow:hidden;
    backdrop-filter:blur(20px);
    animation:fadeIn .7s ease;
}

@keyframes fadeIn{
    from{
        opacity:0;
        transform:translateY(30px);
    }
    to{
        opacity:1;
        transform:translateY(0);
    }
}

.logo-circle{
    width:120px;
    height:120px;
    border-radius:50%;
    background:white;
    display:flex;
    align-items:center;
    justify-content:center;
    margin:auto;
    box-shadow:0 10px 30px rgba(0,0,0,.25);
}

.logo-circle i{
    font-size:4rem;
    color:#aa2710;
}

.login-title{
    color:white;
    text-align:center;
    margin-top:20px;
}

.login-title h2{
    font-weight:700;
    margin-bottom:5px;
}

.login-title p{
    opacity:.85;
}

.card-body{
    padding:35px;
}

.form-control{
    border-radius:12px;
    padding:12px;
}

.input-group-text{
    border-radius:12px 0 0 12px;
}

.btn-login{
    background:#aa2710;
    border:none;
    border-radius:12px;
    padding:12px;
    font-weight:600;
    transition:.3s;
}

.btn-login:hover{
    background:#891a08;
    transform:translateY(-2px);
}

.footer-text{
    text-align:center;
    color:rgba(255,255,255,.8);
    margin-top:20px;
    font-size:.85rem;
}

.toggle-password{
    cursor:pointer;
}

</style>
</head>
<body>

<div class="bg-shape shape1"></div>
<div class="bg-shape shape2"></div>

<div class="container">

    <div class="text-center mb-4">

        <div class="logo-circle">
            <i class="bi bi-calendar-heart-fill"></i>
        </div>

        <div class="login-title">
            <h2>Cerimonial & Assessoria</h2>
            <p>Teste rick</p>
        </div>

    </div>

    <div class="card shadow-lg login-card mx-auto">

        <div class="card-body">

            <h4 class="text-center mb-4 fw-bold">
                Acessar Sistema
            </h4>

            <?php if(!empty($erro)): ?>
                <div class="alert alert-danger text-center">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?= htmlspecialchars($erro) ?>
                </div>
            <?php endif; ?>

            <form method="POST">

                <div class="mb-3">

                    <label class="form-label fw-semibold">
                        Usuário ou E-mail
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-person-fill"></i>
                        </span>

                        <input
                            type="text"
                            name="usuario"
                            class="form-control"
                            required
                            autofocus
                            placeholder="Digite seu usuário ou e-mail">

                    </div>

                </div>

                <div class="mb-3">

                    <label class="form-label fw-semibold">
                        Senha
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-lock-fill"></i>
                        </span>

                        <input
                            type="password"
                            name="senha"
                            id="senha"
                            class="form-control"
                            required
                            placeholder="Digite sua senha">

                        <span
                            class="input-group-text toggle-password"
                            onclick="toggleSenha()">

                            <i id="iconeSenha" class="bi bi-eye-fill"></i>

                        </span>

                    </div>

                </div>

                <div class="form-check mb-4">

                    <input
                        class="form-check-input"
                        type="checkbox"
                        id="lembrar">

                    <label class="form-check-label" for="lembrar">
                        Lembrar acesso
                    </label>

                </div>

                <button class="btn btn-login text-white w-100">

                    Entrar no Sistema
                    <i class="bi bi-box-arrow-in-right ms-1"></i>

                </button>

            </form>

        </div>

    </div>

    <div class="footer-text">

        © <?= date('Y') ?> Cerimonial & Assessoria<br>
        Sistema de Gestão de Eventos

    </div>

</div>

<script>

function toggleSenha(){

    const campo = document.getElementById('senha');
    const icone = document.getElementById('iconeSenha');

    if(campo.type === 'password'){

        campo.type = 'text';
        icone.classList.remove('bi-eye-fill');
        icone.classList.add('bi-eye-slash-fill');

    }else{

        campo.type = 'password';
        icone.classList.remove('bi-eye-slash-fill');
        icone.classList.add('bi-eye-fill');

    }
}

</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>