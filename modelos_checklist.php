<?php
session_start();

// Proteção da página: Apenas administradores
if (!isset($_SESSION['usuario_tipo']) || $_SESSION['usuario_tipo'] !== 'admin') { 
    header("Location: index.php"); 
    exit; 
}

require_once 'conexao.php';

// --- PROCESSAMENTO DOS FORMULÁRIOS (CADASTRAR, EDITAR E EXCLUIR) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. CADASTRAR NOVO MODELO
    if (isset($_POST['cadastrar_modelo'])) {
        $tipo_padrao = trim($_POST['tipo_padrao']);
        $etapa       = (int) $_POST['etapa'];
        $tarefa      = trim($_POST['tarefa']);
        $descricao   = trim($_POST['descricao']);

        if (!empty($tipo_padrao) && !empty($etapa) && !empty($tarefa)) {
            $stmt = $pdo->prepare("INSERT INTO checklist_modelos (tipo_padrao, etapa, tarefa, descricao) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$tipo_padrao, $etapa, $tarefa, $descricao])) {
                $_SESSION['mensagem'] = "Tarefa adicionada ao padrão com sucesso!";
                $_SESSION['tipo_msg'] = "success";
            } else {
                $_SESSION['mensagem'] = "Erro ao cadastrar a tarefa.";
                $_SESSION['tipo_msg'] = "danger";
            }
        } else {
            $_SESSION['mensagem'] = "Preencha todos os campos obrigatórios.";
            $_SESSION['tipo_msg'] = "warning";
        }
        
        // Redireciona para limpar o POST e evitar reenvio
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // 2. EDITAR UM MODELO EXISTENTE
    if (isset($_POST['editar_modelo'])) {
        $id_editar   = (int) $_POST['id_editar'];
        $tipo_padrao = trim($_POST['tipo_padrao_edit']);
        $etapa       = (int) $_POST['etapa_edit'];
        $tarefa      = trim($_POST['tarefa_edit']);
        $descricao   = trim($_POST['descricao_edit']);

        if (!empty($tipo_padrao) && !empty($etapa) && !empty($tarefa)) {
            $stmt = $pdo->prepare("UPDATE checklist_modelos SET tipo_padrao = ?, etapa = ?, tarefa = ?, descricao = ? WHERE id = ?");
            if ($stmt->execute([$tipo_padrao, $etapa, $tarefa, $descricao, $id_editar])) {
                $_SESSION['mensagem'] = "Tarefa atualizada com sucesso!";
                $_SESSION['tipo_msg'] = "success";
            } else {
                $_SESSION['mensagem'] = "Erro ao atualizar a tarefa.";
                $_SESSION['tipo_msg'] = "danger";
            }
        }
        
        // Redireciona para limpar o POST e evitar reenvio
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // 3. EXCLUIR UM MODELO ESPECÍFICO
    if (isset($_POST['excluir_modelo'])) {
        $id_excluir = (int) $_POST['id_excluir'];
        $stmt = $pdo->prepare("DELETE FROM checklist_modelos WHERE id = ?");
        if ($stmt->execute([$id_excluir])) {
            $_SESSION['mensagem'] = "Tarefa excluída do padrão!";
            $_SESSION['tipo_msg'] = "success";
        }
        
        // Redireciona para limpar o POST e evitar reenvio
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// --- RECUPERAR MENSAGENS DA SESSÃO ---
$mensagem = "";
$tipo_msg = "";
if (isset($_SESSION['mensagem'])) {
    $mensagem = $_SESSION['mensagem'];
    $tipo_msg = $_SESSION['tipo_msg'];
    // Limpa as variáveis da sessão para a mensagem não aparecer de novo no próximo F5
    unset($_SESSION['mensagem'], $_SESSION['tipo_msg']);
}

// Buscar todos os modelos já cadastrados
$modelos_cadastrados = $pdo->query("SELECT * FROM checklist_modelos ORDER BY etapa ASC, id ASC")->fetchAll();

// --- LÓGICA DE AGRUPAMENTO SEPARADO ---
$modelos_com_recepcao = [];
$modelos_sem_recepcao = [];

foreach ($modelos_cadastrados as $mod) {
    if ($mod['tipo_padrao'] === 'com_recepcao') {
        $modelos_com_recepcao[$mod['etapa']][] = $mod;
    } else {
        $modelos_sem_recepcao[$mod['etapa']][] = $mod;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Modelos de Checklist</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/estilo.css">
</head>
<body class="bg-light">

<div class="container my-5">
    <div class="bg-white p-4 rounded shadow-sm mb-4">
        <a href="painel_admin.php" class="btn btn-sm btn-outline-secondary mb-2"><i class="bi bi-arrow-left"></i> Voltar ao Painel</a>
        <h2><i class="bi bi-list-check"></i> Gerenciar Modelos de Checklist</h2>
        <p class="text-muted">Crie e edite as tarefas padrão que poderão ser importadas para os eventos.</p>
    </div>

    <?php if (!empty($mensagem)): ?>
        <div class="alert alert-<?= $tipo_msg ?> alert-dismissible fade show shadow-sm" role="alert">
            <?= $mensagem ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-plus-circle"></i> Nova Tarefa Padrão
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Padrão do Evento *</label>
                            <select name="tipo_padrao" class="form-select" required>
                                <option value="">Selecione...</option>
                                <option value="com_recepcao">COM Recepção</option>
                                <option value="sem_recepcao">SEM Recepção</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Etapa (Número) *</label>
                            <input type="number" name="etapa" class="form-control" min="1" placeholder="Ex: 1" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Título da Tarefa *</label>
                            <input type="text" name="tarefa" class="form-control" placeholder="Ex: Definir lista de convidados" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Descrição</label>
                            <textarea name="descricao" class="form-control" rows="3" placeholder="Detalhes da tarefa..."></textarea>
                        </div>
                        <button type="submit" name="cadastrar_modelo" class="btn btn-primary w-100">Salvar Nova Tarefa</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white">
                    <i class="bi bi-table"></i> Tarefas Cadastradas
                </div>
                <div class="card-body">
                    
                    <ul class="nav nav-tabs mb-3" id="checklistTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active fw-bold text-success" id="com-tab" data-bs-toggle="tab" data-bs-target="#com-pane" type="button" role="tab">
                                <i class="bi bi-bookmark-star-fill"></i> COM Recepção
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link fw-bold text-secondary" id="sem-tab" data-bs-toggle="tab" data-bs-target="#sem-pane" type="button" role="tab">
                                <i class="bi bi-bookmark"></i> SEM Recepção
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content" id="checklistTabsContent">
                        
                        <?php
                        function renderizarTabela($modelos, $cor, $id_aba) {
                            if (empty($modelos)) {
                                echo '<p class="text-muted text-center py-4">Nenhuma tarefa cadastrada nesta aba.</p>';
                                return;
                            }
                            
                            echo '<div class="accordion shadow-sm" id="accordion_'.$id_aba.'">';
                            
                            foreach ($modelos as $etapa => $tarefas) {
                                $collapseId = 'collapse_'.$id_aba.'_etapa_'.$etapa;
                                
                                echo '
                                <div class="accordion-item border-0 border-bottom">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed fw-bold text-'.$cor.' bg-light" type="button" data-bs-toggle="collapse" data-bs-target="#'.$collapseId.'">
                                            <i class="bi bi-layers me-2"></i> Etapa '.$etapa.'
                                        </button>
                                    </h2>
                                    <div id="'.$collapseId.'" class="accordion-collapse collapse">
                                        <div class="accordion-body p-0">
                                            <div class="table-responsive">
                                                <table class="table table-hover align-middle mb-0">
                                                    <tbody>';
                                                    
                                                    foreach ($tarefas as $mod) {
                                                        $id = $mod['id'];
                                                        $tarefa_html = htmlspecialchars($mod['tarefa']);
                                                        $desc_html = htmlspecialchars($mod['descricao']);
                                                        $tipo_padrao = $mod['tipo_padrao'];
                                                        $etapa_val = $mod['etapa'];

                                                        echo '
                                                        <tr>
                                                            <td class="w-25 ps-4 border-end"><strong>'.$tarefa_html.'</strong></td>
                                                            <td class="w-50 text-muted small">
                                                                <div style="max-height: 120px; overflow-y: auto; padding-right: 5px;">
                                                                    '.nl2br($desc_html).'
                                                                </div>
                                                            </td>
                                                            <td class="text-end pe-3 border-start" style="width: 15%;">
                                                                <div class="d-flex justify-content-end gap-1">
                                                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalEditar'.$id.'" title="Editar">
                                                                        <i class="bi bi-pencil"></i>
                                                                    </button>
                                                                    <form method="POST" onsubmit="return confirm(\'Apagar esta tarefa?\');">
                                                                        <input type="hidden" name="id_excluir" value="'.$id.'">
                                                                        <button type="submit" name="excluir_modelo" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                                                                    </form>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                        
                                                        <div class="modal fade text-start" id="modalEditar'.$id.'" tabindex="-1" aria-hidden="true">
                                                          <div class="modal-dialog">
                                                            <div class="modal-content">
                                                              <div class="modal-header bg-light">
                                                                <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Editar Tarefa Padrão</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                              </div>
                                                              <form method="POST" action="">
                                                                  <div class="modal-body">
                                                                      <input type="hidden" name="id_editar" value="'.$id.'">
                                                                      <div class="mb-3">
                                                                          <label class="form-label fw-bold">Padrão</label>
                                                                          <select name="tipo_padrao_edit" class="form-select" required>
                                                                              <option value="com_recepcao" '.($tipo_padrao == 'com_recepcao' ? 'selected' : '').'>COM Recepção</option>
                                                                              <option value="sem_recepcao" '.($tipo_padrao == 'sem_recepcao' ? 'selected' : '').'>SEM Recepção</option>
                                                                          </select>
                                                                      </div>
                                                                      <div class="mb-3">
                                                                          <label class="form-label fw-bold">Etapa</label>
                                                                          <input type="number" name="etapa_edit" class="form-control" value="'.$etapa_val.'" min="1" required>
                                                                      </div>
                                                                      <div class="mb-3">
                                                                          <label class="form-label fw-bold">Tarefa</label>
                                                                          <input type="text" name="tarefa_edit" class="form-control" value="'.$tarefa_html.'" required>
                                                                      </div>
                                                                      <div class="mb-3">
                                                                          <label class="form-label fw-bold">Descrição</label>
                                                                          <textarea name="descricao_edit" class="form-control" rows="3">'.$desc_html.'</textarea>
                                                                      </div>
                                                                  </div>
                                                                  <div class="modal-footer">
                                                                      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                                      <button type="submit" name="editar_modelo" class="btn btn-primary">Salvar</button>
                                                                  </div>
                                                              </form>
                                                            </div>
                                                          </div>
                                                        </div>';
                                                    }
                                                    
                                echo '              </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>';
                            }
                            echo '</div>';
                        }
                        ?>

                        <div class="tab-pane fade show active" id="com-pane" role="tabpanel">
                            <?php renderizarTabela($modelos_com_recepcao, 'success', 'com_recepcao'); ?>
                        </div>

                        <div class="tab-pane fade" id="sem-pane" role="tabpanel">
                            <?php renderizarTabela($modelos_sem_recepcao, 'secondary', 'sem_recepcao'); ?>
                        </div>

                    </div> </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>