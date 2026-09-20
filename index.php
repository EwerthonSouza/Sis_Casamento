<?php
session_start();
require_once 'conexao.php';
require_once __DIR__ . '/config/central.php';
garantir_coluna_ultimo_login_usuarios($pdo);

// Evita que o navegador guarde esta página em cache, já causou telas
// desatualizadas aparecerem depois de mudanças no sistema.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$erro = "";
$aviso_sessao_expirada = isset($_GET['sessao_expirada']);

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

                // Se ultimo_login ainda tá vazio, é o primeiro login desse usuário —
                // guarda isso na sessão pra saudação do hub não dizer "de volta" à toa.
                $_SESSION['primeiro_acesso'] = empty($equipe['ultimo_login']);
                $pdo->prepare("UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?")->execute([$equipe['id']]);

                centralQueueEvent($pdo, 'user.login', [
                    'external_id' => (string) $equipe['id'],
                    'name' => $equipe['nome'] ?? null,
                    'email' => $equipe['email'] ?? null,
                ]);

                if ($equipe['tipo'] === 'desenvolvedor') {
                    header("Location: dev_painel.php");
                } else {
                    header("Location: hub_modulos.php");
                }
                exit;
            }

            $erro = "Senha incorreta.";
        } else {

            // NOIVOS
            $stmt = $pdo->prepare("SELECT * FROM clientes WHERE email = ?");
            $stmt->execute([$usuario_input]);
            $cliente = $stmt->fetch();

            if ($cliente) {

                if (validarSenha($senha_input, $cliente['senha'])) {

                    $stmt_eventos = $pdo->prepare("SELECT id FROM eventos WHERE cliente_id = ? ORDER BY data_evento DESC");
                    $stmt_eventos->execute([$cliente['id']]);
                    $eventos_cliente = $stmt_eventos->fetchAll(PDO::FETCH_COLUMN);

                    if (empty($eventos_cliente)) {

                        $erro = "Nenhum evento vinculado ao cadastro.";

                    } else {

                        session_regenerate_id(true);

                        $_SESSION['usuario_tipo'] = 'noivos';
                        $_SESSION['usuario_id'] = $cliente['id'];
                        $_SESSION['usuario_nome'] = $cliente['nome'] ?? 'Casal';

                        centralQueueEvent($pdo, 'user.login', [
                            'external_id' => (string) $cliente['id'],
                            'name' => $cliente['nome'] ?? null,
                            'email' => $cliente['email'] ?? null,
                        ]);

                        if (count($eventos_cliente) === 1) {
                            $_SESSION['evento_id'] = $eventos_cliente[0];
                            header("Location: noivos.php?id=" . $eventos_cliente[0]);
                        } else {
                            header("Location: hub_eventos_cliente.php");
                        }
                        exit;
                    }

                } else {
                    $erro = "Senha incorreta.";
                }

            } else {
                $erro = "Usuário não encontrado.";
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
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php include __DIR__ . '/pwa_head.inc.php'; ?>

<title>Login - Meu Evento PRO</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="css/estilo.css?v=15">

<style>

/* iOS (PWA instalado, tela cheia) às vezes deixa uma faixa sólida embaixo
   porque 100dvh não cobre a área do indicador de home nesse modo — o body
   fica um pouco menor que a tela real e sobra o fundo do <html> aparecendo.
   Por isso o <html> recebe a mesma imagem do body, não só a cor sólida:
   se sobrar aquela faixa, ela mostra a foto em vez de um retângulo liso. */
html{
    background-color:#6f4a2f;
    background-image: url('img/fundo_login.webp');
    background-repeat:no-repeat;
    background-position:center bottom;
    background-size:cover;
}

body{
    min-height:100vh;
    min-height:100dvh;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:calc(20px + env(safe-area-inset-top)) 15px calc(20px + env(safe-area-inset-bottom));
    position:relative;
    overflow-x:hidden;
    background-color:#6f4a2f;
    background-image: url('img/fundo_login.webp');
    background-repeat:no-repeat;
    background-attachment:fixed;
    background-position:center;
    background-size:cover;
}

.bg-shape{ display:none; }

@media (max-width:767.98px){
    body{ background-attachment:scroll; background-image: url('img/fundo_login_mobile.jpg'); }
    html{ background-image: url('img/fundo_login_mobile.jpg'); }

    .login-card{ max-width:340px; border-radius:20px; }

    .card-body{ padding:22px 20px; }

    .card-body h4{ font-size:1.15rem; margin-bottom:1rem !important; }

    .card-body .form-label{ font-size:.82rem; margin-bottom:.3rem; }

    .card-body .form-control{ padding:9px 10px; font-size:16px; }

    .card-body .input-group-text{ padding:9px 10px; }

    .card-body .mb-3{ margin-bottom:.8rem !important; }

    .card-body .form-check.mb-4{ margin-bottom:.9rem !important; }

    .card-body .form-check-label{ font-size:.85rem; }

    .btn-login{ padding:9px; font-size:.9rem; }
}

.bg-shape{
    position:fixed;
    width:500px;
    height:500px;
    border-radius:50%;
    background:rgba(255,255,255,.05);
    filter:blur(20px);
    pointer-events:none;
    z-index:0;
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
    position:relative;
    z-index:1;
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

@keyframes aproximar{
    from{
        opacity:0;
        transform:scale(.55);
    }
    to{
        opacity:1;
        transform:scale(1);
    }
}

.logo-card{
    display:inline-block;
    margin:auto;
    animation:aproximar .8s ease-out;
    transition:transform .3s ease;
    cursor:pointer;
}

.logo-card:hover{
    transform:scale(1.06);
}

.logo-card img{
    display:block;
    width:230px;
    max-width:62vw;
    height:auto;
}

@media (min-width:768px){
    .logo-card img{
        width:280px;
    }
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
    background:var(--color-primary-dark);
    border:none;
    border-radius:12px;
    padding:12px;
    font-weight:600;
    transition:.3s;
}

.btn-login:hover{
    background:#6f4a2f;
    transform:translateY(-2px);
}

.footer-text{
    text-align:center;
    color:rgba(255,255,255,.8);
    margin-top:20px;
    font-size:.85rem;
    position:relative;
    z-index:1;
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

    <div class="text-center" style="margin-bottom:-8px;">

        <div class="logo-card">
            <img src="img/logo-login.png" alt="Meu Evento PRO — Sistema de Gestão de Casamentos">
        </div>

    </div>

    <div class="card shadow-lg login-card mx-auto">

        <div class="card-body">

            <?php if($aviso_sessao_expirada): ?>
                <div class="alert alert-warning text-center">
                    <i class="bi bi-clock-history"></i>
                    Sua sessão expirou por inatividade. Faça login novamente.
                </div>
            <?php endif; ?>

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
                            id="campoUsuario"
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

                            <i id="iconeSenha" class="bi bi-eye-slash-fill"></i>

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

                    Entrar
                    <i class="bi bi-box-arrow-in-right ms-1"></i>

                </button>

            </form>

        </div>

    </div>

    <div class="footer-text">

        © <?= date('Y') ?> Meu Evento PRO<br>
        Sistema de Gestão de Eventos

    </div>

</div>

<script>

function toggleSenha(){

    const campo = document.getElementById('senha');
    const icone = document.getElementById('iconeSenha');

    if(campo.type === 'password'){

        campo.type = 'text';
        icone.classList.remove('bi-eye-slash-fill');
        icone.classList.add('bi-eye-fill');

    }else{

        campo.type = 'password';
        icone.classList.remove('bi-eye-fill');
        icone.classList.add('bi-eye-slash-fill');

    }
}

</script>

</body>
</html>