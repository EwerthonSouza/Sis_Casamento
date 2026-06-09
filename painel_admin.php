<?php
session_start();

// Importa a conexão com o banco de dados para criar a variável $pdo
require_once 'conexao.php';

// ============================================================
// TRAVA DE SEGURANÇA: Admin e Assistente acessam esta página
// ============================================================
if (!isset($_SESSION['usuario_tipo']) || !in_array($_SESSION['usuario_tipo'], ['admin', 'assistente'])) {
    header("Location: index.php");
    exit;
}

// Variável global para facilitar o bloqueio de seções financeiras
$is_admin = ($_SESSION['usuario_tipo'] === 'admin');

// ============================================================
// CSRF: Gera token de sessão para proteção dos formulários
// ============================================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function validar_csrf(): void {
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        die("Requisição inválida. Token CSRF ausente ou incorreto.");
    }
}

$data_hoje = date('Y-m-d');

// ============================================================
// 1. NAVEGAÇÃO DO CALENDÁRIO
// ============================================================
$mes_atual = filter_input(INPUT_GET, 'mes', FILTER_VALIDATE_INT);
$ano_atual = filter_input(INPUT_GET, 'ano', FILTER_VALIDATE_INT);

if (!$mes_atual || $mes_atual < 1 || $mes_atual > 12) {
    $mes_atual = (int)date('m');
}
if (!$ano_atual || $ano_atual < 2000 || $ano_atual > 2100) {
    $ano_atual = (int)date('Y');
}

$mes_atual_str = str_pad($mes_atual, 2, "0", STR_PAD_LEFT);
$mes_anterior = $mes_atual - 1;
$ano_anterior = $ano_atual;
if ($mes_anterior == 0) { $mes_anterior = 12; $ano_anterior--; }

$mes_seguinte = $mes_atual + 1;
$ano_seguinte = $ano_atual;
if ($mes_seguinte == 13) { $mes_seguinte = 1; $ano_seguinte++; }

// ============================================================
// 2. SALVAR ANOTAÇÃO DIÁRIA
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_anotacao'])) {
    validar_csrf();

    $data_nota = $_POST['data_nota'] ?? '';
    $anotacao  = trim($_POST['anotacao'] ?? '');
    $mes_ret   = (int)($_POST['mes'] ?? $mes_atual);
    $ano_ret   = (int)($_POST['ano'] ?? $ano_atual);

    if (!empty($data_nota) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_nota)) {
        if (empty($anotacao)) {
            $stmt = $pdo->prepare("DELETE FROM calendario_anotacoes WHERE data_nota = ?");
            $stmt->execute([$data_nota]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO calendario_anotacoes (data_nota, anotacao) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE anotacao = ?
            ");
            $stmt->execute([$data_nota, $anotacao, $anotacao]);
        }
        $_SESSION['msg_sucesso'] = "Anotação guardada com sucesso!";
        header("Location: painel_admin.php?mes=$mes_ret&ano=$ano_ret");
        exit;
    }
}

// ============================================================
// 3. EXCLUIR EVENTO
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['excluir_evento'])) {
    validar_csrf();
    $evento_id = (int)($_POST['evento_id'] ?? 0);

    if ($evento_id > 0) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM checklist WHERE evento_id = ?")->execute([$evento_id]);
            $pdo->prepare("DELETE FROM convidados WHERE evento_id = ?")->execute([$evento_id]);
            $pdo->prepare("DELETE FROM fornecedores_evento WHERE evento_id = ?")->execute([$evento_id]);
            $pdo->prepare("DELETE FROM eventos WHERE id = ?")->execute([$evento_id]);
            $pdo->commit();
            $_SESSION['msg_sucesso'] = "Evento e todos os seus dados removidos!";
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("[EXCLUIR EVENTO] " . $e->getMessage());
            $_SESSION['msg_erro'] = "Erro ao excluir o evento. Tente novamente.";
        }
    }
    header("Location: painel_admin.php");
    exit;
}

// ============================================================
// 4. CADASTRAR NOVO EVENTO
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cadastrar_evento'])) {
    validar_csrf();

    $nome_noiva       = trim($_POST['nome_noiva'] ?? '');
    $nome_noivo       = trim($_POST['nome_noivo'] ?? '');
    $nome_cliente     = $nome_noiva . ' & ' . $nome_noivo;
    $email_cliente    = trim($_POST['email_cliente'] ?? '');
    $cpf_cliente      = preg_replace('/[^0-9]/', '', trim($_POST['cpf_cliente'] ?? '')); // Limpa a máscara
    $telefone_cliente = trim($_POST['telefone_cliente'] ?? '');
    $senha_raw        = trim($_POST['senha_cliente'] ?? '');
    $data_evento      = $_POST['data_evento'] ?? '';
    $hora_evento      = !empty($_POST['hora_evento']) ? $_POST['hora_evento'] : null;
    $modelo_checklist = $_POST['modelo_checklist'] ?? '';

    if (!empty($nome_noiva) && !empty($nome_noivo) && !empty($email_cliente) && !empty($data_evento)) {
        
        $senha_raw = empty($senha_raw) ? '123456' : $senha_raw;
        $senha_hash = password_hash($senha_raw, PASSWORD_BCRYPT);

        $cpf_liberado = true;
        if (!empty($cpf_cliente)) {
            $stmt_check = $pdo->prepare("
                SELECT e.data_evento 
                FROM clientes c
                INNER JOIN eventos e ON c.id = e.cliente_id
                WHERE c.cpf = ?
            ");
            $stmt_check->execute([$cpf_cliente]);
            foreach ($stmt_check->fetchAll(PDO::FETCH_ASSOC) as $ev) {
                if ($ev['data_evento'] >= $data_hoje) {
                    $cpf_liberado = false;
                    break;
                }
            }
        }

        if (!$cpf_liberado) {
            $_SESSION['msg_erro'] = "Atenção: Já existe um casamento futuro cadastrado para este CPF.";
        } else {
            try {
                $pdo->beginTransaction();

                $stmt_cli = $pdo->prepare("INSERT INTO clientes (nome, email, cpf, telefone, senha) VALUES (?, ?, ?, ?, ?)");
                $stmt_cli->execute([$nome_cliente, $email_cliente, $cpf_cliente, $telefone_cliente, $senha_hash]);
                $cliente_id = $pdo->lastInsertId();

                $stmt_eve = $pdo->prepare("INSERT INTO eventos (cliente_id, data_evento, hora_evento) VALUES (?, ?, ?)");
                $stmt_eve->execute([$cliente_id, $data_evento, $hora_evento]);
                $evento_id = $pdo->lastInsertId();

                if (!empty($modelo_checklist)) {
                    $stmt_mod = $pdo->prepare("SELECT * FROM checklist_modelos WHERE tipo_padrao = ?");
                    $stmt_mod->execute([$modelo_checklist]);
                    $modelos = $stmt_mod->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($modelos)) {
                        $stmt_ins_task = $pdo->prepare("
                            INSERT INTO checklist (evento_id, etapa, tarefa, descricao, origem, status)
                            VALUES (?, ?, ?, ?, 'Assessoria', 'pendente')
                        ");
                        foreach ($modelos as $m) {
                            $stmt_ins_task->execute([$evento_id, $m['etapa'], $m['tarefa'], $m['descricao']]);
                        }
                    }
                }
                $pdo->commit();
                $_SESSION['msg_sucesso'] = "Casal, contrato e cronograma configurados com sucesso!";
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("[CADASTRAR EVENTO] " . $e->getMessage());
                $_SESSION['msg_erro'] = "Erro interno ao cadastrar. Tente novamente.";
            }
        }
    } else {
        $_SESSION['msg_erro'] = "Preencha todos os campos obrigatórios.";
    }
    header("Location: painel_admin.php");
    exit;
}

// ============================================================
// 5. RESETAR SENHA
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resetar_senha'])) {
    validar_csrf();
    $cliente_id = (int)($_POST['cliente_id'] ?? 0);
    $nova_senha = trim($_POST['nova_senha'] ?? '');

    if ($cliente_id > 0 && !empty($nova_senha)) {
        $nova_senha_hash = password_hash($nova_senha, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE clientes SET senha = ? WHERE id = ?")->execute([$nova_senha_hash, $cliente_id]);
        $_SESSION['msg_sucesso'] = "Senha redefinida com sucesso para o cliente!";
    } else {
        $_SESSION['msg_erro'] = "A nova senha não pode estar vazia.";
    }
    header("Location: painel_admin.php");
    exit;
}

// ============================================================
// 6. EDITAR DATA
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_data'])) {
    validar_csrf();
    $evento_id = (int)($_POST['evento_id_data'] ?? 0);
    $nova_data = $_POST['nova_data'] ?? '';
    $nova_hora = !empty($_POST['nova_hora']) ? $_POST['nova_hora'] : null;

    if ($evento_id > 0 && !empty($nova_data) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $nova_data)) {
        $pdo->prepare("UPDATE eventos SET data_evento = ?, hora_evento = ? WHERE id = ?")->execute([$nova_data, $nova_hora, $evento_id]);
        $_SESSION['msg_sucesso'] = "Data e horário do evento alterados com sucesso!";
    } else {
        $_SESSION['msg_erro'] = "A nova data informada é inválida.";
    }
    header("Location: painel_admin.php");
    exit;
}

// ============================================================
// 7. CARREGAMENTO DE DADOS
// ============================================================
$lista_casamentos = $pdo->query("
    SELECT e.id AS evento_id, e.data_evento, e.hora_evento,
           c.id AS cliente_id, c.nome AS nome_noivos, c.email AS email_noivos, c.telefone AS telefone_noivos
    FROM eventos e
    INNER JOIN clientes c ON e.cliente_id = c.id
    ORDER BY e.data_evento ASC, e.hora_evento ASC
")->fetchAll();

$eventos_por_data = [];
$casamentos_realizados = [];
$casamentos_futuros = [];

foreach ($lista_casamentos as $cas) {
    $eventos_por_data[$cas['data_evento']][] = $cas;
    if ($cas['data_evento'] < $data_hoje) {
        $casamentos_realizados[] = $cas;
    } else {
        $casamentos_futuros[] = $cas;
    }
}

$stmt_notas = $pdo->prepare("SELECT data_nota, anotacao FROM calendario_anotacoes WHERE data_nota LIKE ?");
$stmt_notas->execute(["$ano_atual-$mes_atual_str-%"]);
$anotacoes_do_mes = $stmt_notas->fetchAll(PDO::FETCH_KEY_PAIR);

$numero_dias_mes     = cal_days_in_month(CAL_GREGORIAN, $mes_atual, $ano_atual);
$primeiro_dia_semana = (int)date('w', strtotime("$ano_atual-$mes_atual_str-01"));

$casamentos_feitos_no_mes = 0;
foreach ($lista_casamentos as $cas) {
    $dt = new DateTimeImmutable($cas['data_evento']);
    if ((int)$dt->format('m') === $mes_atual && (int)$dt->format('Y') === $ano_atual && $cas['data_evento'] < $data_hoje) {
        $casamentos_feitos_no_mes++;
    }
}

$meses_pt = [
    '01' => 'Janeiro', '02' => 'Fevereiro', '03' => 'Março', '04' => 'Abril', '05' => 'Maio', '06' => 'Junho',
    '07' => 'Julho', '08' => 'Agosto', '09' => 'Setembro', '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro'
];

$msg_erro_session    = $_SESSION['msg_erro'] ?? "";
$msg_sucesso_session = $_SESSION['msg_sucesso'] ?? "";
unset($_SESSION['msg_erro'], $_SESSION['msg_sucesso']);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel da Assessoria</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/estilo.css?v=2">
    <style>
        .nav-tabs .nav-link { color: #6c757d; font-weight: 500; }
        .nav-tabs .nav-link.active { color: #0d6efd; font-weight: bold; border-bottom: 3px solid #0d6efd; background-color: transparent;}
        .celula-dia { cursor: pointer; transition: background 0.2s; }
        .celula-dia:hover { background-color: #f8f9fa; }
        .indicador-nota { position: absolute; bottom: 4px; right: 4px; width: 8px; height: 8px; background-color: #0d6efd; border-radius: 50%; }
        /* Toast Container fixo acima de tudo */
        .toast-container { z-index: 1060; }
    </style>
</head>
<body class="bg-light">

<div class="toast-container position-fixed top-0 end-0 p-3">
    <?php if (!empty($msg_sucesso_session)): ?>
    <div class="toast align-items-center text-bg-success border-0 show" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4000">
        <div class="d-flex">
            <div class="toast-body fw-bold">
                <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($msg_sucesso_session) ?>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($msg_erro_session)): ?>
    <div class="toast align-items-center text-bg-danger border-0 show" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="5000">
        <div class="d-flex">
            <div class="toast-body fw-bold">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($msg_erro_session) ?>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
    <?php endif; ?>
</div>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
    <div class="container">
        <span class="navbar-brand fw-bold text-primary">
            <i class="bi bi-calendar-heart-fill text-danger"></i> Cerimonial Assessoria
        </span>
        <div class="d-flex align-items-center gap-2">
            <span class="text-white small me-2 d-none d-md-block">
    <i class="bi bi-person-circle"></i> Logado como: 
    <strong><?= $is_admin ? 'Assessoria Geral' : 'Assistente' ?></strong>
</span>
            <?php if ($is_admin): ?>
<a href="gerenciar_equipe.php" class="btn btn-sm btn-warning fw-bold text-dark border-0 me-2 shadow-sm">
    <i class="bi bi-people-fill"></i> <span class="d-none d-sm-inline">Equipe</span>
</a>
<?php endif; ?>

<a href="modelos_checklist.php" class="btn btn-sm btn-light fw-bold text-dark border-0">
    <i class="bi bi-gear-fill text-secondary"></i> <span class="d-none d-sm-inline">Checklists</span>
</a>
            </a>
            <a href="logout.php" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-box-arrow-right"></i> <span class="d-none d-sm-inline">Sair</span>
            </a>
        </div>
    </div>
</nav>

<div class="container my-4">

    <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="p-4 d-flex justify-content-between align-items-center flex-wrap gap-3" style="background: linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%); color: white;">
                <div>
                    <h2 class="fw-bold mb-1" style="letter-spacing: -0.5px;">
                        <i class="bi bi-calendar-heart text-danger me-2"></i> Painel da Assessoria
                    </h2>
                    <p class="mb-3 text-white-50">Bem-vinda! Aqui está o resumo geral dos seus eventos e compromissos.</p>
                    
                    <div class="d-flex flex-wrap gap-2 mt-2" style="font-size: 0.90rem;">
                        <span class="bg-white bg-opacity-10 px-3 py-1 rounded-pill shadow-sm">
                            <i class="bi bi-calendar3 me-1 text-info"></i> <?= date('d/m/Y') ?>
                        </span>
                        <span class="bg-white bg-opacity-10 px-3 py-1 rounded-pill shadow-sm">
                            <i class="bi bi-heart-pulse-fill me-1 text-danger"></i> <strong><?= count($casamentos_futuros) ?></strong> Ativos
                        </span>
                        <span class="bg-white bg-opacity-10 px-3 py-1 rounded-pill shadow-sm">
                            <i class="bi bi-check2-circle me-1 text-success"></i> <strong><?= count($casamentos_realizados) ?></strong> Realizados
                        </span>
                    </div>
                </div>
                
                <button type="button" class="btn btn-lg btn-success fw-bold shadow" data-bs-toggle="modal" data-bs-target="#modalNovoCasamento">
                    <i class="bi bi-plus-circle-fill me-2"></i> Novo Casamento
                </button>
            </div>
        </div>
    </div>

    <div class="row g-4">
        
        <div class="col-xl-7 col-lg-12">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-bottom pt-3 pb-0">
                    <ul class="nav nav-tabs border-0" id="eventoTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="futuros-tab" data-bs-toggle="tab" data-bs-target="#futuros" type="button" role="tab">
                                <i class="bi bi-calendar-event me-1"></i> Próximos 
                                <span class="badge bg-primary ms-1"><?= count($casamentos_futuros) ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="historico-tab" data-bs-toggle="tab" data-bs-target="#historico" type="button" role="tab">
                                <i class="bi bi-clock-history me-1"></i> Histórico
                                <span class="badge bg-secondary ms-1"><?= count($casamentos_realizados) ?></span>
                            </button>
                        </li>
                    </ul>
                </div>
                
                <div class="card-body p-0">
                    <div class="tab-content" id="eventoTabsContent">
                        
                        <div class="tab-pane fade show active p-3" id="futuros" role="tabpanel">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0 small">
                                    <thead class="table-light text-muted">
                                        <tr>
                                            <th>Casal & Contatos</th>
                                            <th width="25%">Data</th>
                                            <th width="20%" class="text-center">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($casamentos_futuros)): ?>
                                        <tr><td colspan="3" class="text-center text-muted py-5"><i class="bi bi-inbox fs-3 d-block mb-2"></i>Nenhum casamento futuro agendado.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($casamentos_futuros as $cas): ?>
                                        <tr>
                                            <td>
                                                <span class="text-dark fw-bold fs-6">Casamento de <?= htmlspecialchars($cas['nome_noivos']) ?></span><br>
                                                <div class="text-muted mt-1" style="font-size: 0.8rem;">
                                                    <i class="bi bi-envelope"></i> <?= htmlspecialchars($cas['email_noivos']) ?><br>
                                                    <?php if (!empty($cas['telefone_noivos'])): ?>
                                                        <i class="bi bi-whatsapp text-success"></i> <?= htmlspecialchars($cas['telefone_noivos']) ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="badge bg-primary bg-opacity-10 text-primary p-2 border border-primary border-opacity-25 rounded-3 text-start w-100 position-relative">
                                                    <i class="bi bi-calendar3 me-1"></i> <?= date('d/m/Y', strtotime($cas['data_evento'])) ?>
                                                    <?php if (!empty($cas['hora_evento'])): ?>
                                                        <br><i class="bi bi-clock me-1"></i> <?= date('H:i', strtotime($cas['hora_evento'])) ?>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-sm btn-link text-primary p-0 position-absolute bottom-0 end-0 me-2 mb-1 btn-abrir-modal-data"
                                                            data-evento-id="<?= (int)$cas['evento_id'] ?>" data-data="<?= htmlspecialchars($cas['data_evento']) ?>" data-hora="<?= htmlspecialchars($cas['hora_evento'] ?? '') ?>"
                                                            data-bs-toggle="modal" data-bs-target="#modalEditarData" title="Editar Data">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="d-flex justify-content-center gap-1">
                                                    <button type="button" class="btn btn-sm btn-light border fw-bold text-warning btn-abrir-modal-senha" data-cliente-id="<?= (int)$cas['cliente_id'] ?>" data-bs-toggle="modal" data-bs-target="#modalResetSenha" title="Resetar Senha"><i class="bi bi-key"></i></button>
                                                    <a href="gerenciar.php?id=<?= (int)$cas['evento_id'] ?>" class="btn btn-sm btn-primary shadow-sm" title="Gerenciar"><i class="bi bi-gear-fill"></i></a>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Tem certeza que deseja excluir o evento de <?= htmlspecialchars($cas['nome_noivos']) ?>? Todos os dados serão perdidos!');">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                        <input type="hidden" name="evento_id" value="<?= (int)$cas['evento_id'] ?>">
                                                        <button type="submit" name="excluir_evento" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="tab-pane fade p-3" id="historico" role="tabpanel">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0 small opacity-75">
                                    <thead class="table-light text-muted">
                                        <tr>
                                            <th>Casal & Contatos</th>
                                            <th width="25%">Data Realizada</th>
                                            <th width="20%" class="text-center">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($casamentos_realizados)): ?>
                                        <tr><td colspan="3" class="text-center text-muted py-5"><i class="bi bi-inbox fs-3 d-block mb-2"></i>Nenhum histórico disponível.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($casamentos_realizados as $cas): ?>
                                        <tr>
                                            <td>
                                                <span class="text-dark fw-bold">Casamento de <?= htmlspecialchars($cas['nome_noivos']) ?></span><br>
                                                <div class="text-muted mt-1" style="font-size: 0.8rem;">
                                                    <i class="bi bi-envelope"></i> <?= htmlspecialchars($cas['email_noivos']) ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-secondary border p-2 w-100 text-start">
                                                    <i class="bi bi-calendar-check me-1"></i> <?= date('d/m/Y', strtotime($cas['data_evento'])) ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <div class="d-flex justify-content-center gap-1">
                                                    <a href="gerenciar.php?id=<?= (int)$cas['evento_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Ver Arquivo"><i class="bi bi-folder2-open"></i></a>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Excluir histórico de <?= htmlspecialchars($cas['nome_noivos']) ?>?');">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                        <input type="hidden" name="evento_id" value="<?= (int)$cas['evento_id'] ?>">
                                                        <button type="submit" name="excluir_evento" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-5 col-lg-12">
            <div class="card border-0 shadow-sm rounded-4 p-4 h-100" id="calendario-wrapper" style="transition: opacity 0.3s ease;">
                
                <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-3">
                    <h5 class="mb-0 text-dark fw-bold">
                        <i class="bi bi-calendar3 text-primary"></i> <?= $meses_pt[$mes_atual_str] ?> <?= $ano_atual ?>
                    </h5>
                    <div class="btn-group shadow-sm">
                        <a href="painel_admin.php?mes=<?= $mes_anterior ?>&ano=<?= $ano_anterior ?>" class="btn btn-sm btn-outline-secondary btn-nav-calendario" title="Mês Anterior"><i class="bi bi-chevron-left"></i></a>
                        <a href="painel_admin.php" class="btn btn-sm btn-light border text-secondary font-monospace" style="font-size:0.75rem;">Hoje</a>
                        <a href="painel_admin.php?mes=<?= $mes_seguinte ?>&ano=<?= $ano_seguinte ?>" class="btn btn-sm btn-outline-secondary btn-nav-calendario" title="Próximo Mês"><i class="bi bi-chevron-right"></i></a>
                    </div>
                </div>

                <div class="d-flex justify-content-center gap-3 small mb-3 text-muted">
                    <span class="d-flex align-items-center gap-1"><span class="rounded-circle" style="width:10px; height:10px; background-color:#198754;"></span> A Fazer</span>
                    <span class="d-flex align-items-center gap-1"><span class="rounded-circle" style="width:10px; height:10px; background-color:#dc3545;"></span> Feitos</span>
                    <span class="d-flex align-items-center gap-1"><span class="rounded-circle border border-2 border-warning" style="width:10px; height:10px;"></span> Hoje</span>
                    <span class="d-flex align-items-center gap-1"><span class="rounded-circle" style="width:10px; height:10px; background-color:#0d6efd;"></span> Nota</span>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered text-center align-middle m-0 table-calendario">
                        <thead class="table-light text-uppercase" style="font-size: 0.70rem; letter-spacing: 0.5px;">
                            <tr>
                                <th class="text-danger">Dom</th>
                                <th>Seg</th><th>Ter</th><th>Qua</th><th>Qui</th><th>Sex</th>
                                <th>Sáb</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                            <?php
                            for ($i = 0; $i < $primeiro_dia_semana; $i++) {
                                echo "<td class='bg-light-subtle text-muted border-light'></td>";
                            }

                            $dia_semana_corrente = $primeiro_dia_semana;
                            for ($dia = 1; $dia <= $numero_dias_mes; $dia++) {
                                if ($dia_semana_corrente == 7) { echo "</tr><tr>"; $dia_semana_corrente = 0; }

                                $data_verificacao = sprintf("%s-%s-%02d", $ano_atual, $mes_atual_str, $dia);
                                $e_hoje           = ($data_verificacao === $data_hoje);
                                $estilo_celula    = "";
                                $tooltip_attrs    = "";

                                if (isset($eventos_por_data[$data_verificacao])) {
                                    $cor = ($data_verificacao < $data_hoje) ? '#dc3545' : '#198754';
                                    $estilo_celula = "background-color:{$cor} !important; color:white !important; font-weight:bold; border-radius:50%;";

                                    $casais_nomes = [];
                                    foreach ($eventos_por_data[$data_verificacao] as $ev) {
                                        $hora_str = !empty($ev['hora_evento']) ? ' às ' . date('H:i', strtotime($ev['hora_evento'])) : '';
                                        $casais_nomes[] = 'Casamento: ' . htmlspecialchars($ev['nome_noivos'], ENT_QUOTES) . $hora_str;
                                    }
                                    $tooltip_attrs = "data-bs-toggle='tooltip' data-bs-placement='top' title='" . implode(' | ', $casais_nomes) . "'";
                                }

                                $anotacao_existente = $anotacoes_do_mes[$data_verificacao] ?? "";
                                $borda_hoje = $e_hoje ? "outline: 2px solid #ffc107; outline-offset: -2px;" : "";

                                echo "<td $tooltip_attrs style='height:48px; width:48px; position:relative; $borda_hoje' 
                                          class='celula-dia' data-date='$data_verificacao' data-note='" . htmlspecialchars($anotacao_existente, ENT_QUOTES) . "'>";

                                if (!empty($estilo_celula)) {
                                    echo "<span class='d-flex align-items-center justify-content-center mx-auto shadow-sm' style='width:30px; height:30px; $estilo_celula'>$dia</span>";
                                } else {
                                    echo "<span class='d-flex align-items-center justify-content-center mx-auto' style='width:30px; height:30px; font-weight: 500;'>$dia</span>";
                                }

                                if (!empty($anotacao_existente)) {
                                    echo "<div class='indicador-nota shadow-sm'></div>";
                                }

                                echo "</td>";
                                $dia_semana_corrente++;
                            }

                            while ($dia_semana_corrente < 7) {
                                echo "<td class='bg-light-subtle text-muted border-light'></td>";
                                $dia_semana_corrente++;
                            }
                            ?>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 pt-3 border-top text-center">
                    <div class="fw-bold text-secondary small">
                        Realizados este mês: <span class="badge bg-dark px-2 ms-1"><?= str_pad($casamentos_feitos_no_mes, 2, "0", STR_PAD_LEFT) ?></span>
                    </div>
                </div>
            </div>
        </div>

    </div></div><div class="modal fade" id="modalNovoCasamento" tabindex="-1" aria-labelledby="modalNovoCasamentoLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-success text-white border-0 rounded-top-4">
                <h5 class="modal-title fw-bold" id="modalNovoCasamentoLabel">
                    <i class="bi bi-plus-circle-fill me-2"></i> Adicionar Novo Casamento
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST" action="" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="cadastrar_evento" value="1">

                    <h6 class="fw-bold text-success mb-3 border-bottom pb-2">Acesso e Dados dos Noivos</h6>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-secondary small">Nome (Noiva / Cônjuge 1) *</label>
                            <input type="text" name="nome_noiva" class="form-control bg-light" placeholder="Ex: Ana Maria" required>
                            <div class="invalid-feedback">O nome é obrigatório.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-secondary small">Nome (Noivo / Cônjuge 2) *</label>
                            <input type="text" name="nome_noivo" class="form-control bg-light" placeholder="Ex: Lucas Mendes" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-secondary small">E-mail de Login *</label>
                            <input type="email" name="email_cliente" class="form-control bg-light" placeholder="exemplo@email.com" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-secondary small">WhatsApp / Telefone</label>
                            <input type="tel" name="telefone_cliente" id="input-telefone" class="form-control bg-light" placeholder="(00) 00000-0000">
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-secondary small">CPF do Contratante</label>
                            <input type="text" name="cpf_cliente" id="input-cpf" class="form-control bg-light" placeholder="000.000.000-00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-secondary small">Senha Inicial <span class="text-muted fw-normal">(vazio = 123456)</span></label>
                            <input type="password" name="senha_cliente" class="form-control bg-light" placeholder="Senha do portal">
                        </div>
                    </div>

                    <h6 class="fw-bold text-success mb-3 border-bottom pb-2">Detalhes do Grande Dia</h6>

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-secondary small">Data do Evento *</label>
                            <input type="date" name="data_evento" class="form-control bg-light" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-secondary small">Horário</label>
                            <input type="time" name="hora_evento" class="form-control bg-light">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-secondary small">Checklist Padrão</label>
                            <select name="modelo_checklist" class="form-select bg-light">
                                <option value="">Não importar tarefas</option>
                                <option value="com_recepcao">Modelo Padrão (Com Recepção)</option>
                                <option value="sem_recepcao">Simplificado (Sem Recepção)</option>
                            </select>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success px-4 fw-bold shadow-sm">
                            <i class="bi bi-folder-plus me-1"></i> Criar Casamento
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAnotacaoDia" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-light border-0">
                <h5 class="modal-title fw-bold text-dark">
                    <i class="bi bi-pencil-square text-primary"></i> Anotações: <span id="label-data-titulo" class="text-primary"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="salvar_anotacao" value="1">
                <input type="hidden" name="data_nota" id="input-data-modal">
                <input type="hidden" name="mes" value="<?= $mes_atual ?>">
                <input type="hidden" name="ano" value="<?= $ano_atual ?>">

                <div class="modal-body p-4 pt-2">
                    <p class="text-muted small mb-3">Apontamentos técnicos, lembretes ou bloqueio de agenda para esta data.</p>
                    <textarea class="form-control bg-light" name="anotacao" id="textarea-anotacao-modal" rows="5" placeholder="Escreva aqui... (Limpe para apagar)"></textarea>
                </div>
                <div class="modal-footer border-0 pb-4 pt-0 justify-content-center">
                    <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Fechar</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">Salvar Nota</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalResetSenha" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h6 class="modal-title fw-bold text-warning"><i class="bi bi-key-fill me-1"></i> Resetar Senha</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="resetar_senha" value="1">
                <input type="hidden" name="cliente_id" id="input-cliente-id-reset">
                <div class="modal-body">
                    <label class="form-label small fw-bold text-muted">Nova Senha do Casal</label>
                    <input type="password" name="nova_senha" class="form-control bg-light" placeholder="Digite a nova senha" minlength="6" required>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="submit" class="btn btn-warning w-100 fw-bold">Confirmar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditarData" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h6 class="modal-title fw-bold text-primary"><i class="bi bi-calendar-event me-1"></i> Reagendar</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="editar_data" value="1">
                <input type="hidden" name="evento_id_data" id="input-evento-id-data">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Nova Data</label>
                        <input type="date" name="nova_data" id="input-nova-data" class="form-control bg-light" required>
                    </div>
                    <div>
                        <label class="form-label small fw-bold text-muted">Novo Horário</label>
                        <input type="time" name="nova_hora" id="input-nova-hora" class="form-control bg-light">
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="submit" class="btn btn-primary w-100 fw-bold">Salvar Data</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/imask"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // Inicializa Toasts do Bootstrap
    const toastElList = document.querySelectorAll('.toast')
    const toastList = [...toastElList].map(toastEl => new bootstrap.Toast(toastEl))

    // Inicializa Tooltips do Bootstrap
    function initTooltips() {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            new bootstrap.Tooltip(el);
        });
    }
    initTooltips();

    // Validação visual de Formulários (Bootstrap Múltiplos)
    const forms = document.querySelectorAll('.needs-validation')
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault()
                event.stopPropagation()
            }
            form.classList.add('was-validated')
        }, false)
    })

    // Máscaras de Inputs no Modal Novo Casamento
    const elCpf = document.getElementById('input-cpf');
    const elTel = document.getElementById('input-telefone');
    if (elCpf) { IMask(elCpf, { mask: '000.000.000-00' }); }
    if (elTel) { IMask(elTel, { mask: '(00) 00000-0000' }); }

    // Delegação de cliques para preencher Modais e Navegação Ajax
    document.addEventListener('click', async function (e) {

        // Modal Editar Data
        const btnData = e.target.closest('.btn-abrir-modal-data');
        if (btnData) {
            document.getElementById('input-evento-id-data').value = btnData.dataset.eventoId || '';
            document.getElementById('input-nova-data').value      = btnData.dataset.data || '';
            document.getElementById('input-nova-hora').value      = btnData.dataset.hora || '';
        }

        // Modal Resetar Senha
        const btnSenha = e.target.closest('.btn-abrir-modal-senha');
        if (btnSenha) {
            document.getElementById('input-cliente-id-reset').value = btnSenha.dataset.clienteId || '';
        }

        // Abrir Modal de Anotação Clicando na Célula do Calendário
        const celula = e.target.closest('.celula-dia');
        if (celula) {
            const dataAlvo  = celula.getAttribute('data-date');
            const notaAlvo  = celula.getAttribute('data-note') || '';
            const partes    = dataAlvo.split('-');
            const formatada = partes[2] + '/' + partes[1] + '/' + partes[0];

            document.getElementById('input-data-modal').value        = dataAlvo;
            document.getElementById('label-data-titulo').innerText   = formatada;
            document.getElementById('textarea-anotacao-modal').value = notaAlvo;

            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAnotacaoDia')).show();
            return;
        }

        // Navegação Ajax do Calendário
        const navBtn = e.target.closest('.btn-nav-calendario');
        if (navBtn) {
            e.preventDefault();
            const url     = navBtn.href;
            const wrapper = document.getElementById('calendario-wrapper');

            wrapper.style.opacity       = '0.5';
            wrapper.style.pointerEvents = 'none';

            try {
                const response = await fetch(url);
                const html     = await response.text();
                const doc      = new DOMParser().parseFromString(html, 'text/html');
                const novo     = doc.getElementById('calendario-wrapper');

                if (novo) {
                    wrapper.innerHTML = novo.innerHTML;
                    initTooltips();
                }
            } catch (err) {
                console.error('Erro na navegação AJAX:', err);
                window.location.href = url;
            } finally {
                wrapper.style.opacity       = '1';
                wrapper.style.pointerEvents = 'auto';
            }
        }
    });
});
</script>
</body>
</html>