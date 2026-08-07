<?php
session_start();
require_once 'sessao_timeout.inc.php';
verificar_sessao_ativa();

require_once 'conexao.php';

if (!isset($_SESSION['usuario_tipo']) || !in_array($_SESSION['usuario_tipo'], ['admin', 'assistente'])) {
    header("Location: index.php?sessao_expirada=1");
    exit;
}
$is_admin = ($_SESSION['usuario_tipo'] === 'admin');

// Evita que o navegador guarde esta página (dados financeiros/de convidados) em cache,
// o que já causou telas desatualizadas aparecerem depois de mudanças no sistema.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

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

/* ============================================================
   AUTO-CONFIGURAÇÃO DO BANCO DE DADOS
   ============================================================ */

// 1. Criação automática da tabela notas_evento
try {
    $pdo->query("SELECT 1 FROM notas_evento LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE notas_evento (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            evento_id     INT          NOT NULL,
            titulo        VARCHAR(255) NOT NULL,
            conteudo      TEXT         NULL,
            cor           VARCHAR(50)  DEFAULT 'amarelo',
            autor         VARCHAR(100) DEFAULT 'Assessoria',
            criado_em     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_nota_evento (evento_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}

// 1b. Coluna de prazo por tarefa do checklist
try { $pdo->query("SELECT data_prazo FROM checklist LIMIT 1"); }
catch (Exception $e) { $pdo->exec("ALTER TABLE checklist ADD COLUMN data_prazo DATE NULL"); }

// 1c. Colunas de rastreio de conclusão (quem/quando) — usadas pelo sino de notificações
try { $pdo->query("SELECT concluido_em FROM checklist LIMIT 1"); }
catch (Exception $e) { $pdo->exec("ALTER TABLE checklist ADD COLUMN concluido_em DATETIME NULL"); }
try { $pdo->query("SELECT concluido_por FROM checklist LIMIT 1"); }
catch (Exception $e) { $pdo->exec("ALTER TABLE checklist ADD COLUMN concluido_por VARCHAR(20) NULL"); }

require_once 'notificacoes.inc.php';

// 2. Colunas de convidados
try { $pdo->query("SELECT nomes_acompanhantes FROM convidados LIMIT 1"); }
catch (Exception $e) { $pdo->exec("ALTER TABLE convidados ADD COLUMN nomes_acompanhantes VARCHAR(255) NULL"); }

try { $pdo->query("SELECT idades_filhos FROM convidados LIMIT 1"); }
catch (Exception $e) { $pdo->exec("ALTER TABLE convidados ADD COLUMN idades_filhos VARCHAR(255) NULL"); }

// 3. Coluna de controle de pagamento parcial
try { $pdo->query("SELECT valor_pago FROM suppliers_evento LIMIT 1"); }
catch (Exception $e) { 
    try { $pdo->query("SELECT valor_pago FROM fornecedores_evento LIMIT 1"); }
    catch (Exception $ex) { $pdo->exec("ALTER TABLE fornecedores_evento ADD COLUMN valor_pago DECIMAL(10,2) NOT NULL DEFAULT 0.00"); }
}

// 4. Tabela de músicas com status e momento
try {
    $pdo->query("SELECT 1 FROM musicas_evento LIMIT 1");
    
    try { $pdo->query("SELECT status FROM musicas_evento LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE musicas_evento ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'sugestao'"); }

    // A coluna "status" já existia como TINYINT(1) em bancos antigos, incompatível com os
    // valores 'sugestao'/'confirmada' usados pelo sistema — corrige o tipo se necessário.
    $tipoStatusMusica = $pdo->query("
        SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'musicas_evento' AND COLUMN_NAME = 'status'
    ")->fetchColumn();
    if ($tipoStatusMusica !== 'varchar') {
        $pdo->exec("ALTER TABLE musicas_evento MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'sugestao'");
        $pdo->exec("UPDATE musicas_evento SET status = 'sugestao' WHERE status NOT IN ('sugestao', 'confirmada')");
    }
    
    try { $pdo->query("SELECT momento FROM musicas_evento LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE musicas_evento ADD COLUMN momento VARCHAR(150) NOT NULL DEFAULT 'Livre / Sem Momento Definido'"); }
    
    try { $pdo->query("SELECT artista FROM musicas_evento LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE musicas_evento ADD COLUMN artista VARCHAR(255) NULL"); }
    
    try { $pdo->query("SELECT link FROM musicas_evento LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE musicas_evento ADD COLUMN link VARCHAR(500) NULL"); }

} catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE musicas_evento (
            id        INT AUTO_INCREMENT PRIMARY KEY,
            evento_id INT          NOT NULL,
            titulo    VARCHAR(255) NOT NULL,
            artista   VARCHAR(255) NULL,
            link      VARCHAR(500) NULL,
            momento   VARCHAR(150) NOT NULL DEFAULT 'Livre / Sem Momento Definido',
            status    VARCHAR(20)  NOT NULL DEFAULT 'sugestao',
            criado_em TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_musica_evento (evento_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}

// 5. Garantir estrutura da tabela checklist_comentarios
try {
    $pdo->query("SELECT 1 FROM checklist_comentarios LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE checklist_comentarios (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            checklist_id  INT          NULL,
            evento_id     INT          NULL,
            etapa_nome    VARCHAR(255) NULL,
            autor         VARCHAR(100) DEFAULT 'Assessoria',
            comentario    TEXT         NOT NULL,
            criado_em     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}

// 6. Validar colunas específicas em checklist_comentarios caso ela já existisse vazia ou incompleta
try { $pdo->query("SELECT evento_id FROM checklist_comentarios LIMIT 1"); }
catch (Exception $e) { $pdo->exec("ALTER TABLE checklist_comentarios ADD COLUMN evento_id INT NULL"); }

try { $pdo->query("SELECT etapa_nome FROM checklist_comentarios LIMIT 1"); }
catch (Exception $e) { $pdo->exec("ALTER TABLE checklist_comentarios ADD COLUMN etapa_nome VARCHAR(255) NULL"); }

try { $pdo->query("SELECT checklist_id FROM checklist_comentarios LIMIT 1"); }
catch (Exception $e) { $pdo->exec("ALTER TABLE checklist_comentarios ADD COLUMN checklist_id INT NULL"); }

try { $pdo->query("SELECT criado_em FROM checklist_comentarios LIMIT 1"); }
catch (Exception $e) { $pdo->exec("ALTER TABLE checklist_comentarios ADD COLUMN criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); }


/* ============================================================
   ID DO EVENTO — valida que é inteiro positivo
   ============================================================ */
$evento_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$evento_id) {
    header("Location: painel_admin.php");
    exit;
}

$link_confirmacao_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$link_confirmacao_base   = $link_confirmacao_scheme . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/');
$link_confirmacao_url    = $link_confirmacao_base . '/confirmar.php?evento=' . $evento_id;

/* ============================================================
   HELPER
   ============================================================ */
function json_out(array $data): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* Retorna [classe_css, texto] do badge de prazo de uma tarefa */
function badge_prazo(?string $data_prazo, bool $done): array {
    if (empty($data_prazo)) return ['sem', 'Sem prazo'];
    if ($done) return ['futuro', date('d/m/Y', strtotime($data_prazo))];
    $dias = (int)floor((strtotime($data_prazo) - strtotime(date('Y-m-d'))) / 86400);
    $txt  = date('d/m/Y', strtotime($data_prazo));
    if ($dias < 0)  return ['atrasada', $txt . ' (atrasada)'];
    if ($dias <= 3) return ['proximo', $txt];
    return ['futuro', $txt];
}

/* ============================================================
   MOMENTOS DA CELEBRAÇÃO
   ============================================================ */
$momentos_casamento = [
    'Cerimônia · Entrada dos Padrinhos',
    'Cerimônia · Entrada das Madrinhas',
    'Cerimônia · Entrada dos Pajens / Floristas',
    'Cerimônia · Entrada da Noiva',
    'Cerimônia · Assinatura do Registro',
    'Cerimônia · Saída dos Noivos',
    'Recepção · Primeira Dança (Valsa)',
    'Recepção · Valsa com os Pais',
    'Recepção · Corte do Bolo',
    'Recepção · Entrada na Festa',
    'Recepção · Jantar / Coquetel',
    'Festa · Hora da Dança',
    'Festa · Encerramento',
    'Livre / Sem Momento Definido',
];

/* ============================================================
   POST HANDLERS
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verificar_csrf();

    $ajax = isset($_POST['is_ajax']);

    // 1. Adicionar fornecedor
    if (isset($_POST['adicionar_fornecedor'])) {
        $nome    = trim($_POST['nome_fornecedor']    ?? '');
        $servico = trim($_POST['servico_fornecedor'] ?? '');
        $contato = trim($_POST['contato_fornecedor'] ?? '');
        $status  = trim($_POST['status_fornecedor']  ?? 'Orçamento');
        $valor   = ($is_admin && !empty($_POST['valor_fornecedor']))
                    ? (float)str_replace(['.', ','], ['', '.'], $_POST['valor_fornecedor'])
                    : 0.00;
        if ($nome !== '' && $servico !== '') {
            $pdo->prepare("INSERT INTO fornecedores_evento (evento_id, nome, servico, contato, status, valor, valor_pago) VALUES (?, ?, ?, ?, ?, ?, 0)")
                ->execute([$evento_id, $nome, $servico, $contato, $status, $valor]);
            $_SESSION['msg_sucesso'] = "Fornecedor adicionado com sucesso!";
        }
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 2. Importar padrão (Apenas Admin)
    if (isset($_POST['gerar_padrao'])) {
        if (!$is_admin) { $_SESSION['msg_erro'] = "Acesso negado."; header("Location: gerenciar.php?id=$evento_id"); exit; }

        $tipo    = trim($_POST['tipo_padrao'] ?? '');
        $stmt    = $pdo->prepare("SELECT * FROM checklist_modelos WHERE tipo_padrao = ?");
        $stmt->execute([$tipo]);
        $modelos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($modelos)) {
            $ins = $pdo->prepare("INSERT INTO checklist (evento_id, etapa, tarefa, descricao, origem, status, checado) VALUES (?, ?, ?, ?, 'Assessoria', 'pendente', 0)");
            foreach ($modelos as $m) { $ins->execute([$evento_id, $m['etapa'], $m['tarefa'], $m['descricao']]); }
            $_SESSION['msg_sucesso'] = "Cronograma importado com sucesso!";
        } else {
            $_SESSION['msg_erro'] = "Nenhum modelo encontrado para o tipo: " . htmlspecialchars($tipo);
        }
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 3. Adicionar tarefa manual (Apenas Admin)
    if (isset($_POST['adicionar_manual'])) {
        if (!$is_admin) { $_SESSION['msg_erro'] = "Acesso negado."; header("Location: gerenciar.php?id=$evento_id"); exit; }

        $etapa      = trim($_POST['etapa']       ?? '');
        $tarefa     = trim($_POST['tarefa']      ?? '');
        $descricao  = trim($_POST['descricao'] ?? '');
        $data_prazo = trim($_POST['data_prazo'] ?? '');
        $data_prazo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_prazo) ? $data_prazo : null;
        if ($etapa !== '' && $tarefa !== '') {
            $pdo->prepare("INSERT INTO checklist (evento_id, etapa, tarefa, descricao, origem, status, checado, data_prazo) VALUES (?, ?, ?, ?, 'Assessoria', 'pendente', 0, ?)")
                ->execute([$evento_id, $etapa, $tarefa, $descricao, $data_prazo]);
            $_SESSION['msg_sucesso'] = "Tarefa adicionada!";
        }
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // Renomear Etapa (Admin)
    if (isset($_POST['renomear_etapa'])) {
        if (!$is_admin) { $_SESSION['msg_erro'] = "Acesso negado."; header("Location: gerenciar.php?id=$evento_id"); exit; }
        $antiga = trim($_POST['etapa_antiga'] ?? '');
        $nova   = trim($_POST['etapa_nova'] ?? '');
        if ($antiga !== '' && $nova !== '') {
            $pdo->prepare("UPDATE checklist SET etapa = ? WHERE etapa = ? AND evento_id = ?")->execute([$nova, $antiga, $evento_id]);
            $pdo->prepare("UPDATE checklist_comentarios SET etapa_nome = ? WHERE etapa_nome = ? AND evento_id = ?")->execute([$nova, $antiga, $evento_id]);
            $_SESSION['msg_sucesso'] = "Etapa renomeada!";
        }
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 4. Editar tarefa (Apenas Admin)
    if (isset($_POST['editar_tarefa'])) {
        if (!$is_admin) { $_SESSION['msg_erro'] = "Acesso negado."; header("Location: gerenciar.php?id=$evento_id"); exit; }

        $id         = (int)($_POST['id_tarefa']      ?? 0);
        $etapa      = trim($_POST['etapa_edit']     ?? '');
        $tarefa     = trim($_POST['tarefa_edit']    ?? '');
        $descricao  = trim($_POST['descricao_edit'] ?? '');
        $data_prazo = trim($_POST['data_prazo_edit'] ?? '');
        $data_prazo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_prazo) ? $data_prazo : null;
        if ($id > 0 && $etapa !== '' && $tarefa !== '') {
            $pdo->prepare("UPDATE checklist SET etapa = ?, tarefa = ?, descricao = ?, data_prazo = ? WHERE id = ? AND evento_id = ?")
                ->execute([$etapa, $tarefa, $descricao, $data_prazo, $id, $evento_id]);
            $_SESSION['msg_sucesso'] = "Tarefa atualizada!";
        }
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 5. Toggle status tarefa (AJAX)
    if (isset($_POST['alternar_status'])) {
        $id    = (int)($_POST['id_tarefa']   ?? 0);
        $atual = $_POST['status_atual']      ?? '0';
        $novo  = ($atual == 1 || $atual === 'concluido') ? 0 : 1;
        if ($id > 0) {
            $pdo->prepare("UPDATE checklist SET status = ?, checado = ?, concluido_em = " . ($novo ? "NOW()" : "NULL") . ", concluido_por = ? WHERE id = ? AND evento_id = ?")
                ->execute([$novo ? 'concluido' : 'pendente', $novo, $novo ? 'Assessoria' : null, $id, $evento_id]);
        }
        if ($ajax) json_out(['ok' => true, 'novo' => $novo]);
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 6. Excluir tarefa (AJAX) (Apenas Admin)
    if (isset($_POST['excluir_tarefa'])) {
        if (!$is_admin) {
            if ($ajax) json_out(['ok' => false, 'msg' => 'Acesso negado.']);
            header("Location: gerenciar.php?id=$evento_id"); exit;
        }
        $id = (int)($_POST['id_tarefa'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM checklist WHERE id = ? AND evento_id = ?")
                ->execute([$id, $evento_id]);
        }
        if ($ajax) json_out(['ok' => true]);
        $_SESSION['msg_sucesso'] = "Tarefa removida!";
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 7. Comentário de tarefa (AJAX)
    if (isset($_POST['adicionar_comentario'])) {
        $id    = (int)($_POST['id_tarefa']         ?? 0);
        $texto = trim($_POST['texto_comentario']   ?? '');
        // A coluna `autor` é ENUM('Assessoria','Noivos') — grava o papel, não o nome da pessoa.
        $autor_nome = 'Assessoria';
        if ($id > 0 && $texto !== '') {
            $pdo->prepare("INSERT INTO checklist_comentarios (checklist_id, autor, comentario) VALUES (?, ?, ?)")
                ->execute([$id, $autor_nome, $texto]);
            if ($ajax) json_out([
                'ok'    => true,
                'autor' => htmlspecialchars($autor_nome, ENT_QUOTES, 'UTF-8'),
                'texto' => htmlspecialchars($texto,     ENT_QUOTES, 'UTF-8'),
            ]);
            $_SESSION['msg_sucesso'] = "Comentário adicionado!";
        }
        if (!$ajax) { header("Location: gerenciar.php?id=$evento_id"); exit; }
        exit;
    }

    // 8. Comentário de etapa (AJAX)
    if (isset($_POST['comentario_etapa_admin'])) {
        $etapa = trim($_POST['etapa_nome']            ?? '');
        $texto = trim($_POST['novo_comentario_etapa']   ?? '');
        // A coluna `autor` é ENUM('Assessoria','Noivos') — grava o papel, não o nome da pessoa.
        $autor_nome = 'Assessoria';
        if ($etapa !== '' && $texto !== '') {
            $pdo->prepare("INSERT INTO checklist_comentarios (evento_id, etapa_nome, autor, comentario) VALUES (?, ?, ?, ?)")
                ->execute([$evento_id, $etapa, $autor_nome, $texto]);
            if ($ajax) json_out([
                'ok'    => true,
                'autor' => htmlspecialchars($autor_nome, ENT_QUOTES, 'UTF-8'),
                'texto' => htmlspecialchars($texto,     ENT_QUOTES, 'UTF-8'),
            ]);
        }
        if (!$ajax) { header("Location: gerenciar.php?id=$evento_id"); exit; }
        exit;
    }

    // 9. Limpar todo checklist (Apenas Admin)
    if (isset($_POST['excluir_todo_checklist'])) {
        if (!$is_admin) { $_SESSION['msg_erro'] = "Acesso negado."; header("Location: gerenciar.php?id=$evento_id"); exit; }
        $pdo->prepare("DELETE FROM checklist WHERE evento_id = ?")->execute([$evento_id]);
        $_SESSION['msg_sucesso'] = "Checklist apagado!";
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 10. Adicionar convidado
    if (isset($_POST['adicionar_convidado_admin'])) {
        $nome       = trim($_POST['nome_convidado']       ?? '');
        $fone       = trim($_POST['telefone_convidado']   ?? '');
        $cat        = trim($_POST['categoria_convidado']  ?? 'Outros');
        $acomp_qtd  = max(0, (int)($_POST['acompanhantes']       ?? 0));
        $acomp_nms  = trim($_POST['nomes_acompanhantes']  ?? '');
        $filhos_qtd = max(0, (int)($_POST['filhos']              ?? 0));
        $filhos_ids = trim($_POST['idades_filhos']        ?? '');
        if ($nome !== '') {
            $pdo->prepare("INSERT INTO convidados (evento_id, nome, telefone, categoria, acompanhantes, filhos, confirmado, nomes_acompanhantes, idades_filhos) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)")
                ->execute([$evento_id, $nome, $fone, $cat, $acomp_qtd, $filhos_qtd, $acomp_nms, $filhos_ids]);
            $_SESSION['msg_sucesso'] = "Convidado adicionado!";
        }
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 11. Toggle convidado (AJAX)
    if (isset($_POST['toggle_convidado'])) {
        $id   = (int)($_POST['convidado_id']  ?? 0);
        $novo = (int)($_POST['status_atual']  ?? 0) === 1 ? 0 : 1;
        if ($id > 0) {
            $pdo->prepare("UPDATE convidados SET confirmado = ? WHERE id = ? AND evento_id = ?")
                ->execute([$novo, $id, $evento_id]);
        }
        if ($ajax) json_out(['ok' => true, 'novo' => $novo]);
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 12. Excluir convidado (AJAX)
    if (isset($_POST['excluir_convidado'])) {
        $id  = (int)($_POST['convidado_id'] ?? 0);
        $row = null;
        if ($id > 0) {
            $chk = $pdo->prepare("SELECT confirmado FROM convidados WHERE id = ? AND evento_id = ?");
            $chk->execute([$id, $evento_id]);
            $row = $chk->fetch();
            $pdo->prepare("DELETE FROM convidados WHERE id = ? AND evento_id = ?")
                ->execute([$id, $evento_id]);
        }
        if ($ajax) json_out(['ok' => true, 'era_conf' => $row ? (int)$row['confirmado'] : 0]);
        $_SESSION['msg_sucesso'] = "Convidado removido!";
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 13. Editar locais
    if (isset($_POST['editar_locais'])) {
        $local_cer  = trim($_POST['local_cerimonia'] ?? '');
        $tem_festa  = (int)($_POST['tem_festa']      ?? 0);
        $local_festa = $tem_festa === 1 ? trim($_POST['local_festa'] ?? '') : null;
        $pdo->prepare("UPDATE eventos SET local_cerimonia = ?, tem_festa = ?, local_festa = ? WHERE id = ?")
            ->execute([$local_cer, $tem_festa, $local_festa, $evento_id]);
        $_SESSION['msg_sucesso'] = "Locais atualizados!";
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 14. Atualizar valor pago de um fornecedor (AJAX) (Apenas Admin)
    if (isset($_POST['atualizar_valor_pago'])) {
        if (!$is_admin) {
            if ($ajax) json_out(['ok' => false, 'msg' => 'Acesso negado.']);
            header("Location: gerenciar.php?id=$evento_id"); exit;
        }
        $forn_id    = (int)($_POST['fornecedor_id'] ?? 0);
        $valor_pago = (float)($_POST['valor_pago']  ?? 0);
        if ($forn_id > 0) {
            $chk = $pdo->prepare("SELECT valor FROM fornecedores_evento WHERE id = ? AND evento_id = ?");
            $chk->execute([$forn_id, $evento_id]);
            $forn = $chk->fetch();
            if ($forn) {
                $valor_pago = min(max(0.0, $valor_pago), (float)$forn['valor']);
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
        } else {
            if ($ajax) json_out(['ok' => false, 'msg' => 'ID inválido.']);
        }
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 14b. Adicionar pagamento (soma ao valor já pago) de um fornecedor (AJAX) (Apenas Admin)
    if (isset($_POST['adicionar_pagamento'])) {
        if (!$is_admin) {
            if ($ajax) json_out(['ok' => false, 'msg' => 'Acesso negado.']);
            header("Location: gerenciar.php?id=$evento_id"); exit;
        }
        $forn_id   = (int)($_POST['fornecedor_id'] ?? 0);
        $valor_add = (float)($_POST['valor_pago']  ?? 0);
        if ($forn_id > 0 && $valor_add > 0) {
            $chk = $pdo->prepare("SELECT valor, valor_pago FROM fornecedores_evento WHERE id = ? AND evento_id = ?");
            $chk->execute([$forn_id, $evento_id]);
            $forn = $chk->fetch();
            if ($forn) {
                $novo_pago = min((float)$forn['valor'], (float)($forn['valor_pago'] ?? 0) + $valor_add);
                $pdo->prepare("UPDATE fornecedores_evento SET valor_pago = ? WHERE id = ? AND evento_id = ?")
                    ->execute([$novo_pago, $forn_id, $evento_id]);
                if ($ajax) json_out([
                    'ok'          => true,
                    'valor_pago'  => $novo_pago,
                    'valor_total' => (float)$forn['valor'],
                    'valor_rest'  => (float)$forn['valor'] - $novo_pago,
                ]);
            } else {
                if ($ajax) json_out(['ok' => false, 'msg' => 'Fornecedor não encontrado.']);
            }
        } else {
            if ($ajax) json_out(['ok' => false, 'msg' => 'Informe um valor de pagamento maior que zero.']);
        }
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 15. Salvar / editar nota (AJAX)
    if (isset($_POST['salvar_nota'])) {
        $nota_id  = (int)($_POST['nota_id']     ?? 0);
        $titulo   = trim($_POST['titulo_nota']   ?? '');
        $conteudo = trim($_POST['conteudo_nota'] ?? '');
        $cores_ok = ['amarelo', 'verde', 'azul', 'rosa', 'cinza'];
        $cor      = in_array($_POST['cor_nota'] ?? '', $cores_ok, true) ? $_POST['cor_nota'] : 'amarelo';
        if ($titulo !== '') {
            if ($nota_id > 0) {
                $pdo->prepare("UPDATE notas_evento SET titulo=?, conteudo=?, cor=?, atualizado_em=NOW() WHERE id=? AND evento_id=?")
                    ->execute([$titulo, $conteudo, $cor, $nota_id, $evento_id]);
                $ret_id = $nota_id;
            } else {
                $autor_nome = $_SESSION['usuario_nome'] ?? 'Assessoria';
                $pdo->prepare("INSERT INTO notas_evento (evento_id, titulo, conteudo, cor, autor) VALUES (?,?,?,?,?)")
                    ->execute([$evento_id, $titulo, $conteudo, $cor, $autor_nome]);
                $ret_id = (int)$pdo->lastInsertId();
            }
            if ($ajax) json_out([
                'ok'         => true,
                'id'         => $ret_id,
                'novo'       => $nota_id === 0,
                'titulo'     => htmlspecialchars($titulo,   ENT_QUOTES, 'UTF-8'),
                'conteudo'   => htmlspecialchars($conteudo, ENT_QUOTES, 'UTF-8'),
                'cor'        => $cor,
                'atualizado' => date('d/m/Y \à\s H:i'),
            ]);
        } else {
            if ($ajax) json_out(['ok' => false, 'msg' => 'Informe um título para a nota.']);
        }
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 16. Excluir nota (AJAX)
    if (isset($_POST['excluir_nota'])) {
        $nota_id = (int)($_POST['nota_id'] ?? 0);
        if ($nota_id > 0) {
            $pdo->prepare("DELETE FROM notas_evento WHERE id=? AND evento_id=?")->execute([$nota_id, $evento_id]);
        }
        if ($ajax) json_out(['ok' => true]);
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 17. Adicionar música
    if (isset($_POST['adicionar_musica'])) {
        $titulo  = trim($_POST['titulo_musica']  ?? '');
        $artista = trim($_POST['artista_musica'] ?? '');
        $link    = trim($_POST['link_musica']    ?? '');
        $momento = trim($_POST['momento_musica'] ?? 'Livre / Sem Momento Definido');

        // Valida protocolo do link
        if ($link !== '' && !preg_match('/^https?:\/\//i', $link)) {
            $link = 'https://' . $link;
        }

        if ($titulo !== '') {
            $pdo->prepare("INSERT INTO musicas_evento (evento_id, titulo, artista, link, momento, status) VALUES (?, ?, ?, ?, ?, 'sugestao')")
                ->execute([$evento_id, $titulo, $artista, $link, $momento]);
            $new_id = (int)$pdo->lastInsertId();

            if ($ajax) {
                json_out([
                    'ok'      => true,
                    'id'      => $new_id,
                    'titulo'  => htmlspecialchars($titulo,  ENT_QUOTES, 'UTF-8'),
                    'artista' => htmlspecialchars($artista, ENT_QUOTES, 'UTF-8'),
                    'link'    => htmlspecialchars($link,    ENT_QUOTES, 'UTF-8'),
                    'momento' => htmlspecialchars($momento, ENT_QUOTES, 'UTF-8'),
                    'status'  => 'sugestao',
                ]);
            }
            $_SESSION['msg_sucesso'] = "Música sugerida!";
        } else {
            if ($ajax) json_out(['ok' => false, 'msg' => 'Informe o título da música.']);
        }
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 18. Confirmar / desconfirmar música (AJAX)
    if (isset($_POST['confirmar_musica'])) {
        $musica_id   = (int)($_POST['musica_id']    ?? 0);
        $novo_status = trim($_POST['novo_status']    ?? 'confirmada');
        if (!in_array($novo_status, ['sugestao', 'confirmada'], true)) {
            if ($ajax) json_out(['ok' => false, 'msg' => 'Status inválido.']);
            header("Location: gerenciar.php?id=$evento_id"); exit;
        }
        if ($musica_id > 0) {
            $pdo->prepare("UPDATE musicas_evento SET status = ? WHERE id = ? AND evento_id = ?")
                ->execute([$novo_status, $musica_id, $evento_id]);
        }
        if ($ajax) json_out(['ok' => true, 'novo_status' => $novo_status]);
        $_SESSION['msg_sucesso'] = $novo_status === 'confirmada' ? 'Música confirmada!' : 'Música voltou para sugestões.';
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 19. Excluir música (AJAX)
    if (isset($_POST['excluir_musica'])) {
        $musica_id = (int)($_POST['musica_id'] ?? 0);
        if ($musica_id > 0) {
            $pdo->prepare("DELETE FROM musicas_evento WHERE id = ? AND evento_id = ?")
                ->execute([$musica_id, $evento_id]);
        }
        if ($ajax) json_out(['ok' => true]);
        $_SESSION['msg_sucesso'] = "Música removida!";
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

} // fim do bloco POST

/* ============================================================
   CARREGAMENTO DE DADOS
   ============================================================ */

// Notas do evento
$rs_notas = $pdo->prepare("SELECT * FROM notas_evento WHERE evento_id = ? ORDER BY criado_em DESC");
$rs_notas->execute([$evento_id]);
$lista_notas = $rs_notas->fetchAll();
$total_notas = count($lista_notas);

// Músicas do evento — separadas por status
$rs_mus = $pdo->prepare("SELECT * FROM musicas_evento WHERE evento_id = ? ORDER BY momento ASC, id ASC");
$rs_mus->execute([$evento_id]);
$lista_musicas       = $rs_mus->fetchAll();
$total_musicas       = count($lista_musicas);
$musicas_sugeridas   = [];
$musicas_confirmadas = [];
foreach ($lista_musicas as $m) {
    if ($m['status'] === 'confirmada') {
        $musicas_confirmadas[$m['momento']][] = $m;
    } else {
        $musicas_sugeridas[$m['momento']][] = $m;
    }
}
$cnt_sug  = array_sum(array_map('count', $musicas_sugeridas));
$cnt_conf = array_sum(array_map('count', $musicas_confirmadas));

// Evento
$s = $pdo->prepare("SELECT e.*, c.nome, c.email, c.telefone, c.cpf FROM eventos e INNER JOIN clientes c ON e.cliente_id = c.id WHERE e.id = ?");
$s->execute([$evento_id]);
$evento = $s->fetch();
if (!$evento) { die("Evento não encontrado."); }

// Fornecedores contratados
$rs = $pdo->prepare("SELECT * FROM fornecedores_evento WHERE evento_id = ? AND status = 'Contratado' ORDER BY servico ASC");
$rs->execute([$evento_id]);
$lista_forn = $rs->fetchAll();

$total_forn          = 0.0;
$total_forn_pago     = 0.0;
$total_forn_restante = 0.0;

foreach ($lista_forn as $f) {
    $vt = (float)$f['valor'];
    $vp = (float)($f['valor_pago'] ?? 0);
    $total_forn          += $vt;
    $total_forn_pago     += $vp;
    $total_forn_restante += ($vt - $vp);
}
$total_forn_restante = max(0.0, $total_forn_restante);
$pct_pago_geral = $total_forn > 0 ? round($total_forn_pago / $total_forn * 100) : 0;

// Convidados
$rs2 = $pdo->prepare("SELECT * FROM convidados WHERE evento_id = ? ORDER BY nome ASC");
$rs2->execute([$evento_id]);
$lista_conv = $rs2->fetchAll();
$total_conf = 0; $total_pend = 0;
$conv_grupos = [];
foreach ($lista_conv as $c) {
    $c['confirmado'] ? $total_conf++ : $total_pend++;
    $cat = trim($c['categoria']);
    if (empty($cat)) { $cat = 'Outros'; } else { $cat = mb_convert_case($cat, MB_CASE_TITLE, "UTF-8"); }
    if (!isset($conv_grupos[$cat])) { $conv_grupos[$cat] = []; }
    $conv_grupos[$cat][] = $c;
}
ksort($conv_grupos);

// Checklist - Ordenado dinamicamente pela ordem cronológica de criação/importação das etapas
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

// Comentários de tarefas (com fallback de segurança estrutural)
$ids = array_column($lista_checklist, 'id');
$coments_tarefa = [];
if (!empty($ids)) {
    try {
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $rs4 = $pdo->prepare("SELECT * FROM checklist_comentarios WHERE checklist_id IN ($ph) ORDER BY criado_em ASC");
        $rs4->execute($ids);
        foreach ($rs4->fetchAll() as $c) { $coments_tarefa[$c['checklist_id']][] = $c; }
    } catch (Exception $e) {
        // Fallback caso a coluna de ordenação antiga estivesse em cache ou em uso concorrente
        try {
            $rs4 = $pdo->prepare("SELECT * FROM checklist_comentarios WHERE checklist_id IN ($ph) ORDER BY id ASC");
            $rs4->execute($ids);
            foreach ($rs4->fetchAll() as $c) { $coments_tarefa[$c['checklist_id']][] = $c; }
        } catch (Exception $ex) { $coments_tarefa = []; }
    }
}

// Comentários de etapas filtrados pelo evento_id (com tratamento de exceções)
$coments_etapa = [];
try {
    $rs5 = $pdo->prepare("SELECT * FROM checklist_comentarios WHERE evento_id = ? AND etapa_nome IS NOT NULL ORDER BY criado_em ASC");
    $rs5->execute([$evento_id]);
    foreach ($rs5->fetchAll() as $c) { $coments_etapa[$c['etapa_nome']][] = $c; }
} catch (Exception $e) {
    try {
        $rs5 = $pdo->prepare("SELECT * FROM checklist_comentarios WHERE evento_id = ? AND etapa_nome IS NOT NULL ORDER BY id ASC");
        $rs5->execute([$evento_id]);
        foreach ($rs5->fetchAll() as $c) { $coments_etapa[$c['etapa_nome']][] = $c; }
    } catch (Exception $ex) { $coments_etapa = []; }
}

// Agrupamento e progresso do checklist
$passos = []; $prog = [];
$total_g = 0; $conc_g = 0;
foreach ($lista_checklist as $t) {
    $e = $t['etapa'];
    $passos[$e][] = $t; // O PHP vai manter a ordem exata vinda do banco
    if (!isset($prog[$e])) $prog[$e] = ['total' => 0, 'conc' => 0];
    $prog[$e]['total']++;
    $total_g++;
    $done = ($t['status'] === 'concluido' || $t['checado'] == 1);
    if ($done) { $prog[$e]['conc']++; $conc_g++; }
}
$pct_g = $total_g > 0 ? round($conc_g / $total_g * 100) : 0;

// Etapas começam todas fechadas; só abrem quando o usuário clicar.
$etapa_auto_abrir = null;

// Próximas tarefas (pendentes, ordenadas por prazo — sem prazo por último) e contagem de atrasadas
$hoje_str = date('Y-m-d');
$proximas_tarefas = [];
$total_atrasadas = 0;
foreach ($lista_checklist as $t) {
    $done = ($t['status'] === 'concluido' || $t['checado'] == 1);
    if ($done) continue;
    if (!empty($t['data_prazo']) && $t['data_prazo'] < $hoje_str) { $total_atrasadas++; }
    $proximas_tarefas[] = $t;
}
usort($proximas_tarefas, function ($a, $b) {
    $da = $a['data_prazo'] ?: '9999-12-31';
    $db = $b['data_prazo'] ?: '9999-12-31';
    return $da <=> $db;
});
$proximas_tarefas = array_slice($proximas_tarefas, 0, 5);

// Dias para o evento
$hoje = (new DateTime())->setTime(0, 0, 0);
$dev  = (new DateTime($evento['data_evento']))->setTime(0, 0, 0);
$diff = $hoje->diff($dev);
$dias = $diff->invert ? -$diff->days : $diff->days;

// Flash messages
$msg_sucesso = $_SESSION['msg_sucesso'] ?? '';
$msg_erro    = $_SESSION['msg_erro']    ?? '';
unset($_SESSION['msg_sucesso'], $_SESSION['msg_erro']);

// Notificações (atividade dos noivos neste evento)
$notificacoes    = buscar_notificacoes($pdo, $evento_id, 15);
$ultima_vista    = ultima_visualizacao_notificacoes($pdo, $_SESSION['usuario_tipo'], (int)($_SESSION['usuario_id'] ?? 0));
$nao_lidas       = contar_nao_lidas($notificacoes, $ultima_vista);
$notificacoes    = array_values(array_filter($notificacoes, fn($item) => !$ultima_vista || $item['quando'] > $ultima_vista));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gerenciar Evento - Meu Evento PRO</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
  <link rel="stylesheet" href="css/estilo.css?v=8">
  <style>
    /* ---- VARIÁVEL DE RAIO USADA EM VÁRIOS CARDS (estilo.css não a define) ---- */
    :root { --radius: 16px; }

    .nome-noivos-titulo {
      font-size: clamp(1.05rem, 5.5vw, 2rem);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      display: block;
      max-width: 100%;
    }

    /* ---- LINHA DE BOTÕES DO CABEÇALHO: no mobile o sino fica ao lado do
       Exportar PDF e Confirmação de Presença quebra pra linha própria; no
       desktop os dois botões ficam juntos e o sino vai pra extremidade ---- */
    .header-btn-exportar  { order: 1; }
    .header-btn-sino      { order: 2; margin-left: auto; }
    .header-btn-confirmacao { order: 3; flex-basis: 100%; }

    @media (min-width: 768px) {
      .header-btn-confirmacao { order: 2; flex-basis: auto; }
      .header-btn-sino        { order: 3; }
    }

    /* ---- PAGAMENTO FORNECEDOR ---- */
    .forn-pago-row {
      border-top: 1px solid #f1f5f9;
      padding: .55rem .75rem .55rem 1rem;
      display: grid;
      grid-template-columns: 1fr auto auto;
      align-items: center;
      gap: .5rem;
      background: #fff;
      transition: background .15s;
    }
    .forn-pago-row:hover { background: #f8fafc; }
    .forn-pago-row:last-child { border-bottom-left-radius: 6px; border-bottom-right-radius: 6px; }
    .forn-info-nome { font-size: .78rem; font-weight: 700; color: #1e293b; line-height: 1.2; }
    .forn-info-sub  { font-size: .67rem; color: #94a3b8; }
    .forn-valores   { display: flex; flex-direction: column; align-items: flex-end; gap: .1rem; }
    .forn-val-total { font-size: .72rem; color: #64748b; }
    .forn-val-rest  { font-size: .75rem; font-weight: 700; }
    .forn-val-rest.ok  { color: #16a34a; }
    .forn-val-rest.nok { color: #dc2626; }
    .forn-val-pago-linha { font-size: .72rem; font-weight: 700; color: #2563eb; display: flex; align-items: center; gap: .25rem; }
    .forn-btn-editar-pago, .forn-btn-cancelar-edit {
      border: none; background: transparent; color: #94a3b8; padding: 0; font-size: .68rem;
      cursor: pointer; transition: color .15s; line-height: 1;
    }
    .forn-btn-editar-pago:hover  { color: #2563eb; }
    .forn-btn-cancelar-edit:hover { color: #dc2626; }
    .forn-input-wrap { display: flex; align-items: center; gap: .35rem; }
    .forn-input-pago-adm {
      width: 90px; font-size: .75rem; border: 1.5px solid #e2e8f0;
      border-radius: 7px; padding: .28rem .5rem;
      background: #f8fafc; transition: border-color .2s, background .2s;
      text-align: right;
    }
    .forn-input-pago-adm:focus { outline: none; border-color: #22c55e; background: #fff; }
    .forn-btn-salvar {
      font-size: .7rem; font-weight: 700; padding: .28rem .6rem;
      border-radius: 7px; border: none; background: #22c55e; color: #fff;
      cursor: pointer; white-space: nowrap; transition: background .15s, transform .1s;
    }
    .forn-btn-salvar:hover  { background: #16a34a; }
    .forn-btn-salvar:active { transform: scale(.95); }
    .forn-pago-badge {
      font-size: .58rem; font-weight: 700; padding: .22em .55em;
      border-radius: 999px; text-transform: uppercase; letter-spacing: .04em;
    }
    .barra-pag-wrap { height: 5px; background: #dde3ea; border-radius: 999px; overflow: hidden; margin-top: .3rem; box-shadow: inset 0 1px 2px rgba(0,0,0,.08); }
    .barra-pag-fill {
      height: 100%; border-radius: 999px; transition: width .4s ease;
      background: linear-gradient(90deg, #16a34a, #22c55e);
      box-shadow: 0 0 6px rgba(34,197,94,.5);
      position: relative;
      overflow: hidden;
    }
    .barra-pag-fill::after {
      content: '';
      position: absolute; inset: 0;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,.6), transparent);
      background-size: 60% 100%;
      background-repeat: no-repeat;
      animation: barraPagShimmer 1.8s ease-in-out infinite !important;
    }
    @keyframes barraPagShimmer {
      0%   { background-position: -60% 0; }
      100% { background-position: 160% 0; }
    }
    .fin-chip {
      display: flex; flex-direction: column; align-items: center;
      padding: .55rem .7rem; border-radius: 10px; min-width: 70px;
    }
    .fin-chip-label { font-size: .55rem; text-transform: uppercase; letter-spacing: .06em; font-weight: 700; opacity: .75; }
    .fin-chip-val   { font-size: .85rem; font-weight: 800; line-height: 1.1; margin-top: .15rem; white-space: nowrap; }
    .scroll-lista-pequena { max-height: 360px; overflow-y: auto; }
    .scroll-lista-grande  { max-height: 420px; overflow-y: auto; }

    /* ---- BLOCO DE NOTAS ---- */
    .cor-nota-label { cursor: pointer; }
    .cor-nota-label span {
      display: inline-block; width: 22px; height: 22px;
      border-radius: 50%; border: 2.5px solid transparent;
      transition: transform .15s, box-shadow .15s;
    }
    .cor-nota-label:hover span { transform: scale(1.15); }
    .cor-nota-radio:checked + span { transform: scale(1.3); box-shadow: 0 0 0 3px rgba(0,0,0,.18); }
    .nota-card { transition: box-shadow .2s, transform .2s; position: relative; overflow: hidden; }
    .nota-card::before {
      content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
      background: linear-gradient(90deg, rgba(255,255,255,.6), rgba(255,255,255,.1));
    }
    .nota-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,.12) !important; transform: translateY(-2px); }
    .nota-form-input {
      border: 0; background: #f8fafc; border-radius: 8px;
      transition: background .2s, box-shadow .2s;
    }
    .nota-form-input:focus { background: #fff; box-shadow: 0 0 0 3px rgba(250,204,21,.35); outline: none; }
    .nota-linhas {
      background-image: repeating-linear-gradient(transparent, transparent 23px, #e9d5a0 24px);
      background-size: 100% 24px;
    }
    .btn-notas-sidebar {
      background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
      border: 1.5px solid #fcd34d; border-radius: var(--radius);
      transition: box-shadow .2s, transform .15s; display: block;
    }
    .btn-notas-sidebar:hover { box-shadow: 0 6px 18px rgba(253,211,77,.35); transform: translateY(-1px); }
    #grid-notas .nota-card-wrap { animation: notaEntra .3s ease both; }
    @keyframes notaEntra {
      from { opacity: 0; transform: scale(.94) translateY(8px); }
      to   { opacity: 1; transform: scale(1)   translateY(0); }
    }

    /* ---- PLAYLIST / MÚSICAS ---- */
    .btn-musicas-sidebar {
      background: linear-gradient(135deg, var(--color-primary-light) 0%, #e8d2bd 100%);
      border: 1.5px solid #d9b997; border-radius: var(--radius);
      transition: box-shadow .2s, transform .15s;
      display: block; width: 100%; text-align: left; cursor: pointer;
    }
    .btn-musicas-sidebar:hover { box-shadow: 0 6px 18px rgba(169,116,79,.3); transform: translateY(-1px); }
    .musica-item {
      transition: box-shadow .15s, transform .15s;
      animation: notaEntra .3s ease both;
    }
    .musica-item:hover { box-shadow: 0 4px 14px rgba(124,58,237,.15) !important; transform: translateY(-1px); }
    .musica-grupo-header {
      display: flex; align-items: center; gap: .5rem;
      padding: .4rem .6rem; border-radius: 8px; margin-bottom: .5rem;
    }
    .musica-grupo-header .momento-label {
      font-size: .72rem; font-weight: 700;
      text-transform: uppercase; letter-spacing: .04em;
    }
    .musica-grupo-header .cnt-grp-mus {
      font-size: .6rem; color: #fff;
      border-radius: 999px; padding: .1em .5em; font-weight: 700;
    }
    .musica-modal-form input:focus,
    .musica-modal-form select:focus {
      border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124,58,237,.18);
    }

    /* ---- AJUSTE DE SOBREPOSIÇÃO DOS MODAIS E FUNDOS CINZAS ---- */
    .modal { z-index: 1055 !important; }
    .modal-backdrop { z-index: 1050 !important; }
    #modalConfirmar { z-index: 1075 !important; }
    .modal-backdrop:nth-of-type(2) { z-index: 1070 !important; }

    /* ---- CHECKLIST — REDESIGN ---- */
    /* Seta chamando atenção para o usuário clicar e abrir a etapa */
    .chevron-etapa { display: inline-block; animation: chevronBounce 1.6s ease-in-out infinite; }
    .etapa-hdr[aria-expanded="true"] .chevron-etapa { animation: none; transform: rotate(180deg); }
    @keyframes chevronBounce {
      0%, 100% { transform: translateY(0); }
      50%      { transform: translateY(4px); }
    }
    .checklist-title-icon {
      width: 28px; height: 28px; border-radius: 8px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%);
      color: #fff; font-size: .8rem;
      box-shadow: 0 3px 8px rgba(169,116,79,.35);
    }
    .checklist-title-text { letter-spacing: -.3px; color: #1e293b; font-size: 1.1rem; }
    .ring-wrap-canto { position: absolute; right: 1.5rem; bottom: 1.25rem; }
    .checklist-title-band {
      background: linear-gradient(135deg, var(--color-primary-light) 0%, #fff 100%);
      border: 1px solid rgba(169,116,79,.18);
      border-radius: 12px;
      padding: .4rem .7rem;
    }
    .selo-etapa {
      width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: .8rem; color: #fff;
      background: rgba(255,255,255,.15); border: 1.5px solid rgba(255,255,255,.35);
    }
    .selo-etapa.feita { background: #22c55e; border-color: #22c55e; }
    .badge-prazo { font-size: .64rem; font-weight: 700; padding: .22em .6em; border-radius: 999px; white-space: nowrap; }
    .badge-prazo.sem     { background: #f1f5f9; color: #94a3b8; }
    .badge-prazo.futuro  { background: #dbeafe; color: #1d4ed8; }
    .badge-prazo.proximo { background: #fef3c7; color: #b45309; }
    .badge-prazo.atrasada{ background: #fee2e2; color: #dc2626; }
    .checklist-toolbar { background: #fff; border-radius: 12px; padding: .75rem 1rem; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-bottom: 1rem; }
    .checklist-toolbar .sw { position: relative; flex: 1 1 220px; min-width: 180px; }
    .checklist-toolbar .sw .bi-search { position: absolute; left: .7rem; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: .78rem; }
    .checklist-toolbar .sw input { padding-left: 2rem; font-size: .82rem; }
    .card-proximas { border-radius: var(--radius); border: 1.5px solid #fecaca !important; background: #fff5f5; margin-bottom: 1rem; }
    .card-proximas .item-proxima { display: flex; align-items: center; gap: .6rem; padding: .4rem 0; border-bottom: 1px dashed #fecdd3; font-size: .82rem; }
    .card-proximas .item-proxima:last-child { border-bottom: none; }
    .tarefa-row-hidden { display: none !important; }
    .etapa-hidden { display: none !important; }

    /* ---- SINO DE NOTIFICAÇÕES: evita o dropdown estourar a tela em celular ---- */
    @media (max-width: 575.98px) {
      #dropdown-notificacoes .dropdown-menu.show {
        position: fixed !important;
        inset: auto 0.75rem auto 0.75rem !important;
        top: 4.5rem !important;
        transform: none !important;
        width: auto !important;
        max-width: none !important;
      }
    }

    /* ---- AJUSTES GERAIS PARA MOBILE ---- */
    @media (max-width: 767.98px) {
      .navbar .navbar-brand img { height: 32px; }

      .fin-chip { flex: 1 1 0; min-width: 0; padding: .5rem .4rem; }
      .fin-chip-val { font-size: .72rem; }
      .fin-chip-label { font-size: .5rem; }

      .sidebar-sticky { position: static !important; top: auto !important; }

      .etapa-hdr { flex-wrap: wrap; row-gap: .35rem; }
      .etapa-hdr .fw-bold { min-width: 0; }

      .card-proximas .item-proxima .text-truncate { flex-grow: 1; min-width: 0; }

      .musica-grupo-header .momento-label { flex-grow: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

      .musica-item { flex-wrap: wrap; }
      .musica-item .musica-acoes { flex-basis: 100%; justify-content: flex-end; margin-top: .35rem; }

      .forn-pago-row { grid-template-columns: 1fr; row-gap: .35rem; }
      .forn-valores { align-items: flex-start !important; }
      .forn-input-wrap { justify-content: flex-end; }
      .forn-input-pago-adm { width: auto; flex: 1 1 auto; min-width: 0; }

      .checklist-btns-row { gap: .4rem !important; overflow-x: auto; -webkit-overflow-scrolling: touch; }
      .checklist-btns-row .btn { font-size: .78rem; padding: .38rem .65rem; }
      .checklist-btns-row .btn i { font-size: .82rem; }

      .badges-info-evento { gap: .35rem !important; overflow-x: auto; -webkit-overflow-scrolling: touch; }
      .badges-info-evento .badge { font-size: .68rem; padding: .35rem .55rem !important; }
    }
  </style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark shadow-sm">
  <div class="container">
    <span class="navbar-brand mb-0">
      <img src="img/LOGO MEP NAV.svg" alt="Meu Evento PRO" style="height:40px;">
    </span>
    <div class="d-flex align-items-center gap-2">
      <a href="painel_admin.php" class="btn btn-sm btn-outline-light rounded-3">
        <i class="bi bi-arrow-left me-1"></i> Voltar ao Painel
      </a>
    </div>
  </div>
</nav>

<div id="toast-wrap"></div>

<div class="modal fade" id="modalConfirmar" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content border-0 shadow-lg rounded-4 p-3 text-center">
      <div class="py-2">
        <div id="confirm-icon-box" class="mx-auto mb-3 rounded-circle bg-danger bg-opacity-10 d-flex align-items-center justify-content-center" style="width:52px;height:52px;">
          <i id="confirm-icon" class="bi bi-exclamation-triangle-fill text-danger fs-4"></i>
        </div>
        <h6 id="confirm-title" class="fw-bold mb-1">Tem certeza?</h6>
        <p id="confirm-msg" class="text-muted small mb-0">Esta ação não pode ser desfeita.</p>
      </div>
      <div class="d-flex justify-content-center gap-2 mt-3">
        <button class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancelar</button>
        <button id="btn-confirmar" class="btn btn-danger btn-sm px-4 rounded-pill fw-bold">Confirmar</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalLinkConfirmacao" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header border-0 bg-light">
        <h5 class="modal-title fw-bold"><i class="bi bi-envelope-check-fill text-danger me-2"></i> Confirmação de Presença</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <p class="text-muted small mb-3">Envie este link para os convidados. Eles poderão confirmar a presença de toda a família diretamente por ele — sem precisar de login.</p>
        <div class="input-group mb-2">
          <input type="text" id="input-link-confirmacao" class="form-control" value="<?= htmlspecialchars($link_confirmacao_url) ?>" readonly>
          <button class="btn btn-danger fw-bold" type="button" id="btn-copiar-link">
            <i class="bi bi-clipboard-fill me-1"></i> Copiar
          </button>
        </div>
        <a href="<?= htmlspecialchars($link_confirmacao_url) ?>" target="_blank" class="small text-decoration-none">
          <i class="bi bi-box-arrow-up-right me-1"></i> Abrir o link em uma nova aba
        </a>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-secondary btn-sm px-4 rounded-pill fw-bold" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalExportarPdf" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header border-0 bg-light">
        <h5 class="modal-title fw-bold"><i class="bi bi-file-earmark-pdf-fill text-danger me-2"></i> Exportar PDF</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <a class="btn btn-outline-dark w-100 fw-bold mb-3" href="relatorio_pdf.php?id=<?= $evento_id ?>&secoes=todos" target="_blank">
          <i class="bi bi-file-earmark-text me-1"></i> Relatório Completo
        </a>
        <hr>
        <div class="small text-muted fw-bold text-uppercase mb-2" style="font-size:.7rem;">Ou escolha as seções</div>
        <div class="form-check mb-2">
          <input class="form-check-input pdf-secao-check" type="checkbox" value="checklist" id="pdfSecaoChecklist">
          <label class="form-check-label" for="pdfSecaoChecklist"><i class="bi bi-list-check me-1"></i>Checklist</label>
        </div>
        <div class="form-check mb-2">
          <input class="form-check-input pdf-secao-check" type="checkbox" value="convidados" id="pdfSecaoConvidados">
          <label class="form-check-label" for="pdfSecaoConvidados"><i class="bi bi-people me-1"></i>Convidados</label>
        </div>
        <?php if ($is_admin): ?>
        <div class="form-check mb-2">
          <input class="form-check-input pdf-secao-check" type="checkbox" value="fornecedores" id="pdfSecaoFornecedores">
          <label class="form-check-label" for="pdfSecaoFornecedores"><i class="bi bi-cash-stack me-1"></i>Financeiro</label>
        </div>
        <?php endif; ?>
        <div class="form-check mb-2">
          <input class="form-check-input pdf-secao-check" type="checkbox" value="notas" id="pdfSecaoNotas">
          <label class="form-check-label" for="pdfSecaoNotas"><i class="bi bi-journal-text me-1"></i>Notas</label>
        </div>
        <div class="form-check mb-2">
          <input class="form-check-input pdf-secao-check" type="checkbox" value="musicas" id="pdfSecaoMusicas">
          <label class="form-check-label" for="pdfSecaoMusicas"><i class="bi bi-music-note-beamed me-1"></i>Playlist</label>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-secondary btn-sm px-4 rounded-pill fw-bold" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-danger fw-bold rounded-pill px-4" id="btnGerarPdfSecoes">
          <i class="bi bi-download me-1"></i> Gerar PDF
        </button>
      </div>
    </div>
  </div>
</div>

<?php if ($is_admin): ?>
<form id="form-import-com-rec" method="POST" action="?id=<?= $evento_id ?>" hidden>
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
  <input type="hidden" name="gerar_padrao" value="1">
  <input type="hidden" name="tipo_padrao" value="com_recepcao">
</form>
<form id="form-import-sem-rec" method="POST" action="?id=<?= $evento_id ?>" hidden>
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
  <input type="hidden" name="gerar_padrao" value="1">
  <input type="hidden" name="tipo_padrao" value="sem_recepcao">
</form>
<form id="form-limpar-checklist" method="POST" action="?id=<?= $evento_id ?>" hidden>
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
  <input type="hidden" name="excluir_todo_checklist" value="1">
</form>
<?php endif; ?>

<div class="container my-4 my-md-5">

  <div class="card border-0 shadow-sm mb-4" style="border-radius: var(--radius); backdrop-filter: none; animation: none; transform: none;">
    <div class="header-topo p-3 p-md-4" style="position:relative;">
      <div class="d-flex flex-wrap align-items-center gap-2 mb-3 header-top-actions">
        <button type="button" class="btn btn-sm btn-danger rounded-3 header-btn-exportar"
                data-bs-toggle="modal" data-bs-target="#modalExportarPdf">
          <i class="bi bi-file-earmark-pdf-fill me-1"></i> Exportar PDF
        </button>

        <div class="dropdown header-btn-sino" id="dropdown-notificacoes">
          <button class="btn btn-sm btn-outline-light rounded-circle position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="width:40px;height:40px;">
            <i class="bi bi-bell-fill"></i>
            <?php if ($nao_lidas > 0): ?>
              <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.62rem;">
                <?= $nao_lidas > 9 ? '9+' : $nao_lidas ?>
              </span>
            <?php endif; ?>
          </button>
          <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 p-0" style="width:340px;max-height:420px;overflow-y:auto;">
            <div class="px-3 py-2 border-bottom bg-light d-flex justify-content-between align-items-center">
              <span class="fw-bold small text-uppercase text-muted"><i class="bi bi-bell me-1"></i> Notificações do casal</span>
              <button type="button" id="btn-marcar-lidas" class="btn btn-link btn-sm p-0 text-decoration-none">Marcar lidas</button>
            </div>
            <div id="lista-notificacoes">
            <?php if (empty($notificacoes)): ?>
              <div class="text-center text-muted p-4 small">
                <i class="bi bi-inbox fs-3 d-block mb-2"></i> Nenhuma atividade ainda.
              </div>
            <?php else: foreach ($notificacoes as $n): ?>
              <div class="notif-item d-flex align-items-start gap-2 px-3 py-2 border-bottom" style="cursor:pointer;">
                <i class="bi <?= htmlspecialchars($n['icone'], ENT_QUOTES, 'UTF-8') ?> mt-1"></i>
                <div class="flex-fill" style="min-width:0;">
                  <div class="small text-dark"><?= htmlspecialchars($n['texto'], ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="text-muted" style="font-size:.7rem;"><?= tempo_relativo($n['quando']) ?></div>
                </div>
              </div>
            <?php endforeach; endif; ?>
            </div>
          </div>
        </div>

        <button type="button" class="btn btn-sm btn-outline-light rounded-3 header-btn-confirmacao"
                data-bs-toggle="modal" data-bs-target="#modalLinkConfirmacao">
          <i class="bi bi-envelope-check-fill me-1"></i> Confirmação de Presença
        </button>
      </div>

      <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
          <div class="text-white-50 small text-uppercase fw-semibold mb-1" style="letter-spacing:.06em;">
            <i class="bi bi-rings text-warning me-1"></i> Casamento de
          </div>
          <h2 class="fw-bold mb-1 text-white nome-noivos-titulo" style="letter-spacing:-.5px;">
            <?= htmlspecialchars($evento['nome'], ENT_QUOTES, 'UTF-8') ?>
          </h2>
          <p class="text-white-50 mb-3 small">Painel de controle do evento</p>
          <div class="d-flex flex-nowrap gap-2 badges-info-evento">
            <span class="badge bg-white bg-opacity-10 text-white px-3 py-2 rounded-pill text-nowrap">
              <i class="bi bi-calendar-event me-1 text-warning"></i>
              <?= date('d/m/Y', strtotime($evento['data_evento'])) ?>
            </span>
            <?php if (!empty($evento['hora_evento'])): ?>
            <span class="badge bg-white bg-opacity-10 text-white px-3 py-2 rounded-pill text-nowrap">
              <i class="bi bi-clock me-1 text-warning"></i>
              <?= date('H:i', strtotime($evento['hora_evento'])) ?>
            </span>
            <?php endif; ?>
            <?php if ($dias > 0): ?>
              <span class="badge bg-success bg-opacity-25 text-white border border-success border-opacity-25 px-3 py-2 rounded-pill fw-bold text-nowrap">
                Faltam <?= $dias ?> dias!
              </span>
            <?php elseif ($dias === 0): ?>
              <span class="badge bg-warning bg-opacity-25 text-warning border border-warning border-opacity-25 px-3 py-2 rounded-pill fw-bold text-nowrap">
                É Hoje! <i class="bi bi-stars"></i>
              </span>
            <?php else: ?>
              <span class="badge bg-secondary bg-opacity-25 text-white border border-secondary border-opacity-25 px-3 py-2 rounded-pill fw-bold text-nowrap">
                Realizado há <?= abs($dias) ?> dias
              </span>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($total_g > 0):
          $r_  = 28;
          $circ = 2 * M_PI * $r_;
          $off  = $circ - ($circ * $pct_g / 100); ?>
        <div class="ring-wrap ring-wrap-canto d-none d-md-block" title="<?= $pct_g ?>% do checklist concluído">
          <svg width="72" height="72" viewBox="0 0 72 72">
            <circle cx="36" cy="36" r="<?= $r_ ?>" fill="none" stroke="rgba(255,255,255,.2)" stroke-width="6"/>
            <circle cx="36" cy="36" r="<?= $r_ ?>" fill="none" stroke="#22c55e" stroke-width="6"
              stroke-dasharray="<?= number_format($circ, 2, '.', '') ?>"
              stroke-dashoffset="<?= number_format($off, 2, '.', '') ?>"
              stroke-linecap="round"
              transform="rotate(-90 36 36)"/>
          </svg>
          <div class="ring-label">
            <span id="ring-pct"><?= $pct_g ?>%</span>
            <span style="font-size:.5rem;opacity:.7;">feito</span>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="bg-white p-3 border-top" style="border-radius: 0 0 var(--radius) var(--radius);">
      <div class="d-flex flex-wrap row-gap-2 column-gap-4 info-contato-evento">
        <div class="d-flex align-items-center gap-2 text-muted small">
          <span class="bg-light rounded-circle p-2 d-flex"><i class="bi bi-envelope-fill text-primary"></i></span>
          <?= htmlspecialchars($evento['email'], ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php if (!empty($evento['telefone'])): ?>
        <div class="d-flex align-items-center gap-2 text-muted small">
          <span class="bg-light rounded-circle p-2 d-flex"><i class="bi bi-whatsapp text-success"></i></span>
          <?= htmlspecialchars($evento['telefone'], ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($evento['cpf'])): ?>
        <div class="d-flex align-items-center gap-2 text-muted small">
          <span class="bg-light rounded-circle p-2 d-flex"><i class="bi bi-person-vcard text-secondary"></i></span>
          <?= htmlspecialchars($evento['cpf'], ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>
        <div class="d-flex align-items-center gap-2 text-muted small">
          <span class="bg-light rounded-circle p-2 d-flex"><i class="bi bi-file-earmark-text-fill text-secondary"></i></span>
          Contrato #<?= str_pad($evento['id'], 4, '0', STR_PAD_LEFT) ?>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4 align-items-start">

    <div class="col-md-7">
      <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div class="d-flex align-items-center gap-2 checklist-title-band">
          <span class="checklist-title-icon"><i class="bi bi-list-check"></i></span>
          <div>
            <h4 class="mb-0 fw-bold checklist-title-text">Checklist</h4>
            <?php if ($total_g > 0): ?>
            <div class="text-muted small mt-1">
              <span id="label-conc-g"><?= $conc_g ?></span> de <?= $total_g ?> tarefas concluídas
              <?php if ($total_atrasadas > 0): ?>
                <span class="badge-prazo atrasada ms-1"><i class="bi bi-exclamation-triangle-fill"></i> <?= $total_atrasadas ?> atrasada<?= $total_atrasadas > 1 ? 's' : '' ?></span>
              <?php endif; ?>
              <div class="barra mt-1" style="max-width:140px;">
                <div class="barra-fill" id="barra-g" style="width:<?= $pct_g ?>%;"></div>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="d-flex flex-nowrap gap-2 align-items-center checklist-btns-row">
          <?php if ($is_admin): ?>
          <button type="button" class="btn btn-sm btn-outline-success rounded-3 btn-import-padrao text-nowrap"
                  data-form="form-import-com-rec"
                  data-msg="Isso adicionará todas as tarefas padrão (com recepção) ao evento."
                  data-titulo="Importar cronograma?">
            <i class="bi bi-download me-1"></i> Com Recepção
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary rounded-3 btn-import-padrao text-nowrap"
                  data-form="form-import-sem-rec"
                  data-msg="Isso adicionará todas as tarefas padrão (sem recepção) ao evento."
                  data-titulo="Importar cronograma?">
            <i class="bi bi-download me-1"></i> Sem Recepção
          </button>
          <button type="button" class="btn btn-sm btn-primary rounded-3 text-nowrap" data-bs-toggle="modal" data-bs-target="#modalManual">
            <i class="bi bi-plus-lg me-1"></i> Manual
          </button>
          <?php else: ?>
          <span class="badge bg-light text-muted border shadow-sm px-3 py-2 rounded-3 d-flex align-items-center">
             <i class="bi bi-lock-fill me-1"></i> Gerenciamento Restrito
          </span>
          <?php endif; ?>
        </div>
      </div>

      <?php if (empty($passos)): ?>
        <div class="card border-0 shadow-sm text-center py-5 text-muted" style="border-radius: var(--radius);">
          <i class="bi bi-info-circle fs-1 mb-2"></i>
          <p class="mb-0">Checklist vazio. <?= $is_admin ? 'Use os botões acima para importar um modelo ou adicionar tarefas.' : 'O Administrador ainda não adicionou as tarefas.' ?></p>
        </div>
      <?php else: ?>

        <?php if (!empty($proximas_tarefas)): ?>
        <div class="card border-0 shadow-sm card-proximas p-3">
          <div class="fw-bold text-danger mb-2" style="font-size:.78rem;text-transform:uppercase;letter-spacing:.03em;">
            <i class="bi bi-alarm-fill me-1"></i> Próximas Tarefas
          </div>
          <?php foreach ($proximas_tarefas as $pt): [$pcls, $ptxt] = badge_prazo($pt['data_prazo'] ?? null, false); ?>
            <div class="item-proxima">
              <span class="badge-prazo <?= $pcls ?>"><?= htmlspecialchars($ptxt) ?></span>
              <span class="text-dark text-truncate"><?= htmlspecialchars($pt['tarefa'], ENT_QUOTES, 'UTF-8') ?></span>
              <span class="text-muted ms-auto" style="font-size:.7rem;"><?= htmlspecialchars($pt['etapa']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="checklist-toolbar d-flex flex-wrap gap-2 align-items-center">
          <div class="sw">
            <i class="bi bi-search"></i>
            <input type="text" id="buscaChecklist" class="form-control form-control-sm rounded-pill" placeholder="Buscar tarefa...">
          </div>
          <div class="d-flex gap-1">
            <button class="btn btn-primary btn-sm rounded-pill filtro-check active" data-f="todos" style="font-size:.72rem;">Todas</button>
            <button class="btn btn-outline-warning btn-sm rounded-pill filtro-check" data-f="pendente" style="font-size:.72rem;">Pendentes</button>
            <button class="btn btn-outline-success btn-sm rounded-pill filtro-check" data-f="concluido" style="font-size:.72rem;">Concluídas</button>
          </div>
        </div>

        <div class="d-flex flex-column gap-3" id="lista-etapas-checklist">
          <?php $idx = 0; foreach ($passos as $etapa => $tarefas): $idx++;
            $totE  = $prog[$etapa]['total'];
            $concE = $prog[$etapa]['conc'];
            $pctE  = $totE > 0 ? round($concE / $totE * 100) : 0;
            $ok    = ($totE > 0 && $concE === $totE);
            $label = is_numeric($etapa) ? 'PASSO ' . str_pad($etapa, 2, '0', STR_PAD_LEFT) : $etapa;
            $cid   = 'etapa_' . $idx;
            $auto_abrir = ($etapa === $etapa_auto_abrir);
          ?>
          <div class="card border-0 shadow-sm overflow-hidden etapa-wrap" style="border-radius:12px;">
            <div class="etapa-hdr"
                 data-bs-toggle="collapse"
                 data-bs-target="#<?= $cid ?>"
                 aria-expanded="<?= $auto_abrir ? 'true' : 'false' ?>"
                 id="hdr-<?= $cid ?>">
              <div class="d-flex align-items-center gap-2">
                <span class="selo-etapa <?= $ok ? 'feita' : '' ?> icone-etapa"><?= $ok ? '<i class="bi bi-check-lg"></i>' : $idx ?></span>
                <span class="fw-bold" style="font-size:.88rem;"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>

                <?php if ($is_admin): ?>
                    <button type="button" class="btn btn-sm btn-link text-white p-0 ms-2 btn-renomear-etapa"
                            data-nome="<?= htmlspecialchars($etapa, ENT_QUOTES, 'UTF-8') ?>"
                            style="opacity: 0.7;" title="Renomear etapa">
                        <i class="bi bi-pencil"></i>
                    </button>
                <?php endif; ?>
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
                <i class="bi bi-chevron-down text-white small chevron-etapa"></i>
              </div>
            </div>

            <div id="<?= $cid ?>" class="collapse<?= $auto_abrir ? ' show' : '' ?>">
              <div class="etapa-body p-3 bg-white">
                <div class="p-3 mb-3 bg-light rounded-3 border small">
                  <div class="fw-bold text-muted mb-2" style="font-size:.72rem;text-transform:uppercase;">
                    <i class="bi bi-journal-text me-1"></i> Anotações desta Etapa
                  </div>
                  <div class="lista-coment-etapa mb-2">
                    <?php foreach ($coments_etapa[$etapa] ?? [] as $ce):
                      $cor = $ce['autor'] === 'Noivos' ? 'bg-danger' : 'bg-primary'; ?>
                      <div class="my-1 bg-white border p-2 rounded-3 shadow-sm" style="font-size:.82rem;">
                        <span class="badge <?= $cor ?> rounded-pill me-2"><?= htmlspecialchars($ce['autor'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?= htmlspecialchars($ce['comentario'], ENT_QUOTES, 'UTF-8') ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <form class="d-flex gap-2 form-ajax-etapa" action="?id=<?= $evento_id ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="comentario_etapa_admin" value="1">
                    <input type="hidden" name="etapa_nome" value="<?= htmlspecialchars($etapa, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="text" name="novo_comentario_etapa" class="form-control form-control-sm" placeholder="Nota geral para os noivos…" required>
                    <button type="submit" class="btn btn-sm btn-dark px-3">Salvar</button>
                  </form>
                </div>

                <?php foreach ($tarefas as $t):
                  $tid  = $t['id'];
                  $done = ($t['status'] === 'concluido' || $t['checado'] == 1);
                  $snum = $done ? 1 : 0;
                ?>
                <?php [$badge_cls, $badge_txt] = badge_prazo($t['data_prazo'] ?? null, $done); ?>
                <div class="tarefa-card card border-0 bg-white mb-2 shadow-sm <?= $done ? 'done' : 'pend' ?>"
                     data-tarefa-nome="<?= strtolower(htmlspecialchars($t['tarefa'], ENT_QUOTES, 'UTF-8')) ?>"
                     data-tarefa-status="<?= $done ? 'concluido' : 'pendente' ?>">
                  <div class="card-body p-3">
                    <div class="d-flex align-items-start gap-3">
                      <button type="button"
                              class="btn p-0 border-0 btn-chk text-<?= $done ? 'success' : 'muted' ?> btn-toggle-tarefa"
                              data-id="<?= $tid ?>"
                              data-status="<?= $snum ?>"
                              data-etapa-hdr-id="hdr-<?= $cid ?>"
                              title="<?= $done ? 'Desmarcar' : 'Marcar como concluída' ?>">
                        <i class="bi <?= $done ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i>
                      </button>
                      <div class="w-100">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                          <div style="min-width:0;">
                            <h6 class="fw-bold mb-1 <?= $done ? 'text-muted text-decoration-line-through' : 'text-dark' ?>" style="line-height:1.4;">
                              <?= htmlspecialchars($t['tarefa'], ENT_QUOTES, 'UTF-8') ?>
                            </h6>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                              <span class="badge-prazo <?= $badge_cls ?>"><i class="bi bi-calendar-event me-1"></i><?= htmlspecialchars($badge_txt) ?></span>
                              <?php if (!empty($t['descricao'])): ?>
                              <button class="btn btn-sm btn-outline-secondary py-0 px-2 rounded-pill"
                                      data-bs-toggle="modal"
                                      data-bs-target="#modalDesc_<?= $tid ?>"
                                      style="font-size:.72rem;">
                                <i class="bi bi-file-text"></i> Ler
                              </button>
                              <?php endif; ?>
                            </div>
                          </div>

                          <?php if ($is_admin): ?>
                          <div class="task-actions">
                            <button type="button"
                                    class="btn btn-sm btn-outline-primary py-0 px-2 rounded"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalEditar_<?= $tid ?>"
                                    title="Editar tarefa">
                              <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button"
                                    class="btn btn-sm btn-outline-danger py-0 px-2 rounded btn-del-task"
                                    data-id="<?= $tid ?>"
                                    title="Remover tarefa">
                              <i class="bi bi-trash"></i>
                            </button>
                          </div>
                          <?php endif; ?>

                        </div>
                        <div class="border-top pt-2">
                          <div class="lista-coment-tarefa mb-2">
                            <?php foreach ($coments_tarefa[$tid] ?? [] as $cm):
                              $corC = $cm['autor'] === 'Noivos' ? 'text-danger' : 'text-primary'; ?>
                              <div class="small my-1 bg-light p-2 rounded-3" style="font-size:.77rem;border:1px solid #f1f5f9;">
                                <strong class="<?= $corC ?>"><?= htmlspecialchars($cm['autor'], ENT_QUOTES, 'UTF-8') ?>:</strong>
                                <?= htmlspecialchars($cm['comentario'], ENT_QUOTES, 'UTF-8') ?>
                              </div>
                            <?php endforeach; ?>
                          </div>
                          <form class="d-flex gap-2 form-ajax-tarefa" action="?id=<?= $evento_id ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="adicionar_comentario" value="1">
                            <input type="hidden" name="id_tarefa" value="<?= $tid ?>">
                            <input type="text" name="texto_comentario" class="form-control form-control-sm bg-light border-0" placeholder="Comentar nesta tarefa…" required>
                            <button type="submit" class="btn btn-sm btn-outline-primary px-3" title="Enviar">
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

        <?php if ($is_admin): ?>
        <div class="text-end mt-3">
          <button type="button" class="btn btn-sm btn-outline-danger rounded-3" id="btn-limpar-checklist">
            <i class="bi bi-trash me-1"></i> Limpar Todo o Checklist
          </button>
        </div>
        <?php endif; ?>

      <?php endif; ?>
    </div>

    <div class="col-md-5">
      <div class="sidebar-sticky d-flex flex-column gap-4">

        <div class="card border-0 shadow-sm overflow-hidden card-inspiracoes" style="border-radius: var(--radius);">
          <div class="card-body d-flex justify-content-between align-items-center gap-2 p-3">
            <div class="d-flex align-items-center gap-3" style="min-width:0;">
              <div class="bg-white rounded-3 d-flex align-items-center justify-content-center shadow-sm flex-shrink-0" style="width:44px;height:44px;">
                <i class="bi bi-stars fs-4" style="color:var(--color-primary);"></i>
              </div>
              <div style="min-width:0;">
                <h6 class="mb-0 fw-bold text-white text-truncate">Mural de Inspirações</h6>
                <small class="text-white-50 text-truncate d-block" style="font-size:.78rem;">Referências, paletas e ideias</small>
              </div>
            </div>
            <a href="inspiracoes.php?id=<?= $evento_id ?>" class="btn btn-light btn-sm fw-bold rounded-pill px-3 shadow-sm flex-shrink-0" style="color:var(--color-primary-dark);">
              Abrir <i class="bi bi-arrow-right ms-1"></i>
            </a>
          </div>
        </div>

        <button type="button" class="btn-notas-sidebar" data-bs-toggle="modal" data-bs-target="#modalNotas">
          <div class="d-flex justify-content-between align-items-center gap-2 p-3">
            <div class="d-flex align-items-center gap-3" style="min-width:0;">
              <div class="bg-white rounded-3 d-flex align-items-center justify-content-center shadow-sm flex-shrink-0" style="width:44px;height:44px;">
                <i class="bi bi-journal-text fs-4" style="color:#d97706;"></i>
              </div>
              <div style="min-width:0;">
                <h6 class="mb-0 fw-bold text-dark text-truncate">Bloco de Notas</h6>
                <small class="text-dark text-truncate d-block" style="font-size:.78rem;opacity:.6;">
                  <span id="notas-count-badge"><?= $total_notas ?> nota<?= $total_notas !== 1 ? 's' : '' ?></span>
                  · visível ao casal
                </small>
              </div>
            </div>
            <span class="btn btn-warning btn-sm fw-bold rounded-pill px-3 shadow-sm flex-shrink-0" style="pointer-events:none;">
              Abrir <i class="bi bi-arrow-right ms-1"></i>
            </span>
          </div>
        </button>

        <button type="button" class="btn-musicas-sidebar" data-bs-toggle="modal" data-bs-target="#modalMusicas">
          <div class="d-flex justify-content-between align-items-center gap-2 p-3">
            <div class="d-flex align-items-center gap-3" style="min-width:0;">
              <div class="bg-white rounded-3 d-flex align-items-center justify-content-center shadow-sm flex-shrink-0" style="width:44px;height:44px;">
                <i class="bi bi-music-note-beamed fs-4" style="color:var(--color-primary-dark);"></i>
              </div>
              <div style="min-width:0;">
                <h6 class="mb-0 fw-bold text-dark text-truncate">Playlist do Evento</h6>
                <small class="text-dark text-truncate d-block" style="font-size:.78rem;opacity:.6;">
                  <span id="musicas-count-badge"><?= $total_musicas ?> música<?= $total_musicas !== 1 ? 's' : '' ?></span>
                  · visível ao casal
                </small>
              </div>
            </div>
            <span class="btn btn-sm fw-bold rounded-pill px-3 shadow-sm flex-shrink-0"
                  style="background:var(--color-primary-dark);color:#fff;pointer-events:none;border:none;">
              Abrir <i class="bi bi-arrow-right ms-1"></i>
            </span>
          </div>
        </button>

        <div class="card shadow-sm border-0" style="border-radius: var(--radius);">
          <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
              <h6 class="fw-bold mb-0"><i class="bi bi-geo-alt-fill text-danger me-1"></i> Locais do Evento</h6>
              <button class="btn btn-sm btn-outline-primary py-0 px-2 rounded-pill"
                      data-bs-toggle="modal" data-bs-target="#modalLocais">Editar</button>
            </div>
            <div class="mb-2">
              <div class="text-muted fw-bold mb-1" style="font-size:.68rem;text-transform:uppercase;">Cerimônia</div>
              <div class="d-flex align-items-start gap-2">
                <i class="bi bi-church text-secondary mt-1" style="font-size:.85rem;"></i>
                <span class="small text-dark fw-medium">
                  <?= !empty($evento['local_cerimonia']) ? htmlspecialchars($evento['local_cerimonia'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted fst-italic">A definir…</span>' ?>
                </span>
              </div>
            </div>
            <?php if ($evento['tem_festa'] == 1): ?>
            <div class="mt-3 pt-2 border-top">
              <div class="text-muted fw-bold mb-1" style="font-size:.68rem;text-transform:uppercase;">Recepção / Festa</div>
              <div class="d-flex align-items-start gap-2">
                <i class="bi bi-balloon-heart text-secondary mt-1" style="font-size:.85rem;"></i>
                <span class="small text-dark fw-medium">
                  <?= !empty($evento['local_festa']) ? htmlspecialchars($evento['local_festa'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted fst-italic">A definir…</span>' ?>
                </span>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($is_admin): ?>
        <div class="card shadow-sm border-0 mb-3" style="border-radius: var(--radius);">
          <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h6 class="fw-bold mb-0"><i class="bi bi-wallet2 text-success me-1"></i> Resumo Financeiro</h6>
              <a href="fornecedores_evento.php?id=<?= $evento_id ?>" class="btn btn-sm btn-outline-dark shadow-sm">
                <i class="bi bi-gear-fill me-1"></i> Completo
              </a>
            </div>
            <div class="d-flex gap-2 mb-2">
              <div class="fin-chip bg-primary bg-opacity-10 border border-primary border-opacity-20 flex-fill">
                <span class="fin-chip-label text-primary">Total</span>
                <span class="fin-chip-val text-primary">R$ <?= number_format($total_forn, 2, ',', '.') ?></span>
              </div>
              <div class="fin-chip bg-success bg-opacity-10 border border-success border-opacity-20 flex-fill">
                <span class="fin-chip-label text-success">Pago</span>
                <span class="fin-chip-val text-success" id="adm-total-pago">R$ <?= number_format($total_forn_pago, 2, ',', '.') ?></span>
              </div>
              <div class="fin-chip bg-danger bg-opacity-10 border border-danger border-opacity-20 flex-fill">
                <span class="fin-chip-label text-danger">A Pagar</span>
                <span class="fin-chip-val text-danger" id="adm-total-rest">R$ <?= number_format($total_forn_restante, 2, ',', '.') ?></span>
              </div>
            </div>
            <div class="d-flex justify-content-between mb-1" style="font-size:.66rem;color:#334155;font-weight:800;text-transform:uppercase;letter-spacing:.05em;">
              <span>Progresso de Pagamentos</span>
              <span id="adm-pct-pago" style="color:#16a34a;"><?= $pct_pago_geral ?>%</span>
            </div>
            <div class="barra-pag-wrap">
              <div class="barra-pag-fill" id="adm-barra-pago" style="width:<?= $pct_pago_geral ?>%;"></div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <div class="row g-2" id="grupoAcessos">
          <div class="col-6">
            <button class="btn w-100 d-flex flex-column align-items-center justify-content-center p-2 shadow-sm text-white h-100"
                    style="background: #1e293b; border-radius: var(--radius); border: none;"
                    data-bs-toggle="collapse" data-bs-target="#collapseEquipe"
                    aria-expanded="false" aria-controls="collapseEquipe">
              <i class="bi bi-person-badge-fill text-info fs-3 mb-2"></i>
              <span class="fw-bold" style="font-size:.85rem;">Equipe Contratada</span>
              <?php if (!$is_admin): ?>
              <span class="mt-2 text-white-50" style="font-size:.7rem;"><i class="bi bi-lock-fill me-1"></i>Acesso restrito</span>
              <?php endif; ?>
            </button>
          </div>
          <div class="col-6">
            <button class="btn w-100 d-flex flex-column align-items-center justify-content-center p-2 shadow-sm text-white h-100"
                    style="background: #334155; border-radius: var(--radius); border: none;"
                    data-bs-toggle="collapse" data-bs-target="#collapseConvidados"
                    aria-expanded="false" aria-controls="collapseConvidados">
              <i class="bi bi-people-fill text-info fs-3 mb-2"></i>
              <span class="fw-bold" style="font-size:.85rem;">Convidados</span>
              <div class="mt-2 text-success fw-bold" style="font-size:.85rem;">
                <span id="cnt-badge-conf"><?= $total_conf ?></span> confirmados
              </div>
            </button>
          </div>
        </div>

        <div class="accordion" id="accordionAcessos">

          <div class="collapse" id="collapseEquipe" data-bs-parent="#accordionAcessos">
            <div class="card shadow-sm border-0" style="border-radius: var(--radius);">
              <div class="etapa-body bg-white rounded-3">
                <div class="p-3 border-bottom bg-light rounded-top">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-muted fw-bold" style="font-size:.68rem;text-transform:uppercase;">Resumo Financeiro</small>
                    <?php if ($is_admin): ?>
                    <div class="d-flex gap-2">
                      <button class="btn btn-sm btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#modalFornecedor">
                        <i class="bi bi-plus-lg me-1"></i> Novo
                      </button>
                      <a href="fornecedores_evento.php?id=<?= $evento_id ?>" class="btn btn-sm btn-outline-dark shadow-sm">
                        <i class="bi bi-gear-fill me-1"></i> Completo
                      </a>
                    </div>
                    <?php else: ?>
                    <span class="badge bg-light text-muted border shadow-sm px-2 py-1">
                      <i class="bi bi-lock-fill me-1"></i> Restrito
                    </span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="scroll-lista-pequena">
                  <?php if (empty($lista_forn)): ?>
                    <div class="text-center text-muted py-4 small">
                      <i class="bi bi-inbox d-block fs-3 mb-2"></i>
                      Nenhum fornecedor contratado ainda.
                    </div>
                  <?php else: ?>
                    <?php foreach ($lista_forn as $f):
                      $fid    = (int)$f['id'];
                      $fValor = (float)$f['valor'];
                      $fPago  = (float)($f['valor_pago'] ?? 0);
                      $fRest  = max(0.0, $fValor - $fPago);
                      $fPct   = $fValor > 0 ? round($fPago / $fValor * 100) : 0;
                      $fQuit  = $fRest <= 0;
                      $barClr = $fQuit ? 'bg-success' : ($fPct >= 50 ? 'bg-info' : 'bg-warning');
                    ?>
                    <div class="forn-pago-row" id="forn-adm-<?= $fid ?>" data-pago="<?= $fPago ?>">
                      <div>
                        <div class="forn-info-nome"><?= htmlspecialchars($f['servico'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="forn-info-sub"><?= htmlspecialchars($f['nome'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="d-flex align-items-center gap-2 mt-1">
                          <div style="flex:1;height:3px;background:#e2e8f0;border-radius:999px;overflow:hidden;">
                            <div class="forn-barra-fill-adm <?= $barClr ?>" style="height:100%;width:<?= $fPct ?>%;border-radius:999px;transition:width .4s;"></div>
                          </div>
                          <span class="forn-pago-badge <?= $fQuit ? 'bg-success text-white' : 'bg-warning text-dark' ?> forn-badge-adm">
                            <?= $fQuit ? '✓ Quitado' : ($fPct > 0 ? $fPct.'%' : '—') ?>
                          </span>
                        </div>
                      </div>
                      <?php if ($is_admin): ?>
                      <div class="forn-valores">
                        <div class="forn-val-total">R$ <?= number_format($fValor, 2, ',', '.') ?></div>
                        <div class="forn-val-pago-linha">
                          Pago: R$ <span class="forn-pago-valor-txt"><?= number_format($fPago, 2, ',', '.') ?></span>
                          <button type="button" class="forn-btn-editar-pago" data-id="<?= $fid ?>" title="Corrigir valor pago">
                            <i class="bi bi-pencil-fill"></i>
                          </button>
                        </div>
                        <div class="forn-val-rest <?= $fQuit ? 'ok' : 'nok' ?> forn-rest-adm">
                          <?= $fQuit ? '✓ Quitado' : 'Resta R$ '.number_format($fRest, 2, ',', '.') ?>
                        </div>
                      </div>
                      <div class="forn-input-wrap forn-add-wrap">
                        <input type="text" class="forn-input-pago-adm forn-input-add"
                               data-id="<?= $fid ?>" data-total="<?= $fValor ?>"
                               placeholder="+ pagamento" inputmode="decimal" title="Somar novo pagamento (R$)">
                        <button type="button" class="forn-btn-salvar forn-btn-add-adm"
                                 data-id="<?= $fid ?>" title="Adicionar pagamento">
                          <i class="bi bi-plus-lg"></i>
                        </button>
                      </div>
                      <div class="forn-input-wrap forn-edit-wrap" style="display:none;">
                        <input type="text" class="forn-input-pago-adm forn-input-edit"
                               data-id="<?= $fid ?>" data-total="<?= $fValor ?>"
                               value="<?= number_format($fPago, 2, ',', '.') ?>"
                               placeholder="0,00" inputmode="decimal" title="Corrigir valor pago (R$)">
                        <button type="button" class="forn-btn-salvar forn-btn-salvar-edit-adm"
                                 data-id="<?= $fid ?>" title="Salvar correção">
                          <i class="bi bi-check-lg"></i>
                        </button>
                        <button type="button" class="forn-btn-cancelar-edit" title="Cancelar">
                          <i class="bi bi-x-lg"></i>
                        </button>
                      </div>
                      <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($is_admin): ?>
                    <div class="px-3 py-2 bg-light border-top d-flex justify-content-between align-items-center" style="font-size:.72rem;">
                      <span class="text-muted fw-bold text-uppercase">Total contratado:</span>
                      <span class="fw-bold text-dark">R$ <?= number_format($total_forn, 2, ',', '.') ?></span>
                    </div>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <div class="collapse" id="collapseConvidados" data-bs-parent="#accordionAcessos">
            <div class="card shadow-sm border-0" style="border-radius: var(--radius);">
              <div class="etapa-body bg-white p-3 rounded-3">
                <div class="row g-2 mb-3">
                  <div class="col-12 col-sm-6">
                    <button type="button" class="btn btn-success w-100 btn-sm fw-bold shadow-sm py-2 rounded-3 h-100"
                            data-bs-toggle="modal" data-bs-target="#modalAddConvidado">
                      <i class="bi bi-person-plus-fill me-1"></i> Cadastrar Convidado
                    </button>
                  </div>
                  <div class="col-12 col-sm-6">
                    <a href="organizar_mesas.php?id=<?= $evento_id ?>" class="btn btn-primary w-100 btn-sm fw-bold shadow-sm py-2 rounded-3 h-100 d-flex align-items-center justify-content-center">
                      <i class="bi bi-grid-3x3-gap-fill me-2"></i> Organizar Mesas
                    </a>
                  </div>
                </div>
                <div class="row g-2 mb-3">
                  <div class="col-6">
                    <div class="bg-success bg-opacity-10 rounded-3 p-3 text-center border border-success border-opacity-25">
                      <h4 class="mb-0 fw-bold text-success" id="cnt-conf"><?= $total_conf ?></h4>
                      <small class="text-muted" style="font-size:.7rem;">Confirmados</small>
                    </div>
                  </div>
                  <div class="col-6">
                    <div class="bg-warning bg-opacity-10 rounded-3 p-3 text-center border border-warning border-opacity-25">
                      <h4 class="mb-0 fw-bold" id="cnt-pend" style="color:#d97706;"><?= $total_pend ?></h4>
                      <small class="text-muted" style="font-size:.7rem;">Pendentes</small>
                    </div>
                  </div>
                </div>
                <div class="fw-bold text-muted mb-2" style="font-size:.72rem;text-transform:uppercase;">
                  Lista completa (<span id="cnt-total"><?= count($lista_conv) ?></span>)
                </div>
                <div id="lista-convidados" class="scroll-lista-grande">
                  <?php if (empty($lista_conv)): ?>
                    <p class="text-center text-muted small py-4 mb-0">Nenhum convidado cadastrado.</p>
                  <?php else: ?>
                    <?php foreach ($conv_grupos as $grp => $convidadosDoGrupo): ?>
                    <div class="grupo-sec" data-grupo="<?= htmlspecialchars($grp, ENT_QUOTES, 'UTF-8') ?>">
                      <div class="badge bg-secondary text-white w-100 text-start px-3 py-2 rounded-2 mb-1 mt-2 sec-badge">
                        <i class="bi bi-tag-fill me-1"></i>
                        <?= htmlspecialchars($grp, ENT_QUOTES, 'UTF-8') ?> (<span class="cnt-grp"><?= count($convidadosDoGrupo) ?></span>)
                      </div>
                      <?php foreach ($convidadosDoGrupo as $con):
                        $cConf   = (bool)$con['confirmado'];
                        $recusou = (!$cConf && ($con['resposta_rsvp'] ?? '') === 'recusado'); ?>
                      <div class="conv-row <?= $cConf ? 'conf' : 'pend' ?> p-2 mb-2 bg-light shadow-sm"
                           data-id="<?= $con['id'] ?>"
                           data-conf="<?= (int)$cConf ?>"
                           data-nome="<?= strtolower(htmlspecialchars($con['nome'], ENT_QUOTES, 'UTF-8')) ?>">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                          <h6 class="mb-0 small fw-bold text-dark text-truncate pe-2" title="<?= htmlspecialchars($con['nome'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($con['nome'], ENT_QUOTES, 'UTF-8') ?>
                          </h6>
                          <div class="d-flex align-items-center gap-1 flex-shrink-0">
                            <button type="button" class="btn p-0 border-0 bg-transparent btn-toggle-conv" data-id="<?= $con['id'] ?>">
                              <span class="badge <?= $cConf ? 'bg-success' : ($recusou ? 'bg-danger' : 'bg-warning text-dark') ?> rounded-pill" style="font-size:.6rem;">
                                <?= $cConf ? '<i class="bi bi-check-circle-fill me-1"></i> Confirmado' : ($recusou ? '<i class="bi bi-x-circle-fill me-1"></i> Recusou' : '<i class="bi bi-hourglass-split me-1"></i> Pendente') ?>
                              </span>
                            </button>
                            <button type="button" class="btn p-0 border-0 bg-transparent text-danger btn-excluir-conv" data-id="<?= $con['id'] ?>" title="Remover">
                              <i class="bi bi-trash fs-6"></i>
                            </button>
                          </div>
                        </div>
                        <?php if (!empty($con['telefone']) || $con['acompanhantes'] > 0 || $con['filhos'] > 0): ?>
                        <div class="text-muted border-top pt-1 mt-1" style="font-size:.67rem;line-height:1.4;">
                          <?php if (!empty($con['telefone'])): ?>
                            <div><i class="bi bi-whatsapp me-1 text-success"></i><?= htmlspecialchars($con['telefone'], ENT_QUOTES, 'UTF-8') ?></div>
                          <?php endif; ?>
                          <?php if ($con['acompanhantes'] > 0): ?>
                            <div>
                              <i class="bi bi-person-plus me-1"></i>Acomp (<?= (int)$con['acompanhantes'] ?>):
                              <?= !empty($con['nomes_acompanhantes']) ? htmlspecialchars($con['nomes_acompanhantes'], ENT_QUOTES, 'UTF-8') : '<span class="fst-italic text-black-50">Nomes não informados</span>' ?>
                            </div>
                          <?php endif; ?>
                          <?php if ($con['filhos'] > 0): ?>
                            <div>
                              <i class="bi bi-emoji-smile me-1"></i>Filhos (<?= (int)$con['filhos'] ?>):
                              <?= !empty($con['idades_filhos']) ? htmlspecialchars($con['idades_filhos'], ENT_QUOTES, 'UTF-8') : '<span class="fst-italic text-black-50">Idades não informadas</span>' ?>
                            </div>
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
</div>

<?php if ($is_admin): ?>
<div class="modal fade" id="modalManual" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header bg-light border-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle text-primary me-2"></i> Adicionar Tarefa Manual</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="?id=<?= $evento_id ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="adicionar_manual" value="1">
        <div class="modal-body p-4">
          <div class="mb-3">
            <label class="form-label small fw-bold text-secondary">Etapa (Nome da etapa)</label>
            <input type="text" name="etapa" class="form-control" placeholder="Ex: Pré-Casamento" required>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-bold text-secondary">Nome da Tarefa</label>
            <input type="text" name="tarefa" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-bold text-secondary">Prazo (opcional)</label>
            <input type="date" name="data_prazo" class="form-control">
          </div>
          <div class="mb-1">
            <label class="form-label small fw-bold text-secondary">Descrição (opcional)</label>
            <textarea name="descricao" class="form-control" rows="3" placeholder="Detalhes, instruções…"></textarea>
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-bold">Adicionar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="modalRenomear" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header bg-light border-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-pencil text-primary me-2"></i> Renomear Etapa</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="?id=<?= $evento_id ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="renomear_etapa" value="1">
        <input type="hidden" name="etapa_antiga" id="renomear_antiga">
        <div class="modal-body p-4">
          <div class="mb-3">
            <label class="form-label small fw-bold text-secondary">Novo nome para a etapa:</label>
            <input type="text" name="etapa_nova" id="renomear_nova" class="form-control" required>
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-bold">Renomear</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="modal fade" id="modalFornecedor" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header bg-light border-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-person-plus text-success me-2"></i> Adicionar Fornecedor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="?id=<?= $evento_id ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="adicionar_fornecedor" value="1">
        <div class="modal-body p-4">
          <div class="mb-3">
            <label class="form-label small fw-bold text-secondary">Serviço Prestado</label>
            <input type="text" name="servico_fornecedor" class="form-control" placeholder="Ex: Fotografia, Buffet…" required>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-bold text-secondary">Nome / Empresa</label>
            <input type="text" name="nome_fornecedor" class="form-control" required>
          </div>
          <div class="row g-3">
            <div class="<?= $is_admin ? 'col-12 col-sm-6' : 'col-12' ?>">
              <label class="form-label small fw-bold text-secondary">Status</label>
              <select name="status_fornecedor" class="form-select">
                <option value="Contratado">Contratado</option>
                <option value="Orçamento">Apenas Orçamento</option>
              </select>
            </div>
            <?php if ($is_admin): ?>
            <div class="col-12 col-sm-6">
              <label class="form-label small fw-bold text-secondary">Valor Total (R$)</label>
              <input type="text" name="valor_fornecedor" class="form-control" placeholder="0,00">
            </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-success btn-sm px-4 rounded-pill fw-bold">Salvar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="modalLocais" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header bg-light border-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-geo-alt-fill text-danger me-2"></i> Locais do Evento</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="?id=<?= $evento_id ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="editar_locais" value="1">
        <div class="modal-body p-4">
          <div class="mb-3">
            <label class="form-label small fw-bold text-secondary">Local da Cerimônia</label>
            <input type="text" name="local_cerimonia" class="form-control" placeholder="Igreja, Cartório…"
                   value="<?= htmlspecialchars($evento['local_cerimonia'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-bold text-secondary">Haverá recepção em outro local?</label>
            <select name="tem_festa" id="select-tem-festa" class="form-select">
              <option value="0" <?= ($evento['tem_festa'] == 0) ? 'selected' : '' ?>>Não (mesmo local)</option>
              <option value="1" <?= ($evento['tem_festa'] == 1) ? 'selected' : '' ?>>Sim, outro local</option>
            </select>
          </div>
          <div id="div-local-festa" <?= ($evento['tem_festa'] == 1) ? '' : 'style="display:none;"' ?>>
            <label class="form-label small fw-bold text-secondary">Local da Recepção / Festa</label>
            <input type="text" name="local_festa" class="form-control" placeholder="Espaço, Salão…"
                   value="<?= htmlspecialchars($evento['local_festa'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-bold">Salvar Locais</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="modalAddConvidado" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header bg-light border-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-person-plus text-success me-2"></i> Cadastrar Convidado</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="?id=<?= $evento_id ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="adicionar_convidado_admin" value="1">
        <div class="modal-body p-4">
          <div class="mb-3">
            <label class="form-label small fw-bold text-secondary">Nome do Convidado / Família (Titular)</label>
            <input type="text" name="nome_convidado" class="form-control" required>
          </div>
          <div class="row g-3 mb-4">
            <div class="col-12 col-md-6">
              <label class="form-label small fw-bold text-secondary">Categoria / Grupo</label>
              <input type="text" name="categoria_convidado" class="form-control" list="lista-categorias" placeholder="Ex: Padrinhos..." required>
              <datalist id="lista-categorias">
                <?php
                  if (!empty($conv_grupos)) {
                      foreach (array_keys($conv_grupos) as $nomeGrp) {
                          echo '<option value="' . htmlspecialchars($nomeGrp, ENT_QUOTES, 'UTF-8') . '">';
                      }
                  } else {
                      echo '<option value="Família"><option value="Amigos"><option value="Trabalho">';
                  }
                ?>
              </datalist>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label small fw-bold text-secondary">Telefone / WhatsApp</label>
              <input type="text" name="telefone_convidado" class="form-control" placeholder="(00) 00000-0000">
            </div>
          </div>
          <hr class="my-4 text-secondary opacity-25">
          <h6 class="fw-bold small text-dark mb-3"><i class="bi bi-people-fill text-secondary"></i> Acompanhantes</h6>
          <div class="row g-3 mb-4">
            <div class="col-12 col-sm-4">
              <label class="form-label small text-secondary">Quantidade</label>
              <input type="number" min="0" name="acompanhantes" class="form-control" value="0">
            </div>
            <div class="col-12 col-sm-8">
              <label class="form-label small text-secondary">Nomes (separados por vírgula)</label>
              <input type="text" name="nomes_acompanhantes" class="form-control" placeholder="Ex: Maria, João...">
            </div>
          </div>
          <h6 class="fw-bold small text-dark mb-3"><i class="bi bi-emoji-smile-fill text-secondary"></i> Filhos</h6>
          <div class="row g-3">
            <div class="col-12 col-sm-4">
              <label class="form-label small text-secondary">Quantidade</label>
              <input type="number" min="0" name="filhos" class="form-control" value="0">
            </div>
            <div class="col-12 col-sm-8">
              <label class="form-label small text-secondary">Idades (separadas por vírgula)</label>
              <input type="text" name="idades_filhos" class="form-control" placeholder="Ex: 5 anos, 12 anos...">
            </div>
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-success btn-sm px-4 rounded-pill fw-bold">Cadastrar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php foreach ($lista_checklist as $t):
  $tid = $t['id']; ?>

  <?php if (!empty($t['descricao'])): ?>
  <div class="modal fade" id="modalDesc_<?= $tid ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg rounded-4">
        <div class="modal-header bg-light border-0">
          <h5 class="modal-title fw-bold"><i class="bi bi-card-text text-primary me-2"></i> Detalhes</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body p-4">
          <h6 class="fw-bold mb-3 border-bottom pb-2"><?= htmlspecialchars($t['tarefa'], ENT_QUOTES, 'UTF-8') ?></h6>
          <div style="white-space:pre-wrap;font-size:.93rem;line-height:1.7;"><?= htmlspecialchars(trim($t['descricao']), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button class="btn btn-secondary btn-sm px-4 rounded-pill fw-bold" data-bs-dismiss="modal">Fechar</button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($is_admin): ?>
  <div class="modal fade" id="modalEditar_<?= $tid ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg rounded-4">
        <div class="modal-header bg-light border-0">
          <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square text-primary me-2"></i> Editar Tarefa</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form method="POST" action="?id=<?= $evento_id ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
          <input type="hidden" name="editar_tarefa" value="1">
          <input type="hidden" name="id_tarefa" value="<?= $tid ?>">
          <div class="modal-body p-4">
            <div class="mb-3">
              <label class="form-label small fw-bold text-secondary">Etapa</label>
              <input type="text" name="etapa_edit" class="form-control" value="<?= htmlspecialchars($t['etapa'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-bold text-secondary">Tarefa</label>
              <input type="text" name="tarefa_edit" class="form-control" value="<?= htmlspecialchars($t['tarefa'], ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-bold text-secondary">Prazo (opcional)</label>
              <input type="date" name="data_prazo_edit" class="form-control" value="<?= htmlspecialchars($t['data_prazo'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="mb-1">
              <label class="form-label small fw-bold text-secondary">Descrição</label>
              <textarea name="descricao_edit" class="form-control" rows="4"><?= htmlspecialchars($t['descricao'], ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
          </div>
          <div class="modal-footer border-0 pt-0">
            <button type="button" class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-primary btn-sm px-4 rounded-pill fw-bold">Salvar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

<?php endforeach; ?>

<div class="modal fade" id="modalMusicas" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg rounded-4" style="background:#f5f3ff;">

      <div class="modal-header border-0 px-4 pt-4 pb-2" style="background:transparent;">
        <div class="d-flex align-items-center gap-3">
          <div class="rounded-3 d-flex align-items-center justify-content-center shadow-sm"
               style="width:42px;height:42px;background:#ede9fe;border:1.5px solid #a78bfa;">
            <i class="bi bi-music-note-beamed fs-5" style="color:#7c3aed;"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold mb-0 text-dark">Playlist do Evento</h5>
            <span class="text-muted" style="font-size:.73rem;">Sugestões e músicas confirmadas · visíveis ao casal</span>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body px-4 pb-4 pt-2">

        <div class="card border-0 shadow-sm rounded-4 mb-4 musica-modal-form"
              style="border:1.5px solid #a78bfa !important;background:#fff;">
          <div class="card-body p-3 p-sm-4">
            <div class="d-flex align-items-center gap-2 mb-3">
              <i class="bi bi-plus-circle-fill fs-6" style="color:#7c3aed;"></i>
              <span class="fw-bold text-dark small text-uppercase" style="letter-spacing:.06em;">Sugerir Música</span>
            </div>
            <div class="row g-3 mb-3">
              <div class="col-12 col-sm-6">
                <label class="form-label small fw-bold text-secondary">Título da Música <span class="text-danger">*</span></label>
                <input type="text" id="musica-titulo" class="form-control"
                       placeholder="Ex: Can't Help Falling in Love" maxlength="255">
              </div>
              <div class="col-12 col-sm-6">
                <label class="form-label small fw-bold text-secondary">Artista / Banda</label>
                <input type="text" id="musica-artista" class="form-control"
                       placeholder="Ex: Elvis Presley" maxlength="255">
              </div>
            </div>
            <div class="row g-3 mb-3">
              <div class="col-12 col-sm-6">
                <label class="form-label small fw-bold text-secondary">
                  <i class="bi bi-calendar-heart me-1" style="color:#7c3aed;"></i>
                  Momento da Celebração <span class="text-danger">*</span>
                </label>
                <select id="musica-momento" class="form-select">
                  <?php foreach ($momentos_casamento as $momento): ?>
                  <option value="<?= htmlspecialchars($momento, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($momento, ENT_QUOTES, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12 col-sm-6">
                <label class="form-label small fw-bold text-secondary">
                  <i class="bi bi-link-45deg me-1" style="color:#7c3aed;"></i>
                  Link <span class="text-muted fw-normal" style="font-size:.7rem;">(YouTube / Spotify)</span>
                </label>
                <div class="input-group">
                  <span class="input-group-text bg-light border-end-0" style="border-color:#dee2e6;">
                    <i class="bi bi-music-note text-muted" style="font-size:.8rem;"></i>
                  </span>
                  <input type="url" id="musica-link" class="form-control border-start-0"
                         placeholder="https://…" style="border-color:#dee2e6;">
                </div>
              </div>
            </div>
            <div class="text-end">
              <button type="button" id="btn-add-musica"
                      class="btn btn-sm fw-bold rounded-pill px-4 shadow-sm"
                      style="background:#7c3aed;color:#fff;border:none;">
                <i class="bi bi-lightbulb me-1"></i> Sugerir
              </button>
            </div>
          </div>
        </div>

        <div class="d-flex align-items-center gap-2 mb-3">
          <button type="button" id="aba-sugestoes"
                  class="btn btn-sm fw-bold rounded-pill px-3"
                  style="background:#7c3aed;color:#fff;border:none;">
            <i class="bi bi-lightbulb me-1"></i> Sugestões
            <span class="badge rounded-pill ms-1" id="cnt-aba-sug"
                  style="background:rgba(255,255,255,.25);color:#fff;font-size:.6rem;"><?= $cnt_sug ?></span>
          </button>
          <button type="button" id="aba-confirmadas"
                  class="btn btn-sm fw-bold rounded-pill px-3"
                  style="background:#f0fdf4;color:#16a34a;border:1.5px solid #86efac;">
            <i class="bi bi-check-circle me-1"></i> Confirmadas
            <span class="badge rounded-pill ms-1" id="cnt-aba-conf"
                  style="background:#dcfce7;color:#16a34a;font-size:.6rem;"><?= $cnt_conf ?></span>
          </button>
        </div>

        <div id="painel-sugestoes">
          <?php if (empty($musicas_sugeridas)): ?>
          <div class="text-center py-5 text-muted" id="sug-vazia">
            <i class="bi bi-lightbulb fs-1 d-block mb-2" style="opacity:.2;color:#7c3aed;"></i>
            <small>Nenhuma sugestão ainda. Adicione a primeira acima!</small>
          </div>
          <?php else: ?>
          <div id="lista-sugestoes-grupos">
            <?php foreach ($musicas_sugeridas as $momento => $musicas_do_momento): ?>
            <div class="musica-grupo mb-3" data-momento="<?= htmlspecialchars($momento, ENT_QUOTES, 'UTF-8') ?>" data-aba="sugestao">
              <div class="musica-grupo-header" style="background:linear-gradient(90deg,#ede9fe,#f5f3ff);">
                <i class="bi bi-collection-play" style="color:#7c3aed;font-size:.85rem;"></i>
                <span class="momento-label" style="color:#5b21b6;"><?= htmlspecialchars($momento, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="cnt-grp-mus" style="background:#7c3aed;"><?= count($musicas_do_momento) ?></span>
              </div>
              <div class="musica-lista-items d-flex flex-column gap-2">
                <?php foreach ($musicas_do_momento as $m):
                  $plat = '';
                  if (!empty($m['link'])) {
                    if (strpos($m['link'], 'youtube') !== false || strpos($m['link'], 'youtu.be') !== false) $plat = 'youtube';
                    elseif (strpos($m['link'], 'spotify') !== false) $plat = 'spotify';
                    else $plat = 'link';
                  }
                ?>
                <div class="musica-item d-flex align-items-center gap-2 p-2 bg-white rounded-3 shadow-sm"
                     data-id="<?= $m['id'] ?>" data-status="sugestao"
                     data-artista="<?= htmlspecialchars($m['artista'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                     data-link="<?= htmlspecialchars($m['link'] ?? '',    ENT_QUOTES, 'UTF-8') ?>"
                     style="border-left:3px solid #a78bfa;">
                  <div class="d-flex align-items-center justify-content-center rounded-2 flex-shrink-0"
                       style="width:34px;height:34px;background:#ede9fe;">
                    <i class="bi bi-lightbulb" style="color:#7c3aed;font-size:.9rem;"></i>
                  </div>
                  <div class="flex-fill" style="min-width:0;">
                    <div class="fw-bold text-dark text-truncate musica-titulo-txt" style="font-size:.84rem;"><?= htmlspecialchars($m['titulo'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (!empty($m['artista'])): ?>
                    <div class="text-muted musica-artista-txt" style="font-size:.69rem;">
                      <i class="bi bi-person-fill me-1" style="font-size:.6rem;"></i><?= htmlspecialchars($m['artista'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php endif; ?>
                  </div>
                  <div class="musica-acoes d-flex align-items-center gap-2 flex-shrink-0">
                    <?php if (!empty($m['link'])): ?>
                    <a href="<?= htmlspecialchars($m['link'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"
                       class="btn btn-sm py-1 px-2 rounded-pill flex-shrink-0"
                       style="background:#ede9fe;color:#7c3aed;font-size:.65rem;border:none;white-space:nowrap;">
                      <?php if ($plat === 'youtube'): ?><i class="bi bi-youtube text-danger me-1"></i>YouTube
                      <?php elseif ($plat === 'spotify'): ?><i class="bi bi-spotify text-success me-1"></i>Spotify
                      <?php else: ?><i class="bi bi-link-45deg me-1"></i>Ouvir<?php endif; ?>
                    </a>
                    <?php endif; ?>
                    <button type="button"
                            class="btn btn-sm py-1 px-2 rounded-pill flex-shrink-0 btn-confirmar-musica"
                            data-id="<?= $m['id'] ?>" data-novo="confirmada"
                            title="Confirmar esta música"
                            style="background:#dcfce7;color:#16a34a;border:1.5px solid #86efac;font-size:.65rem;white-space:nowrap;">
                      <i class="bi bi-check-circle me-1"></i> Confirmar
                    </button>
                    <button type="button"
                            class="btn p-1 border-0 bg-transparent text-danger btn-excluir-musica flex-shrink-0"
                            data-id="<?= $m['id'] ?>" title="Remover">
                      <i class="bi bi-trash" style="font-size:.78rem;"></i>
                    </button>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <div id="painel-confirmadas" style="display:none;">
          <?php if (empty($musicas_confirmadas)): ?>
          <div class="text-center py-5 text-muted" id="conf-vazia">
            <i class="bi bi-check-circle fs-1 d-block mb-2" style="opacity:.2;color:#16a34a;"></i>
            <small>Nenhuma música confirmada ainda.<br>Confirme sugestões na outra aba!</small>
          </div>
          <?php else: ?>
          <div id="lista-confirmadas-grupos">
            <?php foreach ($musicas_confirmadas as $momento => $musicas_do_momento): ?>
            <div class="musica-grupo mb-3" data-momento="<?= htmlspecialchars($momento, ENT_QUOTES, 'UTF-8') ?>" data-aba="confirmada">
              <div class="musica-grupo-header" style="background:linear-gradient(90deg,#dcfce7,#f0fdf4);">
                <i class="bi bi-collection-play" style="color:#16a34a;font-size:.85rem;"></i>
                <span class="momento-label" style="color:#14532d;"><?= htmlspecialchars($momento, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="cnt-grp-mus" style="background:#16a34a;"><?= count($musicas_do_momento) ?></span>
              </div>
              <div class="musica-lista-items d-flex flex-column gap-2">
                <?php foreach ($musicas_do_momento as $m):
                  $plat = '';
                  if (!empty($m['link'])) {
                    if (strpos($m['link'], 'youtube') !== false || strpos($m['link'], 'youtu.be') !== false) $plat = 'youtube';
                    elseif (strpos($m['link'], 'spotify') !== false) $plat = 'spotify';
                    else $plat = 'link';
                  }
                ?>
                <div class="musica-item d-flex align-items-center gap-2 p-2 bg-white rounded-3 shadow-sm"
                     data-id="<?= $m['id'] ?>" data-status="confirmada"
                     data-artista="<?= htmlspecialchars($m['artista'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                     data-link="<?= htmlspecialchars($m['link'] ?? '',    ENT_QUOTES, 'UTF-8') ?>"
                     style="border-left:3px solid #86efac;">
                  <div class="d-flex align-items-center justify-content-center rounded-2 flex-shrink-0"
                       style="width:34px;height:34px;background:#dcfce7;">
                    <i class="bi bi-check-circle-fill" style="color:#16a34a;font-size:.9rem;"></i>
                  </div>
                  <div class="flex-fill" style="min-width:0;">
                    <div class="fw-bold text-dark text-truncate musica-titulo-txt" style="font-size:.84rem;"><?= htmlspecialchars($m['titulo'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (!empty($m['artista'])): ?>
                    <div class="text-muted musica-artista-txt" style="font-size:.69rem;">
                      <i class="bi bi-person-fill me-1" style="font-size:.6rem;"></i><?= htmlspecialchars($m['artista'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php endif; ?>
                  </div>
                  <div class="musica-acoes d-flex align-items-center gap-2 flex-shrink-0">
                    <?php if (!empty($m['link'])): ?>
                    <a href="<?= htmlspecialchars($m['link'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"
                       class="btn btn-sm py-1 px-2 rounded-pill flex-shrink-0"
                       style="background:#dcfce7;color:#16a34a;font-size:.65rem;border:none;white-space:nowrap;">
                      <?php if ($plat === 'youtube'): ?><i class="bi bi-youtube text-danger me-1"></i>YouTube
                      <?php elseif ($plat === 'spotify'): ?><i class="bi bi-spotify text-success me-1"></i>Spotify
                      <?php else: ?><i class="bi bi-link-45deg me-1"></i>Ouvir<?php endif; ?>
                    </a>
                    <?php endif; ?>
                    <button type="button"
                            class="btn btn-sm py-1 px-2 rounded-pill flex-shrink-0 btn-confirmar-musica"
                            data-id="<?= $m['id'] ?>" data-novo="sugestao"
                            title="Mover de volta para sugestões"
                            style="background:#fef3c7;color:#92400e;border:1.5px solid #fcd34d;font-size:.65rem;white-space:nowrap;">
                        <i class="bi bi-arrow-left-circle me-1"></i> Desfazer
                    </button>
                    <button type="button"
                            class="btn p-1 border-0 bg-transparent text-danger btn-excluir-musica flex-shrink-0"
                            data-id="<?= $m['id'] ?>" title="Remover">
                      <i class="bi bi-trash" style="font-size:.78rem;"></i>
                    </button>
                  </div>
                </div>
                <?php endforeach; ?>
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

<div class="modal fade" id="modalNotas" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg rounded-4" style="background:#fffbf0;">
      <div class="modal-header border-0 px-4 pt-4 pb-2" style="background:transparent;">
        <div class="d-flex align-items-center gap-3">
          <div class="rounded-3 d-flex align-items-center justify-content-center shadow-sm"
               style="width:42px;height:42px;background:#fef3c7;border:1.5px solid #fcd34d;">
            <i class="bi bi-journal-text text-warning fs-5"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold mb-0 text-dark">Bloco de Notas</h5>
            <span class="text-muted" style="font-size:.73rem;">Anotações do assessor · visíveis ao casal</span>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4 pb-4 pt-2">
        <div class="card border-0 shadow-sm rounded-4 mb-4" id="card-form-nota"
              style="border: 1.5px solid #fde68a !important; background:#fff;">
          <div class="card-body p-3 p-sm-4">
            <div class="d-flex align-items-center gap-2 mb-3">
              <i class="bi bi-plus-circle-fill text-warning fs-6"></i>
              <span class="fw-bold text-dark small text-uppercase" id="form-nota-label" style="letter-spacing:.06em;">Nova Nota</span>
            </div>
            <div class="d-flex align-items-center gap-3 mb-3">
              <span class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;">Cor:</span>
              <?php
              $swatches = [
                'amarelo' => ['#fef9c3', '#ca8a04'],
                'verde'   => ['#dcfce7', '#16a34a'],
                'azul'    => ['#dbeafe', '#2563eb'],
                'rosa'    => ['#fce7f3', '#db2777'],
                'cinza'   => ['#f1f5f9', '#64748b'],
              ];
              foreach ($swatches as $cor => [$bg, $brd]): ?>
              <label class="cor-nota-label" title="<?= ucfirst($cor) ?>">
                <input type="radio" name="cor_nota_ui" value="<?= $cor ?>"
                       class="d-none cor-nota-radio" <?= $cor === 'amarelo' ? 'checked' : '' ?>>
                <span style="background:<?= $bg ?>;border-color:<?= $brd ?>;"></span>
              </label>
              <?php endforeach; ?>
            </div>
            <input type="hidden" id="nota-id-edit" value="0">
            <input type="text" id="nota-titulo"
                   class="form-control nota-form-input fw-bold mb-2 nota-linhas"
                   placeholder="📌  Título da nota…" maxlength="255"
                   style="font-size:.95rem;padding:.65rem .85rem;">
            <textarea id="nota-conteudo"
                      class="form-control nota-form-input nota-linhas" rows="4"
                      placeholder="Escreva aqui a anotação para o casal…"
                      style="font-size:.88rem;resize:vertical;padding:.65rem .85rem;line-height:1.7;"></textarea>
            <div class="d-flex justify-content-between align-items-center mt-3">
              <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 d-none" id="btn-cancelar-nota">
                <i class="bi bi-x me-1"></i> Cancelar
              </button>
              <button type="button" class="btn btn-sm btn-warning fw-bold rounded-pill px-4 shadow-sm ms-auto" id="btn-salvar-nota">
                <i class="bi bi-floppy me-1"></i> Salvar Nota
              </button>
            </div>
          </div>
        </div>
        <div id="lista-notas-wrap">
          <?php if (empty($lista_notas)): ?>
          <div class="text-center py-5 text-muted" id="notas-vazia">
            <i class="bi bi-journal-x fs-1 d-block mb-2" style="opacity:.25;"></i>
            <small>Nenhuma nota ainda. Crie a primeira acima!</small>
          </div>
          <?php else: ?>
          <div class="mb-2 d-flex align-items-center gap-2">
            <span class="badge bg-warning text-dark rounded-pill px-3 badge-notas-cont" style="font-size:.68rem;">
              <i class="bi bi-journals me-1"></i>
              <span id="notas-badge-count"><?= $total_notas ?></span> nota<?= $total_notas !== 1 ? 's' : '' ?>
            </span>
            <span class="text-muted" style="font-size:.68rem;">· mais recentes primeiro</span>
          </div>
          <div class="row g-3" id="grid-notas">
            <?php
            $cores_bg  = ['amarelo'=>'#fef9c3','verde'=>'#dcfce7','azul'=>'#dbeafe','rosa'=>'#fce7f3','cinza'=>'#f1f5f9'];
            $cores_brd = ['amarelo'=>'#fde047','verde'=>'#86efac','azul'=>'#93c5fd','rosa'=>'#f9a8d4','cinza'=>'#cbd5e1'];
            $cores_txt = ['amarelo'=>'#78350f','verde'=>'#14532d','azul'=>'#1e3a8a','rosa'=>'#831843','cinza'=>'#1e293b'];
            foreach ($lista_notas as $nota):
              $cor  = $nota['cor'] ?? 'amarelo';
              $bgC  = $cores_bg[$cor]  ?? '#fef9c3';
              $brdC = $cores_brd[$cor] ?? '#fde047';
              $txtC = $cores_txt[$cor] ?? '#78350f';
              $dt   = date('d/m/Y \à\s H:i', strtotime($nota['atualizado_em'] ?? $nota['criado_em']));
            ?>
            <div class="col-12 col-sm-6 nota-card-wrap" data-id="<?= $nota['id'] ?>">
              <div class="card border-0 shadow-sm h-100 rounded-4 nota-card"
                   style="background:<?= $bgC ?>;border-left:4px solid <?= $brdC ?>!important;">
                <div class="card-body p-3">
                  <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <h6 class="fw-bold mb-0 text-truncate" style="color:<?= $txtC ?>;font-size:.88rem;line-height:1.3;">
                      <?= htmlspecialchars($nota['titulo'], ENT_QUOTES, 'UTF-8') ?>
                    </h6>
                    <div class="d-flex gap-1 flex-shrink-0">
                      <button type="button" class="btn p-1 border-0 bg-transparent btn-editar-nota"
                              data-id="<?= $nota['id'] ?>"
                              data-titulo="<?= htmlspecialchars($nota['titulo'],   ENT_QUOTES, 'UTF-8') ?>"
                              data-conteudo="<?= htmlspecialchars($nota['conteudo'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                              data-cor="<?= htmlspecialchars($nota['cor'], ENT_QUOTES, 'UTF-8') ?>"
                              title="Editar nota">
                        <i class="bi bi-pencil-fill" style="font-size:.78rem;color:<?= $txtC ?>;opacity:.55;"></i>
                      </button>
                      <button type="button" class="btn p-1 border-0 bg-transparent btn-excluir-nota"
                              data-id="<?= $nota['id'] ?>" title="Excluir nota">
                        <i class="bi bi-trash-fill" style="font-size:.78rem;color:#ef4444;opacity:.6;"></i>
                      </button>
                    </div>
                  </div>
                  <?php if (!empty($nota['conteudo'])): ?>
                  <p class="mb-0" style="color:<?= $txtC ?>;opacity:.82;white-space:pre-wrap;line-height:1.6;font-size:.8rem;">
                    <?= htmlspecialchars($nota['conteudo'], ENT_QUOTES, 'UTF-8') ?>
                  </p>
                  <?php endif; ?>
                  <div class="mt-3 pt-2 border-top d-flex justify-content-between align-items-center"
                       style="border-color:<?= $brdC ?>!important;">
                    <span style="font-size:.6rem;color:<?= $txtC ?>;opacity:.5;">
                      <i class="bi bi-clock me-1"></i><?= $dt ?>
                    </span>
                    <span class="badge rounded-pill"
                          style="font-size:.55rem;background:<?= $bgC ?>;border:1px solid <?= $brdC ?>;color:<?= $txtC ?>;opacity:.7;">
                      <?= htmlspecialchars($nota['autor'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                  </div>
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
const SELF       = window.location.href;
const CSRF_TOKEN  = <?= json_encode($csrf_token) ?>;

/* ---- SINO DE NOTIFICAÇÕES ---- */

// Botão "Marcar lidas": some com o badge e limpa a lista exibida
document.getElementById('btn-marcar-lidas')?.addEventListener('click', function (e) {
  e.stopPropagation();
  const badge = document.querySelector('#dropdown-notificacoes .badge');
  if (badge) badge.remove();
  const lista = document.getElementById('lista-notificacoes');
  if (lista) {
    lista.innerHTML = '<div class="text-center text-muted p-4 small"><i class="bi bi-inbox fs-3 d-block mb-2"></i> Nenhuma atividade ainda.</div>';
  }
  fetch('notificacoes_marcar_lidas.php', { method: 'POST' }).catch(() => {});
});

// Clicar em uma notificação também marca como lida e a remove da lista
document.getElementById('lista-notificacoes')?.addEventListener('click', function (e) {
  const item = e.target.closest('.notif-item');
  if (!item) return;
  const badge = document.querySelector('#dropdown-notificacoes .badge');
  if (badge) badge.remove();
  fetch('notificacoes_marcar_lidas.php', { method: 'POST', keepalive: true }).catch(() => {});
  item.remove();
  const lista = document.getElementById('lista-notificacoes');
  if (lista && !lista.querySelector('.notif-item')) {
    lista.innerHTML = '<div class="text-center text-muted p-4 small"><i class="bi bi-inbox fs-3 d-block mb-2"></i> Nenhuma atividade ainda.</div>';
  }
});

/* ---- EXPORTAR PDF: SEÇÕES ESCOLHIDAS ---- */
document.getElementById('btnGerarPdfSecoes')?.addEventListener('click', function () {
  const marcadas = [...document.querySelectorAll('.pdf-secao-check:checked')].map(c => c.value);
  if (marcadas.length === 0) {
    toast('Marque ao menos uma seção.', 'verm');
    return;
  }
  const url = 'relatorio_pdf.php?id=<?= $evento_id ?>&secoes=' + marcadas.join(',');
  // Tenta abrir em nova aba; se o navegador bloquear o pop-up (retorna null/undefined),
  // cai para navegar na própria aba, que nenhum navegador bloqueia.
  const nova = window.open(url, '_blank');
  if (!nova) {
    window.location.href = url;
  }
});

/* ---- COPIAR LINK DE CONFIRMAÇÃO DE PRESENÇA ---- */
document.getElementById('btn-copiar-link')?.addEventListener('click', async function () {
  const input = document.getElementById('input-link-confirmacao');
  const btn   = this;
  const orig  = btn.innerHTML;
  try {
    await navigator.clipboard.writeText(input.value);
  } catch {
    input.removeAttribute('readonly');
    input.select();
    document.execCommand('copy');
    input.setAttribute('readonly', 'readonly');
  }
  btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> Copiado!';
  setTimeout(() => { btn.innerHTML = orig; }, 1800);
});

/* ---- BUSCA + FILTRO DO CHECKLIST ---- */
(function () {
  const busca = document.getElementById('buscaChecklist');
  if (!busca) return;
  let filtroAtivo = 'todos';

  function aplicarFiltroChecklist() {
    const termo = busca.value.trim().toLowerCase();
    document.querySelectorAll('#lista-etapas-checklist .etapa-wrap').forEach(etapaEl => {
      let algumVisivel = false;
      etapaEl.querySelectorAll('.tarefa-card').forEach(card => {
        const nome   = card.dataset.tarefaNome || '';
        const status = card.dataset.tarefaStatus || '';
        const matchNome   = !termo || nome.includes(termo);
        const matchStatus = filtroAtivo === 'todos' || status === filtroAtivo;
        const visivel = matchNome && matchStatus;
        card.classList.toggle('tarefa-row-hidden', !visivel);
        if (visivel) algumVisivel = true;
      });
      etapaEl.classList.toggle('etapa-hidden', !algumVisivel);
      if (algumVisivel && (termo || filtroAtivo !== 'todos')) {
        const collapseEl = etapaEl.querySelector('.collapse');
        if (collapseEl && !collapseEl.classList.contains('show')) {
          new bootstrap.Collapse(collapseEl, { toggle: false }).show();
        }
      }
    });
  }

  busca.addEventListener('input', aplicarFiltroChecklist);
  document.querySelectorAll('.filtro-check').forEach(btn => {
    btn.addEventListener('click', function () {
      filtroAtivo = this.dataset.f;
      document.querySelectorAll('.filtro-check').forEach(b => {
        b.classList.remove('active', 'btn-primary', 'btn-warning', 'btn-success');
        b.classList.add(b.dataset.f === 'pendente' ? 'btn-outline-warning' : b.dataset.f === 'concluido' ? 'btn-outline-success' : 'btn-outline-primary');
      });
      this.classList.remove('btn-outline-warning', 'btn-outline-success', 'btn-outline-primary');
      this.classList.add('active', this.dataset.f === 'pendente' ? 'btn-warning' : this.dataset.f === 'concluido' ? 'btn-success' : 'btn-primary');
      aplicarFiltroChecklist();
    });
  });
})();

/* ---- TOAST ---- */
function toast(msg, tipo = 'verde') {
  const wrap = document.getElementById('toast-wrap');
  const el   = document.createElement('div');
  el.className = `toast-item ${tipo}`;
  const icons = { verde:'check-circle-fill', verm:'exclamation-circle-fill', info:'info-circle-fill', warn:'exclamation-triangle-fill' };
  el.innerHTML = `<i class="bi bi-${icons[tipo] || 'check-circle-fill'}"></i> ${msg}`;
  wrap.appendChild(el);
  setTimeout(() => {
    el.style.transition = 'opacity .3s, transform .3s';
    el.style.opacity    = '0';
    el.style.transform  = 'translateX(24px)';
    setTimeout(() => el.remove(), 320);
  }, 2800);
}

/* ---- AJAX helper — injeta CSRF automaticamente ---- */
async function ajax(obj) {
  obj.is_ajax    = '1';
  obj.csrf_token = CSRF_TOKEN;
  const fd = new FormData();
  Object.entries(obj).forEach(([k, v]) => fd.append(k, v));
  const r = await fetch(SELF, { method: 'POST', body: fd });
  return r.json();
}

/* ---- BRL helpers — CORRIGIDO: remove "R$ " antes de parsear ---- */
function brl(n) {
  return 'R$ ' + parseFloat(n).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function parseBrl(s) {
  const clean = String(s).replace(/R\$\s?/g, '').replace(/\./g, '').replace(',', '.').trim();
  const val   = parseFloat(clean);
  return isNaN(val) ? 0 : val;
}

/* ---- MODAL DE CONFIRMAÇÃO ---- */
const confirmModal = new bootstrap.Modal(document.getElementById('modalConfirmar'));
let _confirmAction = null;

function showConfirm(titulo, msg, onConfirm, opts = {}) {
  document.getElementById('confirm-title').textContent = titulo;
  document.getElementById('confirm-msg').textContent   = msg;
  const box = document.getElementById('confirm-icon-box');
  box.className = `mx-auto mb-3 rounded-circle d-flex align-items-center justify-content-center ${opts.iconBg || 'bg-danger bg-opacity-10'}`;
  box.style.cssText = 'width:52px;height:52px;';
  document.getElementById('confirm-icon').className = opts.icon || 'bi bi-exclamation-triangle-fill text-danger fs-4';
  const btn = document.getElementById('btn-confirmar');
  btn.className   = `btn btn-sm px-4 rounded-pill fw-bold ${opts.btnClass || 'btn-danger'}`;
  btn.textContent = opts.btnText || 'Confirmar';
  _confirmAction  = onConfirm;
  confirmModal.show();
}

document.getElementById('btn-confirmar').addEventListener('click', () => {
  if (_confirmAction) { _confirmAction(); _confirmAction = null; }
  confirmModal.hide();
});

/* ---- FLASH MESSAGES ---- */
<?php if ($msg_sucesso): ?>
  document.addEventListener('DOMContentLoaded', () => toast(<?= json_encode($msg_sucesso) ?>, 'verde'));
<?php endif; ?>
<?php if ($msg_erro): ?>
  document.addEventListener('DOMContentLoaded', () => toast(<?= json_encode($msg_erro) ?>, 'verm'));
<?php endif; ?>

/* ---- MODAL LOCAIS ---- */
const selectFesta = document.getElementById('select-tem-festa');
if (selectFesta) {
  selectFesta.addEventListener('change', function () {
    document.getElementById('div-local-festa').style.display = this.value === '1' ? 'block' : 'none';
  });
}

/* ---- IMPORTAR PADRÃO ---- */
document.querySelectorAll('.btn-import-padrao').forEach(btn => {
  btn.addEventListener('click', () => {
    const formId = btn.dataset.form;
    showConfirm(
      btn.dataset.titulo || 'Importar cronograma?',
      btn.dataset.msg    || 'As tarefas padrão serão adicionadas ao evento.',
      () => document.getElementById(formId).submit(),
      { icon: 'bi bi-download text-primary fs-4', iconBg: 'bg-primary bg-opacity-10', btnClass: 'btn-primary', btnText: 'Importar' }
    );
  });
});

/* ---- LIMPAR CHECKLIST ---- */
document.getElementById('btn-limpar-checklist')?.addEventListener('click', () => {
  showConfirm(
    'Apagar TODO o Checklist?',
    'Isso remove permanentemente TODAS as tarefas deste evento.',
    () => document.getElementById('form-limpar-checklist').submit(),
    { icon: 'bi bi-trash3-fill text-danger fs-4', btnText: 'Apagar Tudo' }
  );
});

/* ---- RENOMEAR ETAPA ---- */
const modalRenomearEl = document.getElementById('modalRenomear');
if(modalRenomearEl) {
    const modalRenomear = new bootstrap.Modal(modalRenomearEl);
    document.querySelectorAll('.btn-renomear-etapa').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            document.getElementById('renomear_antiga').value = btn.dataset.nome;
            document.getElementById('renomear_nova').value = btn.dataset.nome;
            modalRenomear.show();
        });
    });
}

/* ---- TOGGLE TAREFA (AJAX) ---- */
document.querySelectorAll('.btn-toggle-tarefa').forEach(btn => {
  btn.addEventListener('click', async () => {
    const id       = btn.dataset.id;
    const mtual    = +btn.dataset.status;
    const card     = btn.closest('.tarefa-card');
    const titulo   = card.querySelector('h6');
    const hdrId    = btn.dataset.etapaHdrId;
    const collapso = btn.closest('.collapse');
    const orig     = btn.innerHTML;
    btn.innerHTML  = '<span class="spinner-border spinner-border-sm text-secondary"></span>';
    try {
      const r = await ajax({ alternar_status: '1', id_tarefa: id, status_atual: mtual });
      if (!r.ok) throw new Error();
      const novo = r.novo === 1 || r.novo === '1';
      btn.innerHTML      = `<i class="bi ${novo ? 'bi-check-circle-fill' : 'bi-circle'}"></i>`;
      btn.dataset.status = novo ? '1' : '0';
      btn.classList.toggle('text-success', novo);
      btn.classList.toggle('text-muted',   !novo);
      card.classList.toggle('done', novo);
      card.classList.toggle('pend', !novo);
      if (titulo) {
        titulo.classList.toggle('text-decoration-line-through', novo);
        titulo.classList.toggle('text-muted', novo);
        titulo.classList.toggle('text-dark',  !novo);
      }
      const hdr = document.getElementById(hdrId);
      if (hdr && collapso) {
        const allTasks   = collapso.querySelectorAll('.tarefa-card').length;
        const concluidas = collapso.querySelectorAll('.tarefa-card.done').length;
        const pctE       = allTasks > 0 ? Math.round(concluidas / allTasks * 100) : 0;
        const c = hdr.querySelector('.conc-etapa');
        const b = hdr.querySelector('.barra-mini-fill');
        const p = hdr.querySelector('.pct-etapa');
        const i = hdr.querySelector('.icone-etapa');
        if (c) c.textContent = concluidas;
        if (b) b.style.width = pctE + '%';
        if (p) p.textContent = pctE + '%';
        if (i) i.className   = concluidas === allTasks && allTasks > 0
          ? 'bi bi-check-all text-success fs-5 icone-etapa'
          : 'bi bi-folder2-open text-info fs-5 icone-etapa';
      }
      const totalDone = document.querySelectorAll('.tarefa-card.done').length;
      const totalAll  = document.querySelectorAll('.tarefa-card').length;
      const pctG      = totalAll > 0 ? Math.round(totalDone / totalAll * 100) : 0;
      const lblG = document.getElementById('label-conc-g');
      const barG = document.getElementById('barra-g');
      const ring = document.getElementById('ring-pct');
      if (lblG) lblG.textContent = totalDone;
      if (barG) barG.style.width = pctG + '%';
      if (ring) ring.textContent = pctG + '%';
      toast(novo ? 'Tarefa concluída! ✓' : 'Tarefa desmarcada.', novo ? 'verde' : 'info');
    } catch {
      btn.innerHTML = orig;
      toast('Erro ao atualizar tarefa.', 'verm');
    }
  });
});

/* ---- EXCLUIR TAREFA (AJAX) ---- */
document.querySelectorAll('.btn-del-task').forEach(btn => {
  btn.addEventListener('click', () => {
    const id   = btn.dataset.id;
    const card = btn.closest('.tarefa-card');
    showConfirm(
      'Remover esta tarefa?',
      'Esta ação não pode ser desfeita.',
      async () => {
        try {
          const r = await ajax({ excluir_tarefa: '1', id_tarefa: id });
          if (r.ok) {
            card.style.transition = 'opacity .3s, transform .3s';
            card.style.opacity    = '0';
            card.style.transform  = 'scale(.95)';
            setTimeout(() => {
              const collapso = card.closest('.collapse');
              card.remove();
              if (collapso) {
                const hdr = document.querySelector(`[data-bs-target="#${collapso.id}"]`);
                if (hdr) {
                  const remaining  = collapso.querySelectorAll('.tarefa-card').length;
                  const concluidas = collapso.querySelectorAll('.tarefa-card.done').length;
                  const pctE       = remaining > 0 ? Math.round(concluidas / remaining * 100) : 0;
                  const badge = hdr.querySelector('.badge');
                  if (badge) badge.innerHTML = `<span class="conc-etapa">${concluidas}</span>/${remaining}`;
                  const b = hdr.querySelector('.barra-mini-fill');
                  const p = hdr.querySelector('.pct-etapa');
                  const i = hdr.querySelector('.icone-etapa');
                  if (b) b.style.width = pctE + '%';
                  if (p) p.textContent = pctE + '%';
                  if (i) i.className   = remaining > 0 && concluidas === remaining
                    ? 'bi bi-check-all text-success fs-5 icone-etapa'
                    : 'bi bi-folder2-open text-info fs-5 icone-etapa';
                }
              }
              const totalDone = document.querySelectorAll('.tarefa-card.done').length;
              const totalAll  = document.querySelectorAll('.tarefa-card').length;
              const pctG      = totalAll > 0 ? Math.round(totalDone / totalAll * 100) : 0;
              const lblG = document.getElementById('label-conc-g');
              const barG = document.getElementById('barra-g');
              const ring = document.getElementById('ring-pct');
              if (lblG) lblG.textContent = totalDone;
              if (barG) barG.style.width = pctG + '%';
              if (ring) ring.textContent = pctG + '%';
            }, 310);
            toast('Tarefa removida!', 'verm');
          } else {
            toast(r.msg || 'Acesso Negado!', 'verm');
          }
        } catch { toast('Erro ao remover tarefa.', 'verm'); }
      }
    );
  });
});

/* ---- COMENTÁRIOS DE ETAPAS (AJAX) ---- */
document.querySelectorAll('.form-ajax-etapa').forEach(form => {
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const fd    = new FormData(form);
    const lista = form.previousElementSibling;
    const input = form.querySelector('input[type="text"]');
    const btn   = form.querySelector('button');
    const orig  = btn.innerHTML;
    fd.append('is_ajax',    '1');
    fd.append('csrf_token', CSRF_TOKEN);
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    try {
      const r = await (await fetch(SELF, { method: 'POST', body: fd })).json();
      if (r.ok) {
        lista.insertAdjacentHTML('beforeend', `
          <div class="my-1 bg-white border p-2 rounded-3 shadow-sm" style="font-size:.82rem;">
            <span class="badge bg-primary rounded-pill me-2">${r.autor}</span>${r.texto}
          </div>`);
        input.value = '';
        toast('Nota salva!');
      }
    } catch { toast('Erro ao salvar nota.', 'verm'); }
    btn.innerHTML = orig;
  });
});

/* ---- COMENTÁRIOS DE TAREFAS (AJAX) ---- */
document.querySelectorAll('.form-ajax-tarefa').forEach(form => {
  form.addEventListener('submit', async e => {
    e.preventDefault();
    const fd    = new FormData(form);
    const lista = form.previousElementSibling;
    const input = form.querySelector('input[type="text"]');
    const btn   = form.querySelector('button');
    const orig  = btn.innerHTML;
    fd.append('is_ajax',    '1');
    fd.append('csrf_token', CSRF_TOKEN);
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    try {
      const r = await (await fetch(SELF, { method: 'POST', body: fd })).json();
      if (r.ok) {
        lista.insertAdjacentHTML('beforeend', `
          <div class="small my-1 bg-light p-2 rounded-3" style="font-size:.77rem;border:1px solid #f1f5f9;">
            <strong class="text-primary">${r.autor}:</strong> ${r.texto}
          </div>`);
        input.value = '';
        toast('Comentário enviado!');
      }
    } catch { toast('Erro ao comentar.', 'verm'); }
    btn.innerHTML = orig;
  });
});

/* ---- PAGAMENTO FORNECEDORES (AJAX) ---- */
let totalPagoGeralAdm = <?= json_encode($total_forn_pago) ?>;
const totalContratoGeralAdm = <?= json_encode($total_forn) ?>;

function atualizarChipsGeraisAdm() {
  const restante = Math.max(0, totalContratoGeralAdm - totalPagoGeralAdm);
  const pct      = totalContratoGeralAdm > 0 ? Math.round(totalPagoGeralAdm / totalContratoGeralAdm * 100) : 0;
  const elPago   = document.getElementById('adm-total-pago');
  const elRest   = document.getElementById('adm-total-rest');
  const elBarra  = document.getElementById('adm-barra-pago');
  const elPct    = document.getElementById('adm-pct-pago');
  if (elPago)  elPago.textContent  = brl(totalPagoGeralAdm);
  if (elRest)  elRest.textContent  = brl(restante);
  if (elBarra) elBarra.style.width = pct + '%';
  if (elPct)   elPct.textContent   = pct + '%';
}

function atualizarLinhaFornecedorAdm(row, pago, total) {
  const rest = Math.max(0, total - pago);
  const pct  = total > 0 ? Math.round(pago / total * 100) : 0;
  const quit = rest <= 0;

  const pagoAnterior = parseFloat(row.dataset.pago || 0);
  totalPagoGeralAdm += (pago - pagoAnterior);
  row.dataset.pago = pago;

  const barra = row.querySelector('.forn-barra-fill-adm');
  if (barra) {
    barra.className          = 'forn-barra-fill-adm ' + (quit ? 'bg-success' : pct >= 50 ? 'bg-info' : 'bg-warning');
    barra.style.width        = pct + '%';
    barra.style.height       = '100%';
    barra.style.borderRadius = '999px';
    barra.style.transition   = 'width .4s';
  }
  const badge = row.querySelector('.forn-badge-adm');
  if (badge) {
    badge.textContent = quit ? '✓ Quitado' : (pct > 0 ? pct + '%' : '—');
    badge.className   = 'forn-pago-badge forn-badge-adm ' + (quit ? 'bg-success text-white' : 'bg-warning text-dark');
  }
  const restEl = row.querySelector('.forn-rest-adm');
  if (restEl) {
    restEl.textContent = quit ? '✓ Quitado' : 'Resta ' + brl(rest);
    restEl.className   = 'forn-val-rest forn-rest-adm ' + (quit ? 'ok' : 'nok');
  }
  const pagoTxt = row.querySelector('.forn-pago-valor-txt');
  if (pagoTxt) pagoTxt.textContent = pago.toLocaleString('pt-BR', { minimumFractionDigits: 2 });

  atualizarChipsGeraisAdm();
}

/* Somar novo pagamento ao valor já pago */
document.querySelectorAll('.forn-btn-add-adm').forEach(btn => {
  btn.addEventListener('click', async () => {
    const fid   = btn.dataset.id;
    const row   = document.getElementById('forn-adm-' + fid);
    const input = row.querySelector('.forn-input-add');
    const total = parseFloat(input.dataset.total || 0);
    const valor = parseBrl(input.value);
    if (!valor || valor <= 0) { toast('Informe um valor maior que zero.', 'verm'); return; }
    const origBtn = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    btn.disabled  = true;
    try {
      const r = await ajax({ adicionar_pagamento: '1', fornecedor_id: fid, valor_pago: valor.toString() });
      if (r.ok) {
        const pago = parseFloat(r.valor_pago);
        const quit = parseFloat(r.valor_rest) <= 0;
        atualizarLinhaFornecedorAdm(row, pago, total);
        input.value = '';
        toast(quit ? 'Pagamento quitado! 🎉' : 'Pagamento adicionado!', quit ? 'verde' : 'info');
      } else {
        toast(r.msg || 'Erro ao salvar pagamento.', 'verm');
      }
    } catch { toast('Erro de conexão. Tente novamente.', 'verm'); }
    btn.innerHTML = origBtn;
    btn.disabled  = false;
  });
});

/* Alternar para o modo de corrigir o valor pago */
document.querySelectorAll('.forn-btn-editar-pago').forEach(btn => {
  btn.addEventListener('click', () => {
    const fid       = btn.dataset.id;
    const row       = document.getElementById('forn-adm-' + fid);
    const addWrap   = row.querySelector('.forn-add-wrap');
    const editWrap  = row.querySelector('.forn-edit-wrap');
    const editInput = editWrap.querySelector('.forn-input-edit');
    editInput.value = parseFloat(row.dataset.pago || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
    addWrap.style.display  = 'none';
    editWrap.style.display = 'flex';
    editInput.focus();
    editInput.select();
  });
});

/* Cancelar a correção */
document.querySelectorAll('.forn-btn-cancelar-edit').forEach(btn => {
  btn.addEventListener('click', () => {
    const row = btn.closest('.forn-pago-row');
    row.querySelector('.forn-edit-wrap').style.display = 'none';
    row.querySelector('.forn-add-wrap').style.display  = 'flex';
  });
});

/* Salvar a correção (sobrescreve o valor pago) */
document.querySelectorAll('.forn-btn-salvar-edit-adm').forEach(btn => {
  btn.addEventListener('click', async () => {
    const fid   = btn.dataset.id;
    const row   = document.getElementById('forn-adm-' + fid);
    const input = row.querySelector('.forn-input-edit');
    const total = parseFloat(input.dataset.total || 0);
    let   valor = parseBrl(input.value);
    if (valor < 0) { toast('O valor não pode ser negativo.', 'verm'); return; }
    if (valor > total) {
      toast('Valor maior que o contrato! Ajustado para o total.', 'info');
      valor = total;
    }
    const origBtn = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    btn.disabled  = true;
    try {
      const r = await ajax({ atualizar_valor_pago: '1', fornecedor_id: fid, valor_pago: valor.toString() });
      if (r.ok) {
        const pago = parseFloat(r.valor_pago);
        atualizarLinhaFornecedorAdm(row, pago, total);
        row.querySelector('.forn-edit-wrap').style.display = 'none';
        row.querySelector('.forn-add-wrap').style.display  = 'flex';
        toast('Valor corrigido!', 'info');
      } else {
        toast(r.msg || 'Erro ao salvar pagamento.', 'verm');
      }
    } catch { toast('Erro de conexão. Tente novamente.', 'verm'); }
    btn.innerHTML = origBtn;
    btn.disabled  = false;
  });
});

document.querySelectorAll('.forn-input-pago-adm').forEach(input => {
  input.addEventListener('blur',  () => {
    if (input.value.trim() === '') return;
    const n = parseBrl(input.value);
    if (!isNaN(n)) input.value = n.toLocaleString('pt-BR', { minimumFractionDigits: 2 });
  });
  input.addEventListener('focus', () => input.select());
});

/* ---- CONVIDADOS ---- */
function deltaCntTotal(n) {
  const e = document.getElementById('cnt-total');
  if (e) e.textContent = Math.max(0, +e.textContent + n);
}
function deltaCntStatus(conf, n) {
  const el = document.getElementById(conf ? 'cnt-conf' : 'cnt-pend');
  if (el) el.textContent = Math.max(0, +el.textContent + n);
  if (conf) {
    const badge = document.getElementById('cnt-badge-conf');
    if (badge) badge.textContent = Math.max(0, +badge.textContent + n);
  }
}

function bindToggleConv(btn) {
  btn.addEventListener('click', async () => {
    const row   = btn.closest('.conv-row');
    const id    = btn.dataset.id;
    const mtual = +row.dataset.conf;
    const badge = btn.querySelector('.badge');
    const orig  = badge.innerHTML;
    badge.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    try {
      const r = await ajax({ toggle_convidado: '1', convidado_id: id, status_atual: mtual });
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
      toast('Erro ao atualizar.', 'verm');
    }
  });
}

function bindExcluirConv(btn) {
  btn.addEventListener('click', () => {
    const row  = btn.closest('.conv-row');
    const id   = row.dataset.id;
    const conf = +row.dataset.conf;
    showConfirm(
      'Remover convidado?',
      'Esta ação não pode ser desfeita.',
      async () => {
        try {
          const r = await ajax({ excluir_convidado: '1', convidado_id: id });
          if (r.ok) {
            row.style.transition = 'opacity .3s, transform .3s';
            row.style.opacity    = '0';
            row.style.transform  = 'scale(.95)';
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
      }
    );
  });
}

document.querySelectorAll('.conv-row').forEach(row => {
  const t = row.querySelector('.btn-toggle-conv');
  const x = row.querySelector('.btn-excluir-conv');
  if (t) bindToggleConv(t);
  if (x) bindExcluirConv(x);
});

/* ============================================================
   BLOCO DE NOTAS
   ============================================================ */
const NOTAS_CORES_BG  = { amarelo:'#fef9c3', verde:'#dcfce7', azul:'#dbeafe', rosa:'#fce7f3', cinza:'#f1f5f9' };
const NOTAS_CORES_BRD = { amarelo:'#fde047', verde:'#86efac', azul:'#93c5fd', rosa:'#f9a8d4', cinza:'#cbd5e1' };
const NOTAS_CORES_TXT = { amarelo:'#78350f', verde:'#14532d', azul:'#1e3a8a', rosa:'#831843', cinza:'#1e293b' };

function notaCorSelecionada() {
  return (document.querySelector('.cor-nota-radio:checked') || {}).value || 'amarelo';
}
function resetarFormNota() {
  document.getElementById('nota-id-edit').value  = '0';
  document.getElementById('nota-titulo').value   = '';
  document.getElementById('nota-conteudo').value = '';
  const rd = document.querySelector('.cor-nota-radio[value="amarelo"]');
  if (rd) rd.checked = true;
  document.getElementById('form-nota-label').textContent = 'Nova Nota';
  document.getElementById('btn-cancelar-nota').classList.add('d-none');
  document.getElementById('btn-salvar-nota').innerHTML = '<i class="bi bi-floppy me-1"></i> Salvar Nota';
}

function atualizarContadoresNotas() {
  const total  = document.querySelectorAll('#grid-notas .nota-card-wrap').length;
  const sufixo = total !== 1 ? 's' : '';
  const txt    = total + ' nota' + sufixo;

  const badgeSide = document.getElementById('notas-count-badge');
  if (badgeSide) badgeSide.textContent = txt;

  const contBadge = document.querySelector('.badge-notas-cont');
  if (contBadge) {
    contBadge.innerHTML = `<i class="bi bi-journals me-1"></i>${total} nota${sufixo}`;
  }
}

function notaHtmlCard(r, cor) {
  const bg  = NOTAS_CORES_BG[cor]  || '#fef9c3';
  const brd = NOTAS_CORES_BRD[cor] || '#fde047';
  const txt = NOTAS_CORES_TXT[cor] || '#78350f';
  const dt  = r.atualizado || new Date().toLocaleString('pt-BR', {
    day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit'
  }).replace(',', ' às');

  const conteudoHtml = r.conteudo
    ? `<p class="mb-0" style="color:${txt};opacity:.82;white-space:pre-wrap;line-height:1.6;font-size:.8rem;">${r.conteudo}</p>`
    : '';
  return `
    <div class="col-12 col-sm-6 nota-card-wrap" data-id="${r.id}">
      <div class="card border-0 shadow-sm h-100 rounded-4 nota-card"
           style="background:${bg};border-left:4px solid ${brd}!important;">
        <div class="card-body p-3">
          <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
            <h6 class="fw-bold mb-0 text-truncate" style="color:${txt};font-size:.88rem;line-height:1.3;">${r.titulo}</h6>
            <div class="d-flex gap-1 flex-shrink-0">
              <button type="button" class="btn p-1 border-0 bg-transparent btn-editar-nota"
                      data-id="${r.id}"
                      data-titulo="${r.titulo}"
                      data-conteudo="${r.conteudo}"
                      data-cor="${cor}" title="Editar nota">
                <i class="bi bi-pencil-fill" style="font-size:.78rem;color:${txt};opacity:.55;"></i>
              </button>
              <button type="button" class="btn p-1 border-0 bg-transparent btn-excluir-nota"
                      data-id="${r.id}" title="Excluir nota">
                <i class="bi bi-trash-fill" style="font-size:.78rem;color:#ef4444;opacity:.6;"></i>
              </button>
            </div>
          </div>
          ${conteudoHtml}
          <div class="mt-3 pt-2 border-top d-flex justify-content-between align-items-center"
               style="border-color:${brd}!important;">
            <span style="font-size:.6rem;color:${txt};opacity:.5;"><i class="bi bi-clock me-1"></i>${dt}</span>
            <span class="badge rounded-pill"
                  style="font-size:.55rem;background:${bg};border:1px solid ${brd};color:${txt};opacity:.7;">
              Assessoria
            </span>
          </div>
        </div>
      </div>
    </div>`;
}

document.getElementById('btn-salvar-nota')?.addEventListener('click', async () => {
  const id       = +(document.getElementById('nota-id-edit').value || 0);
  const titulo   = document.getElementById('nota-titulo').value.trim();
  const conteudo = document.getElementById('nota-conteudo').value.trim();
  const cor      = notaCorSelecionada();
  if (!titulo) { toast('Informe o título da nota.', 'warn'); return; }
  const btn  = document.getElementById('btn-salvar-nota');
  const orig = btn.innerHTML;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Salvando…';
  btn.disabled  = true;
  try {
    const r = await ajax({ salvar_nota: '1', nota_id: id, titulo_nota: titulo, conteudo_nota: conteudo, cor_nota: cor });
    if (r.ok) {
      document.getElementById('notas-vazia')?.remove();
      let grid = document.getElementById('grid-notas');
      if (!grid) {
        const wrap = document.getElementById('lista-notas-wrap');
        wrap.innerHTML = `
          <div class="mb-2 d-flex align-items-center gap-2">
            <span class="badge bg-warning text-dark rounded-pill px-3 badge-notas-cont" style="font-size:.68rem;">
              <i class="bi bi-journals me-1"></i>0 notas
            </span>
            <span class="text-muted" style="font-size:.68rem;">· mais recentes primeiro</span>
          </div>
          <div class="row g-3" id="grid-notas"></div>`;
        grid = document.getElementById('grid-notas');
      }
      const html = notaHtmlCard(r, cor);
      if (r.novo) { grid.insertAdjacentHTML('afterbegin', html); }
      else {
        const antigo = grid.querySelector(`.nota-card-wrap[data-id="${r.id}"]`);
        if (antigo) antigo.outerHTML = html;
      }
      bindBotoesNota();
      resetarFormNota();
      atualizarContadoresNotas();
      toast(r.novo ? 'Nota criada! 📝' : 'Nota atualizada! ✏️', 'verde');
    } else { toast(r.msg || 'Erro ao salvar nota.', 'verm'); }
  } catch { toast('Erro de conexão. Tente novamente.', 'verm'); }
  btn.innerHTML = orig;
  btn.disabled  = false;
});

document.getElementById('btn-cancelar-nota')?.addEventListener('click', resetarFormNota);

function bindBotoesNota() {
  document.querySelectorAll('.btn-editar-nota').forEach(btn => {
    btn.onclick = () => {
      document.getElementById('nota-id-edit').value  = btn.dataset.id;
      document.getElementById('nota-titulo').value   = btn.dataset.titulo;
      document.getElementById('nota-conteudo').value = btn.dataset.conteudo;
      const rd = document.querySelector(`.cor-nota-radio[value="${btn.dataset.cor}"]`);
      if (rd) rd.checked = true;
      document.getElementById('form-nota-label').textContent = '✏️ Editando Nota';
      document.getElementById('btn-cancelar-nota').classList.remove('d-none');
      document.getElementById('card-form-nota').scrollIntoView({ behavior: 'smooth', block: 'start' });
      setTimeout(() => document.getElementById('nota-titulo').focus(), 350);
    };
  });
  document.querySelectorAll('.btn-excluir-nota').forEach(btn => {
    btn.onclick = () => {
      const id   = btn.dataset.id;
      const wrap = btn.closest('.nota-card-wrap');
      showConfirm(
        'Excluir esta nota?',
        'Esta ação não pode ser desfeita.',
        async () => {
          try {
            const r = await ajax({ excluir_nota: '1', nota_id: id });
            if (r.ok) {
              wrap.style.transition = 'opacity .25s, transform .25s';
              wrap.style.opacity    = '0';
              wrap.style.transform  = 'scale(.92)';
              setTimeout(() => {
                wrap.remove();
                const grid = document.getElementById('grid-notas');
                if (grid && !grid.querySelector('.nota-card-wrap')) {
                  document.getElementById('lista-notas-wrap').innerHTML =
                    `<div class="text-center py-5 text-muted" id="notas-vazia">
                      <i class="bi bi-journal-x fs-1 d-block mb-2" style="opacity:.25;"></i>
                      <small>Nenhuma nota ainda. Crie a primeira acima!</small>
                    </div>`;
                }
                atualizarContadoresNotas();
              }, 280);
              toast('Nota excluída.', 'verm');
            }
          } catch { toast('Erro ao excluir nota.', 'verm'); }
        },
        { icon: 'bi bi-journal-x text-danger fs-4', btnText: 'Excluir Nota' }
      );
    };
  });
}

bindBotoesNota();
document.getElementById('modalNotas')?.addEventListener('hidden.bs.modal', resetarFormNota);

/* ============================================================
   PLAYLIST — MÚSICAS COM SUGESTÃO E CONFIRMAÇÃO
   ============================================================ */

function urlSegura(url) {
  if (!url) return '';
  try {
    const u = new URL(url);
    if (u.protocol !== 'http:' && u.protocol !== 'https:') return '';
    return url;
  } catch { return ''; }
}

function detectarPlataforma(url) {
  if (!url) return null;
  if (url.includes('youtube.com') || url.includes('youtu.be')) return 'youtube';
  if (url.includes('spotify.com')) return 'spotify';
  return 'link';
}

function linkBtnHtml(link, accentBg, accentColor) {
  const safe = urlSegura(link);
  if (!safe) return '';
  const plat = detectarPlataforma(safe);
  const safeAttr = safe.replace(/"/g, '&quot;');
  let icone = `<i class="bi bi-link-45deg me-1"></i>Ouvir`;
  if (plat === 'youtube') icone = `<i class="bi bi-youtube text-danger me-1"></i>YouTube`;
  if (plat === 'spotify') icone = `<i class="bi bi-spotify text-success me-1"></i>Spotify`;
  return `<a href="${safeAttr}" target="_blank" rel="noopener noreferrer"
               class="btn btn-sm py-1 px-2 rounded-pill flex-shrink-0"
               style="background:${accentBg};color:${accentColor};font-size:.65rem;border:none;white-space:nowrap;"
               title="Abrir link">${icone}</a>`;
}

function musicaCardHtml(m) {
  const eSug = m.status === 'sugestao';
  const cor  = eSug
    ? { brd:'#a78bfa', bg:'#ede9fe', txt:'#7c3aed', icone:'bi-lightbulb' }
    : { brd:'#86efac', bg:'#dcfce7', txt:'#16a34a', icone:'bi-check-circle-fill' };

  const tituloEsc   = (m.titulo  || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const artistaEsc  = (m.artista || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  const linkEsc     = (urlSegura(m.link || '')).replace(/"/g,'&quot;');

  const artistaHtml = m.artista
    ? `<div class="text-muted musica-artista-txt" style="font-size:.69rem;"><i class="bi bi-person-fill me-1" style="font-size:.6rem;"></i>${artistaEsc}</div>`
    : '';
  const confirmarBtn = eSug
    ? `<button type="button" class="btn btn-sm py-1 px-2 rounded-pill flex-shrink-0 btn-confirmar-musica"
                data-id="${m.id}" data-novo="confirmada"
                style="background:#dcfce7;color:#16a34a;border:1.5px solid #86efac;font-size:.65rem;white-space:nowrap;">
         <i class="bi bi-check-circle me-1"></i> Confirmar</button>`
    : `<button type="button" class="btn btn-sm py-1 px-2 rounded-pill flex-shrink-0 btn-confirmar-musica"
                data-id="${m.id}" data-novo="sugestao"
                style="background:#fef3c7;color:#92400e;border:1.5px solid #fcd34d;font-size:.65rem;white-space:nowrap;">
         <i class="bi bi-arrow-left-circle me-1"></i> Desfazer</button>`;
  return `
    <div class="musica-item d-flex align-items-center gap-2 p-2 bg-white rounded-3 shadow-sm"
         data-id="${m.id}" data-status="${m.status}"
         data-artista="${artistaEsc}" data-link="${linkEsc}"
         style="border-left:3px solid ${cor.brd};">
      <div class="d-flex align-items-center justify-content-center rounded-2 flex-shrink-0"
           style="width:34px;height:34px;background:${cor.bg};">
        <i class="bi ${cor.icone}" style="color:${cor.txt};font-size:.9rem;"></i>
      </div>
      <div class="flex-fill" style="min-width:0;">
        <div class="fw-bold text-dark text-truncate musica-titulo-txt" style="font-size:.84rem;">${tituloEsc}</div>
        ${artistaHtml}
      </div>
      <div class="musica-acoes d-flex align-items-center gap-2 flex-shrink-0">
        ${linkBtnHtml(m.link, cor.bg, cor.txt)}
        ${confirmarBtn}
        <button type="button" class="btn p-1 border-0 bg-transparent text-danger btn-excluir-musica flex-shrink-0"
                data-id="${m.id}" title="Remover música">
          <i class="bi bi-trash" style="font-size:.78rem;"></i>
        </button>
      </div>
    </div>`;
}

function atualizarContadoresMusicas() {
  const sug   = document.querySelectorAll('#painel-sugestoes .musica-item').length;
  const conf  = document.querySelectorAll('#painel-confirmadas .musica-item').length;
  const total = sug + conf;
  const sideEl = document.getElementById('musicas-count-badge');
  if (sideEl) sideEl.textContent = total + ' música' + (total !== 1 ? 's' : '');
  const bs = document.getElementById('cnt-aba-sug');
  const bc = document.getElementById('cnt-aba-conf');
  if (bs) bs.textContent = sug;
  if (bc) bc.textContent = conf;
}

function cssEscape(str) {
  if (typeof CSS !== 'undefined' && CSS.escape) return CSS.escape(str);
  return str.replace(/[^\w-]/g, c => '\\' + c);
}

function garantizarGrupo(painel, momento, aba) {
  const wrapId = aba === 'sugestao' ? 'lista-sugestoes-grupos' : 'lista-confirmadas-grupos';
  let wrap = document.getElementById(wrapId);
  if (!wrap) {
    wrap = document.createElement('div');
    wrap.id = wrapId;
    painel.appendChild(wrap);
  }
  const eSug = aba === 'sugestao';
  const cor  = eSug
    ? { bg:'linear-gradient(90deg,#ede9fe,#f5f3ff)', txt:'#5b21b6', badge:'#7c3aed' }
    : { bg:'linear-gradient(90deg,#dcfce7,#f0fdf4)', txt:'#14532d', badge:'#16a34a' };
  const momentoAttr = momento.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  let grupo = wrap.querySelector(`.musica-grupo[data-aba="${aba}"][data-momento="${cssEscape(momento)}"]`);
  if (!grupo) {
    grupo = document.createElement('div');
    grupo.className = 'musica-grupo mb-3';
    grupo.dataset.aba       = aba;
    grupo.dataset.momento = momento;
    grupo.innerHTML = `
      <div class="musica-grupo-header" style="background:${cor.bg};">
        <i class="bi bi-collection-play" style="color:${cor.badge};font-size:.85rem;"></i>
        <span class="momento-label" style="color:${cor.txt};">${momentoAttr}</span>
        <span class="cnt-grp-mus" style="background:${cor.badge};">0</span>
      </div>
      <div class="musica-lista-items d-flex flex-column gap-2"></div>`;
    wrap.appendChild(grupo);
  }
  return grupo;
}

function verificarPainelVazio(aba) {
  const eSug   = aba === 'sugestao';
  const painel = document.getElementById(eSug ? 'painel-sugestoes' : 'painel-confirmadas');
  if (!painel) return;
  const itens = painel.querySelectorAll('.musica-item').length;
  const idVazio = eSug ? 'sug-vazia' : 'conf-vazia';
  if (itens === 0 && !painel.querySelector('#' + idVazio)) {
    const icn = eSug ? 'bi-lightbulb' : 'bi-check-circle';
    const cor = eSug ? '#7c3aed' : '#16a34a';
    const msg = eSug
      ? 'Nenhuma sugestão ainda. Adicione a primeira acima!'
      : 'Nenhuma música confirmada ainda.<br>Confirme sugestões na outra aba!';
    painel.insertAdjacentHTML('beforeend',
      `<div class="text-center py-5 text-muted" id="${idVazio}">
        <i class="bi ${icn} fs-1 d-block mb-2" style="opacity:.2;color:${cor};"></i>
        <small>${msg}</small>
      </div>`);
  }
}

function moverCard(card, novoStatus) {
  const momento = card.closest('.musica-grupo').dataset.momento;
  const m = {
    id:      card.dataset.id,
    titulo:  card.querySelector('.musica-titulo-txt')?.textContent?.trim() || '',
    artista: card.dataset.artista || '',
    link:    card.dataset.link    || '',
    status:  novoStatus,
    momento,
  };
  card.style.transition = 'opacity .25s, transform .25s';
  card.style.opacity    = '0';
  card.style.transform  = 'scale(.95)';
  setTimeout(() => {
    const grupoVelho = card.closest('.musica-grupo');
    card.remove();
    const lista = grupoVelho?.querySelector('.musica-lista-items');
    if (lista && lista.querySelectorAll('.musica-item').length === 0) grupoVelho.remove();
    const abaOrigem = novoStatus === 'confirmada' ? 'sugestao' : 'confirmada';
    verificarPainelVazio(abaOrigem);
    const idVazioDestino = novoStatus === 'confirmada' ? 'conf-vazia' : 'sug-vazia';
    document.getElementById(idVazioDestino)?.remove();
    const painelDestino = document.getElementById(novoStatus === 'confirmada' ? 'painel-confirmadas' : 'painel-sugestoes');
    const abaDestino    = novoStatus === 'confirmada' ? 'confirmada' : 'sugestao';
    const grupo = garantizarGrupo(painelDestino, momento, abaDestino);
    const listaItems = grupo.querySelector('.musica-lista-items');
    listaItems.insertAdjacentHTML('beforeend', musicaCardHtml(m));
    const cnt = grupo.querySelector('.cnt-grp-mus');
    if (cnt) cnt.textContent = listaItems.querySelectorAll('.musica-item').length;
    const novoCard = listaItems.querySelector(`.musica-item[data-id="${m.id}"]`);
    if (novoCard) {
      const btnConf = novoCard.querySelector('.btn-confirmar-musica');
      const btnDel  = novoCard.querySelector('.btn-excluir-musica');
      if (btnConf) bindConfirmarMusica(btnConf);
      if (btnDel)  bindExcluirMusica(btnDel);
    }
    atualizarContadoresMusicas();
  }, 280);
}

function bindConfirmarMusica(btn) {
  if (!btn) return;
  btn.addEventListener('click', async () => {
    const id         = btn.dataset.id;
    const novoStatus = btn.dataset.novo;
    const card       = btn.closest('.musica-item');
    const origHtml   = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    btn.disabled  = true;
    try {
      const r = await ajax({ confirmar_musica: '1', musica_id: id, novo_status: novoStatus });
      if (r.ok) {
        moverCard(card, novoStatus);
        toast(novoStatus === 'confirmada' ? 'Música confirmada! ✓' : 'Música movida para sugestões.', novoStatus === 'confirmada' ? 'verde' : 'info');
      } else {
        toast('Erro ao alterar status.', 'verm');
        btn.innerHTML = origHtml;
        btn.disabled  = false;
      }
    } catch {
      toast('Erro de conexão.', 'verm');
      btn.innerHTML = origHtml;
      btn.disabled  = false;
    }
  });
}

function bindExcluirMusica(btn) {
  if (!btn) return;
  btn.addEventListener('click', () => {
    const id    = btn.dataset.id;
    const item  = btn.closest('.musica-item');
    const grupo = btn.closest('.musica-grupo');
    const aba   = grupo?.dataset.aba || 'sugestao';
    showConfirm(
      'Remover esta música?',
      'Esta ação não pode ser desfeita.',
      async () => {
        try {
          const r = await ajax({ excluir_musica: '1', musica_id: id });
          if (r.ok) {
            item.style.transition = 'opacity .3s, transform .3s';
            item.style.opacity    = '0';
            item.style.transform  = 'scale(.95)';
            setTimeout(() => {
              item.remove();
              if (grupo) {
                const rest = grupo.querySelectorAll('.musica-item').length;
                const cnt  = grupo.querySelector('.cnt-grp-mus');
                if (cnt) cnt.textContent = rest;
                if (rest === 0) grupo.remove();
              }
              verificarPainelVazio(aba);
              atualizarContadoresMusicas();
            }, 310);
            toast('Música removida.', 'verm');
          }
        } catch { toast('Erro ao remover música.', 'verm'); }
      },
      { icon: 'bi bi-music-note-beamed text-danger fs-4', btnText: 'Remover' }
    );
  });
}

function inserirMusicaSugerida(m) {
  document.getElementById('sug-vazia')?.remove();
  const painel = document.getElementById('painel-sugestoes');
  const grupo  = garantizarGrupo(painel, m.momento, 'sugestao');
  const lista  = grupo.querySelector('.musica-lista-items');
  lista.insertAdjacentHTML('beforeend', musicaCardHtml(m));
  const cnt = grupo.querySelector('.cnt-grp-mus');
  if (cnt) cnt.textContent = lista.querySelectorAll('.musica-item').length;
  const novoCard = lista.querySelector(`.musica-item[data-id="${m.id}"]`);
  if (novoCard) {
    bindConfirmarMusica(novoCard.querySelector('.btn-confirmar-musica'));
    bindExcluirMusica(novoCard.querySelector('.btn-excluir-musica'));
  }
}

document.querySelectorAll('.btn-confirmar-musica').forEach(btn => bindConfirmarMusica(btn));
document.querySelectorAll('.btn-excluir-musica').forEach(btn => bindExcluirMusica(btn));

// Adicionar música
document.getElementById('btn-add-musica')?.addEventListener('click', async () => {
  const titulo  = document.getElementById('musica-titulo').value.trim();
  const artista = document.getElementById('musica-artista').value.trim();
  const link    = document.getElementById('musica-link').value.trim();
  const momento = document.getElementById('musica-momento').value;
  if (!titulo) {
    toast('Informe o título da música.', 'warn');
    document.getElementById('musica-titulo').focus();
    return;
  }
  const btn  = document.getElementById('btn-add-musica');
  const orig = btn.innerHTML;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sugerindo…';
  btn.disabled  = true;
  try {
    const r = await ajax({ adicionar_musica: '1', titulo_musica: titulo, artista_musica: artista, link_musica: link, momento_musica: momento });
    if (r.ok) {
      inserirMusicaSugerida(r);
      document.getElementById('musica-titulo').value  = '';
      document.getElementById('musica-artista').value = '';
      document.getElementById('musica-link').value    = '';
      atualizarContadoresMusicas();
      toast('Música sugerida! 💡', 'verde');
      document.getElementById('musica-titulo').focus();
    } else { toast(r.msg || 'Erro ao adicionar música.', 'verm'); }
  } catch { toast('Erro de conexão. Tente novamente.', 'verm'); }
  btn.innerHTML = orig;
  btn.disabled  = false;
});

document.getElementById('musica-titulo')?.addEventListener('keydown', e => {
  if (e.key === 'Enter') { e.preventDefault(); document.getElementById('btn-add-musica').click(); }
});

// Alternância de abas
document.getElementById('aba-sugestoes')?.addEventListener('click', () => {
  document.getElementById('painel-sugestoes').style.display   = '';
  document.getElementById('painel-confirmadas').style.display = 'none';
  document.getElementById('aba-sugestoes').style.cssText   = 'background:#7c3aed;color:#fff;border:none;';
  document.getElementById('aba-confirmadas').style.cssText = 'background:#f0fdf4;color:#16a34a;border:1.5px solid #86efac;';
});
document.getElementById('aba-confirmadas')?.addEventListener('click', () => {
  document.getElementById('painel-sugestoes').style.display   = 'none';
  document.getElementById('painel-confirmadas').style.display = '';
  document.getElementById('aba-confirmadas').style.cssText = 'background:#16a34a;color:#fff;border:none;';
  document.getElementById('aba-sugestoes').style.cssText   = 'background:#f5f3ff;color:#7c3aed;border:1.5px solid #a78bfa;';
});
</script>
</body>
</html>