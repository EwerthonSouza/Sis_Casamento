<?php
session_start();
require_once 'sessao_timeout.inc.php';
verificar_sessao_ativa();
require_once 'conexao.php';
require_once 'modulos_evento.inc.php';
garantir_coluna_tipo_evento($pdo);

// ============================================================
// TRAVA DE SEGURANÇA: Admin, Assistente e Noivos acessam esta página
// ============================================================
if (!isset($_SESSION['usuario_tipo']) || !in_array($_SESSION['usuario_tipo'], ['admin', 'assistente', 'noivos', 'desenvolvedor'])) {
    header("Location: index.php?sessao_expirada=1");
    exit;
}

// Variável para esconder botões de pagamento do assistente (se necessário)
$is_admin  = in_array($_SESSION['usuario_tipo'], ['admin', 'desenvolvedor'], true);
$eh_noivos = ($_SESSION['usuario_tipo'] === 'noivos');

// Recebe o ID do evento: noivos só podem ver o próprio evento (ignora manipulação da URL)
if ($eh_noivos) {
    $evento_id = (int)($_SESSION['evento_id'] ?? 0);
    if (!$evento_id) { header("Location: index.php"); exit; }
} else {
    $evento_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$evento_id) {
        header("Location: painel_admin.php");
        exit;
    }
}

// Carrega os dados do evento
$stmt = $pdo->prepare("SELECT e.*, c.nome, c.email FROM eventos e INNER JOIN clientes c ON e.cliente_id = c.id WHERE e.id = ?");
$stmt->execute([$evento_id]);
$evento = $stmt->fetch();

if (!$evento) { die("Evento não encontrado."); }

garantir_tabela_modulos_config($pdo);
$cor_modulo = cor_painel_evento($pdo, $evento);

// Impede a equipe de acessar fornecedores de um evento de outro módulo
if (!$eh_noivos) {
    $modulo_ativo = $_SESSION['modulo_ativo'] ?? null;
    if (!$modulo_ativo || $evento['tipo_evento'] !== $modulo_ativo) {
        header("Location: painel_admin.php");
        exit;
    }
}

// Coluna de controle de pagamento parcial — pode não existir ainda se essa for
// a primeira página do sistema aberta neste evento (gerenciar.php também
// cria a mesma coluna; checagem idempotente, tanto faz qual roda primeiro).
if (!schema_ja_verificado('fornecedores_valor_pago')) {
    try { $pdo->query("SELECT valor_pago FROM fornecedores_evento LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE fornecedores_evento ADD COLUMN valor_pago DECIMAL(10,2) NOT NULL DEFAULT 0.00"); }
    marcar_schema_verificado('fornecedores_valor_pago');
}

// Categoria (classificação fixa pra ícone/filtro), data limite de pagamento e
// histórico individual de cada pagamento (data + valor) — antes só existia o
// total acumulado em valor_pago, sem registro de quando cada parcela foi paga.
if (!schema_ja_verificado('fornecedores_completo_v1')) {
    try { $pdo->query("SELECT categoria FROM fornecedores_evento LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE fornecedores_evento ADD COLUMN categoria VARCHAR(30) NOT NULL DEFAULT 'Outros'"); }
    try { $pdo->query("SELECT data_limite_pagamento FROM fornecedores_evento LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE fornecedores_evento ADD COLUMN data_limite_pagamento DATE NULL"); }
    try {
        $pdo->query("SELECT 1 FROM fornecedores_pagamentos LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("CREATE TABLE fornecedores_pagamentos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fornecedor_id INT NOT NULL,
            valor DECIMAL(10,2) NOT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_forn_pag (fornecedor_id),
            CONSTRAINT fk_forn_pag FOREIGN KEY (fornecedor_id) REFERENCES fornecedores_evento(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    marcar_schema_verificado('fornecedores_completo_v1');
}

// Comprovante de pagamento (imagem ou PDF) anexado a cada linha do histórico —
// mesmo padrão de validação/armazenamento dos documentos do evento em gerenciar.php.
if (!schema_ja_verificado('fornecedores_pagamentos_comprovante_v1')) {
    try { $pdo->query("SELECT comprovante_arquivo FROM fornecedores_pagamentos LIMIT 1"); }
    catch (Exception $e) {
        $pdo->exec("ALTER TABLE fornecedores_pagamentos ADD COLUMN comprovante_arquivo VARCHAR(255) NULL");
        $pdo->exec("ALTER TABLE fornecedores_pagamentos ADD COLUMN comprovante_nome_original VARCHAR(255) NULL");
        $pdo->exec("ALTER TABLE fornecedores_pagamentos ADD COLUMN comprovante_extensao VARCHAR(10) NULL");
    }
    marcar_schema_verificado('fornecedores_pagamentos_comprovante_v1');
}

// Categorias fixas com ícone próprio — usadas no formulário e na listagem.
const CATEGORIAS_FORNECEDOR = [
    'Buffet'              => 'bi-cup-hot-fill',
    'Decoração'           => 'bi-flower1',
    'Fotografia'          => 'bi-camera-fill',
    'Vídeo'               => 'bi-camera-reels-fill',
    'Música/DJ'           => 'bi-music-note-beamed',
    'Local/Espaço'        => 'bi-geo-alt-fill',
    'Cerimonial'          => 'bi-journal-check',
    'Doces/Bolo'          => 'bi-cake2-fill',
    'Convites/Papelaria'  => 'bi-envelope-paper-fill',
    'Transporte'          => 'bi-car-front-fill',
    'Outros'              => 'bi-shop',
];

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

// Recebe o comprovante de pagamento (opcional) de um campo de upload, valida
// o conteúdo de verdade (não só a extensão — um .txt renomeado pra .pdf não
// passa) e salva em uploads/. Mesma checagem usada nos documentos do evento
// em gerenciar.php. Retorna null quando nenhum arquivo foi enviado (campo
// opcional) ou um array com 'ok' => false e 'msg' quando o arquivo é inválido.
function processar_comprovante_pagamento(string $campo, int $evento_id): ?array {
    if (empty($_FILES[$campo]) || ($_FILES[$campo]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $arquivo = $_FILES[$campo];
    if ($arquivo['error'] === UPLOAD_ERR_INI_SIZE || $arquivo['error'] === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'msg' => 'Comprovante grande demais para o limite do servidor.'];
    }
    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'msg' => 'Não foi possível enviar o comprovante.'];
    }

    $extensoesImagem = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));

    $valido = false;
    if (in_array($extensao, $extensoesImagem, true)) {
        $valido = @getimagesize($arquivo['tmp_name']) !== false;
    } elseif ($extensao === 'pdf') {
        $handle = @fopen($arquivo['tmp_name'], 'rb');
        if ($handle) {
            $valido = fread($handle, 5) === '%PDF-';
            fclose($handle);
        }
    }
    if (!$valido) {
        return ['ok' => false, 'msg' => 'Comprovante em formato não suportado. Envie uma imagem (jpg, png, webp, gif) ou PDF.'];
    }

    $nomeArquivo = 'comprovante_' . $evento_id . '_' . time() . '_' . random_int(1000, 9999) . '.' . $extensao;
    if (!move_uploaded_file($arquivo['tmp_name'], './uploads/' . $nomeArquivo)) {
        return ['ok' => false, 'msg' => 'Não foi possível salvar o comprovante no servidor.'];
    }

    return [
        'ok'            => true,
        'arquivo'       => $nomeArquivo,
        'nome_original' => mb_substr($arquivo['name'], 0, 255),
        'extensao'      => $extensao,
    ];
}

// Data em que o pagamento realmente aconteceu (pode ser retroativa, quando o
// registro no sistema é feito depois do dia do pagamento de verdade) — se não
// vier uma data válida do formulário, usa hoje como padrão.
function data_pagamento_valida(string $data): string {
    if ($data !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
        $d = DateTime::createFromFormat('Y-m-d', $data);
        if ($d && $d->format('Y-m-d') === $data) {
            return $data . ' 00:00:00';
        }
    }
    return date('Y-m-d H:i:s');
}

// --- LÓGICA DE PROCESSAMENTO (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verificar_csrf();

    // ADICIONAR
    if (isset($_POST['adicionar_fornecedor'])) {
        $nome = trim($_POST['nome_fornecedor']);
        $servico = trim($_POST['servico_fornecedor']);
        $contato = trim($_POST['contato_fornecedor']);
        $status = trim($_POST['status_fornecedor']);
        $valor = !empty($_POST['valor_fornecedor']) ? (float)$_POST['valor_fornecedor'] : 0.00;
        $categoria = $_POST['categoria_fornecedor'] ?? 'Outros';
        if (!array_key_exists($categoria, CATEGORIAS_FORNECEDOR)) { $categoria = 'Outros'; }
        $data_limite = !empty($_POST['data_limite_fornecedor']) ? $_POST['data_limite_fornecedor'] : null;
        // Valor de entrada: opcional, já entra como o primeiro pagamento (nunca
        // maior que o valor total combinado, pra não deixar "pago" > "previsto").
        $valor_entrada = !empty($_POST['valor_entrada_fornecedor']) ? (float)$_POST['valor_entrada_fornecedor'] : 0.00;
        $valor_entrada = min(max(0.0, $valor_entrada), $valor);
        // Data em que a entrada foi paga de verdade (pode ser antes de hoje,
        // quando o registro no sistema é feito depois do pagamento real).
        $data_entrada = data_pagamento_valida($_POST['data_entrada_fornecedor'] ?? '');
        $comprovante = processar_comprovante_pagamento('comprovante_entrada_fornecedor', $evento_id);

        if ($comprovante !== null && !$comprovante['ok']) {
            $_SESSION['msg_erro'] = $comprovante['msg'];
        } elseif (!empty($nome) && !empty($servico)) {
            $pdo->prepare("INSERT INTO fornecedores_evento (evento_id, nome, servico, contato, status, valor, valor_pago, categoria, data_limite_pagamento) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$evento_id, $nome, $servico, $contato, $status, $valor, $valor_entrada, $categoria, $data_limite]);
            // A entrada já conta como o primeiro registro no histórico de pagamentos.
            if ($valor_entrada > 0) {
                $novo_id = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO fornecedores_pagamentos (fornecedor_id, valor, criado_em, comprovante_arquivo, comprovante_nome_original, comprovante_extensao) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$novo_id, $valor_entrada, $data_entrada, $comprovante['arquivo'] ?? null, $comprovante['nome_original'] ?? null, $comprovante['extensao'] ?? null]);
            }
            $_SESSION['msg_sucesso'] = "Fornecedor adicionado com sucesso!";
        } else {
            $_SESSION['msg_erro'] = "Preencha o nome e o serviço do fornecedor.";
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
        $categoria = $_POST['categoria_fornecedor_edit'] ?? 'Outros';
        if (!array_key_exists($categoria, CATEGORIAS_FORNECEDOR)) { $categoria = 'Outros'; }
        $data_limite = !empty($_POST['data_limite_fornecedor_edit']) ? $_POST['data_limite_fornecedor_edit'] : null;
        // Corrige o valor pago junto (ex: reduziu o valor total combinado com o
        // fornecedor) — sempre limitado a não ultrapassar o novo valor total.
        // Isso é uma CORREÇÃO manual, não entra no histórico de pagamentos
        // individuais (esse é só pra registrar_pagamento, abaixo).
        $valor_pago = !empty($_POST['valor_pago_fornecedor_edit']) ? (float)$_POST['valor_pago_fornecedor_edit'] : 0.00;
        $valor_pago = min(max(0.0, $valor_pago), $valor);

        if (!empty($nome) && !empty($servico)) {
            $pdo->prepare("UPDATE fornecedores_evento SET nome = ?, servico = ?, contato = ?, status = ?, valor = ?, valor_pago = ?, categoria = ?, data_limite_pagamento = ? WHERE id = ? AND evento_id = ?")
                ->execute([$nome, $servico, $contato, $status, $valor, $valor_pago, $categoria, $data_limite, $id_forn, $evento_id]);
            $_SESSION['msg_sucesso'] = "Fornecedor atualizado com sucesso!";
        } else {
            $_SESSION['msg_erro'] = "Preencha o nome e o serviço do fornecedor.";
        }
        header("Location: fornecedores_evento.php?id=" . $evento_id); exit;
    }

    // REGISTRAR PAGAMENTO: soma ao valor já pago (em vez de substituir) e grava
    // uma linha no histórico individual, pra manter registro de quando cada
    // parcela foi paga (não só o total acumulado).
    if (isset($_POST['registrar_pagamento'])) {
        $id_forn = (int)$_POST['id_fornecedor'];
        $valor_add = !empty($_POST['valor_pagamento']) ? (float)$_POST['valor_pagamento'] : 0.00;
        $data_pgto = data_pagamento_valida($_POST['data_pagamento'] ?? '');
        $comprovante = processar_comprovante_pagamento('comprovante_pagamento', $evento_id);

        if ($comprovante !== null && !$comprovante['ok']) {
            $_SESSION['msg_erro'] = $comprovante['msg'];
        } elseif ($id_forn > 0 && $valor_add > 0) {
            $chk = $pdo->prepare("SELECT valor, valor_pago FROM fornecedores_evento WHERE id = ? AND evento_id = ?");
            $chk->execute([$id_forn, $evento_id]);
            $forn = $chk->fetch();
            if ($forn) {
                $valor_add_real = min($valor_add, max(0.0, (float)$forn['valor'] - (float)$forn['valor_pago']));
                $novo_pago = (float)$forn['valor_pago'] + $valor_add_real;
                $pdo->prepare("UPDATE fornecedores_evento SET valor_pago = ? WHERE id = ? AND evento_id = ?")->execute([$novo_pago, $id_forn, $evento_id]);
                if ($valor_add_real > 0) {
                    $pdo->prepare("INSERT INTO fornecedores_pagamentos (fornecedor_id, valor, criado_em, comprovante_arquivo, comprovante_nome_original, comprovante_extensao) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$id_forn, $valor_add_real, $data_pgto, $comprovante['arquivo'] ?? null, $comprovante['nome_original'] ?? null, $comprovante['extensao'] ?? null]);
                }
                $_SESSION['msg_sucesso'] = "Pagamento registrado com sucesso!";
            } else {
                $_SESSION['msg_erro'] = "Fornecedor não encontrado.";
            }
        } else {
            $_SESSION['msg_erro'] = "Informe um valor de pagamento maior que zero.";
        }
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

// Histórico de pagamentos de todos os fornecedores deste evento, buscado em
// lote (1 consulta) em vez de 1 por fornecedor — agrupado por fornecedor_id.
$historico_pagamentos = [];
if (!empty($lista_fornecedores)) {
    $ids_forn = array_column($lista_fornecedores, 'id');
    $ph = implode(',', array_fill(0, count($ids_forn), '?'));
    $stmt_hist = $pdo->prepare("SELECT * FROM fornecedores_pagamentos WHERE fornecedor_id IN ($ph) ORDER BY criado_em DESC, id DESC");
    $stmt_hist->execute($ids_forn);
    foreach ($stmt_hist->fetchAll() as $p) {
        $historico_pagamentos[$p['fornecedor_id']][] = $p;
    }
}

// --- CÁLCULOS FINANCEIROS E CONTADORES ---
$total_fornecedores = 0;
$fornecedores_contratados = 0;
$valor_total = 0.0;
$valor_contratado = 0.0;
$valor_orcamento = 0.0;
$valor_pago_total = 0.0;

foreach ($lista_fornecedores as $f) {
    if ($f['status'] !== 'Cancelado') {
        $total_fornecedores++;
        $val = (float)$f['valor'];
        $valor_total += $val;
        $valor_pago_total += (float)($f['valor_pago'] ?? 0);

        if ($f['status'] == 'Contratado') {
            $fornecedores_contratados++;
            $valor_contratado += $val;
        } elseif ($f['status'] == 'Orçamento') {
            $valor_orcamento += $val;
        }
    }
}
$valor_restante_total = max(0.0, $valor_total - $valor_pago_total);
$pct_pago_total = $valor_total > 0 ? round($valor_pago_total / $valor_total * 100) : 0;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php include __DIR__ . '/pwa_head.inc.php'; ?>
    <title>Fornecedores do Evento - Meu Evento PRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/estilo.css?v=15">
    <?= estilo_tema_evento($cor_modulo) ?>
    <style>
        @media (max-width: 767.98px) {
            .stat-card-forn .card-body {
                flex-direction: column !important;
                text-align: center;
                padding: .6rem .25rem !important;
            }
            .stat-card-forn .rounded-circle {
                width: 30px; height: 30px; padding: 0 !important;
                display: flex; align-items: center; justify-content: center;
                margin: 0 0 .35rem 0 !important;
            }
            .stat-card-forn .fs-4 { font-size: .85rem !important; }
            .stat-card-forn h4 { font-size: .78rem; white-space: nowrap; }
            .stat-card-forn .text-uppercase { font-size: .55rem; letter-spacing: 0; line-height: 1.15; }
        }
        @media (max-width: 420px) {
            .navbar .navbar-brand img { height: 32px; }
            .navbar .btn span.nav-btn-label { display: none; }
        }
    </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-dark shadow-sm" style="background-color: <?= htmlspecialchars($cor_modulo) ?>;">
  <div class="container">
    <span class="navbar-brand mb-0">
      <img src="img/LOGO MEP NAV.svg" alt="Meu Evento PRO" style="height:40px;">
    </span>
    <div class="d-flex align-items-center gap-2">
      <a href="<?= $eh_noivos ? 'noivos.php' : 'gerenciar.php?id=' . $evento_id ?>" class="btn btn-sm btn-outline-light rounded-3">
        <i class="bi bi-arrow-left me-1"></i> <span class="nav-btn-label">Voltar ao Cronograma</span>
      </a>
    </div>
  </div>
</nav>
<div class="container my-3 my-md-5">

    <div class="bg-white p-3 p-md-4 rounded shadow-sm mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h2 class="mb-0 fs-4 fs-md-2">Fornecedores</h2>
            <small class="text-muted">Cliente: <?= htmlspecialchars($evento['nome']) ?></small>
        </div>
        <div class="d-flex flex-wrap gap-3 text-start text-sm-end text-muted small">
            <div class="text-nowrap"><i class="bi bi-people-fill"></i> Total de Serviços: <strong><?= $total_fornecedores ?></strong></div>
            <div class="text-nowrap"><i class="bi bi-check-circle-fill" style="color: #28a745;"></i> Contratados: <strong><?= $fornecedores_contratados ?></strong></div>
        </div>
    </div>

    <div class="row g-2 g-md-3 mb-4">
        <div class="col-4">
            <div class="card bg-white shadow-sm border-0 h-100 stat-card-forn">
                <div class="card-body d-flex align-items-center">
                    <div class="bg-light rounded-circle p-3 me-3 flex-shrink-0"><i class="bi bi-cash-stack fs-4"></i></div>
                    <div style="min-width:0;">
                        <div class="text-muted small fw-bold text-uppercase text-truncate">Custo Previsto (Total)</div>
                        <h4 class="mb-0">R$ <?= number_format($valor_total, 2, ',', '.') ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-4">
            <div class="card bg-white shadow-sm border-0 h-100 stat-card-forn">
                <div class="card-body d-flex align-items-center">
                    <div class="bg-light rounded-circle p-3 me-3 flex-shrink-0"><i class="bi bi-check-circle fs-4" style="color: #28a745;"></i></div>
                    <div style="min-width:0;">
                        <div class="text-muted small fw-bold text-uppercase text-truncate">Já Contratado</div>
                        <h4 class="mb-0" style="color: #28a745;">R$ <?= number_format($valor_contratado, 2, ',', '.') ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-4">
            <div class="card bg-white shadow-sm border-0 h-100 stat-card-forn">
                <div class="card-body d-flex align-items-center">
                    <div class="bg-light rounded-circle p-3 me-3 flex-shrink-0"><i class="bi bi-hourglass-split fs-4" style="color: #ffc107;"></i></div>
                    <div style="min-width:0;">
                        <div class="text-muted small fw-bold text-uppercase text-truncate">Em Negociação</div>
                        <h4 class="mb-0 text-dark">R$ <?= number_format($valor_orcamento, 2, ',', '.') ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-2 g-md-3 mb-4">
        <div class="col-6">
            <div class="card bg-white shadow-sm border-0 h-100 stat-card-forn">
                <div class="card-body d-flex align-items-center">
                    <div class="bg-light rounded-circle p-3 me-3 flex-shrink-0"><i class="bi bi-cash-coin fs-4" style="color: #16a34a;"></i></div>
                    <div style="min-width:0;">
                        <div class="text-muted small fw-bold text-uppercase text-truncate">Já Pago</div>
                        <h4 class="mb-0" style="color: #16a34a;">R$ <?= number_format($valor_pago_total, 2, ',', '.') ?></h4>
                        <div class="text-muted" style="font-size:.68rem;"><?= $pct_pago_total ?>% do total previsto</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6">
            <div class="card bg-white shadow-sm border-0 h-100 stat-card-forn">
                <div class="card-body d-flex align-items-center">
                    <div class="bg-light rounded-circle p-3 me-3 flex-shrink-0"><i class="bi bi-exclamation-circle fs-4" style="color: #dc3545;"></i></div>
                    <div style="min-width:0;">
                        <div class="text-muted small fw-bold text-uppercase text-truncate">Saldo a Pagar</div>
                        <h4 class="mb-0" style="color: #dc3545;">R$ <?= number_format($valor_restante_total, 2, ',', '.') ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($msg_erro)): ?><div class="alert alert-danger shadow-sm alert-dismissible"><button class="btn-close" data-bs-dismiss="alert"></button><?= htmlspecialchars($msg_erro) ?></div><?php endif; ?>
    <?php if (!empty($msg_sucesso)): ?><div class="alert alert-success shadow-sm alert-dismissible"><button class="btn-close" data-bs-dismiss="alert"></button><?= htmlspecialchars($msg_sucesso) ?></div><?php endif; ?>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex flex-column flex-md-row justify-content-between align-items-stretch align-items-md-center gap-2 py-3">
            <h5 class="mb-0"><i class="bi bi-shop me-2"></i> Lista de Fornecedores</h5>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovoFornecedor">
                <i class="bi bi-plus-lg"></i> Adicionar Fornecedor
            </button>
        </div>

        <?php if (!empty($lista_fornecedores)): ?>
        <div class="card-header bg-white border-top-0 pt-0 pb-2">
            <div class="btn-group btn-group-sm flex-wrap" role="group" id="filtro-status-forn">
                <button type="button" class="btn btn-outline-dark active" data-filtro="todos">Todos</button>
                <button type="button" class="btn btn-outline-success" data-filtro="Contratado">Contratados</button>
                <button type="button" class="btn btn-outline-warning" data-filtro="Orçamento">Orçamento</button>
                <button type="button" class="btn btn-outline-danger" data-filtro="Cancelado">Cancelados</button>
            </div>
        </div>
        <?php endif; ?>

        <div class="card-body p-0">
            <?php if (empty($lista_fornecedores)): ?>
                <div class="text-center p-5 text-muted">
                    <p class="mt-2 mb-0">Nenhum fornecedor adicionado para este evento.</p>
                </div>
            <?php else: ?>
                <div class="text-center p-4 text-muted d-none" id="filtro-vazio-msg">
                    <p class="mt-2 mb-0">Nenhum fornecedor nesse status.</p>
                </div>

                <!-- Visão mobile: cards empilhados -->
                <div class="d-md-none">
                    <?php foreach ($lista_fornecedores as $forn):
                        $status_color = 'secondary';
                        if ($forn['status'] == 'Contratado') $status_color = 'success';
                        if ($forn['status'] == 'Orçamento') $status_color = 'warning text-dark';
                        if ($forn['status'] == 'Cancelado') $status_color = 'danger';
                        $fValor = (float)$forn['valor'];
                        $fPago  = (float)($forn['valor_pago'] ?? 0);
                        $fRest  = max(0.0, $fValor - $fPago);
                        $fPct   = $fValor > 0 ? round($fPago / $fValor * 100) : 0;
                        $fQuit  = $fRest <= 0 && $fValor > 0;
                        $barClr = $fQuit ? '#16a34a' : ($fPct >= 50 ? '#0dcaf0' : '#ffc107');
                        $fCategoria = CATEGORIAS_FORNECEDOR[$forn['categoria'] ?? 'Outros'] ?? CATEGORIAS_FORNECEDOR['Outros'];
                        $fVencido = false; $fVenceBreve = false;
                        if (!$fQuit && !empty($forn['data_limite_pagamento'])) {
                            $dias = (strtotime($forn['data_limite_pagamento']) - strtotime(date('Y-m-d'))) / 86400;
                            if ($dias < 0) { $fVencido = true; } elseif ($dias <= 7) { $fVenceBreve = true; }
                        }
                        $qtdHistorico = count($historico_pagamentos[$forn['id']] ?? []);
                    ?>
                        <div class="p-3 border-bottom forn-linha" data-status="<?= htmlspecialchars($forn['status']) ?>">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div style="min-width:0;">
                                    <div class="fw-bold text-truncate">
                                        <i class="bi <?= $fCategoria ?> text-muted me-1" title="<?= htmlspecialchars($forn['categoria'] ?? 'Outros') ?>"></i>
                                        <?= htmlspecialchars($forn['servico']) ?>
                                    </div>
                                    <small class="text-muted"><i class="bi bi-person"></i> <?= htmlspecialchars($forn['nome']) ?></small>
                                </div>
                                <span class="badge bg-<?= $status_color ?> rounded-pill fw-normal px-3 py-2 flex-shrink-0"><?= htmlspecialchars($forn['status']) ?></span>
                            </div>
                            <?php if (!empty($forn['contato'])): ?>
                            <div class="text-muted small mt-2"><i class="bi bi-telephone"></i> <?= htmlspecialchars($forn['contato']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($forn['data_limite_pagamento'])): ?>
                            <div class="small mt-1 <?= $fVencido ? 'text-danger fw-bold' : ($fVenceBreve ? 'text-warning fw-bold' : 'text-muted') ?>">
                                <i class="bi bi-calendar-event"></i>
                                Prazo: <?= date('d/m/Y', strtotime($forn['data_limite_pagamento'])) ?>
                                <?= $fVencido ? ' — Atrasado' : ($fVenceBreve ? ' — Vence em breve' : '') ?>
                            </div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span class="fw-bold">R$ <?= number_format($fValor, 2, ',', '.') ?></span>
                            </div>
                            <?php if ($fValor > 0): ?>
                            <div class="mt-2">
                                <div style="height:4px;background:#e2e8f0;border-radius:999px;overflow:hidden;">
                                    <div style="height:100%;width:<?= $fPct ?>%;background:<?= $barClr ?>;border-radius:999px;"></div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-1" style="font-size:.72rem;">
                                    <span class="text-success">Pago: R$ <?= number_format($fPago, 2, ',', '.') ?></span>
                                    <span class="<?= $fQuit ? 'text-success' : 'text-danger' ?> fw-bold">
                                        <?= $fQuit ? '✓ Quitado' : 'Resta R$ ' . number_format($fRest, 2, ',', '.') ?>
                                    </span>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <div class="d-flex gap-1">
                                    <?php if (!$fQuit && $fValor > 0): ?>
                                    <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#modalPagamentoForn<?= $forn['id'] ?>" title="Registrar pagamento">
                                        <i class="bi bi-cash-coin me-1"></i> Pagamento
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($qtdHistorico > 0): ?>
                                    <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#modalHistoricoForn<?= $forn['id'] ?>" title="Ver histórico de pagamentos">
                                        <i class="bi bi-clock-history"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex gap-1">
                                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalEditarForn<?= $forn['id'] ?>" title="Editar">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Tem certeza que deseja excluir este fornecedor?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="id_fornecedor" value="<?= $forn['id'] ?>">
                                        <button type="submit" name="excluir_fornecedor" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Visão desktop: tabela -->
                <div class="table-responsive d-none d-md-block">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Serviço / Nome</th>
                                <th>Contato</th>
                                <th>Status</th>
                                <th>Prazo pagamento</th>
                                <th style="min-width:180px;">Valor Previsto / Pago</th>
                                <th class="text-end pe-4">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lista_fornecedores as $forn):
                                $status_color = 'secondary';
                                if ($forn['status'] == 'Contratado') $status_color = 'success';
                                if ($forn['status'] == 'Orçamento') $status_color = 'warning text-dark';
                                if ($forn['status'] == 'Cancelado') $status_color = 'danger';
                                $fValor = (float)$forn['valor'];
                                $fPago  = (float)($forn['valor_pago'] ?? 0);
                                $fRest  = max(0.0, $fValor - $fPago);
                                $fPct   = $fValor > 0 ? round($fPago / $fValor * 100) : 0;
                                $fQuit  = $fRest <= 0 && $fValor > 0;
                                $barClr = $fQuit ? '#16a34a' : ($fPct >= 50 ? '#0dcaf0' : '#ffc107');
                                $fCategoria = CATEGORIAS_FORNECEDOR[$forn['categoria'] ?? 'Outros'] ?? CATEGORIAS_FORNECEDOR['Outros'];
                                $fVencido = false; $fVenceBreve = false;
                                if (!$fQuit && !empty($forn['data_limite_pagamento'])) {
                                    $dias = (strtotime($forn['data_limite_pagamento']) - strtotime(date('Y-m-d'))) / 86400;
                                    if ($dias < 0) { $fVencido = true; } elseif ($dias <= 7) { $fVenceBreve = true; }
                                }
                                $qtdHistorico = count($historico_pagamentos[$forn['id']] ?? []);
                            ?>
                                <tr class="forn-linha" data-status="<?= htmlspecialchars($forn['status']) ?>">
                                    <td class="ps-4 py-3">
                                        <div class="fw-bold">
                                            <i class="bi <?= $fCategoria ?> text-muted me-1" title="<?= htmlspecialchars($forn['categoria'] ?? 'Outros') ?>"></i>
                                            <?= htmlspecialchars($forn['servico']) ?>
                                        </div>
                                        <small class="text-muted"><i class="bi bi-person"></i> <?= htmlspecialchars($forn['nome']) ?></small>
                                    </td>
                                    <td><small><i class="bi bi-telephone"></i> <?= htmlspecialchars($forn['contato']) ?></small></td>
                                    <td><span class="badge bg-<?= $status_color ?> rounded-pill fw-normal px-3 py-2"><?= htmlspecialchars($forn['status']) ?></span></td>
                                    <td>
                                        <?php if (!empty($forn['data_limite_pagamento'])): ?>
                                        <small class="<?= $fVencido ? 'text-danger fw-bold' : ($fVenceBreve ? 'text-warning fw-bold' : 'text-muted') ?>">
                                            <i class="bi bi-calendar-event"></i> <?= date('d/m/Y', strtotime($forn['data_limite_pagamento'])) ?>
                                            <?php if ($fVencido): ?><br>Atrasado<?php elseif ($fVenceBreve): ?><br>Vence em breve<?php endif; ?>
                                        </small>
                                        <?php else: ?><small class="text-muted">—</small><?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="fw-bold">R$ <?= number_format($fValor, 2, ',', '.') ?></span>
                                        <?php if ($fValor > 0): ?>
                                        <div style="height:4px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin:.3rem 0;">
                                            <div style="height:100%;width:<?= $fPct ?>%;background:<?= $barClr ?>;border-radius:999px;"></div>
                                        </div>
                                        <div class="d-flex gap-2" style="font-size:.72rem;">
                                            <span class="text-success">Pago: R$ <?= number_format($fPago, 2, ',', '.') ?></span>
                                            <span class="<?= $fQuit ? 'text-success' : 'text-danger' ?> fw-bold">
                                                <?= $fQuit ? '✓ Quitado' : 'Resta R$ ' . number_format($fRest, 2, ',', '.') ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <?php if (!$fQuit && $fValor > 0): ?>
                                        <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#modalPagamentoForn<?= $forn['id'] ?>" title="Registrar pagamento">
                                            <i class="bi bi-cash-coin"></i>
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($qtdHistorico > 0): ?>
                                        <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#modalHistoricoForn<?= $forn['id'] ?>" title="Ver histórico de pagamentos">
                                            <i class="bi bi-clock-history"></i>
                                        </button>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalEditarForn<?= $forn['id'] ?>" title="Editar">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Tem certeza que deseja excluir este fornecedor?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
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
      <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
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
                      <label class="form-label fw-bold small">Categoria</label>
                      <select name="categoria_fornecedor" class="form-select">
                          <?php foreach (CATEGORIAS_FORNECEDOR as $catNome => $catIcone): ?>
                          <option value="<?= htmlspecialchars($catNome) ?>" <?= $catNome === 'Outros' ? 'selected' : '' ?>><?= htmlspecialchars($catNome) ?></option>
                          <?php endforeach; ?>
                      </select>
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label fw-bold small">Status *</label>
                      <select name="status_fornecedor" class="form-select" required>
                          <option value="Orçamento">Orçamento (Avaliando)</option>
                          <option value="Contratado">Contratado (Fechado)</option>
                          <option value="Cancelado">Cancelado</option>
                      </select>
                  </div>
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label fw-bold small">Valor Previsto (R$)</label>
                      <input type="number" step="0.01" min="0" name="valor_fornecedor" class="form-control" placeholder="Ex: 1500.50">
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label fw-bold small">Entrada / Sinal (R$)</label>
                      <input type="number" step="0.01" min="0" name="valor_entrada_fornecedor" class="form-control" placeholder="Opcional">
                      <small class="text-muted">Valor já pago no ato do fechamento, se houver.</small>
                  </div>
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label fw-bold small">Data da entrada</label>
                      <input type="date" name="data_entrada_fornecedor" class="form-control" value="<?= date('Y-m-d') ?>">
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label fw-bold small">Prazo limite de pagamento</label>
                      <input type="date" name="data_limite_fornecedor" class="form-control">
                  </div>
              </div>
              <div class="mb-3">
                  <label class="form-label fw-bold small">Comprovante da entrada</label>
                  <input type="file" name="comprovante_entrada_fornecedor" class="form-control" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf">
                  <small class="text-muted">Opcional — imagem ou PDF.</small>
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
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
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
                      <label class="form-label fw-bold small">Categoria</label>
                      <select name="categoria_fornecedor_edit" class="form-select">
                          <?php foreach (CATEGORIAS_FORNECEDOR as $catNome => $catIcone): ?>
                          <option value="<?= htmlspecialchars($catNome) ?>" <?= ($forn['categoria'] ?? 'Outros') === $catNome ? 'selected' : '' ?>><?= htmlspecialchars($catNome) ?></option>
                          <?php endforeach; ?>
                      </select>
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label fw-bold small">Status *</label>
                      <select name="status_fornecedor_edit" class="form-select" required>
                          <option value="Orçamento" <?= $forn['status'] == 'Orçamento' ? 'selected' : '' ?>>Orçamento</option>
                          <option value="Contratado" <?= $forn['status'] == 'Contratado' ? 'selected' : '' ?>>Contratado</option>
                          <option value="Cancelado" <?= $forn['status'] == 'Cancelado' ? 'selected' : '' ?>>Cancelado</option>
                      </select>
                  </div>
              </div>
              <div class="row">
                  <div class="col-md-6 mb-3">
                      <label class="form-label fw-bold small">Valor Previsto (R$)</label>
                      <input type="number" step="0.01" min="0" name="valor_fornecedor_edit" class="form-control" value="<?= $forn['valor'] ?>">
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label fw-bold small">Prazo limite de pagamento</label>
                      <input type="date" name="data_limite_fornecedor_edit" class="form-control" value="<?= !empty($forn['data_limite_pagamento']) ? htmlspecialchars($forn['data_limite_pagamento']) : '' ?>">
                  </div>
              </div>
              <div class="mb-3">
                  <label class="form-label fw-bold small">Valor Pago (correção manual, R$)</label>
                  <input type="number" step="0.01" min="0" name="valor_pago_fornecedor_edit" class="form-control" value="<?= (float)($forn['valor_pago'] ?? 0) ?>">
                  <small class="text-muted">Pra somar um novo pagamento sem apagar o que já foi registrado, use o botão "Pagamento" na lista.</small>
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

<?php if (!empty($historico_pagamentos[$forn['id']])): ?>
<div class="modal fade" id="modalHistoricoForn<?= $forn['id'] ?>" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title"><i class="bi bi-clock-history"></i> Histórico de Pagamentos</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
          <p class="text-muted small mb-3"><?= htmlspecialchars($forn['servico']) ?> — <?= htmlspecialchars($forn['nome']) ?></p>
          <ul class="list-group list-group-flush">
              <?php foreach ($historico_pagamentos[$forn['id']] as $pgto): ?>
              <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                  <span><i class="bi bi-calendar3 text-muted me-1"></i> <?= date('d/m/Y', strtotime($pgto['criado_em'])) ?></span>
                  <span class="d-flex align-items-center gap-2">
                      <span class="fw-bold text-success">R$ <?= number_format((float)$pgto['valor'], 2, ',', '.') ?></span>
                      <?php if (!empty($pgto['comprovante_arquivo'])): ?>
                      <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 btn-ver-comprovante"
                              data-arquivo="uploads/<?= htmlspecialchars($pgto['comprovante_arquivo'], ENT_QUOTES, 'UTF-8') ?>"
                              data-nome="<?= htmlspecialchars($pgto['comprovante_nome_original'] ?? 'comprovante', ENT_QUOTES, 'UTF-8') ?>"
                              data-imagem="<?= in_array($pgto['comprovante_extensao'], ['jpg','jpeg','png','webp','gif'], true) ? '1' : '0' ?>"
                              title="Ver comprovante: <?= htmlspecialchars($pgto['comprovante_nome_original'] ?? '') ?>">
                          <i class="bi <?= in_array($pgto['comprovante_extensao'], ['jpg','jpeg','png','webp','gif'], true) ? 'bi-image' : 'bi-file-earmark-pdf' ?>"></i>
                      </button>
                      <?php endif; ?>
                  </span>
              </li>
              <?php endforeach; ?>
          </ul>
      </div>
      <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="modal fade" id="modalPagamentoForn<?= $forn['id'] ?>" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title"><i class="bi bi-cash-coin"></i> Registrar Pagamento</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
          <input type="hidden" name="registrar_pagamento" value="1">
          <input type="hidden" name="id_fornecedor" value="<?= $forn['id'] ?>">
          <div class="modal-body">
              <p class="text-muted small mb-3">
                  <?= htmlspecialchars($forn['servico']) ?> — <?= htmlspecialchars($forn['nome']) ?><br>
                  Já pago: <strong>R$ <?= number_format((float)($forn['valor_pago'] ?? 0), 2, ',', '.') ?></strong>
                  de R$ <?= number_format((float)$forn['valor'], 2, ',', '.') ?>
              </p>
              <div class="row">
                  <div class="col-6 mb-1">
                      <label class="form-label fw-bold small">Valor deste pagamento (R$) *</label>
                      <input type="number" step="0.01" min="0.01" name="valor_pagamento" class="form-control" placeholder="Ex: 200.00" required autofocus>
                  </div>
                  <div class="col-6 mb-1">
                      <label class="form-label fw-bold small">Data do pagamento</label>
                      <input type="date" name="data_pagamento" class="form-control" value="<?= date('Y-m-d') ?>">
                  </div>
              </div>
              <small class="text-muted">Esse valor é somado ao que já foi pago — não substitui.</small>
              <div class="mt-3">
                  <label class="form-label fw-bold small">Comprovante</label>
                  <input type="file" name="comprovante_pagamento" class="form-control" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf">
                  <small class="text-muted">Opcional — imagem ou PDF.</small>
              </div>
          </div>
          <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i> Registrar</button>
          </div>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- Modal único de visualização de comprovante — o conteúdo (imagem/PDF) é
     preenchido via JS a partir dos data-* do botão "Ver comprovante" clicado,
     em vez de um modal por pagamento. -->
<div class="modal fade" id="modalVerComprovante" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title text-truncate" id="comprovante-titulo"><i class="bi bi-receipt me-1"></i> Comprovante</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center p-0" style="background:#f1f3f5;">
        <img id="comprovante-preview-img" src="" alt="Comprovante" class="img-fluid" style="max-height:75vh;display:none;">
        <iframe id="comprovante-preview-pdf" src="" style="width:100%;height:75vh;border:0;display:none;"></iframe>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
        <a id="comprovante-download-link" href="" download class="btn btn-primary">
          <i class="bi bi-download me-1"></i> Baixar
        </a>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Filtro por status (Todos/Contratados/Orçamento/Cancelados): filtra as linhas
// já carregadas na página (mobile e desktop juntos), sem precisar recarregar.
document.getElementById('filtro-status-forn')?.addEventListener('click', function (e) {
    const btn = e.target.closest('button[data-filtro]');
    if (!btn) return;
    this.querySelectorAll('button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const filtro = btn.dataset.filtro;
    let visiveis = 0;
    document.querySelectorAll('.forn-linha').forEach(function (linha) {
        const mostra = (filtro === 'todos' || linha.dataset.status === filtro);
        linha.style.display = mostra ? '' : 'none';
        if (mostra) visiveis++;
    });
    const msgVazio = document.getElementById('filtro-vazio-msg');
    if (msgVazio) msgVazio.classList.toggle('d-none', visiveis > 0);
});

// Visualizar comprovante: preenche o modal único com a imagem/PDF do botão
// clicado, em vez de abrir o arquivo em outra aba. O botão fica dentro do
// modal de Histórico — fecha ele primeiro (Bootstrap não empilha modal bem)
// e só abre o de visualização depois que o de Histórico terminou de sumir.
const modalComprovanteEl = document.getElementById('modalVerComprovante');
if (modalComprovanteEl) {
    const modalComprovante = bootstrap.Modal.getOrCreateInstance(modalComprovanteEl);

    function abrirComprovante(btn) {
        const arquivo  = btn.dataset.arquivo;
        const nome     = btn.dataset.nome || 'comprovante';
        const ehImagem = btn.dataset.imagem === '1';

        document.getElementById('comprovante-titulo').textContent = nome;
        document.getElementById('comprovante-download-link').setAttribute('href', arquivo);
        document.getElementById('comprovante-download-link').setAttribute('download', nome);

        const img = document.getElementById('comprovante-preview-img');
        const pdf = document.getElementById('comprovante-preview-pdf');
        if (ehImagem) {
            img.src = arquivo;
            img.style.display = '';
            pdf.style.display = 'none';
            pdf.src = '';
        } else {
            pdf.src = arquivo;
            pdf.style.display = '';
            img.style.display = 'none';
            img.src = '';
        }
        modalComprovante.show();
    }

    document.querySelectorAll('.btn-ver-comprovante').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const modalAtual = btn.closest('.modal');
            if (modalAtual) {
                modalAtual.addEventListener('hidden.bs.modal', () => abrirComprovante(btn), { once: true });
                bootstrap.Modal.getInstance(modalAtual)?.hide();
            } else {
                abrirComprovante(btn);
            }
        });
    });

    // Limpa os previews ao fechar, pra não continuar carregando o PDF/imagem à toa.
    modalComprovanteEl.addEventListener('hidden.bs.modal', function () {
        document.getElementById('comprovante-preview-img').src = '';
        document.getElementById('comprovante-preview-pdf').src = '';
    });
}
</script>
</body>
</html>