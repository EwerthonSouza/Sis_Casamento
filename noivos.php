<?php
session_start();

if (!isset($_SESSION['usuario_tipo']) || $_SESSION['usuario_tipo'] !== 'noivos') {
    header("Location: index.php");
    exit;
}

require_once 'conexao.php';

$evento_id = (int)$_SESSION['evento_id'];

/* ============================================================
   HELPER: Resposta JSON para AJAX
   ============================================================ */
function json_out(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/* ============================================================
   Carrega dados do evento
   ============================================================ */
$s = $pdo->prepare("
    SELECT e.*, c.nome, c.email, c.telefone
    FROM eventos e
    INNER JOIN clientes c ON e.cliente_id = c.id
    WHERE e.id = ?
");
$s->execute([$evento_id]);
$evento = $s->fetch();
if (!$evento) { die("Casamento não encontrado."); }

/* ============================================================
   POST HANDLERS
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $ajax = isset($_POST['is_ajax']);

    // 1. Toggle tarefa
    if (isset($_POST['toggle_check'])) {
        $id    = (int)$_POST['check_id'];
        $atual = (int)$_POST['status_atual'];
        $novo  = $atual === 1 ? 0 : 1;
        $pdo->prepare("UPDATE checklist SET checado = ?, status = ? WHERE id = ? AND evento_id = ?")
            ->execute([$novo, $novo ? 'concluido' : 'pendente', $id, $evento_id]);
        if ($ajax) json_out(['ok' => true, 'novo' => $novo]);
        exit;
    }

    // 2. Comentário de tarefa
    if (isset($_POST['adicionar_comentario_noivos'])) {
        $id    = (int)$_POST['check_id'];
        $texto = trim($_POST['novo_comentario'] ?? '');
        if ($texto !== '') {
            $pdo->prepare("INSERT INTO checklist_comentarios (checklist_id, autor, comentario) VALUES (?, 'Noivos', ?)")
                ->execute([$id, $texto]);
            if ($ajax) json_out(['ok' => true, 'autor' => 'Noivos', 'texto' => htmlspecialchars($texto)]);
        }
        if (!$ajax) { header("Location: noivos.php"); exit; }
        exit;
    }

    // 3. Comentário de etapa
    if (isset($_POST['comentario_etapa_noivos'])) {
        $etapa = trim($_POST['etapa_nome'] ?? '');
        $texto = trim($_POST['novo_comentario_etapa'] ?? '');
        if ($etapa !== '' && $texto !== '') {
            $pdo->prepare("INSERT INTO checklist_comentarios (etapa_nome, autor, comentario) VALUES (?, 'Noivos', ?)")
                ->execute([$etapa, $texto]);
            if ($ajax) json_out(['ok' => true, 'autor' => 'Noivos', 'texto' => htmlspecialchars($texto)]);
        }
        if (!$ajax) { header("Location: noivos.php"); exit; }
        exit;
    }

    // 4. Adicionar convidado
    if (isset($_POST['adicionar_convidado_noivos'])) {
        $nome   = trim($_POST['nome_convidado'] ?? '');
        $fone   = trim($_POST['telefone_convidado'] ?? '');
        $cat    = trim($_POST['categoria_convidado'] ?? 'Outros');
        $acomp  = trim($_POST['acompanhantes'] ?? '');
        $filhos = trim($_POST['filhos'] ?? '');
        if ($nome !== '') {
            $pdo->prepare("INSERT INTO convidados (evento_id, nome, telefone, categoria, acompanhantes, filhos, confirmado) VALUES (?, ?, ?, ?, ?, ?, 0)")
                ->execute([$evento_id, $nome, $fone, $cat, $acomp, $filhos]);
            $newId = (int)$pdo->lastInsertId();
            if ($ajax) json_out([
                'ok'    => true,
                'id'    => $newId,
                'nome'  => htmlspecialchars($nome),
                'fone'  => htmlspecialchars($fone),
                'cat'   => htmlspecialchars($cat),
                'acomp' => htmlspecialchars($acomp),
                'filhos'=> htmlspecialchars($filhos),
            ]);
        }
        header("Location: noivos.php"); exit;
    }

    // 5. Toggle confirmação do convidado
    if (isset($_POST['toggle_convidado'])) {
        $id  = (int)$_POST['convidado_id'];
        $novo = (int)$_POST['status_atual'] === 1 ? 0 : 1;
        $pdo->prepare("UPDATE convidados SET confirmado = ? WHERE id = ? AND evento_id = ?")
            ->execute([$novo, $id, $evento_id]);
        if ($ajax) json_out(['ok' => true, 'novo' => $novo]);
        header("Location: noivos.php"); exit;
    }

    // 6. Excluir convidado
    if (isset($_POST['excluir_convidado_noivos'])) {
        $id  = (int)$_POST['convidado_id'];
        $chk = $pdo->prepare("SELECT confirmado FROM convidados WHERE id = ? AND evento_id = ?");
        $chk->execute([$id, $evento_id]);
        $row = $chk->fetch();
        $pdo->prepare("DELETE FROM convidados WHERE id = ? AND evento_id = ?")
            ->execute([$id, $evento_id]);
        if ($ajax) json_out(['ok' => true, 'era_conf' => $row ? (int)$row['confirmado'] : 0]);
        header("Location: noivos.php"); exit;
    }
}

/* ============================================================
   CARREGAMENTO DE DADOS (GET)
   ============================================================ */

// Checklist
$rs = $pdo->prepare("SELECT * FROM checklist WHERE evento_id = ? ORDER BY etapa ASC, id ASC");
$rs->execute([$evento_id]);
$lista_checklist = $rs->fetchAll();

// Convidados
$rs2 = $pdo->prepare("SELECT * FROM convidados WHERE evento_id = ? ORDER BY nome ASC");
$rs2->execute([$evento_id]);
$lista_convidados = $rs2->fetchAll();

$total_conf = 0; $total_pend = 0;
$conv_grupos = ['Família' => [], 'Amigos' => [], 'Outros' => []];
foreach ($lista_convidados as $c) {
    $c['confirmado'] ? $total_conf++ : $total_pend++;
    $cat = $c['categoria'] ?: 'Outros';
    if (!array_key_exists($cat, $conv_grupos)) $conv_grupos[$cat] = [];
    $conv_grupos[$cat][] = $c;
}

// Notificações da assessoria
$rs3 = $pdo->prepare("
    SELECT cc.*, ch.tarefa
    FROM checklist_comentarios cc
    LEFT JOIN checklist ch ON cc.checklist_id = ch.id
    WHERE (ch.evento_id = ? OR cc.etapa_nome IS NOT NULL)
      AND cc.autor = 'Assessoria'
    ORDER BY cc.data_cadastro DESC
    LIMIT 5
");
$rs3->execute([$evento_id]);
$notificacoes = $rs3->fetchAll();

// Fornecedores
$rs4 = $pdo->prepare("SELECT * FROM fornecedores_evento WHERE evento_id = ? AND status != 'Cancelado' ORDER BY status ASC, servico ASC");
$rs4->execute([$evento_id]);
$valor_cont = 0.0; $valor_neg = 0.0; $lista_cont = [];
foreach ($rs4->fetchAll() as $f) {
    if ($f['status'] === 'Contratado')    { $valor_cont += (float)$f['valor']; $lista_cont[] = $f; }
    elseif ($f['status'] === 'Orçamento') { $valor_neg  += (float)$f['valor']; }
}

// FIX N+1 – precarrega comentários de TODAS as tarefas em uma query só
$ids = array_column($lista_checklist, 'id');
$coments_tarefa = [];
if (!empty($ids)) {
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $rs5 = $pdo->prepare("SELECT * FROM checklist_comentarios WHERE checklist_id IN ($ph) ORDER BY data_cadastro ASC");
    $rs5->execute($ids);
    foreach ($rs5->fetchAll() as $c) { $coments_tarefa[$c['checklist_id']][] = $c; }
}

// FIX N+1 – precarrega comentários de TODAS as etapas em uma query só (era N queries no loop)
$rs6 = $pdo->query("SELECT * FROM checklist_comentarios WHERE etapa_nome IS NOT NULL ORDER BY data_cadastro ASC");
$coments_etapa = [];
foreach ($rs6->fetchAll() as $c) { $coments_etapa[$c['etapa_nome']][] = $c; }

// Agrupamento + cálculo de progresso
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
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Nosso Casamento ♡</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
  <link rel="stylesheet" href="css/estilo.css">
  <style>
    /* =============================================
       VARIÁVEIS & BASE
       ============================================= */
    :root {
      --radius: 16px;
      --verde:  #22c55e;
      --amarel: #f59e0b;
      --azul:   #3b82f6;
    }
    body { font-family: 'Inter', system-ui, sans-serif; background: #f1f5f9; }

    /* =============================================
       TOAST SYSTEM
       ============================================= */
    #toast-wrap {
      position: fixed; bottom: 1.5rem; right: 1.5rem;
      z-index: 9999; display: flex; flex-direction: column; gap: .4rem;
    }
    .toast-item {
      display: flex; align-items: center; gap: .7rem;
      padding: .7rem 1.1rem; border-radius: 12px; min-width: 230px;
      box-shadow: 0 8px 24px rgba(0,0,0,.15);
      font-size: .86rem; font-weight: 600; color: #fff;
      animation: toastIn .25s ease both;
    }
    .toast-item.verde { background: #16a34a; }
    .toast-item.verm  { background: #dc2626; }
    .toast-item.info  { background: #2563eb; }
    @keyframes toastIn {
      from { opacity: 0; transform: translateX(24px); }
      to   { opacity: 1; transform: translateX(0); }
    }

    /* =============================================
       CABEÇALHO
       ============================================= */
    .header-topo {
      background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
      border-radius: var(--radius) var(--radius) 0 0;
    }

    /* =============================================
       PROGRESS RING
       ============================================= */
    .ring-wrap { position: relative; width: 72px; height: 72px; flex-shrink: 0; }
    .ring-wrap svg { transform: rotate(-90deg); }
    .ring-label {
      position: absolute; inset: 0;
      display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      color: #fff; font-size: .78rem; font-weight: 700; line-height: 1.15;
    }

    /* =============================================
       BARRA FINA
       ============================================= */
    .barra { height: 5px; background: #e2e8f0; border-radius: 999px; overflow: hidden; }
    .barra-fill { height: 100%; background: var(--verde); border-radius: 999px; transition: width .4s; }

    /* =============================================
       ACCORDION ETAPA
       ============================================= */
    .etapa-hdr {
      background: #1e293b; color: #fff;
      padding: .85rem 1.1rem; border-radius: 12px;
      cursor: pointer; transition: background .2s;
      display: flex; justify-content: space-between; align-items: center;
      user-select: none;
    }
    .etapa-hdr:hover { background: #253147; }
    .etapa-hdr[aria-expanded="true"] { border-radius: 12px 12px 0 0; }
    .etapa-body { border-radius: 0 0 12px 12px; }

    /* =============================================
       TAREFA CARD
       ============================================= */
    .tarefa-card {
      border-left: 4px solid transparent;
      border-radius: 10px;
      border-top: none; border-right: none; border-bottom: none;
      transition: transform .15s, box-shadow .15s;
    }
    .tarefa-card:hover { transform: translateX(2px); box-shadow: 0 4px 12px rgba(0,0,0,.07); }
    .tarefa-card.done { border-color: var(--verde); }
    .tarefa-card.pend { border-color: var(--amarel); }

    /* =============================================
       BOTÃO CHECK
       ============================================= */
    .btn-chk { font-size: 1.4rem; line-height: 1; transition: transform .2s; }
    .btn-chk:hover { transform: scale(1.2); }

    /* =============================================
       CONVIDADO ROW
       ============================================= */
    .conv-row {
      border-left: 4px solid transparent; border-radius: 10px;
      transition: opacity .3s, transform .3s;
    }
    .conv-row.conf { border-color: var(--verde); }
    .conv-row.pend { border-color: var(--amarel); }

    /* =============================================
       SIDEBAR STICKY (desktop)
       ============================================= */
    @media (min-width: 992px) { .sidebar-sticky { position: sticky; top: 20px; } }

    /* =============================================
       BARRA MINI DA ETAPA
       ============================================= */
    .barra-mini-wrap { width: 72px; height: 4px; background: rgba(255,255,255,.2); border-radius: 999px; overflow: hidden; }
    .barra-mini-fill { height: 100%; background: var(--verde); border-radius: 999px; transition: width .4s; }
  </style>
</head>
<body>

<!-- ============================================================
     TOAST CONTAINER
     ============================================================ -->
<div id="toast-wrap"></div>

<!-- ============================================================
     MODAL: CONFIRMAR EXCLUSÃO
     ============================================================ -->
<div class="modal fade" id="modalExcluir" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content border-0 shadow-lg rounded-4 p-3 text-center">
      <div class="py-2">
        <div class="mx-auto mb-3 rounded-circle bg-danger bg-opacity-10 d-flex align-items-center justify-content-center" style="width:52px;height:52px;">
          <i class="bi bi-trash3-fill text-danger fs-4"></i>
        </div>
        <h6 class="fw-bold mb-1">Remover convidado?</h6>
        <p class="text-muted small mb-0">Esta ação não pode ser desfeita.</p>
      </div>
      <div class="d-flex justify-content-center gap-2 mt-3">
        <button class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancelar</button>
        <button id="btnConfExcluir" class="btn btn-danger btn-sm px-4 rounded-pill fw-bold">Apagar</button>
      </div>
    </div>
  </div>
</div>


<div class="container my-4 my-md-5">

  <!-- ============================================================
       CABEÇALHO DO EVENTO
       ============================================================ -->
  <div class="card border-0 shadow-sm mb-4 overflow-hidden" style="border-radius: var(--radius);">
    
    <!-- Topo escuro -->
    <div class="header-topo p-4 d-flex flex-wrap justify-content-between align-items-start gap-3">
      <div>
        <h2 class="fw-bold mb-1 text-white" style="letter-spacing:-.5px;">
          <i class="bi bi-rings text-warning me-2"></i> Nosso Casamento
        </h2>
        <p class="text-white-50 mb-3 small">Bem-vindos, <?= htmlspecialchars($evento['nome']) ?>!</p>

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
              Casados há <?= abs($dias) ?> dias!
            </span>
          <?php endif; ?>
        </div>
      </div>

      <div class="d-flex align-items-center gap-3">
        <!-- Anel de progresso geral -->
        <?php if ($total_g > 0):
          $r_   = 28;
          $circ = 2 * M_PI * $r_;
          $off  = $circ - ($circ * $pct_g / 100); ?>
        <div class="ring-wrap" title="<?= $pct_g ?>% do cronograma concluído">
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

        <div class="d-flex flex-column gap-2">
          <a href="inspiracoes.php?id=<?= $evento_id ?>&usuario=Noivos" class="btn btn-outline-light btn-sm rounded-3">
            <i class="bi bi-stars text-warning"></i> Inspirações
          </a>
          <a href="index.php" class="btn btn-sm btn-danger rounded-3" style="background:#ef4444;border:none;">
            <i class="bi bi-box-arrow-right"></i> Sair
          </a>
        </div>
      </div>
    </div>

    <!-- Rodapé branco (contatos) -->
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
      </div>
      <span class="badge bg-light text-dark border shadow-sm px-3 py-2 rounded-pill" style="font-size:.7rem;">
        Contrato #<?= str_pad($evento['id'], 4, '0', STR_PAD_LEFT) ?>
      </span>
    </div>
  </div>

  <!-- ============================================================
       NOTIFICAÇÕES DA ASSESSORIA
       ============================================================ -->
  <?php if ($notificacoes): ?>
  <div class="card border-0 shadow-sm mb-4 overflow-hidden" style="border-radius: var(--radius);">
    <div class="card-header border-0 bg-primary text-white fw-bold">
      <i class="bi bi-bell-fill me-2"></i> Recados da Assessoria
    </div>
    <div class="card-body p-3">
      <?php foreach ($notificacoes as $n): ?>
        <div class="small p-2 mb-1 bg-light rounded-3 border-start border-3 border-primary shadow-sm">
          <strong class="text-primary">
            <?= htmlspecialchars(!empty($n['etapa_nome']) ? $n['etapa_nome'] : ($n['tarefa'] ?? '')) ?>:
          </strong>
          <?= htmlspecialchars($n['comentario']) ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ============================================================
       CONTEÚDO PRINCIPAL
       ============================================================ -->
  <div class="row g-4 align-items-start">

    <!-- =========================================================
         COLUNA ESQUERDA: CRONOGRAMA
         ========================================================= -->
    <div class="col-lg-8">
      <div class="card shadow-sm border-0 mb-4" style="border-radius: var(--radius);">

        <div class="card-header bg-white border-bottom pt-4 pb-3 text-center">
          <h5 class="fw-bold mb-1">
            <i class="bi bi-calendar-check text-primary me-2"></i> Nosso Cronograma
          </h5>
          <?php if ($total_g > 0): ?>
          <div class="text-muted small mt-1">
            <span id="label-conc-g"><?= $conc_g ?></span> de <?= $total_g ?> tarefas concluídas
            <div class="barra mx-auto mt-2" style="max-width:200px;">
              <div class="barra-fill" id="barra-g" style="width:<?= $pct_g ?>%;"></div>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <div class="card-body p-4 bg-light">

          <?php if (empty($passos)): ?>
            <div class="text-center py-5 text-muted bg-white rounded-3 shadow-sm">
              <i class="bi bi-clock-history fs-1"></i>
              <p class="mt-3 mb-0">A assessoria ainda está montando o cronograma.<br>Em breve aparecerá aqui!</p>
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

                <!-- Header da etapa -->
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
                    <!-- Mini barra de progresso -->
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

                <!-- Corpo colapsável -->
                <div id="<?= $cid ?>" class="collapse">
                  <div class="etapa-body p-3 bg-white">

                    <!-- Anotações da etapa -->
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
                        <input type="hidden" name="comentario_etapa_noivos" value="1">
                        <input type="hidden" name="etapa_nome" value="<?= htmlspecialchars($etapa) ?>">
                        <input type="text" name="novo_comentario_etapa" class="form-control form-control-sm" placeholder="Nota geral…" required>
                        <button type="submit" class="btn btn-sm btn-dark px-3">Salvar</button>
                      </form>
                    </div>

                    <!-- Lista de tarefas -->
                    <?php foreach ($tarefas as $t):
                      $tid  = $t['id'];
                      $done = ($t['status'] === 'concluido' || $t['checado'] == 1);
                      $snum = $done ? 1 : 0;
                    ?>
                    <div class="tarefa-card card border-0 bg-white mb-2 shadow-sm <?= $done ? 'done' : 'pend' ?>">
                      <div class="card-body p-3">
                        <div class="d-flex align-items-start gap-3">

                          <!-- Botão check AJAX -->
                          <button type="button"
                                  class="btn p-0 border-0 btn-chk text-<?= $done ? 'success' : 'muted' ?> btn-toggle-tarefa"
                                  data-id="<?= $tid ?>"
                                  data-status="<?= $snum ?>"
                                  data-etapa-hdr-id="hdr-<?= $cid ?>"
                                  data-etapa-total="<?= $totE ?>"
                                  title="<?= $done ? 'Desmarcar tarefa' : 'Marcar como concluída' ?>">
                            <i class="bi <?= $done ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i>
                          </button>

                          <div class="w-100">
                            <!-- Título + botão detalhes -->
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                              <h6 class="fw-bold mb-0 <?= $done ? 'text-muted text-decoration-line-through' : 'text-dark' ?>" style="line-height:1.4;">
                                <?= htmlspecialchars($t['tarefa']) ?>
                              </h6>
                              <?php if (!empty($t['descricao'])): ?>
                              <button class="btn btn-sm btn-outline-secondary py-0 px-2 rounded-pill flex-shrink-0"
                                      data-bs-toggle="modal"
                                      data-bs-target="#modalDesc_<?= $tid ?>"
                                      style="font-size:.72rem;">
                                <i class="bi bi-file-text"></i> Ler
                              </button>
                              <?php endif; ?>
                            </div>

                            <!-- Comentários da tarefa -->
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
                                <input type="hidden" name="adicionar_comentario_noivos" value="1">
                                <input type="hidden" name="check_id" value="<?= $tid ?>">
                                <input type="text" name="novo_comentario" class="form-control form-control-sm bg-light border-0" placeholder="Comentar…" required>
                                <button type="submit" class="btn btn-sm btn-outline-danger px-3" title="Enviar">
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

          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- =========================================================
         COLUNA DIREITA: SIDEBAR
         ========================================================= -->
    <div class="col-lg-4">
      <div class="sidebar-sticky d-flex flex-column gap-4">

        <!-- FINANCEIRO & EQUIPE -->
        <div class="card shadow-sm border-0" style="border-radius: var(--radius);">
          <div class="card-header bg-white border-0 pt-4 pb-0 text-center">
            <h5 class="fw-bold mb-0"><i class="bi bi-wallet2 text-success me-2"></i> Financeiro & Equipe</h5>
          </div>
          <div class="card-body px-3 pb-4">

            <div class="row g-2 mt-2 mb-3">
              <div class="col-6">
                <div class="bg-success bg-opacity-10 rounded-3 p-3 text-center border border-success border-opacity-25">
                  <div class="fw-bold text-muted mb-1" style="font-size:.62rem;text-transform:uppercase;">Contratado</div>
                  <div class="fw-bold text-success" style="font-size:.88rem;">R$ <?= number_format($valor_cont, 2, ',', '.') ?></div>
                </div>
              </div>
              <div class="col-6">
                <div class="bg-warning bg-opacity-10 rounded-3 p-3 text-center border border-warning border-opacity-25">
                  <div class="fw-bold text-muted mb-1" style="font-size:.62rem;text-transform:uppercase;">Negociação</div>
                  <div class="fw-bold" style="color:#d97706;font-size:.88rem;">R$ <?= number_format($valor_neg, 2, ',', '.') ?></div>
                </div>
              </div>
            </div>

            <div class="fw-bold text-muted text-center mb-3" style="font-size:.72rem;text-transform:uppercase;">Profissionais Confirmados</div>
            <div style="max-height:200px;overflow-y:auto;">
              <?php if (empty($lista_cont)): ?>
                <p class="text-center text-muted small py-3 mb-0">Nenhum profissional contratado ainda.</p>
              <?php else: ?>
                <?php foreach ($lista_cont as $f): ?>
                <div class="p-2 mb-2 bg-light rounded-3 border-start border-3 border-success shadow-sm">
                  <div class="fw-bold text-dark" style="font-size:.8rem;"><?= htmlspecialchars($f['servico']) ?></div>
                  <div class="d-flex justify-content-between text-muted mt-1" style="font-size:.7rem;">
                    <span class="text-truncate" style="max-width:62%;"><i class="bi bi-person me-1"></i><?= htmlspecialchars($f['nome']) ?></span>
                    <span class="text-success fw-bold">R$ <?= number_format($f['valor'], 2, ',', '.') ?></span>
                  </div>
                </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- CONVIDADOS -->
        <div class="card shadow-sm border-0" style="border-radius: var(--radius);">
          <div class="card-header bg-white border-0 pt-4 pb-0 text-center">
            <h5 class="fw-bold mb-0"><i class="bi bi-people-fill text-primary me-2"></i> Convidados</h5>
          </div>
          <div class="card-body px-3 pb-4">

            <!-- Contadores -->
            <div class="row g-2 mt-2 mb-3">
              <div class="col-6">
                <div class="bg-success rounded-3 p-3 text-white d-flex justify-content-between align-items-center shadow-sm">
                  <div>
                    <h4 class="mb-0 fw-bold" id="cnt-conf"><?= $total_conf ?></h4>
                    <small class="opacity-75" style="font-size:.7rem;">Confirmados</small>
                  </div>
                  <i class="bi bi-check-circle fs-3 opacity-50"></i>
                </div>
              </div>
              <div class="col-6">
                <div class="bg-warning rounded-3 p-3 text-dark d-flex justify-content-between align-items-center shadow-sm">
                  <div>
                    <h4 class="mb-0 fw-bold" id="cnt-pend"><?= $total_pend ?></h4>
                    <small class="opacity-75" style="font-size:.7rem;">Pendentes</small>
                  </div>
                  <i class="bi bi-hourglass-split fs-3 opacity-50"></i>
                </div>
              </div>
            </div>

            <!-- Formulário adicionar convidado (AJAX) -->
            <div class="bg-light border rounded-3 p-3 mb-3">
              <div class="text-muted fw-bold text-center mb-2" style="font-size:.68rem;text-transform:uppercase;">Adicionar Convidado</div>
              <form id="form-add-conv">
                <input type="hidden" name="adicionar_convidado_noivos" value="1">
                <div class="mb-2">
                  <input type="text" name="nome_convidado" class="form-control form-control-sm" placeholder="Nome do titular (obrigatório)" required>
                </div>
                <div class="mb-2">
                  <input type="text" name="telefone_convidado" class="form-control form-control-sm" placeholder="WhatsApp / Telefone">
                </div>
                <div class="mb-2">
                  <select name="categoria_convidado" class="form-select form-select-sm text-secondary" required>
                    <option value="" disabled selected>Grupo?</option>
                    <option value="Família">Família</option>
                    <option value="Amigos">Amigos</option>
                    <option value="Outros">Outros</option>
                  </select>
                </div>
                <div class="row g-2 mb-2">
                  <div class="col-6">
                    <input type="text" name="acompanhantes" class="form-control form-control-sm" placeholder="Acompanhante(s)">
                  </div>
                  <div class="col-6">
                    <input type="text" name="filhos" class="form-control form-control-sm" placeholder="Filho(s)">
                  </div>
                </div>
                <button type="submit" id="btn-add-conv" class="btn btn-primary btn-sm w-100 fw-bold">
                  <i class="bi bi-plus-lg me-1"></i> Incluir na Lista
                </button>
              </form>
            </div>

            <!-- Botão ver lista -->
            <button class="btn btn-outline-primary btn-sm w-100 fw-bold rounded-pill shadow-sm collapsed"
                    type="button" data-bs-toggle="collapse" data-bs-target="#colapso-convidados">
              <i class="bi bi-list-ul me-1"></i> Ver Lista Completa
              (<span id="cnt-total"><?= count($lista_convidados) ?></span>)
            </button>

            <!-- Lista colapsável -->
            <div class="collapse mt-2" id="colapso-convidados">

              <!-- Campo de busca/filtro -->
              <input type="search"
                     id="busca-conv"
                     class="form-control form-control-sm rounded-pill mb-2"
                     placeholder="🔍 Filtrar convidados…">

              <div id="lista-convidados" style="max-height:360px;overflow-y:auto;">

                <?php if (empty($lista_convidados)): ?>
                  <p class="text-center text-muted small py-4 mb-0">Nenhum convidado adicionado.</p>
                <?php else: ?>

                  <?php $grp_icons = ['Família' => 'bi-house-heart-fill', 'Amigos' => 'bi-emoji-sunglasses-fill', 'Outros' => 'bi-collection-fill'];
                  foreach (['Família', 'Amigos', 'Outros'] as $grp):
                    if (empty($conv_grupos[$grp])) continue; ?>
                  <div class="grupo-sec" data-grupo="<?= $grp ?>">
                    <div class="badge bg-secondary text-white w-100 text-start px-3 py-2 rounded-2 mb-1 mt-2" style="font-size:.72rem;">
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

      </div><!-- /sidebar-sticky -->
    </div>
  </div><!-- /row -->
</div><!-- /container -->


<!-- ============================================================
     MODAIS DE DESCRIÇÃO DAS TAREFAS
     ============================================================ -->
<?php foreach ($lista_checklist as $t): ?>
  <?php if (!empty($t['descricao'])): ?>
  <div class="modal fade" id="modalDesc_<?= $t['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg rounded-4">
        <div class="modal-header bg-light border-0">
          <h5 class="modal-title fw-bold"><i class="bi bi-card-text text-primary me-2"></i> Detalhes da Tarefa</h5>
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
<?php endforeach; ?>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ============================================================
   CONSTANTES & HELPERS
   ============================================================ */
const SELF = window.location.href;

/** Exibe um toast temporário */
function toast(msg, tipo = 'verde') {
  const wrap = document.getElementById('toast-wrap');
  const el   = document.createElement('div');
  el.className = `toast-item ${tipo}`;
  el.innerHTML = `<i class="bi bi-${tipo === 'verde' ? 'check-circle-fill' : tipo === 'verm' ? 'exclamation-circle-fill' : 'info-circle-fill'}"></i> ${msg}`;
  wrap.appendChild(el);
  setTimeout(() => {
    el.style.transition = 'opacity .3s, transform .3s';
    el.style.opacity    = '0';
    el.style.transform  = 'translateX(24px)';
    setTimeout(() => el.remove(), 320);
  }, 2800);
}

/** POST AJAX genérico */
async function ajax(obj) {
  obj.is_ajax = '1';
  const fd = new FormData();
  Object.entries(obj).forEach(([k, v]) => fd.append(k, v));
  const r = await fetch(SELF, { method: 'POST', body: fd });
  return r.json();
}

/* ============================================================
   TOGGLE TAREFA
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
      const r = await ajax({ toggle_check: '1', check_id: id, status_atual: atual });
      if (!r.ok) throw new Error();

      const novo = r.novo === 1 || r.novo === '1';

      // Atualiza botão
      btn.innerHTML    = `<i class="bi ${novo ? 'bi-check-circle-fill' : 'bi-circle'}"></i>`;
      btn.dataset.status = novo ? '1' : '0';
      btn.classList.toggle('text-success', novo);
      btn.classList.toggle('text-muted',   !novo);

      // Atualiza card
      card.classList.toggle('done', novo);
      card.classList.toggle('pend', !novo);

      // Atualiza título
      if (titulo) {
        titulo.classList.toggle('text-decoration-line-through', novo);
        titulo.classList.toggle('text-muted', novo);
        titulo.classList.toggle('text-dark',  !novo);
      }

      // ---- Atualiza header da etapa ----
      const hdr = document.getElementById(hdrId);
      if (hdr) {
        const concEl   = collapso.querySelectorAll('.tarefa-card.done').length;
        const pctE     = etaTot > 0 ? Math.round(concEl / etaTot * 100) : 0;

        const concSpan = hdr.querySelector('.conc-etapa');
        const baraMini = hdr.querySelector('.barra-mini-fill');
        const pctSpan  = hdr.querySelector('.pct-etapa');
        const icone    = hdr.querySelector('.icone-etapa');

        if (concSpan) concSpan.textContent = concEl;
        if (baraMini) baraMini.style.width = pctE + '%';
        if (pctSpan)  pctSpan.textContent  = pctE + '%';
        if (icone) {
          icone.className = concEl === etaTot && etaTot > 0
            ? 'bi bi-check-all text-success fs-5 icone-etapa'
            : 'bi bi-folder2-open text-info fs-5 icone-etapa';
        }
      }

      // ---- Atualiza progresso geral ----
      const totalDone = document.querySelectorAll('.tarefa-card.done').length;
      const totalAll  = document.querySelectorAll('.tarefa-card').length;
      const pctG      = totalAll > 0 ? Math.round(totalDone / totalAll * 100) : 0;

      const lbl  = document.getElementById('label-conc-g');
      const barG = document.getElementById('barra-g');
      const ring = document.getElementById('ring-pct');
      if (lbl)  lbl.textContent  = totalDone;
      if (barG) barG.style.width = pctG + '%';
      if (ring) ring.textContent = pctG + '%';

      toast(novo ? 'Tarefa concluída! ✓' : 'Tarefa desmarcada.', novo ? 'verde' : 'info');

    } catch {
      btn.innerHTML = orig;
      toast('Erro ao atualizar. Tente novamente.', 'verm');
    }
  });
});

/* ============================================================
   COMENTÁRIOS DE ETAPAS
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
            <span class="badge bg-danger rounded-pill me-2">${r.autor}</span>${r.texto}
          </div>`);
        input.value = '';
        toast('Nota salva!');
      }
    } catch { toast('Erro ao salvar nota.', 'verm'); }
    btn.innerHTML = orig;
  });
});

/* ============================================================
   COMENTÁRIOS DE TAREFAS
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
            <strong class="text-danger">${r.autor}:</strong> ${r.texto}
          </div>`);
        input.value = '';
        toast('Comentário enviado!');
      }
    } catch { toast('Erro ao comentar.', 'verm'); }
    btn.innerHTML = orig;
  });
});

/* ============================================================
   CONVIDADOS – UTILIDADES
   ============================================================ */
function deltaCntTotal(n)    { const e = document.getElementById('cnt-total'); if (e) e.textContent = +e.textContent + n; }
function deltaCntStatus(conf, n) {
  const e = document.getElementById(conf ? 'cnt-conf' : 'cnt-pend');
  if (e) e.textContent = +e.textContent + n;
}

/* ============================================================
   ADICIONAR CONVIDADO – AJAX + inserção no DOM
   ============================================================ */
document.getElementById('form-add-conv').addEventListener('submit', async e => {
  e.preventDefault();
  const form = e.target;
  const fd   = new FormData(form);
  const btn  = document.getElementById('btn-add-conv');
  const orig = btn.innerHTML;
  fd.append('is_ajax', '1');
  btn.disabled  = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Salvando…';

  try {
    const r = await (await fetch(SELF, { method: 'POST', body: fd })).json();
    if (r.ok) {
      const extras = [
        r.fone   ? `<div><i class="bi bi-whatsapp me-1 text-success"></i>${r.fone}</div>`   : '',
        r.acomp  ? `<div><i class="bi bi-person-plus me-1"></i>Acomp: ${r.acomp}</div>`     : '',
        r.filhos ? `<div><i class="bi bi-emoji-smile me-1"></i>Filhos: ${r.filhos}</div>`   : '',
      ].filter(Boolean).join('');

      const rowHtml = `
        <div class="conv-row pend p-2 mb-2 bg-light shadow-sm"
             data-id="${r.id}" data-conf="0"
             data-nome="${r.nome.toLowerCase()}">
          <div class="d-flex justify-content-between align-items-start mb-1">
            <h6 class="mb-0 small fw-bold text-dark text-truncate pe-2">${r.nome}</h6>
            <div class="d-flex align-items-center gap-1 flex-shrink-0">
              <button type="button" class="btn p-0 border-0 bg-transparent btn-toggle-conv" data-id="${r.id}">
                <span class="badge bg-warning text-dark rounded-pill" style="font-size:.6rem;">
                  <i class="bi bi-hourglass-split me-1"></i> Pendente
                </span>
              </button>
              <button type="button" class="btn p-0 border-0 bg-transparent text-danger btn-excluir-conv" data-id="${r.id}" title="Remover">
                <i class="bi bi-trash fs-6"></i>
              </button>
            </div>
          </div>
          ${extras ? `<div class="text-muted border-top pt-1 mt-1" style="font-size:.67rem;line-height:1.5;">${extras}</div>` : ''}
        </div>`;

      // Localiza ou cria o grupo correto
      const lista = document.getElementById('lista-convidados');
      let grupoEl = lista.querySelector(`.grupo-sec[data-grupo="${r.cat}"]`);

      if (!grupoEl) {
        const icons = { 'Família': 'bi-house-heart-fill', 'Amigos': 'bi-emoji-sunglasses-fill', 'Outros': 'bi-collection-fill' };
        grupoEl = document.createElement('div');
        grupoEl.className    = 'grupo-sec';
        grupoEl.dataset.grupo = r.cat;
        grupoEl.innerHTML    = `
          <div class="badge bg-secondary text-white w-100 text-start px-3 py-2 rounded-2 mb-1 mt-2" style="font-size:.72rem;">
            <i class="bi ${icons[r.cat] || 'bi-collection-fill'} me-1"></i>
            ${r.cat} (<span class="cnt-grp">0</span>)
          </div>`;
        lista.appendChild(grupoEl);
      }

      grupoEl.insertAdjacentHTML('beforeend', rowHtml);

      const cntG = grupoEl.querySelector('.cnt-grp');
      if (cntG) cntG.textContent = +cntG.textContent + 1;

      // Bind nos botões do novo elemento
      bindConvRow(grupoEl.querySelector('.conv-row:last-child'));

      deltaCntTotal(1);
      deltaCntStatus(false, 1);

      form.reset();
      toast('Convidado adicionado!');
    }
  } catch { toast('Erro ao adicionar convidado.', 'verm'); }

  btn.disabled  = false;
  btn.innerHTML = orig;
});

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
      toast('Erro ao atualizar convidado.', 'verm');
    }
  });
}

/* ============================================================
   EXCLUIR CONVIDADO – MODAL DE CONFIRMAÇÃO
   ============================================================ */
let pendingRow    = null;
const modalExcl   = new bootstrap.Modal(document.getElementById('modalExcluir'));

function bindExcluirConv(btn) {
  btn.addEventListener('click', () => {
    pendingRow = btn.closest('.conv-row');
    modalExcl.show();
  });
}

document.getElementById('btnConfExcluir').addEventListener('click', async () => {
  if (!pendingRow) return;
  const row  = pendingRow;
  const id   = row.dataset.id;
  const conf = +row.dataset.conf;
  pendingRow = null;
  modalExcl.hide();

  try {
    const r = await ajax({ excluir_convidado_noivos: '1', convidado_id: id });
    if (r.ok) {
      row.style.opacity   = '0';
      row.style.transform = 'scale(.95)';
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
});

/* ============================================================
   BIND INICIAL + HELPER REUTILIZÁVEL
   ============================================================ */
function bindConvRow(row) {
  const t = row.querySelector('.btn-toggle-conv');
  const x = row.querySelector('.btn-excluir-conv');
  if (t) bindToggleConv(t);
  if (x) bindExcluirConv(x);
}

document.querySelectorAll('.conv-row').forEach(bindConvRow);

/* ============================================================
   BUSCA / FILTRO DE CONVIDADOS
   ============================================================ */
document.getElementById('busca-conv').addEventListener('input', function () {
  const q = this.value.toLowerCase().trim();
  document.querySelectorAll('#lista-convidados .conv-row').forEach(row => {
    row.style.display = (row.dataset.nome || '').includes(q) ? '' : 'none';
  });
  // Esconde grupos sem itens visíveis
  document.querySelectorAll('#lista-convidados .grupo-sec').forEach(sec => {
    const temVisivel = [...sec.querySelectorAll('.conv-row')].some(r => r.style.display !== 'none');
    sec.style.display = temVisivel ? '' : 'none';
  });
});

</script>
</body>
</html>