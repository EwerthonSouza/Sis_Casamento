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
if (!schema_ja_verificado('convite_evento_v2')) {
    $schema_checks_evento = [
        "SELECT cor_convite FROM eventos LIMIT 1"       => "ALTER TABLE eventos ADD COLUMN cor_convite VARCHAR(7) NULL",
        "SELECT foto_casal FROM eventos LIMIT 1"        => "ALTER TABLE eventos ADD COLUMN foto_casal VARCHAR(255) NULL",
        "SELECT foto_casal_ativa FROM eventos LIMIT 1"  => "ALTER TABLE eventos ADD COLUMN foto_casal_ativa TINYINT(1) NOT NULL DEFAULT 0",
        "SELECT mensagem_convite FROM eventos LIMIT 1"  => "ALTER TABLE eventos ADD COLUMN mensagem_convite VARCHAR(500) NULL",
        "SELECT cor_btn_sim FROM eventos LIMIT 1"       => "ALTER TABLE eventos ADD COLUMN cor_btn_sim VARCHAR(7) NULL",
        "SELECT cor_btn_nao FROM eventos LIMIT 1"       => "ALTER TABLE eventos ADD COLUMN cor_btn_nao VARCHAR(7) NULL",
    ];
    foreach ($schema_checks_evento as $check => $alter) {
        try { $pdo->query($check); } catch (Exception $e) { try { $pdo->exec($alter); } catch (Exception $x) {} }
    }
    marcar_schema_verificado('convite_evento_v2');
}

// Link público de confirmação de presença (usado pelo botão de WhatsApp).
// Checa também X-Forwarded-Proto: atrás de proxy/load balancer (comum em
// hospedagem compartilhada), $_SERVER['HTTPS'] não reflete o protocolo real
// usado pelo navegador — sem isso o link saía "http://" numa página https,
// e o iframe da prévia (mixed content) travava silenciosamente carregando.
$https_ativo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
$link_confirmacao_scheme = $https_ativo ? 'https://' : 'http://';
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
        // Número salvo é só DDD+telefone (10/11 dígitos); sem o código do país o
        // WhatsApp interpreta o DDD como início de um código de outro país.
        if (strlen($tel_digits) <= 11) {
            $tel_digits = '55' . $tel_digits;
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

    // 4. Alternar manualmente o status de confirmação de um acompanhante — a
    // assessoria pode marcar presença direto, sem depender do link do convidado.
    if (isset($_POST['alternar_status_acompanhante'])) {
        $cid = (int)($_POST['convidado_id'] ?? 0);
        $confirmar = ($_POST['confirmar'] ?? '0') === '1';
        $pdo->prepare("UPDATE convidados SET confirmado = ?, resposta_rsvp = ? WHERE id = ? AND evento_id = ? AND convidado_principal_id IS NOT NULL")
            ->execute([$confirmar ? 1 : 0, $confirmar ? 'confirmado' : null, $cid, $evento_id]);
        header("Location: $url_pagina"); exit;
    }

    // 5. Personalizar convite: ativar/desativar e enviar a foto do casal
    if (isset($_POST['salvar_foto_casal'])) {
        $ativa = ($_POST['foto_ativa'] ?? '0') === '1';

        if (!$ativa) {
            $pdo->prepare("UPDATE eventos SET foto_casal_ativa = 0 WHERE id = ?")->execute([$evento_id]);
            json_out(['ok' => true, 'ativa' => 0]);
        }

        $stF = $pdo->prepare("SELECT foto_casal FROM eventos WHERE id = ?");
        $stF->execute([$evento_id]);
        $foto_atual = $stF->fetchColumn();

        $tem_arquivo_novo = isset($_FILES['foto_casal_arquivo']) && $_FILES['foto_casal_arquivo']['error'] === UPLOAD_ERR_OK;

        if ($tem_arquivo_novo) {
            $tmpPath   = $_FILES['foto_casal_arquivo']['tmp_name'];
            $ext       = strtolower(pathinfo($_FILES['foto_casal_arquivo']['name'], PATHINFO_EXTENSION));
            $permitido = ['jpg', 'jpeg', 'png', 'webp'];

            if (!in_array($ext, $permitido) || @getimagesize($tmpPath) === false) {
                json_out(['ok' => false, 'msg' => 'Envie uma imagem JPG, PNG ou WEBP válida.']);
            }

            $novo_nome = 'casal_' . $evento_id . '_' . time() . '.' . $ext;
            if (!move_uploaded_file($tmpPath, './uploads/' . $novo_nome)) {
                json_out(['ok' => false, 'msg' => 'Falha ao salvar o arquivo no servidor.']);
            }
            if (!empty($foto_atual)) {
                $antigo = './uploads/' . $foto_atual;
                if (is_file($antigo)) @unlink($antigo);
            }
            $pdo->prepare("UPDATE eventos SET foto_casal = ?, foto_casal_ativa = 1 WHERE id = ?")->execute([$novo_nome, $evento_id]);
            json_out(['ok' => true, 'ativa' => 1, 'foto_url' => 'uploads/' . $novo_nome]);
        }

        if (!empty($foto_atual)) {
            $pdo->prepare("UPDATE eventos SET foto_casal_ativa = 1 WHERE id = ?")->execute([$evento_id]);
            json_out(['ok' => true, 'ativa' => 1, 'foto_url' => 'uploads/' . $foto_atual]);
        }

        json_out(['ok' => false, 'msg' => 'Anexe uma foto para ativar essa opção.']);
    }

    // 6. Remover a foto do casal do convite
    if (isset($_POST['remover_foto_casal'])) {
        $stF = $pdo->prepare("SELECT foto_casal FROM eventos WHERE id = ?");
        $stF->execute([$evento_id]);
        $foto_atual = $stF->fetchColumn();
        if (!empty($foto_atual)) {
            $arq = './uploads/' . $foto_atual;
            if (is_file($arq)) @unlink($arq);
        }
        $pdo->prepare("UPDATE eventos SET foto_casal = NULL, foto_casal_ativa = 0 WHERE id = ?")->execute([$evento_id]);
        json_out(['ok' => true]);
    }

    // 7. Salvar a cor de fundo da página do convite (cor vazia = volta ao padrão)
    if (isset($_POST['salvar_cor_convite'])) {
        $cor = trim($_POST['cor'] ?? '');
        if ($cor !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $cor)) {
            json_out(['ok' => false, 'msg' => 'Cor inválida.']);
        }
        $pdo->prepare("UPDATE eventos SET cor_convite = ? WHERE id = ?")->execute([$cor !== '' ? $cor : null, $evento_id]);
        json_out(['ok' => true]);
    }

    // 8. Salvar o texto de boas-vindas exibido no topo do convite
    if (isset($_POST['salvar_mensagem_convite'])) {
        $msg = trim($_POST['mensagem'] ?? '');
        if (mb_strlen($msg) > 300) {
            json_out(['ok' => false, 'msg' => 'Mensagem muito longa (máx. 300 caracteres).']);
        }
        $pdo->prepare("UPDATE eventos SET mensagem_convite = ? WHERE id = ?")->execute([$msg !== '' ? $msg : null, $evento_id]);
        json_out(['ok' => true, 'mensagem' => $msg]);
    }

    // 9. Salvar a cor dos botões "Sim" / "Não" do convite (vazio = volta ao padrão)
    if (isset($_POST['salvar_cores_botoes_convite'])) {
        $corSim = trim($_POST['cor_sim'] ?? '');
        $corNao = trim($_POST['cor_nao'] ?? '');
        if (($corSim !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $corSim)) || ($corNao !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $corNao))) {
            json_out(['ok' => false, 'msg' => 'Cor inválida.']);
        }
        $pdo->prepare("UPDATE eventos SET cor_btn_sim = ?, cor_btn_nao = ? WHERE id = ?")
            ->execute([$corSim !== '' ? $corSim : null, $corNao !== '' ? $corNao : null, $evento_id]);
        json_out(['ok' => true]);
    }
}

/* ============================================================
   CARREGAMENTO DE DADOS
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT e.data_evento, e.cor_convite, e.foto_casal, e.foto_casal_ativa, e.mensagem_convite, e.cor_btn_sim, e.cor_btn_nao, c.nome
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
$total_pessoas = count($todos_convidados);
$pct_conf = $total_pessoas > 0 ? round($total_conf / $total_pessoas * 100) : 0;
$pct_recusado = $total_pessoas > 0 ? round($total_recusado / $total_pessoas * 100) : 0;
$pct_pend = max(0, 100 - $pct_conf - $pct_recusado);

// Contagem por faixa etária pra fechar com o buffet — só de quem já confirmou
// presença (é isso que a assessoria de fato precisa encomendar).
$fx_adultos = 0; $fx_criancas = 0; $fx_colo = 0;
foreach ($todos_convidados as $p) {
    if (!$p['confirmado'] || $p['resposta_rsvp'] === 'recusado') continue;
    $fx = $p['faixa_etaria'] ?? '';
    if (str_starts_with($fx, 'Criança de Colo')) $fx_colo++;
    elseif (str_starts_with($fx, 'Criança')) $fx_criancas++;
    else $fx_adultos++;
}

$categorias_existentes = array_values(array_unique(array_filter(array_map(fn($c) => trim($c['categoria']), $lista_convidados))));
sort($categorias_existentes);

$msg_ok  = $_SESSION['msg_sucesso'] ?? '';
$msg_err = $_SESSION['msg_erro'] ?? '';
unset($_SESSION['msg_sucesso'], $_SESSION['msg_erro']);

$cor_convite_atual  = !empty($evento['cor_convite'])  ? $evento['cor_convite']  : '#8b5e3c';
$cor_btn_sim_atual  = !empty($evento['cor_btn_sim'])  ? $evento['cor_btn_sim']  : '#16a34a';
$cor_btn_nao_atual  = !empty($evento['cor_btn_nao'])  ? $evento['cor_btn_nao']  : '#64748b';

// Seções do modal "Personalizar Convite" só abrem já expandidas se o evento
// já tiver essa personalização salva; senão começam fechadas, como a foto.
$tem_texto_convite  = !empty($evento['mensagem_convite']);
$tem_cor_convite    = !empty($evento['cor_convite']);
$tem_botoes_convite = !empty($evento['cor_btn_sim']) || !empty($evento['cor_btn_nao']);
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

    .hdr {
      background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
      border-radius: var(--radius);
    }
    .hdr-avatar {
      width: 46px; height: 46px; border-radius: 50%; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      background: rgba(255,255,255,.16); border: 2px solid rgba(255,255,255,.35);
      color: #fff; font-size: 1.25rem;
    }
    @media (min-width: 768px) { .hdr-avatar { width: 54px; height: 54px; font-size: 1.4rem; } }

    /* No mobile os botões de ação ficam em 2 colunas, ocupando a largura
       toda (no desktop continuam numa linha só, ao lado do título). */
    @media (max-width: 767.98px) {
      .hdr-actions-row { width: 100%; flex-wrap: wrap !important; }
      .hdr-actions-row .btn {
        flex: 1 1 calc(50% - .5rem); min-width: 0; white-space: normal; line-height: 1.2;
        font-size: .72rem; padding: .5rem .35rem;
      }
    }

    .stat-card {
      background: #fff; border: 1px solid rgba(15,23,42,.06); border-left: 4px solid transparent;
      border-radius: var(--radius); padding: .9rem 1rem; height: 100%;
      display: flex; align-items: center; gap: .75rem;
      transition: box-shadow .15s, transform .15s;
    }
    .stat-card:hover { box-shadow: 0 .5rem 1.2rem rgba(15,23,42,.08); transform: translateY(-2px); }
    .stat-card[data-filtro] { cursor: pointer; }
    .stat-card .stat-icon {
      width: 40px; height: 40px; border-radius: 12px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
    }
    .stat-card .val { font-size: 1.45rem; font-weight: 700; line-height: 1; }
    .stat-card .lbl { font-size: .65rem; color: #94a3b8; text-transform: uppercase; letter-spacing: .05em; margin-top: .25rem; }
    .stat-card .stat-chevron { margin-left: auto; color: #cbd5e1; font-size: .95rem; flex-shrink: 0; }

    /* Card do buffet ocupa a linha inteira só até o breakpoint lg (onde a
       grade é row-cols-2); do lg pra cima ele segue o row-cols-lg-5 normal,
       na mesma linha dos outros 4 cards. */
    @media (max-width: 991.98px) {
      .stat-buffet-col { width: 100% !important; }
    }

    .stat-card.stat-total       { border-left-color: var(--color-primary); }
    .stat-card.stat-total       .stat-icon { background: var(--color-primary-light); color: var(--color-primary-dark); }
    .stat-card.stat-confirmado  { border-left-color: #10b981; }
    .stat-card.stat-confirmado  .stat-icon { background: #ecfdf5; color: #047857; }
    .stat-card.stat-pendente    { border-left-color: #f59e0b; }
    .stat-card.stat-pendente    .stat-icon { background: #fffbeb; color: #b45309; }
    .stat-card.stat-recusado    { border-left-color: #94a3b8; }
    .stat-card.stat-recusado    .stat-icon { background: #f8fafc; color: #64748b; }
    .stat-card.stat-buffet      { border-left-color: #8b5cf6; }
    .stat-card.stat-buffet      .stat-icon { background: #f5f3ff; color: #7c3aed; }

    .rsvp-progress { height: 8px; border-radius: 20px; overflow: hidden; background: #f1f5f9; display: flex; }
    .rsvp-progress > span { height: 100%; transition: width .4s ease; }
    .rsvp-progress .seg-confirmado { background: #10b981; }
    .rsvp-progress .seg-pendente   { background: #f59e0b; }
    .rsvp-progress .seg-recusado   { background: #94a3b8; }

    .sw { position: relative; }
    .sw .bi-search { position: absolute; left: .9rem; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: .85rem; }
    .sw input { padding-left: 2.2rem; }

    .guest-card { border-left: 4px solid transparent !important; transition: box-shadow .18s, transform .18s; }
    .conv-row.confirmado .guest-card { border-left-color: #10b981 !important; }
    .conv-row.pendente   .guest-card { border-left-color: #f59e0b !important; }
    .conv-row.recusado   .guest-card { border-left-color: #94a3b8 !important; }
    .guest-card:hover { box-shadow: 0 .6rem 1.4rem rgba(15,23,42,.09) !important; transform: translateY(-3px); }

    .status-pill {
      display: inline-flex; align-items: center; gap: .32rem; font-size: .62rem; font-weight: 700;
      padding: .22rem .55rem; border-radius: 20px; white-space: nowrap; letter-spacing: .01em;
    }
    .status-pill .status-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
    .status-pill.status-confirmado { background: #ecfdf5; color: #047857; }
    .status-pill.status-confirmado .status-dot { background: #10b981; }
    .status-pill.status-pendente { background: #fffbeb; color: #b45309; }
    .status-pill.status-pendente .status-dot { background: #f59e0b; }
    .status-pill.status-recusado { background: #f8fafc; color: #64748b; }
    .status-pill.status-recusado .status-dot { background: #94a3b8; }
    button.status-pill { border: 0; cursor: pointer; font-family: inherit; }
    button.status-pill:hover { filter: brightness(.95); }

    /* Grid dos cards de família: cada card fica do tamanho do próprio conteúdo
       (não estica pra acompanhar o vizinho mais alto na mesma "linha") — os
       cards da linha seguinte encaixam logo abaixo de onde sobrou espaço,
       tipo mural/masonry, em vez de ficarem em fileiras retas. No mobile
       fica 1 coluna só, um card embaixo do outro. */
    .family-grid { column-count: 1; column-gap: 1rem; }
    .family-grid > .conv-row {
      display: inline-block; width: 100%; margin-bottom: 1rem;
      break-inside: avoid; -webkit-column-break-inside: avoid;
    }
    @media (min-width: 768px)  { .family-grid { column-count: 2; } }
    @media (min-width: 1400px) { .family-grid { column-count: 3; } }

    /* Card de família: titular + acompanhantes empilhados ao lado, no mesmo card */
    .family-card { display: flex; flex-direction: row; align-items: stretch; overflow: hidden; }
    .family-titular { min-width: 0; }
    .family-acomp-col {
      width: 188px; flex-shrink: 0; background: #fbfbfd; border-left: 1px solid #eef1f5;
      display: flex; flex-direction: column; overflow-y: auto; scrollbar-width: thin;
    }
    .family-acomp-col::-webkit-scrollbar { width: 5px; }
    .family-acomp-col::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    .family-acomp-label {
      position: sticky; top: 0; z-index: 1; flex-shrink: 0;
      font-size: .58rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
      color: #94a3b8; background: #fbfbfd; padding: .5rem .6rem .3rem; border-bottom: 1px solid #eef1f5;
    }
    .family-acomp-item {
      display: flex; align-items: center; justify-content: space-between; gap: .4rem;
      padding: .45rem .6rem; border-bottom: 1px solid #eef1f5; transition: background .12s;
    }
    .family-acomp-item:last-child { border-bottom: 0; }
    .family-acomp-item:hover { background: #f1f5f9; }
    .status-pill-mini { font-size: .56rem; padding: .12rem .42rem; gap: .26rem; }
    .status-pill-mini .status-dot { width: 5px; height: 5px; }

    @media (max-width: 575.98px) {
      .family-card { flex-direction: column; }
      .family-acomp-col { width: 100%; border-left: 0; border-top: 1px solid #eef1f5; max-height: 210px; }
    }

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

    @media (min-width: 768px) {
      .btn-icon-conv { width: 32px; height: 32px; font-size: .95rem; }
    }

    /* O glifo do ícone (Bootstrap Icons) é desenhado no ::before, não no <i>
       em si — aplicar o gradiente/clip só no elemento pai não pinta nada
       nesse pseudo-elemento em vários navegadores mobile (fica invisível).
       Por isso o efeito vai direto no ::before, com -webkit-text-fill-color
       (necessário no Safari/WebView do celular além do color:transparent). */
    .icone-arco-iris { color: transparent; }
    .icone-arco-iris::before {
      background: conic-gradient(#ef4444, #f59e0b, #eab308, #22c55e, #06b6d4, #6366f1, #ec4899, #ef4444);
      -webkit-background-clip: text; background-clip: text;
      -webkit-text-fill-color: transparent; color: transparent;
    }

    /* ---- Modal Link Geral ---- */
    .link-geral-intro {
      background: linear-gradient(135deg, var(--color-primary-light) 0%, #fff 100%);
      border: 1px solid rgba(169,116,79,.18);
    }
    .link-geral-intro-icon {
      width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
      color: #fff; font-size: 1.2rem;
      box-shadow: 0 3px 8px rgba(169,116,79,.35);
    }
    .link-geral-passo {
      width: 26px; height: 26px; border-radius: 50%; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      background: var(--color-primary-light); color: var(--color-primary-dark);
      font-size: .78rem; font-weight: 800;
    }

    .btn-chama-atencao { animation: pulso-convite 2.2s ease-in-out infinite; }
    .btn-chama-atencao:hover { animation-play-state: paused; }
    @keyframes pulso-convite {
      0%, 100% { box-shadow: 0 0 0 0 rgba(255,255,255,.55); }
      50%      { box-shadow: 0 0 0 7px rgba(255,255,255,0); }
    }
    .swatch-cor {
      width: clamp(24px, 7vw, 32px); height: clamp(24px, 7vw, 32px);
      border-radius: 50%; border: 2px solid #fff;
      box-shadow: 0 0 0 1px #e2e8f0; cursor: pointer; padding: 0; flex-shrink: 0;
      transition: transform .12s, box-shadow .12s;
    }
    .swatch-cor:hover { transform: scale(1.08); }
    .swatch-cor.selecionada { box-shadow: 0 0 0 1px #fff, 0 0 0 3px #0f172a; }
    .swatch-custom {
      display: flex; align-items: center; justify-content: center;
      background: conic-gradient(red, yellow, lime, cyan, blue, magenta, red);
      color: #fff; font-size: .75rem; text-shadow: 0 1px 2px rgba(0,0,0,.4);
      position: relative; overflow: hidden;
    }
    .swatch-custom input[type="color"] {
      position: absolute; inset: 0; width: 100%; height: 100%;
      opacity: 0; cursor: pointer; border: none; padding: 0;
    }
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

  <div class="hdr p-4 mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
      <div class="d-flex align-items-center gap-3">
        <span class="hdr-avatar"><i class="bi bi-people-fill"></i></span>
        <div style="min-width:0;">
          <h4 class="fw-bold text-white mb-1">Gerenciar Convidados</h4>
          <p class="text-white opacity-50 mb-0 small text-truncate">
            <?= htmlspecialchars($evento['nome']) ?> &bull; <?= date('d/m/Y', strtotime($evento['data_evento'])) ?>
          </p>
        </div>
      </div>
      <div class="d-flex flex-wrap gap-2 hdr-actions-row">
        <button class="btn btn-sm btn-outline-light rounded-pill fw-semibold px-3 btn-chama-atencao" data-bs-toggle="modal" data-bs-target="#modalPersonalizarConvite">
          <i class="bi bi-palette-fill me-1 icone-arco-iris"></i> Personalizar Convite
        </button>
        <button class="btn btn-sm btn-outline-light rounded-pill fw-semibold px-3" data-bs-toggle="modal" data-bs-target="#modalLinkGeral">
          <i class="bi bi-link-45deg me-1"></i> Link Geral
        </button>
        <a href="organizar_mesas.php<?= $eh_noivos ? '' : '?id=' . $evento_id ?>" class="btn btn-sm btn-outline-light rounded-pill fw-semibold px-3">
          <i class="bi bi-grid-3x3-gap-fill me-1"></i> Organizar Mesas
        </a>
        <button class="btn btn-sm btn-info rounded-pill text-dark fw-semibold shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#modalAddConvidado">
          <i class="bi bi-person-plus-fill me-1"></i> Criar Convite
        </button>
      </div>
    </div>
  </div>

  <div class="row row-cols-2 row-cols-lg-<?= $total_conf > 0 ? '5' : '4' ?> g-2 mb-2">
    <div class="col">
      <div class="stat-card stat-total" data-filtro="todos">
        <span class="stat-icon"><i class="bi bi-envelope-fill"></i></span>
        <div>
          <div class="val" id="cnt-total"><?= $total_conv ?></div>
          <div class="lbl">Convites</div>
          <div class="text-muted" style="font-size:.62rem;margin-top:.15rem;" id="cnt-pessoas"><?= $total_pessoas ?> convidado<?= $total_pessoas === 1 ? '' : 's' ?> ao todo</div>
        </div>
        <i class="bi bi-chevron-right stat-chevron"></i>
      </div>
    </div>
    <div class="col">
      <div class="stat-card stat-confirmado" data-filtro="confirmado">
        <span class="stat-icon"><i class="bi bi-check-circle-fill"></i></span>
        <div><div class="val" id="cnt-conf"><?= $total_conf ?></div><div class="lbl">Confirmados</div></div>
        <i class="bi bi-chevron-right stat-chevron"></i>
      </div>
    </div>
    <div class="col">
      <div class="stat-card stat-pendente" data-filtro="pendente">
        <span class="stat-icon"><i class="bi bi-hourglass-split"></i></span>
        <div><div class="val" id="cnt-pend"><?= $total_pend ?></div><div class="lbl">Pendentes</div></div>
        <i class="bi bi-chevron-right stat-chevron"></i>
      </div>
    </div>
    <div class="col">
      <div class="stat-card stat-recusado" data-filtro="recusado">
        <span class="stat-icon"><i class="bi bi-x-circle-fill"></i></span>
        <div><div class="val" id="cnt-recusado"><?= $total_recusado ?></div><div class="lbl">Recusaram</div></div>
        <i class="bi bi-chevron-right stat-chevron"></i>
      </div>
    </div>
    <?php if ($total_conf > 0): ?>
    <div class="col stat-buffet-col">
      <div class="stat-card stat-buffet">
        <span class="stat-icon"><i class="bi bi-clipboard-check-fill"></i></span>
        <div>
          <div class="val" style="font-size:.92rem;line-height:1.35;">
            <?= $fx_adultos ?> adulto<?= $fx_adultos === 1 ? '' : 's' ?><?php if ($fx_criancas > 0): ?> · <?= $fx_criancas ?> criança<?= $fx_criancas === 1 ? '' : 's' ?><?php endif; ?><?php if ($fx_colo > 0): ?> · <?= $fx_colo ?> colo<?php endif; ?>
          </div>
          <div class="lbl">Buffet (confirmados)</div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($total_pessoas > 0): ?>
  <div class="d-flex align-items-center justify-content-between mb-4" style="font-size:.68rem;">
    <div class="rsvp-progress flex-grow-1 me-3">
      <span class="seg-confirmado" style="width:<?= $pct_conf ?>%;" title="<?= $pct_conf ?>% confirmados"></span>
      <span class="seg-pendente" style="width:<?= $pct_pend ?>%;" title="<?= $pct_pend ?>% pendentes"></span>
      <span class="seg-recusado" style="width:<?= $pct_recusado ?>%;" title="<?= $pct_recusado ?>% recusaram"></span>
    </div>
    <span class="text-muted flex-shrink-0"><?= $pct_conf ?>% das respostas confirmadas</span>
  </div>
  <?php endif; ?>

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
      <div class="family-grid">
      <?php foreach ($lista_convidados as $c):
          $status = $c['resposta_rsvp'] === 'recusado' ? 'recusado' : ($c['confirmado'] ? 'confirmado' : 'pendente');
          $acompLink = $acompanhantes_por_principal[$c['id']] ?? [];
          $temTelefone = strlen(preg_replace('/\D+/', '', $c['telefone'] ?? '')) >= 10;
          $acompJson = htmlspecialchars(json_encode(array_map(fn($a) => ['id' => $a['id'], 'nome' => $a['nome'], 'faixa' => $a['faixa_etaria']], $acompLink)), ENT_QUOTES, 'UTF-8');
          $statusLabel = $status === 'confirmado' ? 'Confirmado' : ($status === 'recusado' ? 'Recusou' : 'Pendente');

          $acompMeta = array_map(function ($a) {
              $statusA = $a['resposta_rsvp'] === 'recusado' ? 'recusado' : ($a['confirmado'] ? 'confirmado' : 'pendente');
              $rotulo = str_starts_with($a['faixa_etaria'] ?? '', 'Criança de Colo') ? 'Criança de colo'
                      : (str_starts_with($a['faixa_etaria'] ?? '', 'Criança') ? 'Criança' : 'Adulto');
              return ['id' => $a['id'], 'nome' => $a['nome'], 'status' => $statusA, 'rotulo' => $rotulo];
          }, $acompLink);
          $statusesAttr = strtolower(implode(' ', array_unique(array_merge([$status], array_column($acompMeta, 'status')))));
          $namesAttr = strtolower(implode(' ', array_merge([$c['nome']], array_column($acompMeta, 'nome'))));
      ?>
      <!-- Card de família: titular + acompanhantes agrupados no mesmo card, um do
           lado do outro. Filtro/busca considera qualquer pessoa da família. -->
      <div class="conv-row <?= $status ?>" data-nome="<?= htmlspecialchars($namesAttr, ENT_QUOTES, 'UTF-8') ?>" data-statuses="<?= htmlspecialchars($statusesAttr, ENT_QUOTES, 'UTF-8') ?>">
        <div class="card border-0 shadow-sm guest-card family-card rounded-4">
          <div class="family-titular p-3 flex-grow-1 d-flex flex-column">
            <div class="d-flex justify-content-between align-items-start gap-1">
              <span class="fw-bold text-dark text-truncate" style="font-size:.85rem;" title="<?= htmlspecialchars($c['nome'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($c['nome'], ENT_QUOTES, 'UTF-8') ?></span>
              <span class="status-pill status-<?= $status ?> flex-shrink-0"><span class="status-dot"></span><?= $statusLabel ?></span>
            </div>
            <div class="d-flex align-items-center gap-1 flex-wrap mt-1">
              <span class="badge bg-light text-dark border" style="font-size:.6rem;"><?= htmlspecialchars($c['categoria'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <div class="d-flex align-items-center justify-content-between gap-2 mt-2 pt-2 border-top">
              <?php if (!empty($c['telefone'])): ?>
                <div class="text-muted text-truncate" style="font-size:.72rem;"><i class="bi bi-whatsapp me-1 text-success"></i><?= htmlspecialchars($c['telefone'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php else: ?>
                <div></div>
              <?php endif; ?>
              <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <button type="button" class="btn-icon-conv btn-whatsapp-conv btn-whatsapp-convidado" data-id="<?= $c['id'] ?>"
                        <?= $temTelefone ? '' : 'disabled' ?>
                        title="<?= $temTelefone ? 'Enviar link de confirmação por WhatsApp' : 'Cadastre um telefone válido para enviar o link' ?>">
                  <i class="bi bi-whatsapp"></i>
                </button>
                <button type="button" class="btn-icon-conv btn-copiar-link-convidado" data-id="<?= $c['id'] ?>"
                        title="Copiar link de confirmação (o mesmo do WhatsApp) para enviar por onde quiser">
                  <i class="bi bi-link-45deg"></i>
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

          <?php if ($acompMeta): ?>
          <div class="family-acomp-col rounded-end-4">
            <div class="family-acomp-label d-flex align-items-center justify-content-between">
              <span>Acompanhantes</span>
              <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill" style="font-size:.58rem;">
                <i class="bi bi-person-fill"></i> <?= count($acompMeta) ?>
              </span>
            </div>
            <?php foreach ($acompMeta as $am):
                $amLabel = $am['status'] === 'confirmado' ? 'Confirmado' : ($am['status'] === 'recusado' ? 'Recusou' : 'Pendente');
            ?>
            <div class="family-acomp-item">
              <span class="text-truncate fw-semibold min-w-0" style="font-size:.72rem;color:#334155;" title="<?= htmlspecialchars($am['nome'] . ' — ' . $am['rotulo'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($am['nome'], ENT_QUOTES, 'UTF-8') ?></span>
              <div class="d-flex align-items-center gap-1 flex-shrink-0">
                <form method="POST" class="m-0">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                  <input type="hidden" name="alternar_status_acompanhante" value="1">
                  <input type="hidden" name="convidado_id" value="<?= $am['id'] ?>">
                  <input type="hidden" name="confirmar" value="<?= $am['status'] === 'confirmado' ? '0' : '1' ?>">
                  <button type="submit" class="status-pill status-pill-mini status-<?= $am['status'] ?> btn-status-convidado"
                          title="<?= $am['status'] === 'confirmado' ? 'Clique para desmarcar a confirmação' : 'Clique para confirmar presença manualmente' ?>">
                    <span class="status-dot"></span><?= $amLabel ?>
                  </button>
                </form>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="text-center text-muted py-4 d-none" id="msg-lista-vazia-filtro">
        <i class="bi bi-search fs-2 d-block mb-2"></i> Nenhum convidado encontrado com esse filtro.
      </div>
    </div>
  </div>
</div>

<!-- Modal: Personalizar Convite (cor de fundo, foto do casal, texto de boas-vindas) -->
<div class="modal fade" id="modalPersonalizarConvite" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header border-0 pb-0">
        <h6 class="modal-title fw-bold"><i class="bi bi-palette-fill text-info me-2"></i>Personalizar Convite</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-3">
        <p class="text-muted mb-3" style="font-size:.78rem;">Essas opções mudam como a página de confirmação (o link que o convidado recebe) aparece pra ele.</p>

        <!-- Texto de boas-vindas -->
        <div class="card border-0 rounded-4 p-3 mb-3" style="background:#f8fafc;border:1.5px solid #e2e8f0 !important;">
          <div class="d-flex justify-content-between align-items-start gap-3">
            <div class="d-flex align-items-start gap-2">
              <div class="bg-white rounded-3 d-flex align-items-center justify-content-center shadow-sm flex-shrink-0" style="width:38px;height:38px;">
                <i class="bi bi-chat-heart-fill text-info"></i>
              </div>
              <div>
                <label class="form-check-label fw-bold small text-dark mb-0" for="switch-texto-convite">Texto de boas-vindas</label>
                <p class="text-muted mb-0" style="font-size:.76rem;line-height:1.4;">Substitui a frase padrão no topo do convite.</p>
              </div>
            </div>
            <div class="form-check form-switch mb-0 flex-shrink-0">
              <input class="form-check-input" type="checkbox" role="switch" id="switch-texto-convite"
                     style="width:2.6em;height:1.4em;" <?= $tem_texto_convite ? 'checked' : '' ?>>
            </div>
          </div>

          <div id="area-texto-convite" class="mt-3 pt-3 border-top" style="border-color:#e2e8f0 !important; <?= $tem_texto_convite ? '' : 'display:none;' ?>">
            <textarea id="input-mensagem-convite" class="form-control rounded-3" rows="3" maxlength="300"
                      placeholder="Ex: Com muito carinho, contamos com a sua presença nesse dia tão especial pra nós!"><?= htmlspecialchars($evento['mensagem_convite'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            <div class="d-flex justify-content-between align-items-center mt-2">
              <span class="text-muted" id="contador-mensagem-convite" style="font-size:.7rem;">0/300</span>
              <button type="button" id="btn-salvar-mensagem-convite" class="btn btn-info btn-sm rounded-pill px-3 fw-bold">
                <i class="bi bi-check-lg me-1"></i> Salvar Texto
              </button>
            </div>
          </div>
        </div>

        <!-- Foto do casal -->
        <div class="card border-0 rounded-4 p-3 mb-3" style="background:#fef2f2;border:1.5px solid #fecaca !important;">
          <div class="d-flex justify-content-between align-items-start gap-3">
            <div class="d-flex align-items-start gap-2">
              <div class="bg-white rounded-3 d-flex align-items-center justify-content-center shadow-sm flex-shrink-0" style="width:38px;height:38px;">
                <i class="bi bi-image-fill text-danger"></i>
              </div>
              <div>
                <label class="form-check-label fw-bold small text-dark mb-0" for="switch-foto-convite">Foto do casal no convite</label>
                <p class="text-muted mb-0" style="font-size:.76rem;line-height:1.4;">Quando ativada, a foto aparece no topo da página que o convidado vê ao abrir o link.</p>
              </div>
            </div>
            <div class="form-check form-switch mb-0 flex-shrink-0">
              <input class="form-check-input" type="checkbox" role="switch" id="switch-foto-convite"
                     style="width:2.6em;height:1.4em;" <?= !empty($evento['foto_casal_ativa']) ? 'checked' : '' ?>>
            </div>
          </div>

          <div id="area-foto-convite" class="mt-3 pt-3 border-top" style="border-color:#fecaca !important; <?= !empty($evento['foto_casal_ativa']) ? '' : 'display:none;' ?>">
            <div class="text-center mb-3">
              <img id="preview-foto-convite"
                   src="<?= !empty($evento['foto_casal']) ? 'uploads/' . htmlspecialchars($evento['foto_casal'], ENT_QUOTES, 'UTF-8') : '' ?>"
                   class="rounded-circle shadow-sm <?= empty($evento['foto_casal']) ? 'd-none' : '' ?>"
                   style="width:96px;height:96px;object-fit:cover;border:3px solid #fff;">
            </div>
            <label class="form-label small fw-semibold text-secondary mb-1">Escolher imagem</label>
            <input type="file" id="input-foto-convite" accept="image/png, image/jpeg, image/webp" class="form-control form-control-sm mb-3 bg-white">
            <div class="d-flex gap-2">
              <button type="button" id="btn-salvar-foto-convite" class="btn btn-danger btn-sm rounded-pill px-3 fw-bold flex-grow-1">
                <i class="bi bi-check-lg me-1"></i> Salvar Foto
              </button>
              <button type="button" id="btn-remover-foto-convite" class="btn btn-outline-secondary btn-sm rounded-pill px-3 <?= empty($evento['foto_casal']) ? 'd-none' : '' ?>">
                <i class="bi bi-trash me-1"></i> Remover
              </button>
            </div>
          </div>
        </div>

        <!-- Cor de fundo -->
        <div class="card border-0 rounded-4 p-3 mb-3" style="background:#f8fafc;border:1.5px solid #e2e8f0 !important;">
          <div class="d-flex justify-content-between align-items-start gap-3">
            <div class="d-flex align-items-start gap-2">
              <div class="bg-white rounded-3 d-flex align-items-center justify-content-center shadow-sm flex-shrink-0" style="width:38px;height:38px;">
                <i class="bi bi-palette-fill" style="color:<?= htmlspecialchars($cor_convite_atual, ENT_QUOTES, 'UTF-8') ?>;"></i>
              </div>
              <div>
                <label class="form-check-label fw-bold small text-dark mb-0" for="switch-cor-convite">Cor da página do convite</label>
                <p class="text-muted mb-0" style="font-size:.76rem;line-height:1.4;">Escolha o tom de fundo que os convidados vão ver ao abrir o link.</p>
              </div>
            </div>
            <div class="form-check form-switch mb-0 flex-shrink-0">
              <input class="form-check-input" type="checkbox" role="switch" id="switch-cor-convite"
                     style="width:2.6em;height:1.4em;" <?= $tem_cor_convite ? 'checked' : '' ?>>
            </div>
          </div>

          <div id="area-cor-convite" class="mt-3 pt-3 border-top" style="border-color:#e2e8f0 !important; <?= $tem_cor_convite ? '' : 'display:none;' ?>">
            <div class="d-flex gap-2 mb-3 flex-wrap" id="paleta-cor-convite">
              <button type="button" class="swatch-cor" data-cor="#8b5e3c" style="background:#8b5e3c;" title="Marrom (padrão)"></button>
              <button type="button" class="swatch-cor" data-cor="#7a1f2b" style="background:#7a1f2b;" title="Bordô"></button>
              <button type="button" class="swatch-cor" data-cor="#b76e79" style="background:#b76e79;" title="Rosé"></button>
              <button type="button" class="swatch-cor" data-cor="#4a5d43" style="background:#4a5d43;" title="Verde Oliva"></button>
              <button type="button" class="swatch-cor" data-cor="#25314c" style="background:#25314c;" title="Azul Marinho"></button>
              <button type="button" class="swatch-cor" data-cor="#a9812f" style="background:#a9812f;" title="Dourado"></button>
              <button type="button" class="swatch-cor" data-cor="#2b2b2b" style="background:#2b2b2b;" title="Preto Elegante"></button>
              <label class="swatch-cor swatch-custom" title="Cor personalizada">
                <i class="bi bi-eyedropper"></i>
                <input type="color" id="input-cor-personalizada" value="<?= htmlspecialchars($cor_convite_atual, ENT_QUOTES, 'UTF-8') ?>">
              </label>
            </div>

            <div class="d-flex align-items-center gap-2">
              <div id="preview-cor-convite" class="rounded-3 flex-grow-1" style="height:34px;"></div>
              <button type="button" id="btn-salvar-cor-convite" class="btn btn-dark btn-sm rounded-pill px-3 fw-bold flex-shrink-0">
                <i class="bi bi-check-lg me-1"></i> Salvar
              </button>
            </div>
          </div>
        </div>

        <!-- Cores dos botões de resposta -->
        <div class="card border-0 rounded-4 p-3 mb-3" style="background:#f8fafc;border:1.5px solid #e2e8f0 !important;">
          <div class="d-flex justify-content-between align-items-start gap-3">
            <div class="d-flex align-items-start gap-2">
              <div class="bg-white rounded-3 d-flex align-items-center justify-content-center shadow-sm flex-shrink-0" style="width:38px;height:38px;">
                <i class="bi bi-hand-thumbs-up-fill text-success"></i>
              </div>
              <div>
                <label class="form-check-label fw-bold small text-dark mb-0" for="switch-botoes-convite">Cores dos botões de resposta</label>
                <p class="text-muted mb-0" style="font-size:.76rem;line-height:1.4;">Escolha a cor dos botões "Sim" e "Não" que o convidado usa pra responder.</p>
              </div>
            </div>
            <div class="form-check form-switch mb-0 flex-shrink-0">
              <input class="form-check-input" type="checkbox" role="switch" id="switch-botoes-convite"
                     style="width:2.6em;height:1.4em;" <?= $tem_botoes_convite ? 'checked' : '' ?>>
            </div>
          </div>

          <div id="area-botoes-convite" class="mt-3 pt-3 border-top" style="border-color:#e2e8f0 !important; <?= $tem_botoes_convite ? '' : 'display:none;' ?>">
            <div class="row g-3">
              <div class="col-6">
                <label class="form-label small fw-semibold text-secondary mb-1">Botão "Sim"</label>
                <div class="d-flex align-items-center gap-2">
                  <label class="swatch-cor swatch-custom" style="width:34px;height:34px;" title="Cor personalizada">
                    <i class="bi bi-eyedropper" style="font-size:.75rem;"></i>
                    <input type="color" id="input-cor-btn-sim" value="<?= htmlspecialchars($cor_btn_sim_atual, ENT_QUOTES, 'UTF-8') ?>">
                  </label>
                  <div id="preview-btn-sim" class="rounded-pill flex-grow-1 text-center fw-bold text-white" style="height:34px;line-height:34px;font-size:.78rem;">Sim</div>
                </div>
              </div>
              <div class="col-6">
                <label class="form-label small fw-semibold text-secondary mb-1">Botão "Não"</label>
                <div class="d-flex align-items-center gap-2">
                  <label class="swatch-cor swatch-custom" style="width:34px;height:34px;" title="Cor personalizada">
                    <i class="bi bi-eyedropper" style="font-size:.75rem;"></i>
                    <input type="color" id="input-cor-btn-nao" value="<?= htmlspecialchars($cor_btn_nao_atual, ENT_QUOTES, 'UTF-8') ?>">
                  </label>
                  <div id="preview-btn-nao" class="rounded-pill flex-grow-1 text-center fw-bold bg-white" style="height:34px;line-height:34px;font-size:.78rem;border:1.5px solid #e2e8f0;">Não</div>
                </div>
              </div>
            </div>

            <div class="d-flex justify-content-end mt-3">
              <button type="button" id="btn-salvar-cores-botoes" class="btn btn-dark btn-sm rounded-pill px-3 fw-bold">
                <i class="bi bi-check-lg me-1"></i> Salvar
              </button>
            </div>
          </div>
        </div>

        <!-- Prévia ao vivo do convite -->
        <div class="card border-0 rounded-4 p-3 mt-3" style="background:#f8fafc;border:1.5px solid #e2e8f0 !important;">
          <div class="d-flex align-items-start justify-content-between gap-2 mb-3">
            <div class="d-flex align-items-start gap-2">
              <div class="bg-white rounded-3 d-flex align-items-center justify-content-center shadow-sm flex-shrink-0" style="width:38px;height:38px;">
                <i class="bi bi-eye-fill text-info"></i>
              </div>
              <div>
                <div class="fw-bold small text-dark">Prévia do convite</div>
                <p class="text-muted mb-0" style="font-size:.76rem;line-height:1.4;">Assim vai ficar a página que o convidado vê ao abrir o link.</p>
              </div>
            </div>
            <button type="button" id="btn-atualizar-preview-convite" class="btn btn-outline-secondary btn-sm rounded-pill flex-shrink-0" title="Atualizar prévia">
              <i class="bi bi-arrow-clockwise"></i>
            </button>
          </div>

          <div class="position-relative mx-auto" style="width:100%;max-width:380px;">
            <span class="badge bg-dark bg-opacity-75 position-absolute top-0 end-0 m-2" style="font-size:.6rem;z-index:1;">
              <i class="bi bi-eye-fill me-1"></i>Somente visual
            </span>
            <div id="preview-convite-box" class="rounded-3 border" style="height:520px;background:#fff;overflow-y:auto;overflow-x:hidden;">
              <div id="preview-convite-loading" class="text-muted text-center py-5" style="font-size:.75rem;">
                <span class="spinner-border spinner-border-sm mb-1"></span><br>Carregando prévia...
              </div>
              <iframe id="iframe-preview-convite" data-link="<?= htmlspecialchars($link_confirmacao_url, ENT_QUOTES, 'UTF-8') ?>" src="about:blank" class="d-none" style="width:100%;border:0;display:block;pointer-events:none;" tabindex="-1"></iframe>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Link Geral de Confirmação -->
<div class="modal fade" id="modalLinkGeral" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header border-0 pb-0">
        <h6 class="modal-title fw-bold"><i class="bi bi-link-45deg text-info me-2"></i>Link Geral de Confirmação</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pt-2">

        <div class="d-flex align-items-center gap-3 mb-4 p-3 rounded-4 link-geral-intro">
          <span class="link-geral-intro-icon"><i class="bi bi-broadcast"></i></span>
          <div class="small text-dark">
            Um único link pra <strong>todo mundo</strong> de uma vez — manda no grupo do WhatsApp e cada convidado confirma a própria presença.
          </div>
        </div>

        <div class="d-flex flex-column gap-3 mb-4">
          <div class="d-flex align-items-center gap-3">
            <span class="link-geral-passo">1</span>
            <div class="small text-secondary">Convidado abre o link e digita o <strong class="text-dark">próprio nome</strong></div>
          </div>
          <div class="d-flex align-items-center gap-3">
            <span class="link-geral-passo">2</span>
            <div class="small text-secondary">Sistema encontra ele na lista de convidados</div>
          </div>
          <div class="d-flex align-items-center gap-3">
            <span class="link-geral-passo">3</span>
            <div class="small text-secondary">Confirma a própria presença e, na sequência, a dos acompanhantes — igual ao link específico</div>
          </div>
        </div>

        <label class="form-label small fw-bold text-secondary mb-1">Link geral do evento</label>
        <div class="input-group input-group-sm shadow-sm">
          <span class="input-group-text bg-white text-muted"><i class="bi bi-link-45deg"></i></span>
          <input type="text" id="input-link-geral" class="form-control font-monospace" style="font-size:.78rem;" readonly value="<?= htmlspecialchars($link_confirmacao_url, ENT_QUOTES, 'UTF-8') ?>">
          <button class="btn btn-primary fw-semibold" type="button" id="btn-copiar-link-geral">
            <i class="bi bi-clipboard-fill me-1"></i> Copiar
          </button>
        </div>

        <div class="small text-muted mt-3 d-flex align-items-start gap-2">
          <i class="bi bi-info-circle-fill text-info mt-1"></i>
          <span>Funciona junto com os links específicos que você já gera por convidado — um não atrapalha o outro.</span>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <a href="<?= htmlspecialchars($link_confirmacao_url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm px-4 rounded-pill">
          <i class="bi bi-box-arrow-up-right me-1"></i> Testar link
        </a>
        <button type="button" class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Fechar</button>
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
          </div>
          <div class="d-flex align-items-start gap-2 rounded-3 p-2 mb-2" style="background:#f1f3f5;">
            <i class="bi bi-info-circle text-secondary mt-1"></i>
            <p class="text-muted mb-0" style="font-size:.72rem;">Informe adulto, criança ou criança de colo para cada um — ajuda a assessoria a fechar a contagem do buffet.</p>
          </div>
          <div class="d-flex align-items-start gap-2 rounded-3 p-2" style="background:#fff8e6;">
            <i class="bi bi-hourglass-split mt-1" style="color:#d97706;"></i>
            <p class="text-muted mb-0" style="font-size:.72rem;">O convite entra como <strong>"Pendente"</strong> — o titular e os acompanhantes confirmam presença por conta própria pelo link.</p>
          </div>
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
const NOME_CASAL = <?= json_encode($evento['nome']) ?>;

document.querySelectorAll('.toast').forEach(t => new bootstrap.Toast(t).show());

/* ---- Repetidor de acompanhantes (nome + faixa etária) ---- */
function linhaAcompanhanteHtml(prefixo, dados) {
  dados = dados || {};
  const comId = prefixo === 'edit';
  return '' +
    '<div class="row g-2 align-items-center acomp-' + prefixo + '-linha">' +
      (comId ? '<input type="hidden" name="id_acompanhante_edit[]" value="' + (dados.id || '') + '">' : '') +
      '<div class="col">' +
        '<input type="text" name="nome_acompanhante_' + (comId ? 'edit' : 'novo') + '[]" class="form-control form-control-sm campo-nome-acomp" placeholder="Nome do acompanhante" value="' + escapeHtmlConv(dados.nome || '') + '">' +
      '</div>' +
      '<div class="col-4">' +
        '<select name="faixa_acompanhante_' + (comId ? 'edit' : 'novo') + '[]" class="form-select form-select-sm campo-faixa-acomp">' +
          '<option value="Adulto (11+ anos)">Adulto</option>' +
          '<option value="Criança (6-10 anos)">Criança (6-10)</option>' +
          '<option value="Criança de Colo (0-5 anos)">Criança de colo</option>' +
        '</select>' +
      '</div>' +
      '<div class="col-auto">' +
        '<button type="button" class="btn btn-outline-danger btn-sm btn-remover-acomp d-flex align-items-center justify-content-center p-0" style="width:2rem;height:2rem;border-radius:50%;" title="Remover"><i class="bi bi-x-lg"></i></button>' +
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
        const msg = encodeURIComponent('Oi ' + r.nome + '! Confirme sua presença no casamento de ' + NOME_CASAL + ' por aqui: ' + r.link);
        window.open('https://wa.me/' + r.telefone_digits + '?text=' + msg, '_blank');
      }
    } catch {
      alert('Erro de conexão.');
    }

    this.disabled = false;
    this.innerHTML = orig;
  });
});

/* ---- Botão de copiar link: mesmo link/token do WhatsApp, pra mandar por onde quiser ---- */
document.querySelectorAll('.btn-copiar-link-convidado').forEach(btn => {
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
        try {
          await navigator.clipboard.writeText(r.link);
        } catch {
          const tmp = document.createElement('textarea');
          tmp.value = r.link;
          tmp.style.position = 'fixed';
          tmp.style.opacity = '0';
          document.body.appendChild(tmp);
          tmp.select();
          document.execCommand('copy');
          document.body.removeChild(tmp);
        }
        this.innerHTML = '<i class="bi bi-check-lg text-success"></i>';
        setTimeout(() => { this.innerHTML = orig; }, 1500);
        this.disabled = false;
        return;
      }
    } catch {
      alert('Erro de conexão.');
    }

    this.disabled = false;
    this.innerHTML = orig;
  });
});

/* ---- Botão de copiar o link geral (sem token, o mesmo pra todo mundo) ---- */
document.getElementById('btn-copiar-link-geral')?.addEventListener('click', async function () {
  const orig = this.innerHTML;
  const link = document.getElementById('input-link-geral').value;
  try {
    await navigator.clipboard.writeText(link);
  } catch {
    const tmp = document.createElement('textarea');
    tmp.value = link;
    tmp.style.position = 'fixed';
    tmp.style.opacity = '0';
    document.body.appendChild(tmp);
    tmp.select();
    document.execCommand('copy');
    document.body.removeChild(tmp);
  }
  this.innerHTML = '<i class="bi bi-check-lg"></i> Copiado!';
  setTimeout(() => { this.innerHTML = orig; }, 1500);
});

/* ---- Personalizar Convite: cor de fundo, foto do casal, texto de boas-vindas ---- */
function ajustarCorConvite(hex, percent) {
  hex = hex.replace('#', '');
  let r = parseInt(hex.substr(0, 2), 16), g = parseInt(hex.substr(2, 2), 16), b = parseInt(hex.substr(4, 2), 16);
  if (percent >= 0) {
    r = r + (255 - r) * percent; g = g + (255 - g) * percent; b = b + (255 - b) * percent;
  } else {
    r = r * (1 + percent); g = g * (1 + percent); b = b * (1 + percent);
  }
  const toHex = v => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0');
  return '#' + toHex(r) + toHex(g) + toHex(b);
}

async function postConvite(payload) {
  const fd = new FormData();
  fd.append('csrf_token', CSRF_TOKEN);
  Object.keys(payload).forEach(k => fd.append(k, payload[k]));
  return fetch(window.location.href, { method: 'POST', body: fd }).then(r => r.json());
}

(function initPersonalizarConvite() {
  // Prévia ao vivo (mesma página que o convidado vê, num iframe só-visual).
  // A caixa é quem rola (overflow-y:auto) — o iframe fica sem scroll próprio,
  // com a altura ajustada pro tamanho real do conteúdo dele.
  const btnAtualizarPre = document.getElementById('btn-atualizar-preview-convite');
  const iframePreview   = document.getElementById('iframe-preview-convite');
  const previewLoading  = document.getElementById('preview-convite-loading');
  const previewBox      = document.getElementById('preview-convite-box');

  function carregarPreviewConvite() {
    if (!iframePreview) return;
    iframePreview.classList.add('d-none');
    iframePreview.style.height = '';
    previewLoading?.classList.remove('d-none');
    previewBox && (previewBox.scrollTop = 0);
    iframePreview.src = iframePreview.dataset.link + '&_preview=1&_t=' + Date.now();
  }

  iframePreview?.addEventListener('load', () => {
    setTimeout(() => {
      let altura = 900;
      try {
        // "body > .container" é o bloco real do convite (card + rodapé); o
        // body em si tem min-height:100vh só pro fundo cobrir a tela toda,
        // o que inflaria a medição se usássemos o body direto.
        const doc = iframePreview.contentDocument;
        const bloco = doc.querySelector('body > .container') || doc.body;
        altura = bloco.scrollHeight || altura;
      } catch (e) {}
      iframePreview.style.height = altura + 'px';
      iframePreview.classList.remove('d-none');
      previewLoading?.classList.add('d-none');
    }, 120);
  });

  btnAtualizarPre?.addEventListener('click', carregarPreviewConvite);

  document.getElementById('modalPersonalizarConvite')?.addEventListener('shown.bs.modal', carregarPreviewConvite);

  // Texto de boas-vindas
  const textarea   = document.getElementById('input-mensagem-convite');
  const contador    = document.getElementById('contador-mensagem-convite');
  const btnSalvarMsg = document.getElementById('btn-salvar-mensagem-convite');

  function atualizarContador() {
    if (textarea && contador) contador.textContent = textarea.value.length + '/300';
  }
  textarea?.addEventListener('input', atualizarContador);
  atualizarContador();

  btnSalvarMsg?.addEventListener('click', async function () {
    const orig = this.innerHTML;
    this.disabled = true;
    this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    try {
      const r = await postConvite({ salvar_mensagem_convite: '1', mensagem: textarea.value });
      if (r.ok) {
        this.innerHTML = '<i class="bi bi-check-lg me-1"></i> Salvo!';
        carregarPreviewConvite();
      } else {
        alert(r.msg || 'Erro ao salvar o texto.');
        this.innerHTML = orig;
      }
    } catch {
      alert('Erro de conexão.');
      this.innerHTML = orig;
    }
    setTimeout(() => { this.innerHTML = orig; this.disabled = false; }, 1500);
  });

  // Foto do casal
  const switchFoto     = document.getElementById('switch-foto-convite');
  const areaFoto       = document.getElementById('area-foto-convite');
  const inputFoto      = document.getElementById('input-foto-convite');
  const previewFoto    = document.getElementById('preview-foto-convite');
  const btnSalvarFoto  = document.getElementById('btn-salvar-foto-convite');
  const btnRemoverFoto = document.getElementById('btn-remover-foto-convite');

  switchFoto?.addEventListener('change', () => {
    areaFoto.style.display = switchFoto.checked ? '' : 'none';
  });

  btnSalvarFoto?.addEventListener('click', async function () {
    const orig    = this.innerHTML;
    const arquivo = inputFoto.files[0];
    this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando...';
    this.disabled  = true;

    try {
      const fd = new FormData();
      fd.append('csrf_token', CSRF_TOKEN);
      fd.append('salvar_foto_casal', '1');
      fd.append('foto_ativa', switchFoto.checked ? '1' : '0');
      if (arquivo) fd.append('foto_casal_arquivo', arquivo);
      const r = await fetch(window.location.href, { method: 'POST', body: fd }).then(res => res.json());

      if (r.ok) {
        if (r.foto_url) {
          previewFoto.src = r.foto_url + '?t=' + Date.now();
          previewFoto.classList.remove('d-none');
          btnRemoverFoto.classList.remove('d-none');
          inputFoto.value = '';
        }
        carregarPreviewConvite();
      } else {
        alert(r.msg || 'Erro ao salvar a foto.');
      }
    } catch {
      alert('Erro de conexão.');
    }
    this.innerHTML = orig;
    this.disabled  = false;
  });

  btnRemoverFoto?.addEventListener('click', async function () {
    if (!confirm('Remover a foto do casal do convite?')) return;
    try {
      const r = await postConvite({ remover_foto_casal: '1' });
      if (r.ok) {
        previewFoto.classList.add('d-none');
        previewFoto.src = '';
        btnRemoverFoto.classList.add('d-none');
        switchFoto.checked = false;
        areaFoto.style.display = 'none';
        carregarPreviewConvite();
      } else {
        alert(r.msg || 'Erro ao remover a foto.');
      }
    } catch {
      alert('Erro de conexão.');
    }
  });

  // Cor de fundo
  const paleta       = document.getElementById('paleta-cor-convite');
  const inputCustom  = document.getElementById('input-cor-personalizada');
  const preview      = document.getElementById('preview-cor-convite');
  const btnSalvarCor = document.getElementById('btn-salvar-cor-convite');

  function atualizarPreviewCor(hex) {
    const c1 = ajustarCorConvite(hex, -0.22);
    const c3 = ajustarCorConvite(hex, 0.22);
    if (preview) preview.style.background = `linear-gradient(135deg, ${c1} 0%, ${hex} 50%, ${c3} 100%)`;
    paleta?.querySelectorAll('.swatch-cor[data-cor]').forEach(sw => {
      sw.classList.toggle('selecionada', sw.dataset.cor.toLowerCase() === hex.toLowerCase());
    });
  }

  paleta?.querySelectorAll('.swatch-cor[data-cor]').forEach(sw => {
    sw.addEventListener('click', () => {
      if (inputCustom) inputCustom.value = sw.dataset.cor;
      atualizarPreviewCor(sw.dataset.cor);
    });
  });

  inputCustom?.addEventListener('input', function () { atualizarPreviewCor(this.value); });
  atualizarPreviewCor(inputCustom?.value || '#8b5e3c');

  btnSalvarCor?.addEventListener('click', async function () {
    const orig = this.innerHTML;
    this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>...';
    this.disabled  = true;
    try {
      const r = await postConvite({ salvar_cor_convite: '1', cor: inputCustom.value });
      if (r.ok) carregarPreviewConvite();
      else alert(r.msg || 'Erro ao salvar a cor.');
    } catch {
      alert('Erro de conexão.');
    }
    this.innerHTML = orig;
    this.disabled  = false;
  });

  // Cores dos botões "Sim" / "Não"
  const inputCorSim     = document.getElementById('input-cor-btn-sim');
  const inputCorNao     = document.getElementById('input-cor-btn-nao');
  const previewBtnSim   = document.getElementById('preview-btn-sim');
  const previewBtnNao   = document.getElementById('preview-btn-nao');
  const btnSalvarBotoes = document.getElementById('btn-salvar-cores-botoes');

  function atualizarPreviewBtnSim(hex) {
    if (!previewBtnSim) return;
    previewBtnSim.style.background = `linear-gradient(135deg, ${ajustarCorConvite(hex, -0.22)}, ${hex})`;
  }
  function atualizarPreviewBtnNao(hex) {
    if (!previewBtnNao) return;
    previewBtnNao.style.color = hex;
    previewBtnNao.style.borderColor = hex;
  }

  inputCorSim?.addEventListener('input', function () { atualizarPreviewBtnSim(this.value); });
  inputCorNao?.addEventListener('input', function () { atualizarPreviewBtnNao(this.value); });
  atualizarPreviewBtnSim(inputCorSim?.value || '#16a34a');
  atualizarPreviewBtnNao(inputCorNao?.value || '#64748b');

  btnSalvarBotoes?.addEventListener('click', async function () {
    const orig = this.innerHTML;
    this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>...';
    this.disabled  = true;
    try {
      const r = await postConvite({
        salvar_cores_botoes_convite: '1',
        cor_sim: inputCorSim.value,
        cor_nao: inputCorNao.value,
      });
      if (r.ok) carregarPreviewConvite();
      else alert(r.msg || 'Erro ao salvar as cores.');
    } catch {
      alert('Erro de conexão.');
    }
    this.innerHTML = orig;
    this.disabled  = false;
  });

  // Ao desligar um desses switches, a área fecha E a personalização salva
  // daquela seção é apagada (volta pro padrão) — não fica só escondida.
  function fecharEResetarAoDesligar(idSwitch, idArea, aoDesligar) {
    const sw = document.getElementById(idSwitch);
    const area = document.getElementById(idArea);
    sw?.addEventListener('change', async () => {
      area.style.display = sw.checked ? '' : 'none';
      if (!sw.checked) await aoDesligar();
    });
  }

  fecharEResetarAoDesligar('switch-texto-convite', 'area-texto-convite', async () => {
    textarea.value = '';
    atualizarContador();
    const r = await postConvite({ salvar_mensagem_convite: '1', mensagem: '' });
    if (r.ok) carregarPreviewConvite();
  });

  fecharEResetarAoDesligar('switch-cor-convite', 'area-cor-convite', async () => {
    inputCustom.value = '#8b5e3c';
    atualizarPreviewCor('#8b5e3c');
    const r = await postConvite({ salvar_cor_convite: '1', cor: '' });
    if (r.ok) carregarPreviewConvite();
  });

  fecharEResetarAoDesligar('switch-botoes-convite', 'area-botoes-convite', async () => {
    inputCorSim.value = '#16a34a';
    inputCorNao.value = '#64748b';
    atualizarPreviewBtnSim('#16a34a');
    atualizarPreviewBtnNao('#64748b');
    const r = await postConvite({ salvar_cores_botoes_convite: '1', cor_sim: '', cor_nao: '' });
    if (r.ok) carregarPreviewConvite();
  });
})();

/* ---- Busca + filtro por status ---- */
const busca = document.getElementById('busca-convidado');
let filtroAtivo = 'todos';

function aplicarFiltro() {
  const termo = busca.value.toLowerCase().trim();
  let algumVisivel = false;
  document.querySelectorAll('#lista-convidados .conv-row').forEach(card => {
    const matchNome   = !termo || card.dataset.nome.includes(termo);
    const matchStatus = filtroAtivo === 'todos' || card.dataset.statuses.split(' ').includes(filtroAtivo);
    const visivel = matchNome && matchStatus;
    card.style.display = visivel ? '' : 'none';
    if (visivel) algumVisivel = true;
  });
  document.getElementById('msg-lista-vazia-filtro').classList.toggle('d-none', algumVisivel);
}

busca?.addEventListener('input', aplicarFiltro);

/* ---- Cards de estatística (Convites/Confirmados/Pendentes/Recusaram) são
   atalho pro mesmo filtro dos botões abaixo da busca — clica no card, aplica
   o filtro e rola até a lista. ---- */
document.querySelectorAll('.stat-card[data-filtro]').forEach(card => {
  card.addEventListener('click', function () {
    const btnFiltro = document.querySelector(`#filtros-wrap [data-f="${this.dataset.filtro}"]`);
    if (btnFiltro) btnFiltro.click();
    document.getElementById('busca-convidado')?.closest('.card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});

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
