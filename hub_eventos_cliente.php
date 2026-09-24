<?php
session_start();
require_once 'sessao_timeout.inc.php';
verificar_sessao_ativa();

require_once 'conexao.php';
require_once 'modulos_evento.inc.php';

// ============================================================
// TRAVA DE SEGURANÇA: só clientes (noivos/aniversariante/empresa/instituição)
// ============================================================
if (!isset($_SESSION['usuario_tipo']) || $_SESSION['usuario_tipo'] !== 'noivos') {
    header("Location: index.php?sessao_expirada=1");
    exit;
}

garantir_coluna_tipo_evento($pdo);
garantir_tabela_modulos_config($pdo);

$cliente_id = (int)$_SESSION['usuario_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Salvar a cor de identidade visual escolhida para um dos eventos do próprio cliente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_cor') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Sessão inválida. Recarregue a página e tente novamente.");
    }
    $evento_cor_id = (int)($_POST['evento_id'] ?? 0);
    $cor_escolhida = trim($_POST['cor'] ?? '');
    if ($evento_cor_id > 0 && preg_match('/^#[0-9a-fA-F]{6}$/', $cor_escolhida)) {
        // Só permite alterar a cor de um evento que realmente pertence a este cliente
        $pdo->prepare("UPDATE eventos SET cor_convite = ? WHERE id = ? AND cliente_id = ?")
            ->execute([$cor_escolhida, $evento_cor_id, $cliente_id]);
    }
    header("Location: hub_eventos_cliente.php");
    exit;
}

// Escolha de qual evento entrar: sempre valida que o evento pertence a este cliente
if (isset($_GET['evento'])) {
    $evento_escolhido = (int)$_GET['evento'];
    $stmt_dono = $pdo->prepare("SELECT id FROM eventos WHERE id = ? AND cliente_id = ?");
    $stmt_dono->execute([$evento_escolhido, $cliente_id]);
    if ($stmt_dono->fetch()) {
        $_SESSION['evento_id'] = $evento_escolhido;
        header("Location: noivos.php?id=" . $evento_escolhido);
        exit;
    }
}

$stmt_eventos = $pdo->prepare("SELECT * FROM eventos WHERE cliente_id = ? ORDER BY data_evento DESC");
$stmt_eventos->execute([$cliente_id]);
$eventos_cliente = $stmt_eventos->fetchAll(PDO::FETCH_ASSOC);

// Sem eventos ou só um: não há o que escolher, segue direto (defensivo)
if (empty($eventos_cliente)) {
    header("Location: index.php?sessao_expirada=1");
    exit;
}
if (count($eventos_cliente) === 1) {
    $_SESSION['evento_id'] = $eventos_cliente[0]['id'];
    header("Location: noivos.php?id=" . $eventos_cliente[0]['id']);
    exit;
}

$nome_cliente = $_SESSION['usuario_nome'] ?? 'Cliente';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Eventos - Meu Evento PRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/estilo.css?v=16">
    <style>
        .card-evento {
            text-decoration: none;
            color: inherit;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            border: 1px solid #e9ecef;
        }
        .card-evento:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 24px rgba(0,0,0,.12);
        }
        .icone-evento {
            width: 64px; height: 64px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 50%;
            font-size: 1.6rem;
            color: #fff;
        }
        .form-cor-evento { z-index: 2; }
        .form-cor-evento .form-control-color { width: 2.2rem; height: 1.8rem; padding: .15rem; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark shadow-sm">
    <div class="container flex-nowrap">
        <span class="navbar-brand flex-shrink-0">
            <img src="img/LOGO MEP NAV.svg" alt="Meu Evento PRO" class="logo-nav-admin" style="height:40px;">
        </span>
        <div class="d-flex align-items-center gap-2">
            <span class="text-white small d-none d-md-block text-nowrap">
                <i class="bi bi-person-circle"></i> Olá, <strong><?= htmlspecialchars($nome_cliente, ENT_QUOTES, 'UTF-8') ?></strong>
            </span>
            <a href="logout.php" class="btn btn-sm btn-outline-light"><i class="bi bi-box-arrow-right"></i> Sair</a>
        </div>
    </div>
</nav>

<div class="container my-5">
    <div class="text-center mb-5">
        <h2 class="fw-bold">Qual dos seus eventos você quer acessar?</h2>
        <p class="text-muted">Escolha um evento para continuar. Você pode personalizar a cor do painel de cada um e trocar entre eles a qualquer momento.</p>
    </div>

    <div class="row g-4 justify-content-center">
        <?php foreach ($eventos_cliente as $ev):
            $labels = labels_modulo_evento($ev['tipo_evento']);
            $cor_atual = cor_painel_evento($pdo, $ev);
            $icones_modulo = [
                'casamento'   => 'bi-heart-fill',
                'aniversario' => 'bi-balloon-fill',
                'corporativo' => 'bi-briefcase-fill',
                'academico'   => 'bi-mortarboard-fill',
            ];
            $icone = $icones_modulo[$ev['tipo_evento']] ?? 'bi-calendar-event-fill';
        ?>
        <div class="col-6 col-lg-3">
            <div class="card card-evento h-100 text-center p-4 position-relative">
                <a href="hub_eventos_cliente.php?evento=<?= (int)$ev['id'] ?>" class="text-decoration-none text-reset stretched-link">
                    <div class="icone-evento mx-auto mb-3" style="background-color: <?= htmlspecialchars($cor_atual) ?>;">
                        <i class="bi <?= htmlspecialchars($icone) ?>"></i>
                    </div>
                    <h5 class="fw-bold mb-1"><?= htmlspecialchars($labels['nome_modulo']) ?></h5>
                    <span class="text-muted small">
                        <?= !empty($ev['data_evento']) ? date('d/m/Y', strtotime($ev['data_evento'])) : 'Data a definir' ?>
                    </span>
                </a>
                <div class="mt-3 pt-3 border-top position-relative form-cor-evento">
                    <form method="POST" action="hub_eventos_cliente.php" class="d-flex align-items-center justify-content-center gap-2">
                        <input type="hidden" name="acao" value="salvar_cor">
                        <input type="hidden" name="evento_id" value="<?= (int)$ev['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <label class="small text-muted mb-0" for="cor-evento-<?= (int)$ev['id'] ?>">Cor do painel</label>
                        <input type="color" id="cor-evento-<?= (int)$ev['id'] ?>" name="cor" value="<?= htmlspecialchars($cor_atual) ?>" class="form-control form-control-color form-control-sm" title="Escolher a cor deste painel">
                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Salvar cor"><i class="bi bi-check-lg"></i></button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
