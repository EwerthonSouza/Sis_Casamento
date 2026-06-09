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
   AUTO-CONFIGURAÇÃO DO BANCO DE DADOS
   ============================================================ */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS mesas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        evento_id INT NOT NULL,
        nome VARCHAR(100) NOT NULL,
        capacidade INT NOT NULL DEFAULT 8,
        ordem INT DEFAULT 0
    )");
} catch (PDOException $e) {}

$schema_checks = [
    "SELECT mesa_id FROM convidados LIMIT 1"             => "ALTER TABLE convidados ADD COLUMN mesa_id INT NULL",
    "SELECT nomes_acompanhantes FROM convidados LIMIT 1" => "ALTER TABLE convidados ADD COLUMN nomes_acompanhantes VARCHAR(255) NULL",
    "SELECT idades_filhos FROM convidados LIMIT 1"       => "ALTER TABLE convidados ADD COLUMN idades_filhos VARCHAR(255) NULL",
    "SELECT ordem FROM mesas LIMIT 1"                   => "ALTER TABLE mesas ADD COLUMN ordem INT DEFAULT 0",
];
foreach ($schema_checks as $check => $alter) {
    try { $pdo->query($check); } catch (Exception $e) { try { $pdo->exec($alter); } catch (Exception $x) {} }
}

/* ============================================================
   HELPER AJAX
   ============================================================ */
function json_out($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/* ============================================================
   POST HANDLERS
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $is_ajax_html = isset($_POST['ajax_html']);

    // AJAX: Mover convidado (Drag & Drop)
    if (isset($_POST['mover_convidado_ajax'])) {
        $cid = (int)$_POST['convidado_id'];
        $mid = (int)$_POST['nova_mesa_id'] ?: null;
        $pdo->prepare("UPDATE convidados SET mesa_id = ? WHERE id = ? AND evento_id = ?")
            ->execute([$mid, $cid, $evento_id]);
        
        if (!$is_ajax_html) json_out(['ok' => true]);
    }

    // AJAX: Reordenar mesas
    if (isset($_POST['reordenar_mesas_ajax'])) {
        $ordem = json_decode($_POST['ordem_mesas'], true);
        if (is_array($ordem)) {
            $st = $pdo->prepare("UPDATE mesas SET ordem = ? WHERE id = ? AND evento_id = ?");
            foreach ($ordem as $i => $id) $st->execute([$i, (int)$id, $evento_id]);
        }
        json_out(['ok' => true]);
    }

    // 1. Adicionar Mesa
    if (isset($_POST['adicionar_mesa'])) {
        $nome = trim($_POST['nome_mesa']);
        $cap  = (int)$_POST['capacidade_mesa'];
        if ($nome !== '' && $cap > 0) {
            $st = $pdo->prepare("SELECT COALESCE(MAX(ordem), 0) FROM mesas WHERE evento_id = ?");
            $st->execute([$evento_id]);
            $pdo->prepare("INSERT INTO mesas (evento_id, nome, capacidade, ordem) VALUES (?, ?, ?, ?)")
                ->execute([$evento_id, $nome, $cap, (int)$st->fetchColumn() + 1]);
            $_SESSION['msg_sucesso'] = "Mesa <strong>" . htmlspecialchars($nome) . "</strong> criada com sucesso!";
        }
        if (!$is_ajax_html) { header("Location: organizar_mesas.php?id=$evento_id"); exit; }
    }

    // 2. Editar Mesa
    if (isset($_POST['editar_mesa'])) {
        $mid  = (int)$_POST['mesa_id'];
        $nome = trim($_POST['nome_mesa']);
        $cap  = (int)$_POST['capacidade_mesa'];
        if ($nome !== '' && $cap > 0) {
            $pdo->prepare("UPDATE mesas SET nome = ?, capacidade = ? WHERE id = ? AND evento_id = ?")
                ->execute([$nome, $cap, $mid, $evento_id]);
            $_SESSION['msg_sucesso'] = "Mesa <strong>" . htmlspecialchars($nome) . "</strong> atualizada!";
        }
        if (!$is_ajax_html) { header("Location: organizar_mesas.php?id=$evento_id"); exit; }
    }

    // 3. Criar Múltiplas Mesas em Lote
    if (isset($_POST['criar_multiplas_mesas'])) {
        $pfx = trim($_POST['prefixo_mesa']);
        $qtd = min((int)$_POST['qtd_mesas'], 50);
        $cap = (int)$_POST['capacidade_padrao'];
        $ini = max(1, (int)$_POST['numero_inicio']);
        if ($pfx !== '' && $qtd > 0 && $cap > 0) {
            $st = $pdo->prepare("SELECT COALESCE(MAX(ordem), 0) FROM mesas WHERE evento_id = ?");
            $st->execute([$evento_id]);
            $maxO = (int)$st->fetchColumn();
            $ins  = $pdo->prepare("INSERT INTO mesas (evento_id, nome, capacidade, ordem) VALUES (?, ?, ?, ?)");
            for ($i = 0; $i < $qtd; $i++)
                $ins->execute([$evento_id, $pfx . ' ' . str_pad($ini + $i, 2, '0', STR_PAD_LEFT), $cap, ++$maxO]);
            $_SESSION['msg_sucesso'] = "<strong>$qtd mesa(s)</strong> criada(s) com sucesso!";
        }
        if (!$is_ajax_html) { header("Location: organizar_mesas.php?id=$evento_id"); exit; }
    }

    // 4. Excluir Mesa
    if (isset($_POST['excluir_mesa'])) {
        $mid = (int)$_POST['mesa_id'];
        $st  = $pdo->prepare("SELECT nome FROM mesas WHERE id = ? AND evento_id = ?");
        $st->execute([$mid, $evento_id]);
        $nn  = $st->fetchColumn() ?: 'Mesa';
        $pdo->prepare("UPDATE convidados SET mesa_id = NULL WHERE mesa_id = ? AND evento_id = ?")->execute([$mid, $evento_id]);
        $pdo->prepare("DELETE FROM mesas WHERE id = ? AND evento_id = ?")->execute([$mid, $evento_id]);
        $_SESSION['msg_sucesso'] = "Mesa <strong>" . htmlspecialchars($nn) . "</strong> removida. Convidados retornados à fila.";
        if (!$is_ajax_html) { header("Location: organizar_mesas.php?id=$evento_id"); exit; }
    }

    // 5. Esvaziar Mesa (liberar todos os convidados)
    if (isset($_POST['esvaziar_mesa'])) {
        $mid = (int)$_POST['mesa_id'];
        $pdo->prepare("UPDATE convidados SET mesa_id = NULL WHERE mesa_id = ? AND evento_id = ?")->execute([$mid, $evento_id]);
        $_SESSION['msg_sucesso'] = "Mesa esvaziada. Convidados retornados à fila de espera.";
        if (!$is_ajax_html) { header("Location: organizar_mesas.php?id=$evento_id"); exit; }
    }

    // 6. Remover Convidado da Mesa (botão X)
    if (isset($_POST['remover_da_mesa'])) {
        $cid = (int)$_POST['convidado_id'];
        $pdo->prepare("UPDATE convidados SET mesa_id = NULL WHERE id = ? AND evento_id = ?")->execute([$cid, $evento_id]);
        if (!$is_ajax_html) { header("Location: organizar_mesas.php?id=$evento_id"); exit; }
    }

    // 7. Adicionar Convidado à Mesa (via Botão + Modal)
    if (isset($_POST['adicionar_convidado_mesa'])) {
        $cid = (int)$_POST['convidado_id'];
        $mid = (int)$_POST['mesa_id'];
        if ($cid > 0 && $mid > 0) {
            $pdo->prepare("UPDATE convidados SET mesa_id = ? WHERE id = ? AND evento_id = ?")
                ->execute([$mid, $cid, $evento_id]);
        }
        if (!$is_ajax_html) { header("Location: organizar_mesas.php?id=$evento_id"); exit; }
    }

    // 8. Alternar Confirmação (Marcar/Desmarcar Presença)
    if (isset($_POST['alternar_confirmacao'])) {
        $cid = (int)$_POST['convidado_id'];
        if ($cid > 0) {
            // Usa "1 - confirmado" para inverter o booleano de forma super rápida no SQL
            $pdo->prepare("UPDATE convidados SET confirmado = 1 - confirmado WHERE id = ? AND evento_id = ?")
                ->execute([$cid, $evento_id]);
        }
        if (!$is_ajax_html) { header("Location: organizar_mesas.php?id=$evento_id"); exit; }
    }
}

/* ============================================================
   CARREGAMENTO DE DADOS
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT e.data_evento, c.nome
    FROM eventos e
    INNER JOIN clientes c ON e.cliente_id = c.id
    WHERE e.id = ?
");
$stmt->execute([$evento_id]);
$evento = $stmt->fetch();
if (!$evento) die("Evento não encontrado.");

$stmtM = $pdo->prepare("SELECT * FROM mesas WHERE evento_id = ? ORDER BY ordem ASC, id ASC");
$stmtM->execute([$evento_id]);
$lista_mesas = $stmtM->fetchAll(PDO::FETCH_ASSOC);

$stmtC = $pdo->prepare("
    SELECT id, nome, categoria, acompanhantes, filhos, confirmado,
           mesa_id, nomes_acompanhantes, idades_filhos
    FROM convidados WHERE evento_id = ? ORDER BY nome ASC
");
$stmtC->execute([$evento_id]);
$todos = $stmtC->fetchAll(PDO::FETCH_ASSOC);

$sem_mesa = $na_mesa = [];
$total_alocados = $total_cap = $total_conf = 0;

foreach ($todos as $c) {
    $c['lugares'] = 1 + (int)$c['acompanhantes'] + (int)$c['filhos'];
    if ($c['confirmado']) $total_conf++;
    if ($c['mesa_id']) {
        $na_mesa[$c['mesa_id']][] = $c;
        $total_alocados += $c['lugares'];
    } else {
        $sem_mesa[] = $c;
    }
}
foreach ($lista_mesas as $m) $total_cap += (int)$m['capacidade'];

$total_conv   = count($todos);
$total_livres = $total_cap - $total_alocados;

$msg_ok  = $_SESSION['msg_sucesso'] ?? '';
$msg_err = $_SESSION['msg_erro'] ?? '';
unset($_SESSION['msg_sucesso'], $_SESSION['msg_erro']);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Organizar Mesas — <?= htmlspecialchars($evento['nome']) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
  <link rel="stylesheet" href="css/estilo.css">

  <style>
    :root { --radius: 12px; }
    body  { background: #f1f5f9; }

    #overlay {
      position: fixed; top: 1.5rem; right: 1.5rem;
      background: #ffffff; box-shadow: 0 4px 15px rgba(0,0,0,0.15);
      border-radius: 50px; padding: 0.6rem 1.2rem;
      z-index: 10050; display: none; flex-direction: row; align-items: center; gap: 0.75rem;
      pointer-events: none; border: 1px solid #e2e8f0; color: #0f172a;
    }
    #overlay.show { display: flex; }
    #overlay .spinner-border { width: 1.1rem; height: 1.1rem; border-width: 2px; }

    .hdr { background: linear-gradient(135deg, #0f172a 0%, #1a3a5c 100%); border-radius: var(--radius); }
    .stat { background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.12); border-radius: 10px; padding: .8rem 1rem; text-align: center; color: #fff; }
    .stat .val { font-size: 1.75rem; font-weight: 700; line-height: 1; }
    .stat .lbl { font-size: .65rem; opacity: .6; text-transform: uppercase; letter-spacing: .05em; margin-top: .3rem; }

    .conv-item { border-left: 4px solid transparent !important; cursor: grab; transition: background .1s, box-shadow .1s; user-select: none; }
    .conv-item:active { cursor: grabbing; }
    .conv-item.confirmado { border-left-color: #10b981 !important; }
    .conv-item.pendente   { border-left-color: #f59e0b !important; }
    .conv-item:hover      { background: #f0f9ff !important; }

    .mesa-card { border-radius: var(--radius) !important; border: 1px solid #e2e8f0 !important; transition: box-shadow .2s, border-color .3s; }
    .mesa-card:hover { box-shadow: 0 .5rem 1.5rem rgba(0,0,0,.1) !important; }
    .mesa-card.s-empty { border-top: 3px solid #94a3b8 !important; }
    .mesa-card.s-ok    { border-top: 3px solid #10b981 !important; }
    .mesa-card.s-warn  { border-top: 3px solid #f59e0b !important; }
    .mesa-card.s-full  { border-top: 3px solid #f97316 !important; }
    .mesa-card.s-over  { border-top: 3px solid #ef4444 !important; }

    .chairs { display: flex; flex-wrap: wrap; gap: 3px; margin: .45rem 0 .15rem; }
    .ch { width: 15px; height: 15px; border-radius: 3px; border: 1px solid #cbd5e1; background: #f8fafc; cursor: default; transition: background .2s; }
    .ch.conf { background: #10b981; border-color: #059669; }
    .ch.pend { background: #60a5fa; border-color: #3b82f6; }
    .ch.over { background: #f87171; border-color: #ef4444; }

    .sortable-area  { min-height: 52px; }
    .sortable-ghost  { opacity: .3; background: #dbeafe !important; border-radius: 8px; }
    .sortable-chosen { box-shadow: 0 8px 20px rgba(59,130,246,.2) !important; }
    .sortable-drag   { cursor: grabbing !important; box-shadow: 0 12px 28px rgba(0,0,0,.15) !important; }

    .drag-mesa  { cursor: grab; color: #94a3b8; }
    .drag-mesa:active { cursor: grabbing; }
    .drag-guest { cursor: grab; color: #b0bec5; }
    .drag-guest:active { cursor: grabbing; }

    .scroll-g { max-height: 640px; overflow-y: auto; overflow-x: hidden; }
    .scroll-m { max-height: 272px; overflow-y: auto; }
    .scroll-g::-webkit-scrollbar, .scroll-m::-webkit-scrollbar { width: 4px; }
    .scroll-g::-webkit-scrollbar-thumb, .scroll-m::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

    .sw { position: relative; }
    .sw .bi-search { position: absolute; left: .7rem; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; font-size: .78rem; }
    .sw input { padding-left: 2rem; font-size: .8rem; }

    .drop-empty { border: 2px dashed #cbd5e1; border-radius: 8px; padding: 1rem .75rem; text-align: center; color: #94a3b8; font-size: .74rem; pointer-events: none; }
    .legend-dot { display: inline-block; width: 11px; height: 11px; border-radius: 2px; vertical-align: middle; }

    /* Estilo sutil de Hover pros botões transparentes */
    .btn-acao-convidado { transition: opacity 0.15s, transform 0.1s; }
    .btn-acao-convidado:hover { opacity: 0.7; transform: scale(1.1); }
    .btn-acao-convidado:active { transform: scale(0.95); }

    @media print {
      .no-print { display: none !important; }
      body  { background: white !important; }
      #col-fila { display: none !important; }
      .mesa-card { break-inside: avoid; page-break-inside: avoid; }
      .scroll-m  { max-height: none !important; overflow: visible !important; }
      .hdr { background: #1e293b !important; -webkit-print-color-adjust: exact; }
    }
  </style>
</head>
<body>

<div id="overlay" class="no-print">
  <div class="spinner-border text-primary" role="status"></div>
  <span class="small fw-semibold" id="overlay-msg">Salvando...</span>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3 no-print" style="z-index:10000;">
  <?php if ($msg_ok): ?>
  <div class="toast align-items-center text-bg-success border-0 shadow-lg" role="alert" data-bs-autohide="true" data-bs-delay="4500">
    <div class="d-flex">
      <div class="toast-body fw-semibold"><i class="bi bi-check-circle-fill me-2"></i><?= $msg_ok ?></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($msg_err): ?>
  <div class="toast align-items-center text-bg-danger border-0 shadow-lg" role="alert" data-bs-autohide="true" data-bs-delay="5000">
    <div class="d-flex">
      <div class="toast-body fw-semibold"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= $msg_err ?></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="container-fluid px-3 px-lg-4 py-4">

  <!-- =========================================================
       CABEÇALHO
       ========================================================= -->
  <div class="hdr p-4 mb-4 no-print">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
      <div>
        <a href="gerenciar.php?id=<?= $evento_id ?>" class="btn btn-sm btn-outline-light rounded-pill mb-3 opacity-75">
          <i class="bi bi-arrow-left me-1"></i> Voltar ao Painel
        </a>
        <h4 class="fw-bold text-white mb-1">
          <i class="bi bi-grid-3x3-gap-fill text-info me-2"></i> Organização de Mesas
        </h4>
        <p class="text-white opacity-50 mb-0 small">
          <?= htmlspecialchars($evento['nome']) ?> &bull; <?= date('d/m/Y', strtotime($evento['data_evento'])) ?>
        </p>
      </div>

      <div class="d-flex flex-wrap gap-2 align-items-center">
        <button class="btn btn-sm btn-outline-light rounded-pill opacity-75" onclick="window.print()" title="Imprimir mapa de mesas">
          <i class="bi bi-printer me-1"></i> Imprimir
        </button>
        <button class="btn btn-sm btn-light rounded-pill text-dark fw-semibold" data-bs-toggle="modal" data-bs-target="#modalLote">
          <i class="bi bi-layers me-1"></i> Criar em Lote
        </button>
        <button class="btn btn-sm btn-success rounded-pill fw-semibold shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#modalAdd">
          <i class="bi bi-plus-lg me-1"></i> Nova Mesa
        </button>
      </div>
    </div>

    <!-- Estatísticas -->
    <div class="row g-2 mt-3">
      <div class="col-6 col-sm-3"><div class="stat"><div class="val"><?= $total_conv ?></div><div class="lbl">Convites</div></div></div>
      <div class="col-6 col-sm-3"><div class="stat"><div class="val text-info"><?= $total_conf ?></div><div class="lbl">Confirmados</div></div></div>
      <div class="col-6 col-sm-3"><div class="stat"><div class="val <?= count($sem_mesa) > 0 ? 'text-warning' : 'text-success' ?>"><?= count($sem_mesa) ?></div><div class="lbl">Sem Mesa</div></div></div>
      <div class="col-6 col-sm-3"><div class="stat"><div class="val <?= $total_livres < 0 ? 'text-danger' : ($total_livres <= 5 && $total_livres >= 0 ? 'text-warning' : 'text-success') ?>"><?= $total_livres ?></div><div class="lbl">Cadeiras Livres</div></div></div>
    </div>
  </div>

  <!-- =========================================================
       CONTEÚDO PRINCIPAL
       ========================================================= -->
  <div class="row g-4">

    <!-- ===== COLUNA: FILA DE ESPERA ===== -->
    <div class="col-lg-4 col-xl-3" id="col-fila">
      <div class="card border-0 shadow-sm d-flex flex-column h-100" style="border-radius:var(--radius);">
        <div class="card-header bg-white border-bottom p-3">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-person-lines-fill text-secondary me-2"></i>Fila de Espera</h6>
            <span class="badge bg-secondary rounded-pill" id="badge-qtd"><?= count($sem_mesa) ?></span>
          </div>

          <div class="sw mb-2">
            <i class="bi bi-search"></i>
            <input type="text" id="busca" class="form-control rounded-pill" placeholder="Buscar convidado...">
          </div>

          <div class="d-flex gap-1" id="filtros-wrap">
            <button class="btn btn-primary btn-sm rounded-pill active" data-f="todos" style="font-size:.7rem;padding:.25rem .6rem;">Todos</button>
            <button class="btn btn-outline-success btn-sm rounded-pill" data-f="confirmado" style="font-size:.7rem;padding:.25rem .6rem;">✓ Confirm.</button>
            <button class="btn btn-outline-warning btn-sm rounded-pill" data-f="pendente" style="font-size:.7rem;padding:.25rem .6rem;">⏳ Pendente</button>
          </div>
        </div>

        <div class="card-body p-0 scroll-g bg-light flex-grow-1">
          <div class="sortable-area p-2" data-mesa-id="0" id="lista-espera" style="min-height: 80px;">
            <?php if (empty($sem_mesa)): ?>
              <div class="text-center py-5 text-muted msg-vazia-estatica">
                <i class="bi bi-check2-all fs-2 d-block mb-2 text-success"></i>
                <strong>Todos alocados!</strong><br><small>Nenhum convidado na fila.</small>
              </div>
            <?php endif; ?>

            <?php foreach ($sem_mesa as $c): $sc = $c['confirmado'] ? 'confirmado' : 'pendente'; ?>
            <div class="list-group-item bg-white rounded-2 shadow-sm conv-item <?= $sc ?> p-2 mb-1 border-0"
                 data-conv-id="<?= $c['id'] ?>"
                 data-nome="<?= strtolower(htmlspecialchars($c['nome'])) ?>"
                 data-status="<?= $sc ?>"
                 data-lugares="<?= $c['lugares'] ?>">
              
              <div class="d-flex align-items-start gap-2">
                <i class="bi bi-grip-vertical drag-guest flex-shrink-0 mt-1"></i>
                <div class="flex-grow-1 min-w-0">
                  <div class="d-flex justify-content-between align-items-start gap-1">
                    <span class="fw-semibold small text-dark text-truncate" title="<?= htmlspecialchars($c['nome']) ?>">
                      <?= htmlspecialchars($c['nome']) ?>
                    </span>
                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill flex-shrink-0" style="font-size:.62rem;">
                      <i class="bi bi-person-fill"></i> <?= $c['lugares'] ?>
                    </span>
                  </div>

                  <?php if (!empty($c['nomes_acompanhantes']) || !empty($c['idades_filhos'])): ?>
                  <div class="text-muted mt-1" style="font-size:.62rem;line-height:1.35;">
                    <?php if (!empty($c['nomes_acompanhantes'])): ?><div class="text-truncate"><i class="bi bi-people me-1"></i><?= htmlspecialchars($c['nomes_acompanhantes']) ?></div><?php endif; ?>
                    <?php if (!empty($c['idades_filhos'])): ?><div class="text-truncate"><i class="bi bi-emoji-smile me-1"></i><?= htmlspecialchars($c['idades_filhos']) ?></div><?php endif; ?>
                  </div>
                  <?php endif; ?>

                  <!-- Rodapé do Card da Fila com Botão de Confirmar -->
                  <div class="d-flex align-items-center justify-content-between mt-2 pt-1 border-top border-light" style="font-size:.64rem;">
                    <form method="POST" class="m-0 no-print form-confirmar">
                      <input type="hidden" name="alternar_confirmacao" value="1">
                      <input type="hidden" name="convidado_id" value="<?= $c['id'] ?>">
                      <button type="submit" class="btn btn-sm p-0 border-0 bg-transparent d-flex align-items-center gap-1 btn-acao-convidado"
                              title="<?= $c['confirmado'] ? 'Mudar para Pendente' : 'Confirmar Presença' ?>">
                        <i class="bi <?= $c['confirmado'] ? 'bi-check-circle-fill text-success' : 'bi-circle text-warning' ?>" style="font-size:.85rem;"></i>
                        <span class="fw-semibold <?= $c['confirmado'] ? 'text-success' : 'text-warning' ?>" style="font-size:.68rem;">
                          <?= $c['confirmado'] ? 'Confirmado' : 'Pendente' ?>
                        </span>
                      </button>
                    </form>
                    <span class="text-muted text-truncate" style="max-width:45%;" title="<?= htmlspecialchars($c['categoria']) ?>"><?= htmlspecialchars($c['categoria'] ?: 'Sem categoria') ?></span>
                  </div>

                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="card-footer bg-white border-0 text-center text-muted py-2 no-print" style="font-size:.67rem;border-radius:0 0 var(--radius) var(--radius);">
          <i class="bi bi-arrows-move me-1"></i> Arraste para alocar em uma mesa
        </div>
      </div>
    </div>

    <!-- ===== COLUNA: GRID DE MESAS ===== -->
    <div class="col-lg-8 col-xl-9" id="mesas-container">
      <?php if (empty($lista_mesas)): ?>
        <div class="card border-0 shadow-sm text-center" style="border-radius:var(--radius);">
          <div class="card-body py-5">
            <div class="bg-primary bg-opacity-10 rounded-circle d-inline-flex align-items-center justify-content-center mb-4" style="width:80px;height:80px;">
              <i class="bi bi-table fs-2 text-primary"></i>
            </div>
            <h5 class="fw-bold">Nenhuma mesa criada ainda</h5>
            <p class="text-muted small mb-4">Crie as mesas do salão para começar a organizar seus convidados.</p>
            <div class="d-flex justify-content-center gap-2 flex-wrap no-print">
              <button class="btn btn-outline-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#modalLote"><i class="bi bi-layers me-1"></i> Criar em Lote</button>
              <button class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#modalAdd"><i class="bi bi-plus-lg me-1"></i> Criar Primeira Mesa</button>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 g-3" id="grid-mesas">
          <?php foreach ($lista_mesas as $mesa):
            $mid  = $mesa['id'];
            $cap  = (int)$mesa['capacidade'];
            $cvs  = $na_mesa[$mid] ?? [];

            $ocup = $conf_ocup = 0;
            foreach ($cvs as $cm) {
              $ocup += $cm['lugares'];
              if ($cm['confirmado']) $conf_ocup += $cm['lugares'];
            }
            $pend_ocup = $ocup - $conf_ocup;
            $pct = $cap > 0 ? min(($ocup / $cap) * 100, 100) : 0;

            if ($ocup === 0)              { $sc = 's-empty'; $barC = 'bg-secondary'; }
            elseif ($ocup < $cap * .75)   { $sc = 's-ok';    $barC = 'bg-success'; }
            elseif ($ocup <= $cap)        { $sc = 's-warn';  $barC = 'bg-warning'; }
            else                          { $sc = 's-over';  $barC = 'bg-danger'; }
          ?>
          <div class="col mesa-col" data-mesa-id="<?= $mid ?>">
            <div class="card border-0 shadow-sm mesa-card <?= $sc ?> h-100 d-flex flex-column">

              <!-- Cabeçalho da Mesa -->
              <div class="card-header bg-white border-bottom p-3">
                <div class="d-flex justify-content-between align-items-center">
                  <h6 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2 min-w-0">
                    <i class="bi bi-arrows-move drag-mesa flex-shrink-0" title="Arraste para reposicionar"></i>
                    <span class="text-truncate"><?= htmlspecialchars($mesa['nome']) ?></span>
                  </h6>

                  <div class="d-flex gap-1 flex-shrink-0 no-print">
                    <!-- BOTÃO ADICIONAR NA MESA -->
                    <button type="button" class="btn btn-sm text-success p-1 border-0 bg-transparent btn-add-guest"
                            title="Adicionar convidado nesta mesa"
                            data-id="<?= $mid ?>"
                            data-nome="<?= htmlspecialchars($mesa['nome']) ?>"
                            data-bs-toggle="modal" data-bs-target="#modalAddGuest">
                      <i class="bi bi-person-plus-fill"></i>
                    </button>

                    <button type="button" class="btn btn-sm text-primary p-1 border-0 bg-transparent btn-edit"
                            title="Editar mesa" data-id="<?= $mid ?>" data-nome="<?= htmlspecialchars($mesa['nome']) ?>" data-cap="<?= $cap ?>" data-bs-toggle="modal" data-bs-target="#modalEdit">
                      <i class="bi bi-pencil-fill"></i>
                    </button>

                    <?php if (!empty($cvs)): ?>
                    <form method="POST" class="d-inline form-esvaziar">
                      <input type="hidden" name="esvaziar_mesa" value="1">
                      <input type="hidden" name="mesa_id" value="<?= $mid ?>">
                      <button type="submit" class="btn btn-sm text-warning p-1 border-0 bg-transparent" title="Esvaziar mesa"><i class="bi bi-eraser-fill"></i></button>
                    </form>
                    <?php endif; ?>

                    <form method="POST" class="d-inline form-excluir">
                      <input type="hidden" name="excluir_mesa" value="1">
                      <input type="hidden" name="mesa_id" value="<?= $mid ?>">
                      <button type="submit" class="btn btn-sm text-danger p-1 border-0 bg-transparent" title="Excluir mesa"><i class="bi bi-trash-fill"></i></button>
                    </form>
                  </div>
                </div>

                <!-- Barra de Ocupação -->
                <div class="mt-2">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <small class="text-muted"><?= $ocup ?> / <?= $cap ?> lugares</small>
                    <?php if ($ocup > $cap): ?><span class="badge bg-danger-subtle text-danger rounded-pill" style="font-size:.59rem;">+<?= $ocup - $cap ?> EXCEDE</span>
                    <?php elseif ($ocup === $cap): ?><span class="badge bg-warning-subtle text-dark rounded-pill" style="font-size:.59rem;">LOTADA</span>
                    <?php elseif ($ocup === 0): ?><span class="badge bg-light text-muted border rounded-pill" style="font-size:.59rem;">VAZIA</span>
                    <?php else: ?><span class="badge bg-success-subtle text-success rounded-pill" style="font-size:.59rem;"><?= $cap - $ocup ?> livres</span>
                    <?php endif; ?>
                  </div>
                  <div class="progress mb-2" style="height: 5px; border-radius: 4px;"><div class="progress-bar <?= $barC ?>" role="progressbar" style="width: <?= $pct ?>%;"></div></div>
                  <div class="chairs">
                    <?php $total_shown = min($cap, 32); for ($i = 0; $i < $total_shown; $i++):
                        if ($i < $conf_ocup) $cc = 'conf'; elseif ($i < $conf_ocup + $pend_ocup) $cc = 'pend'; else $cc = '';
                    ?>
                    <div class="ch <?= $cc ?>"></div>
                    <?php endfor; ?>
                    <?php if ($cap > 32): ?><span class="text-muted align-self-center" style="font-size:.6rem;margin-left:2px;">+<?= $cap - 32 ?></span><?php endif; ?>
                    <?php for ($i = $cap; $i < $ocup && $i < $cap + 10; $i++): ?><div class="ch over"></div><?php endfor; ?>
                  </div>
                </div>
              </div>

              <!-- Lista de Convidados na Mesa -->
              <div class="card-body p-2 flex-grow-1 scroll-m">
                <div class="sortable-area" data-mesa-id="<?= $mid ?>" style="min-height: 44px;">
                  <?php if (empty($cvs)): ?><div class="drop-empty msg-vazia-estatica"><i class="bi bi-box-arrow-in-down me-1"></i> Solte os convidados aqui</div><?php endif; ?>
                  
                  <?php foreach ($cvs as $cm): $sc2 = $cm['confirmado'] ? 'confirmado' : 'pendente'; ?>
                  <div class="list-group-item px-2 py-2 border-0 rounded mb-1 bg-white shadow-sm conv-item <?= $sc2 ?> d-flex align-items-start gap-2" data-conv-id="<?= $cm['id'] ?>" style="font-size:.79rem;border-left-width:3px!important;border-left-style:solid!important;">
                    <i class="bi bi-grip-vertical drag-guest flex-shrink-0 mt-1" style="font-size:.82rem;"></i>
                    <div class="flex-grow-1 min-w-0">
                      <div class="d-flex justify-content-between align-items-start gap-1">
                        <span class="fw-semibold text-dark text-truncate"><?= htmlspecialchars($cm['nome']) ?></span>
                        <?php if ($cm['lugares'] > 1): ?><span class="badge bg-secondary bg-opacity-10 text-secondary flex-shrink-0" style="font-size:.58rem;"><?= $cm['lugares'] ?> lug.</span><?php endif; ?>
                      </div>
                      <?php if ($cm['lugares'] > 1 && (!empty($cm['nomes_acompanhantes']) || !empty($cm['idades_filhos']))): ?>
                      <div class="text-muted mt-0" style="font-size:.62rem;line-height:1.3;">
                        <?php if (!empty($cm['nomes_acompanhantes'])): ?><div class="text-truncate"><i class="bi bi-people me-1"></i><?= htmlspecialchars($cm['nomes_acompanhantes']) ?></div><?php endif; ?>
                        <?php if (!empty($cm['idades_filhos'])): ?><div class="text-truncate"><i class="bi bi-emoji-smile me-1"></i><?= htmlspecialchars($cm['idades_filhos']) ?></div><?php endif; ?>
                      </div>
                      <?php endif; ?>
                    </div>
                    
                    <!-- Botões de Ação na Mesa -->
                    <div class="d-flex align-items-center gap-2 flex-shrink-0 no-print">
                      <!-- Botão Confirmar/Desmarcar -->
                      <form method="POST" class="m-0 form-confirmar">
                        <input type="hidden" name="alternar_confirmacao" value="1">
                        <input type="hidden" name="convidado_id" value="<?= $cm['id'] ?>">
                        <button type="submit" class="btn btn-sm p-0 border-0 bg-transparent btn-acao-convidado" title="<?= $cm['confirmado'] ? 'Desmarcar presença' : 'Confirmar presença' ?>">
                          <i class="bi <?= $cm['confirmado'] ? 'bi-check-circle-fill text-success' : 'bi-circle text-warning' ?>" style="font-size: 1rem;"></i>
                        </button>
                      </form>

                      <!-- Botão Remover -->
                      <form method="POST" class="m-0 form-remover">
                        <input type="hidden" name="remover_da_mesa" value="1">
                        <input type="hidden" name="convidado_id" value="<?= $cm['id'] ?>">
                        <button type="submit" class="btn btn-sm text-danger p-0 border-0 bg-transparent btn-acao-convidado" title="Remover da mesa">
                          <i class="bi bi-x-circle-fill" style="font-size: 1rem;"></i>
                        </button>
                      </form>
                    </div>

                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="d-flex flex-wrap gap-3 mt-3 no-print" style="font-size:.7rem;color:#64748b;">
          <span><span class="legend-dot" style="background:#10b981;"></span> Confirmado</span>
          <span><span class="legend-dot" style="background:#60a5fa;"></span> Pendente</span>
          <span><span class="legend-dot" style="border:1px solid #cbd5e1;"></span> Livre</span>
          <span><span class="legend-dot" style="background:#f87171;"></span> Excede capacidade</span>
          <span class="ms-auto text-muted"><i class="bi bi-arrows-move me-1"></i>Segure <strong>⠿</strong> para reposicionar mesas</span>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- =========================================================
     MODAIS
     ========================================================= -->
<!-- Modal: Adicionar na Mesa -->
<div class="modal fade" id="modalAddGuest" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header border-0 pb-0">
        <h6 class="modal-title fw-bold">
          <i class="bi bi-person-plus-fill text-success me-2"></i>Adicionar à <span id="add-guest-mesa-nome" class="text-decoration-underline">Mesa</span>
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" class="form-ajax">
        <input type="hidden" name="adicionar_convidado_mesa" value="1">
        <input type="hidden" name="mesa_id" id="add-guest-mid">
        <div class="modal-body py-3">
          <div class="mb-0">
            <label class="form-label small fw-semibold text-secondary">Selecione o Convidado da Fila</label>
            <select name="convidado_id" id="select-convidados" class="form-select rounded-3" required>
              <!-- Será populado dinamicamente via JS -->
            </select>
            <div id="add-guest-warning" class="form-text text-danger mt-2" style="font-size: 0.75rem; display: none;">
              <i class="bi bi-exclamation-triangle"></i> Não há mais convidados na fila de espera.
            </div>
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" id="btn-submit-add-guest" class="btn btn-success btn-sm px-4 rounded-pill fw-semibold">Adicionar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Add Mesa -->
<div class="modal fade" id="modalAdd" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header border-0 pb-0">
        <h6 class="modal-title fw-bold"><i class="bi bi-plus-circle text-success me-2"></i>Nova Mesa</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" class="form-ajax">
        <input type="hidden" name="adicionar_mesa" value="1">
        <div class="modal-body py-3">
          <div class="mb-3"><label class="form-label small fw-semibold text-secondary">Nome</label><input type="text" name="nome_mesa" class="form-control rounded-3" required></div>
          <div><label class="form-label small fw-semibold text-secondary">Cadeiras</label><input type="number" name="capacidade_mesa" class="form-control rounded-3" value="8" min="1" max="100" required></div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-success btn-sm px-4 rounded-pill fw-semibold">Criar Mesa</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Edit Mesa -->
<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header border-0 pb-0">
        <h6 class="modal-title fw-bold"><i class="bi bi-pencil-fill text-primary me-2"></i>Editar Mesa</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" class="form-ajax">
        <input type="hidden" name="editar_mesa" value="1">
        <input type="hidden" name="mesa_id" id="e-mid">
        <div class="modal-body py-3">
          <div class="mb-3"><label class="form-label small fw-semibold text-secondary">Nome</label><input type="text" name="nome_mesa" id="e-nome" class="form-control rounded-3" required></div>
          <div><label class="form-label small fw-semibold text-secondary">Cadeiras</label><input type="number" name="capacidade_mesa" id="e-cap" class="form-control rounded-3" min="1" max="100" required></div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-semibold">Salvar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Lote -->
<div class="modal fade" id="modalLote" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header border-0 pb-0">
        <h6 class="modal-title fw-bold"><i class="bi bi-layers text-primary me-2"></i>Criar em Lote</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" class="form-ajax">
        <input type="hidden" name="criar_multiplas_mesas" value="1">
        <div class="modal-body py-3">
          <div class="row g-3">
            <div class="col-12"><label class="form-label small fw-semibold text-secondary">Prefixo</label><input type="text" name="prefixo_mesa" class="form-control rounded-3" value="Mesa" required></div>
            <div class="col-6"><label class="form-label small fw-semibold text-secondary">Início</label><input type="number" name="numero_inicio" class="form-control rounded-3" value="1" min="1" required></div>
            <div class="col-6"><label class="form-label small fw-semibold text-secondary">Quantidade</label><input type="number" name="qtd_mesas" class="form-control rounded-3" value="5" min="1" max="50" required></div>
            <div class="col-12"><label class="form-label small fw-semibold text-secondary">Cadeiras</label><input type="number" name="capacidade_padrao" class="form-control rounded-3" value="8" min="1" max="100" required></div>
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-semibold">Criar Mesas</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- =========================================================
     SCRIPTS
     ========================================================= -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

  document.querySelectorAll('.toast').forEach(el => bootstrap.Toast.getOrCreateInstance(el).show());

  // Delegação dos botões de ação nas mesas
  document.addEventListener('click', function(e) {
    // Botão Editar
    const btnEdit = e.target.closest('.btn-edit');
    if (btnEdit) {
      document.getElementById('e-mid').value  = btnEdit.dataset.id;
      document.getElementById('e-nome').value = btnEdit.dataset.nome;
      document.getElementById('e-cap').value  = btnEdit.dataset.cap;
    }
    
    // Botão Adicionar Convidado na Mesa
    const btnAddGuest = e.target.closest('.btn-add-guest');
    if (btnAddGuest) {
      document.getElementById('add-guest-mid').value = btnAddGuest.dataset.id;
      document.getElementById('add-guest-mesa-nome').textContent = btnAddGuest.dataset.nome;
      
      const select = document.getElementById('select-convidados');
      select.innerHTML = '<option value="" disabled selected>Escolha um convidado...</option>';
      
      const convidadosFila = document.querySelectorAll('#lista-espera .conv-item');
      let qtdDisponivel = 0;
      
      convidadosFila.forEach(item => {
        const cid = item.dataset.convId;
        const nome = item.querySelector('.fw-semibold').textContent.trim();
        const lugares = item.dataset.lugares;
        const pendenteText = item.dataset.status === 'pendente' ? ' ⏳' : ' ✓';
        
        const option = document.createElement('option');
        option.value = cid;
        option.textContent = `${nome} (${lugares} lug.)${pendenteText}`;
        select.appendChild(option);
        qtdDisponivel++;
      });
      
      const btnSubmit = document.getElementById('btn-submit-add-guest');
      const warning = document.getElementById('add-guest-warning');
      
      if (qtdDisponivel === 0) {
        btnSubmit.disabled = true;
        select.disabled = true;
        warning.style.display = 'block';
      } else {
        btnSubmit.disabled = false;
        select.disabled = false;
        warning.style.display = 'none';
      }
    }
  });

  // Filtros da Fila
  const busca = document.getElementById('busca');
  let filtroAtivo = 'todos';

  function applyFilter() {
    const t = busca.value.toLowerCase().trim();
    document.querySelectorAll('#lista-espera .conv-item').forEach(item => {
      const matchNome   = !t || (item.dataset.nome || '').includes(t);
      const matchStatus = filtroAtivo === 'todos' || item.dataset.status === filtroAtivo;
      item.style.display = (matchNome && matchStatus) ? '' : 'none';
    });
  }
  busca.addEventListener('input', applyFilter);

  document.querySelectorAll('#filtros-wrap .btn').forEach(btn => {
    btn.addEventListener('click', function () {
      filtroAtivo = this.dataset.f;
      document.querySelectorAll('#filtros-wrap .btn').forEach(b => {
        const f = b.dataset.f;
        b.className = 'btn btn-sm rounded-pill ' + (b === this
          ? (f === 'confirmado' ? 'btn-success active' : f === 'pendente' ? 'btn-warning active' : 'btn-primary active')
          : (f === 'confirmado' ? 'btn-outline-success' : f === 'pendente' ? 'btn-outline-warning' : 'btn-outline-secondary'));
        b.style.cssText = 'font-size:.7rem;padding:.25rem .6rem;';
      });
      applyFilter();
    });
  });

  // AJAX e Atualização Silenciosa
  const overlay = document.getElementById('overlay');

  async function processAjaxAction(formData, actionType = 'full') {
    overlay.classList.add('show');
    formData.append('ajax_html', '1');

    try {
      const resp = await fetch(window.location.href, { method: 'POST', body: formData });
      if (!resp.ok) throw new Error("Erro de conexão");
      
      const text = await resp.text();
      const doc = new DOMParser().parseFromString(text, 'text/html');

      const cStats = document.querySelector('.hdr .row.g-2');
      const nStats = doc.querySelector('.hdr .row.g-2');
      if (cStats && nStats) cStats.innerHTML = nStats.innerHTML;

      const cBadge = document.getElementById('badge-qtd');
      const nBadge = doc.getElementById('badge-qtd');
      if (cBadge && nBadge) cBadge.innerHTML = nBadge.innerHTML;

      if (actionType === 'silent') {
        document.querySelectorAll('.mesa-col').forEach(col => {
          const mid = col.dataset.mesaId;
          const newCol = doc.querySelector(`.mesa-col[data-mesa-id="${mid}"]`);
          if (newCol) {
            const curCard = col.querySelector('.mesa-card');
            const newCard = newCol.querySelector('.mesa-card');
            if (curCard && newCard) curCard.className = newCard.className;

            const curHeader = col.querySelector('.card-header');
            const newHeader = newCol.querySelector('.card-header');
            if (curHeader && newHeader) curHeader.innerHTML = newHeader.innerHTML;
          }
        });

        const cFila = document.getElementById('lista-espera');
        if (cFila) {
          const hasItems = cFila.querySelectorAll('.conv-item').length > 0;
          const emptyMsg = cFila.querySelector('.msg-vazia-estatica');
          if (!hasItems && !emptyMsg) {
            const nEmpty = doc.querySelector('#lista-espera .msg-vazia-estatica');
            if (nEmpty) cFila.insertAdjacentHTML('afterbegin', nEmpty.outerHTML);
          } else if (hasItems && emptyMsg) {
            emptyMsg.remove();
          }
        }

        document.querySelectorAll('.mesa-card .sortable-area').forEach(area => {
          const hasItems = area.querySelectorAll('.conv-item').length > 0;
          const emptyMsg = area.querySelector('.msg-vazia-estatica');
          if (!hasItems && !emptyMsg) {
            const mid = area.dataset.mesaId;
            const nEmpty = doc.querySelector(`.sortable-area[data-mesa-id="${mid}"] .msg-vazia-estatica`);
            if (nEmpty) area.insertAdjacentHTML('afterbegin', nEmpty.outerHTML);
          } else if (hasItems && emptyMsg) {
            emptyMsg.remove();
          }
        });

      } else {
        // Atualização Completa (Padrão para botões, pra repintar fila e mesas certinho)
        const cToasts = document.querySelector('.toast-container');
        const nToasts = doc.querySelector('.toast-container');
        if (cToasts && nToasts) {
          cToasts.innerHTML = nToasts.innerHTML;
          cToasts.querySelectorAll('.toast').forEach(t => bootstrap.Toast.getOrCreateInstance(t).show());
        }

        const cFila = document.getElementById('lista-espera');
        const nFila = doc.getElementById('lista-espera');
        if (cFila && nFila) cFila.innerHTML = nFila.innerHTML;

        const cMesas = document.getElementById('mesas-container');
        const nMesas = doc.getElementById('mesas-container');
        if (cMesas && nMesas) cMesas.innerHTML = nMesas.innerHTML;

        initSortables();
        applyFilter();
      }

    } catch (err) {
      window.location.reload(); 
    } finally {
      overlay.classList.remove('show');
    }
  }

  // Interceptar todos os formulários do painel
  document.addEventListener('submit', function(e) {
    const form = e.target;
    // Note a adição do 'alternar_confirmacao'
    const isAction = form.querySelector('[name="adicionar_mesa"], [name="editar_mesa"], [name="criar_multiplas_mesas"], [name="excluir_mesa"], [name="esvaziar_mesa"], [name="remover_da_mesa"], [name="adicionar_convidado_mesa"], [name="alternar_confirmacao"]');

    if (isAction) {
      e.preventDefault();

      if (form.querySelector('[name="excluir_mesa"]')) {
        if (!confirm('Tem certeza que quer apagar esta mesa? Os convidados voltarão para a fila.')) return;
      }
      if (form.querySelector('[name="esvaziar_mesa"]')) {
        if (!confirm('Deseja realmente esvaziar esta mesa? Todos os convidados voltarão para a fila.')) return;
      }

      const modalNode = form.closest('.modal');
      if (modalNode) bootstrap.Modal.getInstance(modalNode).hide();

      const fd = new FormData(form);
      processAjaxAction(fd, 'full');
    }
  });

  let sortables = [];

  function initSortables() {
    sortables.forEach(s => s.destroy());
    sortables = [];

    document.querySelectorAll('.sortable-area').forEach(area => {
      const s = Sortable.create(area, {
        group: 'convidados',
        animation: 200,
        ghostClass: 'sortable-ghost',
        chosenClass: 'sortable-chosen',
        dragClass: 'sortable-drag',
        onStart() {
          document.querySelectorAll('.msg-vazia-estatica').forEach(el => el.style.display = 'none');
        },
        onEnd(evt) {
          if (evt.to === evt.from && evt.newIndex === evt.oldIndex) {
            const fd = new FormData();
            processAjaxAction(fd, 'silent'); 
            return;
          }

          const convId = evt.item.dataset.convId;
          const mesaId = evt.to.dataset.mesaId;

          const fd = new FormData();
          fd.append('mover_convidado_ajax', '1');
          fd.append('convidado_id', convId);
          fd.append('nova_mesa_id', mesaId);

          processAjaxAction(fd, 'silent');
        }
      });
      sortables.push(s);
    });

    const grid = document.getElementById('grid-mesas');
    if (grid) {
      const sg = Sortable.create(grid, {
        animation: 200,
        handle: '.drag-mesa',
        ghostClass: 'sortable-ghost',
        chosenClass: 'sortable-chosen',
        onEnd() {
          const ordem = [...grid.querySelectorAll('.mesa-col')].map(c => c.dataset.mesaId);
          const fd = new FormData();
          fd.append('reordenar_mesas_ajax', '1');
          fd.append('ordem_mesas', JSON.stringify(ordem));
          fetch(window.location.href, { method: 'POST', body: fd }); 
        }
      });
      sortables.push(sg);
    }
  }

  initSortables();
});
</script>
</body>
</html>