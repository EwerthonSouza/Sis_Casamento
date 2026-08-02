<?php
session_start();

require_once 'conexao.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($_SESSION['usuario_tipo']) || !in_array($_SESSION['usuario_tipo'], ['admin', 'assistente'])) {
    header("Location: index.php");
    exit;
}
$is_admin = ($_SESSION['usuario_tipo'] === 'admin');

$evento_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$evento_id) {
    header("Location: painel_admin.php");
    exit;
}

/* ============================================================
   SEÇÕES SOLICITADAS
   ============================================================ */
$secoes_disponiveis = ['checklist', 'convidados', 'fornecedores', 'notas', 'musicas'];
$secoes_param = trim($_GET['secoes'] ?? 'todos');

if ($secoes_param === '' || $secoes_param === 'todos') {
    $secoes = $secoes_disponiveis;
} else {
    $secoes = array_values(array_intersect($secoes_disponiveis, explode(',', $secoes_param)));
    if (empty($secoes)) { $secoes = $secoes_disponiveis; }
}

// Assistente nunca visualiza dados financeiros/fornecedores
if (!$is_admin) {
    $secoes = array_diff($secoes, ['fornecedores']);
}
$mostrar = array_flip($secoes);

/* ============================================================
   CARREGAMENTO DE DADOS
   ============================================================ */
$s = $pdo->prepare("SELECT e.*, c.nome, c.email, c.telefone, c.cpf FROM eventos e INNER JOIN clientes c ON e.cliente_id = c.id WHERE e.id = ?");
$s->execute([$evento_id]);
$evento = $s->fetch();
if (!$evento) { die("Evento não encontrado."); }

$lista_checklist = [];
$passos = []; $prog = [];
$total_g = 0; $conc_g = 0;
if (isset($mostrar['checklist'])) {
    $rs = $pdo->prepare("SELECT * FROM checklist WHERE evento_id = ? ORDER BY id ASC");
    $rs->execute([$evento_id]);
    $lista_checklist = $rs->fetchAll();
    foreach ($lista_checklist as $t) {
        $e = $t['etapa'];
        $passos[$e][] = $t;
        if (!isset($prog[$e])) $prog[$e] = ['total' => 0, 'conc' => 0];
        $prog[$e]['total']++;
        $total_g++;
        $done = ($t['status'] === 'concluido' || $t['checado'] == 1);
        if ($done) { $prog[$e]['conc']++; $conc_g++; }
    }
}
$pct_g = $total_g > 0 ? round($conc_g / $total_g * 100) : 0;

$conv_grupos = [];
$total_conf = 0; $total_pend = 0;
if (isset($mostrar['convidados'])) {
    $rs2 = $pdo->prepare("SELECT * FROM convidados WHERE evento_id = ? ORDER BY nome ASC");
    $rs2->execute([$evento_id]);
    $lista_conv = $rs2->fetchAll();
    foreach ($lista_conv as $c) {
        $c['confirmado'] ? $total_conf++ : $total_pend++;
        $cat = trim($c['categoria'] ?? '');
        if ($cat === '') { $cat = 'Outros'; } else { $cat = mb_convert_case($cat, MB_CASE_TITLE, "UTF-8"); }
        if (!isset($conv_grupos[$cat])) { $conv_grupos[$cat] = []; }
        $conv_grupos[$cat][] = $c;
    }
    ksort($conv_grupos);
}

$lista_forn = [];
$total_forn = 0.0; $total_forn_pago = 0.0; $total_forn_restante = 0.0;
if (isset($mostrar['fornecedores'])) {
    $rs = $pdo->prepare("SELECT * FROM fornecedores_evento WHERE evento_id = ? AND status != 'Cancelado' ORDER BY status ASC, servico ASC");
    $rs->execute([$evento_id]);
    $lista_forn = $rs->fetchAll();
    foreach ($lista_forn as $f) {
        $vt = (float)$f['valor'];
        $vp = (float)($f['valor_pago'] ?? 0);
        $total_forn += $vt;
        if ($f['status'] === 'Contratado') {
            $total_forn_pago += $vp;
            $total_forn_restante += ($vt - $vp);
        }
    }
    $total_forn_restante = max(0.0, $total_forn_restante);
}

$lista_notas = [];
if (isset($mostrar['notas'])) {
    try {
        $rs = $pdo->prepare("SELECT * FROM notas_evento WHERE evento_id = ? ORDER BY criado_em DESC");
        $rs->execute([$evento_id]);
        $lista_notas = $rs->fetchAll();
    } catch (Exception $e) { $lista_notas = []; }
}

$lista_musicas = [];
if (isset($mostrar['musicas'])) {
    try {
        $rs = $pdo->prepare("SELECT * FROM musicas_evento WHERE evento_id = ? ORDER BY momento ASC, id ASC");
        $rs->execute([$evento_id]);
        $lista_musicas = $rs->fetchAll();
    } catch (Exception $e) { $lista_musicas = []; }
}

/* ============================================================
   MONTAGEM DO HTML DO RELATÓRIO
   ============================================================ */
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* Retorna [classe_css, texto] do prazo de uma tarefa para o relatório */
function prazo_info(?string $data_prazo, bool $done): array {
    if (empty($data_prazo)) return ['pend', '—'];
    $txt = date('d/m/Y', strtotime($data_prazo));
    if ($done) return ['ok', $txt];
    if ($data_prazo < date('Y-m-d')) return ['atrasada', $txt . ' (atrasada)'];
    return ['pend', $txt];
}

$gerado_em = date('d/m/Y H:i');
$data_evento_fmt = !empty($evento['data_evento']) ? date('d/m/Y', strtotime($evento['data_evento'])) : '—';

ob_start();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<style>
  * { box-sizing: border-box; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1e293b; }
  h1 { font-size: 20px; color: #7a1308; margin: 0 0 4px; }
  h2 { font-size: 14px; color: #7a1308; border-bottom: 2px solid #7a1308; padding-bottom: 4px; margin: 18px 0 10px; }
  h3 { font-size: 12px; color: #334155; margin: 10px 0 4px; }
  .sub { color: #64748b; font-size: 10px; margin-bottom: 14px; }
  .cabecalho { border-bottom: 3px solid #7a1308; padding-bottom: 10px; margin-bottom: 12px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
  th, td { border: 1px solid #e2e8f0; padding: 5px 7px; text-align: left; vertical-align: top; }
  th { background: #f1f5f9; font-size: 10px; text-transform: uppercase; color: #475569; }
  .badge { display: inline-block; padding: 2px 7px; border-radius: 10px; font-size: 9px; font-weight: bold; }
  .ok   { background: #dcfce7; color: #16a34a; }
  .pend { background: #fef3c7; color: #b45309; }
  .atrasada { background: #fee2e2; color: #dc2626; }
  .info-grid { width: 100%; margin-bottom: 6px; }
  .info-grid td { border: none; padding: 2px 0; font-size: 11px; }
  .info-grid .lbl { color: #64748b; width: 140px; font-weight: bold; }
  .footer-pg { position: fixed; bottom: -20px; left: 0; right: 0; font-size: 9px; color: #94a3b8; text-align: center; }
  .vazio { color: #94a3b8; font-style: italic; font-size: 10px; }
  .etapa-title { background: #f8fafc; padding: 4px 8px; font-weight: bold; font-size: 11px; border-left: 4px solid #7a1308; margin-top: 8px; }
  .marca { font-size: 10px; font-weight: bold; letter-spacing: 1px; color: #7a1308; text-transform: uppercase; margin-bottom: 6px; }
</style>
</head>
<body>

<div class="cabecalho">
  <div class="marca">Enlace</div>
  <h1>Casamento de <?= h($evento['nome']) ?></h1>
  <div class="sub">Relatório gerado em <?= h($gerado_em) ?></div>

  <table class="info-grid">
    <tr><td class="lbl">Data do evento</td><td><?= h($data_evento_fmt) ?><?= !empty($evento['hora_evento']) ? ' às ' . h(date('H:i', strtotime($evento['hora_evento']))) : '' ?></td></tr>
    <tr><td class="lbl">E-mail</td><td><?= h($evento['email']) ?></td></tr>
    <?php if (!empty($evento['telefone'])): ?><tr><td class="lbl">Telefone</td><td><?= h($evento['telefone']) ?></td></tr><?php endif; ?>
    <?php if (!empty($evento['local_cerimonia'])): ?><tr><td class="lbl">Local da cerimônia</td><td><?= h($evento['local_cerimonia']) ?></td></tr><?php endif; ?>
    <?php if (!empty($evento['tem_festa']) && !empty($evento['local_festa'])): ?><tr><td class="lbl">Local da recepção</td><td><?= h($evento['local_festa']) ?></td></tr><?php endif; ?>
  </table>
</div>

<?php if (isset($mostrar['checklist'])): ?>
<h2>Cronograma / Checklist <?= $total_g > 0 ? "({$conc_g}/{$total_g} concluídas — {$pct_g}%)" : '' ?></h2>
<?php if (empty($passos)): ?>
  <p class="vazio">Nenhuma tarefa cadastrada.</p>
<?php else: foreach ($passos as $etapa => $tarefas):
  $label = is_numeric($etapa) ? 'Passo ' . str_pad((string)$etapa, 2, '0', STR_PAD_LEFT) : $etapa; ?>
  <div class="etapa-title"><?= h($label) ?> (<?= $prog[$etapa]['conc'] ?>/<?= $prog[$etapa]['total'] ?>)</div>
  <table>
    <thead><tr><th style="width:28%">Tarefa</th><th style="width:37%">Descrição</th><th style="width:17%">Prazo</th><th style="width:18%">Status</th></tr></thead>
    <tbody>
    <?php foreach ($tarefas as $t):
      $done = ($t['status'] === 'concluido' || $t['checado'] == 1);
      [$pcls, $ptxt] = prazo_info($t['data_prazo'] ?? null, $done);
    ?>
      <tr>
        <td><?= h($t['tarefa']) ?></td>
        <td><?= h($t['descricao'] ?? '') ?></td>
        <td><span class="badge <?= $pcls ?>"><?= h($ptxt) ?></span></td>
        <td><span class="badge <?= $done ? 'ok' : 'pend' ?>"><?= $done ? 'Concluída' : 'Pendente' ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endforeach; endif; ?>
<?php endif; ?>

<?php if (isset($mostrar['convidados'])): ?>
<h2>Convidados <?= "({$total_conf} confirmados, {$total_pend} pendentes)" ?></h2>
<?php if (empty($conv_grupos)): ?>
  <p class="vazio">Nenhum convidado cadastrado.</p>
<?php else: foreach ($conv_grupos as $grp => $lista): ?>
  <h3><?= h($grp) ?> (<?= count($lista) ?>)</h3>
  <table>
    <thead><tr><th style="width:28%">Nome</th><th style="width:18%">Telefone</th><th style="width:12%">Status</th><th style="width:42%">Acompanhantes / Filhos</th></tr></thead>
    <tbody>
    <?php foreach ($lista as $c):
      $extras = [];
      if (($c['acompanhantes'] ?? 0) > 0) { $extras[] = 'Acomp (' . (int)$c['acompanhantes'] . '): ' . ($c['nomes_acompanhantes'] ?: '—'); }
      if (($c['filhos'] ?? 0) > 0) { $extras[] = 'Filhos (' . (int)$c['filhos'] . '): ' . ($c['idades_filhos'] ?: '—'); }
    ?>
      <tr>
        <td><?= h($c['nome']) ?></td>
        <td><?= h($c['telefone'] ?? '') ?></td>
        <td><span class="badge <?= $c['confirmado'] ? 'ok' : 'pend' ?>"><?= $c['confirmado'] ? 'Confirmado' : 'Pendente' ?></span></td>
        <td><?= h(implode(' · ', $extras)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endforeach; endif; ?>
<?php endif; ?>

<?php if (isset($mostrar['fornecedores'])): ?>
<h2>Fornecedores / Financeiro</h2>
<?php if (empty($lista_forn)): ?>
  <p class="vazio">Nenhum fornecedor cadastrado.</p>
<?php else: ?>
  <table class="info-grid" style="margin-bottom:8px;">
    <tr><td class="lbl">Total contratado</td><td>R$ <?= number_format($total_forn, 2, ',', '.') ?></td></tr>
    <tr><td class="lbl">Total pago</td><td>R$ <?= number_format($total_forn_pago, 2, ',', '.') ?></td></tr>
    <tr><td class="lbl">Restante a pagar</td><td>R$ <?= number_format($total_forn_restante, 2, ',', '.') ?></td></tr>
  </table>
  <table>
    <thead><tr><th style="width:25%">Serviço</th><th style="width:20%">Nome / Empresa</th><th style="width:15%">Status</th><th style="width:13%">Valor</th><th style="width:13%">Pago</th><th style="width:14%">Restante</th></tr></thead>
    <tbody>
    <?php foreach ($lista_forn as $f):
      $vt = (float)$f['valor']; $vp = (float)($f['valor_pago'] ?? 0); $vr = max(0.0, $vt - $vp);
    ?>
      <tr>
        <td><?= h($f['servico']) ?></td>
        <td><?= h($f['nome']) ?></td>
        <td><?= h($f['status']) ?></td>
        <td>R$ <?= number_format($vt, 2, ',', '.') ?></td>
        <td>R$ <?= number_format($vp, 2, ',', '.') ?></td>
        <td>R$ <?= number_format($vr, 2, ',', '.') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<?php endif; ?>

<?php if (isset($mostrar['notas'])): ?>
<h2>Bloco de Notas</h2>
<?php if (empty($lista_notas)): ?>
  <p class="vazio">Nenhuma nota cadastrada.</p>
<?php else: foreach ($lista_notas as $n): ?>
  <h3><?= h($n['titulo']) ?></h3>
  <?php if (!empty($n['conteudo'])): ?><p style="margin:0 0 8px;"><?= nl2br(h($n['conteudo'])) ?></p><?php endif; ?>
<?php endforeach; endif; ?>
<?php endif; ?>

<?php if (isset($mostrar['musicas'])): ?>
<h2>Playlist do Evento</h2>
<?php if (empty($lista_musicas)): ?>
  <p class="vazio">Nenhuma música cadastrada.</p>
<?php else: ?>
  <table>
    <thead><tr><th style="width:20%">Momento</th><th style="width:30%">Título</th><th style="width:25%">Artista</th><th style="width:25%">Status</th></tr></thead>
    <tbody>
    <?php foreach ($lista_musicas as $m): $conf = $m['status'] === 'confirmada'; ?>
      <tr>
        <td><?= h($m['momento']) ?></td>
        <td><?= h($m['titulo']) ?></td>
        <td><?= h($m['artista'] ?? '') ?></td>
        <td><span class="badge <?= $conf ? 'ok' : 'pend' ?>"><?= $conf ? 'Confirmada' : 'Sugestão' ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<?php endif; ?>

<div class="footer-pg">Enlace &middot; Relatório de <?= h($evento['nome']) ?></div>

</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$nome_arquivo = 'relatorio_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $evento['nome']) . '.pdf';
$dompdf->stream($nome_arquivo, ['Attachment' => false]);
