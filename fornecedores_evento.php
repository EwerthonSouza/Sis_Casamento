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

// Assistente não acessa dados financeiros/fornecedores — mesma regra do
// Resumo Financeiro do gerenciar.php e do relatório PDF. Vale também pra
// quem digitar o endereço direto ou chegar por um link antigo.
if ($_SESSION['usuario_tipo'] === 'assistente') {
    $id_volta = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    header("Location: " . ($id_volta ? "gerenciar.php?id=" . $id_volta : "painel_admin.php"));
    exit;
}

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
// Como chamar o cliente deste evento nos textos (casal, aniversariante, empresa...)
$rotulo_cliente = labels_modulo_evento($evento['tipo_evento'] ?? 'casamento')['singular_contratante'] ?? 'cliente';
$rotulo_cliente = mb_strtoupper(mb_substr($rotulo_cliente, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($rotulo_cliente, 1, null, 'UTF-8');

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

// O comprovante de cada pagamento registra quem enviou ('Noivos'/'Assessoria')
// e quando (a data do pagamento pode ser retroativa, a do envio não) — usado
// pra mostrar quem mandou, pra regra de quem pode excluir e pra notificar o
// outro lado (notificacoes.inc.php / noivos.php).
if (!schema_ja_verificado('fornecedores_anexos_v1')) {
    try { $pdo->query("SELECT comprovante_enviado_por FROM fornecedores_pagamentos LIMIT 1"); }
    catch (Exception $e) {
        $pdo->exec("ALTER TABLE fornecedores_pagamentos
            ADD COLUMN comprovante_enviado_por VARCHAR(20) NULL,
            ADD COLUMN comprovante_enviado_por_nome VARCHAR(120) NULL,
            ADD COLUMN comprovante_enviado_em DATETIME NULL");
    }
    marcar_schema_verificado('fornecedores_anexos_v1');
}

const EXTENSOES_IMAGEM_ANEXO = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

// Quem está enviando (pra registrar nos arquivos/comprovantes)
$papel_usuario = $eh_noivos ? 'Noivos' : 'Assessoria';
$nome_usuario  = mb_substr((string)($_SESSION['usuario_nome'] ?? $papel_usuario), 0, 120);

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
    return salvar_arquivo_enviado($_FILES[$campo], 'comprovante', $evento_id);
}

// Valida (conteúdo real, não só a extensão) e salva em uploads/ um arquivo de
// $_FILES — usado pelo comprovante de pagamento e pelos arquivos compartilhados.
function salvar_arquivo_enviado(array $arquivo, string $prefixo, int $evento_id): array {
    if ($arquivo['error'] === UPLOAD_ERR_INI_SIZE || $arquivo['error'] === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'msg' => 'Arquivo grande demais para o limite do servidor.'];
    }
    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'msg' => 'Não foi possível enviar o arquivo.'];
    }

    $extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));

    $valido = false;
    if (in_array($extensao, EXTENSOES_IMAGEM_ANEXO, true)) {
        $valido = @getimagesize($arquivo['tmp_name']) !== false;
    } elseif ($extensao === 'pdf') {
        $handle = @fopen($arquivo['tmp_name'], 'rb');
        if ($handle) {
            $valido = fread($handle, 5) === '%PDF-';
            fclose($handle);
        }
    }
    if (!$valido) {
        return ['ok' => false, 'msg' => '"' . $arquivo['name'] . '" está num formato não suportado. Envie uma imagem (jpg, png, webp, gif) ou PDF.'];
    }

    $nomeArquivo = $prefixo . '_' . $evento_id . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $extensao;
    if (!move_uploaded_file($arquivo['tmp_name'], './uploads/' . $nomeArquivo)) {
        return ['ok' => false, 'msg' => 'Não foi possível salvar o arquivo no servidor.'];
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
                $pdo->prepare("INSERT INTO fornecedores_pagamentos (fornecedor_id, valor, criado_em, comprovante_arquivo, comprovante_nome_original, comprovante_extensao, comprovante_enviado_por, comprovante_enviado_por_nome, comprovante_enviado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$novo_id, $valor_entrada, $data_entrada, $comprovante['arquivo'] ?? null, $comprovante['nome_original'] ?? null, $comprovante['extensao'] ?? null,
                               $comprovante ? $papel_usuario : null, $comprovante ? $nome_usuario : null, $comprovante ? date('Y-m-d H:i:s') : null]);
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
                $restante = max(0.0, (float)$forn['valor'] - (float)$forn['valor_pago']);
                $valor_add_real = min($valor_add, $restante);
                $novo_pago = (float)$forn['valor_pago'] + $valor_add_real;
                $pdo->prepare("UPDATE fornecedores_evento SET valor_pago = ? WHERE id = ? AND evento_id = ?")->execute([$novo_pago, $id_forn, $evento_id]);
                if ($valor_add_real > 0) {
                    $pdo->prepare("INSERT INTO fornecedores_pagamentos (fornecedor_id, valor, criado_em, comprovante_arquivo, comprovante_nome_original, comprovante_extensao, comprovante_enviado_por, comprovante_enviado_por_nome, comprovante_enviado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$id_forn, $valor_add_real, $data_pgto, $comprovante['arquivo'] ?? null, $comprovante['nome_original'] ?? null, $comprovante['extensao'] ?? null,
                                   $comprovante ? $papel_usuario : null, $comprovante ? $nome_usuario : null, $comprovante ? date('Y-m-d H:i:s') : null]);
                }
                if ($valor_add > $restante) {
                    $_SESSION['msg_erro'] = "O valor informado (R$ " . number_format($valor_add, 2, ',', '.')
                        . ") ultrapassava o quanto faltava (R$ " . number_format($restante, 2, ',', '.')
                        . "). Foi registrado apenas R$ " . number_format($valor_add_real, 2, ',', '.') . ".";
                } else {
                    $_SESSION['msg_sucesso'] = "Pagamento registrado com sucesso!";
                }
            } else {
                $_SESSION['msg_erro'] = "Fornecedor não encontrado.";
            }
        } else {
            $_SESSION['msg_erro'] = "Informe um valor de pagamento maior que zero.";
        }
        header("Location: fornecedores_evento.php?id=" . $evento_id); exit;
    }

    // ANEXAR COMPROVANTE a um pagamento que foi registrado sem ele
    if (isset($_POST['anexar_comprovante_pagamento'])) {
        $pag_id = (int)($_POST['id_pagamento'] ?? 0);
        $sel = $pdo->prepare("SELECT p.id, p.comprovante_arquivo, f.id AS forn_id FROM fornecedores_pagamentos p INNER JOIN fornecedores_evento f ON f.id = p.fornecedor_id WHERE p.id = ? AND f.evento_id = ?");
        $sel->execute([$pag_id, $evento_id]);
        $pag = $sel->fetch();
        $id_forn_ret = $pag ? (int)$pag['forn_id'] : 0;
        $comprovante = processar_comprovante_pagamento('comprovante_existente', $evento_id);

        if (!$pag) {
            $_SESSION['msg_erro'] = "Pagamento não encontrado.";
        } elseif (!empty($pag['comprovante_arquivo'])) {
            $_SESSION['msg_erro'] = "Esse pagamento já tem comprovante.";
        } elseif ($comprovante === null) {
            $_SESSION['msg_erro'] = "Escolha o arquivo do comprovante.";
        } elseif (!$comprovante['ok']) {
            $_SESSION['msg_erro'] = $comprovante['msg'];
        } else {
            $pdo->prepare("UPDATE fornecedores_pagamentos SET comprovante_arquivo = ?, comprovante_nome_original = ?, comprovante_extensao = ?, comprovante_enviado_por = ?, comprovante_enviado_por_nome = ?, comprovante_enviado_em = NOW() WHERE id = ?")
                ->execute([$comprovante['arquivo'], $comprovante['nome_original'], $comprovante['extensao'], $papel_usuario, $nome_usuario, $pag_id]);
            $_SESSION['msg_sucesso'] = "Comprovante anexado ao pagamento!";
        }
        header("Location: fornecedores_evento.php?id=" . $evento_id . ($id_forn_ret ? "&pagamento=" . $id_forn_ret : '')); exit;
    }

    // REMOVER COMPROVANTE de um pagamento (enviado errado) — o pagamento continua
    // registrado, só o arquivo sai (e o botão "Anexar" volta pra mandar o certo).
    // Mesma regra dos arquivos: equipe remove qualquer um; noivos, só os deles.
    if (isset($_POST['remover_comprovante_pagamento'])) {
        $pag_id = (int)($_POST['id_pagamento'] ?? 0);
        $sel = $pdo->prepare("SELECT p.id, p.comprovante_arquivo, p.comprovante_enviado_por, f.id AS forn_id FROM fornecedores_pagamentos p INNER JOIN fornecedores_evento f ON f.id = p.fornecedor_id WHERE p.id = ? AND f.evento_id = ?");
        $sel->execute([$pag_id, $evento_id]);
        $pag = $sel->fetch();
        $id_forn_ret = $pag ? (int)$pag['forn_id'] : 0;

        if (!$pag || empty($pag['comprovante_arquivo'])) {
            $_SESSION['msg_erro'] = "Comprovante não encontrado.";
        } elseif ($eh_noivos && $pag['comprovante_enviado_por'] !== 'Noivos') {
            $_SESSION['msg_erro'] = "Só a assessoria pode excluir comprovantes que ela enviou.";
        } else {
            $pdo->prepare("UPDATE fornecedores_pagamentos SET comprovante_arquivo = NULL, comprovante_nome_original = NULL, comprovante_extensao = NULL, comprovante_enviado_por = NULL, comprovante_enviado_por_nome = NULL, comprovante_enviado_em = NULL WHERE id = ?")
                ->execute([$pag_id]);
            $caminho = './uploads/' . basename($pag['comprovante_arquivo']);
            if (is_file($caminho)) { @unlink($caminho); }
            $_SESSION['msg_sucesso'] = "Comprovante excluído. O pagamento continua registrado — use \"Anexar\" nos pagamentos para enviar o correto.";
        }
        header("Location: fornecedores_evento.php?id=" . $evento_id . ($id_forn_ret ? "&pagamento=" . $id_forn_ret : '')); exit;
    }

    // APAGAR PAGAMENTO registrado errado: tira a linha do histórico, desconta o
    // valor do total pago e apaga o comprovante (se tiver) do disco.
    if (isset($_POST['excluir_pagamento'])) {
        $pag_id = (int)($_POST['id_pagamento'] ?? 0);
        $sel = $pdo->prepare("SELECT p.id, p.valor, p.comprovante_arquivo, f.id AS forn_id, f.valor_pago FROM fornecedores_pagamentos p INNER JOIN fornecedores_evento f ON f.id = p.fornecedor_id WHERE p.id = ? AND f.evento_id = ?");
        $sel->execute([$pag_id, $evento_id]);
        $pag = $sel->fetch();
        $id_forn_ret = $pag ? (int)$pag['forn_id'] : 0;

        if (!$pag) {
            $_SESSION['msg_erro'] = "Pagamento não encontrado.";
        } else {
            $novo_pago = max(0.0, (float)$pag['valor_pago'] - (float)$pag['valor']);
            $pdo->prepare("DELETE FROM fornecedores_pagamentos WHERE id = ?")->execute([$pag_id]);
            $pdo->prepare("UPDATE fornecedores_evento SET valor_pago = ? WHERE id = ? AND evento_id = ?")->execute([$novo_pago, $id_forn_ret, $evento_id]);
            if (!empty($pag['comprovante_arquivo'])) {
                $caminho = './uploads/' . basename($pag['comprovante_arquivo']);
                if (is_file($caminho)) { @unlink($caminho); }
            }
            $_SESSION['msg_sucesso'] = "Pagamento de R$ " . number_format((float)$pag['valor'], 2, ',', '.') . " apagado.";
        }
        header("Location: fornecedores_evento.php?id=" . $evento_id . ($id_forn_ret ? "&pagamento=" . $id_forn_ret : '')); exit;
    }

    // CORRIGIR PAGAMENTO (valor e/ou data): ajusta o total pago pela diferença,
    // sem nunca deixar o pago passar do valor combinado com o fornecedor.
    if (isset($_POST['editar_pagamento'])) {
        $pag_id    = (int)($_POST['id_pagamento'] ?? 0);
        $valor_novo = !empty($_POST['valor_pagamento_edit']) ? (float)$_POST['valor_pagamento_edit'] : 0.00;
        $data_nova = data_pagamento_valida($_POST['data_pagamento_edit'] ?? '');
        $sel = $pdo->prepare("SELECT p.id, p.valor, f.id AS forn_id, f.valor AS valor_total, f.valor_pago FROM fornecedores_pagamentos p INNER JOIN fornecedores_evento f ON f.id = p.fornecedor_id WHERE p.id = ? AND f.evento_id = ?");
        $sel->execute([$pag_id, $evento_id]);
        $pag = $sel->fetch();
        $id_forn_ret = $pag ? (int)$pag['forn_id'] : 0;

        if (!$pag) {
            $_SESSION['msg_erro'] = "Pagamento não encontrado.";
        } elseif ($valor_novo <= 0) {
            $_SESSION['msg_erro'] = "Informe um valor maior que zero (pra tirar o pagamento, use a lixeira).";
        } else {
            $pago_sem_este = max(0.0, (float)$pag['valor_pago'] - (float)$pag['valor']);
            $maximo = max(0.0, (float)$pag['valor_total'] - $pago_sem_este);
            $valor_real = min($valor_novo, $maximo);
            $pdo->prepare("UPDATE fornecedores_pagamentos SET valor = ?, criado_em = ? WHERE id = ?")->execute([$valor_real, $data_nova, $pag_id]);
            $pdo->prepare("UPDATE fornecedores_evento SET valor_pago = ? WHERE id = ? AND evento_id = ?")->execute([$pago_sem_este + $valor_real, $id_forn_ret, $evento_id]);
            if ($valor_novo > $maximo) {
                $_SESSION['msg_erro'] = "O valor informado (R$ " . number_format($valor_novo, 2, ',', '.') . ") passava do total combinado. Foi salvo R$ " . number_format($valor_real, 2, ',', '.') . ".";
            } else {
                $_SESSION['msg_sucesso'] = "Pagamento corrigido!";
            }
        }
        header("Location: fornecedores_evento.php?id=" . $evento_id . ($id_forn_ret ? "&pagamento=" . $id_forn_ret : '')); exit;
    }

    // EXCLUIR
    if (isset($_POST['excluir_fornecedor'])) {
        $id_forn = (int)$_POST['id_fornecedor'];
        // O banco apaga os pagamentos em cascata, mas os comprovantes em
        // uploads/ ficariam órfãos — junta a lista antes de excluir.
        $arquivos_forn_excluir = [];
        $selArq = $pdo->prepare("SELECT p.comprovante_arquivo FROM fornecedores_pagamentos p INNER JOIN fornecedores_evento f ON f.id = p.fornecedor_id WHERE f.id = ? AND f.evento_id = ? AND p.comprovante_arquivo IS NOT NULL");
        $selArq->execute([$id_forn, $evento_id]);
        $arquivos_forn_excluir = $selArq->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare("DELETE FROM fornecedores_evento WHERE id = ? AND evento_id = ?")->execute([$id_forn, $evento_id]);
        foreach ($arquivos_forn_excluir as $arqExcluir) {
            $caminho = './uploads/' . basename((string)$arqExcluir);
            if ($arqExcluir && is_file($caminho)) { @unlink($caminho); }
        }
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

// Quem enviou, em texto curto: "Vocês" (o cliente vendo o que ele mesmo mandou),
// "Você" (o próprio membro da equipe), o cliente pelo rótulo do módulo ("Casal",
// "Aniversariante", "Responsável pela empresa"...) ou "Assessoria (Nome)".
// 'Noivos' é só o valor interno do papel do cliente no banco (vale pra todo módulo).
function rotulo_enviado_por(?string $por, ?string $por_nome, string $papel_usuario, string $nome_usuario): string {
    global $rotulo_cliente;
    if (!$por) return '';
    if ($por === 'Noivos') return $papel_usuario === 'Noivos' ? 'Vocês' : $rotulo_cliente;
    if ($papel_usuario === 'Assessoria' && $por_nome === $nome_usuario) return 'Você';
    return 'Assessoria' . ($por_nome ? ' (' . $por_nome . ')' : '');
}

// Abre direto o modal de pagamentos de um fornecedor (link das notificações e
// retorno após anexar/excluir comprovante) — ?pagamento=ID
$abrir_pagamento_forn = (int)($_GET['pagamento'] ?? 0);

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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/estilo.css?v=18">
    <?= estilo_tema_evento($cor_modulo) ?>
    <style>
        .stat-card-forn .card-body { padding: .75rem 1rem; }
        .stat-card-forn .rounded-circle { width: 42px; height: 42px; padding: 0 !important; display: flex; align-items: center; justify-content: center; }
        .stat-card-forn .fs-4 { font-size: 1.1rem !important; }
        .stat-card-forn h4 { font-size: 1.05rem; }
        .stat-card-forn .text-uppercase { font-size: .62rem; }

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

            /* Nome do cliente numa linha */
            .cabecalho-forn-titulo { min-width: 0; width: 100%; }
            .cliente-forn { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

            /* Filtros (Todos / Contratados / Orçamento / Cancelados) numa linha */
            #filtro-status-forn { flex-wrap: nowrap !important; width: 100%; }
            #filtro-status-forn .btn { flex: 1 1 0; min-width: 0; padding: .3rem .15rem; font-size: .7rem; }
        }
        @media (max-width: 420px) {
            .navbar .navbar-brand img { height: 32px; }
            .navbar .btn span.nav-btn-label { display: none; }
        }

        /* ---- RESUMO DE VALORES (celular): recolhido, toque pra abrir ---- */
        .resumo-valores-toggle {
            display: flex; align-items: center; gap: .85rem; width: 100%;
            background: #fff; border: 0; border-radius: 16px; padding: .9rem 1rem;
            box-shadow: 0 4px 14px rgba(15,23,42,.06); text-align: left;
        }
        .rv-icone {
            width: 44px; height: 44px; border-radius: 12px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            background: #fee2e2; color: #dc2626; font-size: 1.25rem;
        }
        .rv-texto { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; }
        .rv-rotulo { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #64748b; }
        .rv-destaque { font-size: 1.35rem; font-weight: 800; color: #dc2626; line-height: 1.15; }
        .rv-barra { display: block; height: 5px; background: #e2e8f0; border-radius: 999px; overflow: hidden; margin: .35rem 0 .2rem; }
        .rv-barra > span { display: block; height: 100%; background: linear-gradient(90deg, #16a34a, #22c55e); border-radius: 999px; }
        .rv-sub { font-size: .72rem; color: #64748b; }
        .rv-acao { display: flex; flex-direction: column; align-items: center; gap: .1rem; flex-shrink: 0; color: #64748b; }
        .rv-acao-txt { font-size: .6rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
        .rv-chevron { font-size: 1rem; transition: transform .25s ease; }
        .resumo-valores-toggle:not(.collapsed) .rv-chevron { transform: rotate(180deg); }
        .rv-txt-fechar { display: none; }
        .resumo-valores-toggle:not(.collapsed) .rv-txt-abrir { display: none; }
        .resumo-valores-toggle:not(.collapsed) .rv-txt-fechar { display: inline; }
        .rv-lista { background: #fff; border-radius: 16px; margin-top: .5rem; padding: .35rem .9rem; box-shadow: 0 4px 14px rgba(15,23,42,.06); }
        .rv-item { display: flex; align-items: center; gap: .75rem; padding: .7rem 0; border-bottom: 1px solid #f1f5f9; }
        .rv-item:last-child { border-bottom: 0; }
        .rv-item-icone { width: 34px; height: 34px; border-radius: 10px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: .95rem; }
        .rv-item-rotulo { flex: 1 1 auto; min-width: 0; font-size: .85rem; color: #334155; font-weight: 600; }
        .rv-item-rotulo small { color: #94a3b8; font-weight: 500; }
        .rv-item-valor { font-size: 1.08rem; font-weight: 800; color: #0f172a; white-space: nowrap; }

        /* ---- Seletor de arquivo próprio (no lugar do nativo: no iPhone o
           "Escolher arquivo / Nenhum arquivo selecionado" ficava grande e quebrado) ---- */
        .seletor-arquivo {
            display: flex; align-items: center; gap: .6rem; width: 100%; margin: 0;
            border: 1.5px dashed #cbd5e1; border-radius: 12px; padding: .5rem .6rem;
            background: #f8fafc; cursor: pointer; min-width: 0;
        }
        .seletor-arquivo input[type="file"] { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
        .seletor-arquivo-btn {
            flex-shrink: 0; font-size: .82rem; font-weight: 600; color: var(--color-primary-dark, #6f4a2f);
            background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: .35rem .6rem; white-space: nowrap;
        }
        .seletor-arquivo-nome { flex: 1 1 auto; min-width: 0; font-size: .78rem; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .seletor-arquivo.tem-arquivo { border-style: solid; border-color: #86efac; background: #f0fdf4; }
        .seletor-arquivo.tem-arquivo .seletor-arquivo-nome { color: #15803d; font-weight: 600; }

        /* ---- Visualizador de comprovante dentro do modal de pagamento ---- */
        .visor-comprovante-inline { display: none; }
        .modal-content.vendo-comprovante > .visor-comprovante-inline { display: block; }
        .modal-content.vendo-comprovante > :not(.modal-header):not(.visor-comprovante-inline) { display: none !important; }
        .visor-topo { display: flex; align-items: center; gap: .5rem; padding: .75rem 1rem; border-bottom: 1px solid #f1f5f9; }
        .visor-nome { flex: 1 1 auto; min-width: 0; font-size: .8rem; color: #64748b; }
        .visor-area { padding: 1rem; text-align: center; background: #f8fafc; }
        .visor-img { max-width: 100%; max-height: 65vh; object-fit: contain; border-radius: 10px; box-shadow: 0 4px 14px rgba(0,0,0,.08); }
        .visor-pdf { width: 100%; height: 60vh; border: 0; border-radius: 10px; background: #fff; }

        /* Campos de valor/data dos pagamentos: mais baixos e compactos, lado a
           lado. A letra continua 16px no celular (abaixo disso o iPhone dá zoom
           ao tocar no campo) — o que diminui é o espaço interno. */
        .form-registrar-pagamento .form-control,
        .form-editar-pgto .form-control {
            padding: .4rem .65rem; min-height: 0; height: 42px; border-radius: 10px;
        }
        .form-registrar-pagamento input[type="date"],
        .form-editar-pgto input[type="date"] { -webkit-appearance: none; appearance: none; text-align: left; }
        .form-registrar-pagamento input[type="date"]::-webkit-date-and-time-value,
        .form-editar-pgto input[type="date"]::-webkit-date-and-time-value { text-align: left; margin: 0; }
        .form-registrar-pagamento .form-label,
        .form-editar-pgto .form-label { margin-bottom: .3rem; }

        /* Data compacta: o texto visível é nosso (menor, dd/mm/aaaa); o campo de
           data de verdade fica invisível por cima e continua com 16px — então o
           iPhone abre o calendário nativo ao tocar, sem dar zoom e sem mostrar
           a data grande e por extenso dentro do quadro. */
        .data-compacta {
            position: relative; display: flex; align-items: center; justify-content: space-between;
            gap: .4rem; width: 100%; height: 42px; margin: 0; padding: 0 .65rem;
            border: 1px solid #cbd5e1; border-radius: 10px; background: #f8fafc; cursor: pointer;
        }
        .form-editar-pgto .data-compacta { height: 38px; }
        .data-compacta-txt { font-size: .88rem; color: #1e293b; white-space: nowrap; }
        .data-compacta .bi-calendar3 { color: #94a3b8; font-size: .85rem; }
        .data-compacta-input {
            position: absolute; inset: 0; width: 100%; height: 100%;
            opacity: 0; border: 0; padding: 0; margin: 0; cursor: pointer;
            font-size: 16px; -webkit-appearance: none; appearance: none;
        }
        .data-compacta:focus-within { border-color: #c9a181; box-shadow: 0 0 0 4px rgba(169,116,79,.15); background: #fff; }

        /* Botões de cada pagamento no histórico (ver/anexar, corrigir, apagar) */
        .btn-acao-pgto { min-width: 32px; height: 30px; padding: 0 .5rem; display: inline-flex; align-items: center; justify-content: center; font-size: .85rem; }
        .form-editar-pgto { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: .6rem; }

        /* Pagamentos anteriores no modal de registrar pagamento */
        .historico-pgto-modal { max-height: 260px; overflow-y: auto; }
        /* Com a correção aberta, a lista cresce (sem rolagem interna cortando o formulário) */
        .historico-pgto-modal:has(.form-editar-pgto:not([hidden])) { max-height: none; }
        .historico-pgto-modal .list-group-item { font-size: .85rem; }
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
<div class="container my-3 my-md-5" id="conteudo-forn">

    <div class="bg-white p-3 p-md-4 rounded shadow-sm mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="cabecalho-forn-titulo">
            <h2 class="mb-0 fs-4 fs-md-2">Fornecedores</h2>
            <small class="text-muted cliente-forn">Cliente: <?= htmlspecialchars($evento['nome']) ?></small>
        </div>
        <div class="d-flex flex-wrap gap-3 text-start text-sm-end text-muted small">
            <div class="text-nowrap"><i class="bi bi-people-fill"></i> Total de Serviços: <strong><?= $total_fornecedores ?></strong></div>
            <div class="text-nowrap"><i class="bi bi-check-circle-fill" style="color: #28a745;"></i> Contratados: <strong><?= $fornecedores_contratados ?></strong></div>
        </div>
    </div>

    <!-- Celular: resumo recolhido (o que falta pagar em destaque); toque abre
         os 5 valores grandes. Computador/tablet: a fileira de 5 cards abaixo. -->
    <div class="d-md-none mb-3 resumo-valores-mobile">
        <button type="button" class="resumo-valores-toggle collapsed" data-bs-toggle="collapse" data-bs-target="#resumoValoresMobile" aria-expanded="false" aria-controls="resumoValoresMobile">
            <span class="rv-icone"><i class="bi bi-wallet2"></i></span>
            <span class="rv-texto">
                <span class="rv-rotulo">Falta pagar</span>
                <span class="rv-destaque">R$ <?= number_format($valor_restante_total, 2, ',', '.') ?></span>
                <span class="rv-barra"><span style="width:<?= $pct_pago_total ?>%;"></span></span>
                <span class="rv-sub"><?= $pct_pago_total ?>% pago de R$ <?= number_format($valor_total, 2, ',', '.') ?></span>
            </span>
            <span class="rv-acao"><span class="rv-acao-txt"><span class="rv-txt-abrir">Ver valores</span><span class="rv-txt-fechar">Fechar</span></span><i class="bi bi-chevron-down rv-chevron"></i></span>
        </button>
        <div class="collapse" id="resumoValoresMobile">
            <div class="rv-lista">
                <div class="rv-item"><span class="rv-item-icone" style="background:#f1f5f9;color:#334155;"><i class="bi bi-cash-stack"></i></span><span class="rv-item-rotulo">Custo previsto (total)</span><span class="rv-item-valor">R$ <?= number_format($valor_total, 2, ',', '.') ?></span></div>
                <div class="rv-item"><span class="rv-item-icone" style="background:#dcfce7;color:#16a34a;"><i class="bi bi-check-circle"></i></span><span class="rv-item-rotulo">Já contratado</span><span class="rv-item-valor" style="color:#16a34a;">R$ <?= number_format($valor_contratado, 2, ',', '.') ?></span></div>
                <div class="rv-item"><span class="rv-item-icone" style="background:#fef3c7;color:#d97706;"><i class="bi bi-hourglass-split"></i></span><span class="rv-item-rotulo">Em negociação</span><span class="rv-item-valor">R$ <?= number_format($valor_orcamento, 2, ',', '.') ?></span></div>
                <div class="rv-item"><span class="rv-item-icone" style="background:#dcfce7;color:#16a34a;"><i class="bi bi-cash-coin"></i></span><span class="rv-item-rotulo">Já pago <small>(<?= $pct_pago_total ?>%)</small></span><span class="rv-item-valor" style="color:#16a34a;">R$ <?= number_format($valor_pago_total, 2, ',', '.') ?></span></div>
                <div class="rv-item"><span class="rv-item-icone" style="background:#fee2e2;color:#dc2626;"><i class="bi bi-exclamation-circle"></i></span><span class="rv-item-rotulo">Saldo a pagar</span><span class="rv-item-valor" style="color:#dc2626;">R$ <?= number_format($valor_restante_total, 2, ',', '.') ?></span></div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4 linha-stats-forn d-none d-md-flex">
        <div class="col">
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
        <div class="col">
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
        <div class="col">
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
        <div class="col">
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
        <div class="col">
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
                        $qtdHistorico = count($historico_pagamentos[$forn['id']] ?? []); $qtdSemComprovante = count(array_filter($historico_pagamentos[$forn['id']] ?? [], fn($pg) => empty($pg['comprovante_arquivo'])));
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
                                    <?php if ((!$fQuit && $fValor > 0) || $qtdHistorico > 0): ?>
                                    <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#modalPagamentoForn<?= $forn['id'] ?>" title="<?= !$fQuit ? 'Registrar pagamento e ver os anteriores' : 'Ver pagamentos e comprovantes' ?>">
                                        <i class="bi bi-cash-coin me-1"></i> <?= !$fQuit && $fValor > 0 ? 'Pagamento' : 'Pagamentos' ?><?php if ($qtdSemComprovante > 0): ?><span class="badge rounded-pill bg-warning text-dark ms-1" title="<?= $qtdSemComprovante ?> pagamento(s) sem comprovante"><i class="bi bi-exclamation-lg"></i><?= $qtdSemComprovante ?></span><?php endif; ?>
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
                                $qtdHistorico = count($historico_pagamentos[$forn['id']] ?? []); $qtdSemComprovante = count(array_filter($historico_pagamentos[$forn['id']] ?? [], fn($pg) => empty($pg['comprovante_arquivo'])));
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
                                        <?php if ((!$fQuit && $fValor > 0) || $qtdHistorico > 0): ?>
                                        <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#modalPagamentoForn<?= $forn['id'] ?>" title="<?= !$fQuit ? 'Registrar pagamento e ver os anteriores' : 'Ver pagamentos e comprovantes' ?>">
                                            <i class="bi bi-cash-coin"></i><?php if ($qtdSemComprovante > 0): ?><span class="badge rounded-pill bg-warning text-dark ms-1" title="<?= $qtdSemComprovante ?> pagamento(s) sem comprovante"><i class="bi bi-exclamation-lg"></i><?= $qtdSemComprovante ?></span><?php endif; ?>
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
                      <input type="text" inputmode="decimal" name="valor_fornecedor" class="form-control input-moeda" placeholder="Ex: 1.500,50">
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label fw-bold small">Entrada / Sinal (R$)</label>
                      <input type="text" inputmode="decimal" name="valor_entrada_fornecedor" class="form-control input-moeda" placeholder="Opcional">
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
                  <label class="seletor-arquivo">
                      <input type="file" name="comprovante_entrada_fornecedor" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,image/*,application/pdf">
                      <span class="seletor-arquivo-btn"><i class="bi bi-paperclip"></i> Escolher comprovante</span>
                      <span class="seletor-arquivo-nome">Nenhum arquivo</span>
                  </label>
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
                      <input type="text" inputmode="decimal" name="valor_fornecedor_edit" class="form-control input-moeda" value="<?= number_format((float)$forn['valor'], 2, ',', '.') ?>">
                  </div>
                  <div class="col-md-6 mb-3">
                      <label class="form-label fw-bold small">Prazo limite de pagamento</label>
                      <input type="date" name="data_limite_fornecedor_edit" class="form-control" value="<?= !empty($forn['data_limite_pagamento']) ? htmlspecialchars($forn['data_limite_pagamento']) : '' ?>">
                  </div>
              </div>
              <div class="mb-3">
                  <label class="form-label fw-bold small">Valor Pago (correção manual, R$)</label>
                  <input type="text" inputmode="decimal" name="valor_pago_fornecedor_edit" class="form-control input-moeda" value="<?= number_format((float)($forn['valor_pago'] ?? 0), 2, ',', '.') ?>">
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

<div class="modal fade" id="modalPagamentoForn<?= $forn['id'] ?>" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title"><i class="bi bi-cash-coin"></i> <?= (float)$forn['valor'] - (float)($forn['valor_pago'] ?? 0) > 0 ? 'Registrar Pagamento' : 'Pagamentos' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <?php $forn_restante = max(0.0, (float)$forn['valor'] - (float)($forn['valor_pago'] ?? 0)); ?>
      <?php $hist_pgto = $historico_pagamentos[$forn['id']] ?? []; ?>
      <?php if ($hist_pgto): ?>
      <!-- Histórico dos pagamentos anteriores, já visível na hora de registrar
           um novo. Fica fora do <form> de registro porque o "Anexar" de cada
           linha é um formulário próprio (form dentro de form não funciona). -->
      <div class="modal-body pb-0">
          <div class="d-flex justify-content-between align-items-center mb-1">
              <span class="fw-bold small text-secondary"><i class="bi bi-clock-history me-1"></i> Pagamentos anteriores</span>
              <span class="text-muted" style="font-size:.72rem;"><?= count($hist_pgto) ?> registro<?= count($hist_pgto) !== 1 ? 's' : '' ?></span>
          </div>
          <ul class="list-group list-group-flush historico-pgto-modal border rounded-3">
              <?php foreach ($hist_pgto as $pgto):
                  $temComp = !empty($pgto['comprovante_arquivo']);
                  $porPgto = $temComp ? rotulo_enviado_por($pgto['comprovante_enviado_por'] ?? null, $pgto['comprovante_enviado_por_nome'] ?? null, $papel_usuario, $nome_usuario) : '';
              ?>
              <?php $podeExcluirComp = $temComp && (!$eh_noivos || ($pgto['comprovante_enviado_por'] ?? null) === 'Noivos'); ?>
              <li class="list-group-item py-2 linha-pgto" data-pgto-id="<?= (int)$pgto['id'] ?>">
                <div class="d-flex justify-content-between align-items-center gap-2">
                  <span class="small text-nowrap hist-pgto-info">
                      <i class="bi bi-calendar3 text-muted me-1"></i><?= date('d/m/Y', strtotime($pgto['criado_em'])) ?>
                      · <strong class="text-success">R$ <?= number_format((float)$pgto['valor'], 2, ',', '.') ?></strong>
                  </span>
                  <span class="d-flex align-items-center gap-1 flex-shrink-0">
                  <?php if ($temComp): ?>
                  <button type="button" class="btn btn-sm btn-outline-secondary btn-acao-pgto btn-ver-comprovante"
                          data-arquivo="uploads/<?= htmlspecialchars(rawurlencode($pgto['comprovante_arquivo']), ENT_QUOTES, 'UTF-8') ?>"
                          data-nome="<?= htmlspecialchars($pgto['comprovante_nome_original'] ?? 'comprovante', ENT_QUOTES, 'UTF-8') ?>"
                          data-imagem="<?= in_array($pgto['comprovante_extensao'], EXTENSOES_IMAGEM_ANEXO, true) ? '1' : '0' ?>"
                          data-pgto-id="<?= (int)$pgto['id'] ?>"
                          data-pode-excluir="<?= $podeExcluirComp ? '1' : '0' ?>"
                          title="Ver comprovante<?= $porPgto !== '' ? ' (enviado por ' . htmlspecialchars($porPgto) . ')' : '' ?>">
                      <i class="bi <?= in_array($pgto['comprovante_extensao'], EXTENSOES_IMAGEM_ANEXO, true) ? 'bi-image' : 'bi-file-earmark-pdf' ?>"></i><span class="d-none d-sm-inline ms-1">Comprovante</span>
                  </button>
                  <?php else: ?>
                  <form method="POST" enctype="multipart/form-data" class="d-inline form-anexar-comprovante">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                      <input type="hidden" name="anexar_comprovante_pagamento" value="1">
                      <input type="hidden" name="id_pagamento" value="<?= (int)$pgto['id'] ?>">
                      <label class="btn btn-sm btn-outline-warning btn-acao-pgto mb-0" title="Pagamento sem comprovante — anexar agora">
                          <i class="bi bi-paperclip"></i><span class="d-none d-sm-inline ms-1">Anexar</span>
                          <input type="file" name="comprovante_existente" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,image/*,application/pdf" class="d-none" onchange="if (this.files.length) { this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit(); }">
                      </label>
                  </form>
                  <?php endif; ?>
                  <button type="button" class="btn btn-sm btn-outline-secondary btn-acao-pgto btn-editar-pgto" title="Corrigir valor ou data"><i class="bi bi-pencil"></i></button>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Apagar este pagamento de R$ <?= number_format((float)$pgto['valor'], 2, ',', '.') ?>?\n\nO valor sai do total pago<?= $temComp ? ' e o comprovante também é apagado' : '' ?>.');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                      <input type="hidden" name="excluir_pagamento" value="1">
                      <input type="hidden" name="id_pagamento" value="<?= (int)$pgto['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger btn-acao-pgto" title="Apagar pagamento (registrado errado)"><i class="bi bi-trash"></i></button>
                  </form>
                  </span>
                </div>
                <!-- Corrigir: abre aqui mesmo, embaixo da linha -->
                <form method="POST" class="form-editar-pgto mt-2" hidden>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="editar_pagamento" value="1">
                    <input type="hidden" name="id_pagamento" value="<?= (int)$pgto['id'] ?>">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small fw-bold mb-1">Valor (R$)</label>
                            <input type="text" inputmode="decimal" name="valor_pagamento_edit" class="form-control form-control-sm input-moeda" value="<?= number_format((float)$pgto['valor'], 2, ',', '.') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold mb-1">Data</label>
                            <label class="data-compacta">
                                <span class="data-compacta-txt"><?= date('d/m/Y', strtotime($pgto['criado_em'])) ?></span>
                                <i class="bi bi-calendar3"></i>
                                <input type="date" name="data_pagamento_edit" class="data-compacta-input" value="<?= date('Y-m-d', strtotime($pgto['criado_em'])) ?>">
                            </label>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-2">
                        <button type="button" class="btn btn-sm btn-light border btn-cancelar-edit-pgto">Cancelar</button>
                        <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i> Salvar correção</button>
                    </div>
                </form>
              </li>
              <?php endforeach; ?>
          </ul>
      </div>
      <?php endif; ?>
      <?php if ($forn_restante > 0): ?>
      <form method="POST" enctype="multipart/form-data" class="form-registrar-pagamento" data-restante="<?= $forn_restante ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
          <input type="hidden" name="registrar_pagamento" value="1">
          <input type="hidden" name="id_fornecedor" value="<?= $forn['id'] ?>">
          <div class="modal-body">
              <p class="text-muted small mb-3">
                  <?= htmlspecialchars($forn['servico']) ?> — <?= htmlspecialchars($forn['nome']) ?><br>
                  <span class="text-nowrap">Já pago: <strong>R$ <?= number_format((float)($forn['valor_pago'] ?? 0), 2, ',', '.') ?></strong></span>
                  <span class="text-nowrap">de R$ <?= number_format((float)$forn['valor'], 2, ',', '.') ?></span>
                  · <span class="text-nowrap">Falta: <strong class="text-danger">R$ <?= number_format($forn_restante, 2, ',', '.') ?></strong></span>
              </p>
              <div class="row">
                  <div class="col-6 mb-1">
                      <label class="form-label fw-bold small text-nowrap"><span class="d-none d-sm-inline">Valor deste pagamento (R$) *</span><span class="d-sm-none">Valor (R$) *</span></label>
                      <input type="text" inputmode="decimal" name="valor_pagamento" class="form-control input-moeda input-valor-pagamento" placeholder="Ex: 200,00" required autofocus>
                      <div class="invalid-feedback aviso-valor-excede"></div>
                  </div>
                  <div class="col-6 mb-1">
                      <label class="form-label fw-bold small text-nowrap"><span class="d-none d-sm-inline">Data do pagamento</span><span class="d-sm-none">Data</span></label>
                      <label class="data-compacta">
                          <span class="data-compacta-txt"><?= date('d/m/Y') ?></span>
                          <i class="bi bi-calendar3"></i>
                          <input type="date" name="data_pagamento" class="data-compacta-input" value="<?= date('Y-m-d') ?>">
                      </label>
                  </div>
              </div>
              <small class="text-muted">Esse valor é somado ao que já foi pago — não substitui.</small>
              <div class="mt-3">
                  <label class="form-label fw-bold small">Comprovante</label>
                  <label class="seletor-arquivo">
                      <input type="file" name="comprovante_pagamento" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,image/*,application/pdf">
                      <span class="seletor-arquivo-btn"><i class="bi bi-paperclip"></i> Escolher comprovante</span>
                      <span class="seletor-arquivo-nome">Nenhum arquivo</span>
                  </label>
                  <small class="text-muted">Opcional — imagem ou PDF.</small>
              </div>
          </div>
          <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
              <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i> Registrar</button>
          </div>
      </form>
      <?php else: ?>
      <div class="modal-body">
          <div class="alert alert-success small mb-0 py-2">
              <i class="bi bi-check-circle-fill me-1"></i> <?= htmlspecialchars($forn['servico']) ?> está quitado
              (R$ <?= number_format((float)$forn['valor'], 2, ',', '.') ?>).
          </div>
      </div>
      <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ============================================================
// Todos os comportamentos são "delegados" no document (registrados uma vez
// só): valem pros botões que já existem e pros que chegam depois, quando as
// ações do modal de pagamento atualizam a página sem recarregar (ver
// enviarSemRecarregar, mais abaixo).
// ============================================================
const CSRF_FORN = <?= json_encode($csrf_token) ?>;

// ---------- utilidades ----------
function moedaParaFloat(v) {
    if (!v) return 0;
    return parseFloat(String(v).replace(/\./g, '').replace(',', '.')) || 0;
}
// Máscara de moeda BR (1.234,56) — formata sozinho enquanto digita.
function moedaFormatar(digitosBrutos) {
    let digitos = digitosBrutos.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
    if (digitos === '') return '';
    while (digitos.length < 3) digitos = '0' + digitos;
    const centavos = digitos.slice(-2);
    const inteiro  = digitos.slice(0, -2).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return inteiro + ',' + centavos;
}
function brlPt(n) {
    return 'R$ ' + n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function toast(texto, ok = true) {
    let wrap = document.getElementById('toast-wrap');
    if (!wrap) { wrap = document.createElement('div'); wrap.id = 'toast-wrap'; document.body.appendChild(wrap); }
    const el = document.createElement('div');
    el.className = 'toast-item ' + (ok ? 'verde' : 'verm');
    el.innerHTML = '<i class="bi ' + (ok ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill') + '"></i><span></span>';
    el.querySelector('span').textContent = texto;
    wrap.appendChild(el);
    setTimeout(() => { el.style.transition = 'opacity .3s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, ok ? 2600 : 4500);
}

// ---------- filtro por status (Todos/Contratados/Orçamento/Cancelados) ----------
let filtroAtual = 'todos';
function aplicarFiltro() {
    let visiveis = 0;
    document.querySelectorAll('.forn-linha').forEach(function (linha) {
        const mostra = (filtroAtual === 'todos' || linha.dataset.status === filtroAtual);
        linha.style.display = mostra ? '' : 'none';
        if (mostra) visiveis++;
    });
    document.querySelectorAll('#filtro-status-forn button').forEach(b => b.classList.toggle('active', b.dataset.filtro === filtroAtual));
    const msgVazio = document.getElementById('filtro-vazio-msg');
    if (msgVazio) msgVazio.classList.toggle('d-none', visiveis > 0);
}
document.addEventListener('click', function (e) {
    const btn = e.target.closest('#filtro-status-forn button[data-filtro]');
    if (!btn) return;
    filtroAtual = btn.dataset.filtro;
    aplicarFiltro();
});

// ---------- corrigir pagamento: abre o formulário embaixo da linha ----------
document.addEventListener('click', function (e) {
    const editar = e.target.closest('.btn-editar-pgto');
    if (editar) {
        const linha = editar.closest('.linha-pgto');
        const form = linha.querySelector('.form-editar-pgto');
        const abrir = form.hidden;
        linha.closest('.historico-pgto-modal').querySelectorAll('.form-editar-pgto').forEach(f => { f.hidden = true; });
        form.hidden = !abrir;
        if (abrir) form.querySelector('input[name="valor_pagamento_edit"]').focus();
        return;
    }
    const cancelar = e.target.closest('.btn-cancelar-edit-pgto');
    if (cancelar) cancelar.closest('.form-editar-pgto').hidden = true;
});

// ---------- máscara de moeda e aviso de excesso no registrar ----------
function checarExcesso(form) {
    const input = form.querySelector('.input-valor-pagamento');
    const aviso = form.querySelector('.aviso-valor-excede');
    if (!input || !aviso) return false;
    const restante = parseFloat(form.dataset.restante) || 0;
    const valor = moedaParaFloat(input.value);
    const excede = valor > restante && restante >= 0;
    input.classList.toggle('is-invalid', excede);
    if (excede) aviso.textContent = 'Esse valor ultrapassa em ' + brlPt(valor - restante) + ' o quanto ainda falta (' + brlPt(restante) + ').';
    return excede;
}
document.addEventListener('input', function (e) {
    if (!e.target.classList.contains('input-moeda')) return;
    e.target.value = moedaFormatar(e.target.value);
    const form = e.target.closest('.form-registrar-pagamento');
    if (form && e.target.classList.contains('input-valor-pagamento')) checarExcesso(form);
});

// ---------- data compacta: mostra a data escolhida em dd/mm/aaaa ----------
document.addEventListener('change', function (e) {
    const input = e.target;
    if (!input.classList || !input.classList.contains('data-compacta-input')) return;
    const txt = input.closest('.data-compacta').querySelector('.data-compacta-txt');
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(input.value || '');
    if (m) txt.textContent = m[3] + '/' + m[2] + '/' + m[1];
});

// ---------- seletor de arquivo próprio: mostra o nome escolhido ----------
document.addEventListener('change', function (e) {
    const input = e.target;
    if (input.type !== 'file' || !input.closest('.seletor-arquivo')) return;
    const label = input.closest('.seletor-arquivo');
    const nome = label.querySelector('.seletor-arquivo-nome');
    if (!nome.dataset.padrao) nome.dataset.padrao = nome.textContent;
    const arq = input.files && input.files[0];
    nome.textContent = arq ? arq.name : nome.dataset.padrao;
    label.classList.toggle('tem-arquivo', !!arq);
});

// ---------- visualizar comprovante DENTRO do modal de pagamento ----------
// O histórico e o formulário dão lugar à imagem/PDF, com "Voltar" pra retornar.
function visorDoModal(modalContent) {
    let visor = modalContent.querySelector('.visor-comprovante-inline');
    if (visor) return visor;
    visor = document.createElement('div');
    visor.className = 'visor-comprovante-inline';
    visor.innerHTML =
        '<div class="visor-topo">' +
            '<button type="button" class="btn btn-sm btn-light border visor-voltar"><i class="bi bi-arrow-left me-1"></i> Voltar</button>' +
            '<span class="visor-nome text-truncate"></span>' +
            '<a class="btn btn-sm btn-outline-primary visor-baixar" download target="_blank" rel="noopener"><i class="bi bi-download"></i></a>' +
        '</div>' +
        '<div class="visor-area"></div>' +
        '<form method="POST" class="visor-excluir px-3 pb-3 text-center" hidden onsubmit="return confirm(\'Excluir este comprovante? O pagamento continua registrado, só o arquivo sai (depois dá pra anexar o correto).\');">' +
            '<input type="hidden" name="csrf_token">' +
            '<input type="hidden" name="remover_comprovante_pagamento" value="1">' +
            '<input type="hidden" name="id_pagamento">' +
            '<button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i> Excluir este comprovante</button>' +
        '</form>';
    modalContent.querySelector('.modal-header').insertAdjacentElement('afterend', visor);
    return visor;
}
function fecharVisor(modalContent) {
    modalContent.classList.remove('vendo-comprovante');
    const area = modalContent.querySelector('.visor-comprovante-inline .visor-area');
    if (area) area.innerHTML = ''; // para de carregar a imagem/PDF
}
document.addEventListener('click', function (e) {
    const voltar = e.target.closest('.visor-voltar');
    if (voltar) { fecharVisor(voltar.closest('.modal-content')); return; }

    const btn = e.target.closest('.btn-ver-comprovante');
    if (!btn) return;
    const modalContent = btn.closest('.modal-content');
    if (!modalContent) { window.open(btn.dataset.arquivo, '_blank'); return; }
    const visor = visorDoModal(modalContent);
    const arquivo = btn.dataset.arquivo;
    const nome = btn.dataset.nome || 'comprovante';
    visor.querySelector('.visor-nome').textContent = nome;
    const baixar = visor.querySelector('.visor-baixar');
    baixar.href = arquivo;
    baixar.setAttribute('download', nome);
    const area = visor.querySelector('.visor-area');
    area.innerHTML = '';
    if (btn.dataset.imagem === '1') {
        const img = document.createElement('img');
        img.src = arquivo; img.alt = nome; img.className = 'visor-img';
        area.appendChild(img);
    } else {
        // PDF: embutido + link pra abrir inteiro (o Safari do iPhone só
        // mostra a 1ª página de um PDF dentro de iframe).
        const frame = document.createElement('iframe');
        frame.src = arquivo; frame.className = 'visor-pdf'; frame.title = nome;
        area.appendChild(frame);
        const abrir = document.createElement('a');
        abrir.href = arquivo; abrir.target = '_blank'; abrir.rel = 'noopener';
        abrir.className = 'btn btn-sm btn-outline-secondary mt-2';
        abrir.innerHTML = '<i class="bi bi-box-arrow-up-right me-1"></i> Abrir PDF inteiro';
        area.appendChild(abrir);
    }
    const formExcluir = visor.querySelector('.visor-excluir');
    formExcluir.hidden = btn.dataset.podeExcluir !== '1';
    formExcluir.querySelector('[name="csrf_token"]').value = CSRF_FORN;
    formExcluir.querySelector('[name="id_pagamento"]').value = btn.dataset.pgtoId || '';
    modalContent.classList.add('vendo-comprovante');
    // Quem rola é o próprio .modal (e ele pode estar rolado até o campo de
    // valor, que tem autofocus) — volta pro topo pra aparecer o "Voltar".
    const modalEl = modalContent.closest('.modal');
    if (modalEl) modalEl.scrollTop = 0;
});
// Ao fechar o modal, volta pro estado normal (histórico + formulário).
document.querySelectorAll('.modal').forEach(function (m) {
    m.addEventListener('hidden.bs.modal', function () {
        const mc = m.querySelector('.modal-content');
        if (mc && mc.classList.contains('vendo-comprovante')) fecharVisor(mc);
    });
});

// ---------- envio dos formulários ----------
// Ações do modal de pagamento (registrar, corrigir, apagar, anexar e excluir
// comprovante) vão em segundo plano: o modal continua aberto e só as partes
// que mudaram são trocadas. Os demais formulários (novo/editar/excluir
// fornecedor) seguem o envio normal. Em todos, o valor mascarado (1.234,56)
// vira decimal puro (1234.56) antes de sair — sem isso o (float) do PHP lia só
// até a primeira vírgula.
function converterMoedas(form) {
    form.querySelectorAll('.input-moeda').forEach(function (input) {
        if (input.value) input.value = moedaParaFloat(input.value).toFixed(2);
    });
}
function ehAcaoDoModalPagamento(form) {
    return !!form.closest('.modal[id^="modalPagamentoForn"]');
}
async function enviarSemRecarregar(form, submitter) {
    const modalAberto = form.closest('.modal');
    const botao = submitter || form.querySelector('[type="submit"]') || form.querySelector('label.btn');
    const htmlOriginal = botao ? botao.innerHTML : '';
    // Botão com o arquivo dentro (o "Anexar") não pode ter o conteúdo trocado
    // antes de montar o FormData — senão o arquivo some junto.
    const dados = new FormData(form);
    if (submitter && submitter.name) dados.append(submitter.name, submitter.value);
    if (botao) { botao.classList.add('disabled'); botao.setAttribute('aria-busy', 'true'); botao.innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; }
    try {
        const resp = await fetch(form.getAttribute('action') || window.location.href, { method: 'POST', body: dados, credentials: 'same-origin' });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const doc = new DOMParser().parseFromString(await resp.text(), 'text/html');
        const novoConteudo = doc.getElementById('conteudo-forn');
        if (!novoConteudo) throw new Error('resposta inesperada');

        // Mensagens do servidor viram aviso rápido (em vez do alerta no topo).
        const avisos = [...novoConteudo.querySelectorAll('.alert')].map(a => {
            a.remove();
            return { ok: a.classList.contains('alert-success'), texto: a.textContent.replace(/\s+/g, ' ').trim() };
        });

        // Mantém aberto o resumo de valores (celular) se estava aberto.
        const resumoAberto = document.getElementById('resumoValoresMobile')?.classList.contains('show');
        document.getElementById('conteudo-forn').innerHTML = novoConteudo.innerHTML;
        if (resumoAberto) {
            document.getElementById('resumoValoresMobile')?.classList.add('show');
            document.querySelector('.resumo-valores-toggle')?.classList.remove('collapsed');
        }
        aplicarFiltro();

        // Troca o conteúdo de cada modal pelo atualizado (o elemento .modal
        // continua o mesmo — o que está aberto segue aberto).
        doc.querySelectorAll('.modal[id]').forEach(function (novo) {
            const velho = document.getElementById(novo.id);
            const mcNovo = novo.querySelector('.modal-content');
            const mcVelho = velho && velho.querySelector('.modal-content');
            if (!mcNovo || !mcVelho) return;
            mcVelho.innerHTML = mcNovo.innerHTML;
            mcVelho.classList.remove('vendo-comprovante');
        });
        if (modalAberto) modalAberto.scrollTop = 0;

        avisos.forEach(a => toast(a.texto, a.ok));
    } catch (err) {
        if (botao && document.contains(botao)) { botao.classList.remove('disabled'); botao.removeAttribute('aria-busy'); botao.innerHTML = htmlOriginal; }
        toast('Não foi possível salvar agora. Confira a conexão e tente de novo.', false);
    }
}
document.addEventListener('submit', function (e) {
    const form = e.target;
    if (e.defaultPrevented) return; // cancelado por um confirm() do próprio formulário

    if (form.classList.contains('form-registrar-pagamento') && checarExcesso(form)) {
        const valor = moedaParaFloat(form.querySelector('.input-valor-pagamento').value);
        const restante = parseFloat(form.dataset.restante) || 0;
        const ok = confirm(
            'O valor informado (' + brlPt(valor) + ') é maior que o quanto ainda falta pagar (' + brlPt(restante) + ').\n\n' +
            'Se continuar, o pagamento será registrado apenas até completar o valor total (' + brlPt(restante) + ').\n\n' +
            'Deseja continuar mesmo assim?'
        );
        if (!ok) { e.preventDefault(); return; }
    }
    converterMoedas(form);
    if (ehAcaoDoModalPagamento(form)) {
        e.preventDefault();
        enviarSemRecarregar(form, e.submitter);
    }
});

// Chegou por uma notificação ("enviou um comprovante"): abre direto o modal de
// pagamentos daquele fornecedor.
<?php if ($abrir_pagamento_forn > 0): ?>
document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('modalPagamentoForn<?= $abrir_pagamento_forn ?>');
    if (el) bootstrap.Modal.getOrCreateInstance(el).show();
});
<?php endif; ?>

</script>
</body>
</html>