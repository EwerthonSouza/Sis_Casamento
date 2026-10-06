<?php
session_start();
require_once 'sessao_timeout.inc.php';
verificar_sessao_ativa();

// Proteção da página: admin e assistente (sem dado financeiro nesta página)
if (!isset($_SESSION['usuario_tipo']) || !in_array($_SESSION['usuario_tipo'], ['admin', 'assistente'], true)) {
    header("Location: index.php?sessao_expirada=1");
    exit;
}

require_once 'conexao.php';
require_once 'tenant.php';
require_once 'modulos_evento.inc.php';
garantir_coluna_tipo_evento($pdo);
garantir_tabela_modulos_config($pdo);
garantir_tabela_central_modulos_liberados($pdo);
garantir_coluna_tipo_evento_checklist_modelos($pdo);

// Módulo ativo: cada módulo tem sua própria lista de modelos de checklist,
// sem compartilhar com os outros (mesma trava usada em painel_admin.php).
if (!modulo_evento_valido($_SESSION['modulo_ativo'] ?? null)) {
    header("Location: hub_modulos.php");
    exit;
}
if (!in_array($_SESSION['modulo_ativo'], modulos_liberados_sessao($pdo), true)) {
    header("Location: hub_modulos.php");
    exit;
}
$modulo_ativo = $_SESSION['modulo_ativo'];
$labels       = labels_modulo_evento($modulo_ativo);
$cor_modulo   = cor_modulo_evento($pdo, $modulo_ativo);

/* ============================================================
   CSRF TOKEN
   ============================================================ */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function verificar_csrf(): void {
    $token_post    = $_POST['csrf_token']    ?? '';
    $token_header  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $token_enviado = $token_post !== '' ? $token_post : $token_header;
    if (!hash_equals($_SESSION['csrf_token'], $token_enviado)) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'Token CSRF inválido.']);
        exit;
    }
}

// --- PROCESSAMENTO DOS FORMULÁRIOS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verificar_csrf();

    // 1. CADASTRAR NOVO MODELO
    if (isset($_POST['cadastrar_modelo'])) {
        $tipo_padrao = trim($_POST['tipo_padrao']);
        $etapa       = (int) $_POST['etapa'];
        $tarefa      = trim($_POST['tarefa']);
        $descricao   = trim($_POST['descricao']);

        if (!empty($tipo_padrao) && $etapa > 0 && !empty($tarefa)) {
            $stmt = $pdo->prepare("INSERT INTO checklist_modelos (tipo_padrao, etapa, tarefa, descricao, tipo_evento, assessoria_id) VALUES (?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$tipo_padrao, $etapa, $tarefa, $descricao, $modulo_ativo, assessoria_atual()])) {
                $_SESSION['mensagem'] = "Tarefa <strong>" . htmlspecialchars($tarefa) . "</strong> adicionada com sucesso!";
                $_SESSION['tipo_msg'] = "success";
                $_SESSION['aba_ativa'] = $tipo_padrao;
                $_SESSION['etapa_aberta'] = $etapa;
            } else {
                $_SESSION['mensagem'] = "Erro ao cadastrar a tarefa. Tente novamente.";
                $_SESSION['tipo_msg'] = "danger";
            }
        } else {
            $_SESSION['mensagem'] = "Preencha todos os campos obrigatórios.";
            $_SESSION['tipo_msg'] = "warning";
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // 2. EDITAR MODELO EXISTENTE
    if (isset($_POST['editar_modelo'])) {
        $id_editar   = (int) $_POST['id_editar'];
        $tipo_padrao = trim($_POST['tipo_padrao_edit']);
        $etapa       = (int) $_POST['etapa_edit'];
        $tarefa      = trim($_POST['tarefa_edit']);
        $descricao   = trim($_POST['descricao_edit']);

        if (!empty($tipo_padrao) && $etapa > 0 && !empty($tarefa)) {
            $stmt = $pdo->prepare("UPDATE checklist_modelos SET tipo_padrao = ?, etapa = ?, tarefa = ?, descricao = ? WHERE id = ? AND tipo_evento = ? AND assessoria_id = ?");
            if ($stmt->execute([$tipo_padrao, $etapa, $tarefa, $descricao, $id_editar, $modulo_ativo, assessoria_atual()])) {
                $_SESSION['mensagem'] = "Tarefa atualizada com sucesso!";
                $_SESSION['tipo_msg'] = "success";
                $_SESSION['aba_ativa'] = $tipo_padrao;
                $_SESSION['etapa_aberta'] = $etapa;
            } else {
                $_SESSION['mensagem'] = "Erro ao atualizar a tarefa.";
                $_SESSION['tipo_msg'] = "danger";
            }
        } else {
            $_SESSION['mensagem'] = "Preencha todos os campos obrigatórios.";
            $_SESSION['tipo_msg'] = "warning";
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // 3. EXCLUIR MODELO
    if (isset($_POST['excluir_modelo'])) {
        $id_excluir = (int) $_POST['id_excluir'];
        $aba_retorno = trim($_POST['aba_retorno'] ?? '');
        $stmt = $pdo->prepare("DELETE FROM checklist_modelos WHERE id = ? AND tipo_evento = ? AND assessoria_id = ?");
        if ($stmt->execute([$id_excluir, $modulo_ativo, assessoria_atual()])) {
            $_SESSION['mensagem'] = "Tarefa excluída com sucesso!";
            $_SESSION['tipo_msg'] = "success";
            $_SESSION['aba_ativa'] = $aba_retorno;
        } else {
            $_SESSION['mensagem'] = "Erro ao excluir a tarefa.";
            $_SESSION['tipo_msg'] = "danger";
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // 4. DUPLICAR MODELO
    if (isset($_POST['duplicar_modelo'])) {
        $id_duplicar = (int) $_POST['id_duplicar'];
        $stmt = $pdo->prepare("SELECT * FROM checklist_modelos WHERE id = ? AND tipo_evento = ? AND assessoria_id = ?");
        $stmt->execute([$id_duplicar, $modulo_ativo, assessoria_atual()]);
        $original = $stmt->fetch();

        if ($original) {
            $stmt2 = $pdo->prepare("INSERT INTO checklist_modelos (tipo_padrao, etapa, tarefa, descricao, tipo_evento, assessoria_id) VALUES (?, ?, ?, ?, ?, ?)");
            if ($stmt2->execute([$original['tipo_padrao'], $original['etapa'], $original['tarefa'] . ' (cópia)', $original['descricao'], $modulo_ativo, assessoria_atual()])) {
                $_SESSION['mensagem'] = "Tarefa duplicada! Edite a cópia conforme necessário.";
                $_SESSION['tipo_msg'] = "info";
                $_SESSION['aba_ativa'] = $original['tipo_padrao'];
                $_SESSION['etapa_aberta'] = $original['etapa'];
            }
        } else {
            $_SESSION['mensagem'] = "Tarefa original não encontrada para duplicar.";
            $_SESSION['tipo_msg'] = "danger";
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // 5. RENOMEAR MODELO (renomeia todas as tarefas do modelo de uma vez)
    if (isset($_POST['renomear_modelo'])) {
        $tipo_atual = trim($_POST['tipo_padrao_atual'] ?? '');
        $novo_nome  = trim($_POST['novo_nome'] ?? '');

        if ($tipo_atual !== '' && $novo_nome !== '' && $novo_nome !== $tipo_atual) {
            $stmt_dup = $pdo->prepare("SELECT COUNT(*) FROM checklist_modelos WHERE tipo_padrao = ? AND tipo_evento = ? AND assessoria_id = ?");
            $stmt_dup->execute([$novo_nome, $modulo_ativo, assessoria_atual()]);
            if ((int) $stmt_dup->fetchColumn() > 0) {
                $_SESSION['mensagem'] = "Já existe um modelo com esse nome.";
                $_SESSION['tipo_msg'] = "warning";
                $_SESSION['aba_ativa'] = $tipo_atual;
            } else {
                $stmt = $pdo->prepare("UPDATE checklist_modelos SET tipo_padrao = ? WHERE tipo_padrao = ? AND tipo_evento = ? AND assessoria_id = ?");
                $stmt->execute([$novo_nome, $tipo_atual, $modulo_ativo, assessoria_atual()]);
                $_SESSION['mensagem'] = "Modelo renomeado para <strong>" . htmlspecialchars($novo_nome) . "</strong>!";
                $_SESSION['tipo_msg'] = "success";
                $_SESSION['aba_ativa'] = $novo_nome;
            }
        } else {
            $_SESSION['mensagem'] = "Informe um nome válido e diferente do atual.";
            $_SESSION['tipo_msg'] = "warning";
            $_SESSION['aba_ativa'] = $tipo_atual;
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // 6. EXCLUIR MODELO INTEIRO (remove todas as tarefas do modelo de uma vez)
    if (isset($_POST['excluir_modelo_completo'])) {
        $tipo_excluir = trim($_POST['tipo_padrao_excluir'] ?? '');

        if ($tipo_excluir !== '') {
            $stmt = $pdo->prepare("DELETE FROM checklist_modelos WHERE tipo_padrao = ? AND tipo_evento = ? AND assessoria_id = ?");
            $stmt->execute([$tipo_excluir, $modulo_ativo, assessoria_atual()]);
            $_SESSION['mensagem'] = "Modelo <strong>" . htmlspecialchars($tipo_excluir) . "</strong> excluído.";
            $_SESSION['tipo_msg'] = "success";
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// --- RECUPERAR MENSAGENS E ESTADO DA SESSÃO ---
$mensagem    = $_SESSION['mensagem']    ?? '';
$tipo_msg    = $_SESSION['tipo_msg']    ?? '';
$aba_ativa_sessao = $_SESSION['aba_ativa'] ?? null;
$etapa_aberta = $_SESSION['etapa_aberta'] ?? null;
unset($_SESSION['mensagem'], $_SESSION['tipo_msg'], $_SESSION['aba_ativa'], $_SESSION['etapa_aberta']);

// Buscar e ordenar todos os modelos do módulo ativo E da assessoria atual
// (cada módulo/assessoria tem os seus, sem compartilhar, 2026-09-28)
$stmt_modelos = $pdo->prepare("SELECT * FROM checklist_modelos WHERE tipo_evento = ? AND assessoria_id = ? ORDER BY etapa ASC, id ASC");
$stmt_modelos->execute([$modulo_ativo, assessoria_atual()]);
$modelos_cadastrados = $stmt_modelos->fetchAll();

// Lista de modelos: cada nome distinto de tipo_padrao é um modelo com nome
// livre, definido pela própria assessoria (sem mais "com/sem recepção" fixos,
// 2026-09-29). Ordenados pela ordem de criação (primeiro id de cada grupo).
$stmt_nomes = $pdo->prepare("
    SELECT tipo_padrao, MIN(id) AS primeiro_id, COUNT(*) AS total
    FROM checklist_modelos
    WHERE tipo_evento = ? AND assessoria_id = ?
    GROUP BY tipo_padrao
    ORDER BY primeiro_id ASC
");
$stmt_nomes->execute([$modulo_ativo, assessoria_atual()]);
$modelos_lista = $stmt_nomes->fetchAll(PDO::FETCH_ASSOC);
$nomes_modelos = array_column($modelos_lista, 'tipo_padrao');

// Aba ativa: mantém a da sessão (ex.: após salvar/editar) se ainda existir;
// senão cai no primeiro modelo da lista; se não houver nenhum modelo, fica vazia.
if ($aba_ativa_sessao !== null && in_array($aba_ativa_sessao, $nomes_modelos, true)) {
    $aba_ativa = $aba_ativa_sessao;
} else {
    $aba_ativa = $modelos_lista[0]['tipo_padrao'] ?? '';
}

// Agrupar tarefas por modelo > etapa
$modelos_agrupados = [];
foreach ($modelos_cadastrados as $mod) {
    $modelos_agrupados[$mod['tipo_padrao']][$mod['etapa']][] = $mod;
}

$total_geral = count($modelos_cadastrados);

// Id HTML estável (sem espaços/acentos) para as abas de cada modelo
function slug_modelo(string $nome): string {
    return 'md_' . substr(md5($nome), 0, 10);
}

// Helper: ícone por tipo de mensagem
$icones = ['success' => 'check-circle-fill', 'danger' => 'x-circle-fill', 'warning' => 'exclamation-triangle-fill', 'info' => 'info-circle-fill'];
$icone_msg = $icones[$tipo_msg] ?? 'info-circle-fill';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php include __DIR__ . '/pwa_head.inc.php'; ?>
    <title>Modelos de Checklist - Meu Evento PRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/estilo.css?v=19">
    <?= estilo_tema_evento($cor_modulo) ?>
    <style>
        .tarefa-row { transition: background-color .15s; }
        .tarefa-row:hover { background-color: #f8f9fa; }
        .badge-etapa { font-size: .7rem; min-width: 26px; }
        .desc-cell { max-height: 80px; overflow-y: auto; }
        #campoBusca:focus { box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }
        .table td, .table th { vertical-align: middle; }
        .accordion-button:not(.collapsed) { font-weight: 700; }
        .highlight { background-color: #fff3cd !important; transition: background-color 1s; }
        .busca-group { max-width: 220px; }

        .cabecalho-modelos {
            position: relative; overflow: hidden;
            background: linear-gradient(135deg, var(--color-primary-light) 0%, #fff 55%, var(--color-primary-light) 130%);
            border: 1px solid rgba(169,116,79,.12);
        }
        .cabecalho-modelos::before, .cabecalho-modelos::after {
            content: ''; position: absolute; border-radius: 50%;
            background: rgba(169,116,79,.08); pointer-events: none;
        }
        .cabecalho-modelos::before { width: 220px; height: 220px; top: -110px; right: 120px; }
        .cabecalho-modelos::after  { width: 160px; height: 160px; bottom: -90px; right: -40px; }

        .cabecalho-modelos-icone-wrap { position: relative; flex-shrink: 0; }
        .cabecalho-modelos-icone {
            width: 72px; height: 72px; border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, var(--color-primary-light) 0%, #f7dcc4 100%);
            color: var(--color-primary-dark); font-size: 1.9rem;
        }
        .cabecalho-modelos-icone-badge {
            position: absolute; right: -6px; bottom: -6px;
            width: 28px; height: 28px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: var(--color-primary); color: #fff; font-size: .75rem;
            border: 3px solid #fff;
        }
        .cabecalho-modelos-texto h2 { color: #1e293b; letter-spacing: -.3px; }

        .cabecalho-modelos-features {
            display: flex; flex-wrap: wrap; align-items: center; gap: .6rem 0;
            margin-top: .6rem; font-size: .82rem; color: #7a6553;
        }
        .cabecalho-modelos-features span.item { display: flex; align-items: center; gap: .4rem; padding: 0 .8rem; }
        .cabecalho-modelos-features span.item:first-child { padding-left: 0; }
        .cabecalho-modelos-features span.divisor { width: 1px; height: 16px; background: rgba(169,116,79,.25); }
        .cabecalho-modelos-features i { color: var(--color-primary); }

        .cabecalho-modelos-stats { position: relative; flex-shrink: 0; }
        .stat-card {
            background: #fff; border-radius: var(--radius-md, 12px);
            box-shadow: 0 4px 14px rgba(0,0,0,.06);
            padding: .7rem 1.1rem; display: flex; align-items: center; gap: .7rem;
            min-width: 168px;
        }
        .stat-card-icone {
            width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-size: 1.15rem;
        }
        .stat-card-icone.tema-primario { background: var(--color-primary-light); color: var(--color-primary-dark); }
        .stat-card-icone.tema-sucesso  { background: rgba(34,197,94,.14); color: var(--color-success); }
        .stat-card-valor { font-weight: 800; font-size: 1.4rem; color: #1e293b; line-height: 1.1; display: block; }
        .stat-card-label { font-size: .74rem; color: #8a7a6c; white-space: nowrap; }

        @media (max-width: 767.98px) {
            .busca-group { max-width: 100%; }
        }

        @media (max-width: 575.98px) {
            .badge.fs-6 { font-size: .75rem !important; }
            .card-header { padding: .75rem 1rem; }
            .accordion-button { font-size: .9rem; padding: .6rem .75rem; }
            .table td, .table th { font-size: .82rem; padding: .5rem .4rem; }
            .table th { white-space: nowrap; }

            #checklistTabs { flex-wrap: nowrap; }
            #checklistTabs .nav-item { flex: 1 1 50%; min-width: 0; }
            #checklistTabs .nav-link {
                padding: .5rem .35rem; font-size: .78rem;
                white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
                display: flex; align-items: center; justify-content: center; gap: .3rem;
            }

            .cabecalho-modelos-texto h2 { font-size: 1.15rem; line-height: 1.25; letter-spacing: -.2px; }
            .cabecalho-modelos-texto p { font-size: .82rem; line-height: 1.4; }
            .cabecalho-modelos-icone { width: 54px; height: 54px; font-size: 1.4rem; border-radius: 16px; }
            .cabecalho-modelos-icone-badge { width: 22px; height: 22px; font-size: .62rem; }
            .cabecalho-modelos-features { display: none; }

            .cabecalho-modelos-stats { width: 100%; }
            .stat-card { flex: 1 1 0; min-width: 0; padding: .55rem .6rem; }
            .stat-card-icone { width: 34px; height: 34px; font-size: .95rem; }
            .stat-card-valor { font-size: 1.05rem; }
            .stat-card-label { font-size: .64rem; white-space: normal; }
        }
    </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-dark shadow-sm" style="background-color: <?= htmlspecialchars($cor_modulo) ?>;">
  <div class="container">
    <span class="navbar-brand mb-0">
      <img src="img/LOGO MEP NAV.svg" alt="Meu Evento PRO" style="height:40px;">
    </span>
    <div class="d-flex align-items-center gap-2">
      <a href="painel_admin.php" class="btn btn-sm btn-outline-light rounded-3">
        <i class="bi bi-arrow-left me-1"></i> Voltar ao Painel
      </a>
    </div>
  </div>
</nav>

<div class="container my-3 my-md-5">

    <!-- Cabeçalho -->
    <div class="cabecalho-modelos p-3 p-md-4 rounded shadow-sm mb-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3" style="position: relative;">
            <div class="cabecalho-modelos-icone-wrap">
                <span class="cabecalho-modelos-icone"><i class="bi bi-clipboard2-check"></i></span>
                <span class="cabecalho-modelos-icone-badge"><i class="bi bi-gear-fill"></i></span>
            </div>
            <div class="cabecalho-modelos-texto">
                <h2 class="mb-0 fs-5 fs-md-3 fw-bold">Gerenciar Modelos de Checklist</h2>
                <p class="text-muted mb-0 mt-1">Crie e edite as tarefas padrão que poderão ser importadas para os eventos.</p>
                <div class="cabecalho-modelos-features">
                    <span class="item"><i class="bi bi-stack"></i> Organize os modelos</span>
                    <span class="divisor"></span>
                    <span class="item"><i class="bi bi-clipboard-check"></i> Defina as tarefas padrão</span>
                    <span class="divisor"></span>
                    <span class="item"><i class="bi bi-arrow-repeat"></i> Importe para os eventos</span>
                </div>
            </div>
        </div>
        <div class="d-flex gap-2 cabecalho-modelos-stats">
            <div class="stat-card">
                <span class="stat-card-icone tema-primario"><i class="bi bi-file-earmark-text"></i></span>
                <div>
                    <span class="stat-card-valor"><?= count($modelos_lista) ?></span>
                    <span class="stat-card-label">Modelo<?= count($modelos_lista) === 1 ? '' : 's' ?> de checklist</span>
                </div>
            </div>
            <div class="stat-card">
                <span class="stat-card-icone tema-sucesso"><i class="bi bi-check-circle-fill"></i></span>
                <div>
                    <span class="stat-card-valor"><?= $total_geral ?></span>
                    <span class="stat-card-label">Tarefa<?= $total_geral === 1 ? '' : 's' ?> no total</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Alerta de feedback com ícone e auto-dismiss -->
    <?php if (!empty($mensagem)): ?>
        <div class="alert alert-<?= $tipo_msg ?> alert-dismissible fade show shadow-sm d-flex align-items-center gap-2" role="alert" id="alertaMensagem">
            <i class="bi bi-<?= $icone_msg ?> flex-shrink-0"></i>
            <div><?= $mensagem ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- Formulário de cadastro -->
        <div class="col-md-4">
            <div class="card shadow-sm sticky-md-top" style="top: 1rem;">
                <div class="card-header bg-primary text-white fw-bold">
                    <i class="bi bi-plus-circle"></i> Nova Tarefa Padrão
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="formCadastrar" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Modelo <span class="text-danger">*</span></label>
                            <input type="text" name="tipo_padrao" id="inputTipoPadrao" class="form-control"
                                list="listaModelos" maxlength="50" required
                                placeholder="Ex: Com Recepção, Chá de Panela...">
                            <datalist id="listaModelos">
                                <?php foreach ($modelos_lista as $ml): ?>
                                <option value="<?= htmlspecialchars($ml['tipo_padrao']) ?>">
                                <?php endforeach; ?>
                            </datalist>
                            <div class="form-text">Nome de um modelo já existente adiciona a tarefa nele; um nome novo cria um modelo.</div>
                            <div class="invalid-feedback">Informe o nome do modelo.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Etapa (Número) <span class="text-danger">*</span></label>
                            <input type="number" name="etapa" id="inputEtapa" class="form-control" min="1" max="99" placeholder="Ex: 1" required>
                            <div class="invalid-feedback">Informe um número de etapa válido (mín. 1).</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Título da Tarefa <span class="text-danger">*</span></label>
                            <input type="text" name="tarefa" id="inputTarefa" class="form-control" placeholder="Ex: Definir lista de convidados" maxlength="200" required>
                            <div class="invalid-feedback">Informe o título da tarefa.</div>
                            <div class="form-text text-end"><span id="contadorTarefa">0</span>/200</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Descrição</label>
                            <textarea name="descricao" class="form-control" rows="3" placeholder="Detalhes, responsável, prazo..."></textarea>
                        </div>
                        <button type="submit" name="cadastrar_modelo" class="btn btn-primary w-100">
                            <i class="bi bi-floppy"></i> Salvar Nova Tarefa
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Tabela de tarefas cadastradas -->
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white d-flex flex-column flex-md-row align-items-stretch align-items-md-center justify-content-between gap-2">
                    <span><i class="bi bi-table"></i> Tarefas Cadastradas</span>
                    <!-- Campo de busca -->
                    <div class="d-flex align-items-center gap-2">
                        <div class="input-group input-group-sm busca-group">
                            <span class="input-group-text bg-secondary border-0 text-white"><i class="bi bi-search"></i></span>
                            <input type="text" id="campoBusca" class="form-control form-control-sm border-0" placeholder="Filtrar tarefas...">
                        </div>
                        <button class="btn btn-sm btn-outline-light" id="btnLimparBusca" title="Limpar busca" style="display:none;">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body pb-2">

                    <?php if (empty($modelos_lista)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                        Nenhum modelo cadastrado ainda neste módulo.<br>
                        Crie o primeiro preenchendo o formulário ao lado.
                    </div>
                    <?php else: ?>
                    <!-- Abas (uma por modelo, nomes livres definidos pela assessoria) -->
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                        <ul class="nav nav-tabs flex-grow-1" id="checklistTabs" role="tablist">
                            <?php foreach ($modelos_lista as $ml):
                                $id_aba = slug_modelo($ml['tipo_padrao']);
                                $ativo  = $ml['tipo_padrao'] === $aba_ativa;
                            ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?= $ativo ? 'active' : '' ?> fw-bold" id="<?= $id_aba ?>-tab"
                                    data-bs-toggle="tab" data-bs-target="#<?= $id_aba ?>-pane" type="button" role="tab"
                                    data-tipo-padrao="<?= htmlspecialchars($ml['tipo_padrao']) ?>" data-total="<?= (int) $ml['total'] ?>">
                                    <i class="bi bi-bookmark-star-fill"></i> <?= htmlspecialchars($ml['tipo_padrao']) ?>
                                    <span class="badge bg-secondary ms-1"><?= (int) $ml['total'] ?></span>
                                </button>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="d-flex gap-1">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnRenomearModelo" title="Renomear modelo atual">
                                <i class="bi bi-pencil"></i> Renomear
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" id="btnExcluirModelo" title="Excluir modelo atual">
                                <i class="bi bi-trash"></i> Excluir modelo
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Contador de resultados da busca -->
                    <div id="resultadoBusca" class="text-muted small mb-2" style="display:none;"></div>

                    <div class="tab-content" id="checklistTabsContent">

                        <?php
                        function renderizarTabela(array $modelos, string $cor, string $id_aba, ?int $etapa_aberta): string {
                            global $csrf_token;
                            $modais_html = '';

                            if (empty($modelos)) {
                                echo '<p class="text-muted text-center py-4"><i class="bi bi-inbox fs-4 d-block mb-1"></i>Nenhuma tarefa cadastrada nesta aba.</p>';
                                return '';
                            }

                            $cores_etapa = ['primary', 'success', 'warning', 'danger', 'info', 'dark', 'secondary'];

                            echo '<div class="accordion" id="accordion_' . $id_aba . '">';

                            $idx_etapa = 0;
                            foreach ($modelos as $etapa => $tarefas) {
                                $collapseId = 'collapse_' . $id_aba . '_etapa_' . $etapa;
                                $abrir = ($etapa_aberta !== null && (int)$etapa === (int)$etapa_aberta);
                                $cor_badge = $cores_etapa[$idx_etapa % count($cores_etapa)];
                                $idx_etapa++;

                                echo '
                                <div class="accordion-item border-0 border-bottom">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button ' . ($abrir ? '' : 'collapsed') . ' fw-bold bg-light py-2" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#' . $collapseId . '" aria-expanded="' . ($abrir ? 'true' : 'false') . '">
                                            <span class="badge bg-' . $cor_badge . ' badge-etapa me-2">' . $etapa . '</span>
                                            Etapa ' . $etapa . '
                                            <span class="badge bg-light text-dark border ms-2">' . count($tarefas) . ' tarefa' . (count($tarefas) > 1 ? 's' : '') . '</span>
                                        </button>
                                    </h2>
                                    <div id="' . $collapseId . '" class="accordion-collapse collapse ' . ($abrir ? 'show' : '') . '">
                                        <div class="accordion-body p-0">

                                            <!-- Visão mobile: cards empilhados -->
                                            <div class="d-md-none lista-tarefas">';

                                foreach ($tarefas as $mod) {
                                    $id          = (int)$mod['id'];
                                    $tarefa_html = htmlspecialchars($mod['tarefa']);
                                    $desc_html   = htmlspecialchars($mod['descricao'] ?? '');
                                    $tipo_padrao = htmlspecialchars($mod['tipo_padrao']);
                                    $etapa_val   = (int)$mod['etapa'];

                                    echo '
                                    <div class="tarefa-row p-3 border-bottom" data-tarefa="' . strtolower($tarefa_html) . '" data-desc="' . strtolower($desc_html) . '">
                                        <div class="fw-semibold">' . $tarefa_html . '</div>
                                        <div class="desc-cell text-muted small mt-1">' . (empty($desc_html) ? '<em class="text-muted">—</em>' : nl2br($desc_html)) . '</div>
                                        <div class="d-flex justify-content-end gap-1 flex-wrap mt-2">

                                            <!-- Botão Editar -->
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                                data-bs-target="#modalEditar' . $id . '" title="Editar tarefa">
                                                <i class="bi bi-pencil"></i>
                                            </button>

                                            <!-- Botão Duplicar -->
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf_token) . '">
                                                <input type="hidden" name="id_duplicar" value="' . $id . '">
                                                <button type="submit" name="duplicar_modelo" class="btn btn-sm btn-outline-info" title="Duplicar tarefa">
                                                    <i class="bi bi-copy"></i>
                                                </button>
                                            </form>

                                            <!-- Botão Excluir (abre modal de confirmação) -->
                                            <button type="button" class="btn btn-sm btn-outline-danger" title="Excluir tarefa"
                                                data-bs-toggle="modal" data-bs-target="#modalExcluir' . $id . '">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>';
                                }

                                echo '
                                            </div>

                                            <!-- Visão desktop: tabela -->
                                            <div class="table-responsive d-none d-md-block">
                                                <table class="table table-hover mb-0">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th class="ps-3" style="width:30%">Tarefa</th>
                                                            <th style="width:55%">Descrição</th>
                                                            <th class="text-center" style="width:15%">Ações</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="lista-tarefas">';

                                foreach ($tarefas as $mod) {
                                    $id          = (int)$mod['id'];
                                    $tarefa_html = htmlspecialchars($mod['tarefa']);
                                    $desc_html   = htmlspecialchars($mod['descricao'] ?? '');
                                    $tipo_padrao = htmlspecialchars($mod['tipo_padrao']);
                                    $etapa_val   = (int)$mod['etapa'];

                                    echo '
                                    <tr class="tarefa-row" data-tarefa="' . strtolower($tarefa_html) . '" data-desc="' . strtolower($desc_html) . '">
                                        <td class="ps-3 fw-semibold">' . $tarefa_html . '</td>
                                        <td>
                                            <div class="desc-cell text-muted small">' . (empty($desc_html) ? '<em class="text-muted">—</em>' : nl2br($desc_html)) . '</div>
                                        </td>
                                        <td class="text-center">
                                            <div class="d-flex justify-content-center gap-1 flex-wrap">

                                                <!-- Botão Editar -->
                                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                                    data-bs-target="#modalEditar' . $id . '" title="Editar tarefa">
                                                    <i class="bi bi-pencil"></i>
                                                </button>

                                                <!-- Botão Duplicar -->
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf_token) . '">
                                                    <input type="hidden" name="id_duplicar" value="' . $id . '">
                                                    <button type="submit" name="duplicar_modelo" class="btn btn-sm btn-outline-info" title="Duplicar tarefa">
                                                        <i class="bi bi-copy"></i>
                                                    </button>
                                                </form>

                                                <!-- Botão Excluir (abre modal de confirmação) -->
                                                <button type="button" class="btn btn-sm btn-outline-danger" title="Excluir tarefa"
                                                    data-bs-toggle="modal" data-bs-target="#modalExcluir' . $id . '">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>';

                                    $modais_html .= '
                                    <!-- Modal Editar -->
                                    <div class="modal fade text-start" id="modalEditar' . $id . '" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content shadow">
                                                <div class="modal-header bg-primary text-white">
                                                    <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Editar Tarefa Padrão</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST" action="" class="needs-validation" novalidate>
                                                    <div class="modal-body">
                                                        <input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf_token) . '">
                                                        <input type="hidden" name="id_editar" value="' . $id . '">
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Modelo <span class="text-danger">*</span></label>
                                                            <input type="text" name="tipo_padrao_edit" class="form-control" list="listaModelos" maxlength="50" required value="' . $tipo_padrao . '">
                                                            <div class="invalid-feedback">Informe o nome do modelo.</div>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Etapa <span class="text-danger">*</span></label>
                                                            <input type="number" name="etapa_edit" class="form-control" value="' . $etapa_val . '" min="1" max="99" required>
                                                            <div class="invalid-feedback">Etapa inválida.</div>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Tarefa <span class="text-danger">*</span></label>
                                                            <input type="text" name="tarefa_edit" class="form-control" value="' . $tarefa_html . '" maxlength="200" required>
                                                            <div class="invalid-feedback">Informe o título da tarefa.</div>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Descrição</label>
                                                            <textarea name="descricao_edit" class="form-control" rows="3">' . $desc_html . '</textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer bg-light">
                                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                        <button type="submit" name="editar_modelo" class="btn btn-primary">
                                                            <i class="bi bi-floppy"></i> Salvar Alterações
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Modal Confirmar Exclusão -->
                                    <div class="modal fade" id="modalExcluir' . $id . '" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered modal-sm">
                                            <div class="modal-content shadow">
                                                <div class="modal-header bg-danger text-white border-0">
                                                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Confirmar Exclusão</h5>
                                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body text-center py-3">
                                                    <p class="mb-1">Você tem certeza que deseja excluir:</p>
                                                    <p class="fw-bold text-danger mb-0">"' . $tarefa_html . '"</p>
                                                    <small class="text-muted">Esta ação não pode ser desfeita.</small>
                                                </div>
                                                <div class="modal-footer border-0 justify-content-center gap-2">
                                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                    <form method="POST" action="">
                                                        <input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf_token) . '">
                                                        <input type="hidden" name="id_excluir" value="' . $id . '">
                                                        <input type="hidden" name="aba_retorno" value="' . $tipo_padrao . '">
                                                        <button type="submit" name="excluir_modelo" class="btn btn-danger">
                                                            <i class="bi bi-trash"></i> Excluir
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>';
                                }

                                echo '
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>';
                            }
                            echo '</div>';

                            // Retorna (não ecoa) o HTML dos modais: eles precisam ser impressos fora
                            // do .card (que tem backdrop-filter no CSS global). backdrop-filter cria
                            // um novo contexto de empilhamento, prendendo o modal atrás do próprio
                            // backdrop do Bootstrap e deixando-o sem receber cliques.
                            return $modais_html;
                        }
                        ?>

                        <?php
                        $modais_acumulados = '';
                        foreach ($modelos_lista as $ml):
                            $id_aba = slug_modelo($ml['tipo_padrao']);
                            $ativo  = $ml['tipo_padrao'] === $aba_ativa;
                        ?>
                        <div class="tab-pane fade <?= $ativo ? 'show active' : '' ?>" id="<?= $id_aba ?>-pane" role="tabpanel">
                            <?php $modais_acumulados .= renderizarTabela($modelos_agrupados[$ml['tipo_padrao']] ?? [], 'primary', $id_aba, $etapa_aberta); ?>
                        </div>
                        <?php endforeach; ?>

                    </div>
                </div>
            </div>
        </div><!-- /col -->

    </div><!-- /row -->
</div><!-- /container -->

<!-- Modais impressos fora do .card (veja comentário em renderizarTabela) -->
<?= $modais_acumulados ?? '' ?>

<!-- Form oculto: excluir modelo inteiro (confirm() já pergunta antes de enviar) -->
<form method="POST" action="" id="formExcluirModeloCompleto" class="d-none">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    <input type="hidden" name="tipo_padrao_excluir" id="inputTipoPadraoExcluir" value="">
    <input type="hidden" name="excluir_modelo_completo" value="1">
</form>

<!-- Modal: renomear modelo (afeta todas as tarefas do modelo de uma vez) -->
<div class="modal fade" id="modalRenomearModelo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Renomear Modelo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="tipo_padrao_atual" id="inputTipoPadraoAtualRenomear" value="">
                    <label class="form-label fw-bold">Novo nome do modelo</label>
                    <input type="text" name="novo_nome" id="inputNovoNomeModelo" class="form-control" maxlength="50" required>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="renomear_modelo" class="btn btn-primary"><i class="bi bi-floppy"></i> Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Auto-dismiss do alerta após 5 segundos
const alerta = document.getElementById('alertaMensagem');
if (alerta) {
    setTimeout(() => {
        const bsAlert = bootstrap.Alert.getOrCreateInstance(alerta);
        bsAlert.close();
    }, 5000);
}

// Contador de caracteres no campo tarefa
const inputTarefa = document.getElementById('inputTarefa');
const contadorTarefa = document.getElementById('contadorTarefa');
if (inputTarefa && contadorTarefa) {
    inputTarefa.addEventListener('input', () => {
        contadorTarefa.textContent = inputTarefa.value.length;
    });
}

// Validação Bootstrap do formulário de cadastro
const formCadastrar = document.getElementById('formCadastrar');
if (formCadastrar) {
    formCadastrar.addEventListener('submit', function (e) {
        if (!this.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
        }
        this.classList.add('was-validated');
    });
}

// Validação nos modais de edição
document.querySelectorAll('.needs-validation').forEach(form => {
    form.addEventListener('submit', function (e) {
        if (!this.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
        }
        this.classList.add('was-validated');
    });
});

// Busca/filtro em tempo real nas tarefas
const campoBusca = document.getElementById('campoBusca');
const btnLimpar  = document.getElementById('btnLimparBusca');
const resultadoBusca = document.getElementById('resultadoBusca');

campoBusca.addEventListener('input', filtrarTarefas);
btnLimpar.addEventListener('click', () => {
    campoBusca.value = '';
    filtrarTarefas();
    btnLimpar.style.display = 'none';
    campoBusca.focus();
});

function filtrarTarefas() {
    const termo = campoBusca.value.trim().toLowerCase();
    btnLimpar.style.display = termo ? 'inline-block' : 'none';

    const rows = document.querySelectorAll('.tarefa-row');
    let visiveis = 0;

    rows.forEach(row => {
        const tarefa = row.dataset.tarefa || '';
        const desc   = row.dataset.desc || '';
        const match  = !termo || tarefa.includes(termo) || desc.includes(termo);
        row.style.display = match ? '' : 'none';
        if (match) visiveis++;

        // Abre o accordion pai se há match
        if (match && termo) {
            const collapse = row.closest('.accordion-collapse');
            if (collapse && !collapse.classList.contains('show')) {
                new bootstrap.Collapse(collapse, { toggle: false }).show();
            }
        }
    });

    if (termo) {
        resultadoBusca.style.display = 'block';
        resultadoBusca.textContent = visiveis + (visiveis === 1 ? ' tarefa encontrada' : ' tarefas encontradas') + ' para "' + campoBusca.value.trim() + '"';
    } else {
        resultadoBusca.style.display = 'none';
    }
}

// Preenche automaticamente o campo "Modelo" do formulário conforme a aba ativa
document.querySelectorAll('#checklistTabs button').forEach(btn => {
    btn.addEventListener('shown.bs.tab', function (e) {
        const input = document.getElementById('inputTipoPadrao');
        if (input) input.value = e.target.dataset.tipoPadrao || '';
    });
});

// Sincroniza o campo com a aba já ativa ao carregar
(function () {
    const abaAtiva = document.querySelector('#checklistTabs .nav-link.active');
    const input = document.getElementById('inputTipoPadrao');
    if (abaAtiva && input) input.value = abaAtiva.dataset.tipoPadrao || '';
})();

// Botões "Renomear" / "Excluir modelo" agem sobre o modelo da aba ativa
function abaAtivaInfo() {
    const btn = document.querySelector('#checklistTabs .nav-link.active');
    if (!btn) return null;
    return { tipoPadrao: btn.dataset.tipoPadrao || '', total: parseInt(btn.dataset.total || '0', 10) };
}

const btnRenomear = document.getElementById('btnRenomearModelo');
if (btnRenomear) {
    btnRenomear.addEventListener('click', () => {
        const info = abaAtivaInfo();
        if (!info) return;
        document.getElementById('inputTipoPadraoAtualRenomear').value = info.tipoPadrao;
        document.getElementById('inputNovoNomeModelo').value = info.tipoPadrao;
        new bootstrap.Modal(document.getElementById('modalRenomearModelo')).show();
    });
}

const btnExcluir = document.getElementById('btnExcluirModelo');
if (btnExcluir) {
    btnExcluir.addEventListener('click', () => {
        const info = abaAtivaInfo();
        if (!info) return;
        const aviso = info.total > 0
            ? `Isso vai excluir o modelo "${info.tipoPadrao}" e ${info.total === 1 ? 'sua 1 tarefa' : 'suas ' + info.total + ' tarefas'}. Eventos que já importaram esse cronograma não são afetados. Esta ação não pode ser desfeita.`
            : `Excluir o modelo "${info.tipoPadrao}"?`;
        if (confirm(aviso)) {
            document.getElementById('inputTipoPadraoExcluir').value = info.tipoPadrao;
            document.getElementById('formExcluirModeloCompleto').submit();
        }
    });
}
</script>
</body>
</html>
