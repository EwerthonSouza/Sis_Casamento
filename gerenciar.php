<?php
session_start();

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

/* ============================================================
   HELPER
   ============================================================ */
function json_out(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/* ============================================================
   POST HANDLERS
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $ajax = isset($_POST['is_ajax']);

    // 1. Adicionar fornecedor
    if (isset($_POST['adicionar_fornecedor'])) {
        $nome    = trim($_POST['nome_fornecedor']);
        $servico = trim($_POST['servico_fornecedor']);
        $contato = trim($_POST['contato_fornecedor'] ?? '');
        $status  = trim($_POST['status_fornecedor'] ?? 'Orçamento');
        $valor   = !empty($_POST['valor_fornecedor']) ? (float)$_POST['valor_fornecedor'] : 0.00;
        if ($nome !== '' && $servico !== '') {
            $pdo->prepare("INSERT INTO fornecedores_evento (evento_id, nome, servico, contato, status, valor) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$evento_id, $nome, $servico, $contato, $status, $valor]);
            $_SESSION['msg_sucesso'] = "Fornecedor adicionado com sucesso!";
        }
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 2. Importar padrão
    if (isset($_POST['gerar_padrao'])) {
        $tipo    = trim($_POST['tipo_padrao']);
        $stmt    = $pdo->prepare("SELECT * FROM checklist_modelos WHERE tipo_padrao = ?");
        $stmt->execute([$tipo]);
        $modelos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($modelos)) {
            $ins = $pdo->prepare("INSERT INTO checklist (evento_id, etapa, tarefa, descricao, origem, status, checado) VALUES (?, ?, ?, ?, 'Assessoria', 'pendente', 0)");
            foreach ($modelos as $m) { $ins->execute([$evento_id, $m['etapa'], $m['tarefa'], $m['descricao']]); }
            $_SESSION['msg_sucesso'] = "Cronograma importado com sucesso!";
        } else {
            $_SESSION['msg_erro'] = "Nenhum modelo encontrado para o tipo: " . htmlspecialchars($tipo);
        }
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 3. Adicionar tarefa manual
    if (isset($_POST['adicionar_manual'])) {
        $etapa     = (int)$_POST['etapa'];
        $tarefa    = trim($_POST['tarefa']);
        $descricao = trim($_POST['descricao']);
        if ($etapa > 0 && $tarefa !== '') {
            $pdo->prepare("INSERT INTO checklist (evento_id, etapa, tarefa, descricao, origem, status, checado) VALUES (?, ?, ?, ?, 'Assessoria', 'pendente', 0)")
                ->execute([$evento_id, $etapa, $tarefa, $descricao]);
            $_SESSION['msg_sucesso'] = "Tarefa adicionada!";
        }
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 4. Editar tarefa
    if (isset($_POST['editar_tarefa'])) {
        $id        = (int)$_POST['id_tarefa'];
        $etapa     = (int)$_POST['etapa_edit'];
        $tarefa    = trim($_POST['tarefa_edit']);
        $descricao = trim($_POST['descricao_edit']);
        $pdo->prepare("UPDATE checklist SET etapa = ?, tarefa = ?, descricao = ? WHERE id = ? AND evento_id = ?")
            ->execute([$etapa, $tarefa, $descricao, $id, $evento_id]);
        $_SESSION['msg_sucesso'] = "Tarefa atualizada!";
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 5. Toggle status tarefa (AJAX)
    if (isset($_POST['alternar_status'])) {
        $id    = (int)$_POST['id_tarefa'];
        $atual = $_POST['status_atual'];
        $novo  = ($atual == 1 || $atual === 'concluido') ? 0 : 1;
        $pdo->prepare("UPDATE checklist SET status = ?, checado = ? WHERE id = ? AND evento_id = ?")
            ->execute([$novo ? 'concluido' : 'pendente', $novo, $id, $evento_id]);
        if ($ajax) json_out(['ok' => true, 'novo' => $novo]);
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 6. Excluir tarefa (AJAX)
    if (isset($_POST['excluir_tarefa'])) {
        $id = (int)$_POST['id_tarefa'];
        $pdo->prepare("DELETE FROM checklist WHERE id = ? AND evento_id = ?")
            ->execute([$id, $evento_id]);
        if ($ajax) json_out(['ok' => true]);
        $_SESSION['msg_sucesso'] = "Tarefa removida!";
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 7. Comentário de tarefa (AJAX)
    if (isset($_POST['adicionar_comentario'])) {
        $id    = (int)$_POST['id_tarefa'];
        $texto = trim($_POST['texto_comentario'] ?? '');
        if ($texto !== '') {
            $pdo->prepare("INSERT INTO checklist_comentarios (checklist_id, autor, comentario) VALUES (?, 'Assessoria', ?)")
                ->execute([$id, $texto]);
            if ($ajax) json_out(['ok' => true, 'autor' => 'Assessoria', 'texto' => htmlspecialchars($texto)]);
            $_SESSION['msg_sucesso'] = "Comentário adicionado!";
        }
        if (!$ajax) { header("Location: gerenciar.php?id=$evento_id"); exit; }
        exit;
    }

    // 8. Comentário de etapa (AJAX)
    if (isset($_POST['comentario_etapa_admin'])) {
        $etapa = trim($_POST['etapa_nome'] ?? '');
        $texto = trim($_POST['novo_comentario_etapa'] ?? '');
        if ($etapa !== '' && $texto !== '') {
            $pdo->prepare("INSERT INTO checklist_comentarios (etapa_nome, autor, comentario) VALUES (?, 'Assessoria', ?)")
                ->execute([$etapa, $texto]);
            if ($ajax) json_out(['ok' => true, 'autor' => 'Assessoria', 'texto' => htmlspecialchars($texto)]);
        }
        if (!$ajax) { header("Location: gerenciar.php?id=$evento_id"); exit; }
        exit;
    }

    // 9. Limpar todo checklist
    if (isset($_POST['excluir_todo_checklist'])) {
        $pdo->prepare("DELETE FROM checklist WHERE evento_id = ?")->execute([$evento_id]);
        $_SESSION['msg_sucesso'] = "Checklist apagado!";
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 10. Adicionar convidado
    if (isset($_POST['adicionar_convidado_admin'])) {
        $nome   = trim($_POST['nome_convidado'] ?? '');
        $fone   = trim($_POST['telefone_convidado'] ?? '');
        $cat    = trim($_POST['categoria_convidado'] ?? 'Outros');
        $acomp  = trim($_POST['acompanhantes'] ?? '');
        $filhos = trim($_POST['filhos'] ?? '');
        if ($nome !== '') {
            $pdo->prepare("INSERT INTO convidados (evento_id, nome, telefone, categoria, acompanhantes, filhos, confirmado) VALUES (?, ?, ?, ?, ?, ?, 0)")
                ->execute([$evento_id, $nome, $fone, $cat, $acomp, $filhos]);
            $_SESSION['msg_sucesso'] = "Convidado adicionado!";
        }
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 11. Toggle convidado (AJAX)
    if (isset($_POST['toggle_convidado'])) {
        $id  = (int)$_POST['convidado_id'];
        $novo = (int)$_POST['status_atual'] === 1 ? 0 : 1;
        $pdo->prepare("UPDATE convidados SET confirmado = ? WHERE id = ? AND evento_id = ?")
            ->execute([$novo, $id, $evento_id]);
        if ($ajax) json_out(['ok' => true, 'novo' => $novo]);
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 12. Excluir convidado (AJAX)
    if (isset($_POST['excluir_convidado'])) {
        $id  = (int)$_POST['convidado_id'];
        $chk = $pdo->prepare("SELECT confirmado FROM convidados WHERE id = ? AND evento_id = ?");
        $chk->execute([$id, $evento_id]);
        $row = $chk->fetch();
        $pdo->prepare("DELETE FROM convidados WHERE id = ? AND evento_id = ?")
            ->execute([$id, $evento_id]);
        if ($ajax) json_out(['ok' => true, 'era_conf' => $row ? (int)$row['confirmado'] : 0]);
        $_SESSION['msg_sucesso'] = "Convidado removido!";
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 13. Editar locais
    if (isset($_POST['editar_locais'])) {
        $local_cer  = trim($_POST['local_cerimonia']);
        $tem_festa  = (int)$_POST['tem_festa'];
        $local_festa = $tem_festa === 1 ? trim($_POST['local_festa']) : null;
        $pdo->prepare("UPDATE eventos SET local_cerimonia = ?, tem_festa = ?, local_festa = ? WHERE id = ?")
            ->execute([$local_cer, $tem_festa, $local_festa, $evento_id]);
        $_SESSION['msg_sucesso'] = "Locais atualizados!";
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }
}

/* ============================================================
   CARREGAMENTO DE DADOS
   ============================================================ */

// Evento
$s = $pdo->prepare("SELECT e.*, c.nome, c.email, c.telefone, c.cpf FROM eventos e INNER JOIN clientes c ON e.cliente_id = c.id WHERE e.id = ?");
$s->execute([$evento_id]);
$evento = $s->fetch();
if (!$evento) { die("Evento não encontrado."); }

// Fornecedores contratados
$rs = $pdo->prepare("SELECT * FROM fornecedores_evento WHERE evento_id = ? AND status = 'Contratado' ORDER BY nome ASC");
$rs->execute([$evento_id]);
$lista_forn = $rs->fetchAll();
$total_forn = array_sum(array_column($lista_forn, 'valor'));

// Convidados
$rs2 = $pdo->prepare("SELECT * FROM convidados WHERE evento_id = ? ORDER BY nome ASC");
$rs2->execute([$evento_id]);
$lista_conv = $rs2->fetchAll();
$total_conf = 0; $total_pend = 0;
$conv_grupos = ['Família' => [], 'Amigos' => [], 'Outros' => []];
foreach ($lista_conv as $c) {
    $c['confirmado'] ? $total_conf++ : $total_pend++;
    $cat = $c['categoria'] ?: 'Outros';
    if (!array_key_exists($cat, $conv_grupos)) $conv_grupos[$cat] = [];
    $conv_grupos[$cat][] = $c;
}

// Checklist
$rs3 = $pdo->prepare("SELECT * FROM checklist WHERE evento_id = ? ORDER BY etapa ASC, id ASC");
$rs3->execute([$evento_id]);
$lista_checklist = $rs3->fetchAll();

// FIX N+1 – precarrega comentários de TODAS as tarefas em 1 query
$ids = array_column($lista_checklist, 'id');
$coments_tarefa = [];
if (!empty($ids)) {
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $rs4 = $pdo->prepare("SELECT * FROM checklist_comentarios WHERE checklist_id IN ($ph) ORDER BY data_cadastro ASC");
    $rs4->execute($ids);
    foreach ($rs4->fetchAll() as $c) { $coments_tarefa[$c['checklist_id']][] = $c; }
}

// FIX N+1 – precarrega comentários de TODAS as etapas em 1 query (era N queries no loop!)
$rs5 = $pdo->query("SELECT * FROM checklist_comentarios WHERE etapa_nome IS NOT NULL ORDER BY data_cadastro ASC");
$coments_etapa = [];
foreach ($rs5->fetchAll() as $c) { $coments_etapa[$c['etapa_nome']][] = $c; }

// Agrupamento e progresso
$passos = []; $prog = [];
$total_g = 0; $conc_g = 0;
foreach ($lista_checklist as $t) {
    $e = $t['etapa'];
    $passos[$e][] = $t;
    if (!isset($prog[$e])) $prog[$e] = ['total' => 0, 'conc' => 0];
    $prog[$e]['total']++;
    $total_g++;
    $done = ($t['status'] === 'concluido' || $t['checado'] == 1);
    if ($done) { $prog[$e]['conc']++; $conc_g++; }
}
$pct_g = $total_g > 0 ? round($conc_g / $total_g * 100) : 0;

// Dias para o evento
$hoje = (new DateTime())->setTime(0, 0, 0);
$dev  = (new DateTime($evento['data_evento']))->setTime(0, 0, 0);
$diff = $hoje->diff($dev);
$dias = $diff->invert ? -$diff->days : $diff->days;

// Flash messages
$msg_sucesso = $_SESSION['msg_sucesso'] ?? '';
$msg_erro    = $_SESSION['msg_erro'] ?? '';
unset($_SESSION['msg_sucesso'], $_SESSION['msg_erro']);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gerenciar Evento</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
  <link rel="stylesheet" href="css/estilo.css">
</head>
<body>

<div id="toast-wrap"></div>

<div class="modal fade" id="modalConfirmar" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content border-0 shadow-lg rounded-4 p-3 text-center">
      <div class="py-2">
        <div id="confirm-icon-box"
             class="mx-auto mb-3 rounded-circle bg-danger bg-opacity-10 d-flex align-items-center justify-content-center"
             style="width:52px;height:52px;">
          <i id="confirm-icon" class="bi bi-exclamation-triangle-fill text-danger fs-4"></i>
        </div>
        <h6 id="confirm-title" class="fw-bold mb-1">Tem certeza?</h6>
        <p id="confirm-msg" class="text-muted small mb-0">Esta ação não pode ser desfeita.</p>
      </div>
      <div class="d-flex justify-content-center gap-2 mt-3">
        <button class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancelar</button>
        <button id="btn-confirmar" class="btn btn-danger btn-sm px-4 rounded-pill fw-bold">Confirmar</button>
      </div>
    </div>
  </div>
</div>

<form id="form-import-com-rec" method="POST" hidden>
  <input type="hidden" name="gerar_padrao" value="1">
  <input type="hidden" name="tipo_padrao" value="com_recepcao">
</form>
<form id="form-import-sem-rec" method="POST" hidden>
  <input type="hidden" name="gerar_padrao" value="1">
  <input type="hidden" name="tipo_padrao" value="sem_recepcao">
</form>
<form id="form-limpar-checklist" method="POST" hidden>
  <input type="hidden" name="excluir_todo_checklist" value="1">
</form>


<div class="container my-4 my-md-5">

  <div class="card border-0 shadow-sm mb-4 overflow-hidden" style="border-radius: var(--radius);">

    <div class="header-topo p-4 d-flex flex-wrap justify-content-between align-items-start gap-3">
      <div>
        <a href="painel_admin.php" class="btn btn-sm btn-outline-light mb-3 rounded-3">
          <i class="bi bi-arrow-left me-1"></i> Voltar ao Painel
        </a>
        <h2 class="fw-bold mb-1 text-white" style="letter-spacing:-.5px;">
          <i class="bi bi-rings text-warning me-2"></i>
          Casamento de <?= htmlspecialchars($evento['nome']) ?>
        </h2>
        <p class="text-white-50 mb-3 small">Painel de controle do evento</p>

        <div class="d-flex flex-wrap gap-2">
          <span class="badge bg-white bg-opacity-10 text-white px-3 py-2 rounded-pill">
            <i class="bi bi-calendar-event me-1 text-warning"></i>
            <?= date('d/m/Y', strtotime($evento['data_evento'])) ?>
          </span>

          <?php if (!empty($evento['hora_evento'])): ?>
          <span class="badge bg-white bg-opacity-10 text-white px-3 py-2 rounded-pill">
            <i class="bi bi-clock me-1 text-warning"></i>
            <?= date('H:i', strtotime($evento['hora_evento'])) ?>
          </span>
          <?php endif; ?>

          <?php if ($dias > 0): ?>
            <span class="badge bg-success bg-opacity-25 text-white border border-success border-opacity-25 px-3 py-2 rounded-pill fw-bold">
              Faltam <?= $dias ?> dias!
            </span>
          <?php elseif ($dias === 0): ?>
            <span class="badge bg-warning bg-opacity-25 text-warning border border-warning border-opacity-25 px-3 py-2 rounded-pill fw-bold">
              É Hoje! <i class="bi bi-stars"></i>
            </span>
          <?php else: ?>
            <span class="badge bg-secondary bg-opacity-25 text-white border border-secondary border-opacity-25 px-3 py-2 rounded-pill fw-bold">
              Realizado há <?= abs($dias) ?> dias
            </span>
          <?php endif; ?>
        </div>
      </div>

      <div class="d-flex align-items-center gap-3">
        <?php if ($total_g > 0):
          $r_   = 28;
          $circ = 2 * M_PI * $r_;
          $off  = $circ - ($circ * $pct_g / 100); ?>
        <div class="ring-wrap" title="<?= $pct_g ?>% do checklist concluído">
          <svg width="72" height="72" viewBox="0 0 72 72">
            <circle cx="36" cy="36" r="<?= $r_ ?>" fill="none" stroke="rgba(255,255,255,.2)" stroke-width="6"/>
            <circle cx="36" cy="36" r="<?= $r_ ?>" fill="none" stroke="#22c55e" stroke-width="6"
              stroke-dasharray="<?= number_format($circ, 2, '.', '') ?>"
              stroke-dashoffset="<?= number_format($off, 2, '.', '') ?>"
              stroke-linecap="round"/>
          </svg>
          <div class="ring-label">
            <span id="ring-pct"><?= $pct_g ?>%</span>
            <span style="font-size:.5rem;opacity:.7;">feito</span>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="bg-white p-3 d-flex flex-wrap justify-content-between align-items-center border-top">
      <div class="d-flex flex-wrap gap-4">
        <div class="d-flex align-items-center gap-2 text-muted small">
          <span class="bg-light rounded-circle p-2 d-flex"><i class="bi bi-envelope-fill text-primary"></i></span>
          <?= htmlspecialchars($evento['email']) ?>
        </div>
        <?php if (!empty($evento['telefone'])): ?>
        <div class="d-flex align-items-center gap-2 text-muted small">
          <span class="bg-light rounded-circle p-2 d-flex"><i class="bi bi-whatsapp text-success"></i></span>
          <?= htmlspecialchars($evento['telefone']) ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($evento['cpf'])): ?>
        <div class="d-flex align-items-center gap-2 text-muted small">
          <span class="bg-light rounded-circle p-2 d-flex"><i class="bi bi-person-vcard text-secondary"></i></span>
          <?= htmlspecialchars($evento['cpf']) ?>
        </div>
        <?php endif; ?>
      </div>
      <span class="badge bg-light text-dark border shadow-sm px-3 py-2 rounded-pill" style="font-size:.7rem;">
        Contrato #<?= str_pad($evento['id'], 4, '0', STR_PAD_LEFT) ?>
      </span>
    </div>
  </div>

  <div class="row g-4 align-items-start">

    <div class="col-md-7">

      <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
          <h4 class="mb-0 fw-bold"><i class="bi bi-list-check text-primary me-2"></i> Checklist</h4>
          <?php if ($total_g > 0): ?>
          <div class="text-muted small mt-1">
            <span id="label-conc-g"><?= $conc_g ?></span> de <?= $total_g ?> tarefas concluídas
            <div class="barra mt-1" style="max-width:140px;">
              <div class="barra-fill" id="barra-g" style="width:<?= $pct_g ?>%;"></div>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <div class="d-flex flex-wrap gap-2">
          <button type="button" class="btn btn-sm btn-outline-success rounded-3 btn-import-padrao"
                  data-form="form-import-com-rec"
                  data-msg="Isso adicionará todas as tarefas padrão (com recepção) ao evento."
                  data-titulo="Importar cronograma?">
            <i class="bi bi-download me-1"></i> Com Recepção
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary rounded-3 btn-import-padrao"
                  data-form="form-import-sem-rec"
                  data-msg="Isso adicionará todas as tarefas padrão (sem recepção) ao evento."
                  data-titulo="Importar cronograma?">
            <i class="bi bi-download me-1"></i> Sem Recepção
          </button>
          <button type="button" class="btn btn-sm btn-primary rounded-3" data-bs-toggle="modal" data-bs-target="#modalManual">
            <i class="bi bi-plus-lg me-1"></i> Manual
          </button>
        </div>
      </div>

      <?php if (empty($passos)): ?>
        <div class="card border-0 shadow-sm text-center py-5 text-muted" style="border-radius: var(--radius);">
          <i class="bi bi-info-circle fs-1 mb-2"></i>
          <p class="mb-0">Checklist vazio. Use os botões acima para importar um modelo ou adicionar tarefas.</p>
        </div>
      <?php else: ?>

        <div class="d-flex flex-column gap-3">
          <?php $idx = 0; foreach ($passos as $etapa => $tarefas): $idx++;
            $totE  = $prog[$etapa]['total'];
            $concE = $prog[$etapa]['conc'];
            $pctE  = $totE > 0 ? round($concE / $totE * 100) : 0;
            $ok    = ($totE > 0 && $concE === $totE);
            $label = is_numeric($etapa) ? 'PASSO ' . str_pad($etapa, 2, '0', STR_PAD_LEFT) : $etapa;
            $cid   = 'etapa_' . $idx;
          ?>
          <div class="card border-0 shadow-sm overflow-hidden" style="border-radius:12px;">

            <div class="etapa-hdr"
                 data-bs-toggle="collapse"
                 data-bs-target="#<?= $cid ?>"
                 aria-expanded="false"
                 id="hdr-<?= $cid ?>">
              <div class="d-flex align-items-center gap-2">
                <i class="bi <?= $ok ? 'bi-check-all text-success' : 'bi-folder2-open text-info' ?> fs-5 icone-etapa"></i>
                <span class="fw-bold" style="font-size:.88rem;"><?= htmlspecialchars($label) ?></span>
              </div>
              <div class="d-flex align-items-center gap-3">
                <div class="d-none d-sm-flex align-items-center gap-2">
                  <div class="barra-mini-wrap">
                    <div class="barra-mini-fill" style="width:<?= $pctE ?>%;"></div>
                  </div>
                  <span class="text-white-50 pct-etapa" style="font-size:.72rem;min-width:30px;"><?= $pctE ?>%</span>
                </div>
                <span class="badge bg-white bg-opacity-20 text-white rounded-pill px-2">
                  <span class="conc-etapa"><?= $concE ?></span>/<?= $totE ?>
                </span>
                <i class="bi bi-chevron-down text-white-50 small"></i>
              </div>
            </div>

            <div id="<?= $cid ?>" class="collapse">
              <div class="etapa-body p-3 bg-white">

                <div class="p-3 mb-3 bg-light rounded-3 border small">
                  <div class="fw-bold text-muted mb-2" style="font-size:.72rem;text-transform:uppercase;">
                    <i class="bi bi-journal-text me-1"></i> Anotações desta Etapa
                  </div>
                  <div class="lista-coment-etapa mb-2">
                    <?php foreach ($coments_etapa[$etapa] ?? [] as $ce):
                      $cor = $ce['autor'] === 'Noivos' ? 'bg-danger' : 'bg-primary'; ?>
                      <div class="my-1 bg-white border p-2 rounded-3 shadow-sm" style="font-size:.82rem;">
                        <span class="badge <?= $cor ?> rounded-pill me-2"><?= htmlspecialchars($ce['autor']) ?></span>
                        <?= htmlspecialchars($ce['comentario']) ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <form class="d-flex gap-2 form-ajax-etapa">
                    <input type="hidden" name="comentario_etapa_admin" value="1">
                    <input type="hidden" name="etapa_nome" value="<?= htmlspecialchars($etapa) ?>">
                    <input type="text" name="novo_comentario_etapa" class="form-control form-control-sm" placeholder="Nota geral para os noivos…" required>
                    <button type="submit" class="btn btn-sm btn-dark px-3">Salvar</button>
                  </form>
                </div>

                <?php foreach ($tarefas as $t):
                  $tid  = $t['id'];
                  $done = ($t['status'] === 'concluido' || $t['checado'] == 1);
                  $snum = $done ? 1 : 0;
                ?>
                <div class="tarefa-card card border-0 bg-white mb-2 shadow-sm <?= $done ? 'done' : 'pend' ?>">
                  <div class="card-body p-3">
                    <div class="d-flex align-items-start gap-3">

                      <button type="button"
                              class="btn p-0 border-0 btn-chk text-<?= $done ? 'success' : 'muted' ?> btn-toggle-tarefa"
                              data-id="<?= $tid ?>"
                              data-status="<?= $snum ?>"
                              data-etapa-hdr-id="hdr-<?= $cid ?>"
                              data-etapa-total="<?= $totE ?>"
                              title="<?= $done ? 'Desmarcar' : 'Marcar como concluída' ?>">
                        <i class="bi <?= $done ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i>
                      </button>

                      <div class="w-100">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                          <div>
                            <h6 class="fw-bold mb-1 <?= $done ? 'text-muted text-decoration-line-through' : 'text-dark' ?>" style="line-height:1.4;">
                              <?= htmlspecialchars($t['tarefa']) ?>
                            </h6>
                            <?php if (!empty($t['descricao'])): ?>
                            <button class="btn btn-sm btn-outline-secondary py-0 px-2 rounded-pill"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalDesc_<?= $tid ?>"
                                    style="font-size:.72rem;">
                              <i class="bi bi-file-text"></i> Ler
                            </button>
                            <?php endif; ?>
                          </div>

                          <div class="task-actions">
                            <button type="button"
                                    class="btn btn-sm btn-outline-primary py-0 px-2 rounded"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalEditar_<?= $tid ?>"
                                    title="Editar tarefa">
                              <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button"
                                    class="btn btn-sm btn-outline-danger py-0 px-2 rounded btn-del-task"
                                    data-id="<?= $tid ?>"
                                    title="Remover tarefa">
                              <i class="bi bi-trash"></i>
                            </button>
                          </div>
                        </div>

                        <div class="border-top pt-2">
                          <div class="lista-coment-tarefa mb-2">
                            <?php foreach ($coments_tarefa[$tid] ?? [] as $cm):
                              $corC = $cm['autor'] === 'Noivos' ? 'text-danger' : 'text-primary'; ?>
                              <div class="small my-1 bg-light p-2 rounded-3" style="font-size:.77rem;border:1px solid #f1f5f9;">
                                <strong class="<?= $corC ?>"><?= htmlspecialchars($cm['autor']) ?>:</strong>
                                <?= htmlspecialchars($cm['comentario']) ?>
                              </div>
                            <?php endforeach; ?>
                          </div>
                          <form class="d-flex gap-2 form-ajax-tarefa">
                            <input type="hidden" name="adicionar_comentario" value="1">
                            <input type="hidden" name="id_tarefa" value="<?= $tid ?>">
                            <input type="text" name="texto_comentario" class="form-control form-control-sm bg-light border-0" placeholder="Comentar nesta tarefa…" required>
                            <button type="submit" class="btn btn-sm btn-outline-primary px-3" title="Enviar">
                              <i class="bi bi-send-fill"></i>
                            </button>
                          </form>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <?php endforeach; ?>

              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="text-end mt-3">
          <button type="button" class="btn btn-sm btn-outline-danger rounded-3" id="btn-limpar-checklist">
            <i class="bi bi-trash me-1"></i> Limpar Todo o Checklist
          </button>
        </div>

      <?php endif; ?>
    </div>

    <div class="col-md-5">
      <div class="sidebar-sticky d-flex flex-column gap-4">

        <div class="card border-0 shadow-sm overflow-hidden card-inspiracoes" style="border-radius: var(--radius);">
          <div class="card-body d-flex justify-content-between align-items-center p-3">
            <div class="d-flex align-items-center gap-3">
              <div class="bg-white rounded-3 d-flex align-items-center justify-content-center shadow-sm" style="width:44px;height:44px;">
                <i class="bi bi-stars fs-4 text-indigo" style="color:#6366f1;"></i>
              </div>
              <div>
                <h6 class="mb-0 fw-bold text-white">Mural de Inspirações</h6>
                <small class="text-white-50" style="font-size:.78rem;">Referências, paletas e ideias</small>
              </div>
            </div>
            <a href="inspiracoes.php?id=<?= $evento_id ?>" class="btn btn-light btn-sm fw-bold rounded-pill px-3 shadow-sm" style="color:#4f46e5;">
              Acessar <i class="bi bi-arrow-right ms-1"></i>
            </a>
          </div>
        </div>

        <div class="card shadow-sm border-0" style="border-radius: var(--radius);">
          <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
              <h6 class="fw-bold mb-0"><i class="bi bi-geo-alt-fill text-danger me-1"></i> Locais do Evento</h6>
              <button class="btn btn-sm btn-outline-primary py-0 px-2 rounded-pill"
                      data-bs-toggle="modal" data-bs-target="#modalLocais">Editar</button>
            </div>

            <div class="mb-2">
              <div class="text-muted fw-bold mb-1" style="font-size:.68rem;text-transform:uppercase;">Cerimônia</div>
              <div class="d-flex align-items-start gap-2">
                <i class="bi bi-church text-secondary mt-1" style="font-size:.85rem;"></i>
                <span class="small text-dark fw-medium">
                  <?= !empty($evento['local_cerimonia']) ? htmlspecialchars($evento['local_cerimonia']) : '<span class="text-muted fst-italic">A definir…</span>' ?>
                </span>
              </div>
            </div>

            <?php if ($evento['tem_festa'] == 1): ?>
            <div class="mt-3 pt-2 border-top">
              <div class="text-muted fw-bold mb-1" style="font-size:.68rem;text-transform:uppercase;">Recepção / Festa</div>
              <div class="d-flex align-items-start gap-2">
                <i class="bi bi-balloon-heart text-secondary mt-1" style="font-size:.85rem;"></i>
                <span class="small text-dark fw-medium">
                  <?= !empty($evento['local_festa']) ? htmlspecialchars($evento['local_festa']) : '<span class="text-muted fst-italic">A definir…</span>' ?>
                </span>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card shadow-sm border-0" style="border-radius: var(--radius);">
          <div class="etapa-hdr" style="border-radius: var(--radius);"
               data-bs-toggle="collapse" data-bs-target="#collapseEquipe" aria-expanded="false">
            <div class="d-flex align-items-center gap-2">
              <i class="bi bi-person-badge-fill text-info fs-5"></i>
              <span class="fw-bold" style="font-size:.88rem;">Equipe Contratada</span>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="badge bg-white bg-opacity-20 text-white rounded-pill px-2">
                <?= count($lista_forn) ?> contratos
              </span>
              <i class="bi bi-chevron-down text-white-50 small"></i>
            </div>
          </div>

          <div class="collapse" id="collapseEquipe">
            <div class="etapa-body bg-white">
              <div class="p-2 border-bottom d-flex justify-content-between align-items-center bg-light">
                <small class="text-muted fw-bold ms-1" style="font-size:.7rem;text-transform:uppercase;">Ações</small>
                <div class="d-flex gap-2">
                  <button class="btn btn-sm btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#modalFornecedor">
                    <i class="bi bi-plus-lg me-1"></i> Novo
                  </button>
                  <a href="fornecedores_evento.php?id=<?= $evento_id ?>" class="btn btn-sm btn-outline-dark shadow-sm">
                    <i class="bi bi-gear-fill me-1"></i> Completo
                  </a>
                </div>
              </div>

              <div class="scroll-lista-pequena">
                <table class="table table-hover table-sm align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th class="ps-3 py-2 text-secondary" style="font-size:.75rem;">Serviço / Fornecedor</th>
                      <th class="text-end pe-3 py-2 text-secondary" style="font-size:.75rem;">Valor</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($lista_forn)): ?>
                      <tr><td colspan="2" class="text-center text-muted py-4 small">Nenhum fornecedor contratado ainda.</td></tr>
                    <?php else: ?>
                      <?php foreach ($lista_forn as $f): ?>
                      <tr>
                        <td class="ps-3 py-2">
                          <div class="fw-bold text-dark small"><?= htmlspecialchars($f['servico']) ?></div>
                          <div class="text-muted" style="font-size:.72rem;"><?= htmlspecialchars($f['nome']) ?></div>
                        </td>
                        <td class="text-end pe-3 fw-bold text-success py-2 small">
                          R$ <?= number_format($f['valor'], 2, ',', '.') ?>
                        </td>
                      </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                  <?php if (!empty($lista_forn)): ?>
                  <tfoot class="table-light">
                    <tr>
                      <td class="ps-3 py-2 text-end fw-bold text-secondary" style="font-size:.72rem;text-transform:uppercase;">Total Contratado:</td>
                      <td class="text-end pe-3 py-2 fw-bold text-dark">R$ <?= number_format($total_forn, 2, ',', '.') ?></td>
                    </tr>
                  </tfoot>
                  <?php endif; ?>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="card shadow-sm border-0" style="border-radius: var(--radius);">
          <div class="etapa-hdr" style="border-radius: var(--radius); background: #334155;"
               data-bs-toggle="collapse" data-bs-target="#collapseConvidados" aria-expanded="false">
            <div class="d-flex align-items-center gap-2">
              <i class="bi bi-people-fill text-info fs-5"></i>
              <span class="fw-bold" style="font-size:.88rem;">Gestão de Convidados</span>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="badge bg-white bg-opacity-20 text-white rounded-pill px-2">
                <span id="cnt-badge-total"><?= count($lista_conv) ?></span> na lista
              </span>
              <i class="bi bi-chevron-down text-white-50 small"></i>
            </div>
          </div>

          <div class="collapse" id="collapseConvidados">
            <div class="etapa-body bg-white p-3">

              <button type="button" class="btn btn-success w-100 btn-sm fw-bold shadow-sm mb-3 py-2 rounded-3"
                      data-bs-toggle="modal" data-bs-target="#modalAddConvidado">
                <i class="bi bi-person-plus-fill me-1"></i> Cadastrar Novo Convidado
              </button>

              <div class="row g-2 mb-3">
                <div class="col-6">
                  <div class="bg-success bg-opacity-10 rounded-3 p-3 text-center border border-success border-opacity-25">
                    <h4 class="mb-0 fw-bold text-success" id="cnt-conf"><?= $total_conf ?></h4>
                    <small class="text-muted" style="font-size:.7rem;">Confirmados</small>
                  </div>
                </div>
                <div class="col-6">
                  <div class="bg-warning bg-opacity-10 rounded-3 p-3 text-center border border-warning border-opacity-25">
                    <h4 class="mb-0 fw-bold" id="cnt-pend" style="color:#d97706;"><?= $total_pend ?></h4>
                    <small class="text-muted" style="font-size:.7rem;">Pendentes</small>
                  </div>
                </div>
              </div>

              <div class="fw-bold text-muted mb-2" style="font-size:.72rem;text-transform:uppercase;">
                Lista completa (<span id="cnt-total"><?= count($lista_conv) ?></span>)
              </div>

              <div id="lista-convidados" class="scroll-lista-grande">
                <?php if (empty($lista_conv)): ?>
                  <p class="text-center text-muted small py-4 mb-0">Nenhum convidado cadastrado.</p>
                <?php else: ?>
                  <?php $grp_icons = ['Família' => 'bi-house-heart-fill', 'Amigos' => 'bi-emoji-sunglasses-fill', 'Outros' => 'bi-collection-fill'];
                  foreach (['Família', 'Amigos', 'Outros'] as $grp):
                    if (empty($conv_grupos[$grp])) continue; ?>
                  <div class="grupo-sec" data-grupo="<?= $grp ?>">
                    <div class="badge bg-secondary text-white w-100 text-start px-3 py-2 rounded-2 mb-1 mt-2 sec-badge">
                      <i class="bi <?= $grp_icons[$grp] ?> me-1"></i>
                      <?= $grp ?> (<span class="cnt-grp"><?= count($conv_grupos[$grp]) ?></span>)
                    </div>
                    <?php foreach ($conv_grupos[$grp] as $con):
                      $cConf = (bool)$con['confirmado']; ?>
                    <div class="conv-row <?= $cConf ? 'conf' : 'pend' ?> p-2 mb-2 bg-light shadow-sm"
                         data-id="<?= $con['id'] ?>"
                         data-conf="<?= (int)$cConf ?>"
                         data-nome="<?= strtolower(htmlspecialchars($con['nome'])) ?>">

                      <div class="d-flex justify-content-between align-items-start mb-1">
                        <h6 class="mb-0 small fw-bold text-dark text-truncate pe-2" title="<?= htmlspecialchars($con['nome']) ?>">
                          <?= htmlspecialchars($con['nome']) ?>
                        </h6>
                        <div class="d-flex align-items-center gap-1 flex-shrink-0">
                          <button type="button" class="btn p-0 border-0 bg-transparent btn-toggle-conv" data-id="<?= $con['id'] ?>">
                            <span class="badge <?= $cConf ? 'bg-success' : 'bg-warning text-dark' ?> rounded-pill" style="font-size:.6rem;">
                              <?= $cConf
                                ? '<i class="bi bi-check-circle-fill me-1"></i> Confirmado'
                                : '<i class="bi bi-hourglass-split me-1"></i> Pendente' ?>
                            </span>
                          </button>
                          <button type="button" class="btn p-0 border-0 bg-transparent text-danger btn-excluir-conv" data-id="<?= $con['id'] ?>" title="Remover">
                            <i class="bi bi-trash fs-6"></i>
                          </button>
                        </div>
                      </div>

                      <?php if (!empty($con['telefone']) || !empty($con['acompanhantes']) || !empty($con['filhos'])): ?>
                      <div class="text-muted border-top pt-1 mt-1" style="font-size:.67rem;line-height:1.5;">
                        <?php if (!empty($con['telefone'])): ?>
                          <div><i class="bi bi-whatsapp me-1 text-success"></i><?= htmlspecialchars($con['telefone']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($con['acompanhantes'])): ?>
                          <div><i class="bi bi-person-plus me-1"></i>Acomp: <?= htmlspecialchars($con['acompanhantes']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($con['filhos'])): ?>
                          <div><i class="bi bi-emoji-smile me-1"></i>Filhos: <?= htmlspecialchars($con['filhos']) ?></div>
                        <?php endif; ?>
                      </div>
                      <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                  </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>

            </div>
          </div>
        </div>

      </div></div>
  </div></div><div class="modal fade" id="modalManual" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header bg-light border-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle text-primary me-2"></i> Adicionar Tarefa Manual</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="adicionar_manual" value="1">
        <div class="modal-body p-4">
          <div class="mb-3">
            <label class="form-label small fw-bold text-secondary">Etapa (Número ou Nome)</label>
            <input type="text" name="etapa" class="form-control" placeholder="Ex: 1  ou  Pré-Casamento" required>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-bold text-secondary">Nome da Tarefa</label>
            <input type="text" name="tarefa" class="form-control" required>
          </div>
          <div class="mb-1">
            <label class="form-label small fw-bold text-secondary">Descrição (opcional)</label>
            <textarea name="descricao" class="form-control" rows="3" placeholder="Detalhes, instruções…"></textarea>
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-bold">Adicionar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="modalFornecedor" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header bg-light border-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-person-plus text-success me-2"></i> Adicionar Fornecedor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="adicionar_fornecedor" value="1">
        <div class="modal-body p-4">
          <div class="mb-3">
            <label class="form-label small fw-bold text-secondary">Serviço Prestado</label>
            <input type="text" name="servico_fornecedor" class="form-control" placeholder="Ex: Fotografia, Buffet…" required>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-bold text-secondary">Nome / Empresa</label>
            <input type="text" name="nome_fornecedor" class="form-control" required>
          </div>
          <div class="row g-3">
            <div class="col-6">
              <label class="form-label small fw-bold text-secondary">Status</label>
              <select name="status_fornecedor" class="form-select">
                <option value="Contratado">Contratado</option>
                <option value="Orçamento">Apenas Orçamento</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label small fw-bold text-secondary">Valor (R$)</label>
              <input type="number" step="0.01" min="0" name="valor_fornecedor" class="form-control" placeholder="0,00">
            </div>
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-success btn-sm px-4 rounded-pill fw-bold">Salvar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="modalLocais" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header bg-light border-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-geo-alt-fill text-danger me-2"></i> Locais do Evento</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="editar_locais" value="1">
        <div class="modal-body p-4">
          <div class="mb-3">
            <label class="form-label small fw-bold text-secondary">Local da Cerimônia</label>
            <input type="text" name="local_cerimonia" class="form-control" placeholder="Igreja, Cartório…"
                   value="<?= htmlspecialchars($evento['local_cerimonia'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-bold text-secondary">Haverá recepção em outro local?</label>
            <select name="tem_festa" id="select-tem-festa" class="form-select">
              <option value="0" <?= ($evento['tem_festa'] == 0) ? 'selected' : '' ?>>Não (mesmo local)</option>
              <option value="1" <?= ($evento['tem_festa'] == 1) ? 'selected' : '' ?>>Sim, outro local</option>
            </select>
          </div>
          <div id="div-local-festa" <?= ($evento['tem_festa'] == 1) ? '' : 'style="display:none;"' ?>>
            <label class="form-label small fw-bold text-secondary">Local da Recepção / Festa</label>
            <input type="text" name="local_festa" class="form-control" placeholder="Espaço, Salão…"
                   value="<?= htmlspecialchars($evento['local_festa'] ?? '') ?>">
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-bold">Salvar Locais</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php foreach ($lista_checklist as $t):
  $tid = $t['id']; ?>

  <?php if (!empty($t['descricao'])): ?>
  <div class="modal fade" id="modalDesc_<?= $tid ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg rounded-4">
        <div class="modal-header bg-light border-0">
          <h5 class="modal-title fw-bold"><i class="bi bi-card-text text-primary me-2"></i> Detalhes</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-4">
          <h6 class="fw-bold mb-3 border-bottom pb-2"><?= htmlspecialchars($t['tarefa']) ?></h6>
          <div style="white-space:pre-wrap;font-size:.93rem;line-height:1.7;"><?= htmlspecialchars(trim($t['descricao'])) ?></div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button class="btn btn-secondary btn-sm px-4 rounded-pill fw-bold" data-bs-dismiss="modal">Fechar</button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="modal fade" id="modalEditar_<?= $tid ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg rounded-4">
        <div class="modal-header bg-light border-0">
          <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square text-primary me-2"></i> Editar Tarefa</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form method="POST">
          <input type="hidden" name="editar_tarefa" value="1">
          <input type="hidden" name="id_tarefa" value="<?= $tid ?>">
          <div class="modal-body p-4">
            <div class="mb-3">
              <label class="form-label small fw-bold text-secondary">Etapa</label>
              <input type="text" name="etapa_edit" class="form-control" value="<?= htmlspecialchars($t['etapa']) ?>" required>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-bold text-secondary">Tarefa</label>
              <input type="text" name="tarefa_edit" class="form-control" value="<?= htmlspecialchars($t['tarefa']) ?>" required>
            </div>
            <div class="mb-1">
              <label class="form-label small fw-bold text-secondary">Descrição</label>
              <textarea name="descricao_edit" class="form-control" rows="4"><?= htmlspecialchars($t['descricao']) ?></textarea>
            </div>
          </div>
          <div class="modal-footer border-0 pt-0">
            <button type="button" class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-bold">Salvar</button>
          </div>
        </form>
      </div>
    </div>
  </div>

<?php endforeach; ?>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ============================================================
   CONSTANTES & HELPERS
   ============================================================ */
const SELF = window.location.href;

/** Toast temporário */
function toast(msg, tipo = 'verde') {
  const wrap = document.getElementById('toast-wrap');
  const el   = document.createElement('div');
  el.className = `toast-item ${tipo}`;
  const icons = { verde: 'check-circle-fill', verm: 'exclamation-circle-fill', info: 'info-circle-fill', warn: 'exclamation-triangle-fill' };
  el.innerHTML = `<i class="bi bi-${icons[tipo] || 'check-circle-fill'}"></i> ${msg}`;
  wrap.appendChild(el);
  setTimeout(() => {
    el.style.transition = 'opacity .3s, transform .3s';
    el.style.opacity    = '0';
    el.style.transform  = 'translateX(24px)';
    setTimeout(() => el.remove(), 320);
  }, 2800);
}

/** POST AJAX */
async function ajax(obj) {
  obj.is_ajax = '1';
  const fd = new FormData();
  Object.entries(obj).forEach(([k, v]) => fd.append(k, v));
  const r = await fetch(SELF, { method: 'POST', body: fd });
  return r.json();
}

/* ============================================================
   MODAL DE CONFIRMAÇÃO FLEXÍVEL
   ============================================================ */
const confirmModal = new bootstrap.Modal(document.getElementById('modalConfirmar'));
let _confirmAction = null;

function showConfirm(titulo, msg, onConfirm, opts = {}) {
  document.getElementById('confirm-title').textContent = titulo;
  document.getElementById('confirm-msg').textContent   = msg;

  const box = document.getElementById('confirm-icon-box');
  box.className = `mx-auto mb-3 rounded-circle d-flex align-items-center justify-content-center ${opts.iconBg || 'bg-danger bg-opacity-10'}`;
  box.style.cssText = 'width:52px;height:52px;';

  document.getElementById('confirm-icon').className = opts.icon || 'bi bi-exclamation-triangle-fill text-danger fs-4';

  const btn = document.getElementById('btn-confirmar');
  btn.className   = `btn btn-sm px-4 rounded-pill fw-bold ${opts.btnClass || 'btn-danger'}`;
  btn.textContent = opts.btnText || 'Confirmar';

  _confirmAction = onConfirm;
  confirmModal.show();
}

document.getElementById('btn-confirmar').addEventListener('click', () => {
  if (_confirmAction) { _confirmAction(); _confirmAction = null; }
  confirmModal.hide();
});

/* ============================================================
   EXIBIR FLASH MESSAGES COMO TOAST
   ============================================================ */
<?php if ($msg_sucesso): ?>
  document.addEventListener('DOMContentLoaded', () => toast(<?= json_encode($msg_sucesso) ?>, 'verde'));
<?php endif; ?>
<?php if ($msg_erro): ?>
  document.addEventListener('DOMContentLoaded', () => toast(<?= json_encode($msg_erro) ?>, 'verm'));
<?php endif; ?>

/* ============================================================
   MODAL LOCAIS – TOGGLE CAMPO FESTA
   ============================================================ */
const selectFesta = document.getElementById('select-tem-festa');
if (selectFesta) {
  selectFesta.addEventListener('change', function () {
    document.getElementById('div-local-festa').style.display = this.value === '1' ? 'block' : 'none';
  });
}

/* ============================================================
   IMPORTAR PADRÃO – botões com confirmação
   ============================================================ */
document.querySelectorAll('.btn-import-padrao').forEach(btn => {
  btn.addEventListener('click', () => {
    const formId = btn.dataset.form;
    showConfirm(
      btn.dataset.titulo || 'Importar cronograma?',
      btn.dataset.msg    || 'As tarefas padrão serão adicionadas ao evento.',
      () => document.getElementById(formId).submit(),
      { icon: 'bi bi-download text-primary fs-4', iconBg: 'bg-primary bg-opacity-10', btnClass: 'btn-primary', btnText: 'Importar' }
    );
  });
});

/* ============================================================
   LIMPAR CHECKLIST
   ============================================================ */
document.getElementById('btn-limpar-checklist')?.addEventListener('click', () => {
  showConfirm(
    'Apagar TODO o Checklist?',
    'Isso remove permanentemente TODAS as tarefas deste evento.',
    () => document.getElementById('form-limpar-checklist').submit(),
    { icon: 'bi bi-trash3-fill text-danger fs-4', btnText: 'Apagar Tudo' }
  );
});

/* ============================================================
   TOGGLE TAREFA (AJAX)
   ============================================================ */
document.querySelectorAll('.btn-toggle-tarefa').forEach(btn => {
  btn.addEventListener('click', async () => {
    const id       = btn.dataset.id;
    const atual    = +btn.dataset.status;
    const card     = btn.closest('.tarefa-card');
    const titulo   = card.querySelector('h6');
    const hdrId    = btn.dataset.etapaHdrId;
    const etaTot   = +btn.dataset.etapaTotal;
    const collapso = btn.closest('.collapse');

    const orig = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm text-secondary"></span>';

    try {
      const r = await ajax({ alternar_status: '1', id_tarefa: id, status_atual: atual });
      if (!r.ok) throw new Error();

      const novo = r.novo === 1 || r.novo === '1';

      btn.innerHTML      = `<i class="bi ${novo ? 'bi-check-circle-fill' : 'bi-circle'}"></i>`;
      btn.dataset.status = novo ? '1' : '0';
      btn.classList.toggle('text-success', novo);
      btn.classList.toggle('text-muted',   !novo);

      card.classList.toggle('done', novo);
      card.classList.toggle('pend', !novo);

      if (titulo) {
        titulo.classList.toggle('text-decoration-line-through', novo);
        titulo.classList.toggle('text-muted', novo);
        titulo.classList.toggle('text-dark',  !novo);
      }

      // Atualiza header da etapa
      const hdr = document.getElementById(hdrId);
      if (hdr) {
        const concluidas = collapso.querySelectorAll('.tarefa-card.done').length;
        const pctE       = etaTot > 0 ? Math.round(concluidas / etaTot * 100) : 0;

        const concSpan = hdr.querySelector('.conc-etapa');
        const baraMini = hdr.querySelector('.barra-mini-fill');
        const pctSpan  = hdr.querySelector('.pct-etapa');
        const icone    = hdr.querySelector('.icone-etapa');

        if (concSpan) concSpan.textContent = concluidas;
        if (baraMini) baraMini.style.width = pctE + '%';
        if (pctSpan)  pctSpan.textContent  = pctE + '%';
        if (icone) {
          icone.className = concluidas === etaTot && etaTot > 0
            ? 'bi bi-check-all text-success fs-5 icone-etapa'
            : 'bi bi-folder2-open text-info fs-5 icone-etapa';
        }
      }

      // Atualiza progresso geral
      const totalDone = document.querySelectorAll('.tarefa-card.done').length;
      const totalAll  = document.querySelectorAll('.tarefa-card').length;
      const pctG      = totalAll > 0 ? Math.round(totalDone / totalAll * 100) : 0;
      const lblG      = document.getElementById('label-conc-g');
      const barG      = document.getElementById('barra-g');
      const ring      = document.getElementById('ring-pct');
      if (lblG)  lblG.textContent  = totalDone;
      if (barG)  barG.style.width  = pctG + '%';
      if (ring)  ring.textContent  = pctG + '%';

      toast(novo ? 'Tarefa concluída! ✓' : 'Tarefa desmarcada.', novo ? 'verde' : 'info');

    } catch {
      btn.innerHTML = orig;
      toast('Erro ao atualizar tarefa.', 'verm');
    }
  });
});

/* ============================================================
   EXCLUIR TAREFA (AJAX)
   ============================================================ */
document.querySelectorAll('.btn-del-task').forEach(btn => {
  btn.addEventListener('click', () => {
    const id   = btn.dataset.id;
    const card = btn.closest('.tarefa-card');

    showConfirm(
      'Remover esta tarefa?',
      'Esta ação não pode ser desfeita.',
      async () => {
        try {
          const r = await ajax({ excluir_tarefa: '1', id_tarefa: id });
          if (r.ok) {
            card.style.transition = 'opacity .3s, transform .3s';
            card.style.opacity    = '0';
            card.style.transform  = 'scale(.95)';
            setTimeout(() => {
              const collapso = card.closest('.collapse');
              card.remove();

              // Atualiza contadores da etapa
              const hdr = collapso.previousElementSibling;
              if (hdr) {
                const remaining  = collapso.querySelectorAll('.tarefa-card').length;
                const concluidas = collapso.querySelectorAll('.tarefa-card.done').length;
                const concSpan   = hdr.querySelector('.conc-etapa');
                // Atualiza o total no badge
                const badge = hdr.querySelector('.badge');
                if (badge) {
                  badge.innerHTML = badge.innerHTML.replace(/\/\d+/, `/${remaining}`);
                }
                if (concSpan) concSpan.textContent = concluidas;
              }

              // Atualiza geral
              const totalDone = document.querySelectorAll('.tarefa-card.done').length;
              const totalAll  = document.querySelectorAll('.tarefa-card').length;
              const pctG      = totalAll > 0 ? Math.round(totalDone / totalAll * 100) : 0;
              const lblG = document.getElementById('label-conc-g');
              const barG = document.getElementById('barra-g');
              const ring = document.getElementById('ring-pct');
              if (lblG) lblG.textContent = totalDone;
              if (barG) barG.style.width = pctG + '%';
              if (ring) ring.textContent = pctG + '%';

            }, 310);
            toast('Tarefa removida!', 'verm');
          }
        } catch { toast('Erro ao remover tarefa.', 'verm'); }
      }
    );
  });
});

/* ============================================================
   COMENTÁRIOS DE ETAPAS (AJAX)
   ============================================================ */
document.querySelectorAll('.form-ajax-etapa').forEach(form => {
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const fd    = new FormData(form);
    const lista = form.previousElementSibling;
    const input = form.querySelector('input[type="text"]');
    const btn   = form.querySelector('button');
    const orig  = btn.innerHTML;
    fd.append('is_ajax', '1');
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    try {
      const r = await (await fetch(SELF, { method: 'POST', body: fd })).json();
      if (r.ok) {
        lista.insertAdjacentHTML('beforeend', `
          <div class="my-1 bg-white border p-2 rounded-3 shadow-sm" style="font-size:.82rem;">
            <span class="badge bg-primary rounded-pill me-2">${r.autor}</span>${r.texto}
          </div>`);
        input.value = '';
        toast('Nota salva!');
      }
    } catch { toast('Erro ao salvar nota.', 'verm'); }
    btn.innerHTML = orig;
  });
});

/* ============================================================
   COMENTÁRIOS DE TAREFAS (AJAX)
   ============================================================ */
document.querySelectorAll('.form-ajax-tarefa').forEach(form => {
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const fd    = new FormData(form);
    const lista = form.previousElementSibling;
    const input = form.querySelector('input[type="text"]');
    const btn   = form.querySelector('button');
    const orig  = btn.innerHTML;
    fd.append('is_ajax', '1');
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    try {
      const r = await (await fetch(SELF, { method: 'POST', body: fd })).json();
      if (r.ok) {
        lista.insertAdjacentHTML('beforeend', `
          <div class="small my-1 bg-light p-2 rounded-3" style="font-size:.77rem;border:1px solid #f1f5f9;">
            <strong class="text-primary">${r.autor}:</strong> ${r.texto}
          </div>`);
        input.value = '';
        toast('Comentário enviado!');
      }
    } catch { toast('Erro ao comentar.', 'verm'); }
    btn.innerHTML = orig;
  });
});

/* ============================================================
   CONVIDADOS – HELPERS
   ============================================================ */
function deltaCntTotal(n) {
  ['cnt-total', 'cnt-badge-total'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.textContent = +el.textContent + n;
  });
}
function deltaCntStatus(conf, n) {
  const el = document.getElementById(conf ? 'cnt-conf' : 'cnt-pend');
  if (el) el.textContent = +el.textContent + n;
}

/* ============================================================
   TOGGLE STATUS DO CONVIDADO
   ============================================================ */
function bindToggleConv(btn) {
  btn.addEventListener('click', async () => {
    const row   = btn.closest('.conv-row');
    const id    = btn.dataset.id;
    const atual = +row.dataset.conf;
    const badge = btn.querySelector('.badge');
    const orig  = badge.innerHTML;
    badge.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    try {
      const r = await ajax({ toggle_convidado: '1', convidado_id: id, status_atual: atual });
      if (r.ok) {
        const novo = r.novo === 1;
        row.dataset.conf = novo ? '1' : '0';
        row.classList.toggle('conf', novo);
        row.classList.toggle('pend', !novo);
        badge.className = `badge ${novo ? 'bg-success' : 'bg-warning text-dark'} rounded-pill`;
        badge.innerHTML = novo
          ? '<i class="bi bi-check-circle-fill me-1"></i> Confirmado'
          : '<i class="bi bi-hourglass-split me-1"></i> Pendente';
        deltaCntStatus(novo,  1);
        deltaCntStatus(!novo, -1);
        toast(novo ? 'Presença confirmada!' : 'Marcado como pendente.', novo ? 'verde' : 'info');
      }
    } catch {
      badge.innerHTML = orig;
      toast('Erro ao atualizar.', 'verm');
    }
  });
}

/* ============================================================
   EXCLUIR CONVIDADO (AJAX + modal confirm)
   ============================================================ */
function bindExcluirConv(btn) {
  btn.addEventListener('click', () => {
    const row  = btn.closest('.conv-row');
    const id   = row.dataset.id;
    const conf = +row.dataset.conf;

    showConfirm(
      'Remover convidado?',
      'Esta ação não pode ser desfeita.',
      async () => {
        try {
          const r = await ajax({ excluir_convidado: '1', convidado_id: id });
          if (r.ok) {
            row.style.transition = 'opacity .3s, transform .3s';
            row.style.opacity    = '0';
            row.style.transform  = 'scale(.95)';
            setTimeout(() => {
              const grupoEl = row.closest('.grupo-sec');
              row.remove();
              if (grupoEl) {
                const cntG = grupoEl.querySelector('.cnt-grp');
                const rows = grupoEl.querySelectorAll('.conv-row');
                if (cntG) cntG.textContent = rows.length;
                if (rows.length === 0) grupoEl.remove();
              }
              deltaCntTotal(-1);
              deltaCntStatus(conf === 1, -1);
            }, 310);
            toast('Convidado removido.', 'verm');
          }
        } catch { toast('Erro ao remover convidado.', 'verm'); }
      }
    );
  });
}

/* ============================================================
   BIND INICIAL
   ============================================================ */
document.querySelectorAll('.conv-row').forEach(row => {
  const t = row.querySelector('.btn-toggle-conv');
  const x = row.querySelector('.btn-excluir-conv');
  if (t) bindToggleConv(t);
  if (x) bindExcluirConv(x);
});

</script>
</body>
</html>