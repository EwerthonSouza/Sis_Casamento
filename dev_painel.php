<?php
session_start();
require_once 'sessao_timeout.inc.php';
verificar_sessao_ativa();

require_once 'conexao.php';
require_once 'modulos_evento.inc.php';

// ============================================================
// TRAVA DE SEGURANÇA: só o desenvolvedor do sistema acessa esta página
// ============================================================
if (!isset($_SESSION['usuario_tipo']) || $_SESSION['usuario_tipo'] !== 'desenvolvedor') {
    header("Location: index.php?sessao_expirada=1");
    exit;
}

garantir_coluna_tipo_evento($pdo);
garantir_tabela_modulos_liberados($pdo);
garantir_coluna_criado_por($pdo);
garantir_tabela_solicitacoes_upgrade($pdo);
garantir_tabela_planos_modulo($pdo);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_liberacao') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Sessão inválida. Recarregue a página e tente novamente.");
    }
    $usuario_alvo_id = (int)($_POST['usuario_id'] ?? 0);

    // Só pode liberar módulos pra quem é realmente admin/assistente (nunca outro desenvolvedor)
    $stmt_check = $pdo->prepare("SELECT id FROM usuarios WHERE id = ? AND tipo IN ('admin', 'assistente')");
    $stmt_check->execute([$usuario_alvo_id]);
    if ($stmt_check->fetch()) {
        $modulos_marcados = $_POST['modulos'] ?? [];
        salvar_modulos_liberados_usuario($pdo, $usuario_alvo_id, is_array($modulos_marcados) ? $modulos_marcados : []);
        $_SESSION['msg_sucesso_dev'] = "Módulos atualizados.";
    }
    // Redireciona (Post-Redirect-Get) pra um F5 na página não reenviar o formulário.
    header("Location: dev_painel.php");
    exit;
}

// Atender (liberar) ou dispensar um pedido de upgrade vindo do hub
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atender_solicitacao') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Sessão inválida. Recarregue a página e tente novamente.");
    }
    $solicitacao_id = (int)($_POST['solicitacao_id'] ?? 0);
    $conceder = ($_POST['conceder'] ?? '') === '1';

    $stmt_sol = $pdo->prepare("SELECT usuario_id, tipo_evento FROM solicitacoes_upgrade WHERE id = ? AND atendido = 0");
    $stmt_sol->execute([$solicitacao_id]);
    $solicitacao = $stmt_sol->fetch(PDO::FETCH_ASSOC);

    if ($solicitacao) {
        if ($conceder) {
            adicionar_modulo_liberado_usuario($pdo, (int)$solicitacao['usuario_id'], $solicitacao['tipo_evento']);
            $_SESSION['msg_sucesso_dev'] = "Módulo liberado e pedido atendido.";
        } else {
            $_SESSION['msg_sucesso_dev'] = "Pedido dispensado.";
        }
        $pdo->prepare("UPDATE solicitacoes_upgrade SET atendido = 1 WHERE id = ?")->execute([$solicitacao_id]);
    }
    header("Location: dev_painel.php");
    exit;
}

// Criar um novo usuário de equipe (admin ou assistente) direto por aqui — mesmo
// e-mail/senha que já usam pra logar em index.php, sem precisar de um admin
// existente pra cadastrar o primeiro usuário de uma assessoria nova.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar_usuario') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Sessão inválida. Recarregue a página e tente novamente.");
    }
    $novo_nome = trim($_POST['nome'] ?? '');
    $novo_email = trim($_POST['email'] ?? '');
    $nova_senha = trim($_POST['senha'] ?? '');
    $novo_tipo = ($_POST['tipo'] ?? '') === 'admin' ? 'admin' : 'assistente';

    if (empty($novo_nome) || empty($novo_email) || strlen($nova_senha) < 6) {
        $_SESSION['msg_erro_dev'] = "Preencha nome, e-mail e uma senha com pelo menos 6 caracteres.";
    } else {
        try {
            $senha_hash = password_hash($nova_senha, PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO usuarios (nome, email, senha, tipo) VALUES (?, ?, ?, ?)")
                ->execute([$novo_nome, $novo_email, $senha_hash, $novo_tipo]);
            $_SESSION['msg_sucesso_dev'] = "Usuário '$novo_nome' criado! Já pode liberar os módulos dele abaixo.";
        } catch (Exception $e) {
            $_SESSION['msg_erro_dev'] = "Não deu pra criar. O e-mail '$novo_email' já pode estar em uso.";
        }
    }
    header("Location: dev_painel.php");
    exit;
}

// Preço (e promoção com período) de cada módulo — a promoção só entra em vigor
// se as duas datas forem preenchidas; sem elas, fica só no preço normal.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_plano') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Sessão inválida. Recarregue a página e tente novamente.");
    }
    $tipo_plano = $_POST['tipo_evento'] ?? '';
    $preco_normal_input = str_replace(',', '.', trim($_POST['preco_normal'] ?? ''));
    $preco_promo_input = str_replace(',', '.', trim($_POST['preco_promocional'] ?? ''));
    $inicio_input = trim($_POST['promocao_inicio'] ?? '');
    $fim_input = trim($_POST['promocao_fim'] ?? '');

    if (modulo_evento_valido($tipo_plano) && is_numeric($preco_normal_input) && (float)$preco_normal_input > 0) {
        $preco_promocional = (is_numeric($preco_promo_input) && (float)$preco_promo_input > 0) ? (float)$preco_promo_input : null;
        $promocao_inicio = !empty($inicio_input) ? $inicio_input : null;
        $promocao_fim = !empty($fim_input) ? $fim_input : null;
        // Promoção só vale se tiver preço promocional E as duas datas — do contrário, ignora o período.
        if (!$preco_promocional || !$promocao_inicio || !$promocao_fim) {
            $preco_promocional = null;
            $promocao_inicio = null;
            $promocao_fim = null;
        }
        salvar_plano_modulo($pdo, $tipo_plano, (float)$preco_normal_input, $preco_promocional, $promocao_inicio, $promocao_fim);
        $_SESSION['msg_sucesso_dev'] = "Preço atualizado.";
    } else {
        $_SESSION['msg_erro_dev'] = "Informe um preço normal válido.";
    }
    header("Location: dev_painel.php");
    exit;
}

$msg_sucesso = $_SESSION['msg_sucesso_dev'] ?? null;
$msg_erro = $_SESSION['msg_erro_dev'] ?? null;
unset($_SESSION['msg_sucesso_dev'], $_SESSION['msg_erro_dev']);

$stmt_pedidos = $pdo->query("
    SELECT s.id, s.tipo_evento, s.criado_em, u.nome, u.email
    FROM solicitacoes_upgrade s
    INNER JOIN usuarios u ON u.id = s.usuario_id
    WHERE s.atendido = 0
    ORDER BY s.criado_em ASC
");
$pedidos_pendentes = $stmt_pedidos->fetchAll(PDO::FETCH_ASSOC);

$stmt_usuarios = $pdo->query("SELECT id, nome, email, tipo, criado_por FROM usuarios WHERE tipo IN ('admin', 'assistente') ORDER BY nome ASC");
$usuarios_equipe = $stmt_usuarios->fetchAll(PDO::FETCH_ASSOC);

$todos_modulos = [
    ['tipo' => 'casamento',   'icone' => 'bi-heart-fill',         'variante' => 'danger',  'curto' => 'Casamento'],
    ['tipo' => 'aniversario', 'icone' => 'bi-balloon-fill', 'variante' => 'warning', 'curto' => 'Aniversário'],
    ['tipo' => 'corporativo', 'icone' => 'bi-briefcase-fill',     'variante' => 'primary', 'curto' => 'Corporativo'],
    ['tipo' => 'academico',   'icone' => 'bi-mortarboard-fill',   'variante' => 'success', 'curto' => 'Acadêmico'],
];

$planos_modulos = planos_todos_modulos($pdo);
foreach ($todos_modulos as &$mod_com_plano) {
    $mod_com_plano['plano'] = $planos_modulos[$mod_com_plano['tipo']];
}
unset($mod_com_plano);

function iniciais_nome(string $nome): string {
    $partes = preg_split('/\s+/', trim($nome));
    $iniciais = mb_strtoupper(mb_substr($partes[0] ?? '?', 0, 1));
    if (count($partes) > 1) {
        $iniciais .= mb_strtoupper(mb_substr(end($partes), 0, 1));
    }
    return $iniciais;
}

// Agrupa cada assistente sob o admin que o cadastrou (usuarios.criado_por) — assim,
// à medida que o sistema passar a atender várias assessorias/clientes diferentes,
// fica visualmente claro qual assistente pertence a qual admin. Assistentes sem
// vínculo registrado (cadastrados antes desse controle existir, ou órfãos) caem
// num grupo à parte no fim da página.
$admins_equipe = array_values(array_filter($usuarios_equipe, fn($u) => $u['tipo'] === 'admin'));
$ids_admins    = array_column($admins_equipe, 'id');

$assistentes_por_admin = array_fill_keys($ids_admins, []);
$assistentes_sem_admin = [];
foreach ($usuarios_equipe as $u) {
    if ($u['tipo'] !== 'assistente') continue;
    $dono_id = $u['criado_por'] !== null ? (int)$u['criado_por'] : null;
    if ($dono_id !== null && in_array($dono_id, $ids_admins, true)) {
        $assistentes_por_admin[$dono_id][] = $u;
    } else {
        $assistentes_sem_admin[] = $u;
    }
}

// 1 consulta pros módulos liberados de todo mundo, em vez de 1 por usuário
// nos três loops de renderização abaixo.
$liberados_por_usuario = modulos_liberados_todos_usuarios($pdo);

function render_bloco_usuario(array $usuario, array $liberados, array $todos_modulos, string $csrf_token): void {
    $eh_admin_usuario = ($usuario['tipo'] === 'admin');
    ?>
    <div class="linha-usuario d-flex flex-wrap align-items-center gap-2">
        <div class="avatar-usuario <?= $eh_admin_usuario ? 'admin' : 'assistente' ?>">
            <?= htmlspecialchars(iniciais_nome($usuario['nome'])) ?>
        </div>
        <div class="info-usuario">
            <div class="fw-bold text-truncate">
                <?= htmlspecialchars($usuario['nome'], ENT_QUOTES, 'UTF-8') ?>
                <span class="badge-tipo-usuario <?= $eh_admin_usuario ? 'admin' : 'assistente' ?> text-uppercase fw-bold ms-1">
                    <?= $eh_admin_usuario ? 'Admin' : 'Assist.' ?>
                </span>
            </div>
            <div class="text-muted text-truncate email-usuario"><?= htmlspecialchars($usuario['email'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>

        <form method="POST" action="dev_painel.php" class="d-flex align-items-center flex-wrap gap-1 ms-auto">
            <input type="hidden" name="acao" value="salvar_liberacao">
            <input type="hidden" name="usuario_id" value="<?= (int)$usuario['id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

            <div class="d-flex flex-wrap gap-1 grupo-modulos-compacto">
                <?php foreach ($todos_modulos as $mod):
                    $marcado = in_array($mod['tipo'], $liberados, true);
                    $id_check = 'mod-' . $mod['tipo'] . '-' . $usuario['id'];
                ?>
                <input type="checkbox" class="btn-check" id="<?= $id_check ?>" name="modulos[]" value="<?= htmlspecialchars($mod['tipo']) ?>" autocomplete="off" <?= $marcado ? 'checked' : '' ?>>
                <label class="btn btn-outline-<?= $mod['variante'] ?>" for="<?= $id_check ?>">
                    <i class="bi <?= htmlspecialchars($mod['icone']) ?>"></i>
                    <span><?= htmlspecialchars($mod['curto']) ?></span>
                </label>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="btn btn-salvar-liberacao btn-sm">
                <i class="bi bi-check-lg"></i>
            </button>
        </form>
    </div>
    <?php
}

$total_usuarios   = count($usuarios_equipe);
$total_admins     = count($admins_equipe);
$total_assistente = $total_usuarios - $total_admins;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel do Desenvolvedor - Meu Evento PRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/estilo.css?v=18">
    <style>
        :root {
            --dev-1: #1e1b4b;
            --dev-2: #4338ca;
            --dev-3: #6366f1;
        }
        body { background: #f1f2f9; font-family: 'Poppins', 'Inter', system-ui, sans-serif; }

        .navbar-dev {
            background: linear-gradient(120deg, var(--dev-1) 0%, var(--dev-2) 100%);
        }
        .navbar-dev .navbar-brand i { color: #a5b4fc; }

        .hero-dev {
            background: linear-gradient(135deg, var(--dev-1) 0%, var(--dev-2) 55%, var(--dev-3) 100%);
            border-radius: 24px;
            color: #fff;
            padding: 2.5rem 2rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 16px 40px rgba(30, 27, 75, .25);
        }
        .hero-dev::before {
            content: '';
            position: absolute; inset: 0;
            background-image: radial-gradient(circle at 85% 20%, rgba(255,255,255,.14) 0%, transparent 45%),
                               radial-gradient(circle at 10% 90%, rgba(255,255,255,.10) 0%, transparent 40%);
            pointer-events: none;
        }
        .hero-dev .icone-hero {
            width: 68px; height: 68px; border-radius: 20px;
            background: rgba(255,255,255,.14);
            border: 1px solid rgba(255,255,255,.25);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.9rem;
            backdrop-filter: blur(6px);
        }
        .chip-stat {
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.22);
            border-radius: 999px;
            padding: .45rem 1rem;
            backdrop-filter: blur(6px);
            font-size: .88rem;
        }
        .btn-novo-usuario-dev {
            background: #fff;
            color: var(--dev-2);
            font-weight: 700;
            border: none;
            border-radius: 999px;
            padding: .55rem 1.3rem;
            font-size: .9rem;
            transition: transform .15s ease, box-shadow .15s ease;
        }
        .btn-novo-usuario-dev:hover {
            color: var(--dev-2);
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(0,0,0,.2);
        }

        .card-usuario {
            border: none;
            border-radius: 16px;
            box-shadow: 0 4px 14px rgba(30, 27, 75, .06);
        }
        .avatar-usuario {
            width: 36px; height: 36px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: .78rem; color: #fff;
            flex-shrink: 0;
        }
        .avatar-usuario.admin { background: linear-gradient(135deg, #4338ca, #6366f1); }
        .avatar-usuario.assistente { background: linear-gradient(135deg, #0d9488, #14b8a6); }

        .linha-usuario { padding: .35rem 0; }
        .info-usuario { width: 200px; flex: 0 0 200px; }
        .info-usuario .fw-bold { font-size: .84rem; }
        .info-usuario .email-usuario { font-size: .72rem; }
        .linha-usuario form { flex: 1 1 auto; justify-content: flex-end; }

        .badge-tipo-usuario {
            font-size: .58rem;
            letter-spacing: .3px;
            padding: .12rem .4rem;
            border-radius: 999px;
            vertical-align: middle;
        }
        .badge-tipo-usuario.admin { background: #eef2ff; color: #4338ca; }
        .badge-tipo-usuario.assistente { background: #ecfdf9; color: #0d9488; }

        .grupo-modulos-compacto .btn-check + .btn {
            border-radius: 8px;
            font-size: .7rem;
            font-weight: 600;
            padding: .28rem .5rem;
            display: inline-flex; flex-direction: row; align-items: center; gap: .3rem;
            white-space: nowrap;
            transition: transform .15s ease;
        }
        .grupo-modulos-compacto .btn-check:checked + .btn { transform: scale(1.03); }
        .grupo-modulos-compacto .btn-check + .btn i { font-size: .85rem; }
        @media (max-width: 575.98px) {
            .grupo-modulos-compacto .btn-check + .btn span { display: none; }
        }

        .btn-salvar-liberacao {
            border-radius: 999px;
            font-weight: 700;
            background: linear-gradient(135deg, var(--dev-1), var(--dev-2));
            border: none;
            color: #fff;
            width: 32px; height: 32px;
            display: flex; align-items: center; justify-content: center;
            padding: 0;
            transition: transform .15s ease, box-shadow .15s ease;
        }
        .btn-salvar-liberacao:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(67, 56, 202, .35);
            color: #fff;
        }

        .alert-sucesso-dev {
            border: none;
            border-radius: 14px;
            background: #ecfdf5;
            color: #047857;
            font-weight: 600;
        }

        .rotulo-secao-equipe {
            font-size: .74rem;
            letter-spacing: .6px;
            color: #6366f1;
        }
        .card-assistente {
            background: #f8f8fd;
            border: 1px solid #ececf7;
            border-left: 3px solid #a5b4fc;
            border-radius: 14px;
        }
        .card-usuario.card-orfaos {
            border: 1px dashed #d8dae6;
            box-shadow: none;
        }
        .card-usuario.card-orfaos:hover { transform: none; box-shadow: none; }

        .chip-stat.chip-alerta {
            background: rgba(251, 191, 36, .22);
            border-color: rgba(251, 191, 36, .4);
        }
        .card-pedidos {
            border: none;
            border-radius: 16px;
            background: #fffaf0;
            border-left: 4px solid #f59e0b;
        }
        .linha-pedido { padding: .4rem 0; border-bottom: 1px dashed #f0e0bb; }
        .linha-pedido:last-child { border-bottom: none; }
        .badge-preco-pedido {
            background: #fff;
            border: 1px solid #f0e0bb;
            color: #b8860b;
            font-size: .72rem;
            font-weight: 700;
            padding: .15rem .5rem;
            border-radius: 999px;
            margin-left: .3rem;
        }
        .btn-liberar-pedido {
            background: #16a34a; color: #fff; border: none;
            border-radius: 999px; font-weight: 700; font-size: .78rem;
            padding: .4rem .9rem;
        }
        .btn-liberar-pedido:hover { background: #15803d; color: #fff; }
        .btn-dispensar-pedido {
            background: #fff; color: #6b7280; border: 1px solid #d1d5db;
            border-radius: 999px; padding: .4rem .6rem;
        }
        .btn-dispensar-pedido:hover { background: #f3f4f6; color: #374151; }

        .card-precos { border: none; border-radius: 16px; }
        .card-plano-modulo {
            background: #f8f8fd;
            border: 1px solid #ececf7;
            border-radius: 14px;
            height: 100%;
        }
        .label-campo-plano {
            font-size: .66rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #8b8e9a;
            margin-bottom: .15rem;
            display: block;
        }
        .selo-promo-ativa {
            background: linear-gradient(135deg, #f97316, #ef4444);
            color: #fff;
            font-size: .66rem;
            font-weight: 800;
            padding: .2rem .55rem;
            border-radius: 999px;
        }
        .btn-salvar-plano {
            border-radius: 999px;
            font-weight: 700;
            background: linear-gradient(135deg, var(--dev-1), var(--dev-2));
            border: none;
            color: #fff;
            padding: .35rem 1.1rem;
        }
        .btn-salvar-plano:hover { color: #fff; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark navbar-dev shadow-sm">
    <div class="container">
        <span class="navbar-brand mb-0 fw-bold"><i class="bi bi-braces-asterisk me-2"></i>Painel do Desenvolvedor</span>
        <a href="logout.php" class="btn btn-sm btn-outline-light rounded-pill px-3"><i class="bi bi-box-arrow-right"></i> Sair</a>
    </div>
</nav>

<div class="container my-5">

    <div class="hero-dev mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-4" style="position: relative; z-index: 1;">
            <div class="d-flex align-items-center gap-3">
                <div class="icone-hero"><i class="bi bi-shield-lock-fill"></i></div>
                <div>
                    <h2 class="fw-bold mb-1" style="letter-spacing:-.5px;">Liberação de módulos</h2>
                    <p class="mb-0 text-white-50">Escolha quais módulos cada usuário da equipe pode ver e administrar no hub dele.</p>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <span class="chip-stat"><i class="bi bi-people-fill me-1"></i><strong><?= $total_usuarios ?></strong> usuário(s)</span>
                <span class="chip-stat"><i class="bi bi-person-badge-fill me-1"></i><strong><?= $total_admins ?></strong> admin(s)</span>
                <span class="chip-stat"><i class="bi bi-person-workspace me-1"></i><strong><?= $total_assistente ?></strong> assistente(s)</span>
                <?php if (!empty($pedidos_pendentes)): ?>
                <span class="chip-stat chip-alerta"><i class="bi bi-bell-fill me-1"></i><strong><?= count($pedidos_pendentes) ?></strong> pedido(s) de upgrade</span>
                <?php endif; ?>
            </div>
        </div>
        <div style="position: relative; z-index: 1;" class="mt-4">
            <button type="button" class="btn btn-novo-usuario-dev" data-bs-toggle="modal" data-bs-target="#modalNovoUsuario">
                <i class="bi bi-person-plus-fill me-1"></i> Novo usuário
            </button>
        </div>
    </div>

    <?php if ($msg_sucesso): ?>
        <div class="alert alert-sucesso-dev py-3 px-4 mb-4"><i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($msg_sucesso) ?></div>
    <?php endif; ?>
    <?php if ($msg_erro): ?>
        <div class="alert alert-danger py-3 px-4 mb-4"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($msg_erro) ?></div>
    <?php endif; ?>

    <?php if (!empty($pedidos_pendentes)): ?>
    <div class="card card-pedidos p-3 mb-4">
        <div class="rotulo-secao-equipe fw-bold text-uppercase mb-2">
            <i class="bi bi-bell-fill me-1"></i> Pedidos de upgrade pendentes (<?= count($pedidos_pendentes) ?>)
        </div>
        <div class="d-flex flex-column gap-2">
            <?php foreach ($pedidos_pendentes as $pedido):
                $labels_pedido = labels_modulo_evento($pedido['tipo_evento']);
                $plano_pedido = $planos_modulos[$pedido['tipo_evento']];
            ?>
            <div class="linha-pedido d-flex flex-wrap align-items-center gap-2">
                <div class="flex-grow-1">
                    <span class="fw-bold"><?= htmlspecialchars($pedido['nome'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="text-muted small"> pediu </span>
                    <span class="fw-bold"><?= htmlspecialchars($labels_pedido['nome_modulo']) ?></span>
                    <span class="badge-preco-pedido">R$ <?= number_format($plano_pedido['preco'], 2, ',', '.') ?>/mês</span>
                    <div class="text-muted" style="font-size:.72rem;">
                        <?= htmlspecialchars($pedido['email'], ENT_QUOTES, 'UTF-8') ?> · <?= date('d/m/Y H:i', strtotime($pedido['criado_em'])) ?>
                    </div>
                </div>
                <form method="POST" action="dev_painel.php" class="d-flex gap-1">
                    <input type="hidden" name="acao" value="atender_solicitacao">
                    <input type="hidden" name="solicitacao_id" value="<?= (int)$pedido['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <button type="submit" name="conceder" value="1" class="btn btn-sm btn-liberar-pedido"><i class="bi bi-unlock-fill me-1"></i> Liberar</button>
                    <button type="submit" name="conceder" value="0" class="btn btn-sm btn-dispensar-pedido"><i class="bi bi-x-lg"></i></button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card card-precos p-3 mb-4">
        <div class="rotulo-secao-equipe fw-bold text-uppercase mb-3">
            <i class="bi bi-tag-fill me-1"></i> Preços e promoções dos módulos
        </div>
        <div class="row g-3">
            <?php foreach ($todos_modulos as $mod): $p = $mod['plano']; ?>
            <div class="col-12 col-lg-6">
                <form method="POST" action="dev_painel.php" class="card-plano-modulo p-3">
                    <input type="hidden" name="acao" value="salvar_plano">
                    <input type="hidden" name="tipo_evento" value="<?= htmlspecialchars($mod['tipo']) ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="bi <?= htmlspecialchars($mod['icone']) ?>"></i>
                        <span class="fw-bold"><?= htmlspecialchars($mod['curto']) ?></span>
                        <?php if ($p['em_promocao']): ?>
                            <span class="selo-promo-ativa ms-auto"><i class="bi bi-lightning-fill"></i> Promoção ativa</span>
                        <?php endif; ?>
                    </div>

                    <div class="row g-2 align-items-end">
                        <div class="col-6 col-md-3">
                            <label class="label-campo-plano">Preço normal</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">R$</span>
                                <input type="text" inputmode="decimal" name="preco_normal" class="form-control" value="<?= number_format($p['preco_normal'], 2, ',', '.') ?>" required>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="label-campo-plano">Preço promo</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">R$</span>
                                <input type="text" inputmode="decimal" name="preco_promocional" class="form-control" placeholder="opcional" value="<?= $p['preco_promocional'] !== null ? number_format($p['preco_promocional'], 2, ',', '.') : '' ?>">
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="label-campo-plano">De</label>
                            <input type="date" name="promocao_inicio" class="form-control form-control-sm" value="<?= htmlspecialchars($p['promocao_inicio'] ?? '') ?>">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="label-campo-plano">Até</label>
                            <input type="date" name="promocao_fim" class="form-control form-control-sm" value="<?= htmlspecialchars($p['promocao_fim'] ?? '') ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-salvar-plano btn-sm mt-2">
                        <i class="bi bi-check-lg me-1"></i> Salvar
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (empty($usuarios_equipe)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-person-x fs-1 d-block mb-2"></i>
            Nenhum usuário admin/assistente cadastrado ainda.
        </div>
    <?php endif; ?>

    <div class="d-flex flex-column gap-2">
        <?php foreach ($admins_equipe as $admin):
            $liberados_admin = $liberados_por_usuario[(int)$admin['id']] ?? ['casamento'];
            $equipe_do_admin = $assistentes_por_admin[$admin['id']] ?? [];
        ?>
        <div class="card card-usuario p-3">
            <?php render_bloco_usuario($admin, $liberados_admin, $todos_modulos, $csrf_token); ?>

            <?php if (!empty($equipe_do_admin)): ?>
            <div class="mt-2 pt-2 border-top">
                <div class="rotulo-secao-equipe fw-bold text-uppercase mb-2">
                    <i class="bi bi-diagram-3-fill me-1"></i> Equipe desta assessoria (<?= count($equipe_do_admin) ?>)
                </div>
                <div class="d-flex flex-column gap-1">
                    <?php foreach ($equipe_do_admin as $assistente):
                        $liberados_assistente = $liberados_por_usuario[(int)$assistente['id']] ?? ['casamento'];
                    ?>
                    <div class="card-assistente px-2">
                        <?php render_bloco_usuario($assistente, $liberados_assistente, $todos_modulos, $csrf_token); ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if (!empty($assistentes_sem_admin)): ?>
        <div class="card card-usuario card-orfaos p-3">
            <div class="rotulo-secao-equipe fw-bold text-uppercase mb-2">
                <i class="bi bi-question-diamond-fill me-1"></i> Sem assessoria vinculada
                <span class="fw-normal text-muted text-lowercase">— cadastrados antes deste controle existir</span>
            </div>
            <div class="d-flex flex-column gap-1">
                <?php foreach ($assistentes_sem_admin as $orfao):
                    $liberados_orfao = $liberados_por_usuario[(int)$orfao['id']] ?? ['casamento'];
                ?>
                <div class="card-assistente px-2">
                    <?php render_bloco_usuario($orfao, $liberados_orfao, $todos_modulos, $csrf_token); ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="modalNovoUsuario" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4">
            <form method="POST" action="dev_painel.php">
                <input type="hidden" name="acao" value="criar_usuario">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <div class="modal-header border-0 pb-0">
                    <h5 class="fw-bold mb-0"><i class="bi bi-person-plus-fill me-2"></i>Novo usuário da equipe</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Cria o acesso pra essa pessoa entrar em index.php com e-mail e senha, igual todo mundo. Depois é só liberar os módulos dela na lista abaixo.</p>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Nome</label>
                        <input type="text" name="nome" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">E-mail (login)</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Senha</label>
                        <input type="password" name="senha" class="form-control" minlength="6" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Tipo de acesso</label>
                        <select name="tipo" class="form-select">
                            <option value="assistente">Assistente</option>
                            <option value="admin">Administrador</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-salvar-liberacao px-4" style="width:auto;border-radius:999px;">
                        <i class="bi bi-check-lg me-1"></i> Criar usuário
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
