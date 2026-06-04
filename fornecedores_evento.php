<?php
session_start();

// 1. VALIDAÇÃO DE ACESSO
if (!isset($_SESSION['usuario_tipo']) || $_SESSION['usuario_tipo'] !== 'admin') { 
    header("Location: index.php"); 
    exit; 
}

require_once 'conexao.php';

$evento_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$evento_id) { 
    header("Location: painel_admin.php"); 
    exit; 
}

// Carrega os dados do evento
$stmt = $pdo->prepare("SELECT e.*, c.nome, c.email FROM eventos e INNER JOIN clientes c ON e.cliente_id = c.id WHERE e.id = ?");
$stmt->execute([$evento_id]);
$evento = $stmt->fetch();

if (!$evento) { die("Evento não encontrado."); }

// --- LÓGICA DE PROCESSAMENTO (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ADICIONAR
    if (isset($_POST['adicionar_fornecedor'])) {
        $nome = trim($_POST['nome_fornecedor']);
        $servico = trim($_POST['servico_fornecedor']);
        $contato = trim($_POST['contato_fornecedor']);
        $status = trim($_POST['status_fornecedor']);
        $valor = !empty($_POST['valor_fornecedor']) ? (float)$_POST['valor_fornecedor'] : 0.00;
        
        if (!empty($nome) && !empty($servico)) {
            $pdo->prepare("INSERT INTO fornecedores_evento (evento_id, nome, servico, contato, status, valor) VALUES (?, ?, ?, ?, ?, ?)")->execute([$evento_id, $nome, $servico, $contato, $status, $valor]);
            $_SESSION['msg_sucesso'] = "Fornecedor adicionado com sucesso!";
        }
        header("Location: fornecedores_evento.php?id=" . $evento_id); exit;
    }

    // EDITAR
    if (isset($_POST['editar_fornecedor'])) {
        $id_forn = (int)$_POST['id_fornecedor'];
        $nome = trim($_POST['nome_fornecedor_edit']);
        $servico = trim($_POST['servico_fornecedor_edit']);
        $contato = trim($_POST['contato_fornecedor_edit']);
        $status = trim($_POST['status_fornecedor_edit']);
        $valor = !empty($_POST['valor_fornecedor_edit']) ? (float)$_POST['valor_fornecedor_edit'] : 0.00;

        $pdo->prepare("UPDATE fornecedores_evento SET nome = ?, servico = ?, contato = ?, status = ?, valor = ? WHERE id = ? AND evento_id = ?")->execute([$nome, $servico, $contato, $status, $valor, $id_forn, $evento_id]);
        $_SESSION['msg_sucesso'] = "Fornecedor atualizado com sucesso!";
        header("Location: fornecedores_evento.php?id=" . $evento_id); exit;
    }

    // EXCLUIR
    if (isset($_POST['excluir_fornecedor'])) {
        $id_forn = (int)$_POST['id_fornecedor'];
        $pdo->prepare("DELETE FROM fornecedores_evento WHERE id = ? AND evento_id = ?")->execute([$id_forn, $evento_id]);
        $_SESSION['msg_sucesso'] = "Fornecedor removido!";
        header("Location: fornecedores_evento.php?id=" . $evento_id); exit;
    }
}

// --- MENSAGENS DE SESSÃO ---
$msg_erro = $_SESSION['msg_erro'] ?? "";
$msg_sucesso = $_SESSION['msg_sucesso'] ?? "";
unset($_SESSION['msg_erro'], $_SESSION['msg_sucesso']);

// --- CARREGAR DADOS DOS FORNECEDORES PARA A TELA ---
$stmt_forn = $pdo->prepare("SELECT * FROM fornecedores_evento WHERE evento_id = ? ORDER BY status ASC, nome ASC");
$stmt_forn->execute([$evento_id]);
$lista_fornecedores = $stmt_forn->fetchAll();

// --- CÁLCULOS FINANCEIROS E CONTADORES ---
$total_fornecedores = 0;
$fornecedores_contratados = 0;
$valor_total = 0.0;
$valor_contratado = 0.0;
$valor_orcamento = 0.0;

foreach ($lista_fornecedores as $f) { 
    if ($f['status'] !== 'Cancelado') {
        $total_fornecedores++; 
        $val = (float)$f['valor'];
        $valor_total += $val;
        
        if ($f['status'] == 'Contratado') { 
            $fornecedores_contratados++; 
            $valor_contratado += $val;
        } elseif ($f['status'] == 'Orçamento') {
            $valor_orcamento += $val;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Fornecedores do Evento</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/estilo.css"> 
</head>
<body class="bg-light">
<div class="container my-5">
    
    <div class="bg-white p-4 rounded shadow-sm mb-4 d-flex justify-content-between align-items-center">
        <div>
            <a href="gerenciar.php?id=<?= $evento_id ?>" class="btn btn-sm btn-outline-secondary mb-3">
                <i class="bi bi-arrow-left"></i> Voltar ao Cronograma
            </a>
            <h2 class="mb-0">Fornecedores</h2>
            <small class="text-muted">Cliente: <?= htmlspecialchars($evento['nome']) ?></small>
        </div>
        <div class="text-end text-muted small">
            <div><i class="bi bi-people-fill"></i> Total de Serviços: <strong><?= $total_fornecedores ?></strong></div>
            <div><i class="bi bi-check-circle-fill" style="color: #28a745;"></i> Contratados: <strong><?= $fornecedores_contratados ?></strong></div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card bg-white shadow-sm border-0 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="bg-light rounded-circle p-3 me-3"><i class="bi bi-cash-stack fs-4"></i></div>
                    <div>
                        <div class="text-muted small fw-bold text-uppercase">Custo Previsto (Total)</div>
                        <h4 class="mb-0">R$ <?= number_format($valor_total, 2, ',', '.') ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-white shadow-sm border-0 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="bg-light rounded-circle p-3 me-3"><i class="bi bi-check-circle fs-4" style="color: #28a745;"></i></div>
                    <div>
                        <div class="text-muted small fw-bold text-uppercase">Já Contratado</div>
                        <h4 class="mb-0" style="color: #28a745;">R$ <?= number_format($valor_contratado, 2, ',', '.') ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-white shadow-sm border-0 h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="bg-light rounded-circle p-3 me-3"><i class="bi bi-hourglass-split fs-4" style="color: #ffc107;"></i></div>
                    <div>
                        <div class="text-muted small fw-bold text-uppercase">Em Negociação</div>
                        <h4 class="mb-0 text-dark">R$ <?= number_format($valor_orcamento, 2, ',', '.') ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($msg_erro)): ?><div class="alert alert-danger shadow-sm alert-dismissible"><button class="btn-close" data-bs-dismiss="alert"></button><?= $msg_erro ?></div><?php endif; ?>
    <?php if (!empty($msg_sucesso)): ?><div class="alert alert-success shadow-sm alert-dismissible"><button class="btn-close" data-bs-dismiss="alert"></button><?= $msg_sucesso ?></div><?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
            <h5 class="mb-0"><i class="bi bi-shop me-2"></i> Lista de Fornecedores</h5>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovoFornecedor">
                <i class="bi bi-plus-lg"></i> Adicionar Fornecedor
            </button>
        </div>
        
        <div class="card-body p-0">
            <?php if (empty($lista_fornecedores)): ?>
                <div class="text-center p-5 text-muted">
                    <p class="mt-2 mb-0">Nenhum fornecedor adicionado para este evento.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Serviço / Nome</th>
                                <th>Contato</th>
                                <th>Status</th>
                                <th>Valor Previsto</th>
                                <th class="text-end pe-4">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lista_fornecedores as $forn): 
                                $status_color = 'secondary';
                                if ($forn['status'] == 'Contratado') $status_color = 'success';
                                if ($forn['status'] == 'Orçamento') $status_color = 'warning text-dark';
                                if ($forn['status'] == 'Cancelado') $status_color = 'danger';
                            ?>
                                <tr>
                                    <td class="ps-4 py-3">
                                        <div class="fw-bold"><?= htmlspecialchars($forn['servico']) ?></div>
                                        <small class="text-muted"><i class="bi bi-person"></i> <?= htmlspecialchars($forn['nome']) ?></small>
                                    </td>
                                    <td><small><i class="bi bi-telephone"></i> <?= htmlspecialchars($forn['contato']) ?></small></td>
                                    <td><span class="badge bg-<?= $status_color ?> rounded-pill fw-normal px-3 py-2"><?= htmlspecialchars($forn['status']) ?></span></td>
                                    <td>
                                        <span class="fw-bold">R$ <?= number_format($forn['valor'], 2, ',', '.') ?></span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalEditarForn<?= $forn['id'] ?>" title="Editar">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Tem certeza que deseja excluir este fornecedor?');">
                                            <input type="hidden" name="id_fornecedor" value="<?= $forn['id'] ?>">
                                            <button type="submit" name="excluir_fornecedor" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade" id="modalNovoFornecedor" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title"><i class="bi bi-person-plus"></i> Adicionar Fornecedor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
          <input type="hidden" name="adicionar_fornecedor" value="1">
          <div class="modal-body">
              <div class="mb-3">
                  <label class="form-label fw-bold small">Serviço Prestado (Ex: Decoração) *</label>
                  <input type="text" name="servico_fornecedor" class="form-control" placeholder="O que ele vai fazer?" required>
              </div>
              <div class="mb-3">
                  <label class="form-label fw-bold small">Nome / Empresa *</label>
                  <input type="text" name="nome_fornecedor" class="form-control" placeholder="Nome do contato ou empresa" required>
              </div>
              <div class="mb-3">
                  <label class="form-label fw-bold small">Contato</label>
                  <input type="text" name="contato_fornecedor" class="form-control" placeholder="(00) 00000-0000">
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label fw-bold small">Status *</label>
                      <select name="status_fornecedor" class="form-select" required>
                          <option value="Orçamento">Orçamento (Avaliando)</option>
                          <option value="Contratado">Contratado (Fechado)</option>
                          <option value="Cancelado">Cancelado</option>
                      </select>
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label fw-bold small">Valor Previsto (R$)</label>
                      <input type="number" step="0.01" min="0" name="valor_fornecedor" class="form-control" placeholder="Ex: 1500.50">
                  </div>
              </div>
          </div>
          <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-primary">Cadastrar</button>
          </div>
      </form>
    </div>
  </div>
</div>

<?php foreach ($lista_fornecedores as $forn): ?>
<div class="modal fade" id="modalEditarForn<?= $forn['id'] ?>" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Editar Fornecedor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
          <input type="hidden" name="editar_fornecedor" value="1">
          <input type="hidden" name="id_fornecedor" value="<?= $forn['id'] ?>">
          <div class="modal-body">
              <div class="mb-3">
                  <label class="form-label fw-bold small">Serviço Prestado *</label>
                  <input type="text" name="servico_fornecedor_edit" class="form-control" value="<?= htmlspecialchars($forn['servico']) ?>" required>
              </div>
              <div class="mb-3">
                  <label class="form-label fw-bold small">Nome / Empresa *</label>
                  <input type="text" name="nome_fornecedor_edit" class="form-control" value="<?= htmlspecialchars($forn['nome']) ?>" required>
              </div>
              <div class="mb-3">
                  <label class="form-label fw-bold small">Contato</label>
                  <input type="text" name="contato_fornecedor_edit" class="form-control" value="<?= htmlspecialchars($forn['contato']) ?>">
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label fw-bold small">Status *</label>
                      <select name="status_fornecedor_edit" class="form-select" required>
                          <option value="Orçamento" <?= $forn['status'] == 'Orçamento' ? 'selected' : '' ?>>Orçamento</option>
                          <option value="Contratado" <?= $forn['status'] == 'Contratado' ? 'selected' : '' ?>>Contratado</option>
                          <option value="Cancelado" <?= $forn['status'] == 'Cancelado' ? 'selected' : '' ?>>Cancelado</option>
                      </select>
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label fw-bold small">Valor Previsto (R$)</label>
                      <input type="number" step="0.01" min="0" name="valor_fornecedor_edit" class="form-control" value="<?= $forn['valor'] ?>">
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
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>