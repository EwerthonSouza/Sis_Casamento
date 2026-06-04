<?php
session_start();

// ============================================================
// TRAVA DE SEGURANÇA: Apenas admin acessa esta página
// ============================================================
if (!isset($_SESSION['usuario_tipo']) || $_SESSION['usuario_tipo'] !== 'admin') {
    header("Location: index.php");
    exit;
}

require_once 'conexao.php';

// ============================================================
// CSRF: Gera token de sessão para proteção dos formulários
// ============================================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/**
 * Valida o token CSRF enviado via POST.
 * Encerra a execução se o token for inválido.
 */
function validar_csrf(): void {
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        die("Requisição inválida. Token CSRF ausente ou incorreto.");
    }
}

$mensagem = "";
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
            $_SESSION['msg_sucesso'] = "Evento e todos os seus dados removidos com sucesso!";
        } catch (Exception $e) {
            $pdo->rollBack();
            // Erro interno: loga o detalhe, exibe mensagem genérica
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

    $nome_noiva       = trim($_POST['nome_noiva']       ?? '');
    $nome_noivo       = trim($_POST['nome_noivo']       ?? '');
    $nome_cliente     = $nome_noiva . ' & ' . $nome_noivo;
    $email_cliente    = trim($_POST['email_cliente']    ?? '');
    $cpf_cliente      = trim($_POST['cpf_cliente']      ?? '');
    $telefone_cliente = trim($_POST['telefone_cliente'] ?? '');
    $senha_raw        = trim($_POST['senha_cliente']    ?? '');
    $data_evento      = $_POST['data_evento']            ?? '';
    $hora_evento      = !empty($_POST['hora_evento']) ? $_POST['hora_evento'] : null;
    $modelo_checklist = $_POST['modelo_checklist']       ?? '';

    if (!empty($nome_noiva) && !empty($nome_noivo) && !empty($email_cliente) && !empty($data_evento)) {

        // Senha padrão se não informada; sempre com hash bcrypt
        if (empty($senha_raw)) {
            $senha_raw = '123456';
        }
        $senha_hash = password_hash($senha_raw, PASSWORD_BCRYPT);

        // Verifica CPF duplicado com evento futuro
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
            $mensagem = "<div class='alert alert-danger shadow-sm'>
                <i class='bi bi-shield-x'></i>
                <strong>Atenção:</strong> Já existe um casamento futuro cadastrado para este CPF.
            </div>";
        } else {
            try {
                $pdo->beginTransaction();

                $stmt_cli = $pdo->prepare("
                    INSERT INTO clientes (nome, email, cpf, telefone, senha)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt_cli->execute([$nome_cliente, $email_cliente, $cpf_cliente, $telefone_cliente, $senha_hash]);
                $cliente_id = $pdo->lastInsertId();

                $stmt_eve = $pdo->prepare("
                    INSERT INTO eventos (cliente_id, data_evento, hora_evento)
                    VALUES (?, ?, ?)
                ");
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
                $mensagem = "<div class='alert alert-success shadow-sm'>
                    <i class='bi bi-check-circle-fill'></i> Casal, contrato e cronograma configurados com sucesso!
                </div>";
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("[CADASTRAR EVENTO] " . $e->getMessage());
                $mensagem = "<div class='alert alert-danger shadow-sm'>
                    <i class='bi bi-exclamation-triangle-fill'></i> Erro ao cadastrar. Tente novamente.
                </div>";
            }
        }
    } else {
        $mensagem = "<div class='alert alert-warning shadow-sm'>
            <i class='bi bi-exclamation-circle-fill'></i> Preencha todos os campos obrigatórios.
        </div>";
    }
}

// ============================================================
// 5. RESETAR SENHA DO CLIENTE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resetar_senha'])) {
    validar_csrf();

    $cliente_id = (int)($_POST['cliente_id'] ?? 0);
    $nova_senha = trim($_POST['nova_senha'] ?? '');

    if ($cliente_id > 0 && !empty($nova_senha)) {
        $nova_senha_hash = password_hash($nova_senha, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("UPDATE clientes SET senha = ? WHERE id = ?");
        $stmt->execute([$nova_senha_hash, $cliente_id]);
        $_SESSION['msg_sucesso'] = "Senha redefinida com sucesso para o cliente!";
    } else {
        $_SESSION['msg_erro'] = "A nova senha não pode estar vazia.";
    }
    header("Location: painel_admin.php");
    exit;
}

// ============================================================
// 6. EDITAR DATA E HORÁRIO DO EVENTO
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_data'])) {
    validar_csrf();

    $evento_id = (int)($_POST['evento_id_data'] ?? 0);
    $nova_data = $_POST['nova_data'] ?? '';
    $nova_hora = !empty($_POST['nova_hora']) ? $_POST['nova_hora'] : null;

    if ($evento_id > 0 && !empty($nova_data) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $nova_data)) {
        $stmt = $pdo->prepare("UPDATE eventos SET data_evento = ?, hora_evento = ? WHERE id = ?");
        $stmt->execute([$nova_data, $nova_hora, $evento_id]);
        $_SESSION['msg_sucesso'] = "Data e horário do evento alterados com sucesso!";
    } else {
        $_SESSION['msg_erro'] = "A nova data é inválida.";
    }
    header("Location: painel_admin.php");
    exit;
}

// ============================================================
// 7. CARREGAMENTO DE DADOS
// ============================================================
$lista_casamentos = $pdo->query("
    SELECT
        e.id          AS evento_id,
        e.data_evento,
        e.hora_evento,
        c.id          AS cliente_id,
        c.nome        AS nome_noivos,
        c.email       AS email_noivos,
        c.telefone    AS telefone_noivos
    FROM eventos e
    INNER JOIN clientes c ON e.cliente_id = c.id
    ORDER BY e.data_evento ASC, e.hora_evento ASC
")->fetchAll();

$eventos_por_data     = [];
$casamentos_realizados = [];
$casamentos_futuros   = [];

foreach ($lista_casamentos as $cas) {
    $eventos_por_data[$cas['data_evento']][] = $cas;

    if ($cas['data_evento'] < $data_hoje) {
        $casamentos_realizados[] = $cas;
    } else {
        $casamentos_futuros[] = $cas;
    }
}

// Anotações do mês visualizado
$stmt_notas = $pdo->prepare("
    SELECT data_nota, anotacao
    FROM calendario_anotacoes
    WHERE data_nota LIKE ?
");
$stmt_notas->execute(["$ano_atual-$mes_atual_str-%"]);
$anotacoes_do_mes = $stmt_notas->fetchAll(PDO::FETCH_KEY_PAIR);

$numero_dias_mes      = cal_days_in_month(CAL_GREGORIAN, $mes_atual, $ano_atual);
$primeiro_dia_semana  = (int)date('w', strtotime("$ano_atual-$mes_atual_str-01"));

// Contador de casamentos realizados no mês visualizado
$casamentos_feitos_no_mes = 0;
foreach ($lista_casamentos as $cas) {
    $dt = new DateTimeImmutable($cas['data_evento']);
    if (
        (int)$dt->format('m') === $mes_atual &&
        (int)$dt->format('Y') === $ano_atual &&
        $cas['data_evento'] < $data_hoje
    ) {
        $casamentos_feitos_no_mes++;
    }
}

$meses_pt = [
    '01' => 'Janeiro',  '02' => 'Fevereiro', '03' => 'Março',    '04' => 'Abril',
    '05' => 'Maio',     '06' => 'Junho',      '07' => 'Julho',    '08' => 'Agosto',
    '09' => 'Setembro', '10' => 'Outubro',    '11' => 'Novembro', '12' => 'Dezembro'
];

// Mensagens de sessão
$msg_erro_session    = $_SESSION['msg_erro']    ?? "";
$msg_sucesso_session = $_SESSION['msg_sucesso'] ?? "";
unset($_SESSION['msg_erro'], $_SESSION['msg_sucesso']);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel de Controle da Assessoria</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/estilo.css?v=2">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
    <div class="container">
        <span class="navbar-brand fw-bold text-primary">
            <i class="bi bi-calendar-heart-fill text-danger"></i> Cerimonial Assessoria
        </span>
        <div class="d-flex align-items-center gap-2">
            <span class="text-white small me-2">
                <i class="bi bi-person-circle"></i> Logado como: <strong>Assessoria Geral</strong>
            </span>
            <a href="modelos_checklist.php" class="btn btn-sm btn-light fw-bold text-dark border-0">
                <i class="bi bi-gear-fill text-secondary"></i> Modelos de Checklist
            </a>
            <a href="logout.php" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-box-arrow-right"></i> Sair
            </a>
        </div>
    </div>
</nav>

<div class="container my-5">

    <?= $mensagem ?>

    <?php if (!empty($msg_sucesso_session)): ?>
        <div class="alert alert-success shadow-sm alert-dismissible">
            <button class="btn-close" data-bs-dismiss="alert"></button>
            <?= htmlspecialchars($msg_sucesso_session) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($msg_erro_session)): ?>
        <div class="alert alert-danger shadow-sm alert-dismissible">
            <button class="btn-close" data-bs-dismiss="alert"></button>
            <?= htmlspecialchars($msg_erro_session) ?>
        </div>
    <?php endif; ?>
    <!-- CABEÇALHO GERAL (DASHBOARD ADMIN) -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="p-4 d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%); color: white;">
                <div>
                    <h2 class="fw-bold mb-1" style="letter-spacing: -0.5px;">
                        <i class="bi bi-calendar-heart text-danger me-2"></i> Painel da Assessoria
                    </h2>
                    <p class="mb-3 text-white-50">Bem-vinda! Aqui está o resumo geral dos seus eventos e compromissos.</p>
                    
                    <div class="d-flex flex-wrap gap-3 mt-2" style="font-size: 0.95rem; opacity: 0.95;">
                        <span class="bg-white bg-opacity-10 px-3 py-1 rounded-pill shadow-sm" title="Data de Hoje">
                            <i class="bi bi-calendar3 me-1 text-info"></i> <?= date('d/m/Y') ?>
                        </span>
                        
                        <span class="bg-white bg-opacity-10 px-3 py-1 rounded-pill shadow-sm" title="Casamentos agendados e em planejamento">
                            <i class="bi bi-heart-pulse-fill me-1 text-danger"></i> <strong><?= count($casamentos_futuros) ?></strong> Eventos Ativos
                        </span>
                        
                        <span class="bg-white bg-opacity-10 px-3 py-1 rounded-pill shadow-sm" title="Casamentos já concluídos">
                            <i class="bi bi-check2-circle me-1 text-success"></i> <strong><?= count($casamentos_realizados) ?></strong> Realizados
                        </span>
                    </div>
                </div>
                
                <!-- Ícone de maleta que aparece só em telas grandes (PCs) -->
                <div class="d-none d-md-block opacity-25 pe-4">
                    <i class="bi bi-briefcase-fill" style="font-size: 5.5rem;"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">

        <!-- ================================================
             COLUNA ESQUERDA: FORMULÁRIO NOVO CASAMENTO
             ================================================ -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 border-top border-4 border-success">

                <div class="card-header bg-white border-0 pt-3 pb-3 d-flex justify-content-between align-items-center"
                     data-bs-toggle="collapse" data-bs-target="#collapseNovoCasamento"
                     aria-expanded="false" style="cursor: pointer;" title="Clique para abrir o formulário">
                    <h5 class="mb-0 fw-bold text-dark">
                        <i class="bi bi-plus-circle-fill text-success"></i> Novo Casamento
                    </h5>
                    <div class="badge bg-success bg-opacity-10 text-success rounded-pill px-2 pb-1 shadow-sm">
                        Abrir <i class="bi bi-chevron-expand"></i>
                    </div>
                </div>

                <div class="collapse" id="collapseNovoCasamento">
                    <div class="card-body p-4 pt-0">
                        <form method="POST" action="" class="small">
                            <!-- CSRF -->
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="cadastrar_evento" value="1">

                            <h6 class="fw-bold text-muted mb-2 border-bottom pb-1"
                                style="font-size: 0.75rem; text-transform: uppercase;">
                                Acesso dos Noivos
                            </h6>

                            <div class="mb-2">
                                <label class="form-label fw-bold text-secondary">Nome Completo (Noiva / Cônjuge 1)</label>
                                <input type="text" name="nome_noiva"
                                       class="form-control form-control-sm border-light-subtle bg-light"
                                       placeholder="Ex: Ana Maria da Silva"
                                       minlength="3" maxlength="100" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-bold text-secondary">Nome Completo (Noivo / Cônjuge 2)</label>
                                <input type="text" name="nome_noivo"
                                       class="form-control form-control-sm border-light-subtle bg-light"
                                       placeholder="Ex: Lucas Mendes de Souza"
                                       minlength="3" maxlength="100" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-bold text-secondary">E-mail de Login</label>
                                <input type="email" name="email_cliente"
                                       class="form-control form-control-sm border-light-subtle bg-light"
                                       placeholder="exemplo@email.com" maxlength="150" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-bold text-secondary">CPF (Para Contrato)</label>
                                <input type="text" name="cpf_cliente"
                                       class="form-control form-control-sm border-light-subtle bg-light"
                                       placeholder="000.000.000-00"
                                       pattern="\d{3}\.?\d{3}\.?\d{3}-?\d{2}" maxlength="14">
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-bold text-secondary">WhatsApp / Telefone</label>
                                <input type="tel" name="telefone_cliente"
                                       class="form-control form-control-sm border-light-subtle bg-light"
                                       placeholder="Ex: (95) 99999-9999" maxlength="20">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold text-secondary">
                                    Senha
                                    <span class="text-muted fw-normal">(vazio = padrão 123456)</span>
                                </label>
                                <input type="password" name="senha_cliente"
                                       class="form-control form-control-sm border-light-subtle bg-light"
                                       placeholder="Senha inicial" minlength="6" maxlength="50"
                                       autocomplete="new-password">
                            </div>

                            <h6 class="fw-bold text-muted mb-2 border-bottom pb-1"
                                style="font-size: 0.75rem; text-transform: uppercase;">
                                Dados do Grande Dia
                            </h6>

                            <div class="row mb-2">
                                <div class="col-7">
                                    <label class="form-label fw-bold text-secondary">Data do Evento</label>
                                    <input type="date" name="data_evento"
                                           class="form-control form-control-sm border-light-subtle bg-light" required>
                                </div>
                                <div class="col-5">
                                    <label class="form-label fw-bold text-secondary">Horário</label>
                                    <input type="time" name="hora_evento"
                                           class="form-control form-control-sm border-light-subtle bg-light">
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold text-secondary">Cronograma Inicial</label>
                                <select name="modelo_checklist"
                                        class="form-select form-select-sm border-light-subtle bg-light text-secondary">
                                    <option value="">Não importar tarefas agora</option>
                                    <option value="com_recepcao">Modelo Padrão (Com Recepção)</option>
                                    <option value="sem_recepcao">Modelo Simplificado (Sem Recepção)</option>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-success w-100 fw-bold py-2 shadow-sm"
                                    style="border-radius: 8px;">
                                <i class="bi bi-folder-plus me-1"></i> Criar Conta e Contrato
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================
             COLUNA DIREITA
             ================================================ -->
        <div class="col-lg-8">

            <!-- ABA 1: PRÓXIMOS CASAMENTOS -->
            <div class="card shadow-sm mb-3 border-0">
                <div class="card-header bg-dark text-white fw-bold d-flex justify-content-between align-items-center rounded shadow-sm collapsed"
                     data-bs-toggle="collapse" data-bs-target="#collapseCasamentosFuturos"
                     aria-expanded="false" style="cursor: pointer;">
                    <span><i class="bi bi-calendar-heart-fill me-1 text-primary"></i> Próximos Casamentos</span>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-light text-dark rounded-pill px-2 shadow-sm">
                            <?= count($casamentos_futuros) ?> agendados
                        </span>
                        <i class="bi bi-chevron-expand fs-5"></i>
                    </div>
                </div>

                <div class="collapse" id="collapseCasamentosFuturos">
                    <div class="card-body bg-white border border-top-0 rounded-bottom p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 small">
                                <thead class="table-light">
                                    <tr>
                                        <th width="5%" class="ps-3">ID</th>
                                        <th>Casal / Contatos de Acesso</th>
                                        <th width="30%">Data e Hora</th>
                                        <th width="18%" class="text-center pe-3">Ação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($casamentos_futuros)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">
                                            Nenhum próximo casamento agendado.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($casamentos_futuros as $cas): ?>
                                    <tr>
                                        <td class="ps-3 text-muted">#<?= (int)$cas['evento_id'] ?></td>
                                        <td>
                                            <span class="text-dark fw-bold fs-6">
                                                Casamento de <?= htmlspecialchars($cas['nome_noivos']) ?>
                                            </span><br>
                                            <div class="d-flex flex-column gap-1 mt-1 text-muted" style="font-size: 0.75rem;">
                                                <span>
                                                    <i class="bi bi-envelope"></i>
                                                    <?= htmlspecialchars($cas['email_noivos']) ?>
                                                </span>
                                                <?php if (!empty($cas['telefone_noivos'])): ?>
                                                    <span class="fw-bold text-secondary">
                                                        <i class="bi bi-whatsapp text-success"></i>
                                                        <?= htmlspecialchars($cas['telefone_noivos']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge bg-primary bg-opacity-10 text-primary p-2 border border-primary border-opacity-20 rounded-3 text-start">
                                                    <i class="bi bi-calendar3 me-1"></i>
                                                    <?= date('d/m/Y', strtotime($cas['data_evento'])) ?>
                                                    <?php if (!empty($cas['hora_evento'])): ?>
                                                        <br><i class="bi bi-clock me-1"></i>
                                                        <?= date('H:i', strtotime($cas['hora_evento'])) ?>
                                                    <?php endif; ?>
                                                </span>

                                                <!-- Botão editar data — usa data-* para evitar XSS no onclick -->
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-primary py-0 px-1 border-0 btn-abrir-modal-data"
                                                        data-evento-id="<?= (int)$cas['evento_id'] ?>"
                                                        data-data="<?= htmlspecialchars($cas['data_evento']) ?>"
                                                        data-hora="<?= htmlspecialchars($cas['hora_evento'] ?? '') ?>"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modalEditarData"
                                                        title="Alterar Data/Hora">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                            </div>
                                        </td>
                                        <td class="text-center pe-3">
                                            <div class="d-flex justify-content-center gap-1">
                                                <!-- Reset senha -->
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-warning fw-bold shadow-sm py-1 px-2 btn-abrir-modal-senha"
                                                        style="border-radius: 6px;"
                                                        data-cliente-id="<?= (int)$cas['cliente_id'] ?>"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modalResetSenha"
                                                        title="Resetar Senha do Casal">
                                                    <i class="bi bi-key-fill"></i>
                                                </button>

                                                <a href="gerenciar.php?id=<?= (int)$cas['evento_id'] ?>"
                                                   class="btn btn-sm btn-primary fw-bold shadow-sm py-1 px-3"
                                                   style="border-radius: 6px;" title="Gerenciar Evento">
                                                    <i class="bi bi-gear-fill"></i>
                                                </a>

                                                <form method="POST"
                                                      onsubmit="return confirm('ATENÇÃO: Isso excluirá o contrato, checklist e dados deste evento. Tem certeza?');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                    <input type="hidden" name="evento_id" value="<?= (int)$cas['evento_id'] ?>">
                                                    <button type="submit" name="excluir_evento"
                                                            class="btn btn-sm btn-outline-danger py-1 px-2"
                                                            style="border-radius: 6px;" title="Excluir Evento">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
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

            <!-- ABA 2: CASAMENTOS REALIZADOS (HISTÓRICO) -->
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-secondary text-white fw-bold d-flex justify-content-between align-items-center rounded shadow-sm collapsed"
                     data-bs-toggle="collapse" data-bs-target="#collapseCasamentosRealizados"
                     aria-expanded="false" style="cursor: pointer;">
                    <span><i class="bi bi-check-circle-fill me-1 text-success"></i> Casamentos Realizados (Histórico)</span>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-light text-secondary rounded-pill px-2 shadow-sm">
                            <?= count($casamentos_realizados) ?> concluídos
                        </span>
                        <i class="bi bi-chevron-expand fs-5"></i>
                    </div>
                </div>

                <div class="collapse" id="collapseCasamentosRealizados">
                    <div class="card-body bg-white border border-top-0 rounded-bottom p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 small">
                                <thead class="table-light">
                                    <tr>
                                        <th width="5%" class="ps-3">ID</th>
                                        <th>Casal / Contatos de Acesso</th>
                                        <th width="30%">Data e Hora</th>
                                        <th width="18%" class="text-center pe-3">Ação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($casamentos_realizados)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">
                                            Nenhum histórico de casamento realizado.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($casamentos_realizados as $cas): ?>
                                    <tr>
                                        <td class="ps-3 text-muted">#<?= (int)$cas['evento_id'] ?></td>
                                        <td>
                                            <span class="text-dark fw-bold fs-6">
                                                Casamento de <?= htmlspecialchars($cas['nome_noivos']) ?>
                                            </span><br>
                                            <div class="d-flex flex-column gap-1 mt-1 text-muted" style="font-size: 0.75rem;">
                                                <span>
                                                    <i class="bi bi-envelope"></i>
                                                    <?= htmlspecialchars($cas['email_noivos']) ?>
                                                </span>
                                                <?php if (!empty($cas['telefone_noivos'])): ?>
                                                    <span class="fw-bold text-secondary">
                                                        <i class="bi bi-whatsapp text-success"></i>
                                                        <?= htmlspecialchars($cas['telefone_noivos']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge bg-light text-dark border p-2 rounded-3 text-start">
                                                    <i class="bi bi-calendar3 text-muted me-1"></i>
                                                    <?= date('d/m/Y', strtotime($cas['data_evento'])) ?>
                                                    <?php if (!empty($cas['hora_evento'])): ?>
                                                        <br><i class="bi bi-clock text-muted me-1"></i>
                                                        <?= date('H:i', strtotime($cas['hora_evento'])) ?>
                                                    <?php endif; ?>
                                                </span>

                                                <button type="button"
                                                        class="btn btn-sm btn-outline-secondary py-0 px-1 border-0 btn-abrir-modal-data"
                                                        data-evento-id="<?= (int)$cas['evento_id'] ?>"
                                                        data-data="<?= htmlspecialchars($cas['data_evento']) ?>"
                                                        data-hora="<?= htmlspecialchars($cas['hora_evento'] ?? '') ?>"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modalEditarData"
                                                        title="Alterar Data/Hora">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                            </div>
                                        </td>
                                        <td class="text-center pe-3">
                                            <div class="d-flex justify-content-center gap-1">
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-warning fw-bold shadow-sm py-1 px-2 btn-abrir-modal-senha"
                                                        style="border-radius: 6px;"
                                                        data-cliente-id="<?= (int)$cas['cliente_id'] ?>"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modalResetSenha"
                                                        title="Resetar Senha do Casal">
                                                    <i class="bi bi-key-fill"></i>
                                                </button>

                                                <a href="gerenciar.php?id=<?= (int)$cas['evento_id'] ?>"
                                                   class="btn btn-sm btn-outline-secondary fw-bold shadow-sm py-1 px-3"
                                                   style="border-radius: 6px;" title="Ver Histórico">
                                                    <i class="bi bi-folder-symlink"></i>
                                                </a>

                                                <form method="POST"
                                                      onsubmit="return confirm('ATENÇÃO: Isso excluirá todo o histórico deste evento. Tem certeza?');">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                    <input type="hidden" name="evento_id" value="<?= (int)$cas['evento_id'] ?>">
                                                    <button type="submit" name="excluir_evento"
                                                            class="btn btn-sm btn-outline-danger py-1 px-2"
                                                            style="border-radius: 6px;" title="Excluir Evento">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
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

            <!-- CALENDÁRIO -->
            <div class="card border-0 shadow-sm rounded-3 p-4" id="calendario-wrapper"
                 style="transition: opacity 0.3s ease;">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0 text-dark font-title">
                        <i class="bi bi-calendar3 text-primary"></i> Visão Mensal:
                        <strong><?= $meses_pt[$mes_atual_str] ?> de <?= $ano_atual ?></strong>
                    </h5>

                    <div class="d-flex align-items-center gap-2">
                        <div class="btn-group shadow-sm">
                            <a href="painel_admin.php?mes=<?= $mes_anterior ?>&ano=<?= $ano_anterior ?>"
                               class="btn btn-sm btn-outline-secondary btn-nav-calendario" title="Mês Anterior">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                            <a href="painel_admin.php"
                               class="btn btn-sm btn-outline-secondary font-monospace btn-nav-calendario"
                               style="font-size:0.75rem;">Mês Atual</a>
                            <a href="painel_admin.php?mes=<?= $mes_seguinte ?>&ano=<?= $ano_seguinte ?>"
                               class="btn btn-sm btn-outline-secondary btn-nav-calendario" title="Próximo Mês">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </div>
                        <div class="d-flex gap-3 small ms-2">
                            <span class="d-flex align-items-center gap-1">
                                <span class="d-inline-block rounded-circle"
                                      style="width:12px; height:12px; background-color:#dc3545;"></span> Feitos
                            </span>
                            <span class="d-flex align-items-center gap-1">
                                <span class="d-inline-block rounded-circle"
                                      style="width:12px; height:12px; background-color:#198754;"></span> A Fazer
                            </span>
                            <span class="d-flex align-items-center gap-1">
                                <span class="d-inline-block rounded-circle border border-2 border-warning"
                                      style="width:12px; height:12px; background-color: transparent;"></span> Hoje
                            </span>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered text-center align-middle m-0 table-calendario">
                        <thead class="table-light text-uppercase" style="font-size: 0.75rem;">
                            <tr>
                                <th class="text-danger">Dom</th>
                                <th>Seg</th><th>Ter</th><th>Qua</th><th>Qui</th><th>Sex</th>
                                <th>Sáb</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                            <?php
                            // Células vazias antes do 1º dia
                            for ($i = 0; $i < $primeiro_dia_semana; $i++) {
                                echo "<td class='bg-light-subtle text-muted'></td>";
                            }

                            $dia_semana_corrente = $primeiro_dia_semana;

                            for ($dia = 1; $dia <= $numero_dias_mes; $dia++) {
                                if ($dia_semana_corrente == 7) {
                                    echo "</tr><tr>";
                                    $dia_semana_corrente = 0;
                                }

                                $data_verificacao = sprintf("%s-%s-%02d", $ano_atual, $mes_atual_str, $dia);
                                $e_hoje           = ($data_verificacao === $data_hoje);
                                $estilo_celula    = "";
                                $tooltip_attrs    = "";

                                if (isset($eventos_por_data[$data_verificacao])) {
                                    $cor = ($data_verificacao < $data_hoje) ? '#dc3545' : '#198754';
                                    $estilo_celula = "background-color:{$cor} !important; color:white !important; font-weight:bold; border-radius:50%;";

                                    $casais_nomes = [];
                                    foreach ($eventos_por_data[$data_verificacao] as $ev) {
                                        $hora_str = !empty($ev['hora_evento'])
                                            ? ' às ' . date('H:i', strtotime($ev['hora_evento']))
                                            : '';
                                        $casais_nomes[] = 'Casamento de ' . htmlspecialchars($ev['nome_noivos'], ENT_QUOTES) . $hora_str;
                                    }
                                    $tooltip_attrs = "data-bs-toggle='tooltip' data-bs-placement='top' title='"
                                        . implode(' / ', $casais_nomes) . "'";
                                }

                                $anotacao_existente = $anotacoes_do_mes[$data_verificacao] ?? "";

                                // Borda de destaque para o dia atual
                                $borda_hoje = $e_hoje ? "outline: 2px solid #ffc107; outline-offset: -2px;" : "";

                                echo "<td $tooltip_attrs
                                         style='height:45px; width:45px; position:relative; $borda_hoje'
                                         class='celula-dia'
                                         data-date='$data_verificacao'
                                         data-note='" . htmlspecialchars($anotacao_existente, ENT_QUOTES) . "'>";

                                if (!empty($estilo_celula)) {
                                    echo "<span class='d-flex align-items-center justify-content-center mx-auto shadow-sm'
                                               style='width:32px; height:32px; $estilo_celula'>$dia</span>";
                                } else {
                                    echo $dia;
                                }

                                if (!empty($anotacao_existente)) {
                                    echo "<div class='indicador-nota' title='Possui anotação técnica'></div>";
                                }

                                echo "</td>";
                                $dia_semana_corrente++;
                            }

                            // Células vazias no final da última semana
                            while ($dia_semana_corrente < 7) {
                                echo "<td class='bg-light-subtle text-muted'></td>";
                                $dia_semana_corrente++;
                            }
                            ?>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center border-top pt-3 mt-4">
                    <div class="fw-bold text-secondary small">
                        <i class="bi bi-graph-up-arrow me-1 text-primary"></i>
                        Casamentos realizados em <?= $meses_pt[$mes_atual_str] ?>:
                    </div>
                    <div class="badge bg-dark px-3 py-2 rounded-3 fs-6 shadow-sm">
                        <?= str_pad($casamentos_feitos_no_mes, 2, "0", STR_PAD_LEFT) ?>
                    </div>
                </div>
            </div>

        </div><!-- /col-lg-8 -->
    </div><!-- /row -->
</div><!-- /container -->

<!-- ============================================================
     MODAL: ANOTAÇÕES DO DIA
     ============================================================ -->
<div class="modal fade" id="modalAnotacaoDia" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 14px;">
            <div class="modal-header bg-light border-0">
                <h5 class="modal-title fw-bold text-dark">
                    <i class="bi bi-pencil-square text-primary"></i>
                    Anotações do Dia <span id="label-data-titulo" class="text-primary"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form method="POST" action="">
                <!-- CSRF + mês/ano para redirecionar ao mês correto -->
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="salvar_anotacao" value="1">
                <input type="hidden" name="data_nota" id="input-data-modal">
                <input type="hidden" name="mes" value="<?= $mes_atual ?>">
                <input type="hidden" name="ano" value="<?= $ano_atual ?>">

                <div class="modal-body p-4">
                    <p class="text-muted small">
                        Utilize este espaço para apontar tarefas gerais da assessoria,
                        lembretes de reuniões ou observações internas para este dia específico.
                    </p>
                    <textarea class="form-control" name="anotacao" id="textarea-anotacao-modal"
                              rows="5" maxlength="2000"
                              placeholder="Escreva aqui as observações técnicas... (Deixe em branco para apagar)"></textarea>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary btn-sm px-3 rounded-pill fw-bold"
                            data-bs-dismiss="modal">Fechar</button>
                    <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-bold shadow-sm">
                        Guardar Nota
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================
     MODAL: RESETAR SENHA
     ============================================================ -->
<div class="modal fade" id="modalResetSenha" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">Resetar Senha</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="resetar_senha" value="1">
                <input type="hidden" name="cliente_id" id="input-cliente-id-reset">
                <div class="modal-body">
                    <input type="password" name="nova_senha" class="form-control"
                           placeholder="Digite a nova senha" minlength="6" maxlength="50"
                           autocomplete="new-password" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning">Confirmar Alteração</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================
     MODAL: EDITAR DATA E HORÁRIO
     ============================================================ -->
<div class="modal fade" id="modalEditarData" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-calendar-event text-primary"></i> Alterar Data e Horário
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="editar_data" value="1">
                <input type="hidden" name="evento_id_data" id="input-evento-id-data">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-7">
                            <label class="form-label fw-bold text-secondary">Nova Data do Evento</label>
                            <input type="date" name="nova_data" id="input-nova-data"
                                   class="form-control" required>
                        </div>
                        <div class="col-5">
                            <label class="form-label fw-bold text-secondary">Novo Horário</label>
                            <input type="time" name="nova_hora" id="input-nova-hora" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar Alterações</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {

    // --------------------------------------------------------
    // Inicializa tooltips do Bootstrap
    // --------------------------------------------------------
    function initTooltips() {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            new bootstrap.Tooltip(el);
        });
    }
    initTooltips();

    // --------------------------------------------------------
    // Preenche modal de EDITAR DATA via data-* attributes
    // (elimina uso de onclick com variáveis PHP direto no HTML)
    // --------------------------------------------------------
    document.addEventListener('click', function (e) {
        const btnData = e.target.closest('.btn-abrir-modal-data');
        if (btnData) {
            document.getElementById('input-evento-id-data').value = btnData.dataset.eventoId  || '';
            document.getElementById('input-nova-data').value      = btnData.dataset.data       || '';
            document.getElementById('input-nova-hora').value      = btnData.dataset.hora       || '';
        }

        // Preenche modal de RESETAR SENHA
        const btnSenha = e.target.closest('.btn-abrir-modal-senha');
        if (btnSenha) {
            document.getElementById('input-cliente-id-reset').value = btnSenha.dataset.clienteId || '';
        }
    });

    // --------------------------------------------------------
    // Delegação de eventos global
    // --------------------------------------------------------
    document.addEventListener('click', async function (e) {

        // 1. Abertura do modal de anotação ao clicar numa célula do calendário
        const celula = e.target.closest('.celula-dia');
        if (celula) {
            const dataAlvo  = celula.getAttribute('data-date');
            const notaAlvo  = celula.getAttribute('data-note') || '';
            const partes    = dataAlvo.split('-');
            const formatada = partes[2] + '/' + partes[1] + '/' + partes[0];

            document.getElementById('input-data-modal').value           = dataAlvo;
            document.getElementById('label-data-titulo').innerText      = '(' + formatada + ')';
            document.getElementById('textarea-anotacao-modal').value    = notaAlvo;

            bootstrap.Modal.getOrCreateInstance(
                document.getElementById('modalAnotacaoDia')
            ).show();
            return;
        }

        // 2. Navegação do calendário via AJAX
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
