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

// Arquivos compartilhados de cada fornecedor (prints de orçamento, comprovantes,
// contrato...) — enviados tanto pelos noivos quanto pela equipe e visíveis pros
// dois lados. enviado_por guarda o papel ('Noivos'/'Assessoria') pra mostrar
// quem mandou e pra notificar o outro lado (notificacoes.inc.php / noivos.php).
// No histórico de pagamentos, o comprovante também passa a registrar quem
// enviou e quando (a data do pagamento pode ser retroativa, a do envio não).
if (!schema_ja_verificado('fornecedores_anexos_v1')) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fornecedores_anexos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fornecedor_id INT NOT NULL,
        tipo VARCHAR(20) NOT NULL DEFAULT 'outro',
        arquivo VARCHAR(255) NOT NULL,
        nome_original VARCHAR(255) NULL,
        extensao VARCHAR(10) NULL,
        enviado_por VARCHAR(20) NOT NULL,
        enviado_por_nome VARCHAR(120) NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_forn_anexo (fornecedor_id),
        INDEX idx_forn_anexo_envio (enviado_por, criado_em),
        CONSTRAINT fk_forn_anexo FOREIGN KEY (fornecedor_id) REFERENCES fornecedores_evento(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    try { $pdo->query("SELECT comprovante_enviado_por FROM fornecedores_pagamentos LIMIT 1"); }
    catch (Exception $e) {
        $pdo->exec("ALTER TABLE fornecedores_pagamentos
            ADD COLUMN comprovante_enviado_por VARCHAR(20) NULL,
            ADD COLUMN comprovante_enviado_por_nome VARCHAR(120) NULL,
            ADD COLUMN comprovante_enviado_em DATETIME NULL");
    }
    marcar_schema_verificado('fornecedores_anexos_v1');
}

// Tipos de arquivo compartilhado (rótulo + ícone)
const TIPOS_ANEXO_FORNECEDOR = [
    'orcamento'   => ['Orçamento',   'bi-file-earmark-text'],
    'comprovante' => ['Comprovante', 'bi-receipt'],
    'contrato'    => ['Contrato',    'bi-file-earmark-check'],
    'outro'       => ['Outro',       'bi-paperclip'],
];
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

    // ENVIAR ARQUIVOS (prints de orçamento, comprovantes, contrato...) — noivos
    // e equipe enviam, os dois lados veem. Aceita vários de uma vez.
    if (isset($_POST['enviar_anexo_fornecedor'])) {
        $id_forn = (int)($_POST['id_fornecedor'] ?? 0);
        $tipo = $_POST['tipo_anexo'] ?? 'outro';
        if (!array_key_exists($tipo, TIPOS_ANEXO_FORNECEDOR)) { $tipo = 'outro'; }

        $chk = $pdo->prepare("SELECT id FROM fornecedores_evento WHERE id = ? AND evento_id = ?");
        $chk->execute([$id_forn, $evento_id]);

        // $_FILES de campo múltiplo vem "transposto" (name[], tmp_name[]...) — remonta 1 array por arquivo.
        $arquivos = [];
        $campo = $_FILES['arquivos_anexo'] ?? null;
        if ($campo && is_array($campo['name'])) {
            foreach ($campo['name'] as $i => $nome) {
                if (($campo['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $arquivos[] = ['name' => $nome, 'tmp_name' => $campo['tmp_name'][$i], 'error' => $campo['error'][$i], 'size' => $campo['size'][$i]];
            }
        }

        if (!$chk->fetch()) {
            $_SESSION['msg_erro'] = "Fornecedor não encontrado.";
        } elseif (empty($arquivos)) {
            $_SESSION['msg_erro'] = "Escolha pelo menos um arquivo para enviar.";
        } else {
            $enviados = 0;
            $erros = [];
            $ins = $pdo->prepare("INSERT INTO fornecedores_anexos (fornecedor_id, tipo, arquivo, nome_original, extensao, enviado_por, enviado_por_nome) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($arquivos as $arq) {
                $res = salvar_arquivo_enviado($arq, 'forn_anexo', $evento_id);
                if (!$res['ok']) { $erros[] = $res['msg']; continue; }
                $ins->execute([$id_forn, $tipo, $res['arquivo'], $res['nome_original'], $res['extensao'], $papel_usuario, $nome_usuario]);
                $enviados++;
            }
            if ($enviados > 0) {
                $_SESSION['msg_sucesso'] = $enviados === 1 ? "Arquivo enviado! Já está visível para todos do evento." : "$enviados arquivos enviados! Já estão visíveis para todos do evento.";
            }
            if ($erros) { $_SESSION['msg_erro'] = implode(' ', $erros); }
        }
        header("Location: fornecedores_evento.php?id=" . $evento_id . "&arquivos=" . $id_forn); exit;
    }

    // EXCLUIR ARQUIVO — a equipe pode excluir qualquer um; os noivos, só os que eles mesmos enviaram.
    if (isset($_POST['excluir_anexo_fornecedor'])) {
        $anexo_id = (int)($_POST['id_anexo'] ?? 0);
        $sel = $pdo->prepare("SELECT a.*, f.id AS forn_id FROM fornecedores_anexos a INNER JOIN fornecedores_evento f ON f.id = a.fornecedor_id WHERE a.id = ? AND f.evento_id = ?");
        $sel->execute([$anexo_id, $evento_id]);
        $anexo = $sel->fetch();
        $id_forn_ret = $anexo ? (int)$anexo['forn_id'] : 0;

        if (!$anexo) {
            $_SESSION['msg_erro'] = "Arquivo não encontrado.";
        } elseif ($eh_noivos && $anexo['enviado_por'] !== 'Noivos') {
            $_SESSION['msg_erro'] = "Só a assessoria pode excluir arquivos que ela enviou.";
        } else {
            $pdo->prepare("DELETE FROM fornecedores_anexos WHERE id = ?")->execute([$anexo_id]);
            $caminho = './uploads/' . basename($anexo['arquivo']);
            if (is_file($caminho)) { @unlink($caminho); }
            $_SESSION['msg_sucesso'] = "Arquivo excluído.";
        }
        header("Location: fornecedores_evento.php?id=" . $evento_id . ($id_forn_ret ? "&arquivos=" . $id_forn_ret : '')); exit;
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
        header("Location: fornecedores_evento.php?id=" . $evento_id . ($id_forn_ret ? "&arquivos=" . $id_forn_ret : '')); exit;
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
            $_SESSION['msg_sucesso'] = "Comprovante excluído. O pagamento continua registrado — use \"Anexar\" em Arquivos para enviar o correto.";
        }
        header("Location: fornecedores_evento.php?id=" . $evento_id . ($id_forn_ret ? "&arquivos=" . $id_forn_ret : '')); exit;
    }

    // EXCLUIR
    if (isset($_POST['excluir_fornecedor'])) {
        $id_forn = (int)$_POST['id_fornecedor'];
        // O banco apaga anexos/pagamentos em cascata, mas os arquivos em
        // uploads/ ficariam órfãos — junta a lista antes de excluir.
        $arquivos_forn_excluir = [];
        $selArq = $pdo->prepare("SELECT a.arquivo FROM fornecedores_anexos a INNER JOIN fornecedores_evento f ON f.id = a.fornecedor_id WHERE f.id = ? AND f.evento_id = ?
                                 UNION ALL
                                 SELECT p.comprovante_arquivo FROM fornecedores_pagamentos p INNER JOIN fornecedores_evento f ON f.id = p.fornecedor_id WHERE f.id = ? AND f.evento_id = ? AND p.comprovante_arquivo IS NOT NULL");
        $selArq->execute([$id_forn, $evento_id, $id_forn, $evento_id]);
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

// Arquivos compartilhados de cada fornecedor: os enviados na área "Arquivos"
// + os comprovantes anexados aos pagamentos, numa lista só (mais novo primeiro).
$arquivos_fornecedor = [];
if (!empty($lista_fornecedores)) {
    $stmt_anx = $pdo->prepare("SELECT * FROM fornecedores_anexos WHERE fornecedor_id IN ($ph) ORDER BY criado_em DESC, id DESC");
    $stmt_anx->execute($ids_forn);
    foreach ($stmt_anx->fetchAll() as $a) {
        $arquivos_fornecedor[$a['fornecedor_id']][] = [
            'origem'     => 'anexo',
            'id'         => (int)$a['id'],
            'tipo'       => $a['tipo'],
            'arquivo'    => $a['arquivo'],
            'nome'       => $a['nome_original'] ?: 'arquivo',
            'extensao'   => $a['extensao'],
            'por'        => $a['enviado_por'],
            'por_nome'   => $a['enviado_por_nome'],
            'quando'     => $a['criado_em'],
        ];
    }
    foreach ($historico_pagamentos as $fid => $pgtos) {
        foreach ($pgtos as $p) {
            if (empty($p['comprovante_arquivo'])) continue;
            $arquivos_fornecedor[$fid][] = [
                'origem'     => 'pagamento',
                'id'         => (int)$p['id'],
                'tipo'       => 'comprovante',
                'arquivo'    => $p['comprovante_arquivo'],
                'nome'       => $p['comprovante_nome_original'] ?: 'comprovante',
                'extensao'   => $p['comprovante_extensao'],
                'por'        => $p['comprovante_enviado_por'] ?? null,
                'por_nome'   => $p['comprovante_enviado_por_nome'] ?? null,
                'quando'     => $p['comprovante_enviado_em'] ?? $p['criado_em'],
                'valor'      => (float)$p['valor'],
                'data_pgto'  => $p['criado_em'],
            ];
        }
    }
    foreach ($arquivos_fornecedor as &$lista_arq) {
        usort($lista_arq, fn($a, $b) => strcmp($b['quando'], $a['quando']));
    }
    unset($lista_arq);
}

// Quem enviou, em texto curto: "Vocês" (casal vendo o que o casal mandou),
// "Você" (o próprio membro da equipe), "Noivos" ou "Assessoria (Nome)"
function rotulo_enviado_por(?string $por, ?string $por_nome, string $papel_usuario, string $nome_usuario): string {
    if (!$por) return '';
    if ($por === 'Noivos') return $papel_usuario === 'Noivos' ? 'Vocês' : 'Noivos';
    if ($papel_usuario === 'Assessoria' && $por_nome === $nome_usuario) return 'Você';
    return 'Assessoria' . ($por_nome ? ' (' . $por_nome . ')' : '');
}

// Abre direto a área de arquivos de um fornecedor (link das notificações e
// retorno após enviar/excluir) — ?arquivos=ID
$abrir_arquivos_forn = (int)($_GET['arquivos'] ?? 0);

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
    <link rel="stylesheet" href="css/estilo.css?v=16">
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
        }
        @media (max-width: 420px) {
            .navbar .navbar-brand img { height: 32px; }
            .navbar .btn span.nav-btn-label { display: none; }
        }

        /* ---- ARQUIVOS COMPARTILHADOS DO FORNECEDOR ---- */
        .grade-arquivos-forn {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: .75rem;
        }
        @media (max-width: 575.98px) {
            .grade-arquivos-forn { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
        }
        .arquivo-forn-item {
            border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden;
            background: #fff; display: flex; flex-direction: column;
        }
        .arquivo-forn-thumb {
            position: relative; display: block; width: 100%;
            aspect-ratio: 1 / 1; padding: 0; border: 0;
            background: #f1f5f9; cursor: zoom-in; overflow: hidden;
        }
        .arquivo-forn-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .25s ease; }
        .arquivo-forn-thumb:hover img { transform: scale(1.04); }
        .arquivo-forn-pdf {
            width: 100%; height: 100%; display: flex; flex-direction: column;
            align-items: center; justify-content: center; color: #dc2626; font-size: 2.4rem;
        }
        .arquivo-forn-pdf small { font-size: .7rem; font-weight: 700; color: #64748b; }
        .arquivo-forn-tipo {
            position: absolute; top: .4rem; left: .4rem;
            font-size: .62rem; font-weight: 700; padding: .15rem .45rem;
            border-radius: 999px; background: rgba(255,255,255,.92); color: #334155;
            box-shadow: 0 1px 3px rgba(0,0,0,.12);
        }
        .arquivo-tipo-orcamento   { color: #b45309; }
        .arquivo-tipo-comprovante { color: #15803d; }
        .arquivo-tipo-contrato    { color: #1d4ed8; }
        .arquivo-forn-info { padding: .45rem .55rem .5rem; font-size: .7rem; line-height: 1.35; min-width: 0; }
        .arquivo-forn-info .fw-semibold { font-size: .74rem; color: #1e293b; }
        .arquivo-forn-excluir { font-size: .7rem; text-decoration: none; }
        .btn-arquivos-forn .badge { font-size: .6rem; }
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
        <div class="col-6 col-lg">
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
        <div class="col-6 col-lg">
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
        <div class="col-6 col-lg">
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
        <div class="col-6 col-lg">
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
        <div class="col-6 col-lg">
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
                        $qtdArquivos = count($arquivos_fornecedor[$forn['id']] ?? []); $qtdSemComprovante = count(array_filter($historico_pagamentos[$forn['id']] ?? [], fn($pg) => empty($pg['comprovante_arquivo'])));
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
                                    <button class="btn btn-sm btn-outline-primary btn-arquivos-forn" data-bs-toggle="modal" data-bs-target="#modalArquivosForn<?= $forn['id'] ?>" title="Arquivos e comprovantes (compartilhados com <?= $eh_noivos ? 'a assessoria' : 'os noivos' ?>)">
                                        <i class="bi bi-paperclip"></i> Arquivos<?php if ($qtdArquivos > 0): ?><span class="badge rounded-pill bg-primary ms-1"><?= $qtdArquivos ?></span><?php endif; ?><?php if ($qtdSemComprovante > 0): ?><span class="badge rounded-pill bg-warning text-dark ms-1" title="<?= $qtdSemComprovante ?> pagamento(s) sem comprovante"><i class="bi bi-exclamation-lg"></i><?= $qtdSemComprovante ?></span><?php endif; ?>
                                    </button>
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
                                $qtdArquivos = count($arquivos_fornecedor[$forn['id']] ?? []); $qtdSemComprovante = count(array_filter($historico_pagamentos[$forn['id']] ?? [], fn($pg) => empty($pg['comprovante_arquivo'])));
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
                                        <button class="btn btn-sm btn-outline-primary btn-arquivos-forn" data-bs-toggle="modal" data-bs-target="#modalArquivosForn<?= $forn['id'] ?>" title="Arquivos e comprovantes (compartilhados com <?= $eh_noivos ? 'a assessoria' : 'os noivos' ?>)">
                                            <i class="bi bi-paperclip"></i><?php if ($qtdArquivos > 0): ?><span class="badge rounded-pill bg-primary ms-1"><?= $qtdArquivos ?></span><?php endif; ?><?php if ($qtdSemComprovante > 0): ?><span class="badge rounded-pill bg-warning text-dark ms-1" title="<?= $qtdSemComprovante ?> pagamento(s) sem comprovante"><i class="bi bi-exclamation-lg"></i><?= $qtdSemComprovante ?></span><?php endif; ?>
                                        </button>
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

<?php $lista_arquivos_forn = $arquivos_fornecedor[$forn['id']] ?? []; ?>
<div class="modal fade modal-arquivos-forn" id="modalArquivosForn<?= $forn['id'] ?>" data-fornecedor-id="<?= $forn['id'] ?>" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <div style="min-width:0;">
          <h5 class="modal-title text-truncate"><i class="bi bi-paperclip"></i> Arquivos — <?= htmlspecialchars($forn['servico']) ?></h5>
          <small class="text-muted"><i class="bi bi-people-fill me-1"></i>Compartilhado entre os noivos e a assessoria</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
          <form method="POST" enctype="multipart/form-data" class="form-enviar-anexo border rounded-3 p-3 mb-3 bg-light">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
              <input type="hidden" name="enviar_anexo_fornecedor" value="1">
              <input type="hidden" name="id_fornecedor" value="<?= $forn['id'] ?>">
              <div class="row g-2 align-items-end">
                  <div class="col-12 col-sm-4">
                      <label class="form-label fw-bold small mb-1">Tipo</label>
                      <select name="tipo_anexo" class="form-select form-select-sm">
                          <?php $tipo_padrao = $forn['status'] === 'Orçamento' ? 'orcamento' : 'comprovante'; ?>
                          <?php foreach (TIPOS_ANEXO_FORNECEDOR as $tipoChave => [$tipoRotulo]): ?>
                          <option value="<?= $tipoChave ?>" <?= $tipoChave === $tipo_padrao ? 'selected' : '' ?>><?= $tipoRotulo ?></option>
                          <?php endforeach; ?>
                      </select>
                  </div>
                  <div class="col-12 col-sm-8">
                      <label class="form-label fw-bold small mb-1">Prints ou PDFs</label>
                      <input type="file" name="arquivos_anexo[]" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,image/*,application/pdf" multiple required>
                  </div>
              </div>
              <div class="d-flex justify-content-between align-items-center gap-2 mt-2">
                  <small class="text-muted">Pode escolher vários de uma vez. <?= $eh_noivos ? 'A assessoria' : 'Os noivos' ?> vai ver e receber um aviso.</small>
                  <button type="submit" class="btn btn-primary btn-sm flex-shrink-0"><i class="bi bi-cloud-arrow-up me-1"></i> Enviar</button>
              </div>
          </form>

          <?php $pgtos_sem_comprovante = array_values(array_filter($historico_pagamentos[$forn['id']] ?? [], fn($pg) => empty($pg['comprovante_arquivo']))); ?>
          <?php if ($pgtos_sem_comprovante): ?>
          <div class="pgtos-sem-comprovante border border-warning-subtle rounded-3 p-2 px-3 mb-3">
              <div class="fw-bold small text-warning-emphasis mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i> Pagamentos sem comprovante</div>
              <?php foreach ($pgtos_sem_comprovante as $pgto): ?>
              <div class="d-flex justify-content-between align-items-center gap-2 py-1">
                  <span class="small"><i class="bi bi-calendar3 text-muted me-1"></i><?= date('d/m/Y', strtotime($pgto['criado_em'])) ?> · <strong class="text-success">R$ <?= number_format((float)$pgto['valor'], 2, ',', '.') ?></strong></span>
                  <form method="POST" enctype="multipart/form-data" class="d-inline form-anexar-comprovante">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                      <input type="hidden" name="anexar_comprovante_pagamento" value="1">
                      <input type="hidden" name="id_pagamento" value="<?= (int)$pgto['id'] ?>">
                      <label class="btn btn-sm btn-outline-primary py-0 px-2 mb-0" title="Anexar comprovante a este pagamento">
                          <i class="bi bi-plus-lg"></i> Anexar
                          <input type="file" name="comprovante_existente" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,image/*,application/pdf" class="d-none" onchange="if (this.files.length) this.form.submit();">
                      </label>
                  </form>
              </div>
              <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <?php if (empty($lista_arquivos_forn)): ?>
              <div class="text-center text-muted py-4">
                  <i class="bi bi-images fs-2 d-block mb-2"></i>
                  Nenhum arquivo ainda.<br><small>Envie o print do orçamento, o comprovante de um pagamento ou o contrato.</small>
              </div>
          <?php else: ?>
              <div class="grade-arquivos-forn">
                  <?php foreach ($lista_arquivos_forn as $arq):
                      $ehImg   = in_array($arq['extensao'], EXTENSOES_IMAGEM_ANEXO, true);
                      $url     = 'uploads/' . rawurlencode($arq['arquivo']);
                      [$tipoRotulo, $tipoIcone] = TIPOS_ANEXO_FORNECEDOR[$arq['tipo']] ?? TIPOS_ANEXO_FORNECEDOR['outro'];
                      $porTxt  = rotulo_enviado_por($arq['por'], $arq['por_nome'], $papel_usuario, $nome_usuario);
                      $podeExcluir = !$eh_noivos || $arq['por'] === 'Noivos';
                  ?>
                  <div class="arquivo-forn-item">
                      <button type="button" class="arquivo-forn-thumb btn-ver-comprovante"
                              data-arquivo="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
                              data-nome="<?= htmlspecialchars($arq['nome'], ENT_QUOTES, 'UTF-8') ?>"
                              data-imagem="<?= $ehImg ? '1' : '0' ?>"
                              title="Ver <?= htmlspecialchars($arq['nome'], ENT_QUOTES, 'UTF-8') ?>">
                          <?php if ($ehImg): ?>
                              <img src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy" decoding="async">
                          <?php else: ?>
                              <span class="arquivo-forn-pdf"><i class="bi bi-file-earmark-pdf-fill"></i><small>PDF</small></span>
                          <?php endif; ?>
                          <span class="arquivo-forn-tipo arquivo-tipo-<?= htmlspecialchars($arq['tipo']) ?>"><i class="bi <?= $tipoIcone ?>"></i> <?= $tipoRotulo ?></span>
                      </button>
                      <div class="arquivo-forn-info">
                          <div class="text-truncate fw-semibold" title="<?= htmlspecialchars($arq['nome'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($arq['nome']) ?></div>
                          <?php if ($arq['origem'] === 'pagamento'): ?>
                          <div class="text-success">Pgto de R$ <?= number_format($arq['valor'], 2, ',', '.') ?> · <?= date('d/m/Y', strtotime($arq['data_pgto'])) ?></div>
                          <?php endif; ?>
                          <div class="text-muted">
                              <?= $porTxt !== '' ? htmlspecialchars($porTxt) . ' · ' : '' ?><?= date('d/m/Y H:i', strtotime($arq['quando'])) ?>
                          </div>
                          <?php if ($podeExcluir): ?>
                          <?php $ehPgto = $arq['origem'] === 'pagamento'; ?>
                          <form method="POST" class="d-inline" onsubmit="return confirm('<?= $ehPgto ? 'Excluir este comprovante? O pagamento continua registrado, só o arquivo sai (depois dá pra anexar o correto aqui mesmo).' : 'Excluir este arquivo? Ele some para todos do evento.' ?>');">
                              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                              <?php if ($ehPgto): ?>
                              <input type="hidden" name="remover_comprovante_pagamento" value="1">
                              <input type="hidden" name="id_pagamento" value="<?= $arq['id'] ?>">
                              <?php else: ?>
                              <input type="hidden" name="excluir_anexo_fornecedor" value="1">
                              <input type="hidden" name="id_anexo" value="<?= $arq['id'] ?>">
                              <?php endif; ?>
                              <button type="submit" class="btn btn-link btn-sm text-danger p-0 arquivo-forn-excluir"><i class="bi bi-trash"></i> Excluir</button>
                          </form>
                          <?php endif; ?>
                      </div>
                  </div>
                  <?php endforeach; ?>
              </div>
          <?php endif; ?>
      </div>
      <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalPagamentoForn<?= $forn['id'] ?>" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title"><i class="bi bi-cash-coin"></i> Registrar Pagamento</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <?php $forn_restante = max(0.0, (float)$forn['valor'] - (float)($forn['valor_pago'] ?? 0)); ?>
      <form method="POST" enctype="multipart/form-data" class="form-registrar-pagamento" data-restante="<?= $forn_restante ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
          <input type="hidden" name="registrar_pagamento" value="1">
          <input type="hidden" name="id_fornecedor" value="<?= $forn['id'] ?>">
          <div class="modal-body">
              <p class="text-muted small mb-3">
                  <?= htmlspecialchars($forn['servico']) ?> — <?= htmlspecialchars($forn['nome']) ?><br>
                  Já pago: <strong>R$ <?= number_format((float)($forn['valor_pago'] ?? 0), 2, ',', '.') ?></strong>
                  de R$ <?= number_format((float)$forn['valor'], 2, ',', '.') ?>
                  · Falta: <strong class="text-danger">R$ <?= number_format($forn_restante, 2, ',', '.') ?></strong>
              </p>
              <div class="row">
                  <div class="col-6 mb-1">
                      <label class="form-label fw-bold small">Valor deste pagamento (R$) *</label>
                      <input type="text" inputmode="decimal" name="valor_pagamento" class="form-control input-moeda input-valor-pagamento" placeholder="Ex: 200,00" required autofocus>
                      <div class="invalid-feedback aviso-valor-excede"></div>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
// modal de Arquivos — fecha ele primeiro (Bootstrap não empilha modal bem)
// e só abre o de visualização depois que o de Arquivos terminou de sumir.
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

    // Modal de onde o arquivo foi aberto (Arquivos) — reaberto ao
    // fechar a visualização, pra pessoa continuar vendo os outros arquivos.
    let modalParaVoltar = null;

    document.querySelectorAll('.btn-ver-comprovante').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const modalAtual = btn.closest('.modal');
            if (modalAtual) {
                modalParaVoltar = modalAtual;
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
        if (modalParaVoltar) {
            bootstrap.Modal.getOrCreateInstance(modalParaVoltar).show();
            modalParaVoltar = null;
        }
    });
}

// Chegou por uma notificação ("enviou um arquivo") ou acabou de enviar/excluir:
// abre direto a área de arquivos daquele fornecedor.
<?php if ($abrir_arquivos_forn > 0): ?>
document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('modalArquivosForn<?= $abrir_arquivos_forn ?>');
    if (el) bootstrap.Modal.getOrCreateInstance(el).show();
});
<?php endif; ?>

// Enviar arquivos: trava o botão e mostra "Enviando..." (prints grandes pelo
// celular podem demorar alguns segundos e a pessoa clicava de novo).
document.querySelectorAll('.form-enviar-anexo').forEach(function (form) {
    form.addEventListener('submit', function () {
        const btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.classList.add('disabled');
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Enviando...';
        }
    });
});

// Máscara de moeda BR (1.234,56) — formata sozinho enquanto digita, sem
// precisar digitar o ponto/vírgula na mão.
function moedaParaFloat(v) {
    if (!v) return 0;
    return parseFloat(String(v).replace(/\./g, '').replace(',', '.')) || 0;
}
function moedaFormatar(digitosBrutos) {
    let digitos = digitosBrutos.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
    if (digitos === '') return '';
    while (digitos.length < 3) digitos = '0' + digitos;
    const centavos = digitos.slice(-2);
    const inteiro  = digitos.slice(0, -2).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return inteiro + ',' + centavos;
}
document.querySelectorAll('.input-moeda').forEach(function (input) {
    input.addEventListener('input', function () {
        input.value = moedaFormatar(input.value);
    });
});
// Ao enviar cada form (exceto o de pagamento, que já cuida disso abaixo por
// causa da confirmação de excesso), troca o valor mascarado (1.234,56) pelo
// decimal puro (1234.56) que o PHP espera — sem isso, (float) do PHP lia só
// até a primeira vírgula.
document.querySelectorAll('form').forEach(function (form) {
    if (form.classList.contains('form-registrar-pagamento')) return;
    const camposMoeda = form.querySelectorAll('.input-moeda');
    if (!camposMoeda.length) return;
    form.addEventListener('submit', function () {
        camposMoeda.forEach(function (input) {
            if (input.value) input.value = moedaParaFloat(input.value).toFixed(2);
        });
    });
});

// Avisa quando o valor do pagamento ultrapassa o quanto ainda falta pagar —
// o backend já limita ao valor restante (nunca deixa "valor_pago" passar de
// "valor"), mas fazer isso silenciosamente confundia: a pessoa digitava um
// valor e o sistema salvava outro, menor, sem avisar por quê.
function brlPt(n) {
    return 'R$ ' + n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
document.querySelectorAll('.form-registrar-pagamento').forEach(function (form) {
    const restante = parseFloat(form.dataset.restante) || 0;
    const input    = form.querySelector('.input-valor-pagamento');
    const aviso    = form.querySelector('.aviso-valor-excede');
    if (!input || !aviso) return;

    function checarExcesso() {
        const valor = moedaParaFloat(input.value);
        const excede = valor > restante && restante >= 0;
        input.classList.toggle('is-invalid', excede);
        if (excede) {
            aviso.textContent = 'Esse valor ultrapassa em ' + brlPt(valor - restante) + ' o quanto ainda falta (' + brlPt(restante) + ').';
        }
        return excede;
    }

    input.addEventListener('input', checarExcesso);

    form.addEventListener('submit', function (e) {
        if (!checarExcesso()) {
            if (input.value) input.value = moedaParaFloat(input.value).toFixed(2);
            return;
        }
        e.preventDefault();
        const valor = moedaParaFloat(input.value);
        const confirmado = confirm(
            'O valor informado (' + brlPt(valor) + ') é maior que o quanto ainda falta pagar (' + brlPt(restante) + ').\n\n' +
            'Se continuar, o pagamento será registrado apenas até completar o valor total (' + brlPt(restante) + ').\n\n' +
            'Deseja continuar mesmo assim?'
        );
        if (confirmado) {
            input.value = valor.toFixed(2);
            form.submit();
        }
    });
});
</script>
</body>
</html>