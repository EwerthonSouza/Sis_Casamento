<?php
session_start();

require_once 'conexao.php'; 

if (!isset($_SESSION['usuario_tipo']) || !in_array($_SESSION['usuario_tipo'], ['admin', 'assistente'])) {
    header("Location: index.php");
    exit;
}
$is_admin = ($_SESSION['usuario_tipo'] === 'admin');

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

// 2. Colunas de convidados
try { $pdo->query("SELECT nomes_acompanhantes FROM convidados LIMIT 1"); }
catch (Exception $e) { $pdo->exec("ALTER TABLE convidados ADD COLUMN nomes_acompanhantes VARCHAR(255) NULL"); }

try { $pdo->query("SELECT idades_filhos FROM convidados LIMIT 1"); }
catch (Exception $e) { $pdo->exec("ALTER TABLE convidados ADD COLUMN idades_filhos VARCHAR(255) NULL"); }

// 3. Coluna de controle de pagamento parcial
try { $pdo->query("SELECT valor_pago FROM fornecedores_evento LIMIT 1"); }
catch (Exception $e) { $pdo->exec("ALTER TABLE fornecedores_evento ADD COLUMN valor_pago DECIMAL(10,2) NOT NULL DEFAULT 0.00"); }

// 4. Tabela de músicas com status de sugestão/confirmação
try {
    $pdo->query("SELECT 1 FROM musicas_evento LIMIT 1");
    // Tabela existe — garante coluna status
    try { $pdo->query("SELECT status FROM musicas_evento LIMIT 1"); }
    catch (Exception $e) {
        $pdo->exec("ALTER TABLE musicas_evento ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'sugestao'");
    }
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

/* ============================================================
   ID DO EVENTO
   ============================================================ */
$evento_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$evento_id) {
    header("Location: painel_admin.php");
    exit;
}

/* ============================================================
   HELPER
   ============================================================ */
function json_out(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
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

    $ajax = isset($_POST['is_ajax']);

    // 1. Adicionar fornecedor
    if (isset($_POST['adicionar_fornecedor'])) {
        $nome    = trim($_POST['nome_fornecedor']);
        $servico = trim($_POST['servico_fornecedor']);
        $contato = trim($_POST['contato_fornecedor'] ?? '');
        $status  = trim($_POST['status_fornecedor']  ?? 'Orçamento');
        $valor   = !empty($_POST['valor_fornecedor'])
                   ? (float)str_replace(['.', ','], ['', '.'], $_POST['valor_fornecedor'])
                   : 0.00;
        if ($nome !== '' && $servico !== '') {
            $pdo->prepare("INSERT INTO fornecedores_evento (evento_id, nome, servico, contato, status, valor, valor_pago) VALUES (?, ?, ?, ?, ?, ?, 0)")
                ->execute([$evento_id, $nome, $servico, $contato, $status, $valor]);
            $_SESSION['msg_sucesso'] = "Fornecedor adicionado com sucesso!";
        }
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 2. Importar padrão
    if (isset($_POST['gerar_padrao'])) {
        $tipo  = trim($_POST['tipo_padrao']);
        $stmt  = $pdo->prepare("SELECT * FROM checklist_modelos WHERE tipo_padrao = ?");
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

    // 3. Adicionar tarefa manual
    if (isset($_POST['adicionar_manual'])) {
        $etapa     = trim($_POST['etapa']);
        $tarefa    = trim($_POST['tarefa']);
        $descricao = trim($_POST['descricao']);
        if ($etapa !== '' && $tarefa !== '') {
            $pdo->prepare("INSERT INTO checklist (evento_id, etapa, tarefa, descricao, origem, status, checado) VALUES (?, ?, ?, ?, 'Assessoria', 'pendente', 0)")
                ->execute([$evento_id, $etapa, $tarefa, $descricao]);
            $_SESSION['msg_sucesso'] = "Tarefa adicionada!";
        }
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 4. Editar tarefa
    if (isset($_POST['editar_tarefa'])) {
        $id        = (int)$_POST['id_tarefa'];
        $etapa     = trim($_POST['etapa_edit']);
        $tarefa    = trim($_POST['tarefa_edit']);
        $descricao = trim($_POST['descricao_edit']);
        $pdo->prepare("UPDATE checklist SET etapa = ?, tarefa = ?, descricao = ? WHERE id = ? AND evento_id = ?")
            ->execute([$etapa, $tarefa, $descricao, $id, $evento_id]);
        $_SESSION['msg_sucesso'] = "Tarefa atualizada!";
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 5. Toggle status tarefa (AJAX)
    if (isset($_POST['alternar_status'])) {
        $id   = (int)$_POST['id_tarefa'];
        $atual = $_POST['status_atual'];
        $novo  = ($atual == 1 || $atual === 'concluido') ? 0 : 1;
        $pdo->prepare("UPDATE checklist SET status = ?, checado = ? WHERE id = ? AND evento_id = ?")
            ->execute([$novo ? 'concluido' : 'pendente', $novo, $id, $evento_id]);
        if ($ajax) json_out(['ok' => true, 'novo' => $novo]);
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 6. Excluir tarefa (AJAX)
    if (isset($_POST['excluir_tarefa'])) {
        $id = (int)$_POST['id_tarefa'];
        $pdo->prepare("DELETE FROM checklist WHERE id = ? AND evento_id = ?")
            ->execute([$id, $evento_id]);
        if ($ajax) json_out(['ok' => true]);
        $_SESSION['msg_sucesso'] = "Tarefa removida!";
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 7. Comentário de tarefa (AJAX)
    if (isset($_POST['adicionar_comentario'])) {
        $id    = (int)$_POST['id_tarefa'];
        $texto = trim($_POST['texto_comentario'] ?? '');
        $autor_nome = $_SESSION['usuario_nome'] ?? 'Assessoria';
        if ($texto !== '') {
            $pdo->prepare("INSERT INTO checklist_comentarios (checklist_id, autor, comentario) VALUES (?, ?, ?)")
                ->execute([$id, $autor_nome, $texto]);
            if ($ajax) json_out(['ok' => true, 'autor' => htmlspecialchars($autor_nome), 'texto' => htmlspecialchars($texto)]);
            $_SESSION['msg_sucesso'] = "Comentário adicionado!";
        }
        if (!$ajax) { header("Location: gerenciar.php?id=$evento_id"); exit; }
        exit;
    }

    // 8. Comentário de etapa (AJAX)
    if (isset($_POST['comentario_etapa_admin'])) {
        $etapa = trim($_POST['etapa_nome'] ?? '');
        $texto = trim($_POST['novo_comentario_etapa'] ?? '');
        $autor_nome = $_SESSION['usuario_nome'] ?? 'Assessoria';
        if ($etapa !== '' && $texto !== '') {
            $pdo->prepare("INSERT INTO checklist_comentarios (etapa_nome, autor, comentario) VALUES (?, ?, ?)")
                ->execute([$etapa, $autor_nome, $texto]);
            if ($ajax) json_out(['ok' => true, 'autor' => htmlspecialchars($autor_nome), 'texto' => htmlspecialchars($texto)]);
        }
        if (!$ajax) { header("Location: gerenciar.php?id=$evento_id"); exit; }
        exit;
    }

    // 9. Limpar todo checklist
    if (isset($_POST['excluir_todo_checklist'])) {
        $pdo->prepare("DELETE FROM checklist WHERE evento_id = ?")->execute([$evento_id]);
        $_SESSION['msg_sucesso'] = "Checklist apagado!";
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 10. Adicionar convidado
    if (isset($_POST['adicionar_convidado_admin'])) {
        $nome       = trim($_POST['nome_convidado']       ?? '');
        $fone       = trim($_POST['telefone_convidado']   ?? '');
        $cat        = trim($_POST['categoria_convidado']  ?? 'Outros');
        $acomp_qtd  = (int)($_POST['acompanhantes']       ?? 0);
        $acomp_nms  = trim($_POST['nomes_acompanhantes']  ?? '');
        $filhos_qtd = (int)($_POST['filhos']              ?? 0);
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
        $id  = (int)$_POST['convidado_id'];
        $novo = (int)$_POST['status_atual'] === 1 ? 0 : 1;
        $pdo->prepare("UPDATE convidados SET confirmado = ? WHERE id = ? AND evento_id = ?")
            ->execute([$novo, $id, $evento_id]);
        if ($ajax) json_out(['ok' => true, 'novo' => $novo]);
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 12. Excluir convidado (AJAX)
    if (isset($_POST['excluir_convidado'])) {
        $id  = (int)$_POST['convidado_id'];
        $chk = $pdo->prepare("SELECT confirmado FROM convidados WHERE id = ? AND evento_id = ?");
        $chk->execute([$id, $evento_id]);
        $row = $chk->fetch();
        $pdo->prepare("DELETE FROM convidados WHERE id = ? AND evento_id = ?")
            ->execute([$id, $evento_id]);
        if ($ajax) json_out(['ok' => true, 'era_conf' => $row ? (int)$row['confirmado'] : 0]);
        $_SESSION['msg_sucesso'] = "Convidado removido!";
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 13. Editar locais
    if (isset($_POST['editar_locais'])) {
        $local_cer   = trim($_POST['local_cerimonia']);
        $tem_festa   = (int)$_POST['tem_festa'];
        $local_festa = $tem_festa === 1 ? trim($_POST['local_festa']) : null;
        $pdo->prepare("UPDATE eventos SET local_cerimonia = ?, tem_festa = ?, local_festa = ? WHERE id = ?")
            ->execute([$local_cer, $tem_festa, $local_festa, $evento_id]);
        $_SESSION['msg_sucesso'] = "Locais atualizados!";
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 14. Atualizar valor pago de um fornecedor (AJAX)
    if (isset($_POST['atualizar_valor_pago'])) {
        $forn_id    = (int)$_POST['fornecedor_id'];
        $valor_pago = (float)($_POST['valor_pago'] ?? '0');
        $chk = $pdo->prepare("SELECT valor FROM fornecedores_evento WHERE id = ? AND evento_id = ?");
        $chk->execute([$forn_id, $evento_id]);
        $forn = $chk->fetch();
        if ($forn) {
            $valor_pago = min(max(0, $valor_pago), (float)$forn['valor']);
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
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 15. Salvar / editar nota (AJAX)
    if (isset($_POST['salvar_nota'])) {
        $nota_id  = (int)($_POST['nota_id']      ?? 0);
        $titulo   = trim($_POST['titulo_nota']   ?? '');
        $conteudo = trim($_POST['conteudo_nota'] ?? '');
        $cores_ok = ['amarelo', 'verde', 'azul', 'rosa', 'cinza'];
        $cor      = in_array($_POST['cor_nota'] ?? '', $cores_ok) ? $_POST['cor_nota'] : 'amarelo';
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
                'titulo'     => htmlspecialchars($titulo),
                'conteudo'   => htmlspecialchars($conteudo),
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
        $nota_id = (int)$_POST['nota_id'];
        $pdo->prepare("DELETE FROM notas_evento WHERE id=? AND evento_id=?")->execute([$nota_id, $evento_id]);
        if ($ajax) json_out(['ok' => true]);
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 17. Adicionar música — salva como 'sugestao'
    if (isset($_POST['adicionar_musica'])) {
        $titulo  = trim($_POST['titulo_musica']  ?? '');
        $artista = trim($_POST['artista_musica'] ?? '');
        $link    = trim($_POST['link_musica']    ?? '');
        $momento = trim($_POST['momento_musica'] ?? 'Livre / Sem Momento Definido');
        if ($titulo !== '') {
            $pdo->prepare("INSERT INTO musicas_evento (evento_id, titulo, artista, link, momento, status) VALUES (?, ?, ?, ?, ?, 'sugestao')")
                ->execute([$evento_id, $titulo, $artista, $link, $momento]);
            $new_id = (int)$pdo->lastInsertId();
            if ($ajax) json_out([
                'ok'      => true,
                'id'      => $new_id,
                'titulo'  => htmlspecialchars($titulo),
                'artista' => htmlspecialchars($artista),
                'link'    => htmlspecialchars($link),
                'momento' => htmlspecialchars($momento),
                'status'  => 'sugestao',
            ]);
            $_SESSION['msg_sucesso'] = "Música sugerida!";
        } else {
            if ($ajax) json_out(['ok' => false, 'msg' => 'Informe o título da música.']);
        }
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 18. Confirmar / desconfirmar música (AJAX)
    if (isset($_POST['confirmar_musica'])) {
        $musica_id   = (int)$_POST['musica_id'];
        $novo_status = trim($_POST['novo_status'] ?? 'confirmada');
        if (!in_array($novo_status, ['sugestao', 'confirmada'])) {
            if ($ajax) json_out(['ok' => false, 'msg' => 'Status inválido.']);
            header("Location: gerenciar.php?id=$evento_id"); exit;
        }
        $pdo->prepare("UPDATE musicas_evento SET status = ? WHERE id = ? AND evento_id = ?")
            ->execute([$novo_status, $musica_id, $evento_id]);
        if ($ajax) json_out(['ok' => true, 'novo_status' => $novo_status]);
        $_SESSION['msg_sucesso'] = $novo_status === 'confirmada' ? 'Música confirmada!' : 'Música voltou para sugestões.';
        header("Location: gerenciar.php?id=$evento_id"); exit;
    }

    // 19. Excluir música (AJAX)
    if (isset($_POST['excluir_musica'])) {
        $musica_id = (int)$_POST['musica_id'];
        $pdo->prepare("DELETE FROM musicas_evento WHERE id = ? AND evento_id = ?")
            ->execute([$musica_id, $evento_id]);
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
$total_forn_restante = max(0, $total_forn_restante);
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

// Checklist
$rs3 = $pdo->prepare("SELECT * FROM checklist WHERE evento_id = ? ORDER BY etapa ASC, id ASC");
$rs3->execute([$evento_id]);
$lista_checklist = $rs3->fetchAll();

// Comentários de tarefas (sem N+1)
$ids = array_column($lista_checklist, 'id');
$coments_tarefa = [];
if (!empty($ids)) {
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $rs4 = $pdo->prepare("SELECT * FROM checklist_comentarios WHERE checklist_id IN ($ph) ORDER BY data_cadastro ASC");
    $rs4->execute($ids);
    foreach ($rs4->fetchAll() as $c) { $coments_tarefa[$c['checklist_id']][] = $c; }
}

// Comentários de etapas
$rs5 = $pdo->query("SELECT * FROM checklist_comentarios WHERE etapa_nome IS NOT NULL ORDER BY data_cadastro ASC");
$coments_etapa = [];
foreach ($rs5->fetchAll() as $c) { $coments_etapa[$c['etapa_nome']][] = $c; }

// Agrupamento e progresso do checklist
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

// Flash messages
$msg_sucesso = $_SESSION['msg_sucesso'] ?? '';
$msg_erro    = $_SESSION['msg_erro']    ?? '';
unset($_SESSION['msg_sucesso'], $_SESSION['msg_erro']);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gerenciar Evento</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
  <link rel="stylesheet" href="css/estilo.css">
  <style>
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
    .barra-pag-wrap { height: 4px; background: #e2e8f0; border-radius: 999px; overflow: hidden; margin-top: .3rem; }
    .barra-pag-fill { height: 100%; border-radius: 999px; transition: width .4s ease; }
    .fin-chip {
      display: flex; flex-direction: column; align-items: center;
      padding: .55rem .7rem; border-radius: 10px; min-width: 70px;
    }
    .fin-chip-label { font-size: .55rem; text-transform: uppercase; letter-spacing: .06em; font-weight: 700; opacity: .75; }
    .fin-chip-val   { font-size: .85rem; font-weight: 800; line-height: 1.1; margin-top: .15rem; }
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
      background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%);
      border: 1.5px solid #a78bfa; border-radius: var(--radius);
      transition: box-shadow .2s, transform .15s;
      display: block; width: 100%; text-align: left; cursor: pointer;
    }
    .btn-musicas-sidebar:hover { box-shadow: 0 6px 18px rgba(124,58,237,.22); transform: translateY(-1px); }
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

    /* ---- MODAL CONFIRMAR SEMPRE À FRENTE ---- */
    #modalConfirmar { z-index: 1075 !important; }
  </style>
</head>
<body>

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

<form id="form-import-com-rec" method="POST" hidden>
  <input type="hidden" name="gerar_padrao" value="1">
  <input type="hidden" name="tipo_padrao" value="com_recepcao">
</form>
<form id="form-import-sem-rec" method="POST" hidden>
  <input type="hidden" name="gerar_padrao" value="1">
  <input type="hidden" name="tipo_padrao" value="sem_recepcao">
</form>
<form id="form-limpar-checklist" method="POST" hidden>
  <input type="hidden" name="excluir_todo_checklist" value="1">
</form>

<div class="container my-4 my-md-5">

  <div class="card border-0 shadow-sm mb-4 overflow-hidden" style="border-radius: var(--radius);">
    <div class="header-topo p-4 d-flex flex-wrap justify-content-between align-items-start gap-3">
      <div>
        <a href="painel_admin.php" class="btn btn-sm btn-outline-light mb-3 rounded-3">
          <i class="bi bi-arrow-left me-1"></i> Voltar ao Painel
        </a>
        <h2 class="fw-bold mb-1 text-white" style="letter-spacing:-.5px;">
          <i class="bi bi-rings text-warning me-2"></i>
          Casamento de <?= htmlspecialchars($evento['nome']) ?>
        </h2>
        <p class="text-white-50 mb-3 small">Painel de controle do evento</p>
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
              Realizado há <?= abs($dias) ?> dias
            </span>
          <?php endif; ?>
        </div>
      </div>
      <div class="d-flex align-items-center gap-3">
        <?php if ($total_g > 0):
          $r_   = 28;
          $circ = 2 * M_PI * $r_;
          $off  = $circ - ($circ * $pct_g / 100); ?>
        <div class="ring-wrap" title="<?= $pct_g ?>% do checklist concluído">
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
        <?php if (!empty($evento['cpf'])): ?>
        <div class="d-flex align-items-center gap-2 text-muted small">
          <span class="bg-light rounded-circle p-2 d-flex"><i class="bi bi-person-vcard text-secondary"></i></span>
          <?= htmlspecialchars($evento['cpf']) ?>
        </div>
        <?php endif; ?>
      </div>
      <span class="badge bg-light text-dark border shadow-sm px-3 py-2 rounded-pill" style="font-size:.7rem;">
        Contrato #<?= str_pad($evento['id'], 4, '0', STR_PAD_LEFT) ?>
      </span>
    </div>
  </div>

  <div class="row g-4 align-items-start">

    <div class="col-md-7">
      <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
          <h4 class="mb-0 fw-bold"><i class="bi bi-list-check text-primary me-2"></i> Checklist</h4>
          <?php if ($total_g > 0): ?>
          <div class="text-muted small mt-1">
            <span id="label-conc-g"><?= $conc_g ?></span> de <?= $total_g ?> tarefas concluídas
            <div class="barra mt-1" style="max-width:140px;">
              <div class="barra-fill" id="barra-g" style="width:<?= $pct_g ?>%;"></div>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <button type="button" class="btn btn-sm btn-outline-success rounded-3 btn-import-padrao"
                  data-form="form-import-com-rec"
                  data-msg="Isso adicionará todas as tarefas padrão (com recepção) ao evento."
                  data-titulo="Importar cronograma?">
            <i class="bi bi-download me-1"></i> Com Recepção
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary rounded-3 btn-import-padrao"
                  data-form="form-import-sem-rec"
                  data-msg="Isso adicionará todas as tarefas padrão (sem recepção) ao evento."
                  data-titulo="Importar cronograma?">
            <i class="bi bi-download me-1"></i> Sem Recepção
          </button>
          <button type="button" class="btn btn-sm btn-primary rounded-3" data-bs-toggle="modal" data-bs-target="#modalManual">
            <i class="bi bi-plus-lg me-1"></i> Manual
          </button>
        </div>
      </div>

      <?php if (empty($passos)): ?>
        <div class="card border-0 shadow-sm text-center py-5 text-muted" style="border-radius: var(--radius);">
          <i class="bi bi-info-circle fs-1 mb-2"></i>
          <p class="mb-0">Checklist vazio. Use os botões acima para importar um modelo ou adicionar tarefas.</p>
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
                    <input type="hidden" name="comentario_etapa_admin" value="1">
                    <input type="hidden" name="etapa_nome" value="<?= htmlspecialchars($etapa) ?>">
                    <input type="text" name="novo_comentario_etapa" class="form-control form-control-sm" placeholder="Nota geral para os noivos…" required>
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
                              title="<?= $done ? 'Desmarcar' : 'Marcar como concluída' ?>">
                        <i class="bi <?= $done ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i>
                      </button>
                      <div class="w-100">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                          <div>
                            <h6 class="fw-bold mb-1 <?= $done ? 'text-muted text-decoration-line-through' : 'text-dark' ?>" style="line-height:1.4;">
                              <?= htmlspecialchars($t['tarefa']) ?>
                            </h6>
                            <?php if (!empty($t['descricao'])): ?>
                            <button class="btn btn-sm btn-outline-secondary py-0 px-2 rounded-pill"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modalDesc_<?= $tid ?>"
                                    style="font-size:.72rem;">
                              <i class="bi bi-file-text"></i> Ler
                            </button>
                            <?php endif; ?>
                          </div>
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

        <div class="text-end mt-3">
          <button type="button" class="btn btn-sm btn-outline-danger rounded-3" id="btn-limpar-checklist">
            <i class="bi bi-trash me-1"></i> Limpar Todo o Checklist
          </button>
        </div>
      <?php endif; ?>
    </div>

    <div class="col-md-5">
      <div class="sidebar-sticky d-flex flex-column gap-4">

        <div class="card border-0 shadow-sm overflow-hidden card-inspiracoes" style="border-radius: var(--radius);">
          <div class="card-body d-flex justify-content-between align-items-center p-3">
            <div class="d-flex align-items-center gap-3">
              <div class="bg-white rounded-3 d-flex align-items-center justify-content-center shadow-sm" style="width:44px;height:44px;">
                <i class="bi bi-stars fs-4" style="color:#6366f1;"></i>
              </div>
              <div>
                <h6 class="mb-0 fw-bold text-white">Mural de Inspirações</h6>
                <small class="text-white-50" style="font-size:.78rem;">Referências, paletas e ideias</small>
              </div>
            </div>
            <a href="inspiracoes.php?id=<?= $evento_id ?>" class="btn btn-light btn-sm fw-bold rounded-pill px-3 shadow-sm" style="color:#4f46e5;">
              Acessar <i class="bi bi-arrow-right ms-1"></i>
            </a>
          </div>
        </div>

        <!-- Bloco de Notas -->
        <button type="button" class="btn-notas-sidebar" data-bs-toggle="modal" data-bs-target="#modalNotas">
          <div class="d-flex justify-content-between align-items-center p-3">
            <div class="d-flex align-items-center gap-3">
              <div class="bg-white rounded-3 d-flex align-items-center justify-content-center shadow-sm flex-shrink-0" style="width:44px;height:44px;">
                <i class="bi bi-journal-text fs-4" style="color:#d97706;"></i>
              </div>
              <div>
                <h6 class="mb-0 fw-bold text-dark">Bloco de Notas</h6>
                <small class="text-dark" style="font-size:.78rem;opacity:.6;">
                  <span id="notas-count-badge"><?= $total_notas ?> nota<?= $total_notas !== 1 ? 's' : '' ?></span>
                  · visível ao casal
                </small>
              </div>
            </div>
            <span class="btn btn-warning btn-sm fw-bold rounded-pill px-3 shadow-sm" style="pointer-events:none;">
              Abrir <i class="bi bi-arrow-right ms-1"></i>
            </span>
          </div>
        </button>

        <!-- Playlist do Evento -->
        <button type="button" class="btn-musicas-sidebar" data-bs-toggle="modal" data-bs-target="#modalMusicas">
          <div class="d-flex justify-content-between align-items-center p-3">
            <div class="d-flex align-items-center gap-3">
              <div class="bg-white rounded-3 d-flex align-items-center justify-content-center shadow-sm flex-shrink-0" style="width:44px;height:44px;">
                <i class="bi bi-music-note-beamed fs-4" style="color:#7c3aed;"></i>
              </div>
              <div>
                <h6 class="mb-0 fw-bold text-dark">Playlist do Evento</h6>
                <small class="text-dark" style="font-size:.78rem;opacity:.6;">
                  <span id="musicas-count-badge"><?= $total_musicas ?> música<?= $total_musicas !== 1 ? 's' : '' ?></span>
                  · visível ao casal
                </small>
              </div>
            </div>
            <span class="btn btn-sm fw-bold rounded-pill px-3 shadow-sm"
                  style="background:#7c3aed;color:#fff;pointer-events:none;border:none;">
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
                  <?= !empty($evento['local_cerimonia']) ? htmlspecialchars($evento['local_cerimonia']) : '<span class="text-muted fst-italic">A definir…</span>' ?>
                </span>
              </div>
            </div>
            <?php if ($evento['tem_festa'] == 1): ?>
            <div class="mt-3 pt-2 border-top">
              <div class="text-muted fw-bold mb-1" style="font-size:.68rem;text-transform:uppercase;">Recepção / Festa</div>
              <div class="d-flex align-items-start gap-2">
                <i class="bi bi-balloon-heart text-secondary mt-1" style="font-size:.85rem;"></i>
                <span class="small text-dark fw-medium">
                  <?= !empty($evento['local_festa']) ? htmlspecialchars($evento['local_festa']) : '<span class="text-muted fst-italic">A definir…</span>' ?>
                </span>
              </div>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="row g-2" id="grupoAcessos">
          <div class="col-6">
            <button class="btn w-100 d-flex flex-column align-items-center justify-content-center p-3 shadow-sm text-white h-100"
                    style="background: #1e293b; border-radius: var(--radius); border: none;"
                    data-bs-toggle="collapse" data-bs-target="#collapseEquipe"
                    aria-expanded="false" aria-controls="collapseEquipe">
              <i class="bi bi-person-badge-fill text-info fs-3 mb-2"></i>
              <span class="fw-bold" style="font-size:.85rem;">Equipe Contratada</span>
              <?php if ($is_admin): ?>
              <div class="mt-2 text-warning fw-bold" style="font-size:.82rem;">
                R$ <?= number_format($total_forn, 2, ',', '.') ?>
              </div>
              <?php if ($total_forn > 0): ?>
              <div class="mt-1 d-flex gap-2">
                <span style="font-size:.62rem;color:#86efac;">✓ R$ <?= number_format($total_forn_pago, 2, ',', '.') ?></span>
                <span style="font-size:.62rem;color:#fca5a5;">↻ R$ <?= number_format($total_forn_restante, 2, ',', '.') ?></span>
              </div>
              <?php endif; ?>
              <?php endif; ?>
            </button>
          </div>
          <div class="col-6">
            <button class="btn w-100 d-flex flex-column align-items-center justify-content-center p-3 shadow-sm text-white h-100"
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
                  <?php if ($is_admin): ?>
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
                  <div class="d-flex justify-content-between mb-1" style="font-size:.6rem;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">
                    <span>Progresso de Pagamentos</span>
                    <span id="adm-pct-pago"><?= $pct_pago_geral ?>%</span>
                  </div>
                  <div class="barra-pag-wrap">
                    <div class="barra-pag-fill bg-success" id="adm-barra-pago" style="width:<?= $pct_pago_geral ?>%;"></div>
                  </div>
                  <?php endif; ?>
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
                      $fRest  = max(0, $fValor - $fPago);
                      $fPct   = $fValor > 0 ? round($fPago / $fValor * 100) : 0;
                      $fQuit  = $fRest <= 0;
                      $barClr = $fQuit ? 'bg-success' : ($fPct >= 50 ? 'bg-info' : 'bg-warning');
                    ?>
                    <div class="forn-pago-row" id="forn-adm-<?= $fid ?>">
                      <div>
                        <div class="forn-info-nome"><?= htmlspecialchars($f['servico']) ?></div>
                        <div class="forn-info-sub"><?= htmlspecialchars($f['nome']) ?></div>
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
                        <div class="forn-val-rest <?= $fQuit ? 'ok' : 'nok' ?> forn-rest-adm">
                          <?= $fQuit ? '✓ Quitado' : 'Resta R$ '.number_format($fRest, 2, ',', '.') ?>
                        </div>
                      </div>
                      <div class="forn-input-wrap">
                        <input type="text" class="forn-input-pago-adm"
                               data-id="<?= $fid ?>" data-total="<?= $fValor ?>"
                               value="<?= number_format($fPago, 2, ',', '.') ?>"
                               placeholder="0,00" inputmode="decimal" title="Valor já pago (R$)">
                        <button type="button" class="forn-btn-salvar forn-btn-salvar-adm"
                                data-id="<?= $fid ?>" title="Salvar pagamento">
                          <i class="bi bi-floppy"></i>
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
                    <div class="grupo-sec" data-grupo="<?= htmlspecialchars($grp) ?>">
                      <div class="badge bg-secondary text-white w-100 text-start px-3 py-2 rounded-2 mb-1 mt-2 sec-badge">
                        <i class="bi bi-tag-fill me-1"></i>
                        <?= htmlspecialchars($grp) ?> (<span class="cnt-grp"><?= count($convidadosDoGrupo) ?></span>)
                      </div>
                      <?php foreach ($convidadosDoGrupo as $con):
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
                                <?= $cConf ? '<i class="bi bi-check-circle-fill me-1"></i> Confirmado' : '<i class="bi bi-hourglass-split me-1"></i> Pendente' ?>
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
                            <div><i class="bi bi-whatsapp me-1 text-success"></i><?= htmlspecialchars($con['telefone']) ?></div>
                          <?php endif; ?>
                          <?php if ($con['acompanhantes'] > 0): ?>
                            <div>
                              <i class="bi bi-person-plus me-1"></i>Acomp (<?= $con['acompanhantes'] ?>):
                              <?= !empty($con['nomes_acompanhantes']) ? htmlspecialchars($con['nomes_acompanhantes']) : '<span class="fst-italic text-black-50">Nomes não informados</span>' ?>
                            </div>
                          <?php endif; ?>
                          <?php if ($con['filhos'] > 0): ?>
                            <div>
                              <i class="bi bi-emoji-smile me-1"></i>Filhos (<?= $con['filhos'] ?>):
                              <?= !empty($con['idades_filhos']) ? htmlspecialchars($con['idades_filhos']) : '<span class="fst-italic text-black-50">Idades não informadas</span>' ?>
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

<!-- ============================================================
     MODAIS
     ============================================================ -->

<div class="modal fade" id="modalManual" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header bg-light border-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle text-primary me-2"></i> Adicionar Tarefa Manual</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="adicionar_manual" value="1">
        <div class="modal-body p-4">
          <div class="mb-3">
            <label class="form-label small fw-bold text-secondary">Etapa (Número ou Nome)</label>
            <input type="text" name="etapa" class="form-control" placeholder="Ex: 1  ou  Pré-Casamento" required>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-bold text-secondary">Nome da Tarefa</label>
            <input type="text" name="tarefa" class="form-control" required>
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

<div class="modal fade" id="modalFornecedor" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header bg-light border-0">
        <h5 class="modal-title fw-bold"><i class="bi bi-person-plus text-success me-2"></i> Adicionar Fornecedor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
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
            <div class="<?= $is_admin ? 'col-6' : 'col-12' ?>">
              <label class="form-label small fw-bold text-secondary">Status</label>
              <select name="status_fornecedor" class="form-select">
                <option value="Contratado">Contratado</option>
                <option value="Orçamento">Apenas Orçamento</option>
              </select>
            </div>
            <?php if ($is_admin): ?>
            <div class="col-6">
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
      <form method="POST">
        <input type="hidden" name="editar_locais" value="1">
        <div class="modal-body p-4">
          <div class="mb-3">
            <label class="form-label small fw-bold text-secondary">Local da Cerimônia</label>
            <input type="text" name="local_cerimonia" class="form-control" placeholder="Igreja, Cartório…"
                   value="<?= htmlspecialchars($evento['local_cerimonia'] ?? '') ?>">
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
                   value="<?= htmlspecialchars($evento['local_festa'] ?? '') ?>">
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
      <form method="POST">
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
                          echo '<option value="' . htmlspecialchars($nomeGrp) . '">';
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
            <div class="col-4">
              <label class="form-label small text-secondary">Quantidade</label>
              <input type="number" min="0" name="acompanhantes" class="form-control" value="0">
            </div>
            <div class="col-8">
              <label class="form-label small text-secondary">Nomes (separados por vírgula)</label>
              <input type="text" name="nomes_acompanhantes" class="form-control" placeholder="Ex: Maria, João...">
            </div>
          </div>
          <h6 class="fw-bold small text-dark mb-3"><i class="bi bi-emoji-smile-fill text-secondary"></i> Filhos</h6>
          <div class="row g-3">
            <div class="col-4">
              <label class="form-label small text-secondary">Quantidade</label>
              <input type="number" min="0" name="filhos" class="form-control" value="0">
            </div>
            <div class="col-8">
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

  <div class="modal fade" id="modalEditar_<?= $tid ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg rounded-4">
        <div class="modal-header bg-light border-0">
          <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square text-primary me-2"></i> Editar Tarefa</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <form method="POST">
          <input type="hidden" name="editar_tarefa" value="1">
          <input type="hidden" name="id_tarefa" value="<?= $tid ?>">
          <div class="modal-body p-4">
            <div class="mb-3">
              <label class="form-label small fw-bold text-secondary">Etapa</label>
              <input type="text" name="etapa_edit" class="form-control" value="<?= htmlspecialchars($t['etapa']) ?>" required>
            </div>
            <div class="mb-3">
              <label class="form-label small fw-bold text-secondary">Tarefa</label>
              <input type="text" name="tarefa_edit" class="form-control" value="<?= htmlspecialchars($t['tarefa']) ?>" required>
            </div>
            <div class="mb-1">
              <label class="form-label small fw-bold text-secondary">Descrição</label>
              <textarea name="descricao_edit" class="form-control" rows="4"><?= htmlspecialchars($t['descricao']) ?></textarea>
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

<?php endforeach; ?>

<!-- ============================================================
     MODAL PLAYLIST DO EVENTO
     ============================================================ -->
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

        <!-- Formulário de sugestão -->
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
                  <option value="<?= htmlspecialchars($momento) ?>"><?= htmlspecialchars($momento) ?></option>
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

        <!-- Abas Sugestões / Confirmadas -->
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

        <!-- Painel Sugestões -->
        <div id="painel-sugestoes">
          <?php if (empty($musicas_sugeridas)): ?>
          <div class="text-center py-5 text-muted" id="sug-vazia">
            <i class="bi bi-lightbulb fs-1 d-block mb-2" style="opacity:.2;color:#7c3aed;"></i>
            <small>Nenhuma sugestão ainda. Adicione a primeira acima!</small>
          </div>
          <?php else: ?>
          <div id="lista-sugestoes-grupos">
            <?php foreach ($musicas_sugeridas as $momento => $musicas_do_momento): ?>
            <div class="musica-grupo mb-3" data-momento="<?= htmlspecialchars($momento) ?>" data-aba="sugestao">
              <div class="musica-grupo-header" style="background:linear-gradient(90deg,#ede9fe,#f5f3ff);">
                <i class="bi bi-collection-play" style="color:#7c3aed;font-size:.85rem;"></i>
                <span class="momento-label" style="color:#5b21b6;"><?= htmlspecialchars($momento) ?></span>
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
                     style="border-left:3px solid #a78bfa;">
                  <div class="d-flex align-items-center justify-content-center rounded-2 flex-shrink-0"
                       style="width:34px;height:34px;background:#ede9fe;">
                    <i class="bi bi-lightbulb" style="color:#7c3aed;font-size:.9rem;"></i>
                  </div>
                  <div class="flex-fill" style="min-width:0;">
                    <div class="fw-bold text-dark text-truncate" style="font-size:.84rem;"><?= htmlspecialchars($m['titulo']) ?></div>
                    <?php if (!empty($m['artista'])): ?>
                    <div class="text-muted" style="font-size:.69rem;">
                      <i class="bi bi-person-fill me-1" style="font-size:.6rem;"></i><?= htmlspecialchars($m['artista']) ?>
                    </div>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($m['link'])): ?>
                  <a href="<?= htmlspecialchars($m['link']) ?>" target="_blank" rel="noopener"
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
                <?php endforeach; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <!-- Painel Confirmadas -->
        <div id="painel-confirmadas" style="display:none;">
          <?php if (empty($musicas_confirmadas)): ?>
          <div class="text-center py-5 text-muted" id="conf-vazia">
            <i class="bi bi-check-circle fs-1 d-block mb-2" style="opacity:.2;color:#16a34a;"></i>
            <small>Nenhuma música confirmada ainda.<br>Confirme sugestões na outra aba!</small>
          </div>
          <?php else: ?>
          <div id="lista-confirmadas-grupos">
            <?php foreach ($musicas_confirmadas as $momento => $musicas_do_momento): ?>
            <div class="musica-grupo mb-3" data-momento="<?= htmlspecialchars($momento) ?>" data-aba="confirmada">
              <div class="musica-grupo-header" style="background:linear-gradient(90deg,#dcfce7,#f0fdf4);">
                <i class="bi bi-collection-play" style="color:#16a34a;font-size:.85rem;"></i>
                <span class="momento-label" style="color:#14532d;"><?= htmlspecialchars($momento) ?></span>
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
                     style="border-left:3px solid #86efac;">
                  <div class="d-flex align-items-center justify-content-center rounded-2 flex-shrink-0"
                       style="width:34px;height:34px;background:#dcfce7;">
                    <i class="bi bi-check-circle-fill" style="color:#16a34a;font-size:.9rem;"></i>
                  </div>
                  <div class="flex-fill" style="min-width:0;">
                    <div class="fw-bold text-dark text-truncate" style="font-size:.84rem;"><?= htmlspecialchars($m['titulo']) ?></div>
                    <?php if (!empty($m['artista'])): ?>
                    <div class="text-muted" style="font-size:.69rem;">
                      <i class="bi bi-person-fill me-1" style="font-size:.6rem;"></i><?= htmlspecialchars($m['artista']) ?>
                    </div>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($m['link'])): ?>
                  <a href="<?= htmlspecialchars($m['link']) ?>" target="_blank" rel="noopener"
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

<!-- MODAL NOTAS -->
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
            <span class="badge bg-warning text-dark rounded-pill px-3" style="font-size:.68rem;">
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
                      <?= htmlspecialchars($nota['titulo']) ?>
                    </h6>
                    <div class="d-flex gap-1 flex-shrink-0">
                      <button type="button" class="btn p-1 border-0 bg-transparent btn-editar-nota"
                              data-id="<?= $nota['id'] ?>"
                              data-titulo="<?= htmlspecialchars($nota['titulo'], ENT_QUOTES) ?>"
                              data-conteudo="<?= htmlspecialchars($nota['conteudo'], ENT_QUOTES) ?>"
                              data-cor="<?= htmlspecialchars($nota['cor']) ?>" title="Editar nota">
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
                    <?= htmlspecialchars($nota['conteudo']) ?>
                  </p>
                  <?php endif; ?>
                  <div class="mt-3 pt-2 border-top d-flex justify-content-between align-items-center"
                       style="border-color:<?= $brdC ?>!important;">
                    <span style="font-size:.6rem;color:<?= $txtC ?>;opacity:.5;">
                      <i class="bi bi-clock me-1"></i><?= $dt ?>
                    </span>
                    <span class="badge rounded-pill"
                          style="font-size:.55rem;background:<?= $bgC ?>;border:1px solid <?= $brdC ?>;color:<?= $txtC ?>;opacity:.7;">
                      <?= htmlspecialchars($nota['autor']) ?>
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
const SELF = window.location.href;

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

async function ajax(obj) {
  obj.is_ajax = '1';
  const fd = new FormData();
  Object.entries(obj).forEach(([k, v]) => fd.append(k, v));
  const r = await fetch(SELF, { method: 'POST', body: fd });
  return r.json();
}

function brl(n) {
  return 'R$ ' + parseFloat(n).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function parseBrl(s) {
  return parseFloat(String(s).replace(/\./g, '').replace(',', '.')) || 0;
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
  setTimeout(() => {
    const backdrops = document.querySelectorAll('.modal-backdrop');
    if (backdrops.length >= 2) backdrops[backdrops.length - 1].style.zIndex = '1070';
  }, 50);
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

/* ---- TOGGLE TAREFA (AJAX) ---- */
document.querySelectorAll('.btn-toggle-tarefa').forEach(btn => {
  btn.addEventListener('click', async () => {
    const id       = btn.dataset.id;
    const atual    = +btn.dataset.status;
    const card     = btn.closest('.tarefa-card');
    const titulo   = card.querySelector('h6');
    const hdrId    = btn.dataset.etapaHdrId;
    const collapso = btn.closest('.collapse');
    const orig     = btn.innerHTML;
    btn.innerHTML  = '<span class="spinner-border spinner-border-sm text-secondary"></span>';
    try {
      const r = await ajax({ alternar_status: '1', id_tarefa: id, status_atual: atual });
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
      if (hdr) {
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
              const hdr = collapso?.previousElementSibling;
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
    fd.append('is_ajax', '1');
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
    fd.append('is_ajax', '1');
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
function recalcularTotaisAdm() {
  let totalContrato = 0, totalPago = 0;
  document.querySelectorAll('.forn-input-pago-adm').forEach(inp => {
    const t = parseFloat(inp.dataset.total || 0);
    const p = parseBrl(inp.value);
    totalContrato += t;
    totalPago     += Math.min(p, t);
  });
  const restante = Math.max(0, totalContrato - totalPago);
  const pct      = totalContrato > 0 ? Math.round(totalPago / totalContrato * 100) : 0;
  const elPago   = document.getElementById('adm-total-pago');
  const elRest   = document.getElementById('adm-total-rest');
  const elBarra  = document.getElementById('adm-barra-pago');
  const elPct    = document.getElementById('adm-pct-pago');
  if (elPago)  elPago.textContent  = brl(totalPago);
  if (elRest)  elRest.textContent  = brl(restante);
  if (elBarra) elBarra.style.width = pct + '%';
  if (elPct)   elPct.textContent   = pct + '%';
}

document.querySelectorAll('.forn-btn-salvar-adm').forEach(btn => {
  btn.addEventListener('click', async () => {
    const fid   = btn.dataset.id;
    const row   = document.getElementById('forn-adm-' + fid);
    const input = row.querySelector('.forn-input-pago-adm');
    const total = parseFloat(input.dataset.total || 0);
    let   valor = parseBrl(input.value);
    if (valor < 0) { toast('O valor não pode ser negativo.', 'verm'); return; }
    if (valor > total) {
      toast('Valor maior que o contrato! Ajustado para o total.', 'info');
      valor = total;
      input.value = total.toLocaleString('pt-BR', { minimumFractionDigits: 2 });
    }
    const origBtn = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    btn.disabled  = true;
    try {
      const r = await ajax({ atualizar_valor_pago: '1', fornecedor_id: fid, valor_pago: valor.toString() });
      if (r.ok) {
        const pago = parseFloat(r.valor_pago);
        const rest = Math.max(0, parseFloat(r.valor_rest));
        const pct  = total > 0 ? Math.round(pago / total * 100) : 0;
        const quit = rest <= 0;
        const barra = row.querySelector('.forn-barra-fill-adm');
        if (barra) {
          barra.style.width        = pct + '%';
          barra.className          = 'forn-barra-fill-adm ' + (quit ? 'bg-success' : pct >= 50 ? 'bg-info' : 'bg-warning');
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
        input.value = pago.toLocaleString('pt-BR', { minimumFractionDigits: 2 });
        recalcularTotaisAdm();
        toast(quit ? 'Pagamento quitado! 🎉' : 'Pagamento atualizado!', quit ? 'verde' : 'info');
      } else {
        toast(r.msg || 'Erro ao salvar pagamento.', 'verm');
      }
    } catch { toast('Erro de conexão. Tente novamente.', 'verm'); }
    btn.innerHTML = origBtn;
    btn.disabled  = false;
  });
});

document.querySelectorAll('.forn-input-pago-adm').forEach(input => {
  input.addEventListener('blur',  () => { const n = parseBrl(input.value); if (!isNaN(n)) input.value = n.toLocaleString('pt-BR', { minimumFractionDigits: 2 }); });
  input.addEventListener('focus', () => input.select());
});

/* ---- CONVIDADOS ---- */
function deltaCntTotal(n) {
  const e = document.getElementById('cnt-total');
  if (e) e.textContent = +e.textContent + n;
}
function deltaCntStatus(conf, n) {
  const el = document.getElementById(conf ? 'cnt-conf' : 'cnt-pend');
  if (el) el.textContent = +el.textContent + n;
  if (conf) {
    const badge = document.getElementById('cnt-badge-conf');
    if (badge) badge.textContent = +badge.textContent + n;
  }
}

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

/* ---- BLOCO DE NOTAS ---- */
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
  const total = document.querySelectorAll('#grid-notas .nota-card-wrap').length;
  const txt   = total + ' nota' + (total !== 1 ? 's' : '');
  const badge  = document.getElementById('notas-count-badge');
  const badge2 = document.getElementById('notas-badge-count');
  if (badge)  badge.textContent = txt;
  if (badge2) badge2.closest('.badge').innerHTML = `<i class="bi bi-journals me-1"></i>${total} nota${total !== 1 ? 's' : ''}`;
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
                      data-id="${r.id}" data-titulo="${r.titulo.replace(/"/g,'&quot;')}"
                      data-conteudo="${r.conteudo.replace(/"/g,'&quot;')}"
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
            <span class="badge bg-warning text-dark rounded-pill px-3" style="font-size:.68rem;">
              <i class="bi bi-journals me-1"></i>
              <span id="notas-badge-count">0</span> notas
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

function detectarPlataforma(url) {
  if (!url) return null;
  if (url.includes('youtube.com') || url.includes('youtu.be')) return 'youtube';
  if (url.includes('spotify.com')) return 'spotify';
  return 'link';
}

function linkBtnHtml(link, accentBg, accentColor) {
  if (!link) return '';
  const plat = detectarPlataforma(link);
  const safe = link.replace(/"/g, '&quot;');
  let icone = `<i class="bi bi-link-45deg me-1"></i>Ouvir`;
  if (plat === 'youtube') icone = `<i class="bi bi-youtube text-danger me-1"></i>YouTube`;
  if (plat === 'spotify') icone = `<i class="bi bi-spotify text-success me-1"></i>Spotify`;
  return `<a href="${safe}" target="_blank" rel="noopener"
              class="btn btn-sm py-1 px-2 rounded-pill flex-shrink-0"
              style="background:${accentBg};color:${accentColor};font-size:.65rem;border:none;white-space:nowrap;"
              title="Abrir link">${icone}</a>`;
}

function musicaCardHtml(m) {
  const eSug = m.status === 'sugestao';
  const cor  = eSug
    ? { brd:'#a78bfa', bg:'#ede9fe', txt:'#7c3aed', icone:'bi-lightbulb' }
    : { brd:'#86efac', bg:'#dcfce7', txt:'#16a34a', icone:'bi-check-circle-fill' };
  const artistaHtml = m.artista
    ? `<div class="text-muted" style="font-size:.69rem;"><i class="bi bi-person-fill me-1" style="font-size:.6rem;"></i>${m.artista}</div>`
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
         style="border-left:3px solid ${cor.brd};">
      <div class="d-flex align-items-center justify-content-center rounded-2 flex-shrink-0"
           style="width:34px;height:34px;background:${cor.bg};">
        <i class="bi ${cor.icone}" style="color:${cor.txt};font-size:.9rem;"></i>
      </div>
      <div class="flex-fill" style="min-width:0;">
        <div class="fw-bold text-dark text-truncate" style="font-size:.84rem;">${m.titulo}</div>
        ${artistaHtml}
      </div>
      ${linkBtnHtml(m.link, cor.bg, cor.txt)}
      ${confirmarBtn}
      <button type="button" class="btn p-1 border-0 bg-transparent text-danger btn-excluir-musica flex-shrink-0"
              data-id="${m.id}" title="Remover música">
        <i class="bi bi-trash" style="font-size:.78rem;"></i>
      </button>
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

function garantirGrupo(painel, momento, aba) {
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
  let grupo = wrap.querySelector(`.musica-grupo[data-aba="${aba}"][data-momento="${CSS.escape(momento)}"]`);
  if (!grupo) {
    grupo = document.createElement('div');
    grupo.className = 'musica-grupo mb-3';
    grupo.dataset.aba = aba;
    grupo.dataset.momento = momento;
    grupo.innerHTML = `
      <div class="musica-grupo-header" style="background:${cor.bg};">
        <i class="bi bi-collection-play" style="color:${cor.badge};font-size:.85rem;"></i>
        <span class="momento-label" style="color:${cor.txt};">${momento}</span>
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
    titulo:  card.querySelector('.text-truncate').textContent.trim(),
    artista: card.querySelector('.bi-person-fill')?.nextSibling?.textContent?.trim() || '',
    link:    card.querySelector('a[href]')?.href || '',
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
    const grupo = garantirGrupo(painelDestino, momento, abaDestino);
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
  const grupo  = garantirGrupo(painel, m.momento, 'sugestao');
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

// Inicializa botões existentes
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