<?php
session_start();

// Proteção da página: Apenas administradores
if (!isset($_SESSION['usuario_tipo']) || $_SESSION['usuario_tipo'] !== 'admin') {
    header("Location: index.php");
    exit;
}

require_once 'conexao.php';

// --- PROCESSAMENTO DOS FORMULÁRIOS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. CADASTRAR NOVO MODELO
    if (isset($_POST['cadastrar_modelo'])) {
        $tipo_padrao = trim($_POST['tipo_padrao']);
        $etapa       = (int) $_POST['etapa'];
        $tarefa      = trim($_POST['tarefa']);
        $descricao   = trim($_POST['descricao']);

        if (!empty($tipo_padrao) && $etapa > 0 && !empty($tarefa)) {
            $stmt = $pdo->prepare("INSERT INTO checklist_modelos (tipo_padrao, etapa, tarefa, descricao) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$tipo_padrao, $etapa, $tarefa, $descricao])) {
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
            $stmt = $pdo->prepare("UPDATE checklist_modelos SET tipo_padrao = ?, etapa = ?, tarefa = ?, descricao = ? WHERE id = ?");
            if ($stmt->execute([$tipo_padrao, $etapa, $tarefa, $descricao, $id_editar])) {
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
        $stmt = $pdo->prepare("DELETE FROM checklist_modelos WHERE id = ?");
        if ($stmt->execute([$id_excluir])) {
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
        $stmt = $pdo->prepare("SELECT * FROM checklist_modelos WHERE id = ?");
        $stmt->execute([$id_duplicar]);
        $original = $stmt->fetch();

        if ($original) {
            $stmt2 = $pdo->prepare("INSERT INTO checklist_modelos (tipo_padrao, etapa, tarefa, descricao) VALUES (?, ?, ?, ?)");
            if ($stmt2->execute([$original['tipo_padrao'], $original['etapa'], $original['tarefa'] . ' (cópia)', $original['descricao']])) {
                $_SESSION['mensagem'] = "Tarefa duplicada! Edite a cópia conforme necessário.";
                $_SESSION['tipo_msg'] = "info";
                $_SESSION['aba_ativa'] = $original['tipo_padrao'];
                $_SESSION['etapa_aberta'] = $original['etapa'];
            }
        }
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// --- RECUPERAR MENSAGENS E ESTADO DA SESSÃO ---
$mensagem    = $_SESSION['mensagem']    ?? '';
$tipo_msg    = $_SESSION['tipo_msg']    ?? '';
$aba_ativa   = $_SESSION['aba_ativa']   ?? 'com_recepcao';
$etapa_aberta = $_SESSION['etapa_aberta'] ?? null;
unset($_SESSION['mensagem'], $_SESSION['tipo_msg'], $_SESSION['aba_ativa'], $_SESSION['etapa_aberta']);

// Buscar e ordenar todos os modelos
$modelos_cadastrados = $pdo->query("SELECT * FROM checklist_modelos ORDER BY etapa ASC, tarefa ASC")->fetchAll();

// Agrupar
$modelos_com_recepcao = [];
$modelos_sem_recepcao = [];
foreach ($modelos_cadastrados as $mod) {
    if ($mod['tipo_padrao'] === 'com_recepcao') {
        $modelos_com_recepcao[$mod['etapa']][] = $mod;
    } else {
        $modelos_sem_recepcao[$mod['etapa']][] = $mod;
    }
}

// Contadores
$total_com = count(array_filter($modelos_cadastrados, fn($m) => $m['tipo_padrao'] === 'com_recepcao'));
$total_sem = count($modelos_cadastrados) - $total_com;

// Helper: ícone por tipo de mensagem
$icones = ['success' => 'check-circle-fill', 'danger' => 'x-circle-fill', 'warning' => 'exclamation-triangle-fill', 'info' => 'info-circle-fill'];
$icone_msg = $icones[$tipo_msg] ?? 'info-circle-fill';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Modelos de Checklist</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/estilo.css">
    <style>
        .tarefa-row { transition: background-color .15s; }
        .tarefa-row:hover { background-color: #f8f9fa; }
        .badge-etapa { font-size: .7rem; min-width: 26px; }
        .desc-cell { max-height: 80px; overflow-y: auto; }
        #campoBusca:focus { box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); }
        .table td, .table th { vertical-align: middle; }
        .accordion-button:not(.collapsed) { font-weight: 700; }
        .highlight { background-color: #fff3cd !important; transition: background-color 1s; }
    </style>
</head>
<body class="bg-light">

<div class="container my-5">

    <!-- Cabeçalho -->
    <div class="bg-white p-4 rounded shadow-sm mb-4 d-flex align-items-start justify-content-between flex-wrap gap-2">
        <div>
            <a href="painel_admin.php" class="btn btn-sm btn-outline-secondary mb-2">
                <i class="bi bi-arrow-left"></i> Voltar ao Painel
            </a>
            <h2 class="mb-0"><i class="bi bi-list-check text-primary"></i> Gerenciar Modelos de Checklist</h2>
            <p class="text-muted mb-0 mt-1">Crie e edite as tarefas padrão que poderão ser importadas para os eventos.</p>
        </div>
        <div class="text-end">
            <span class="badge bg-success fs-6 me-1"><?= $total_com ?> tarefas COM recepção</span>
            <span class="badge bg-secondary fs-6"><?= $total_sem ?> tarefas SEM recepção</span>
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
            <div class="card shadow-sm sticky-top" style="top: 1rem;">
                <div class="card-header bg-primary text-white fw-bold">
                    <i class="bi bi-plus-circle"></i> Nova Tarefa Padrão
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="formCadastrar" novalidate>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Padrão do Evento <span class="text-danger">*</span></label>
                            <select name="tipo_padrao" class="form-select" required id="selectTipoPadrao">
                                <option value="">Selecione...</option>
                                <option value="com_recepcao">COM Recepção</option>
                                <option value="sem_recepcao">SEM Recepção</option>
                            </select>
                            <div class="invalid-feedback">Selecione o padrão do evento.</div>
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
                <div class="card-header bg-dark text-white d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span><i class="bi bi-table"></i> Tarefas Cadastradas</span>
                    <!-- Campo de busca -->
                    <div class="d-flex align-items-center gap-2">
                        <div class="input-group input-group-sm" style="max-width: 220px;">
                            <span class="input-group-text bg-secondary border-0 text-white"><i class="bi bi-search"></i></span>
                            <input type="text" id="campoBusca" class="form-control form-control-sm border-0" placeholder="Filtrar tarefas...">
                        </div>
                        <button class="btn btn-sm btn-outline-light" id="btnLimparBusca" title="Limpar busca" style="display:none;">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body pb-2">

                    <!-- Abas -->
                    <ul class="nav nav-tabs mb-3" id="checklistTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?= $aba_ativa === 'com_recepcao' ? 'active' : '' ?> fw-bold text-success" id="com-tab"
                                data-bs-toggle="tab" data-bs-target="#com-pane" type="button" role="tab">
                                <i class="bi bi-bookmark-star-fill"></i> COM Recepção
                                <span class="badge bg-success ms-1"><?= $total_com ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?= $aba_ativa === 'sem_recepcao' ? 'active' : '' ?> fw-bold text-secondary" id="sem-tab"
                                data-bs-toggle="tab" data-bs-target="#sem-pane" type="button" role="tab">
                                <i class="bi bi-bookmark"></i> SEM Recepção
                                <span class="badge bg-secondary ms-1"><?= $total_sem ?></span>
                            </button>
                        </li>
                    </ul>

                    <!-- Contador de resultados da busca -->
                    <div id="resultadoBusca" class="text-muted small mb-2" style="display:none;"></div>

                    <div class="tab-content" id="checklistTabsContent">

                        <?php
                        function renderizarTabela(array $modelos, string $cor, string $id_aba, ?int $etapa_aberta): void {
                            if (empty($modelos)) {
                                echo '<p class="text-muted text-center py-4"><i class="bi bi-inbox fs-4 d-block mb-1"></i>Nenhuma tarefa cadastrada nesta aba.</p>';
                                return;
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
                                            <div class="table-responsive">
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
                                    </tr>

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
                                                        <input type="hidden" name="id_editar" value="' . $id . '">
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Padrão <span class="text-danger">*</span></label>
                                                            <select name="tipo_padrao_edit" class="form-select" required>
                                                                <option value="com_recepcao" ' . ($tipo_padrao == 'com_recepcao' ? 'selected' : '') . '>COM Recepção</option>
                                                                <option value="sem_recepcao" ' . ($tipo_padrao == 'sem_recepcao' ? 'selected' : '') . '>SEM Recepção</option>
                                                            </select>
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
                        }
                        ?>

                        <div class="tab-pane fade <?= $aba_ativa === 'com_recepcao' ? 'show active' : '' ?>" id="com-pane" role="tabpanel">
                            <?php renderizarTabela($modelos_com_recepcao, 'success', 'com_recepcao', $etapa_aberta); ?>
                        </div>

                        <div class="tab-pane fade <?= $aba_ativa === 'sem_recepcao' ? 'show active' : '' ?>" id="sem-pane" role="tabpanel">
                            <?php renderizarTabela($modelos_sem_recepcao, 'secondary', 'sem_recepcao', $etapa_aberta); ?>
                        </div>

                    </div>
                </div>
            </div>
        </div><!-- /col -->

    </div><!-- /row -->
</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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

// Preenche automaticamente a aba do formulário conforme a aba ativa
document.querySelectorAll('#checklistTabs button').forEach(btn => {
    btn.addEventListener('shown.bs.tab', function (e) {
        const aba = e.target.id === 'com-tab' ? 'com_recepcao' : 'sem_recepcao';
        const select = document.getElementById('selectTipoPadrao');
        if (select) select.value = aba;
    });
});

// Sincroniza select do form com a aba já ativa ao carregar
(function () {
    const abaAtiva = document.querySelector('#checklistTabs .nav-link.active');
    if (abaAtiva) {
        const aba = abaAtiva.id === 'com-tab' ? 'com_recepcao' : 'sem_recepcao';
        const select = document.getElementById('selectTipoPadrao');
        if (select) select.value = aba;
    }
})();
</script>
</body>
</html>
