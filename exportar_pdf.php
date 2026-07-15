<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require_once 'conexao.php';

// Validação de segurança
if (!isset($_SESSION['usuario_tipo']) || !in_array($_SESSION['usuario_tipo'], ['admin', 'assistente'])) {
    header("Location: index.php");
    exit;
}

$evento_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$evento_id) {
    die("ID do evento inválido.");
}

// Captura as seções que o usuário quer imprimir (Se vazio, assume todas por padrão)
$secoes = $_REQUEST['secoes'] ?? ['info', 'convidados', 'financeiro', 'tarefas', 'musicas'];

// Carrega as dependências do Composer (DomPDF)
require_once 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isRemoteEnabled', true); 
$options->set('defaultFont', 'Arial');
$dompdf = new Dompdf($options);

// ============================================================
// CARREGAMENTO DOS DADOS CONDICIONAL
// ============================================================

// Sempre carrega os dados básicos do evento para o cabeçalho
$s = $pdo->prepare("SELECT e.*, c.nome, c.email, c.telefone, c.cpf FROM eventos e INNER JOIN clientes c ON e.cliente_id = c.id WHERE e.id = ?");
$s->execute([$evento_id]);
$evento = $s->fetch();
if (!$evento) { die("Evento não encontrado."); }

// Fornecedores
if (in_array('financeiro', $secoes)) {
    $rs = $pdo->prepare("SELECT * FROM fornecedores_evento WHERE evento_id = ? AND status = 'Contratado' ORDER BY servico ASC");
    $rs->execute([$evento_id]);
    $lista_forn = $rs->fetchAll();
}

// Convidados
if (in_array('convidados', $secoes)) {
    $rs2 = $pdo->prepare("SELECT * FROM convidados WHERE evento_id = ? ORDER BY nome ASC");
    $rs2->execute([$evento_id]);
    $lista_conv = $rs2->fetchAll();
    $total_conf = 0; $total_pend = 0;
    foreach ($lista_conv as $c) {
        $c['confirmado'] ? $total_conf++ : $total_pend++;
    }
}

// Checklist
if (in_array('tarefas', $secoes)) {
    $rs3 = $pdo->prepare("
        SELECT c.* FROM checklist c
        JOIN (
            SELECT etapa, MIN(id) as primeiro_id 
            FROM checklist 
            WHERE evento_id = ? 
            GROUP BY etapa
        ) ordenacao ON c.etapa = ordenacao.etapa
        WHERE c.evento_id = ? 
        ORDER BY ordenacao.primeiro_id ASC, c.id ASC
    ");
    $rs3->execute([$evento_id, $evento_id]);
    $lista_checklist = $rs3->fetchAll();

    $total_g = 0; $conc_g = 0;
    foreach ($lista_checklist as $t) {
        $total_g++;
        $done = ($t['status'] === 'concluido' || $t['checado'] == 1);
        if ($done) { $conc_g++; }
    }
    $pct_g = $total_g > 0 ? round($conc_g / $total_g * 100) : 0;
}

// Músicas (Nova adição solicitada!)
if (in_array('musicas', $secoes)) {
    $rs_mus = $pdo->prepare("SELECT * FROM musicas_evento WHERE evento_id = ? ORDER BY momento ASC, id ASC");
    $rs_mus->execute([$evento_id]);
    $lista_musicas = $rs_mus->fetchAll();
}

// Configuração da logo
$caminho_logo = __DIR__ . '/css/logo.png'; 
$logo_base64 = '';
if (file_exists($caminho_logo)) {
    $logo_data = base64_encode(file_get_contents($caminho_logo));
    $logo_base64 = 'data:image/' . pathinfo($caminho_logo, PATHINFO_EXTENSION) . ';base64,' . $logo_data;
}

ob_start();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Relatório Personalizado</title>
    <style>
        @page { margin: 1.2cm; }
        body { font-family: 'Helvetica', sans-serif; color: #334155; font-size: 11px; line-height: 1.5; }
        .logo-container { text-align: center; margin-bottom: 15px; }
        .logo-container img { max-width: 140px; height: auto; }
        .header { text-align: center; margin-bottom: 25px; background: #f8fafc; padding: 18px; border-radius: 8px; border: 1px solid #e2e8f0; }
        .header h1 { margin: 0; color: #1e293b; font-size: 22px; text-transform: uppercase; letter-spacing: 0.5px; }
        .header p { margin: 5px 0 0 0; color: #64748b; font-size: 13px; font-weight: 500; }
        .section-title { font-size: 12px; font-weight: bold; color: #4f46e5; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; margin-top: 25px; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.3px; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 18px; }
        th { background-color: #4f46e5; color: #ffffff; padding: 9px 10px; font-size: 10px; text-transform: uppercase; font-weight: bold; }
        td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; font-size: 10px; color: #475569; }
        tr:nth-child(even) { background-color: #f8fafc; }
        .info-grid { width: 100%; margin-bottom: 15px; border: none; }
        .info-grid tr { background-color: transparent !important; }
        .info-grid td { border: none !important; padding: 5px 0; font-size: 11px; color: #334155; }
        .total-row { background-color: #eef2ff !important; font-weight: bold; }
        .total-row td { color: #1e1b4b; font-weight: bold; border-bottom: 2px solid #c7d2fe; }
        .badge { padding: 3px 8px; border-radius: 999px; font-size: 9px; font-weight: bold; display: inline-block; }
        .bg-success { background: #dcfce7; color: #166534; }
        .bg-warning { background: #fef3c7; color: #92400e; }
    </style>
</head>
<body>

    <?php if (!empty($logo_base64)): ?>
    <div class="logo-container">
        <img src="<?= $logo_base64 ?>" alt="Logo">
    </div>
    <?php endif; ?>

    <div class="header">
        <h1>Relatório de Planejamento</h1>
        <p>Noivos: <?= htmlspecialchars($evento['nome'], ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <?php if (in_array('info', $secoes)): ?>
    <div class="section-title">Informações do Evento</div>
    <table class="info-grid">
        <tr>
            <td style="width: 33%;"><strong>Data:</strong> <?= date('d/m/Y', strtotime($evento['data_evento'])) ?></td>
            <td style="width: 33%;"><strong>Horário:</strong> <?= !empty($evento['hora_evento']) ? date('H:i', strtotime($evento['hora_evento'])) : 'A definir' ?></td>
            <td style="width: 34%;"><strong>Contrato:</strong> #<?= str_pad($evento['id'], 4, '0', STR_PAD_LEFT) ?></td>
        </tr>
        <tr>
            <td colspan="3" style="padding-top: 8px;"><strong>Local da Cerimônia:</strong> <?= htmlspecialchars($evento['local_cerimonia'] ?? 'A definir', ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <?php if ($evento['tem_festa'] == 1): ?>
        <tr>
            <td colspan="3"><strong>Local da Recepção / Festa:</strong> <?= htmlspecialchars($evento['local_festa'] ?? 'A definir', ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
        <?php endif; ?>
    </table>
    <?php endif; ?>

    <?php if (in_array('convidados', $secoes)): ?>
    <div class="section-title">Controle de Convidados</div>
    <table>
        <thead>
            <tr>
                <th style="width: 33%;">Confirmados</th>
                <th style="width: 33%;">Pendentes</th>
                <th style="width: 34%;">Total Cadastrado</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?= $total_conf ?></td>
                <td><?= $total_pend ?></td>
                <td><strong><?= count($lista_conv) ?></strong> pessoas</td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (in_array('financeiro', $secoes)): ?>
    <div class="section-title">Planejamento Financeiro de Fornecedores</div>
    <table>
        <thead>
            <tr>
                <th style="width: 25%;">Serviço / Setor</th>
                <th style="width: 25%;">Fornecedor Contratado</th>
                <th style="width: 16%;">Valor Total</th>
                <th style="width: 16%;">Valor Pago</th>
                <th style="width: 18%;">Saldo Restante</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $t_total = 0; $t_pago = 0; $t_rest = 0;
            foreach ($lista_forn as $f): 
                $v_total = (float)$f['valor'];
                $v_pago = (float)($f['valor_pago'] ?? 0);
                $v_rest = max(0.0, $v_total - $v_pago);
                $t_total += $v_total; $t_pago += $v_pago; $t_rest += $v_rest;
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($f['servico'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                <td><?= htmlspecialchars($f['nome'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>R$ <?= number_format($v_total, 2, ',', '.') ?></td>
                <td>R$ <?= number_format($v_pago, 2, ',', '.') ?></td>
                <td>R$ <?= number_format($v_rest, 2, ',', '.') ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="2">RESUMO GERAL DOS CONTRATOS</td>
                <td>R$ <?= number_format($t_total, 2, ',', '.') ?></td>
                <td>R$ <?= number_format($t_pago, 2, ',', '.') ?></td>
                <td>R$ <?= number_format($t_rest, 2, ',', '.') ?></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (in_array('tarefas', $secoes)): ?>
    <div class="section-title">Cronograma de Tarefas (Progresso: <?= $pct_g ?>%)</div>
    <table>
        <thead>
            <tr>
                <th style="width: 30%;">Etapa</th>
                <th style="width: 55%;">Tarefa</th>
                <th style="width: 15%; text-align: center;">Situação</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lista_checklist as $t): 
                $done = ($t['status'] === 'concluido' || $t['checado'] == 1);
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($t['etapa'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                <td><?= htmlspecialchars($t['tarefa'], ENT_QUOTES, 'UTF-8') ?></td>
                <td style="text-align: center;">
                    <span class="badge <?= $done ? 'bg-success' : 'bg-warning' ?>">
                        <?= $done ? 'Concluído' : 'Pendente' ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (in_array('musicas', $secoes)): ?>
    <div class="section-title">Playlist / Músicas do Evento (Guia do Sonoplasta)</div>
    <table>
        <thead>
            <tr>
                <th style="width: 35%;">Momento da Celebração</th>
                <th style="width: 35%;">Título da Música</th>
                <th style="width: 20%;">Artista / Banda</th>
                <th style="width: 10%; text-align: center;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($lista_musicas)): ?>
            <tr>
                <td colspan="4" style="text-align: center; color: #64748b; font-style: italic;">Nenhuma música foi sugerida ou confirmada para este evento.</td>
            </tr>
            <?php else: ?>
                <?php foreach ($lista_musicas as $m): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($m['momento'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                    <td><?= htmlspecialchars($m['titulo'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= !empty($m['artista']) ? htmlspecialchars($m['artista'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
                    <td style="text-align: center;">
                        <span class="badge <?= $m['status'] === 'confirmada' ? 'bg-success' : 'bg-warning' ?>">
                            <?= $m['status'] === 'confirmada' ? 'OK' : 'Sugestão' ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php endif; ?>

</body>
</html>
<?php
$html = ob_get_clean();

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$dompdf->stream("resumo_casamento_" . $evento_id . ".pdf", ["Attachment" => false]);
exit;
?>