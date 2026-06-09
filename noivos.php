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

    // 7. Atualizar valor pago de um fornecedor (AJAX)
    if (isset($_POST['atualizar_valor_pago'])) {
        $forn_id    = (int)$_POST['fornecedor_id'];
        $valor_pago = (float)str_replace(['.', ','], ['', '.'], $_POST['valor_pago'] ?? '0');

        $chk = $pdo->prepare("SELECT valor FROM fornecedores_evento WHERE id = ? AND evento_id = ?");
        $chk->execute([$forn_id, $evento_id]);
        $forn = $chk->fetch();

        if ($forn) {
            $valor_pago = min($valor_pago, (float)$forn['valor']);
            $pdo->prepare("UPDATE fornecedores_evento SET valor_pago = ? WHERE id = ? AND evento_id = ?")
                ->execute([$valor_pago, $forn_id, $evento_id]);
            if ($ajax) json_out([
                'ok'          => true,
                'valor_pago'  => $valor_pago,
                'valor_total' => (float)$forn['valor'],
                'valor_rest'  => (float)$forn['valor'] - $valor_pago,
            ]);
        } else {
            if ($ajax) json_out(['ok' => false, 'msg' => 'Fornecedor não encontrado.']);
        }
        header("Location: noivos.php"); exit;
    }

    // 8. Adicionar Música (Noivos)
    if (isset($_POST['adicionar_musica_noivos'])) {
        $momento = trim($_POST['momento_musica'] ?? '');
        $titulo  = trim($_POST['titulo_musica'] ?? '');
        $link    = trim($_POST['link_musica'] ?? '');

        if ($momento !== '' && $titulo !== '') {
            $pdo->prepare("INSERT INTO musicas_evento (evento_id, momento, titulo, link, status) VALUES (?, ?, ?, ?, 0)")
                ->execute([$evento_id, $momento, $titulo, $link]);
            $ret_id = (int)$pdo->lastInsertId();
            if ($ajax) json_out([
                'ok'      => true,
                'id'      => $ret_id,
                'momento' => htmlspecialchars($momento),
                'titulo'  => htmlspecialchars($titulo),
                'link'    => htmlspecialchars($link)
            ]);
        } else {
            if ($ajax) json_out(['ok' => false, 'msg' => 'Preencha o momento e a música.']);
        }
        header("Location: noivos.php"); exit;
    }

    // 9. Excluir Música (Noivos)
    if (isset($_POST['excluir_musica_noivos'])) {
        $musica_id = (int)$_POST['musica_id'];
        $pdo->prepare("DELETE FROM musicas_evento WHERE id=? AND evento_id=?")->execute([$musica_id, $evento_id]);
        if ($ajax) json_out(['ok' => true]);
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
$todos_fornecedores = $rs4->fetchAll();

$valor_cont  = 0.0;
$valor_neg   = 0.0;
$valor_pago_total = 0.0;
$lista_cont  = [];

foreach ($todos_fornecedores as $f) {
    if ($f['status'] === 'Contratado') {
        $valor_cont += (float)$f['valor'];
        $valor_pago_total += (float)($f['valor_pago'] ?? 0);
        $lista_cont[] = $f;
    } elseif ($f['status'] === 'Orçamento') {
        $valor_neg += (float)$f['valor'];
    }
}

$valor_restante_total = $valor_cont - $valor_pago_total;
$pct_pago = $valor_cont > 0 ? round($valor_pago_total / $valor_cont * 100) : 0;

// Músicas do evento
$rs_musicas = $pdo->prepare("SELECT * FROM musicas_evento WHERE evento_id = ? ORDER BY id ASC");
$rs_musicas->execute([$evento_id]);
$lista_musicas = $rs_musicas->fetchAll();
$total_musicas = count($lista_musicas);

// FIX N+1 – precarrega comentários de todas as tarefas
$ids = array_column($lista_checklist, 'id');
$coments_tarefa = [];
if (!empty($ids)) {
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $rs5 = $pdo->prepare("SELECT * FROM checklist_comentarios WHERE checklist_id IN ($ph) ORDER BY data_cadastro ASC");
    $rs5->execute($ids);
    foreach ($rs5->fetchAll() as $c) { $coments_tarefa[$c['checklist_id']][] = $c; }
}

// FIX N+1 – precarrega comentários de todas as etapas
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
    :root {
      --radius: 16px;
      --verde:  #22c55e;
      --amarel: #f59e0b;
      --azul:   #3b82f6;
      --verm:   #ef4444;
    }
    body { font-family: 'Inter', system-ui, sans-serif; background: #f1f5f9; }

    /* TOAST */
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

    /* HEADER */
    .header-topo {
      background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
      border-radius: var(--radius) var(--radius) 0 0;
    }

    /* PROGRESS RING */
    .ring-wrap { position: relative; width: 72px; height: 72px; flex-shrink: 0; }
    .ring-wrap svg { transform: rotate(-90deg); }
    .ring-label {
      position: absolute; inset: 0;
      display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      color: #fff; font-size: .78rem; font-weight: 700; line-height: 1.15;
    }

    /* BARRA FINA */
    .barra { height: 5px; background: #e2e8f0; border-radius: 999px; overflow: hidden; }
    .barra-fill { height: 100%; background: var(--verde); border-radius: 999px; transition: width .4s; }

    /* BARRA PAGO */
    .barra-pago-wrap { height: 8px; background: #e2e8f0; border-radius: 999px; overflow: hidden; position: relative; }
    .barra-pago-fill { height: 100%; border-radius: 999px; transition: width .5s ease; }

    /* ACCORDION ETAPA */
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

    /* TAREFA CARD
       FIX FLICKER: removido o transform: translateX(2px) que causava o loop de
       hover/unhover quando o mouse ficava perto da borda esquerda do card.
       Agora apenas a sombra muda no hover, sem mover o elemento. */
    .tarefa-card {
      border-left: 4px solid transparent; border-radius: 10px;
      border-top: none; border-right: none; border-bottom: none;
      transition: box-shadow .2s;
    }
    .tarefa-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,.09); }
    .tarefa-card.done { border-color: var(--verde); }
    .tarefa-card.pend { border-color: var(--amarel); }

    /* BOTÃO CHECK */
    .btn-chk { font-size: 1.4rem; line-height: 1; transition: transform .2s; will-change: transform; }
    .btn-chk:hover { transform: scale(1.2); }

    /* CONVIDADO ROW */
    .conv-row {
      border-left: 4px solid transparent; border-radius: 10px;
      transition: opacity .3s, transform .3s;
    }
    .conv-row.conf { border-color: var(--verde); }
    .conv-row.pend { border-color: var(--amarel); }

    /* SIDEBAR */
    @media (min-width: 992px) { .sidebar-sticky { position: sticky; top: 20px; } }

    /* BARRA MINI ETAPA */
    .barra-mini-wrap { width: 72px; height: 4px; background: rgba(255,255,255,.2); border-radius: 999px; overflow: hidden; }
    .barra-mini-fill { height: 100%; background: var(--verde); border-radius: 999px; transition: width .4s; }

    /* ---- CARD DE PAGAMENTO DO FORNECEDOR ---- */
    .forn-card {
      background: #fff;
      border-radius: 12px;
      border: 1px solid #e2e8f0;
      padding: .85rem 1rem;
      margin-bottom: .75rem;
      transition: box-shadow .2s;
    }
    .forn-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.07); }
    .forn-card:last-child { margin-bottom: 0; }

    .forn-pago-badge {
      font-size: .6rem;
      font-weight: 700;
      padding: .25em .6em;
      border-radius: 999px;
      text-transform: uppercase;
      letter-spacing: .04em;
    }

    .valor-pago-input {
      font-size: .8rem;
      border: 1.5px solid #e2e8f0;
      border-radius: 8px;
      padding: .3rem .6rem;
      width: 100%;
      transition: border-color .2s;
      background: #f8fafc;
    }
    .valor-pago-input:focus {
      outline: none;
      border-color: #22c55e;
      background: #fff;
    }

    .btn-salvar-pag {
      font-size: .72rem;
      font-weight: 700;
      padding: .3rem .8rem;
      border-radius: 8px;
      border: none;
      background: #22c55e;
      color: #fff;
      cursor: pointer;
      transition: background .2s, transform .1s;
      white-space: nowrap;
    }
    .btn-salvar-pag:hover { background: #16a34a; }
    .btn-salvar-pag:active { transform: scale(.96); }

    /* Resumo financeiro global */
    .fin-summary-card {
      border-radius: 12px;
      padding: .9rem 1rem;
      text-align: center;
    }
    .fin-summary-label {
      font-size: .6rem;
      text-transform: uppercase;
      letter-spacing: .07em;
      font-weight: 700;
      opacity: .75;
      margin-bottom: .3rem;
    }
    .fin-summary-val {
      font-size: .95rem;
      font-weight: 800;
      line-height: 1;
    }

    /* ---- TRILHA SONORA ---- */
    .btn-musicas-sidebar {
      background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
      border: 1.5px solid #a5b4fc;
      border-radius: var(--radius);
      transition: box-shadow .2s, transform .15s;
      will-change: transform;
      display: block;
      width: 100%;
      text-align: left;
    }
    .btn-musicas-sidebar:hover {
      box-shadow: 0 6px 18px rgba(165,180,252,.4);
      transform: translateY(-1px);
    }
    #grid-musicas .musica-card-wrap {
      animation: entraItem .3s ease both;
    }
    @keyframes entraItem {
      from { opacity: 0; transform: scale(.94) translateY(8px); }
      to   { opacity: 1; transform: scale(1)   translateY(0); }
    }
  </style>
</head>
<body>

<div id="toast-wrap"></div>

<!-- Modal de confirmação de exclusão de convidado -->
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

<!-- FIX: Modal de Conversa/Histórico movido para FORA do bloco <script> -->
<div class="modal fade" id="modalConversa" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header bg-light border-0">
        <h5 class="modal-title fw-bold" id="conversa-titulo">Histórico</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4" id="conversa-corpo" style="max-height: 400px; overflow-y: auto;">
        <!-- Histórico injetado via JS -->
      </div>
    </div>
  </div>
</div>

<div class="container my-4 my-md-5">

  <div class="card border-0 shadow-sm mb-4 overflow-hidden" style="border-radius: var(--radius);">
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

  <?php if ($notificacoes): ?>
<div class="card border-0 shadow-sm mb-4 overflow-hidden" style="border-radius: var(--radius);">
  <div class="card-header border-0 bg-primary text-white fw-bold">
    <i class="bi bi-bell-fill me-2"></i> Novas Notificações
  </div>
  <div class="card-body p-2">
    <?php foreach ($notificacoes as $n):
        $tipo = !empty($n['etapa_nome']) ? 'etapa' : 'tarefa';
        $id_busca = ($tipo === 'etapa') ? $n['etapa_nome'] : $n['checklist_id'];
    ?>
    <div class="notificacao-box p-3 mb-2 bg-white rounded-3 border shadow-sm"
         onclick="abrirConversa('<?= $tipo ?>', '<?= addslashes($id_busca) ?>', '<?= htmlspecialchars(!empty($n['etapa_nome']) ? 'Etapa: ' . $n['etapa_nome'] : 'Tarefa: ' . $n['tarefa']) ?>')"
         style="cursor:pointer; border-left: 4px solid var(--azul);">
      <div class="d-flex justify-content-between">
        <strong class="text-primary small">
          <?= htmlspecialchars(!empty($n['etapa_nome']) ? 'Etapa: ' . $n['etapa_nome'] : 'Tarefa: ' . $n['tarefa']) ?>
        </strong>
        <small class="text-muted" style="font-size: .7rem;"><?= date('d/m H:i', strtotime($n['data_cadastro'])) ?></small>
      </div>
      <div class="text-dark small mt-1" style="font-size: .85rem;">
        <em>Assessoria:</em> <?= htmlspecialchars($n['comentario']) ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

  <div class="row g-4 align-items-start">

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
                        <input type="hidden" name="comentario_etapa_noivos" value="1">
                        <input type="hidden" name="etapa_nome" value="<?= htmlspecialchars($etapa) ?>">
                        <input type="text" name="novo_comentario_etapa" class="form-control form-control-sm" placeholder="Nota geral…" required>
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
                                  title="<?= $done ? 'Desmarcar tarefa' : 'Marcar como concluída' ?>">
                            <i class="bi <?= $done ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i>
                          </button>
                          <div class="w-100">
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

    <div class="col-lg-4">
      <div class="sidebar-sticky d-flex flex-column gap-4">

        <button type="button"
                class="btn-musicas-sidebar mt-0 mb-0"
                data-bs-toggle="modal"
                data-bs-target="#modalMusicas">
          <div class="d-flex justify-content-between align-items-center p-3">
            <div class="d-flex align-items-center gap-3">
              <div class="bg-white rounded-3 d-flex align-items-center justify-content-center shadow-sm flex-shrink-0"
                   style="width:44px;height:44px;">
                <i class="bi bi-music-note-list fs-4" style="color:#4f46e5;"></i>
              </div>
              <div class="text-start">
                <h6 class="mb-0 fw-bold text-dark">Nossa Trilha Sonora</h6>
                <small class="text-dark" style="font-size:.78rem;opacity:.6;">
                  <span id="musicas-count-badge"><?= $total_musicas ?> música<?= $total_musicas !== 1 ? 's' : '' ?></span>
                  · sugestões
                </small>
              </div>
            </div>
            <span class="btn btn-primary btn-sm fw-bold rounded-pill px-3 shadow-sm" style="pointer-events:none; background:#4f46e5; border:none;">
              Abrir <i class="bi bi-arrow-right ms-1"></i>
            </span>
          </div>
        </button>

        <div class="card shadow-sm border-0" style="border-radius: var(--radius);">
          <div class="card-header bg-white border-0 pt-4 pb-0 text-center">
            <h5 class="fw-bold mb-0"><i class="bi bi-wallet2 text-success me-2"></i> Financeiro & Equipe</h5>
          </div>
          <div class="card-body px-3 pb-4">

            <div class="row g-2 mt-2 mb-3">
              <div class="col-4">
                <div class="fin-summary-card bg-primary bg-opacity-10 border border-primary border-opacity-20">
                  <div class="fin-summary-label text-primary">Total</div>
                  <div class="fin-summary-val text-primary">R$ <?= number_format($valor_cont, 2, ',', '.') ?></div>
                </div>
              </div>
              <div class="col-4">
                <div class="fin-summary-card bg-success bg-opacity-10 border border-success border-opacity-20">
                  <div class="fin-summary-label text-success">Pago</div>
                  <div class="fin-summary-val text-success" id="total-pago-geral">R$ <?= number_format($valor_pago_total, 2, ',', '.') ?></div>
                </div>
              </div>
              <div class="col-4">
                <div class="fin-summary-card bg-danger bg-opacity-10 border border-danger border-opacity-20">
                  <div class="fin-summary-label text-danger">A Pagar</div>
                  <div class="fin-summary-val text-danger" id="total-rest-geral">R$ <?= number_format($valor_restante_total, 2, ',', '.') ?></div>
                </div>
              </div>
            </div>

            <div class="mb-1 d-flex justify-content-between align-items-center" style="font-size:.68rem;">
              <span class="text-muted fw-bold" style="text-transform:uppercase;letter-spacing:.05em;">Progresso de Pagamentos</span>
              <span class="fw-bold text-success" id="pct-pago-label"><?= $pct_pago ?>%</span>
            </div>
            <div class="barra-pago-wrap mb-3">
              <div class="barra-pago-fill bg-success" id="barra-pago-global" style="width:<?= $pct_pago ?>%;"></div>
            </div>

            <?php if ($valor_neg > 0): ?>
            <div class="d-flex align-items-center justify-content-between bg-warning bg-opacity-10 border border-warning border-opacity-25 rounded-3 px-3 py-2 mb-3">
              <div>
                <div class="fw-bold" style="font-size:.72rem;text-transform:uppercase;color:#92400e;">Em Negociação</div>
                <div class="fw-bold" style="color:#d97706;font-size:.9rem;">R$ <?= number_format($valor_neg, 2, ',', '.') ?></div>
              </div>
              <i class="bi bi-hourglass-split text-warning fs-4 opacity-50"></i>
            </div>
            <?php endif; ?>

            <div class="fw-bold text-muted text-center mb-2" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;">
              <i class="bi bi-people-fill me-1"></i> Profissionais Contratados
            </div>

            <div style="max-height:420px;overflow-y:auto;" id="lista-fornecedores">
              <?php if (empty($lista_cont)): ?>
                <p class="text-center text-muted small py-3 mb-0">Nenhum profissional contratado ainda.</p>
              <?php else: ?>
                <?php foreach ($lista_cont as $f):
                  $fid        = (int)$f['id'];
                  $fValor     = (float)$f['valor'];
                  $fPago      = (float)($f['valor_pago'] ?? 0);
                  $fRest      = $fValor - $fPago;
                  $fPct       = $fValor > 0 ? round($fPago / $fValor * 100) : 0;
                  $fQuitado   = $fRest <= 0;
                  $barColor   = $fQuitado ? 'bg-success' : ($fPct >= 50 ? 'bg-info' : 'bg-warning');
                ?>
                <div class="forn-card" id="forn-<?= $fid ?>">
                  <div class="d-flex justify-content-between align-items-start mb-1">
                    <div class="fw-bold text-dark" style="font-size:.83rem;line-height:1.3;">
                      <?= htmlspecialchars($f['servico']) ?>
                    </div>
                    <span class="forn-pago-badge ms-2 flex-shrink-0 <?= $fQuitado ? 'bg-success text-white' : 'bg-warning text-dark' ?>">
                      <?= $fQuitado ? '✓ Quitado' : ($fPct > 0 ? $fPct.'% pago' : 'Não iniciado') ?>
                    </span>
                  </div>

                  <?php if (!empty($f['nome'])): ?>
                  <div class="text-muted mb-2" style="font-size:.72rem;">
                    <i class="bi bi-person me-1"></i><?= htmlspecialchars($f['nome']) ?>
                  </div>
                  <?php endif; ?>

                  <div class="d-flex justify-content-between mb-2" style="font-size:.72rem;">
                    <div>
                      <span class="text-muted">Contrato: </span>
                      <span class="fw-bold text-dark">R$ <?= number_format($fValor, 2, ',', '.') ?></span>
                    </div>
                    <div class="text-end">
                      <span class="text-muted">Restante: </span>
                      <span class="fw-bold <?= $fQuitado ? 'text-success' : 'text-danger' ?> forn-rest-val">
                        R$ <?= number_format($fRest < 0 ? 0 : $fRest, 2, ',', '.') ?>
                      </span>
                    </div>
                  </div>

                  <div class="barra-pago-wrap mb-2">
                    <div class="barra-pago-fill <?= $barColor ?> forn-barra-fill" style="width:<?= $fPct ?>%;"></div>
                  </div>

                  <div class="d-flex align-items-center gap-2 mt-2">
                    <div class="flex-grow-1">
                      <label style="font-size:.62rem;color:#64748b;text-transform:uppercase;font-weight:700;letter-spacing:.05em;">
                        Valor já pago (R$)
                      </label>
                      <input
                        type="text"
                        class="valor-pago-input forn-input-pago"
                        data-id="<?= $fid ?>"
                        data-total="<?= $fValor ?>"
                        value="<?= number_format($fPago, 2, ',', '.') ?>"
                        placeholder="0,00"
                        inputmode="decimal"
                      >
                    </div>
                    <div class="mt-3">
                      <button type="button"
                              class="btn-salvar-pag btn-salvar-pagamento"
                              data-id="<?= $fid ?>">
                        <i class="bi bi-floppy me-1"></i>Salvar
                      </button>
                    </div>
                  </div>

                </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>

          </div>
        </div>

        <div class="card shadow-sm border-0" style="border-radius: var(--radius);">
          <div class="card-header bg-white border-0 pt-4 pb-0 text-center">
            <h5 class="fw-bold mb-0"><i class="bi bi-people-fill text-primary me-2"></i> Convidados</h5>
          </div>
          <div class="card-body px-3 pb-4">
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

            <button class="btn btn-outline-primary btn-sm w-100 fw-bold rounded-pill shadow-sm collapsed"
                    type="button" data-bs-toggle="collapse" data-bs-target="#colapso-convidados">
              <i class="bi bi-list-ul me-1"></i> Ver Lista Completa
              (<span id="cnt-total"><?= count($lista_convidados) ?></span>)
            </button>

            <div class="collapse mt-2" id="colapso-convidados">
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

      </div>
    </div>
  </div>
</div>

<!-- Modais de descrição de tarefas -->
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

<!-- Modal de Músicas -->
<div class="modal fade" id="modalMusicas" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg rounded-4" style="background:#f8fafc;">

      <div class="modal-header border-0 px-4 pt-4 pb-2" style="background:transparent;">
        <div class="d-flex align-items-center gap-3">
          <div class="rounded-3 d-flex align-items-center justify-content-center shadow-sm"
               style="width:42px;height:42px;background:#e0e7ff;border:1.5px solid #a5b4fc;">
            <i class="bi bi-music-note-beamed text-primary fs-5"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold mb-0 text-dark">Nossa Trilha Sonora</h5>
            <span class="text-muted" style="font-size:.73rem;">Sugira as músicas para cada momento especial</span>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body px-4 pb-4 pt-2">
        <div class="card border-0 shadow-sm rounded-4 mb-4" style="border: 1.5px solid #c7d2fe !important; background:#fff;">
          <div class="card-body p-3 p-sm-4">
            <div class="d-flex align-items-center gap-2 mb-3">
              <i class="bi bi-plus-circle-fill text-primary fs-6"></i>
              <span class="fw-bold text-dark small text-uppercase" style="letter-spacing:.06em;">Adicionar Sugestão</span>
            </div>

            <form id="form-musica">
              <div class="row g-2 mb-2">
                <div class="col-md-5">
                  <input type="text" id="musica-momento" class="form-control form-control-sm bg-light" placeholder="Momento (Ex: Entrada da Noiva)" list="lista-momentos" required>
                  <datalist id="lista-momentos">
                    <option value="Entrada do Noivo">
                    <option value="Entrada dos Padrinhos">
                    <option value="Entrada da Noiva">
                    <option value="Entrada das Alianças">
                    <option value="Assinaturas">
                    <option value="Saída dos Noivos">
                    <option value="Primeira Dança">
                    <option value="Corte do Bolo">
                  </datalist>
                </div>
                <div class="col-md-7">
                  <input type="text" id="musica-titulo" class="form-control form-control-sm bg-light" placeholder="Nome da Música e Artista (Ex: A Thousand Years)" required>
                </div>
              </div>
              <div class="mb-3">
                <input type="url" id="musica-link" class="form-control form-control-sm bg-light" placeholder="Link para ouvir (YouTube, Spotify...)">
              </div>
              <div class="text-end">
                <button type="submit" class="btn btn-sm btn-primary fw-bold rounded-pill px-4 shadow-sm" id="btn-salvar-musica">
                  <i class="bi bi-plus-lg me-1"></i> Sugerir Música
                </button>
              </div>
            </form>
          </div>
        </div>

        <div id="lista-musicas-wrap">
          <?php if (empty($lista_musicas)): ?>
            <div class="text-center py-5 text-muted" id="musicas-vazia">
              <i class="bi bi-music-note-list fs-1 d-block mb-2" style="opacity:.25;"></i>
              <small>Nenhuma música sugerida ainda.</small>
            </div>
          <?php else: ?>
            <div class="row g-3" id="grid-musicas">
              <?php foreach ($lista_musicas as $m):
                $mOk = (int)$m['status'] === 1;
              ?>
                <div class="col-12 musica-card-wrap" data-id="<?= $m['id'] ?>">
                  <div class="card border-0 shadow-sm rounded-3 <?= $mOk ? 'border-success border bg-success bg-opacity-10' : 'bg-white' ?>">
                    <div class="card-body p-3 d-flex align-items-center justify-content-between gap-3">
                      <div class="d-flex align-items-center gap-3 w-100">
                         <div class="flex-grow-1">
                           <div class="d-flex align-items-center gap-2 mb-1">
                             <div class="fw-bold text-uppercase text-muted" style="font-size:.65rem; letter-spacing:.05em;"><?= htmlspecialchars($m['momento']) ?></div>
                             <?php if($mOk): ?>
                               <span class="badge bg-success" style="font-size:.55rem;"><i class="bi bi-check-circle-fill me-1"></i>Aprovada</span>
                             <?php else: ?>
                               <span class="badge bg-secondary opacity-75" style="font-size:.55rem;"><i class="bi bi-hourglass-split me-1"></i>Pendente</span>
                             <?php endif; ?>
                           </div>
                           <h6 class="mb-0 fw-bold <?= $mOk ? 'text-success' : 'text-dark' ?>" style="font-size:.9rem;"><?= htmlspecialchars($m['titulo']) ?></h6>
                           <?php if (!empty($m['link'])): ?>
                             <a href="<?= htmlspecialchars($m['link']) ?>" target="_blank" class="small text-decoration-none mt-1 d-inline-block">
                               <i class="bi bi-link-45deg"></i> Ouvir Referência
                             </a>
                           <?php endif; ?>
                         </div>
                      </div>
                      <button type="button" class="btn p-1 border-0 text-danger btn-excluir-musica flex-shrink-0" data-id="<?= $m['id'] ?>" title="Remover música">
                        <i class="bi bi-trash-fill"></i>
                      </button>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ============================================================
   HELPERS
   ============================================================ */
const SELF = window.location.href;

function toast(msg, tipo = 'verde') {
  const wrap = document.getElementById('toast-wrap');
  const el   = document.createElement('div');
  el.className = `toast-item ${tipo}`;
  const icones = { verde: 'check-circle-fill', verm: 'exclamation-circle-fill', info: 'info-circle-fill' };
  el.innerHTML = `<i class="bi bi-${icones[tipo] || 'info-circle-fill'}"></i> ${msg}`;
  wrap.appendChild(el);
  setTimeout(() => {
    el.style.transition = 'opacity .3s, transform .3s';
    el.style.opacity    = '0';
    el.style.transform  = 'translateX(24px)';
    setTimeout(() => el.remove(), 320);
  }, 2800);
}

async function ajax(obj) {
  obj.is_ajax = '1';
  const fd = new FormData();
  Object.entries(obj).forEach(([k, v]) => fd.append(k, v));
  const r = await fetch(SELF, { method: 'POST', body: fd });
  return r.json();
}

/* Formata número como moeda BR */
function brl(n) {
  return 'R$ ' + parseFloat(n).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/* Converte string de input (pt-BR) para float */
function parseBrl(s) {
  return parseFloat(String(s).replace(/\./g, '').replace(',', '.')) || 0;
}

/* ============================================================
   ABRIR CONVERSA / HISTÓRICO (FIX: função estava ausente)
   ============================================================ */
function abrirConversa(tipo, id, titulo) {
  const modalEl = document.getElementById('modalConversa');
  if (!modalEl) return;
  document.getElementById('conversa-titulo').textContent = titulo;
  const corpo = document.getElementById('conversa-corpo');
  corpo.innerHTML = '<div class="text-center py-4"><span class="spinner-border text-primary"></span></div>';
  const modal = new bootstrap.Modal(modalEl);
  modal.show();

  // Monta lista de comentários já no DOM para o tipo/id informado
  let comentarios = [];
  if (tipo === 'tarefa') {
    document.querySelectorAll('.lista-coment-tarefa').forEach(lista => {
      const form = lista.nextElementSibling;
      if (form && form.querySelector('[name="check_id"]')?.value == id) {
        lista.querySelectorAll('div').forEach(d => comentarios.push(d.innerHTML));
      }
    });
  } else {
    document.querySelectorAll('.lista-coment-etapa').forEach(lista => {
      const hidden = lista.closest('form')?.querySelector('[name="etapa_nome"]');
      if (!hidden) {
        // busca pelo form que tem o etapa_nome correto dentro do mesmo bloco
        const bloco = lista.closest('.p-3');
        if (bloco) {
          const f = bloco.querySelector('input[name="etapa_nome"]');
          if (f && f.value === id) {
            lista.querySelectorAll('div').forEach(d => comentarios.push(d.innerHTML));
          }
        }
      }
    });
  }

  if (comentarios.length === 0) {
    corpo.innerHTML = '<p class="text-muted text-center py-4 mb-0">Nenhum comentário ainda.</p>';
  } else {
    corpo.innerHTML = comentarios.map(c => `<div class="mb-2">${c}</div>`).join('');
  }
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
    const orig     = btn.innerHTML;

    btn.innerHTML = '<span class="spinner-border spinner-border-sm text-secondary"></span>';
    try {
      const r = await ajax({ toggle_check: '1', check_id: id, status_atual: atual });
      if (!r.ok) throw new Error();
      const novo = r.novo === 1 || r.novo === '1';

      btn.innerHTML      = `<i class="bi ${novo ? 'bi-check-circle-fill' : 'bi-circle'}"></i>`;
      btn.dataset.status = novo ? '1' : '0';
      btn.classList.toggle('text-success', novo);
      btn.classList.toggle('text-muted', !novo);
      card.classList.toggle('done', novo);
      card.classList.toggle('pend', !novo);
      if (titulo) {
        titulo.classList.toggle('text-decoration-line-through', novo);
        titulo.classList.toggle('text-muted', novo);
        titulo.classList.toggle('text-dark', !novo);
      }

      const hdr = document.getElementById(hdrId);
      if (hdr) {
        const concEl = collapso.querySelectorAll('.tarefa-card.done').length;
        const pctE   = etaTot > 0 ? Math.round(concEl / etaTot * 100) : 0;
        const c = hdr.querySelector('.conc-etapa');
        const b = hdr.querySelector('.barra-mini-fill');
        const p = hdr.querySelector('.pct-etapa');
        const i = hdr.querySelector('.icone-etapa');
        if (c) c.textContent  = concEl;
        if (b) b.style.width  = pctE + '%';
        if (p) p.textContent  = pctE + '%';
        if (i) i.className    = concEl === etaTot && etaTot > 0
          ? 'bi bi-check-all text-success fs-5 icone-etapa'
          : 'bi bi-folder2-open text-info fs-5 icone-etapa';
      }

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
   CONTROLE DE PAGAMENTO DOS FORNECEDORES
   ============================================================ */
function recalcularTotaisGlobais() {
  let totalContrato = 0;
  let totalPago     = 0;

  document.querySelectorAll('.forn-card').forEach(card => {
    const input = card.querySelector('.forn-input-pago');
    const total = parseFloat(input?.dataset.total || 0);
    const pago  = parseBrl(input?.value || '0');
    totalContrato += total;
    totalPago     += Math.min(pago, total);
  });

  const restante = Math.max(0, totalContrato - totalPago);
  const pct      = totalContrato > 0 ? Math.round(totalPago / totalContrato * 100) : 0;

  const elPago  = document.getElementById('total-pago-geral');
  const elRest  = document.getElementById('total-rest-geral');
  const elBarra = document.getElementById('barra-pago-global');
  const elPct   = document.getElementById('pct-pago-label');

  if (elPago)  elPago.textContent  = brl(totalPago);
  if (elRest)  elRest.textContent  = brl(restante);
  if (elBarra) elBarra.style.width = pct + '%';
  if (elPct)   elPct.textContent   = pct + '%';
}

document.querySelectorAll('.btn-salvar-pagamento').forEach(btn => {
  btn.addEventListener('click', async () => {
    const fid   = btn.dataset.id;
    const card  = document.getElementById('forn-' + fid);
    const input = card.querySelector('.forn-input-pago');
    const total = parseFloat(input.dataset.total || 0);
    let   valor = parseBrl(input.value);

    if (valor < 0) { toast('O valor não pode ser negativo.', 'verm'); return; }

    if (valor > total) {
      toast('Valor maior que o contrato! Ajustado para o total.', 'info');
      valor = total;
      input.value = total.toLocaleString('pt-BR', { minimumFractionDigits: 2 });
    }

    const orig = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    btn.disabled  = true;

    try {
      const r = await ajax({
        atualizar_valor_pago: '1',
        fornecedor_id:        fid,
        valor_pago:           valor.toString(),
      });

      if (r.ok) {
        const pago   = parseFloat(r.valor_pago);
        const rest   = Math.max(0, parseFloat(r.valor_rest));
        const pct    = total > 0 ? Math.round(pago / total * 100) : 0;
        const quit   = rest <= 0;

        const barra  = card.querySelector('.forn-barra-fill');
        const restEl = card.querySelector('.forn-rest-val');
        const badge  = card.querySelector('.forn-pago-badge');

        if (barra) {
          barra.style.width = pct + '%';
          barra.className   = 'barra-pago-fill forn-barra-fill ' + (quit ? 'bg-success' : pct >= 50 ? 'bg-info' : 'bg-warning');
        }
        if (restEl) {
          restEl.textContent = brl(rest);
          restEl.className   = 'fw-bold forn-rest-val ' + (quit ? 'text-success' : 'text-danger');
        }
        if (badge) {
          badge.textContent = quit ? '✓ Quitado' : (pct > 0 ? pct + '% pago' : 'Não iniciado');
          badge.className   = 'forn-pago-badge ms-2 flex-shrink-0 ' + (quit ? 'bg-success text-white' : 'bg-warning text-dark');
        }

        input.value = pago.toLocaleString('pt-BR', { minimumFractionDigits: 2 });

        recalcularTotaisGlobais();
        toast(quit ? 'Pagamento quitado! 🎉' : 'Pagamento atualizado!', quit ? 'verde' : 'info');
      } else {
        toast(r.msg || 'Erro ao salvar pagamento.', 'verm');
      }
    } catch {
      toast('Erro de conexão. Tente novamente.', 'verm');
    }

    btn.innerHTML = orig;
    btn.disabled  = false;
  });
});

document.querySelectorAll('.forn-input-pago').forEach(input => {
  input.addEventListener('blur', () => {
    const n = parseBrl(input.value);
    if (!isNaN(n)) {
      input.value = n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
  });
  input.addEventListener('focus', () => { input.select(); });
});

/* ============================================================
   CONVIDADOS
   ============================================================ */
function deltaCntTotal(n) {
  const e = document.getElementById('cnt-total');
  if (e) e.textContent = +e.textContent + n;
}
function deltaCntStatus(conf, n) {
  const e = document.getElementById(conf ? 'cnt-conf' : 'cnt-pend');
  if (e) e.textContent = +e.textContent + n;
}

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
        r.acomp  ? `<div><i class="bi bi-person-plus me-1"></i>Acomp: ${r.acomp}</div>`      : '',
        r.filhos ? `<div><i class="bi bi-emoji-smile me-1"></i>Filhos: ${r.filhos}</div>`   : '',
      ].filter(Boolean).join('');
      const rowHtml = `
        <div class="conv-row pend p-2 mb-2 bg-light shadow-sm"
             data-id="${r.id}" data-conf="0" data-nome="${r.nome.toLowerCase()}">
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

      const lista   = document.getElementById('lista-convidados');
      let   grupoEl = lista.querySelector(`.grupo-sec[data-grupo="${r.cat}"]`);
      if (!grupoEl) {
        const icons = { 'Família': 'bi-house-heart-fill', 'Amigos': 'bi-emoji-sunglasses-fill', 'Outros': 'bi-collection-fill' };
        grupoEl = document.createElement('div');
        grupoEl.className     = 'grupo-sec';
        grupoEl.dataset.grupo = r.cat;
        grupoEl.innerHTML     = `
          <div class="badge bg-secondary text-white w-100 text-start px-3 py-2 rounded-2 mb-1 mt-2" style="font-size:.72rem;">
            <i class="bi ${icons[r.cat] || 'bi-collection-fill'} me-1"></i>
            ${r.cat} (<span class="cnt-grp">0</span>)
          </div>`;
        lista.appendChild(grupoEl);
      }
      grupoEl.insertAdjacentHTML('beforeend', rowHtml);
      const cntG = grupoEl.querySelector('.cnt-grp');
      if (cntG) cntG.textContent = +cntG.textContent + 1;
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
        deltaCntStatus(novo, 1);
        deltaCntStatus(!novo, -1);
        toast(novo ? 'Presença confirmada!' : 'Marcado como pendente.', novo ? 'verde' : 'info');
      }
    } catch {
      badge.innerHTML = orig;
      toast('Erro ao atualizar convidado.', 'verm');
    }
  });
}

let pendingRow  = null;
const modalExcl = new bootstrap.Modal(document.getElementById('modalExcluir'));

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

function bindConvRow(row) {
  const t = row.querySelector('.btn-toggle-conv');
  const x = row.querySelector('.btn-excluir-conv');
  if (t) bindToggleConv(t);
  if (x) bindExcluirConv(x);
}

document.querySelectorAll('.conv-row').forEach(bindConvRow);

document.getElementById('busca-conv').addEventListener('input', function () {
  const q = this.value.toLowerCase().trim();
  document.querySelectorAll('#lista-convidados .conv-row').forEach(row => {
    row.style.display = (row.dataset.nome || '').includes(q) ? '' : 'none';
  });
  document.querySelectorAll('#lista-convidados .grupo-sec').forEach(sec => {
    const temVisivel = [...sec.querySelectorAll('.conv-row')].some(r => r.style.display !== 'none');
    sec.style.display = temVisivel ? '' : 'none';
  });
});

/* ============================================================
   TRILHA SONORA (MÚSICAS)
   ============================================================ */
function atualizarContadoresMusicas() {
  const total = document.querySelectorAll('#grid-musicas .musica-card-wrap').length;
  const txt   = total + ' música' + (total !== 1 ? 's' : '');
  const badge = document.getElementById('musicas-count-badge');
  if (badge) badge.textContent = txt;
}

function bindBotoesMusica() {
  document.querySelectorAll('.btn-excluir-musica').forEach(btn => {
    btn.onclick = () => {
      const id   = btn.dataset.id;
      const wrap = btn.closest('.musica-card-wrap');
      if (confirm('Deseja realmente remover esta sugestão de música?')) {
        ajax({ excluir_musica_noivos: '1', musica_id: id }).then(r => {
          if (r.ok) {
            wrap.style.transition = 'opacity .25s, transform .25s';
            wrap.style.opacity    = '0';
            wrap.style.transform  = 'scale(.92)';
            setTimeout(() => {
              wrap.remove();
              const grid = document.getElementById('grid-musicas');
              if (grid && !grid.querySelector('.musica-card-wrap')) {
                document.getElementById('lista-musicas-wrap').innerHTML =
                  `<div class="text-center py-5 text-muted" id="musicas-vazia">
                    <i class="bi bi-music-note-list fs-1 d-block mb-2" style="opacity:.25;"></i>
                    <small>Nenhuma música sugerida ainda.</small>
                  </div>`;
              }
              atualizarContadoresMusicas();
            }, 280);
            toast('Música removida.', 'verm');
          }
        }).catch(() => toast('Erro ao remover.', 'verm'));
      }
    };
  });
}

document.getElementById('form-musica')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const momento = document.getElementById('musica-momento').value.trim();
  const titulo  = document.getElementById('musica-titulo').value.trim();
  const link    = document.getElementById('musica-link').value.trim();

  const btn  = document.getElementById('btn-salvar-musica');
  const orig = btn.innerHTML;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>...';
  btn.disabled = true;

  try {
    const r = await ajax({ adicionar_musica_noivos: '1', momento_musica: momento, titulo_musica: titulo, link_musica: link });
    if (r.ok) {
      document.getElementById('musicas-vazia')?.remove();
      let grid = document.getElementById('grid-musicas');
      if (!grid) {
        document.getElementById('lista-musicas-wrap').innerHTML = `<div class="row g-3" id="grid-musicas"></div>`;
        grid = document.getElementById('grid-musicas');
      }

      const linkHtml = r.link ? `<a href="${r.link}" target="_blank" class="small text-decoration-none mt-1 d-inline-block"><i class="bi bi-link-45deg"></i> Ouvir Referência</a>` : '';

      const html = `
        <div class="col-12 musica-card-wrap" data-id="${r.id}">
          <div class="card border-0 shadow-sm rounded-3 bg-white">
            <div class="card-body p-3 d-flex align-items-center justify-content-between gap-3">
              <div class="d-flex align-items-center gap-3 w-100">
                 <div class="flex-grow-1">
                   <div class="d-flex align-items-center gap-2 mb-1">
                     <div class="fw-bold text-uppercase text-muted" style="font-size:.65rem; letter-spacing:.05em;">${r.momento}</div>
                     <span class="badge bg-secondary opacity-75" style="font-size:.55rem;"><i class="bi bi-hourglass-split me-1"></i>Pendente</span>
                   </div>
                   <h6 class="mb-0 fw-bold text-dark" style="font-size:.9rem;">${r.titulo}</h6>
                   ${linkHtml}
                 </div>
              </div>
              <button type="button" class="btn p-1 border-0 text-danger btn-excluir-musica flex-shrink-0" data-id="${r.id}">
                <i class="bi bi-trash-fill"></i>
              </button>
            </div>
          </div>
        </div>`;

      grid.insertAdjacentHTML('beforeend', html);
      bindBotoesMusica();
      document.getElementById('form-musica').reset();
      atualizarContadoresMusicas();
      toast('Música sugerida com sucesso!', 'verde');
      document.getElementById('musica-momento').focus();
    } else {
      toast(r.msg || 'Erro ao salvar.', 'verm');
    }
  } catch {
    toast('Erro de conexão.', 'verm');
  }
  btn.innerHTML = orig;
  btn.disabled = false;
});

bindBotoesMusica();
</script>
</body>
</html>