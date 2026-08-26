<?php
session_start();
require_once 'sessao_timeout.inc.php';
verificar_sessao_ativa();

if (!isset($_SESSION['usuario_tipo']) || !in_array($_SESSION['usuario_tipo'], ['admin', 'assistente', 'noivos'])) {
    header("Location: index.php?sessao_expirada=1");
    exit;
}
$eh_noivos = ($_SESSION['usuario_tipo'] === 'noivos');

require_once 'conexao.php';

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

if ($eh_noivos) {
    // Noivos só podem gerenciar convidados do próprio evento (ignora manipulação da URL)
    $evento_id = (int)($_SESSION['evento_id'] ?? 0);
    if (!$evento_id) { header("Location: index.php"); exit; }
} else {
    $evento_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$evento_id) {
        header("Location: painel_admin.php");
        exit;
    }
}
$url_pagina = 'convidados.php' . ($eh_noivos ? '' : "?id=$evento_id");

/* ============================================================
   AUTO-CONFIGURAÇÃO DO BANCO DE DADOS
   ============================================================ */
if (!schema_ja_verificado('convidados')) {
    $schema_checks = [
        "SELECT resposta_rsvp FROM convidados LIMIT 1"          => "ALTER TABLE convidados ADD COLUMN resposta_rsvp VARCHAR(20) NULL",
        "SELECT token_convite FROM convidados LIMIT 1"          => "ALTER TABLE convidados ADD COLUMN token_convite VARCHAR(64) NULL",
        "SELECT convidado_principal_id FROM convidados LIMIT 1" => "ALTER TABLE convidados ADD COLUMN convidado_principal_id INT NULL",
    ];
    foreach ($schema_checks as $check => $alter) {
        try { $pdo->query($check); } catch (Exception $e) { try { $pdo->exec($alter); } catch (Exception $x) {} }
    }
    marcar_schema_verificado('convidados');
}

// Link público de confirmação de presença (usado pelo botão de WhatsApp)
$link_confirmacao_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$link_confirmacao_base   = $link_confirmacao_scheme . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/');
$link_confirmacao_url    = $link_confirmacao_base . '/confirmar.php?evento=' . $evento_id;

/* ============================================================
   HELPER AJAX
   ============================================================ */
function json_out($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

const FAIXAS_ETARIAS_CONVIDADOS = ['Adulto (11+ anos)', 'Criança (6-10 anos)', 'Criança de Colo (0-5 anos)'];

/** Sincroniza os acompanhantes (nome + faixa etária) de um convidado titular:
 *  atualiza os que vieram com id, cria os novos, remove os que saíram da lista. */
function sincronizar_acompanhantes(PDO $pdo, int $evento_id, int $principal_id, array $ids, array $nomes, array $faixas): void {
    $mantidos = [];
    for ($i = 0; $i < count($nomes); $i++) {
        $nome = trim($nomes[$i] ?? '');
        if ($nome === '') continue;
        $faixa = in_array($faixas[$i] ?? '', FAIXAS_ETARIAS_CONVIDADOS, true) ? $faixas[$i] : FAIXAS_ETARIAS_CONVIDADOS[0];
        $id = (int)($ids[$i] ?? 0);

        if ($id > 0) {
            $chk = $pdo->prepare("SELECT id FROM convidados WHERE id = ? AND convidado_principal_id = ? AND evento_id = ?");
            $chk->execute([$id, $principal_id, $evento_id]);
            if ($chk->fetch()) {
                $pdo->prepare("UPDATE convidados SET nome = ?, faixa_etaria = ? WHERE id = ?")->execute([$nome, $faixa, $id]);
                $mantidos[] = $id;
                continue;
            }
        }

        $pdo->prepare("INSERT INTO convidados (evento_id, nome, faixa_etaria, categoria, confirmado, convidado_principal_id) VALUES (?, ?, ?, 'Outros', 0, ?)")
            ->execute([$evento_id, $nome, $faixa, $principal_id]);
        $mantidos[] = (int)$pdo->lastInsertId();
    }

    $stmtAtuais = $pdo->prepare("SELECT id FROM convidados WHERE convidado_principal_id = ? AND evento_id = ?");
    $stmtAtuais->execute([$principal_id, $evento_id]);
    $idsAtuais = array_map('intval', $stmtAtuais->fetchAll(PDO::FETCH_COLUMN));
    $idsRemover = array_diff($idsAtuais, $mantidos);
    if (!empty($idsRemover)) {
        $ph = implode(',', array_fill(0, count($idsRemover), '?'));
        $pdo->prepare("DELETE FROM convidados WHERE id IN ($ph)")->execute(array_values($idsRemover));
    }
}

/* ============================================================
   POST HANDLERS
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verificar_csrf();

    // AJAX: gera (se ainda não existir) e devolve o link específico do convidado, pro botão de WhatsApp
    if (isset($_POST['obter_link_whatsapp'])) {
        $cid = (int)($_POST['convidado_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM convidados WHERE id = ? AND evento_id = ?");
        $stmt->execute([$cid, $evento_id]);
        $conv = $stmt->fetch();
        if (!$conv) json_out(['ok' => false, 'msg' => 'Convidado não encontrado.']);

        $tel_digits = preg_replace('/\D+/', '', $conv['telefone'] ?? '');
        if (strlen($tel_digits) < 10) {
            json_out(['ok' => false, 'msg' => 'Cadastre um telefone com DDD para este convidado antes de enviar.']);
        }

        $token = $conv['token_convite'];
        if (empty($token)) {
            do {
                $token = bin2hex(random_bytes(16));
                $chk = $pdo->prepare("SELECT id FROM convidados WHERE token_convite = ?");
                $chk->execute([$token]);
            } while ($chk->fetch());
            $pdo->prepare("UPDATE convidados SET token_convite = ? WHERE id = ?")->execute([$token, $cid]);
        }

        json_out([
            'ok'              => true,
            'nome'            => $conv['nome'],
            'telefone_digits' => $tel_digits,
            'link'            => $link_confirmacao_url . '&token=' . $token,
        ]);
    }

    // 1. Criar Convite (titular + acompanhantes com nome e faixa etária)
    // Sempre entra como "pendente": nem o titular nem os acompanhantes têm como os
    // noivos/assessoria saberem se vão comparecer antes deles mesmos responderem pelo link.
    if (isset($_POST['adicionar_convidado'])) {
        $nome       = trim($_POST['nome_convidado']      ?? '');
        $fone       = trim($_POST['telefone_convidado']  ?? '');
        $cat        = trim($_POST['categoria_convidado'] ?? 'Outros');
        $nomes_acomp  = $_POST['nome_acompanhante_novo']  ?? [];
        $faixas_acomp = $_POST['faixa_acompanhante_novo'] ?? [];

        if ($nome === '') {
            $_SESSION['msg_erro'] = "Informe o nome do convidado.";
        } elseif (strlen(preg_replace('/\D+/', '', $fone)) < 10) {
            $_SESSION['msg_erro'] = "Informe um telefone/WhatsApp válido (com DDD) para o convidado.";
        } else {
            $pdo->prepare("INSERT INTO convidados (evento_id, nome, telefone, categoria, confirmado) VALUES (?, ?, ?, ?, 0)")
                ->execute([$evento_id, $nome, $fone, $cat]);
            $novo_id = (int)$pdo->lastInsertId();
            sincronizar_acompanhantes($pdo, $evento_id, $novo_id, [], $nomes_acomp, $faixas_acomp);
            $_SESSION['msg_sucesso'] = "Convite <strong>" . htmlspecialchars($nome) . "</strong> criado!";
        }
        header("Location: $url_pagina"); exit;
    }

    // 2. Editar Convidado (titular + acompanhantes com nome e faixa etária)
    if (isset($_POST['editar_convidado'])) {
        $cid        = (int)($_POST['convidado_id'] ?? 0);
        $nome       = trim($_POST['nome_convidado']      ?? '');
        $fone       = trim($_POST['telefone_convidado']  ?? '');
        $cat        = trim($_POST['categoria_convidado'] ?? 'Outros');
        $confirmado = ($_POST['status_convidado'] ?? 'pendente') === 'confirmado' ? 1 : 0;
        $ids_acomp    = $_POST['id_acompanhante_edit']    ?? [];
        $nomes_acomp  = $_POST['nome_acompanhante_edit']  ?? [];
        $faixas_acomp = $_POST['faixa_acompanhante_edit'] ?? [];

        if ($cid <= 0 || $nome === '') {
            $_SESSION['msg_erro'] = "Informe o nome do convidado.";
        } elseif (strlen(preg_replace('/\D+/', '', $fone)) < 10) {
            $_SESSION['msg_erro'] = "Informe um telefone/WhatsApp válido (com DDD) para o convidado.";
        } else {
            $pdo->prepare("UPDATE convidados SET nome = ?, telefone = ?, categoria = ?, confirmado = ? WHERE id = ? AND evento_id = ?")
                ->execute([$nome, $fone, $cat, $confirmado, $cid, $evento_id]);
            sincronizar_acompanhantes($pdo, $evento_id, $cid, $ids_acomp, $nomes_acomp, $faixas_acomp);
            $_SESSION['msg_sucesso'] = "Convidado <strong>" . htmlspecialchars($nome) . "</strong> atualizado!";
        }
        header("Location: $url_pagina"); exit;
    }

    // 3. Excluir Convidado (e os acompanhantes do link específico dele, se houver)
    if (isset($_POST['excluir_convidado'])) {
        $cid = (int)($_POST['convidado_id'] ?? 0);
        $st  = $pdo->prepare("SELECT nome FROM convidados WHERE id = ? AND evento_id = ?");
        $st->execute([$cid, $evento_id]);
        $nn  = $st->fetchColumn() ?: 'Convidado';
        $pdo->prepare("DELETE FROM convidados WHERE convidado_principal_id = ? AND evento_id = ?")->execute([$cid, $evento_id]);
        $pdo->prepare("DELETE FROM convidados WHERE id = ? AND evento_id = ?")->execute([$cid, $evento_id]);
        $_SESSION['msg_sucesso'] = "Convidado <strong>" . htmlspecialchars($nn) . "</strong> removido.";
        header("Location: $url_pagina"); exit;
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

$stmtC = $pdo->prepare("SELECT * FROM convidados WHERE evento_id = ? ORDER BY nome ASC");
$stmtC->execute([$evento_id]);
$todos_convidados = $stmtC->fetchAll(PDO::FETCH_ASSOC);

$acompanhantes_por_principal = [];
foreach ($todos_convidados as $c) {
    if (!empty($c['convidado_principal_id'])) {
        $acompanhantes_por_principal[$c['convidado_principal_id']][] = $c;
    }
}
// A lista principal não mostra os acompanhantes do link específico como linhas
// separadas — eles aparecem agrupados dentro do card do convidado titular.
$lista_convidados = array_values(array_filter($todos_convidados, fn($c) => empty($c['convidado_principal_id'])));

$total_conf = 0; $total_pend = 0; $total_recusado = 0;
foreach ($todos_convidados as $c) {
    if ($c['resposta_rsvp'] === 'recusado') $total_recusado++;
    elseif ($c['confirmado']) $total_conf++;
    else $total_pend++;
}
$total_conv = count($lista_convidados);

$categorias_existentes = array_values(array_unique(array_filter(array_map(fn($c) => trim($c['categoria']), $lista_convidados))));
sort($categorias_existentes);

$msg_ok  = $_SESSION['msg_sucesso'] ?? '';
$msg_err = $_SESSION['msg_erro'] ?? '';
unset($_SESSION['msg_sucesso'], $_SESSION['msg_erro']);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gerenciar Convidados — <?= htmlspecialchars($evento['nome']) ?> - Meu Evento PRO</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
  <link rel="stylesheet" href="css/estilo.css?v=13">

  <style>
    :root { --radius: 12px; }
    body  { background: var(--bg-app); }

    .hdr { background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%); border-radius: var(--radius); }
    .stat {
      background: var(--color-primary-light); border: 1px solid rgba(0,0,0,.08);
      border-radius: var(--radius); padding: .85rem 1rem; color: var(--color-primary-dark);
      display: flex; align-items: center; gap: .75rem;
    }
    .stat-icon {
      width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      background: rgba(255,255,255,.7); font-size: 1.05rem;
    }
    .stat .val { font-size: 1.5rem; font-weight: 700; line-height: 1; }
    .stat .lbl { font-size: .65rem; opacity: .7; text-transform: uppercase; letter-spacing: .05em; margin-top: .3rem; }

    @media (min-width: 768px) {
      .stat { position: relative; justify-content: center; }
      .stat-icon { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); }
      .stat-body { text-align: center; }
    }
    @media (max-width: 767.98px) {
      .stat { flex-direction: column; align-items: flex-start; gap: 0; padding: .75rem; }
      .stat-icon { width: 26px; height: 26px; font-size: .75rem; border-radius: 7px; }
      .stat-body { width: 100%; text-align: center; }
      .stat-body .val { margin-top: -1rem; }
    }

    .sw { position: relative; }
    .sw .bi-search { position: absolute; left: .9rem; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: .85rem; }
    .sw input { padding-left: 2.2rem; }

    .guest-card { border-left: 4px solid transparent !important; transition: box-shadow .15s; }
    .conv-row.confirmado .guest-card { border-left-color: #10b981 !important; }
    .conv-row.pendente   .guest-card { border-left-color: #f59e0b !important; }
    .conv-row.recusado   .guest-card { border-left-color: #94a3b8 !important; }
    .guest-card:hover { box-shadow: 0 .4rem 1rem rgba(0,0,0,.08) !important; }
    .guest-card-acomp { background: #fbfbfd; }

    /* Acompanhantes ficam sempre um do lado do outro (uma linha só); se não
       couber, rola na horizontal em vez de quebrar pra próxima linha. */
    .acomp-scroll { overflow-x: auto; padding-bottom: 2px; scrollbar-width: thin; }
    .acomp-scroll::-webkit-scrollbar { height: 4px; }
    .acomp-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

    .btn-icon-conv {
      width: 26px; height: 26px; padding: 0; border: 1px solid #e2e8f0; border-radius: 50%;
      display: inline-flex; align-items: center; justify-content: center;
      background: #fff; transition: background .15s, transform .1s; font-size: .8rem;
    }
    .btn-icon-conv:hover { background: #f1f5f9; transform: scale(1.06); }
    .btn-icon-conv:disabled { opacity: .4; cursor: not-allowed; }
    .btn-icon-conv.btn-whatsapp-conv { color: #16a34a; border-color: #bbf7d0; }
    .btn-icon-conv.btn-whatsapp-conv:hover { background: #f0fdf4; }
  </style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark shadow-sm">
  <div class="container-fluid px-3 px-lg-4">
    <span class="navbar-brand mb-0">
      <img src="img/LOGO MEP NAV.svg" alt="Meu Evento PRO" style="height:40px;">
    </span>
    <div class="d-flex align-items-center gap-2">
      <a href="<?= $eh_noivos ? 'noivos.php' : 'gerenciar.php?id=' . $evento_id ?>" class="btn btn-sm btn-outline-light rounded-3">
        <i class="bi bi-arrow-left me-1"></i> Voltar ao Painel
      </a>
    </div>
  </div>
</nav>

<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:10000;">
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

  <div class="hdr p-4 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
      <div>
        <h4 class="fw-bold text-white mb-1">
          <i class="bi bi-people-fill text-info me-2"></i> Gerenciar Convidados
        </h4>
        <p class="text-white opacity-50 mb-0 small">
          <?= htmlspecialchars($evento['nome']) ?> &bull; <?= date('d/m/Y', strtotime($evento['data_evento'])) ?>
        </p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <a href="organizar_mesas.php<?= $eh_noivos ? '' : '?id=' . $evento_id ?>" class="btn btn-sm btn-primary rounded-pill fw-semibold shadow-sm px-3">
          <i class="bi bi-grid-3x3-gap-fill me-1"></i> Organizar Mesas
        </a>
        <button class="btn btn-sm btn-info rounded-pill text-dark fw-semibold shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#modalAddConvidado">
          <i class="bi bi-person-plus-fill me-1"></i> Criar Convite
        </button>
      </div>
    </div>

    <div class="row g-2 mt-3">
      <div class="col-6 col-sm-3">
        <div class="stat">
          <span class="stat-icon text-primary"><i class="bi bi-envelope-fill"></i></span>
          <div class="stat-body"><div class="val" id="cnt-total"><?= $total_conv ?></div><div class="lbl">Convites</div></div>
        </div>
      </div>
      <div class="col-6 col-sm-3">
        <div class="stat">
          <span class="stat-icon text-success"><i class="bi bi-check-circle-fill"></i></span>
          <div class="stat-body"><div class="val text-success" id="cnt-conf"><?= $total_conf ?></div><div class="lbl">Confirmados</div></div>
        </div>
      </div>
      <div class="col-6 col-sm-3">
        <div class="stat">
          <span class="stat-icon" style="color:#d97706;"><i class="bi bi-hourglass-split"></i></span>
          <div class="stat-body"><div class="val" id="cnt-pend" style="color:#d97706;"><?= $total_pend ?></div><div class="lbl">Pendentes</div></div>
        </div>
      </div>
      <div class="col-6 col-sm-3">
        <div class="stat">
          <span class="stat-icon text-secondary"><i class="bi bi-x-circle-fill"></i></span>
          <div class="stat-body"><div class="val text-secondary" id="cnt-recusado"><?= $total_recusado ?></div><div class="lbl">Recusaram</div></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm" style="border-radius:var(--radius);">
    <div class="card-header bg-white border-bottom p-3">
      <div class="row g-2 align-items-center">
        <div class="col-md-6">
          <div class="sw">
            <i class="bi bi-search"></i>
            <input type="text" id="busca-convidado" class="form-control rounded-pill" placeholder="Buscar por nome...">
          </div>
        </div>
        <div class="col-md-6 d-flex gap-1 justify-content-md-end" id="filtros-wrap">
          <button class="btn btn-primary btn-sm rounded-pill active" data-f="todos" style="font-size:.7rem;padding:.3rem .7rem;">Todos</button>
          <button class="btn btn-outline-success btn-sm rounded-pill" data-f="confirmado" style="font-size:.7rem;padding:.3rem .7rem;">Confirmados</button>
          <button class="btn btn-outline-warning btn-sm rounded-pill" data-f="pendente" style="font-size:.7rem;padding:.3rem .7rem;">Pendentes</button>
          <button class="btn btn-outline-secondary btn-sm rounded-pill" data-f="recusado" style="font-size:.7rem;padding:.3rem .7rem;">Recusaram</button>
        </div>
      </div>
    </div>

    <div class="card-body p-3" id="lista-convidados">
      <?php if (empty($lista_convidados)): ?>
        <div class="text-center text-muted py-5" id="msg-lista-vazia-geral">
          <i class="bi bi-people fs-1 d-block mb-2"></i>
          Nenhum convidado cadastrado ainda.
        </div>
      <?php else: ?>
      <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xxl-5 g-3">
      <?php foreach ($lista_convidados as $c):
          $status = $c['resposta_rsvp'] === 'recusado' ? 'recusado' : ($c['confirmado'] ? 'confirmado' : 'pendente');
          $acompLink = $acompanhantes_por_principal[$c['id']] ?? [];
          $lugares = 1 + count($acompLink);
          $temTelefone = strlen(preg_replace('/\D+/', '', $c['telefone'] ?? '')) >= 10;
          $acompJson = htmlspecialchars(json_encode(array_map(fn($a) => ['id' => $a['id'], 'nome' => $a['nome'], 'faixa' => $a['faixa_etaria']], $acompLink)), ENT_QUOTES, 'UTF-8');
      ?>
      <!-- Titular: cada pessoa (titular ou acompanhante) é um card independente e
           filtrável pelo próprio status, um do lado do outro na mesma família. -->
      <div class="col conv-row <?= $status ?>" data-nome="<?= strtolower(htmlspecialchars($c['nome'], ENT_QUOTES, 'UTF-8')) ?>" data-status="<?= $status ?>">
        <div class="card border guest-card rounded-3 p-2 h-100 d-flex flex-column">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div class="min-w-0">
              <span class="fw-bold text-dark d-block text-truncate" style="font-size:.85rem;" title="<?= htmlspecialchars($c['nome'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($c['nome'], ENT_QUOTES, 'UTF-8') ?></span>
              <div class="d-flex align-items-center gap-1 flex-wrap mt-1">
                <span class="badge bg-light text-dark border" style="font-size:.6rem;"><?= htmlspecialchars($c['categoria'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($lugares > 1): ?>
                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill" style="font-size:.6rem;">
                  <i class="bi bi-person-fill"></i> <?= $lugares ?>
                </span>
                <?php endif; ?>
              </div>
            </div>
            <span class="badge flex-shrink-0 <?= $status === 'confirmado' ? 'bg-success' : ($status === 'recusado' ? 'bg-secondary' : 'bg-warning text-dark') ?>" style="font-size:.6rem;">
              <?= $status === 'confirmado' ? 'Confirmado' : ($status === 'recusado' ? 'Recusou' : 'Pendente') ?>
            </span>
          </div>

          <?php if (!empty($c['telefone'])): ?>
            <div class="text-muted mt-1" style="font-size:.72rem;"><i class="bi bi-whatsapp me-1 text-success"></i><?= htmlspecialchars($c['telefone'], ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>

          <div class="d-flex align-items-center gap-2 justify-content-end mt-auto pt-1 border-top">
            <button type="button" class="btn-icon-conv btn-whatsapp-conv btn-whatsapp-convidado" data-id="<?= $c['id'] ?>"
                    <?= $temTelefone ? '' : 'disabled' ?>
                    title="<?= $temTelefone ? 'Enviar link de confirmação por WhatsApp' : 'Cadastre um telefone válido para enviar o link' ?>">
              <i class="bi bi-whatsapp"></i>
            </button>
            <button type="button" class="btn-icon-conv text-primary btn-edit-convidado"
                    title="Editar convidado"
                    data-id="<?= $c['id'] ?>"
                    data-nome="<?= htmlspecialchars($c['nome'], ENT_QUOTES, 'UTF-8') ?>"
                    data-telefone="<?= htmlspecialchars($c['telefone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    data-categoria="<?= htmlspecialchars($c['categoria'], ENT_QUOTES, 'UTF-8') ?>"
                    data-acompanhantes-json="<?= $acompJson ?>"
                    data-confirmado="<?= (int)$c['confirmado'] ?>"
                    data-bs-toggle="modal" data-bs-target="#modalEditConvidado">
              <i class="bi bi-pencil-fill"></i>
            </button>
            <form method="POST" class="m-0" onsubmit="return confirm('Tem certeza que quer excluir este convidado? Essa ação não pode ser desfeita.');">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
              <input type="hidden" name="excluir_convidado" value="1">
              <input type="hidden" name="convidado_id" value="<?= $c['id'] ?>">
              <button type="submit" class="btn-icon-conv text-danger" title="Excluir convidado">
                <i class="bi bi-trash-fill"></i>
              </button>
            </form>
          </div>
        </div>
      </div>

      <?php foreach ($acompLink as $a):
          $statusA = $a['resposta_rsvp'] === 'recusado' ? 'recusado' : ($a['confirmado'] ? 'confirmado' : 'pendente');
          $rotulo = str_starts_with($a['faixa_etaria'] ?? '', 'Criança de Colo') ? 'Criança de colo'
                  : (str_starts_with($a['faixa_etaria'] ?? '', 'Criança') ? 'Criança' : 'Adulto');
      ?>
      <!-- Acompanhante: card próprio, filtrável pelo status dele mesmo, com a
           referência de quem ele está acompanhando. Editar abre o convite do
           titular (é lá que a lista de acompanhantes é ajustada). -->
      <div class="col conv-row <?= $statusA ?>" data-nome="<?= strtolower(htmlspecialchars($a['nome'], ENT_QUOTES, 'UTF-8')) ?>" data-status="<?= $statusA ?>">
        <div class="card border guest-card guest-card-acomp rounded-3 p-2 h-100 d-flex flex-column">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div class="min-w-0">
              <span class="fw-bold text-dark d-block text-truncate" style="font-size:.85rem;" title="<?= htmlspecialchars($a['nome'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($a['nome'], ENT_QUOTES, 'UTF-8') ?></span>
              <div class="d-flex align-items-center gap-1 flex-wrap mt-1">
                <span class="badge bg-light text-dark border" style="font-size:.6rem;"><?= $rotulo ?></span>
              </div>
            </div>
            <span class="badge flex-shrink-0 <?= $statusA === 'confirmado' ? 'bg-success' : ($statusA === 'recusado' ? 'bg-secondary' : 'bg-warning text-dark') ?>" style="font-size:.6rem;">
              <?= $statusA === 'confirmado' ? 'Confirmado' : ($statusA === 'recusado' ? 'Recusou' : 'Pendente') ?>
            </span>
          </div>

          <div class="text-muted mt-1" style="font-size:.72rem;"><i class="bi bi-people-fill me-1"></i>Acompanha: <?= htmlspecialchars($c['nome'], ENT_QUOTES, 'UTF-8') ?></div>

          <div class="d-flex align-items-center gap-2 justify-content-end mt-auto pt-1 border-top">
            <button type="button" class="btn-icon-conv text-primary btn-edit-convidado"
                    title="Editar convite de <?= htmlspecialchars($c['nome'], ENT_QUOTES, 'UTF-8') ?>"
                    data-id="<?= $c['id'] ?>"
                    data-nome="<?= htmlspecialchars($c['nome'], ENT_QUOTES, 'UTF-8') ?>"
                    data-telefone="<?= htmlspecialchars($c['telefone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                    data-categoria="<?= htmlspecialchars($c['categoria'], ENT_QUOTES, 'UTF-8') ?>"
                    data-acompanhantes-json="<?= $acompJson ?>"
                    data-confirmado="<?= (int)$c['confirmado'] ?>"
                    data-bs-toggle="modal" data-bs-target="#modalEditConvidado">
              <i class="bi bi-pencil-fill"></i>
            </button>
            <form method="POST" class="m-0" onsubmit="return confirm('Remover <?= htmlspecialchars(addslashes($a['nome']), ENT_QUOTES, 'UTF-8') ?> da lista de acompanhantes?');">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
              <input type="hidden" name="excluir_convidado" value="1">
              <input type="hidden" name="convidado_id" value="<?= $a['id'] ?>">
              <button type="submit" class="btn-icon-conv text-danger" title="Remover acompanhante">
                <i class="bi bi-trash-fill"></i>
              </button>
            </form>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="text-center text-muted py-4 d-none" id="msg-lista-vazia-filtro">
        <i class="bi bi-search fs-2 d-block mb-2"></i> Nenhum convidado encontrado com esse filtro.
      </div>
    </div>
  </div>
</div>

<!-- Modal: Criar Convite -->
<div class="modal fade" id="modalAddConvidado" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header border-0 pb-0">
        <h6 class="modal-title fw-bold"><i class="bi bi-person-plus-fill text-info me-2"></i>Criar Convite</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="adicionar_convidado" value="1">
        <div class="modal-body py-3">
          <div class="mb-3">
            <label class="form-label small fw-semibold text-secondary">Nome do Convidado / Família (Titular)</label>
            <input type="text" name="nome_convidado" class="form-control rounded-3" required>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary">Categoria / Grupo</label>
              <input type="text" name="categoria_convidado" class="form-control rounded-3" list="lista-categorias-convidados" placeholder="Ex: Família..." required>
              <datalist id="lista-categorias-convidados">
                <?php if (!empty($categorias_existentes)): foreach ($categorias_existentes as $catEx): ?>
                  <option value="<?= htmlspecialchars($catEx, ENT_QUOTES, 'UTF-8') ?>">
                <?php endforeach; else: ?>
                  <option value="Família"><option value="Amigos"><option value="Outros">
                <?php endif; ?>
              </datalist>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary">Telefone / WhatsApp</label>
              <input type="text" name="telefone_convidado" class="form-control rounded-3" placeholder="(00) 00000-0000" required>
            </div>
          </div>
          <hr class="my-3 text-secondary opacity-25">
          <div class="mb-2">
            <label class="form-label small fw-semibold text-secondary d-block mb-1">Acompanhantes</label>
            <div id="acomp-add-lista" class="d-flex flex-column gap-2 mb-2"></div>
            <button type="button" id="btn-add-acomp-add" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
              <i class="bi bi-person-plus-fill me-1"></i> Adicionar acompanhante
            </button>
            <p class="text-muted mb-0 mt-1" style="font-size:.7rem;">Informe adulto, criança ou criança de colo para cada um — ajuda a assessoria a fechar a contagem do buffet.</p>
          </div>
          <p class="text-muted mb-0" style="font-size:.72rem;"><i class="bi bi-info-circle me-1"></i>O convite entra como "Pendente" — o titular e os acompanhantes confirmam presença por conta própria pelo link.</p>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-info btn-sm px-4 rounded-pill fw-semibold">Criar Convite</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Editar Convidado -->
<div class="modal fade" id="modalEditConvidado" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header border-0 pb-0">
        <h6 class="modal-title fw-bold"><i class="bi bi-pencil-fill text-primary me-2"></i>Editar Convidado</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="editar_convidado" value="1">
        <input type="hidden" name="convidado_id" id="ec-id">
        <div class="modal-body py-3">
          <div class="mb-3">
            <label class="form-label small fw-semibold text-secondary">Nome do Convidado / Família (Titular)</label>
            <input type="text" name="nome_convidado" id="ec-nome" class="form-control rounded-3" required>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary">Categoria / Grupo</label>
              <input type="text" name="categoria_convidado" id="ec-categoria" class="form-control rounded-3" list="lista-categorias-convidados" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary">Telefone / WhatsApp</label>
              <input type="text" name="telefone_convidado" id="ec-telefone" class="form-control rounded-3" placeholder="(00) 00000-0000" required>
            </div>
          </div>
          <hr class="my-3 text-secondary opacity-25">
          <div class="mb-2">
            <label class="form-label small fw-semibold text-secondary d-block mb-1">Acompanhantes</label>
            <div id="acomp-edit-lista" class="d-flex flex-column gap-2 mb-2"></div>
            <button type="button" id="btn-add-acomp-edit" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
              <i class="bi bi-person-plus-fill me-1"></i> Adicionar acompanhante
            </button>
          </div>
          <hr class="my-3 text-secondary opacity-25">
          <div>
            <label class="form-label small fw-semibold text-secondary d-block">Status de Confirmação</label>
            <div class="btn-group w-100" role="group">
              <input type="radio" class="btn-check" name="status_convidado" id="ec-status-pendente" value="pendente">
              <label class="btn btn-outline-warning btn-sm" for="ec-status-pendente"><i class="bi bi-hourglass-split"></i> Pendente</label>

              <input type="radio" class="btn-check" name="status_convidado" id="ec-status-confirmado" value="confirmado">
              <label class="btn btn-outline-success btn-sm" for="ec-status-confirmado"><i class="bi bi-check-circle"></i> Confirmado</label>
            </div>
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-semibold">Salvar Alterações</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;

document.querySelectorAll('.toast').forEach(t => new bootstrap.Toast(t).show());

/* ---- Repetidor de acompanhantes (nome + faixa etária) ---- */
function linhaAcompanhanteHtml(prefixo, dados) {
  dados = dados || {};
  const comId = prefixo === 'edit';
  return '' +
    '<div class="row g-2 align-items-center acomp-' + prefixo + '-linha">' +
      (comId ? '<input type="hidden" name="id_acompanhante_edit[]" value="' + (dados.id || '') + '">' : '') +
      '<div class="col-7">' +
        '<input type="text" name="nome_acompanhante_' + (comId ? 'edit' : 'novo') + '[]" class="form-control form-control-sm campo-nome-acomp" placeholder="Nome do acompanhante" value="' + escapeHtmlConv(dados.nome || '') + '">' +
      '</div>' +
      '<div class="col-4">' +
        '<select name="faixa_acompanhante_' + (comId ? 'edit' : 'novo') + '[]" class="form-select form-select-sm campo-faixa-acomp">' +
          '<option value="Adulto (11+ anos)">Adulto</option>' +
          '<option value="Criança (6-10 anos)">Criança (6-10)</option>' +
          '<option value="Criança de Colo (0-5 anos)">Criança de colo</option>' +
        '</select>' +
      '</div>' +
      '<div class="col-1 text-end">' +
        '<button type="button" class="btn btn-outline-danger btn-sm w-100 btn-remover-acomp" title="Remover"><i class="bi bi-x-lg"></i></button>' +
      '</div>' +
    '</div>';
}

function escapeHtmlConv(str) {
  const d = document.createElement('div');
  d.textContent = str || '';
  return d.innerHTML;
}

function adicionarLinhaAcompanhante(containerId, prefixo, dados) {
  const container = document.getElementById(containerId);
  container.insertAdjacentHTML('beforeend', linhaAcompanhanteHtml(prefixo, dados));
  if (dados && dados.faixa) {
    const linhas = container.querySelectorAll('.campo-faixa-acomp');
    linhas[linhas.length - 1].value = dados.faixa;
  }
}

document.getElementById('btn-add-acomp-add')?.addEventListener('click', () => {
  adicionarLinhaAcompanhante('acomp-add-lista', 'add');
});
document.getElementById('btn-add-acomp-edit')?.addEventListener('click', () => {
  adicionarLinhaAcompanhante('acomp-edit-lista', 'edit');
});

document.getElementById('acomp-add-lista')?.addEventListener('click', e => {
  const btn = e.target.closest('.btn-remover-acomp');
  if (btn) btn.closest('.acomp-add-linha').remove();
});
document.getElementById('acomp-edit-lista')?.addEventListener('click', e => {
  const btn = e.target.closest('.btn-remover-acomp');
  if (btn) btn.closest('.acomp-edit-linha').remove();
});

// Limpa os acompanhantes do modal de adicionar sempre que ele é reaberto
document.getElementById('modalAddConvidado')?.addEventListener('show.bs.modal', () => {
  document.getElementById('acomp-add-lista').innerHTML = '';
});

/* ---- Editar: popular o modal a partir dos data-* do botão ---- */
document.querySelectorAll('.btn-edit-convidado').forEach(btn => {
  btn.addEventListener('click', function () {
    document.getElementById('ec-id').value        = this.dataset.id;
    document.getElementById('ec-nome').value       = this.dataset.nome;
    document.getElementById('ec-categoria').value  = this.dataset.categoria;
    document.getElementById('ec-telefone').value   = this.dataset.telefone;
    document.getElementById(this.dataset.confirmado === '1' ? 'ec-status-confirmado' : 'ec-status-pendente').checked = true;

    const listaEdit = document.getElementById('acomp-edit-lista');
    listaEdit.innerHTML = '';
    let acompanhantes = [];
    try { acompanhantes = JSON.parse(this.dataset.acompanhantesJson || '[]'); } catch (e) {}
    acompanhantes.forEach(a => adicionarLinhaAcompanhante('acomp-edit-lista', 'edit', a));
  });
});

/* ---- Botão de WhatsApp: garante o link específico e abre o wa.me ---- */
document.querySelectorAll('.btn-whatsapp-convidado').forEach(btn => {
  btn.addEventListener('click', async function () {
    if (this.disabled) return;
    const orig = this.innerHTML;
    this.disabled = true;
    this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    try {
      const fd = new FormData();
      fd.append('obter_link_whatsapp', '1');
      fd.append('convidado_id', this.dataset.id);
      fd.append('csrf_token', CSRF_TOKEN);
      const r = await fetch(window.location.href, { method: 'POST', body: fd }).then(res => res.json());

      if (!r.ok) {
        alert(r.msg || 'Não foi possível gerar o link.');
      } else {
        const msg = encodeURIComponent('Oi ' + r.nome + '! Confirme sua presença no nosso casamento por aqui: ' + r.link);
        window.open('https://wa.me/' + r.telefone_digits + '?text=' + msg, '_blank');
      }
    } catch {
      alert('Erro de conexão.');
    }

    this.disabled = false;
    this.innerHTML = orig;
  });
});

/* ---- Busca + filtro por status ---- */
const busca = document.getElementById('busca-convidado');
let filtroAtivo = 'todos';

function aplicarFiltro() {
  const termo = busca.value.toLowerCase().trim();
  let algumVisivel = false;
  document.querySelectorAll('#lista-convidados .conv-row').forEach(card => {
    const matchNome   = !termo || card.dataset.nome.includes(termo);
    const matchStatus = filtroAtivo === 'todos' || card.dataset.status === filtroAtivo;
    const visivel = matchNome && matchStatus;
    card.style.display = visivel ? '' : 'none';
    if (visivel) algumVisivel = true;
  });
  document.getElementById('msg-lista-vazia-filtro').classList.toggle('d-none', algumVisivel);
}

busca?.addEventListener('input', aplicarFiltro);

document.querySelectorAll('#filtros-wrap .btn').forEach(btn => {
  btn.addEventListener('click', function () {
    filtroAtivo = this.dataset.f;
    document.querySelectorAll('#filtros-wrap .btn').forEach(b => {
      const f = b.dataset.f;
      const ativo = b === this;
      const cores = { todos: 'primary', confirmado: 'success', pendente: 'warning', recusado: 'secondary' };
      b.className = 'btn btn-sm rounded-pill ' + (ativo ? 'btn-' + cores[f] + ' active' : 'btn-outline-' + cores[f]);
      b.style.cssText = 'font-size:.7rem;padding:.3rem .7rem;';
    });
    aplicarFiltro();
  });
});
</script>

</body>
</html>
