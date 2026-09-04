<?php
session_start();
require_once 'sessao_timeout.inc.php';
verificar_sessao_ativa();

if (!isset($_SESSION['usuario_tipo']) || $_SESSION['usuario_tipo'] !== 'noivos') {
    header("Location: index.php?sessao_expirada=1");
    exit;
}

require_once 'conexao.php';
require_once 'notificacoes.inc.php';

$evento_id = (int)$_SESSION['evento_id'];

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

// Link público de confirmação de presença para compartilhar com os convidados.
// Checa também X-Forwarded-Proto: atrás de proxy/load balancer, $_SERVER['HTTPS']
// não reflete o protocolo real usado pelo navegador.
$https_ativo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
$link_confirmacao_scheme = $https_ativo ? 'https://' : 'http://';
$link_confirmacao_base   = $link_confirmacao_scheme . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/');
$link_confirmacao_url    = $link_confirmacao_base . '/confirmar.php?evento=' . $evento_id;

// Mesma migração de gerenciar.php — roda só uma vez (marcador em disco) em vez
// de em toda requisição, já que essa página é recarregada o tempo todo.
if (!schema_ja_verificado('noivos')) {
    try { $pdo->query("SELECT data_prazo FROM checklist LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE checklist ADD COLUMN data_prazo DATE NULL"); }

    // Colunas de rastreio de conclusão (quem/quando) — usadas pelo sino de notificações do admin
    try { $pdo->query("SELECT concluido_em FROM checklist LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE checklist ADD COLUMN concluido_em DATETIME NULL"); }
    try { $pdo->query("SELECT concluido_por FROM checklist LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE checklist ADD COLUMN concluido_por VARCHAR(20) NULL"); }

    // Foto do casal exibida no convite (link público de confirmação de presença)
    try { $pdo->query("SELECT foto_casal FROM eventos LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE eventos ADD COLUMN foto_casal VARCHAR(255) NULL"); }
    try { $pdo->query("SELECT foto_casal_ativa FROM eventos LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE eventos ADD COLUMN foto_casal_ativa TINYINT(1) NOT NULL DEFAULT 0"); }

    // Cor de fundo da página do convite (link público de confirmação de presença)
    try { $pdo->query("SELECT cor_convite FROM eventos LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE eventos ADD COLUMN cor_convite VARCHAR(7) NULL"); }

    // Token do link específico de confirmação (por convidado)
    try { $pdo->query("SELECT token_convite FROM convidados LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE convidados ADD COLUMN token_convite VARCHAR(64) NULL"); }

    // Acompanhante de um link específico (aponta pro id do convidado "dono" do link)
    try { $pdo->query("SELECT convidado_principal_id FROM convidados LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE convidados ADD COLUMN convidado_principal_id INT NULL"); }

    // Modo de confirmação escolhido para o evento (geral ou específico)
    try { $pdo->query("SELECT modo_confirmacao FROM eventos LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE eventos ADD COLUMN modo_confirmacao VARCHAR(20) NOT NULL DEFAULT 'geral'"); }

    marcar_schema_verificado('noivos');
}

// Tabela de documentos/uploads (contrato, RG, comprovantes...) — compartilhada com
// gerenciar.php (mesmo marcador em disco: se a assessoria já abriu o Uploads antes,
// a tabela já existe e essa checagem só confirma e segue). Só marca como verificado
// se a tabela for criada de fato, senão a página quebraria pra sempre com uma
// tabela inexistente caso o CREATE falhasse por qualquer motivo.
if (!schema_ja_verificado('gerenciar_documentos_v1')) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS documentos_evento (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                evento_id     INT          NOT NULL,
                categoria     VARCHAR(50)  NOT NULL DEFAULT 'Outros',
                nome_original VARCHAR(255) NOT NULL,
                nome_arquivo  VARCHAR(255) NOT NULL,
                extensao      VARCHAR(10)  NOT NULL,
                tamanho       INT          NOT NULL DEFAULT 0,
                enviado_em    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_doc_evento (evento_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        marcar_schema_verificado('gerenciar_documentos_v1');
    } catch (PDOException $e) {}
}

/* ============================================================
   HELPER: Resposta JSON para AJAX
   ============================================================ */
function json_out(array $data): void {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/* Formata um tamanho em bytes pra "KB"/"MB" legível */
function tamanho_arquivo_fmt(int $bytes): string {
    if ($bytes >= 1024 * 1024) return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
    return number_format(max(1, $bytes) / 1024, 0, ',', '.') . ' KB';
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
   Carrega dados do evento
   ============================================================ */
$s = $pdo->prepare("
    SELECT e.*, c.nome, c.email, c.telefone
    FROM eventos e
    INNER JOIN clientes c ON e.cliente_id = c.id
    WHERE e.id = ?
");
$s->execute([$evento_id]);
$evento = $s->fetch();
if (!$evento) { die("Casamento não encontrado."); }

/* ============================================================
   POST HANDLERS
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verificar_csrf();

    $ajax = isset($_POST['is_ajax']);

    // 1. Toggle tarefa
    if (isset($_POST['toggle_check'])) {
        $id    = (int)$_POST['check_id'];
        $atual = (int)$_POST['status_atual'];
        $novo  = $atual === 1 ? 0 : 1;
        $pdo->prepare("UPDATE checklist SET checado = ?, status = ?, concluido_em = " . ($novo ? "NOW()" : "NULL") . ", concluido_por = ? WHERE id = ? AND evento_id = ?")
            ->execute([$novo, $novo ? 'concluido' : 'pendente', $novo ? 'Noivos' : null, $id, $evento_id]);
        if ($ajax) json_out(['ok' => true, 'novo' => $novo]);
        exit;
    }

    // 2. Comentário de tarefa
    if (isset($_POST['adicionar_comentario_noivos'])) {
        $id    = (int)$_POST['check_id'];
        $texto = trim($_POST['novo_comentario'] ?? '');
        if ($texto !== '') {
            $pdo->prepare("INSERT INTO checklist_comentarios (checklist_id, autor, comentario) VALUES (?, 'Noivos', ?)")
                ->execute([$id, $texto]);
            if ($ajax) json_out(['ok' => true, 'autor' => 'Noivos', 'texto' => htmlspecialchars($texto)]);
        }
        if (!$ajax) { header("Location: noivos.php"); exit; }
        exit;
    }

    // 3. Comentário de etapa
    if (isset($_POST['comentario_etapa_noivos'])) {
        $etapa = trim($_POST['etapa_nome'] ?? '');
        $texto = trim($_POST['novo_comentario_etapa'] ?? '');
        if ($etapa !== '' && $texto !== '') {
            $pdo->prepare("INSERT INTO checklist_comentarios (evento_id, etapa_nome, autor, comentario) VALUES (?, ?, 'Noivos', ?)")
                ->execute([$evento_id, $etapa, $texto]);
            if ($ajax) json_out(['ok' => true, 'autor' => 'Noivos', 'texto' => htmlspecialchars($texto)]);
        }
        if (!$ajax) { header("Location: noivos.php"); exit; }
        exit;
    }

    // 5. Toggle confirmação do convidado
    if (isset($_POST['toggle_convidado'])) {
        $id  = (int)$_POST['convidado_id'];
        $novo = (int)$_POST['status_atual'] === 1 ? 0 : 1;
        $pdo->prepare("UPDATE convidados SET confirmado = ? WHERE id = ? AND evento_id = ?")
            ->execute([$novo, $id, $evento_id]);
        if ($ajax) json_out(['ok' => true, 'novo' => $novo]);
        header("Location: noivos.php"); exit;
    }

    // 6. Excluir convidado (e os acompanhantes ligados a ele, se houver)
    if (isset($_POST['excluir_convidado_noivos'])) {
        $id  = (int)$_POST['convidado_id'];
        $chk = $pdo->prepare("SELECT confirmado FROM convidados WHERE id = ? AND evento_id = ?");
        $chk->execute([$id, $evento_id]);
        $row = $chk->fetch();
        $pdo->prepare("DELETE FROM convidados WHERE convidado_principal_id = ? AND evento_id = ?")
            ->execute([$id, $evento_id]);
        $pdo->prepare("DELETE FROM convidados WHERE id = ? AND evento_id = ?")
            ->execute([$id, $evento_id]);
        if ($ajax) json_out(['ok' => true, 'era_conf' => $row ? (int)$row['confirmado'] : 0]);
        header("Location: noivos.php"); exit;
    }

    // 6b. Criar convite (Noivos) — sempre entra como "pendente"
    if (isset($_POST['adicionar_convidado_noivos'])) {
        $nome       = trim($_POST['nome_convidado']      ?? '');
        $fone       = trim($_POST['telefone_convidado']  ?? '');
        $cat        = trim($_POST['categoria_convidado'] ?? '') ?: 'Outros';
        $nomes_acomp  = $_POST['nome_acompanhante_novo']  ?? [];
        $faixas_acomp = $_POST['faixa_acompanhante_novo'] ?? [];
        if ($nome === '') {
            if ($ajax) json_out(['ok' => false, 'msg' => 'Informe o nome do convidado.']);
        } elseif (strlen(preg_replace('/\D+/', '', $fone)) < 10) {
            if ($ajax) json_out(['ok' => false, 'msg' => 'Informe um telefone/WhatsApp válido (com DDD) para o convidado.']);
        } else {
            $pdo->prepare("INSERT INTO convidados (evento_id, nome, telefone, categoria, confirmado) VALUES (?, ?, ?, ?, 0)")
                ->execute([$evento_id, $nome, $fone, $cat]);
            $novo_id = (int)$pdo->lastInsertId();
            sincronizar_acompanhantes($pdo, $evento_id, $novo_id, [], $nomes_acomp, $faixas_acomp);
            if ($ajax) {
                $stAc = $pdo->prepare("SELECT id, nome, faixa_etaria FROM convidados WHERE convidado_principal_id = ? AND evento_id = ?");
                $stAc->execute([$novo_id, $evento_id]);
                $acompanhantes_atuais = array_map(fn($a) => ['id' => $a['id'], 'nome' => $a['nome'], 'faixa' => $a['faixa_etaria']], $stAc->fetchAll(PDO::FETCH_ASSOC));
                json_out([
                    'ok'             => true,
                    'id'             => $novo_id,
                    'nome'           => htmlspecialchars($nome),
                    'categoria'      => htmlspecialchars($cat),
                    'telefone'       => htmlspecialchars($fone),
                    'acompanhantes'  => $acompanhantes_atuais,
                    'confirmado'     => 0,
                ]);
            }
        }
        header("Location: noivos.php"); exit;
    }

    // 6c. Editar convidado (Noivos)
    if (isset($_POST['editar_convidado_noivos'])) {
        $id         = (int)($_POST['convidado_id'] ?? 0);
        $nome       = trim($_POST['nome_convidado']      ?? '');
        $fone       = trim($_POST['telefone_convidado']  ?? '');
        $cat        = trim($_POST['categoria_convidado'] ?? '') ?: 'Outros';
        $ids_acomp    = $_POST['id_acompanhante_edit']    ?? [];
        $nomes_acomp  = $_POST['nome_acompanhante_edit']  ?? [];
        $faixas_acomp = $_POST['faixa_acompanhante_edit'] ?? [];
        if ($id <= 0 || $nome === '') {
            if ($ajax) json_out(['ok' => false, 'msg' => 'Informe o nome do convidado.']);
        } elseif (strlen(preg_replace('/\D+/', '', $fone)) < 10) {
            if ($ajax) json_out(['ok' => false, 'msg' => 'Informe um telefone/WhatsApp válido (com DDD) para o convidado.']);
        } else {
            $pdo->prepare("UPDATE convidados SET nome = ?, telefone = ?, categoria = ? WHERE id = ? AND evento_id = ?")
                ->execute([$nome, $fone, $cat, $id, $evento_id]);
            sincronizar_acompanhantes($pdo, $evento_id, $id, $ids_acomp, $nomes_acomp, $faixas_acomp);
            if ($ajax) {
                $stAc = $pdo->prepare("SELECT id, nome, faixa_etaria FROM convidados WHERE convidado_principal_id = ? AND evento_id = ?");
                $stAc->execute([$id, $evento_id]);
                $acompanhantes_atuais = array_map(fn($a) => ['id' => $a['id'], 'nome' => $a['nome'], 'faixa' => $a['faixa_etaria']], $stAc->fetchAll(PDO::FETCH_ASSOC));
                json_out([
                    'ok'             => true,
                    'id'             => $id,
                    'nome'           => htmlspecialchars($nome),
                    'categoria'      => htmlspecialchars($cat),
                    'telefone'       => htmlspecialchars($fone),
                    'acompanhantes'  => $acompanhantes_atuais,
                ]);
            }
        }
        header("Location: noivos.php"); exit;
    }

    // 7. Corrigir valor pago de um fornecedor, sobrescrevendo o total (AJAX)
    if (isset($_POST['atualizar_valor_pago'])) {
        $forn_id    = (int)$_POST['fornecedor_id'];
        $valor_pago = (float)($_POST['valor_pago'] ?? 0);

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
        header("Location: noivos.php"); exit;
    }

    // 7b. Adicionar pagamento (soma ao valor já pago) de um fornecedor (AJAX)
    if (isset($_POST['adicionar_pagamento'])) {
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
        header("Location: noivos.php"); exit;
    }

    // 8. Adicionar Música (Noivos)
    if (isset($_POST['adicionar_musica_noivos'])) {
        $momento = trim($_POST['momento_musica'] ?? '');
        $titulo  = trim($_POST['titulo_musica'] ?? '');
        $link    = trim($_POST['link_musica'] ?? '');
        if ($link !== '' && !preg_match('#^https?://#i', $link)) {
            $link = '';
        }

        if ($momento !== '' && $titulo !== '') {
            $pdo->prepare("INSERT INTO musicas_evento (evento_id, momento, titulo, link, status) VALUES (?, ?, ?, ?, 'sugestao')")
                ->execute([$evento_id, $momento, $titulo, $link]);
            $ret_id = (int)$pdo->lastInsertId();
            if ($ajax) json_out([
                'ok'      => true,
                'id'      => $ret_id,
                'momento' => htmlspecialchars($momento),
                'titulo'  => htmlspecialchars($titulo),
                'link'    => htmlspecialchars($link)
            ]);
        } else {
            if ($ajax) json_out(['ok' => false, 'msg' => 'Preencha o momento e a música.']);
        }
        header("Location: noivos.php"); exit;
    }

    // 9. Excluir Música (Noivos)
    if (isset($_POST['excluir_musica_noivos'])) {
        $musica_id = (int)$_POST['musica_id'];
        $pdo->prepare("DELETE FROM musicas_evento WHERE id=? AND evento_id=?")->execute([$musica_id, $evento_id]);
        if ($ajax) json_out(['ok' => true]);
        header("Location: noivos.php"); exit;
    }

    // 10. Salvar / (re)ativar ou desativar a foto do casal exibida no convite
    if (isset($_POST['salvar_foto_casal'])) {
        $ativa = ($_POST['foto_ativa'] ?? '0') === '1';

        if (!$ativa) {
            $pdo->prepare("UPDATE eventos SET foto_casal_ativa = 0 WHERE id = ?")->execute([$evento_id]);
            if ($ajax) json_out(['ok' => true, 'ativa' => 0]);
            header("Location: noivos.php"); exit;
        }

        $tem_arquivo_novo = isset($_FILES['foto_casal_arquivo']) && $_FILES['foto_casal_arquivo']['error'] === UPLOAD_ERR_OK;

        if ($tem_arquivo_novo) {
            $tmpPath   = $_FILES['foto_casal_arquivo']['tmp_name'];
            $ext       = strtolower(pathinfo($_FILES['foto_casal_arquivo']['name'], PATHINFO_EXTENSION));
            $permitido = ['jpg', 'jpeg', 'png', 'webp'];

            if (!in_array($ext, $permitido) || @getimagesize($tmpPath) === false) {
                if ($ajax) json_out(['ok' => false, 'msg' => 'Envie uma imagem JPG, PNG ou WEBP válida.']);
            } else {
                $novo_nome = 'casal_' . $evento_id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($tmpPath, './uploads/' . $novo_nome)) {
                    if (!empty($evento['foto_casal'])) {
                        $antigo = './uploads/' . $evento['foto_casal'];
                        if (is_file($antigo)) @unlink($antigo);
                    }
                    $pdo->prepare("UPDATE eventos SET foto_casal = ?, foto_casal_ativa = 1 WHERE id = ?")
                        ->execute([$novo_nome, $evento_id]);
                    if ($ajax) json_out(['ok' => true, 'ativa' => 1, 'foto_url' => 'uploads/' . $novo_nome]);
                } else {
                    if ($ajax) json_out(['ok' => false, 'msg' => 'Falha ao salvar o arquivo no servidor.']);
                }
            }
        } elseif (!empty($evento['foto_casal'])) {
            $pdo->prepare("UPDATE eventos SET foto_casal_ativa = 1 WHERE id = ?")->execute([$evento_id]);
            if ($ajax) json_out(['ok' => true, 'ativa' => 1, 'foto_url' => 'uploads/' . $evento['foto_casal']]);
        } else {
            if ($ajax) json_out(['ok' => false, 'msg' => 'Anexe uma foto para ativar essa opção.']);
        }
        header("Location: noivos.php"); exit;
    }

    // 11. Remover a foto do casal
    if (isset($_POST['remover_foto_casal'])) {
        if (!empty($evento['foto_casal'])) {
            $arq = './uploads/' . $evento['foto_casal'];
            if (is_file($arq)) @unlink($arq);
        }
        $pdo->prepare("UPDATE eventos SET foto_casal = NULL, foto_casal_ativa = 0 WHERE id = ?")->execute([$evento_id]);
        if ($ajax) json_out(['ok' => true]);
        header("Location: noivos.php"); exit;
    }

    // 12. Salvar a cor de fundo da página do convite
    if (isset($_POST['salvar_cor_convite'])) {
        $cor = trim($_POST['cor'] ?? '');
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $cor)) {
            $pdo->prepare("UPDATE eventos SET cor_convite = ? WHERE id = ?")->execute([$cor, $evento_id]);
            if ($ajax) json_out(['ok' => true, 'cor' => $cor]);
        } else {
            if ($ajax) json_out(['ok' => false, 'msg' => 'Cor inválida.']);
        }
        header("Location: noivos.php"); exit;
    }

    // 13. Gerar link específico de confirmação (travado no WhatsApp do convidado, com acompanhantes)
    if (isset($_POST['criar_link_especifico'])) {
        $nome_link = trim($_POST['nome_convidado_link'] ?? '');
        $tel_link  = trim($_POST['telefone_convidado_link'] ?? '');
        $tel_link_digits = preg_replace('/\D+/', '', $tel_link);
        $nomes_acomp  = $_POST['nome_acompanhante_link']  ?? [];
        $faixas_acomp = $_POST['faixa_acompanhante_link'] ?? [];

        if ($nome_link === '' || strlen($tel_link_digits) < 10) {
            if ($ajax) json_out(['ok' => false, 'msg' => 'Informe o nome e um número de WhatsApp válido (com DDD).']);
            header("Location: noivos.php"); exit;
        }
        // Número digitado é só DDD+telefone (10/11 dígitos); sem o código do país o
        // WhatsApp interpreta o DDD como início de um código de outro país.
        $tel_link_digits_wpp = strlen($tel_link_digits) <= 11 ? '55' . $tel_link_digits : $tel_link_digits;

        do {
            $token_link = bin2hex(random_bytes(16));
            $chk = $pdo->prepare("SELECT id FROM convidados WHERE token_convite = ?");
            $chk->execute([$token_link]);
        } while ($chk->fetch());

        $pdo->prepare("INSERT INTO convidados (evento_id, nome, telefone, categoria, confirmado, token_convite) VALUES (?, ?, ?, 'Outros', 0, ?)")
            ->execute([$evento_id, $nome_link, $tel_link, $token_link]);
        $novo_convidado_id = (int)$pdo->lastInsertId();

        $acompanhantes_criados = [];
        $insAcomp = $pdo->prepare("INSERT INTO convidados (evento_id, nome, faixa_etaria, categoria, confirmado, convidado_principal_id) VALUES (?, ?, ?, 'Outros', 0, ?)");
        for ($i = 0; $i < count($nomes_acomp); $i++) {
            $nome_acomp = trim($nomes_acomp[$i]);
            if ($nome_acomp === '') continue;
            $faixa_acomp = in_array($faixas_acomp[$i] ?? '', ['Criança de Colo (0-5 anos)', 'Criança (6-10 anos)', 'Adulto (11+ anos)'], true)
                ? $faixas_acomp[$i] : 'Adulto (11+ anos)';
            $insAcomp->execute([$evento_id, $nome_acomp, $faixa_acomp, $novo_convidado_id]);
            $acompanhantes_criados[] = ['id' => (int)$pdo->lastInsertId(), 'nome' => $nome_acomp];
        }

        if ($ajax) {
            json_out([
                'ok'              => true,
                'id'              => $novo_convidado_id,
                'nome'            => $nome_link,
                'telefone'        => $tel_link,
                'telefone_digits' => $tel_link_digits_wpp,
                'link'            => $link_confirmacao_url . '&token=' . $token_link,
                'acompanhantes'   => $acompanhantes_criados,
            ]);
        }
        header("Location: noivos.php"); exit;
    }

    // 14. Remover / revogar link específico (AJAX) — junto com os acompanhantes ainda pendentes
    if (isset($_POST['excluir_link_especifico'])) {
        $id_link = (int)($_POST['convidado_id'] ?? 0);
        $chk = $pdo->prepare("SELECT resposta_rsvp FROM convidados WHERE id = ? AND evento_id = ?");
        $chk->execute([$id_link, $evento_id]);
        $conv_link = $chk->fetch();
        if ($conv_link) {
            if ($conv_link['resposta_rsvp'] === null) {
                $pdo->prepare("DELETE FROM convidados WHERE convidado_principal_id = ? AND evento_id = ? AND resposta_rsvp IS NULL")->execute([$id_link, $evento_id]);
                $pdo->prepare("DELETE FROM convidados WHERE id = ? AND evento_id = ?")->execute([$id_link, $evento_id]);
            } else {
                $pdo->prepare("UPDATE convidados SET token_convite = NULL WHERE id = ? AND evento_id = ?")->execute([$id_link, $evento_id]);
            }
        }
        if ($ajax) json_out(['ok' => true]);
        header("Location: noivos.php"); exit;
    }

    // 15. Enviar documento/arquivo do evento (contrato, RG, comprovantes...) — feito
    // 100% via AJAX (modal de Uploads). Mesma tabela usada pela assessoria em
    // gerenciar.php — cada evento só vê os próprios arquivos (filtrado por evento_id).
    if (isset($_POST['upload_documento'])) {
        $categoria = trim($_POST['categoria_documento'] ?? '') ?: 'Outros';
        $categoria = mb_substr($categoria, 0, 50);

        $arquivo = $_FILES['arquivo_documento'] ?? null;
        if (!$arquivo || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            json_out(['ok' => false, 'msg' => 'Selecione um arquivo para enviar.']);
        }
        if ($arquivo['error'] === UPLOAD_ERR_INI_SIZE || $arquivo['error'] === UPLOAD_ERR_FORM_SIZE) {
            json_out(['ok' => false, 'msg' => 'Arquivo grande demais para o limite do servidor.']);
        }
        if ($arquivo['error'] !== UPLOAD_ERR_OK) {
            json_out(['ok' => false, 'msg' => 'Não foi possível enviar o arquivo.']);
        }

        // Extensão sozinha não garante nada (um .txt renomeado pra .pdf passaria
        // batido) — getimagesize() confirma que é mesmo uma imagem, e o PDF é
        // validado pela assinatura binária real ("%PDF-") em vez do nome do arquivo.
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
            json_out(['ok' => false, 'msg' => 'Formato não suportado. Envie imagens (jpg, png, webp, gif) ou PDF.']);
        }

        $nomeArquivo = 'doc_' . $evento_id . '_' . time() . '_' . random_int(1000, 9999) . '.' . $extensao;
        if (!move_uploaded_file($arquivo['tmp_name'], './uploads/' . $nomeArquivo)) {
            json_out(['ok' => false, 'msg' => 'Não foi possível salvar o arquivo no servidor.']);
        }

        $nomeOriginal = mb_substr($arquivo['name'], 0, 255);
        $pdo->prepare("INSERT INTO documentos_evento (evento_id, categoria, nome_original, nome_arquivo, extensao, tamanho) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$evento_id, $categoria, $nomeOriginal, $nomeArquivo, $extensao, (int)$arquivo['size']]);

        json_out([
            'ok' => true,
            'documento' => [
                'id'            => (int)$pdo->lastInsertId(),
                'categoria'     => $categoria,
                'nome_original' => $nomeOriginal,
                'nome_arquivo'  => $nomeArquivo,
                'extensao'      => $extensao,
                'is_imagem'     => in_array($extensao, $extensoesImagem, true),
                'tamanho_fmt'   => tamanho_arquivo_fmt((int)$arquivo['size']),
                'enviado_em'    => date('d/m/Y \à\s H:i'),
            ],
        ]);
    }

    // 16. Excluir documento/arquivo do evento
    if (isset($_POST['excluir_documento'])) {
        $doc_id = (int)($_POST['documento_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT nome_arquivo FROM documentos_evento WHERE id = ? AND evento_id = ?");
        $stmt->execute([$doc_id, $evento_id]);
        $doc = $stmt->fetch();
        if ($doc) {
            $caminho = './uploads/' . $doc['nome_arquivo'];
            if (is_file($caminho)) @unlink($caminho);
            $pdo->prepare("DELETE FROM documentos_evento WHERE id = ? AND evento_id = ?")->execute([$doc_id, $evento_id]);
        }
        json_out(['ok' => true]);
    }
}

/* ============================================================
   CARREGAMENTO DE DADOS (GET)
   ============================================================ */

// Checklist
$rs = $pdo->prepare("SELECT * FROM checklist WHERE evento_id = ? ORDER BY etapa ASC, id ASC");
$rs->execute([$evento_id]);
$lista_checklist = $rs->fetchAll();

// Convidados
$rs2 = $pdo->prepare("SELECT * FROM convidados WHERE evento_id = ? ORDER BY nome ASC");
$rs2->execute([$evento_id]);
$lista_convidados = $rs2->fetchAll();
$links_especificos = array_values(array_filter($lista_convidados, fn($c) => !empty($c['token_convite'])));
$acompanhantes_por_principal = [];
foreach ($lista_convidados as $c) {
    if (!empty($c['convidado_principal_id'])) {
        $acompanhantes_por_principal[$c['convidado_principal_id']][] = $c;
    }
}

// Acompanhantes do link específico não aparecem como convidados avulsos na lista —
// eles vêm agrupados dentro do card do convidado titular.
$lista_convidados_principais = array_values(array_filter($lista_convidados, fn($c) => empty($c['convidado_principal_id'])));

$total_conf = 0; $total_pend = 0;
$conv_grupos = ['Família' => [], 'Amigos' => [], 'Outros' => []];
foreach ($lista_convidados_principais as $c) {
    $c['confirmado'] ? $total_conf++ : $total_pend++;
    $cat = $c['categoria'] ?: 'Outros';
    if (!array_key_exists($cat, $conv_grupos)) $conv_grupos[$cat] = [];
    $conv_grupos[$cat][] = $c;
}

// Categorias fixas primeiro (Família, Amigos, Outros), depois quaisquer categorias
// customizadas (ex: cadastradas em Organizar Mesas), em ordem alfabética.
$grupos_fixos  = ['Família', 'Amigos', 'Outros'];
$grupos_extras = array_diff(array_keys($conv_grupos), $grupos_fixos);
sort($grupos_extras, SORT_FLAG_CASE | SORT_STRING);
$ordem_grupos = array_merge($grupos_fixos, $grupos_extras);

$categorias_existentes = array_values(array_unique(array_filter(array_map(
    fn($c) => trim($c['categoria'] ?? ''), $lista_convidados_principais
))));
sort($categorias_existentes, SORT_FLAG_CASE | SORT_STRING);

// Notificações da assessoria
$rs3 = $pdo->prepare("
    SELECT cc.*, ch.tarefa
    FROM checklist_comentarios cc
    LEFT JOIN checklist ch ON cc.checklist_id = ch.id
    WHERE (ch.evento_id = ? OR cc.evento_id = ?)
      AND cc.autor = 'Assessoria'
    ORDER BY cc.data_cadastro DESC
    LIMIT 15
");
$rs3->execute([$evento_id, $evento_id]);
$notificacoes = $rs3->fetchAll();

$ultima_vista_noivos = ultima_visualizacao_notificacoes($pdo, 'noivos', (int)($_SESSION['usuario_id'] ?? 0));
$nao_lidas = 0;
foreach ($notificacoes as $n) {
    if (!$ultima_vista_noivos || $n['data_cadastro'] > $ultima_vista_noivos) $nao_lidas++;
}
$notificacoes = array_values(array_filter($notificacoes, fn($n) => !$ultima_vista_noivos || $n['data_cadastro'] > $ultima_vista_noivos));

// Fornecedores
$rs4 = $pdo->prepare("SELECT * FROM fornecedores_evento WHERE evento_id = ? AND status != 'Cancelado' ORDER BY status ASC, servico ASC");
$rs4->execute([$evento_id]);
$todos_fornecedores = $rs4->fetchAll();

$valor_cont  = 0.0;
$valor_neg   = 0.0;
$valor_pago_total = 0.0;
$lista_cont  = [];

foreach ($todos_fornecedores as $f) {
    if ($f['status'] === 'Contratado') {
        $valor_cont += (float)$f['valor'];
        $valor_pago_total += (float)($f['valor_pago'] ?? 0);
        $lista_cont[] = $f;
    } elseif ($f['status'] === 'Orçamento') {
        $valor_neg += (float)$f['valor'];
    }
}

$valor_restante_total = $valor_cont - $valor_pago_total;
$pct_pago = $valor_cont > 0 ? round($valor_pago_total / $valor_cont * 100) : 0;

// Músicas do evento
$rs_musicas = $pdo->prepare("SELECT * FROM musicas_evento WHERE evento_id = ? ORDER BY id ASC");
$rs_musicas->execute([$evento_id]);
$lista_musicas = $rs_musicas->fetchAll();
$total_musicas = count($lista_musicas);

// Documentos/uploads do evento (contrato, RG, comprovantes...) — mesma tabela
// usada pela assessoria em gerenciar.php, sempre filtrada pelo evento_id da
// sessão então cada casal só vê os próprios arquivos.
$categorias_documento_padrao = ['Contrato', 'Documento Pessoal', 'Comprovante de Pagamento'];
$documentos_por_categoria = [];
$rs_docs = $pdo->prepare("SELECT * FROM documentos_evento WHERE evento_id = ? ORDER BY enviado_em DESC");
$rs_docs->execute([$evento_id]);
$lista_documentos = $rs_docs->fetchAll();
$total_documentos = count($lista_documentos);
foreach ($lista_documentos as $doc) {
    $documentos_por_categoria[$doc['categoria']][] = $doc;
}
$categorias_documento = array_unique(array_merge($categorias_documento_padrao, array_keys($documentos_por_categoria)));

// FIX N+1 – precarrega comentários de todas as tarefas
$ids = array_column($lista_checklist, 'id');
$coments_tarefa = [];
if (!empty($ids)) {
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $rs5 = $pdo->prepare("SELECT * FROM checklist_comentarios WHERE checklist_id IN ($ph) ORDER BY data_cadastro ASC");
    $rs5->execute($ids);
    foreach ($rs5->fetchAll() as $c) { $coments_tarefa[$c['checklist_id']][] = $c; }
}

// FIX N+1 – precarrega comentários de todas as etapas
$rs6 = $pdo->prepare("SELECT * FROM checklist_comentarios WHERE evento_id = ? AND etapa_nome IS NOT NULL ORDER BY data_cadastro ASC");
$rs6->execute([$evento_id]);
$coments_etapa = [];
foreach ($rs6->fetchAll() as $c) { $coments_etapa[$c['etapa_nome']][] = $c; }

// Agrupamento + cálculo de progresso
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
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Nosso Casamento ♡ - Meu Evento PRO</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
  <link rel="stylesheet" href="css/estilo.css?v=13">
  <style>
    :root {
      --radius: 16px;
      --verde:  #22c55e;
      --amarel: #f59e0b;
      --azul:   #3b82f6;
      --verm:   #ef4444;
    }
    body { font-family: 'Inter', system-ui, sans-serif; background: var(--bg-app); }

    /* ---- HERO DO CABEÇALHO (mesmo layout usado em gerenciar.php) ---- */
    .nome-noivos-titulo {
      font-size: clamp(1.4rem, 6vw, 2.6rem);
      font-weight: 800;
      text-transform: uppercase;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      display: block;
      max-width: 100%;
      line-height: 1.05;
    }
    .header-btn-exportar {
      order: 1;
      background: #fff; color: #b45309; border: none;
      box-shadow: 0 2px 10px rgba(0,0,0,.15);
    }
    .header-btn-exportar:hover { background: #fffaf0; color: #92400e; }
    .btn-chama-atencao { animation: pulso-convite 2.2s ease-in-out infinite; }
    .btn-chama-atencao:hover { animation-play-state: paused; }
    @keyframes pulso-convite {
      0%, 100% { box-shadow: 0 0 0 0 rgba(255,255,255,.55); }
      50%      { box-shadow: 0 0 0 7px rgba(255,255,255,0); }
    }
    .header-btn-sino {
      border-color: rgba(255,255,255,.55) !important;
      background: transparent !important;
    }
    .header-btn-sino .badge { background: #f59e0b !important; }
    .header-actions-noivos { margin-left: auto; order: 2; }

    .header-hero-accent {
      border-left: 3px solid #f0c987;
      padding-left: 1rem;
    }
    .header-hero-label {
      font-size: .74rem; font-weight: 700; text-transform: uppercase;
      letter-spacing: .12em; color: #f0c987; margin-bottom: .3rem;
    }
    .header-hero-subtitle { color: rgba(255,255,255,.75); font-size: .92rem; }

    /* Contagem regressiva no mobile: badge minimalista ao lado do rótulo
       (no desktop continua o card grande junto com data/hora). */
    .dias-pill-mobile {
      display: inline-flex; align-items: center; flex-shrink: 0;
      font-size: .68rem; font-weight: 700; white-space: nowrap;
      padding: .3rem .7rem; border-radius: 999px;
      background: rgba(34,197,94,.2); color: #dcfce7;
      border: 1px solid rgba(34,197,94,.45);
    }
    .dias-pill-mobile.dias-pill-hoje     { background: rgba(245,158,11,.2); color: #fef3c7; border-color: rgba(245,158,11,.45); }
    .dias-pill-mobile.dias-pill-passado  { background: rgba(148,163,184,.2); color: #e2e8f0; border-color: rgba(148,163,184,.45); }

    .info-tiles { margin-top: 1.1rem; }
    @media (max-width: 767.98px) {
      .info-tiles { flex-wrap: nowrap; }
      .info-tiles .info-tile { flex: 1 1 0; min-width: 0; padding: .5rem .55rem .5rem .5rem; }
      .info-tiles .info-tile-icon { width: 34px; height: 34px; font-size: .95rem; }
      .info-tiles .info-tile-val,
      .info-tiles .info-tile-lbl { overflow: hidden; text-overflow: ellipsis; }
      .info-tiles .info-tile-val { font-size: .9rem; }
    }
    .info-tile {
      display: flex; align-items: center; gap: .65rem;
      background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.18);
      border-radius: 14px; padding: .5rem .9rem .5rem .5rem;
    }
    .info-tile-icon {
      width: 40px; height: 40px; border-radius: 10px; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      background: rgba(240,201,135,.22); color: #f0c987; font-size: 1.05rem;
    }
    .info-tile-val { font-weight: 700; color: #fff; font-size: 1.02rem; line-height: 1.15; white-space: nowrap; }
    .info-tile-lbl { font-size: .66rem; color: rgba(255,255,255,.65); margin-top: .1rem; white-space: nowrap; }

    .info-tile-destaque { background: rgba(220,252,231,.94); border-color: rgba(220,252,231,.94); }
    .info-tile-destaque .info-tile-icon { background: #22c55e; color: #fff; }
    .info-tile-destaque .info-tile-val,
    .info-tile-destaque .info-tile-lbl  { color: #15803d; }

    .info-tile-destaque.hoje { background: rgba(254,243,199,.94); border-color: rgba(254,243,199,.94); }
    .info-tile-destaque.hoje .info-tile-icon { background: #f59e0b; }
    .info-tile-destaque.hoje .info-tile-val,
    .info-tile-destaque.hoje .info-tile-lbl  { color: #b45309; }

    .info-tile-destaque.passado { background: rgba(226,232,240,.9); border-color: rgba(226,232,240,.9); }
    .info-tile-destaque.passado .info-tile-icon { background: #64748b; }
    .info-tile-destaque.passado .info-tile-val,
    .info-tile-destaque.passado .info-tile-lbl  { color: #334155; }

    /* Fundo decorativo: pontinhos + painel de "luzes desfocadas" + corações
       em linha — isolado num div de fundo próprio (overflow:hidden só nele),
       pra não cortar o dropdown do sino. */
    .header-hero-bg {
      position: absolute; inset: 0; overflow: hidden; border-radius: inherit;
      pointer-events: none; z-index: 0;
    }
    .header-topo > *:not(.header-hero-bg) { position: relative; z-index: 1; }
    /* A linha de botões (sino/inspirações) precisa ficar acima do bloco do
       título — senão o dropdown de notificações abre "atrás" do título/
       badges, que tem sua própria pilha de contexto por causa do z-index
       acima. */
    .header-topo > .header-top-actions { z-index: 2 !important; }
    .hero-dots {
      position: absolute; top: 14%; right: 30%; width: 110px; height: 64px;
      background-image: radial-gradient(rgba(240,201,135,.45) 1.6px, transparent 1.6px);
      background-size: 14px 14px;
      display: none;
    }
    .hero-foto-sim {
      position: absolute; inset: 0; left: 52%;
      clip-path: url(#heroWaveClipNoivos);
      background:
        radial-gradient(circle at 28% 28%, rgba(255,224,178,.55), transparent 32%),
        radial-gradient(circle at 72% 52%, rgba(255,255,255,.3), transparent 26%),
        radial-gradient(circle at 55% 82%, rgba(255,196,120,.4), transparent 32%),
        radial-gradient(circle at 88% 22%, rgba(255,255,255,.22), transparent 22%),
        linear-gradient(135deg, rgba(0,0,0,.1), rgba(0,0,0,.28));
      display: none;
    }
    .hero-hearts {
      position: absolute; top: 10%; right: 3%; width: 150px; height: auto; display: none;
    }
    .hero-hearts path {
      stroke-dasharray: 1000;
      stroke-dashoffset: 1000;
      animation: heroHeartsDraw 7s ease-in-out infinite;
    }
    .hero-hearts path:nth-of-type(2) { animation-delay: 1.2s; }
    @keyframes heroHeartsDraw {
      0%   { stroke-dashoffset: 1000; }
      42%  { stroke-dashoffset: 0; }
      65%  { stroke-dashoffset: 0; }
      100% { stroke-dashoffset: -1000; }
    }
    @media (min-width: 768px) {
      .hero-dots, .hero-foto-sim, .hero-hearts { display: block; }
    }

    .info-contato-evento > div { padding-right: 1.5rem; border-right: 1px solid #e5e7eb; }
    .info-contato-evento > div:last-child { padding-right: 0; border-right: 0; }
    .min-width-0 { min-width: 0; }
    .info-contato-fixa { min-width: 0; }
    .info-contato-fixa > div:first-child { padding-right: 1rem; border-right: 1px solid #e5e7eb; margin-right: 1rem; }
    .contato-email-val { font-size: .78rem; }
    /* Linha "contato + Uploads" nunca quebra pro mobile — o bloco de contato
       encolhe/trunca (min-width:0) e o botão Uploads mantém tamanho fixo
       (flex-shrink:0 no próprio botão), então os dois cabem lado a lado. */
    .linha-contato-uploads { min-width: 0; }
    .linha-contato-uploads > .info-contato-evento { min-width: 0; flex-shrink: 1; }
    /* ---- BOTÃO "UPLOADS" (mesma cara das tiles de contato, com borda fina indicando que é clicável) ---- */
    .btn-uploads-tile {
      border: 1px solid #dee2e6; border-radius: 12px; padding: .4rem .8rem .4rem .5rem;
      background: #fff; transition: border-color .15s, box-shadow .15s, transform .15s;
    }
    .btn-uploads-tile:hover { border-color: var(--color-primary); box-shadow: 0 .3rem 0.9rem rgba(15,23,42,.08); transform: translateY(-1px); }
    @media (max-width: 767.98px) {
      .contato-email-val { max-width: 40vw; }
      /* Uploads bem mais compacto no mobile — sobra espaço pro e-mail dos noivos */
      .btn-uploads-tile { padding: .3rem .5rem .3rem .35rem; gap: .4rem !important; }
      .btn-uploads-tile .rounded-circle { width: 26px; height: 26px; padding: 0 !important; display: flex; align-items: center; justify-content: center; }
      .btn-uploads-tile .rounded-circle i { font-size: .75rem; }
      .btn-uploads-tile .fw-bold { font-size: .72rem; }
      .btn-uploads-tile .badge { font-size: .55rem; padding: .25em .45em; }
    }

    /* TOAST */
    #toast-wrap {
      position: fixed; bottom: 1.5rem; right: 1.5rem;
      z-index: 9999; display: flex; flex-direction: column; gap: .4rem;
    }
    .toast-item {
      display: flex; align-items: center; gap: .7rem;
      padding: .7rem 1.1rem; border-radius: 12px; min-width: 230px;
      box-shadow: 0 8px 24px rgba(0,0,0,.15);
      font-size: .86rem; font-weight: 600; color: #fff;
      animation: toastIn .25s ease both;
    }
    .toast-item.verde { background: #16a34a; }
    .toast-item.verm  { background: #dc2626; }
    .toast-item.info  { background: #2563eb; }
    @keyframes toastIn {
      from { opacity: 0; transform: translateX(24px); }
      to   { opacity: 1; transform: translateX(0); }
    }

    /* SWATCHES DE COR DO CONVITE */
    #paleta-cor-convite {
      flex-wrap: nowrap;
      overflow-x: auto;
      overflow-y: hidden;
      padding: 4px 4px 6px;
      margin: -4px -4px 0;
      -webkit-overflow-scrolling: touch;
    }
    #paleta-cor-convite::-webkit-scrollbar { height: 4px; }
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

    /* PROGRESS RING */
    .ring-wrap { position: relative; width: 72px; height: 72px; flex-shrink: 0; }
    .ring-wrap-compact { width: 40px; height: 40px; }
    .ring-wrap svg { transform: rotate(-90deg); }
    .ring-label {
      position: absolute; inset: 0;
      display: flex; flex-direction: column;
      align-items: center; justify-content: center;
      color: #fff; font-size: .78rem; font-weight: 700; line-height: 1.15;
    }

    /* BARRA FINA */
    .barra { height: 5px; background: #e2e8f0; border-radius: 999px; overflow: hidden; }
    .barra-fill { height: 100%; background: var(--verde); border-radius: 999px; transition: width .4s; }

    /* BARRA PAGO */
    .barra-pago-wrap { height: 5px; background: #e2e8f0; border-radius: 999px; overflow: hidden; position: relative; box-shadow: inset 0 1px 2px rgba(0,0,0,.08); }
    .barra-pago-fill { height: 100%; border-radius: 999px; transition: width .5s ease; position: relative; overflow: hidden; }
    #barra-pago-global { background: linear-gradient(90deg, #16a34a, #22c55e); box-shadow: 0 0 6px rgba(34,197,94,.5); }
    .barra-pago-fill::after {
      content: '';
      position: absolute; inset: 0;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,.6), transparent);
      background-size: 60% 100%;
      background-repeat: no-repeat;
      animation: barraPagoShimmer 1.8s ease-in-out infinite !important;
    }
    @keyframes barraPagoShimmer {
      0%   { background-position: -60% 0; }
      100% { background-position: 160% 0; }
    }

    /* ACCORDION ETAPA (cores herdadas de css/estilo.css) */
    .etapa-hdr[aria-expanded="true"] { border-radius: 12px 12px 0 0; }
    .etapa-body { border-radius: 0 0 12px 12px; }

    /* Seta chamando atenção para o usuário clicar e abrir a etapa */
    .chevron-etapa { display: inline-block; animation: chevronBounce 1.6s ease-in-out infinite; }
    .etapa-hdr[aria-expanded="true"] .chevron-etapa { animation: none; transform: rotate(180deg); }
    @keyframes chevronBounce {
      0%, 100% { transform: translateY(0); }
      50%      { transform: translateY(4px); }
    }

    /* Dica sutil chamando atenção para o toque no status "Pendente" */
    .dica-status-conv i.bi-hand-index-thumb-fill { display: inline-block; animation: dicaTap 1.8s ease-in-out infinite; }
    @keyframes dicaTap {
      0%, 100% { transform: translateY(0) rotate(0deg); }
      50%      { transform: translateY(2px) rotate(-8deg); }
    }

    /* TAREFA CARD
       FIX FLICKER: removido o transform: translateX(2px) que causava o loop de
       hover/unhover quando o mouse ficava perto da borda esquerda do card.
       Agora apenas a sombra muda no hover, sem mover o elemento. */
    .tarefa-card {
      border-left: 4px solid transparent; border-radius: 10px;
      border-top: none; border-right: none; border-bottom: none;
      transition: box-shadow .2s;
    }
    .tarefa-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,.09); }
    .tarefa-card.done { border-color: var(--verde); }
    .tarefa-card.pend { border-color: var(--amarel); }

    /* BOTÃO CHECK */
    .btn-chk { font-size: 1.4rem; line-height: 1; transition: transform .2s; will-change: transform; }
    .btn-chk:hover { transform: scale(1.2); }

    /* CONVIDADO ROW */
    .conv-row {
      border-left: 4px solid transparent; border-radius: 10px;
      transition: opacity .3s, transform .3s;
    }
    .conv-row.conf { border-color: var(--verde); }
    .conv-row.pend { border-color: var(--amarel); }

    /* SIDEBAR */
    @media (min-width: 992px) { .sidebar-sticky { position: sticky; top: 20px; } }

    /* BARRA MINI ETAPA */
    .barra-mini-wrap { width: 72px; height: 4px; background: rgba(255,255,255,.2); border-radius: 999px; overflow: hidden; }
    .barra-mini-fill { height: 100%; background: var(--verde); border-radius: 999px; transition: width .4s; }

    /* ---- CARD DE PAGAMENTO DO FORNECEDOR ---- */
    .forn-card {
      background: #fff;
      border-radius: 12px;
      border: 1px solid #e2e8f0;
      padding: .85rem 1rem;
      margin-bottom: .75rem;
      transition: box-shadow .2s;
    }
    .forn-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.07); }
    .forn-card:last-child { margin-bottom: 0; }

    .forn-pago-badge {
      font-size: .6rem;
      font-weight: 700;
      padding: .25em .6em;
      border-radius: 999px;
      text-transform: uppercase;
      letter-spacing: .04em;
    }

    .valor-pago-input {
      font-size: .8rem;
      border: 1.5px solid #e2e8f0;
      border-radius: 8px;
      padding: .3rem .6rem;
      width: 100%;
      transition: border-color .2s;
      background: #f8fafc;
    }
    .valor-pago-input:focus {
      outline: none;
      border-color: #22c55e;
      background: #fff;
    }

    .btn-salvar-pag {
      font-size: .72rem;
      font-weight: 700;
      padding: .3rem .8rem;
      border-radius: 8px;
      border: none;
      background: #22c55e;
      color: #fff;
      cursor: pointer;
      transition: background .2s, transform .1s;
      white-space: nowrap;
    }
    .btn-salvar-pag:hover { background: #16a34a; }
    .btn-salvar-pag:active { transform: scale(.96); }

    .btn-editar-pago-noivos, .btn-cancelar-edit-noivos {
      border: none; background: transparent; color: #94a3b8; padding: 0; font-size: .68rem;
      cursor: pointer; transition: color .15s; line-height: 1;
    }
    .btn-editar-pago-noivos:hover  { color: #2563eb; }
    .btn-cancelar-edit-noivos:hover { color: #dc2626; }
    .btn-salvar-edit-noivos { padding: .3rem .6rem; }

    /* Resumo financeiro global */
    .fin-summary-card {
      border-radius: 12px;
      padding: .9rem 1rem;
      text-align: center;
    }
    .fin-summary-label {
      font-size: .6rem;
      text-transform: uppercase;
      letter-spacing: .07em;
      font-weight: 700;
      opacity: .75;
      margin-bottom: .3rem;
    }
    .fin-summary-val {
      font-size: .95rem;
      font-weight: 800;
      line-height: 1;
    }

    /* ---- TRILHA SONORA ---- */
    .btn-musicas-sidebar {
      background: linear-gradient(135deg, var(--color-primary-light) 0%, #e8d2bd 100%);
      border: 1.5px solid #d9b997;
      border-radius: var(--radius);
      transition: box-shadow .2s, transform .15s;
      will-change: transform;
      display: block;
      width: 100%;
      text-align: left;
    }
    .btn-musicas-sidebar:hover {
      box-shadow: 0 6px 18px rgba(169,116,79,.35);
      transform: translateY(-1px);
    }
    #grid-musicas .musica-card-wrap {
      animation: entraItem .3s ease both;
    }
    @keyframes entraItem {
      from { opacity: 0; transform: scale(.94) translateY(8px); }
      to   { opacity: 1; transform: scale(1)   translateY(0); }
    }

    /* ---- CHECKLIST — REDESIGN ---- */
    .selo-etapa {
      width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: .8rem; color: #fff;
      background: rgba(255,255,255,.15); border: 1.5px solid rgba(255,255,255,.35);
    }
    .selo-etapa.feita { background: var(--verde); border-color: var(--verde); }
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

    /* ---- AJUSTES GERAIS PARA MOBILE (mesmas regras usadas em gerenciar.php) ---- */
    @media (max-width: 767.98px) {

      /* Aviso de "cronograma ainda vazio": vira uma linha compacta e
         horizontal em vez do bloco grande e centralizado do desktop, e fica
         mais colado no título "Nosso Cronograma" acima. */
      .cronograma-header { padding-bottom: .5rem !important; }
      .cronograma-body { padding-top: .5rem !important; }
      .cronograma-vazio {
        padding: 1rem 1.1rem !important;
        display: flex; align-items: center; gap: .75rem; text-align: left;
      }
      .cronograma-vazio-icon { font-size: 1.5rem !important; color: #cbd5e1; flex-shrink: 0; }
      .cronograma-vazio-txt { font-size: .8rem; margin-top: 0 !important; }
      .cronograma-vazio-txt br { display: none; }

      .fin-summary-val { font-size: .78rem; white-space: nowrap; }
      .fin-summary-label { font-size: .55rem; }

      .etapa-hdr { flex-wrap: wrap; row-gap: .35rem; }
      .card-proximas .item-proxima .text-truncate { flex-grow: 1; min-width: 0; }

      .btn-musicas-sidebar .d-flex.justify-content-between {
        flex-wrap: nowrap; padding: .75rem .6rem !important; gap: .5rem;
      }
      .btn-musicas-sidebar .d-flex.align-items-center.gap-3 { min-width: 0; flex: 1 1 auto; }
      .btn-musicas-sidebar .text-start { min-width: 0; }
      .btn-musicas-sidebar h6, .btn-musicas-sidebar small {
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block;
      }
      .btn-musicas-sidebar .btn {
        flex-shrink: 0; white-space: nowrap;
        font-size: .68rem; padding: .3rem .5rem;
      }

      .checklist-toolbar { padding: .65rem .75rem; }
      .checklist-toolbar .sw { flex: 1 1 100%; min-width: 100%; }
      .checklist-toolbar .filtros-check-wrap {
        width: 100%; justify-content: center; flex-wrap: nowrap;
      }
      .checklist-toolbar .filtro-check { font-size: .68rem !important; padding: .35rem .6rem; }

      .anotacoes-etapa-box { padding: .6rem .7rem !important; margin-bottom: .6rem !important; }
      .anotacoes-etapa-box .fw-bold.text-muted { margin-bottom: .4rem !important; }
      .anotacoes-etapa-box .lista-coment-etapa { margin-bottom: .4rem !important; }
      .anotacoes-etapa-box .form-control { padding-top: .3rem; padding-bottom: .3rem; }
      .anotacoes-etapa-box button { padding-top: .3rem; padding-bottom: .3rem; }

      .tarefa-card .card-body { padding: .6rem .7rem !important; }
      .tarefa-card .d-flex.align-items-start.gap-3 { gap: .6rem !important; }
      .tarefa-card h6 { margin-bottom: .3rem !important; }
      .tarefa-card .mb-2 { margin-bottom: .4rem !important; }
      .tarefa-card .border-top { padding-top: .4rem !important; }
      .tarefa-card .lista-coment-tarefa { margin-bottom: .3rem !important; }
      .tarefa-card .form-control { padding-top: .3rem; padding-bottom: .3rem; }
      .tarefa-card form button { padding-top: .3rem; padding-bottom: .3rem; }
    }

    /* ---- MODAL DE UPLOADS (documentos/arquivos do evento) ---- */
    .docs-filtros-scroll { overflow-x: auto; overflow-y: hidden; padding-bottom: .3rem; scrollbar-width: thin; }
    .docs-filtros-scroll .doc-filtro-btn { flex-shrink: 0; white-space: nowrap; font-size: .72rem; padding: .3rem .7rem; }
    .docs-filtros-scroll .doc-filtro-btn .badge { font-size: .6rem; }
    .docs-filtros-scroll::-webkit-scrollbar { height: 4px; }
    .docs-filtros-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    .doc-grupo-header .cnt-grp-doc { font-size: .6rem; }
    .doc-lista-items { --doc-w: 108px; }
    .doc-item {
      width: var(--doc-w); border: 1px solid #e2e8f0; border-radius: 12px;
      padding: .5rem; cursor: pointer; background: #fff;
      transition: box-shadow .15s, transform .15s, border-color .15s;
    }
    .doc-item:hover { box-shadow: 0 4px 14px rgba(169,116,79,.2); transform: translateY(-1px); border-color: var(--color-primary); }
    .doc-item-thumb {
      width: 100%; aspect-ratio: 1 / 1; border-radius: 8px; overflow: hidden;
      background: #f1f5f9; display: flex; align-items: center; justify-content: center;
      margin-bottom: .4rem;
    }
    .doc-item-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .doc-item-thumb i { font-size: 1.8rem; color: #dc2626; }
    .doc-item-nome { font-size: .68rem; font-weight: 600; color: #1e293b; }
    .doc-item-meta { font-size: .62rem; color: #94a3b8; }
    .doc-item-remove {
      position: absolute; top: -6px; right: -6px; width: 20px; height: 20px; border-radius: 50%;
      background: #ef4444; color: #fff; border: 2px solid #fff; display: flex; align-items: center; justify-content: center;
      font-size: .58rem; line-height: 1; cursor: pointer; box-shadow: 0 2px 6px rgba(0,0,0,.25); z-index: 2;
    }
    #docs-preview-conteudo img { max-width: 100%; max-height: 62vh; border-radius: 10px; box-shadow: 0 4px 18px rgba(0,0,0,.15); }
    #docs-preview-conteudo iframe { width: 100%; height: 62vh; border: 0; border-radius: 10px; box-shadow: 0 4px 18px rgba(0,0,0,.15); }
    @media (max-width: 575.98px) {
      .modal-header-uploads { padding: .9rem 1rem .5rem !important; }
      .modal-header-uploads-icone { width: 34px !important; height: 34px !important; }
      .modal-header-uploads .modal-title { font-size: 1rem; }
      .modal-body-uploads { padding: .5rem 1rem 1rem !important; }
      .upload-form-card .card-body { padding: .75rem .9rem !important; }
      .upload-form-titulo { margin-bottom: .6rem !important; font-size: .78rem; }
      .upload-form-campos { margin-bottom: .5rem !important; row-gap: .6rem !important; }
      .upload-form-campos .form-label { font-size: .74rem; margin-bottom: .25rem; }
      .upload-form-campos .form-select,
      .upload-form-campos .form-control { font-size: .82rem; padding: .4rem .6rem; }
      #btn-add-doc { font-size: .78rem; padding: .4rem 1rem; }
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
      <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalConfirmarSaida">
        <i class="bi bi-box-arrow-right"></i> <span class="d-none d-sm-inline">Sair</span>
      </button>
    </div>
  </div>
</nav>

<div id="toast-wrap"></div>

<div class="modal fade" id="modalConfirmarSaida" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content border-0 shadow-lg rounded-4 p-2 text-center">
      <div class="pt-4 pb-2 px-3">
        <div class="mx-auto mb-3 rounded-circle bg-danger bg-opacity-10 d-flex align-items-center justify-content-center" style="width:64px;height:64px;">
          <i class="bi bi-box-arrow-right text-danger fs-3"></i>
        </div>
        <h6 class="fw-bold mb-1">Sair do sistema?</h6>
        <p class="text-muted small mb-0">Você precisará fazer login novamente para acessar o portal.</p>
      </div>
      <div class="d-flex gap-2 p-3 pt-2">
        <button type="button" class="btn btn-light fw-bold flex-fill rounded-pill" data-bs-dismiss="modal">Cancelar</button>
        <a href="logout.php" class="btn btn-danger fw-bold flex-fill rounded-pill">
          <i class="bi bi-box-arrow-right me-1"></i> Sair
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Modal de confirmação de exclusão de convidado -->

<div class="modal fade" id="modalExcluir" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content border-0 shadow-lg rounded-4 p-3 text-center">
      <div class="py-2">
        <div class="mx-auto mb-3 rounded-circle bg-danger bg-opacity-10 d-flex align-items-center justify-content-center" style="width:52px;height:52px;">
          <i class="bi bi-trash3-fill text-danger fs-4"></i>
        </div>
        <h6 class="fw-bold mb-1">Remover convidado?</h6>
        <p class="text-muted small mb-0">Esta ação não pode ser desfeita.</p>
      </div>
      <div class="d-flex justify-content-center gap-2 mt-3">
        <button class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancelar</button>
        <button id="btnConfExcluir" class="btn btn-danger btn-sm px-4 rounded-pill fw-bold">Apagar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Adicionar Convidado -->
<div class="modal fade" id="modalAddConvidado" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header border-0 pb-0">
        <h6 class="modal-title fw-bold"><i class="bi bi-person-plus-fill text-primary me-2"></i>Criar Convite</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="form-convidado">
        <div class="modal-body py-3">
          <div class="mb-3">
            <label class="form-label small fw-semibold text-secondary">Nome do Convidado / Família</label>
            <input type="text" id="conv-nome" class="form-control rounded-3" required>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary">Categoria / Grupo</label>
              <input type="text" id="conv-categoria" class="form-control rounded-3" list="lista-categorias-noivos" placeholder="Ex: Família, Amigos...">
              <datalist id="lista-categorias-noivos">
                <option value="Família"><option value="Amigos"><option value="Outros">
                <?php foreach ($categorias_existentes as $catEx): if (in_array($catEx, ['Família', 'Amigos', 'Outros'])) continue; ?>
                  <option value="<?= htmlspecialchars($catEx, ENT_QUOTES, 'UTF-8') ?>">
                <?php endforeach; ?>
              </datalist>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary">Telefone / WhatsApp</label>
              <input type="text" id="conv-telefone" class="form-control rounded-3" placeholder="(00) 00000-0000" required>
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
          <button type="submit" id="btn-salvar-convidado" class="btn btn-primary btn-sm px-4 rounded-pill fw-semibold">Criar Convite</button>
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
      <form id="form-edit-convidado">
        <input type="hidden" id="econv-id">
        <div class="modal-body py-3">
          <div class="mb-3">
            <label class="form-label small fw-semibold text-secondary">Nome do Convidado / Família</label>
            <input type="text" id="econv-nome" class="form-control rounded-3" required>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary">Categoria / Grupo</label>
              <input type="text" id="econv-categoria" class="form-control rounded-3" list="lista-categorias-noivos" placeholder="Ex: Família, Amigos...">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold text-secondary">Telefone / WhatsApp</label>
              <input type="text" id="econv-telefone" class="form-control rounded-3" placeholder="(00) 00000-0000" required>
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
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-outline-secondary btn-sm px-4 rounded-pill" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" id="btn-salvar-edit-convidado" class="btn btn-primary btn-sm px-4 rounded-pill fw-semibold">Salvar Alterações</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="modalLinkConfirmacao" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header border-0 bg-light">
        <h5 class="modal-title fw-bold"><i class="bi bi-envelope-check-fill text-danger me-2"></i> Confirmação de Presença</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">

        <p class="text-muted small mb-3">Gere um link exclusivo para cada convidado. O link já chega travado no nome e no WhatsApp dele, evitando confirmações feitas por engano em nome de outra pessoa. Se ele já vem acompanhado, cadastre os acompanhantes junto — o link chega com a família inteira pré-preenchida.</p>

        <div class="card border-0 rounded-4 p-3 mb-3" style="background:#f8fafc;border:1.5px solid #e2e8f0 !important;">
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label small fw-bold text-secondary mb-1">Nome do convidado</label>
              <input type="text" id="link-esp-nome" class="form-control form-control-sm" placeholder="Ex: Maria Silva">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-bold text-secondary mb-1">WhatsApp</label>
              <input type="text" id="link-esp-telefone" class="form-control form-control-sm" placeholder="(00) 00000-0000">
            </div>
          </div>

          <div id="lista-acompanhantes-link-esp" class="d-flex flex-column gap-2 mt-2"></div>

          <button type="button" id="btn-add-acompanhante-link-esp" class="btn btn-outline-secondary btn-sm rounded-pill mt-2 align-self-start px-3">
            <i class="bi bi-person-plus-fill me-1"></i> Adicionar acompanhante
          </button>

          <button type="button" id="btn-gerar-link-especifico" class="btn btn-danger btn-sm fw-bold rounded-pill mt-3 align-self-start px-3">
            <i class="bi bi-magic me-1"></i> Gerar Link
          </button>
          <div id="link-esp-erro" class="text-danger small mt-2 d-none"></div>
        </div>

        <div id="lista-links-especificos" class="d-flex flex-column gap-2 mb-3" style="max-height:260px;overflow-y:auto;">
          <?php foreach ($links_especificos as $c): $linkEsp = $link_confirmacao_url . '&token=' . $c['token_convite']; $telDigitsC = preg_replace('/\D+/', '', $c['telefone'] ?? ''); if (strlen($telDigitsC) <= 11) { $telDigitsC = '55' . $telDigitsC; } $acompC = $acompanhantes_por_principal[$c['id']] ?? []; ?>
          <div class="linha-link-especifico border rounded-3 p-2" data-id="<?= (int)$c['id'] ?>">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-1">
              <div class="small fw-bold text-truncate"><?= htmlspecialchars($c['nome'], ENT_QUOTES, 'UTF-8') ?></div>
              <span class="badge <?= $c['resposta_rsvp'] === 'confirmado' ? 'bg-success' : ($c['resposta_rsvp'] === 'recusado' ? 'bg-secondary' : 'bg-warning text-dark') ?>" style="font-size:.65rem;">
                <?= $c['resposta_rsvp'] === 'confirmado' ? 'Confirmado' : ($c['resposta_rsvp'] === 'recusado' ? 'Recusou' : 'Pendente') ?>
              </span>
            </div>
            <?php if (!empty($acompC)): ?>
              <div class="text-muted mb-1" style="font-size:.72rem;">
                <i class="bi bi-people-fill me-1"></i><?= htmlspecialchars(implode(', ', array_column($acompC, 'nome')), ENT_QUOTES, 'UTF-8') ?>
              </div>
            <?php endif; ?>
            <div class="input-group input-group-sm">
              <input type="text" class="form-control campo-link-esp" value="<?= htmlspecialchars($linkEsp, ENT_QUOTES, 'UTF-8') ?>" readonly>
              <button class="btn btn-outline-secondary btn-copiar-link-esp" type="button" title="Copiar"><i class="bi bi-clipboard"></i></button>
              <a class="btn btn-outline-success btn-whatsapp-link-esp" target="_blank" title="Enviar por WhatsApp"
                 href="https://wa.me/<?= htmlspecialchars($telDigitsC, ENT_QUOTES, 'UTF-8') ?>?text=<?= rawurlencode('Oi ' . $c['nome'] . '! Confirme sua presença no casamento de ' . $evento['nome'] . ' por aqui: ' . $linkEsp) ?>">
                <i class="bi bi-whatsapp"></i>
              </a>
              <button class="btn btn-outline-danger btn-remover-link-esp" type="button" title="Remover link"><i class="bi bi-trash"></i></button>
            </div>
          </div>
          <?php endforeach; ?>
          <div class="text-center text-muted small py-3" id="msg-lista-vazia" <?= empty($links_especificos) ? '' : 'style="display:none;"' ?>>
            <i class="bi bi-inbox fs-4 d-block mb-1"></i> Nenhum link específico gerado ainda.
          </div>
        </div>

        <div class="card border-0 rounded-4 p-3" style="background:#fef2f2;border:1.5px solid #fecaca !important;">
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
                   src="<?= !empty($evento['foto_casal']) ? 'uploads/' . htmlspecialchars($evento['foto_casal']) : '' ?>"
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

        <?php $cor_convite_atual = !empty($evento['cor_convite']) ? $evento['cor_convite'] : '#8b5e3c'; ?>
        <div class="card border-0 rounded-4 p-3 mt-3" style="background:#f8fafc;border:1.5px solid #e2e8f0 !important;">
          <div class="d-flex align-items-start gap-2 mb-3">
            <div class="bg-white rounded-3 d-flex align-items-center justify-content-center shadow-sm flex-shrink-0" style="width:38px;height:38px;">
              <i class="bi bi-palette-fill" style="color:<?= htmlspecialchars($cor_convite_atual, ENT_QUOTES, 'UTF-8') ?>;"></i>
            </div>
            <div>
              <div class="fw-bold small text-dark">Cor da página do convite</div>
              <p class="text-muted mb-0" style="font-size:.76rem;line-height:1.4;">Escolha o tom de fundo que os convidados vão ver ao abrir o link.</p>
            </div>
          </div>

          <div class="d-flex gap-2 mb-3" id="paleta-cor-convite">
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
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-secondary btn-sm px-4 rounded-pill fw-bold" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<!-- FIX: Modal de Conversa/Histórico movido para FORA do bloco <script> -->
<div class="modal fade" id="modalConversa" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow-lg rounded-4">
      <div class="modal-header bg-light border-0">
        <h5 class="modal-title fw-bold" id="conversa-titulo">Histórico</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4" id="conversa-corpo" style="max-height: 400px; overflow-y: auto;">
        <!-- Histórico injetado via JS -->
      </div>
    </div>
  </div>
</div>

<div class="container my-4 my-md-5">

  <div class="card border-0 shadow-sm mb-4" style="border-radius: var(--radius); backdrop-filter: none; animation: none; transform: none;">
    <div class="header-topo p-3 p-md-4" style="position:relative;">
      <div class="header-hero-bg" aria-hidden="true">
        <svg width="0" height="0" style="position:absolute;">
          <defs>
            <clipPath id="heroWaveClipNoivos" clipPathUnits="objectBoundingBox">
              <path d="M0.31,0 C0.20,0.12 0.10,0.20 0.15,0.35 C0.20,0.50 0.24,0.55 0.19,0.68 C0.15,0.80 0.09,0.88 0.09,1 L1,1 L1,0 Z" />
            </clipPath>
          </defs>
        </svg>
        <span class="hero-dots"></span>
        <span class="hero-foto-sim"></span>
        <svg class="hero-hearts" viewBox="0 0 200 160">
          <path d="M62,42 C42,20 8,32 8,58 C8,84 42,98 62,120 C82,98 116,84 116,58 C116,32 82,20 62,42 Z" fill="none" stroke="rgba(255,222,160,.95)" stroke-width="3.5"/>
          <path d="M104,74 C90,60 68,68 68,86 C68,104 90,112 104,128 C118,112 140,104 140,86 C140,68 118,60 104,74 Z" fill="none" stroke="rgba(255,222,160,.8)" stroke-width="3.5"/>
        </svg>
      </div>

      <div class="d-flex flex-wrap align-items-center gap-2 mb-3 header-top-actions">
        <a href="inspiracoes.php?id=<?= $evento_id ?>" class="btn btn-sm rounded-pill fw-bold header-btn-exportar btn-chama-atencao">
          <i class="bi bi-stars me-1"></i> Inspirações
        </a>

        <div class="d-flex align-items-center gap-2 header-actions-noivos">
          <?php if ($total_g > 0):
            $r_   = 28;
            $circ = 2 * M_PI * $r_;
            $off  = $circ - ($circ * $pct_g / 100); ?>
          <div class="ring-wrap ring-wrap-compact" title="<?= $pct_g ?>% do cronograma concluído">
            <svg width="40" height="40" viewBox="0 0 72 72">
              <circle cx="36" cy="36" r="<?= $r_ ?>" fill="none" stroke="rgba(255,255,255,.2)" stroke-width="6"/>
              <circle cx="36" cy="36" r="<?= $r_ ?>" fill="none" stroke="#22c55e" stroke-width="6"
                stroke-dasharray="<?= number_format($circ, 2, '.', '') ?>"
                stroke-dashoffset="<?= number_format($off, 2, '.', '') ?>"
                stroke-linecap="round"/>
            </svg>
            <div class="ring-label" style="font-size:.55rem;">
              <span id="ring-pct"><?= $pct_g ?>%</span>
            </div>
          </div>
          <?php endif; ?>

          <div class="dropdown header-btn-sino" id="dropdown-notificacoes">
            <button class="btn btn-sm btn-outline-light rounded-circle position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="width:40px;height:40px;">
              <i class="bi bi-bell-fill"></i>
              <?php if ($nao_lidas > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill" style="font-size:.62rem;">
                  <?= $nao_lidas > 9 ? '9+' : $nao_lidas ?>
                </span>
              <?php endif; ?>
            </button>
            <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 p-0" style="width:340px;max-height:420px;overflow-y:auto;">
              <div class="px-3 py-2 border-bottom bg-light d-flex justify-content-between align-items-center">
                <span class="fw-bold small text-uppercase text-muted"><i class="bi bi-bell me-1"></i> Notificações da Assessoria</span>
                <button type="button" id="btn-marcar-lidas" class="btn btn-link btn-sm p-0 text-decoration-none">Marcar lidas</button>
              </div>
              <div id="lista-notificacoes">
              <?php if (empty($notificacoes)): ?>
                <div class="text-center text-muted p-4 small">
                  <i class="bi bi-inbox fs-3 d-block mb-2"></i> Nenhuma atividade ainda.
                </div>
              <?php else: foreach ($notificacoes as $n): ?>
                <div class="notif-item d-flex align-items-start gap-2 px-3 py-2 border-bottom" style="cursor:pointer;">
                  <i class="bi bi-chat-left-text-fill text-primary mt-1"></i>
                  <div class="flex-fill" style="min-width:0;">
                    <div class="small fw-bold text-dark"><?= htmlspecialchars(!empty($n['etapa_nome']) ? 'Etapa: ' . $n['etapa_nome'] : 'Tarefa: ' . $n['tarefa'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="small text-body"><?= htmlspecialchars($n['comentario'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="text-muted" style="font-size:.7rem;"><?= tempo_relativo($n['data_cadastro']) ?></div>
                  </div>
                </div>
              <?php endforeach; endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="header-hero-accent">
        <div class="d-flex align-items-center justify-content-between gap-2">
          <div class="header-hero-label mb-0">Nosso Casamento</div>
          <?php if ($dias > 0): ?>
            <span class="dias-pill-mobile d-md-none"><i class="bi bi-calendar-check-fill me-1"></i>Faltam <?= $dias ?> dia<?= $dias > 1 ? 's' : '' ?></span>
          <?php elseif ($dias === 0): ?>
            <span class="dias-pill-mobile dias-pill-hoje d-md-none"><i class="bi bi-stars me-1"></i>É hoje!</span>
          <?php else: ?>
            <span class="dias-pill-mobile dias-pill-passado d-md-none">Casados há <?= abs($dias) ?> dia<?= abs($dias) > 1 ? 's' : '' ?></span>
          <?php endif; ?>
        </div>
        <h2 class="mb-1 text-white nome-noivos-titulo">
          <?= htmlspecialchars($evento['nome'], ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <p class="header-hero-subtitle mb-0">Bem-vindos! Acompanhe aqui os preparativos do seu grande dia.</p>

        <div class="d-flex flex-wrap gap-2 info-tiles">
          <div class="info-tile">
            <span class="info-tile-icon"><i class="bi bi-calendar-event"></i></span>
            <div>
              <div class="info-tile-val"><?= date('d/m/Y', strtotime($evento['data_evento'])) ?></div>
              <div class="info-tile-lbl">Data do evento</div>
            </div>
          </div>
          <?php if (!empty($evento['hora_evento'])): ?>
          <div class="info-tile">
            <span class="info-tile-icon"><i class="bi bi-clock"></i></span>
            <div>
              <div class="info-tile-val"><?= date('H:i', strtotime($evento['hora_evento'])) ?></div>
              <div class="info-tile-lbl">Horário do evento</div>
            </div>
          </div>
          <?php endif; ?>
          <?php if ($dias > 0): ?>
          <div class="info-tile info-tile-destaque d-none d-md-flex">
            <span class="info-tile-icon"><i class="bi bi-calendar-check-fill"></i></span>
            <div>
              <div class="info-tile-val">Faltam <?= $dias ?> dia<?= $dias > 1 ? 's' : '' ?>!</div>
              <div class="info-tile-lbl">Contagem regressiva</div>
            </div>
          </div>
          <?php elseif ($dias === 0): ?>
          <div class="info-tile info-tile-destaque hoje d-none d-md-flex">
            <span class="info-tile-icon"><i class="bi bi-stars"></i></span>
            <div>
              <div class="info-tile-val">É hoje!</div>
              <div class="info-tile-lbl">Contagem regressiva</div>
            </div>
          </div>
          <?php else: ?>
          <div class="info-tile info-tile-destaque passado d-none d-md-flex">
            <span class="info-tile-icon"><i class="bi bi-check2-circle"></i></span>
            <div>
              <div class="info-tile-val">Casados há <?= abs($dias) ?> dia<?= abs($dias) > 1 ? 's' : '' ?></div>
              <div class="info-tile-lbl">Contagem regressiva</div>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="bg-white p-3 border-top d-flex flex-nowrap align-items-center gap-2 gap-md-3 linha-contato-uploads" style="border-radius: 0 0 var(--radius) var(--radius);">
      <div class="d-flex flex-wrap row-gap-3 info-contato-evento">
        <div class="d-flex flex-nowrap info-contato-fixa">
          <div class="d-flex align-items-center gap-2 min-width-0">
            <span class="bg-light rounded-circle p-2 d-flex flex-shrink-0"><i class="bi bi-envelope-fill text-primary"></i></span>
            <div class="min-width-0">
              <div class="fw-bold small text-truncate contato-email-val"><?= htmlspecialchars($evento['email'], ENT_QUOTES, 'UTF-8') ?></div>
              <div class="text-muted text-truncate" style="font-size:.68rem;">E-mail do responsável</div>
            </div>
          </div>
          <?php if (!empty($evento['telefone'])): ?>
          <div class="d-flex align-items-center gap-2 flex-shrink-0">
            <span class="bg-light rounded-circle p-2 d-flex flex-shrink-0"><i class="bi bi-whatsapp text-success"></i></span>
            <div>
              <div class="fw-bold small text-nowrap"><?= htmlspecialchars($evento['telefone'], ENT_QUOTES, 'UTF-8') ?></div>
              <div class="text-muted" style="font-size:.68rem;">WhatsApp</div>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <button type="button" class="btn d-flex align-items-center gap-2 flex-shrink-0 btn-uploads-tile"
              data-bs-toggle="modal" data-bs-target="#modalDocumentos"
              title="Uploads do evento (contrato, RG, comprovantes...)">
        <span class="bg-light rounded-circle p-2 d-flex flex-shrink-0"><i class="bi bi-paperclip text-secondary"></i></span>
        <div class="text-start">
          <div class="fw-bold small text-nowrap">
            Uploads
            <?php if ($total_documentos > 0): ?><span class="badge rounded-pill bg-primary ms-1"><?= $total_documentos ?></span><?php endif; ?>
          </div>
          <div class="text-muted d-none d-md-block" style="font-size:.68rem;">Contrato, RG, comprovantes...</div>
        </div>
      </button>
    </div>
  </div>


  <div class="row g-4 align-items-start">

    <div class="col-lg-8">
      <div class="card shadow-sm border-0 mb-4" style="border-radius: var(--radius);">
        <div class="card-header bg-white border-bottom pt-4 pb-3 text-center cronograma-header">
          <h5 class="fw-bold mb-1">
            <i class="bi bi-calendar-check text-primary me-2"></i> Nosso Cronograma
          </h5>
          <?php if ($total_g > 0): ?>
          <div class="text-muted small mt-1">
            <span id="label-conc-g"><?= $conc_g ?></span> de <?= $total_g ?> tarefas concluídas
            <?php if ($total_atrasadas > 0): ?>
              <span class="badge-prazo atrasada ms-1"><i class="bi bi-exclamation-triangle-fill"></i> <?= $total_atrasadas ?> atrasada<?= $total_atrasadas > 1 ? 's' : '' ?></span>
            <?php endif; ?>
            <div class="barra mx-auto mt-2" style="max-width:200px;">
              <div class="barra-fill" id="barra-g" style="width:<?= $pct_g ?>%;"></div>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <div class="card-body p-4 bg-light cronograma-body">
          <?php if (empty($passos)): ?>
            <div class="text-center py-5 text-muted bg-white rounded-3 shadow-sm cronograma-vazio">
              <i class="bi bi-clock-history fs-1 cronograma-vazio-icon"></i>
              <p class="mt-3 mb-0 cronograma-vazio-txt">A assessoria ainda está montando o cronograma.<br>Em breve aparecerá aqui!</p>
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
                  <span class="text-dark text-truncate"><?= htmlspecialchars($pt['tarefa']) ?></span>
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
              <div class="d-flex gap-1 filtros-check-wrap">
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
                    <i class="bi bi-chevron-down text-white small chevron-etapa"></i>
                  </div>
                </div>
                <div id="<?= $cid ?>" class="collapse<?= $auto_abrir ? ' show' : '' ?>">
                  <div class="etapa-body p-3 bg-white">
                    <div class="p-3 mb-3 bg-light rounded-3 border small anotacoes-etapa-box">
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
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="comentario_etapa_noivos" value="1">
                        <input type="hidden" name="etapa_nome" value="<?= htmlspecialchars($etapa) ?>">
                        <input type="text" name="novo_comentario_etapa" class="form-control form-control-sm" placeholder="Nota geral…" required>
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
                         data-tarefa-nome="<?= strtolower(htmlspecialchars($t['tarefa'])) ?>"
                         data-tarefa-status="<?= $done ? 'concluido' : 'pendente' ?>">
                      <div class="card-body p-3">
                        <div class="d-flex align-items-start gap-3">
                          <button type="button"
                                  class="btn p-0 border-0 btn-chk text-<?= $done ? 'success' : 'muted' ?> btn-toggle-tarefa"
                                  data-id="<?= $tid ?>"
                                  data-status="<?= $snum ?>"
                                  data-etapa-hdr-id="hdr-<?= $cid ?>"
                                  data-etapa-total="<?= $totE ?>"
                                  title="<?= $done ? 'Desmarcar tarefa' : 'Marcar como concluída' ?>">
                            <i class="bi <?= $done ? 'bi-check-circle-fill' : 'bi-circle' ?>"></i>
                          </button>
                          <div class="w-100">
                            <div class="mb-2" style="min-width:0;">
                              <h6 class="fw-bold mb-1 <?= $done ? 'text-muted text-decoration-line-through' : 'text-dark' ?>" style="line-height:1.4;">
                                <?= htmlspecialchars($t['tarefa']) ?>
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
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="adicionar_comentario_noivos" value="1">
                                <input type="hidden" name="check_id" value="<?= $tid ?>">
                                <input type="text" name="novo_comentario" class="form-control form-control-sm bg-light border-0" placeholder="Comentar…" required>
                                <button type="submit" class="btn btn-sm btn-outline-danger px-3" title="Enviar">
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
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="sidebar-sticky d-flex flex-column gap-4">

        <button type="button"
                class="btn-musicas-sidebar mt-0 mb-0"
                data-bs-toggle="modal"
                data-bs-target="#modalMusicas">
          <div class="d-flex justify-content-between align-items-center p-3">
            <div class="d-flex align-items-center gap-3">
              <div class="bg-white rounded-3 d-flex align-items-center justify-content-center shadow-sm flex-shrink-0"
                   style="width:44px;height:44px;">
                <i class="bi bi-music-note-list fs-4" style="color:var(--color-primary-dark);"></i>
              </div>
              <div class="text-start">
                <h6 class="mb-0 fw-bold text-dark">Nossa Trilha Sonora</h6>
                <small class="text-dark" style="font-size:.78rem;opacity:.6;">
                  <span id="musicas-count-badge"><?= $total_musicas ?> música<?= $total_musicas !== 1 ? 's' : '' ?></span>
                  · sugestões
                </small>
              </div>
            </div>
            <span class="btn btn-primary btn-sm fw-bold rounded-pill px-3 shadow-sm" style="pointer-events:none; background:var(--color-primary-dark); border:none;">
              Abrir <i class="bi bi-arrow-right ms-1"></i>
            </span>
          </div>
        </button>

        <a href="convidados.php" class="btn-musicas-sidebar text-decoration-none" style="background: linear-gradient(135deg, #cffafe 0%, #a5f3fc 100%); border-color: #67e8f9;">
          <div class="d-flex justify-content-between align-items-center p-3">
            <div class="d-flex align-items-center gap-3">
              <div class="bg-white rounded-3 d-flex align-items-center justify-content-center shadow-sm flex-shrink-0"
                   style="width:44px;height:44px;">
                <i class="bi bi-people-fill fs-4" style="color:#0891b2;"></i>
              </div>
              <div class="text-start">
                <h6 class="mb-0 fw-bold text-dark">Gerenciar Convidados</h6>
                <small class="text-dark text-nowrap" style="font-size:.78rem;opacity:.6;">Adicione, edite e envie pelo WhatsApp</small>
              </div>
            </div>
            <span class="btn btn-sm fw-bold rounded-pill px-3 shadow-sm" style="pointer-events:none; background:#0891b2; border:none; color:#fff;">
              Abrir <i class="bi bi-arrow-right ms-1"></i>
            </span>
          </div>
        </a>

        <a href="organizar_mesas.php" class="btn-musicas-sidebar text-decoration-none" style="background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%); border-color: #86efac;">
          <div class="d-flex justify-content-between align-items-center p-3">
            <div class="d-flex align-items-center gap-3">
              <div class="bg-white rounded-3 d-flex align-items-center justify-content-center shadow-sm flex-shrink-0"
                   style="width:44px;height:44px;">
                <i class="bi bi-grid-3x3-gap-fill fs-4" style="color:#16a34a;"></i>
              </div>
              <div class="text-start">
                <h6 class="mb-0 fw-bold text-dark">Organizar Mesas</h6>
                <small class="text-dark" style="font-size:.78rem;opacity:.6;">Arraste os convidados para as mesas</small>
              </div>
            </div>
            <span class="btn btn-sm fw-bold rounded-pill px-3 shadow-sm" style="pointer-events:none; background:#16a34a; border:none; color:#fff;">
              Abrir <i class="bi bi-arrow-right ms-1"></i>
            </span>
          </div>
        </a>

        <a href="fornecedores_evento.php" class="btn-musicas-sidebar text-decoration-none" style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-color: #fcd34d;">
          <div class="d-flex justify-content-between align-items-center p-3">
            <div class="d-flex align-items-center gap-3">
              <div class="bg-white rounded-3 d-flex align-items-center justify-content-center shadow-sm flex-shrink-0"
                   style="width:44px;height:44px;">
                <i class="bi bi-briefcase-fill fs-4" style="color:#b45309;"></i>
              </div>
              <div class="text-start">
                <h6 class="mb-0 fw-bold text-dark">Fornecedores &amp; Orçamentos</h6>
                <small class="text-dark" style="font-size:.78rem;opacity:.6;">Contratar profissionais e ver valores</small>
              </div>
            </div>
            <span class="btn btn-sm fw-bold rounded-pill px-3 shadow-sm" style="pointer-events:none; background:#b45309; border:none; color:#fff;">
              Abrir <i class="bi bi-arrow-right ms-1"></i>
            </span>
          </div>
        </a>

        <div class="card shadow-sm border-0" style="border-radius: var(--radius);">
          <div class="card-header bg-white border-0 pt-4 pb-0 text-center">
            <h5 class="fw-bold mb-0"><i class="bi bi-wallet2 text-success me-2"></i> Financeiro & Equipe</h5>
          </div>
          <div class="card-body px-3 pb-4">

            <div class="row g-2 mt-2 mb-3">
              <div class="col-4">
                <div class="fin-summary-card bg-primary bg-opacity-10 border border-primary border-opacity-20">
                  <div class="fin-summary-label text-primary">Total</div>
                  <div class="fin-summary-val text-primary">R$ <?= number_format($valor_cont, 2, ',', '.') ?></div>
                </div>
              </div>
              <div class="col-4">
                <div class="fin-summary-card bg-success bg-opacity-10 border border-success border-opacity-20">
                  <div class="fin-summary-label text-success">Pago</div>
                  <div class="fin-summary-val text-success" id="total-pago-geral">R$ <?= number_format($valor_pago_total, 2, ',', '.') ?></div>
                </div>
              </div>
              <div class="col-4">
                <div class="fin-summary-card bg-danger bg-opacity-10 border border-danger border-opacity-20">
                  <div class="fin-summary-label text-danger">A Pagar</div>
                  <div class="fin-summary-val text-danger" id="total-rest-geral">R$ <?= number_format($valor_restante_total, 2, ',', '.') ?></div>
                </div>
              </div>
            </div>

            <div class="mb-1 d-flex justify-content-between align-items-center" style="font-size:.68rem;">
              <span class="text-muted fw-bold" style="text-transform:uppercase;letter-spacing:.05em;">Progresso de Pagamentos</span>
              <span class="fw-bold text-success" id="pct-pago-label"><?= $pct_pago ?>%</span>
            </div>
            <div class="barra-pago-wrap mb-3">
              <div class="barra-pago-fill" id="barra-pago-global" style="width:<?= $pct_pago ?>%;"></div>
            </div>

            <?php if ($valor_neg > 0): ?>
            <div class="d-flex align-items-center justify-content-between bg-warning bg-opacity-10 border border-warning border-opacity-25 rounded-3 px-3 py-2 mb-3">
              <div>
                <div class="fw-bold" style="font-size:.72rem;text-transform:uppercase;color:#92400e;">Em Negociação</div>
                <div class="fw-bold" style="color:#d97706;font-size:.9rem;">R$ <?= number_format($valor_neg, 2, ',', '.') ?></div>
              </div>
              <i class="bi bi-hourglass-split text-warning fs-4 opacity-50"></i>
            </div>
            <?php endif; ?>

            <div class="fw-bold text-muted text-center mb-2" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;">
              <i class="bi bi-people-fill me-1"></i> Profissionais Contratados
            </div>

            <div style="max-height:420px;overflow-y:auto;" id="lista-fornecedores">
              <?php if (empty($lista_cont)): ?>
                <p class="text-center text-muted small py-3 mb-0">Nenhum profissional contratado ainda.</p>
              <?php else: ?>
                <?php foreach ($lista_cont as $f):
                  $fid        = (int)$f['id'];
                  $fValor     = (float)$f['valor'];
                  $fPago      = (float)($f['valor_pago'] ?? 0);
                  $fRest      = $fValor - $fPago;
                  $fPct       = $fValor > 0 ? round($fPago / $fValor * 100) : 0;
                  $fQuitado   = $fRest <= 0;
                  $barColor   = $fQuitado ? 'bg-success' : ($fPct >= 50 ? 'bg-info' : 'bg-warning');
                ?>
                <div class="forn-card" id="forn-<?= $fid ?>" data-pago="<?= $fPago ?>">
                  <div class="d-flex justify-content-between align-items-start mb-1">
                    <div class="fw-bold text-dark" style="font-size:.83rem;line-height:1.3;">
                      <?= htmlspecialchars($f['servico']) ?>
                    </div>
                    <span class="forn-pago-badge ms-2 flex-shrink-0 <?= $fQuitado ? 'bg-success text-white' : 'bg-warning text-dark' ?>">
                      <?= $fQuitado ? '✓ Quitado' : ($fPct > 0 ? $fPct.'% pago' : 'Não iniciado') ?>
                    </span>
                  </div>

                  <?php if (!empty($f['nome'])): ?>
                  <div class="text-muted mb-2" style="font-size:.72rem;">
                    <i class="bi bi-person me-1"></i><?= htmlspecialchars($f['nome']) ?>
                  </div>
                  <?php endif; ?>

                  <div class="d-flex justify-content-between mb-2" style="font-size:.72rem;">
                    <div>
                      <span class="text-muted">Contrato: </span>
                      <span class="fw-bold text-dark">R$ <?= number_format($fValor, 2, ',', '.') ?></span>
                    </div>
                    <div class="text-end">
                      <span class="text-muted">Restante: </span>
                      <span class="fw-bold <?= $fQuitado ? 'text-success' : 'text-danger' ?> forn-rest-val">
                        R$ <?= number_format($fRest < 0 ? 0 : $fRest, 2, ',', '.') ?>
                      </span>
                    </div>
                  </div>

                  <div class="mb-2 d-flex align-items-center gap-1" style="font-size:.72rem;">
                    <span class="text-muted">Pago:</span>
                    <span class="fw-bold text-primary forn-pago-valor-txt">R$ <?= number_format($fPago, 2, ',', '.') ?></span>
                    <button type="button" class="btn-editar-pago-noivos" data-id="<?= $fid ?>" title="Corrigir valor pago">
                      <i class="bi bi-pencil-fill"></i>
                    </button>
                  </div>

                  <div class="barra-pago-wrap mb-2">
                    <div class="barra-pago-fill <?= $barColor ?> forn-barra-fill" style="width:<?= $fPct ?>%;"></div>
                  </div>

                  <div class="d-flex align-items-center gap-2 mt-2 forn-add-wrap-noivos">
                    <div class="flex-grow-1">
                      <label style="font-size:.62rem;color:#64748b;text-transform:uppercase;font-weight:700;letter-spacing:.05em;">
                        Adicionar pagamento (R$)
                      </label>
                      <input
                        type="text"
                        class="valor-pago-input forn-input-add-noivos"
                        data-id="<?= $fid ?>"
                        data-total="<?= $fValor ?>"
                        placeholder="0,00"
                        inputmode="decimal"
                      >
                    </div>
                    <div class="mt-3">
                      <button type="button"
                              class="btn-salvar-pag btn-add-pagamento-noivos"
                              data-id="<?= $fid ?>">
                        <i class="bi bi-plus-lg me-1"></i>Somar
                      </button>
                    </div>
                  </div>

                  <div class="d-flex align-items-center gap-2 mt-2 forn-edit-wrap-noivos" style="display:none;">
                    <div class="flex-grow-1">
                      <label style="font-size:.62rem;color:#64748b;text-transform:uppercase;font-weight:700;letter-spacing:.05em;">
                        Corrigir valor pago (R$)
                      </label>
                      <input
                        type="text"
                        class="valor-pago-input forn-input-edit-noivos"
                        data-id="<?= $fid ?>"
                        data-total="<?= $fValor ?>"
                        value="<?= number_format($fPago, 2, ',', '.') ?>"
                        placeholder="0,00"
                        inputmode="decimal"
                      >
                    </div>
                    <div class="mt-3 d-flex gap-1">
                      <button type="button" class="btn-salvar-pag btn-salvar-edit-noivos" data-id="<?= $fid ?>" title="Salvar correção">
                        <i class="bi bi-check-lg"></i>
                      </button>
                      <button type="button" class="btn-cancelar-edit-noivos" title="Cancelar">
                        <i class="bi bi-x-lg"></i>
                      </button>
                    </div>
                  </div>

                </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>

          </div>
        </div>

        <div class="card shadow-sm border-0" style="border-radius: var(--radius);">
          <div class="card-header bg-white border-0 pt-4 pb-0 text-center">
            <h5 class="fw-bold mb-0"><i class="bi bi-people-fill text-primary me-2"></i> Convidados</h5>
          </div>
          <div class="card-body px-3 pb-4">
            <div class="row g-2 mt-2 mb-3">
              <div class="col-6">
                <div class="bg-success rounded-3 p-3 text-white d-flex justify-content-between align-items-center shadow-sm">
                  <div>
                    <h4 class="mb-0 fw-bold" id="cnt-conf"><?= $total_conf ?></h4>
                    <small class="opacity-75" style="font-size:.7rem;">Confirmados</small>
                  </div>
                  <i class="bi bi-check-circle fs-3 opacity-50"></i>
                </div>
              </div>
              <div class="col-6">
                <div class="bg-warning rounded-3 p-3 text-dark d-flex justify-content-between align-items-center shadow-sm">
                  <div>
                    <h4 class="mb-0 fw-bold" id="cnt-pend"><?= $total_pend ?></h4>
                    <small class="opacity-75" style="font-size:.7rem;">Pendentes</small>
                  </div>
                  <i class="bi bi-hourglass-split fs-3 opacity-50"></i>
                </div>
              </div>
            </div>

            <button class="btn btn-primary btn-sm w-100 fw-bold rounded-pill shadow-sm mb-2"
                    type="button" data-bs-toggle="modal" data-bs-target="#modalAddConvidado">
              <i class="bi bi-person-plus-fill me-1"></i> Criar Convite
            </button>

            <span id="cnt-total" class="d-none"><?= count($lista_convidados) ?></span>

            <div class="collapse mt-2" id="colapso-convidados">
              <div class="d-flex align-items-center gap-1 justify-content-center text-muted mb-2 dica-status-conv" style="font-size:.68rem;">
                <i class="bi bi-hand-index-thumb-fill text-warning"></i>
                <span>Toque em
                  <span class="badge bg-warning text-dark rounded-pill" style="font-size:.6rem;"><i class="bi bi-hourglass-split me-1"></i>Pendente</span>
                  para confirmar a presença
                </span>
              </div>
              <input type="search"
                     id="busca-conv"
                     class="form-control form-control-sm rounded-pill mb-2"
                     placeholder="🔍 Filtrar convidados…">
              <div id="lista-convidados" style="max-height:360px;overflow-y:auto;">
                <?php if (empty($lista_convidados)): ?>
                  <p class="text-center text-muted small py-4 mb-0">Nenhum convidado adicionado.</p>
                <?php else: ?>
                  <?php $grp_icons = ['Família' => 'bi-house-heart-fill', 'Amigos' => 'bi-emoji-sunglasses-fill', 'Outros' => 'bi-collection-fill'];
                  foreach ($ordem_grupos as $grp):
                    if (empty($conv_grupos[$grp])) continue; ?>
                  <div class="grupo-sec" data-grupo="<?= htmlspecialchars($grp) ?>">
                    <div class="badge bg-secondary text-white w-100 text-start px-3 py-2 rounded-2 mb-1 mt-2" style="font-size:.72rem;">
                      <i class="bi <?= $grp_icons[$grp] ?? 'bi-tag-fill' ?> me-1"></i>
                      <?= htmlspecialchars($grp) ?> (<span class="cnt-grp"><?= count($conv_grupos[$grp]) ?></span>)
                    </div>
                    <?php foreach ($conv_grupos[$grp] as $con):
                      $cConf   = (bool)$con['confirmado'];
                      $recusou = (!$cConf && ($con['resposta_rsvp'] ?? '') === 'recusado');
                      $acompCon = $acompanhantes_por_principal[$con['id']] ?? []; ?>
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
                            <span class="badge <?= $cConf ? 'bg-success' : ($recusou ? 'bg-danger' : 'bg-warning text-dark') ?> rounded-pill" style="font-size:.6rem;">
                              <?= $cConf
                                ? '<i class="bi bi-check-circle-fill me-1"></i> Confirmado'
                                : ($recusou
                                    ? '<i class="bi bi-x-circle-fill me-1"></i> Recusou'
                                    : '<i class="bi bi-hourglass-split me-1"></i> Pendente') ?>
                            </span>
                          </button>
                          <button type="button" class="btn p-0 border-0 bg-transparent text-primary btn-edit-conv"
                                  data-id="<?= $con['id'] ?>"
                                  data-nome="<?= htmlspecialchars($con['nome'], ENT_QUOTES, 'UTF-8') ?>"
                                  data-categoria="<?= htmlspecialchars($con['categoria'], ENT_QUOTES, 'UTF-8') ?>"
                                  data-telefone="<?= htmlspecialchars($con['telefone'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                  data-acompanhantes-json="<?= htmlspecialchars(json_encode(array_map(fn($a) => ['id' => $a['id'], 'nome' => $a['nome'], 'faixa' => $a['faixa_etaria']], $acompCon)), ENT_QUOTES, 'UTF-8') ?>"
                                  title="Editar">
                            <i class="bi bi-pencil fs-6"></i>
                          </button>
                          <button type="button" class="btn p-0 border-0 bg-transparent text-danger btn-excluir-conv" data-id="<?= $con['id'] ?>" title="Remover">
                            <i class="bi bi-trash fs-6"></i>
                          </button>
                        </div>
                      </div>
                      <?php if (!empty($con['telefone'])): ?>
                      <div class="text-muted border-top pt-1 mt-1" style="font-size:.67rem;line-height:1.5;">
                        <div><i class="bi bi-whatsapp me-1 text-success"></i><?= htmlspecialchars($con['telefone']) ?></div>
                      </div>
                      <?php endif; ?>
                      <?php if (!empty($acompCon)): ?>
                      <div class="d-flex flex-wrap align-items-center gap-1 mt-1">
                        <?php foreach ($acompCon as $a):
                            $statusA = $a['resposta_rsvp'] === 'recusado' ? 'recusado' : ($a['confirmado'] ? 'confirmado' : 'pendente');
                            $rotuloA = str_starts_with($a['faixa_etaria'] ?? '', 'Criança de Colo') ? 'colo'
                                     : (str_starts_with($a['faixa_etaria'] ?? '', 'Criança') ? 'criança' : 'adulto');
                            $corA = $statusA === 'confirmado' ? 'bg-success-subtle text-success' : ($statusA === 'recusado' ? 'bg-secondary-subtle text-secondary' : 'bg-warning-subtle text-warning-emphasis');
                            $iconeA = $statusA === 'confirmado' ? 'bi-check-circle-fill' : ($statusA === 'recusado' ? 'bi-x-circle-fill' : 'bi-hourglass-split');
                        ?>
                        <span class="badge rounded-pill <?= $corA ?> border" style="font-size:.62rem;font-weight:500;">
                          <i class="bi <?= $iconeA ?> me-1"></i><?= htmlspecialchars($a['nome'], ENT_QUOTES, 'UTF-8') ?> <span class="opacity-75">(<?= $rotuloA ?>)</span>
                        </span>
                        <?php endforeach; ?>
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

<!-- Modais de descrição de tarefas -->
<?php foreach ($lista_checklist as $t): ?>
  <?php if (!empty($t['descricao'])): ?>
  <div class="modal fade" id="modalDesc_<?= $t['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow-lg rounded-4">
        <div class="modal-header bg-light border-0">
          <h5 class="modal-title fw-bold"><i class="bi bi-card-text text-primary me-2"></i> Detalhes da Tarefa</h5>
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
<?php endforeach; ?>

<!-- Modal de Músicas -->
<div class="modal fade" id="modalMusicas" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg rounded-4" style="background:#f8fafc;">

      <div class="modal-header border-0 px-4 pt-4 pb-2" style="background:transparent;">
        <div class="d-flex align-items-center gap-3">
          <div class="rounded-3 d-flex align-items-center justify-content-center shadow-sm"
               style="width:42px;height:42px;background:var(--color-primary-light);border:1.5px solid #d9b997;">
            <i class="bi bi-music-note-beamed text-primary fs-5"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold mb-0 text-dark">Nossa Trilha Sonora</h5>
            <span class="text-muted" style="font-size:.73rem;">Sugira as músicas para cada momento especial</span>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body px-4 pb-4 pt-2">
        <div class="card border-0 shadow-sm rounded-4 mb-4" style="border: 1.5px solid #c7d2fe !important; background:#fff;">
          <div class="card-body p-3 p-sm-4">
            <div class="d-flex align-items-center gap-2 mb-3">
              <i class="bi bi-plus-circle-fill text-primary fs-6"></i>
              <span class="fw-bold text-dark small text-uppercase" style="letter-spacing:.06em;">Adicionar Sugestão</span>
            </div>

            <form id="form-musica">
              <div class="row g-2 mb-2">
                <div class="col-md-5">
                  <input type="text" id="musica-momento" class="form-control form-control-sm bg-light" placeholder="Momento (Ex: Entrada da Noiva)" list="lista-momentos" required>
                  <datalist id="lista-momentos">
                    <option value="Entrada do Noivo">
                    <option value="Entrada dos Padrinhos">
                    <option value="Entrada da Noiva">
                    <option value="Entrada das Alianças">
                    <option value="Assinaturas">
                    <option value="Saída dos Noivos">
                    <option value="Primeira Dança">
                    <option value="Corte do Bolo">
                  </datalist>
                </div>
                <div class="col-md-7">
                  <input type="text" id="musica-titulo" class="form-control form-control-sm bg-light" placeholder="Nome da Música e Artista (Ex: A Thousand Years)" required>
                </div>
              </div>
              <div class="mb-3">
                <input type="url" id="musica-link" class="form-control form-control-sm bg-light" placeholder="Link para ouvir (YouTube, Spotify...)">
              </div>
              <div class="text-end">
                <button type="submit" class="btn btn-sm btn-primary fw-bold rounded-pill px-4 shadow-sm" id="btn-salvar-musica">
                  <i class="bi bi-plus-lg me-1"></i> Sugerir Música
                </button>
              </div>
            </form>
          </div>
        </div>

        <div id="lista-musicas-wrap">
          <?php if (empty($lista_musicas)): ?>
            <div class="text-center py-5 text-muted" id="musicas-vazia">
              <i class="bi bi-music-note-list fs-1 d-block mb-2" style="opacity:.25;"></i>
              <small>Nenhuma música sugerida ainda.</small>
            </div>
          <?php else: ?>
            <div class="row g-3" id="grid-musicas">
              <?php foreach ($lista_musicas as $m):
                $mOk = $m['status'] === 'confirmada';
              ?>
                <div class="col-12 musica-card-wrap" data-id="<?= $m['id'] ?>">
                  <div class="card border-0 shadow-sm rounded-3 <?= $mOk ? 'border-success border bg-success bg-opacity-10' : 'bg-white' ?>">
                    <div class="card-body p-3 d-flex align-items-center justify-content-between gap-3">
                      <div class="d-flex align-items-center gap-3 w-100">
                         <div class="flex-grow-1">
                           <div class="d-flex align-items-center gap-2 mb-1">
                             <div class="fw-bold text-uppercase text-muted" style="font-size:.65rem; letter-spacing:.05em;"><?= htmlspecialchars($m['momento']) ?></div>
                             <?php if($mOk): ?>
                               <span class="badge bg-success" style="font-size:.55rem;"><i class="bi bi-check-circle-fill me-1"></i>Aprovada</span>
                             <?php else: ?>
                               <span class="badge bg-secondary opacity-75" style="font-size:.55rem;"><i class="bi bi-hourglass-split me-1"></i>Pendente</span>
                             <?php endif; ?>
                           </div>
                           <h6 class="mb-0 fw-bold <?= $mOk ? 'text-success' : 'text-dark' ?>" style="font-size:.9rem;"><?= htmlspecialchars($m['titulo']) ?></h6>
                           <?php if (!empty($m['link']) && preg_match('#^https?://#i', $m['link'])): ?>
                             <a href="<?= htmlspecialchars($m['link']) ?>" target="_blank" rel="noopener noreferrer" class="small text-decoration-none mt-1 d-inline-block">
                               <i class="bi bi-link-45deg"></i> Ouvir Referência
                             </a>
                           <?php endif; ?>
                         </div>
                      </div>
                      <button type="button" class="btn p-1 border-0 text-danger btn-excluir-musica flex-shrink-0" data-id="<?= $m['id'] ?>" title="Remover música">
                        <i class="bi bi-trash-fill"></i>
                      </button>
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

<!-- Modal de Uploads -->
<div class="modal fade" id="modalDocumentos" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg rounded-4">

      <div class="modal-header border-0 px-4 pt-4 pb-2 modal-header-uploads">
        <div class="d-flex align-items-center gap-3">
          <div class="rounded-3 d-flex align-items-center justify-content-center shadow-sm modal-header-uploads-icone"
               style="width:42px;height:42px;background:var(--color-primary-light);border:1.5px solid var(--color-primary);">
            <i class="bi bi-paperclip fs-5" style="color:var(--color-primary-dark);"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold mb-0 text-dark">Uploads do Evento</h5>
            <span class="text-muted d-none d-sm-inline" style="font-size:.73rem;">Contrato, documentos e comprovantes do casamento de vocês</span>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body px-4 pb-4 pt-2 modal-body-uploads">

        <!-- VIEW: lista + formulário de envio -->
        <div id="docs-view-lista">
          <div class="card border-0 shadow-sm rounded-4 mb-4 upload-form-card" style="border:1.5px solid var(--color-primary) !important;">
            <div class="card-body p-3 p-sm-4">
              <div class="d-flex align-items-center gap-2 mb-3 upload-form-titulo">
                <i class="bi bi-cloud-upload-fill" style="color:var(--color-primary-dark);"></i>
                <span class="fw-bold text-dark small text-uppercase" style="letter-spacing:.06em;">Enviar Arquivo</span>
              </div>
              <div class="row g-3 mb-3 upload-form-campos">
                <div class="col-12 col-sm-6">
                  <label class="form-label small fw-bold text-secondary">Categoria</label>
                  <select id="doc-categoria" class="form-select">
                    <?php foreach ($categorias_documento as $cat): ?>
                    <option value="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                    <option value="__outro__">✏️ Outra categoria (digitar)</option>
                  </select>
                  <input type="text" id="doc-categoria-outra" class="form-control mt-2 d-none" placeholder="Ex: Buffet" maxlength="50">
                </div>
                <div class="col-12 col-sm-6">
                  <label class="form-label small fw-bold text-secondary">Arquivo <span class="text-muted fw-normal">(imagem ou PDF)</span></label>
                  <input type="file" id="doc-arquivo" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif,application/pdf">
                </div>
              </div>
              <div class="text-end">
                <button type="button" id="btn-add-doc" class="btn btn-sm btn-primary fw-bold rounded-pill px-4 shadow-sm">
                  <i class="bi bi-upload me-1"></i> Enviar
                </button>
              </div>
            </div>
          </div>

          <div class="d-flex align-items-center gap-2 mb-3">
            <i class="bi bi-funnel-fill text-muted flex-shrink-0" style="font-size:.8rem;" title="Filtrar por categoria"></i>
            <div class="d-flex flex-nowrap gap-2 docs-filtros-scroll" id="docs-filtros">
              <button type="button" class="btn btn-sm rounded-pill fw-bold doc-filtro-btn active" data-cat="Todos" style="background:var(--color-primary-dark);color:#fff;border:1px solid var(--color-primary-dark);">
                Todos <span class="badge rounded-pill ms-1" style="background:rgba(255,255,255,.3);color:#fff;"><?= $total_documentos ?></span>
              </button>
              <?php foreach ($categorias_documento as $cat): $qtdCat = count($documentos_por_categoria[$cat] ?? []); ?>
              <button type="button" class="btn btn-sm rounded-pill doc-filtro-btn" data-cat="<?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?>" style="background:var(--color-primary-light);color:var(--color-primary-dark);border:1px solid var(--color-primary);">
                <?= htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') ?> <span class="badge rounded-pill ms-1" style="background:rgba(169,116,79,.18);color:var(--color-primary-dark);"><?= $qtdCat ?></span>
              </button>
              <?php endforeach; ?>
            </div>
          </div>

          <div id="docs-lista-grupos">
            <?php if (empty($documentos_por_categoria)): ?>
            <div class="text-center py-5 text-muted" id="docs-vazia">
              <i class="bi bi-folder2-open fs-1 d-block mb-2" style="opacity:.2;"></i>
              <small>Nenhum arquivo enviado ainda.</small>
            </div>
            <?php else: foreach ($documentos_por_categoria as $categoria => $docs): ?>
            <div class="doc-grupo mb-4" data-categoria="<?= htmlspecialchars($categoria, ENT_QUOTES, 'UTF-8') ?>">
              <div class="doc-grupo-header d-flex align-items-center gap-2 px-2 py-2 rounded-3 mb-2" style="background:var(--color-primary-light);">
                <i class="bi bi-folder-fill" style="color:var(--color-primary-dark);"></i>
                <span class="fw-bold small" style="color:var(--color-primary-dark);"><?= htmlspecialchars($categoria, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="badge rounded-pill cnt-grp-doc" style="background:var(--color-primary-dark);"><?= count($docs) ?></span>
              </div>
              <div class="doc-lista-items d-flex flex-wrap gap-2">
                <?php foreach ($docs as $d):
                  $doc_ehImagem = in_array(strtolower($d['extensao']), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
                ?>
                <div class="doc-item position-relative" data-id="<?= $d['id'] ?>"
                     data-arquivo="<?= htmlspecialchars($d['nome_arquivo'], ENT_QUOTES, 'UTF-8') ?>"
                     data-nome="<?= htmlspecialchars($d['nome_original'], ENT_QUOTES, 'UTF-8') ?>"
                     data-imagem="<?= $doc_ehImagem ? '1' : '0' ?>"
                     title="<?= htmlspecialchars($d['nome_original'], ENT_QUOTES, 'UTF-8') ?>">
                  <span class="doc-item-remove" title="Excluir"><i class="bi bi-x-lg"></i></span>
                  <div class="doc-item-thumb">
                    <?php if ($doc_ehImagem): ?>
                      <img src="./uploads/<?= htmlspecialchars($d['nome_arquivo'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                    <?php else: ?>
                      <i class="bi bi-file-earmark-pdf-fill"></i>
                    <?php endif; ?>
                  </div>
                  <div class="doc-item-nome text-truncate"><?= htmlspecialchars($d['nome_original'], ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="doc-item-meta"><?= tamanho_arquivo_fmt((int)$d['tamanho']) ?></div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endforeach; endif; ?>
          </div>
        </div>

        <!-- VIEW: prévia do arquivo (mesma modal, sem trocar de página) -->
        <div id="docs-view-preview" class="d-none">
          <div class="d-flex align-items-center justify-content-between mb-3">
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" id="btn-doc-voltar">
              <i class="bi bi-arrow-left me-1"></i> Voltar
            </button>
            <a href="#" target="_blank" rel="noopener noreferrer" id="doc-preview-abrir" class="btn btn-sm rounded-pill" style="color:var(--color-primary-dark);border:1px solid var(--color-primary);">
              <i class="bi bi-box-arrow-up-right me-1"></i> Abrir em nova aba
            </a>
          </div>
          <div class="text-center" id="docs-preview-conteudo"></div>
          <div class="mt-3 text-muted small text-center" id="docs-preview-nome"></div>
        </div>

      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ============================================================
   HELPERS
   ============================================================ */
const SELF = window.location.href;

/* ============================================================
   MODAL DE CONFIRMAÇÃO DE PRESENÇA — link específico
   ============================================================ */

function escapeHtmlLinkEsp(str) {
  const d = document.createElement('div');
  d.textContent = str || '';
  return d.innerHTML;
}

/* ---- Acompanhantes cadastrados junto com o link específico ---- */
function linhaAcompanhanteInputHtml() {
  return '' +
    '<div class="row g-2 align-items-center acomp-link-linha">' +
      '<div class="col-7">' +
        '<input type="text" class="form-control form-control-sm campo-nome-acomp-link" placeholder="Nome do acompanhante">' +
      '</div>' +
      '<div class="col-4">' +
        '<select class="form-select form-select-sm campo-faixa-acomp-link">' +
          '<option value="Adulto (11+ anos)" selected>Adulto</option>' +
          '<option value="Criança (6-10 anos)">Criança (6-10)</option>' +
          '<option value="Criança de Colo (0-5 anos)">Criança de colo</option>' +
        '</select>' +
      '</div>' +
      '<div class="col-1 text-end">' +
        '<button type="button" class="btn btn-outline-danger btn-sm w-100 btn-remover-acomp-link" title="Remover"><i class="bi bi-x-lg"></i></button>' +
      '</div>' +
    '</div>';
}

document.getElementById('btn-add-acompanhante-link-esp')?.addEventListener('click', function () {
  document.getElementById('lista-acompanhantes-link-esp').insertAdjacentHTML('beforeend', linhaAcompanhanteInputHtml());
});

document.getElementById('lista-acompanhantes-link-esp')?.addEventListener('click', function (e) {
  const btn = e.target.closest('.btn-remover-acomp-link');
  if (btn) btn.closest('.acomp-link-linha').remove();
});

function linhaLinkEspecificoHtml(r) {
  const msgWpp = encodeURIComponent('Oi ' + r.nome + '! Confirme sua presença no casamento de ' + NOME_CASAL + ' por aqui: ' + r.link);
  const acompHtml = (r.acompanhantes && r.acompanhantes.length)
    ? '<div class="text-muted mb-1" style="font-size:.72rem;"><i class="bi bi-people-fill me-1"></i>' + escapeHtmlLinkEsp(r.acompanhantes.map(a => a.nome).join(', ')) + '</div>'
    : '';
  return '' +
    '<div class="linha-link-especifico border rounded-3 p-2" data-id="' + r.id + '">' +
      '<div class="d-flex justify-content-between align-items-center gap-2 mb-1">' +
        '<div class="small fw-bold text-truncate">' + escapeHtmlLinkEsp(r.nome) + '</div>' +
        '<span class="badge bg-warning text-dark" style="font-size:.65rem;">Pendente</span>' +
      '</div>' +
      acompHtml +
      '<div class="input-group input-group-sm">' +
        '<input type="text" class="form-control campo-link-esp" value="' + escapeHtmlLinkEsp(r.link) + '" readonly>' +
        '<button class="btn btn-outline-secondary btn-copiar-link-esp" type="button" title="Copiar"><i class="bi bi-clipboard"></i></button>' +
        '<a class="btn btn-outline-success btn-whatsapp-link-esp" target="_blank" title="Enviar por WhatsApp" href="https://wa.me/' + r.telefone_digits + '?text=' + msgWpp + '"><i class="bi bi-whatsapp"></i></a>' +
        '<button class="btn btn-outline-danger btn-remover-link-esp" type="button" title="Remover link"><i class="bi bi-trash"></i></button>' +
      '</div>' +
    '</div>';
}

document.getElementById('btn-gerar-link-especifico')?.addEventListener('click', async function () {
  const nomeEl = document.getElementById('link-esp-nome');
  const telEl  = document.getElementById('link-esp-telefone');
  const erroEl = document.getElementById('link-esp-erro');
  const btn    = this;
  const nome   = nomeEl.value.trim();
  const tel    = telEl.value.trim();
  erroEl.classList.add('d-none');

  if (!nome || tel.replace(/\D/g, '').length < 10) {
    erroEl.textContent = 'Informe o nome e um WhatsApp válido (com DDD).';
    erroEl.classList.remove('d-none');
    return;
  }

  const nomesAcomp  = Array.from(document.querySelectorAll('#lista-acompanhantes-link-esp .campo-nome-acomp-link')).map(el => el.value.trim());
  const faixasAcomp = Array.from(document.querySelectorAll('#lista-acompanhantes-link-esp .campo-faixa-acomp-link')).map(el => el.value);

  btn.disabled = true;
  const orig = btn.innerHTML;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Gerando...';

  const r = await ajax({
    criar_link_especifico: '1',
    nome_convidado_link: nome,
    telefone_convidado_link: tel,
    nome_acompanhante_link: nomesAcomp,
    faixa_acompanhante_link: faixasAcomp,
  });

  btn.disabled = false;
  btn.innerHTML = orig;

  if (!r.ok) {
    erroEl.textContent = r.msg || 'Não foi possível gerar o link.';
    erroEl.classList.remove('d-none');
    return;
  }

  nomeEl.value = '';
  telEl.value  = '';
  document.getElementById('lista-acompanhantes-link-esp').innerHTML = '';
  document.getElementById('msg-lista-vazia').style.display = 'none';
  document.getElementById('lista-links-especificos').insertAdjacentHTML('afterbegin', linhaLinkEspecificoHtml(r));
});

document.getElementById('lista-links-especificos')?.addEventListener('click', async function (e) {
  const linha = e.target.closest('.linha-link-especifico');
  if (!linha) return;

  if (e.target.closest('.btn-copiar-link-esp')) {
    const input = linha.querySelector('.campo-link-esp');
    try {
      await navigator.clipboard.writeText(input.value);
    } catch {
      input.removeAttribute('readonly');
      input.select();
      document.execCommand('copy');
      input.setAttribute('readonly', 'readonly');
    }
    const btnCopiar = e.target.closest('.btn-copiar-link-esp');
    const origIcon = btnCopiar.innerHTML;
    btnCopiar.innerHTML = '<i class="bi bi-check-lg"></i>';
    setTimeout(() => { btnCopiar.innerHTML = origIcon; }, 1500);
  }

  if (e.target.closest('.btn-remover-link-esp')) {
    if (!confirm('Remover este link específico?')) return;
    const r = await ajax({ excluir_link_especifico: '1', convidado_id: linha.dataset.id });
    if (r.ok) {
      linha.remove();
      if (!document.querySelector('.linha-link-especifico')) {
        document.getElementById('msg-lista-vazia').style.display = '';
      }
    }
  }
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

function toast(msg, tipo = 'verde') {
  const wrap = document.getElementById('toast-wrap');
  const el   = document.createElement('div');
  el.className = `toast-item ${tipo}`;
  const icones = { verde: 'check-circle-fill', verm: 'exclamation-circle-fill', info: 'info-circle-fill' };
  el.innerHTML = `<i class="bi bi-${icones[tipo] || 'info-circle-fill'}"></i> ${msg}`;
  wrap.appendChild(el);
  setTimeout(() => {
    el.style.transition = 'opacity .3s, transform .3s';
    el.style.opacity    = '0';
    el.style.transform  = 'translateX(24px)';
    setTimeout(() => el.remove(), 320);
  }, 2800);
}

const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;
const NOME_CASAL = <?= json_encode($evento['nome']) ?>;

async function ajax(obj) {
  obj.is_ajax = '1';
  obj.csrf_token = CSRF_TOKEN;
  const fd = new FormData();
  Object.entries(obj).forEach(([k, v]) => {
    if (Array.isArray(v)) {
      v.forEach(item => fd.append(k + '[]', item));
    } else {
      fd.append(k, v);
    }
  });
  const r = await fetch(SELF, { method: 'POST', body: fd });
  return r.json();
}

/* ============================================================
   FOTO DO CASAL E COR DA PÁGINA DO CONVITE
   (a mesma configuração vale pro link geral e pros links
   específicos — todos abrem a mesma página pública de RSVP)
   ============================================================ */
function ajustarCor(hex, percent) {
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

function initCustomizacaoConvite(sufixo) {
  const switchFoto     = document.getElementById('switch-foto-convite' + sufixo);
  const areaFoto       = document.getElementById('area-foto-convite' + sufixo);
  const inputFoto      = document.getElementById('input-foto-convite' + sufixo);
  const btnSalvarFoto  = document.getElementById('btn-salvar-foto-convite' + sufixo);
  const btnRemoverFoto = document.getElementById('btn-remover-foto-convite' + sufixo);

  switchFoto?.addEventListener('change', () => {
    areaFoto.style.display = switchFoto.checked ? '' : 'none';
  });

  btnSalvarFoto?.addEventListener('click', async function () {
    const btn     = this;
    const orig    = btn.innerHTML;
    const arquivo = inputFoto.files[0];

    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Salvando...';
    btn.disabled  = true;

    try {
      const payload = { salvar_foto_casal: '1', foto_ativa: switchFoto.checked ? '1' : '0' };
      if (arquivo) payload.foto_casal_arquivo = arquivo;

      const r = await ajax(payload);
      if (r.ok) {
        if (r.foto_url) {
          document.querySelectorAll('[id^="preview-foto-convite"]').forEach(img => {
            img.src = r.foto_url + '?t=' + Date.now();
            img.classList.remove('d-none');
          });
          document.querySelectorAll('[id^="btn-remover-foto-convite"]').forEach(b => b.classList.remove('d-none'));
          inputFoto.value = '';
        }
        toast(r.ativa ? 'Foto ativada no convite!' : 'Foto desativada no convite.', 'verde');
      } else {
        toast(r.msg || 'Erro ao salvar a foto.', 'verm');
      }
    } catch {
      toast('Erro de conexão.', 'verm');
    }
    btn.innerHTML = orig;
    btn.disabled  = false;
  });

  btnRemoverFoto?.addEventListener('click', async function () {
    if (!confirm('Remover a foto do casal do convite?')) return;
    try {
      const r = await ajax({ remover_foto_casal: '1' });
      if (r.ok) {
        document.querySelectorAll('[id^="preview-foto-convite"]').forEach(img => { img.classList.add('d-none'); img.src = ''; });
        document.querySelectorAll('[id^="btn-remover-foto-convite"]').forEach(b => b.classList.add('d-none'));
        document.querySelectorAll('[id^="switch-foto-convite"]').forEach(sw => { sw.checked = false; });
        document.querySelectorAll('[id^="area-foto-convite"]').forEach(a => { a.style.display = 'none'; });
        toast('Foto removida.', 'verm');
      } else {
        toast(r.msg || 'Erro ao remover a foto.', 'verm');
      }
    } catch {
      toast('Erro de conexão.', 'verm');
    }
  });

  const paleta       = document.getElementById('paleta-cor-convite' + sufixo);
  const inputCustom  = document.getElementById('input-cor-personalizada' + sufixo);
  const preview      = document.getElementById('preview-cor-convite' + sufixo);
  const btnSalvarCor = document.getElementById('btn-salvar-cor-convite' + sufixo);

  function atualizarPreview(hex) {
    const c1 = ajustarCor(hex, -0.22);
    const c3 = ajustarCor(hex, 0.22);
    if (preview) preview.style.background = `linear-gradient(135deg, ${c1} 0%, ${hex} 50%, ${c3} 100%)`;
    paleta?.querySelectorAll('.swatch-cor[data-cor]').forEach(sw => {
      sw.classList.toggle('selecionada', sw.dataset.cor.toLowerCase() === hex.toLowerCase());
    });
  }

  paleta?.querySelectorAll('.swatch-cor[data-cor]').forEach(sw => {
    sw.addEventListener('click', () => {
      if (inputCustom) inputCustom.value = sw.dataset.cor;
      atualizarPreview(sw.dataset.cor);
    });
  });

  inputCustom?.addEventListener('input', function () { atualizarPreview(this.value); });

  atualizarPreview(inputCustom?.value || '#8b5e3c');

  btnSalvarCor?.addEventListener('click', async function () {
    const btn  = this;
    const orig = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>...';
    btn.disabled  = true;

    try {
      const r = await ajax({ salvar_cor_convite: '1', cor: inputCustom.value });
      if (r.ok) {
        toast('Cor do convite atualizada!', 'verde');
        document.querySelectorAll('[id^="input-cor-personalizada"]').forEach(el => { el.value = inputCustom.value; });
        document.querySelectorAll('[id^="preview-cor-convite"]').forEach(el => {
          const c1b = ajustarCor(inputCustom.value, -0.22);
          const c3b = ajustarCor(inputCustom.value, 0.22);
          el.style.background = `linear-gradient(135deg, ${c1b} 0%, ${inputCustom.value} 50%, ${c3b} 100%)`;
        });
        document.querySelectorAll('.swatch-cor[data-cor]').forEach(sw => {
          sw.classList.toggle('selecionada', sw.dataset.cor.toLowerCase() === inputCustom.value.toLowerCase());
        });
      } else {
        toast(r.msg || 'Erro ao salvar a cor.', 'verm');
      }
    } catch {
      toast('Erro de conexão.', 'verm');
    }
    btn.innerHTML = orig;
    btn.disabled  = false;
  });
}

initCustomizacaoConvite('');

/* Formata número como moeda BR */
function brl(n) {
  return 'R$ ' + parseFloat(n).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/* Converte string de input (pt-BR) para float */
function parseBrl(s) {
  return parseFloat(String(s).replace(/\./g, '').replace(',', '.')) || 0;
}

/* Botão "Marcar lidas": some com o badge e limpa a lista exibida */
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

/* Clicar em uma notificação também marca como lida e a remove da lista */
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

/* ============================================================
   ABRIR CONVERSA / HISTÓRICO (FIX: função estava ausente)
   ============================================================ */
function abrirConversa(tipo, id, titulo) {
  const modalEl = document.getElementById('modalConversa');
  if (!modalEl) return;
  document.getElementById('conversa-titulo').textContent = titulo;
  const corpo = document.getElementById('conversa-corpo');
  corpo.innerHTML = '<div class="text-center py-4"><span class="spinner-border text-primary"></span></div>';
  const modal = new bootstrap.Modal(modalEl);
  modal.show();

  // Monta lista de comentários já no DOM para o tipo/id informado
  let comentarios = [];
  if (tipo === 'tarefa') {
    document.querySelectorAll('.lista-coment-tarefa').forEach(lista => {
      const form = lista.nextElementSibling;
      if (form && form.querySelector('[name="check_id"]')?.value == id) {
        lista.querySelectorAll('div').forEach(d => comentarios.push(d.innerHTML));
      }
    });
  } else {
    document.querySelectorAll('.lista-coment-etapa').forEach(lista => {
      const hidden = lista.closest('form')?.querySelector('[name="etapa_nome"]');
      if (!hidden) {
        // busca pelo form que tem o etapa_nome correto dentro do mesmo bloco
        const bloco = lista.closest('.p-3');
        if (bloco) {
          const f = bloco.querySelector('input[name="etapa_nome"]');
          if (f && f.value === id) {
            lista.querySelectorAll('div').forEach(d => comentarios.push(d.innerHTML));
          }
        }
      }
    });
  }

  if (comentarios.length === 0) {
    corpo.innerHTML = '<p class="text-muted text-center py-4 mb-0">Nenhum comentário ainda.</p>';
  } else {
    corpo.innerHTML = comentarios.map(c => `<div class="mb-2">${c}</div>`).join('');
  }
}

/* ============================================================
   TOGGLE TAREFA
   ============================================================ */
document.querySelectorAll('.btn-toggle-tarefa').forEach(btn => {
  btn.addEventListener('click', async () => {
    const id       = btn.dataset.id;
    const atual    = +btn.dataset.status;
    const card     = btn.closest('.tarefa-card');
    const titulo   = card.querySelector('h6');
    const hdrId    = btn.dataset.etapaHdrId;
    const etaTot   = +btn.dataset.etapaTotal;
    const collapso = btn.closest('.collapse');
    const orig     = btn.innerHTML;

    btn.innerHTML = '<span class="spinner-border spinner-border-sm text-secondary"></span>';
    try {
      const r = await ajax({ toggle_check: '1', check_id: id, status_atual: atual });
      if (!r.ok) throw new Error();
      const novo = r.novo === 1 || r.novo === '1';

      btn.innerHTML      = `<i class="bi ${novo ? 'bi-check-circle-fill' : 'bi-circle'}"></i>`;
      btn.dataset.status = novo ? '1' : '0';
      btn.classList.toggle('text-success', novo);
      btn.classList.toggle('text-muted', !novo);
      card.classList.toggle('done', novo);
      card.classList.toggle('pend', !novo);
      if (titulo) {
        titulo.classList.toggle('text-decoration-line-through', novo);
        titulo.classList.toggle('text-muted', novo);
        titulo.classList.toggle('text-dark', !novo);
      }

      const hdr = document.getElementById(hdrId);
      if (hdr) {
        const concEl = collapso.querySelectorAll('.tarefa-card.done').length;
        const pctE   = etaTot > 0 ? Math.round(concEl / etaTot * 100) : 0;
        const c = hdr.querySelector('.conc-etapa');
        const b = hdr.querySelector('.barra-mini-fill');
        const p = hdr.querySelector('.pct-etapa');
        const i = hdr.querySelector('.icone-etapa');
        if (c) c.textContent  = concEl;
        if (b) b.style.width  = pctE + '%';
        if (p) p.textContent  = pctE + '%';
        if (i) i.className    = concEl === etaTot && etaTot > 0
          ? 'bi bi-check-all text-success fs-5 icone-etapa'
          : 'bi bi-folder2-open text-info fs-5 icone-etapa';
      }

      const totalDone = document.querySelectorAll('.tarefa-card.done').length;
      const totalAll  = document.querySelectorAll('.tarefa-card').length;
      const pctG      = totalAll > 0 ? Math.round(totalDone / totalAll * 100) : 0;
      const lbl  = document.getElementById('label-conc-g');
      const barG = document.getElementById('barra-g');
      const ring = document.getElementById('ring-pct');
      if (lbl)  lbl.textContent  = totalDone;
      if (barG) barG.style.width = pctG + '%';
      if (ring) ring.textContent = pctG + '%';

      toast(novo ? 'Tarefa concluída! ✓' : 'Tarefa desmarcada.', novo ? 'verde' : 'info');
    } catch {
      btn.innerHTML = orig;
      toast('Erro ao atualizar. Tente novamente.', 'verm');
    }
  });
});

/* ============================================================
   COMENTÁRIOS DE ETAPAS
   ============================================================ */
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
            <span class="badge bg-danger rounded-pill me-2">${r.autor}</span>${r.texto}
          </div>`);
        input.value = '';
        toast('Nota salva!');
      }
    } catch { toast('Erro ao salvar nota.', 'verm'); }
    btn.innerHTML = orig;
  });
});

/* ============================================================
   COMENTÁRIOS DE TAREFAS
   ============================================================ */
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
            <strong class="text-danger">${r.autor}:</strong> ${r.texto}
          </div>`);
        input.value = '';
        toast('Comentário enviado!');
      }
    } catch { toast('Erro ao comentar.', 'verm'); }
    btn.innerHTML = orig;
  });
});

/* ============================================================
   CONTROLE DE PAGAMENTO DOS FORNECEDORES
   ============================================================ */
let totalPagoGeralNoivos = <?= json_encode($valor_pago_total) ?>;
const totalContratoGeralNoivos = <?= json_encode($valor_cont) ?>;

function atualizarChipsGeraisNoivos() {
  const restante = Math.max(0, totalContratoGeralNoivos - totalPagoGeralNoivos);
  const pct      = totalContratoGeralNoivos > 0 ? Math.round(totalPagoGeralNoivos / totalContratoGeralNoivos * 100) : 0;

  const elPago  = document.getElementById('total-pago-geral');
  const elRest  = document.getElementById('total-rest-geral');
  const elBarra = document.getElementById('barra-pago-global');
  const elPct   = document.getElementById('pct-pago-label');

  if (elPago)  elPago.textContent  = brl(totalPagoGeralNoivos);
  if (elRest)  elRest.textContent  = brl(restante);
  if (elBarra) elBarra.style.width = pct + '%';
  if (elPct)   elPct.textContent   = pct + '%';
}

function atualizarCardFornecedorNoivos(card, pago, total) {
  const rest = Math.max(0, total - pago);
  const pct  = total > 0 ? Math.round(pago / total * 100) : 0;
  const quit = rest <= 0;

  const pagoAnterior = parseFloat(card.dataset.pago || 0);
  totalPagoGeralNoivos += (pago - pagoAnterior);
  card.dataset.pago = pago;

  const barra  = card.querySelector('.forn-barra-fill');
  const restEl = card.querySelector('.forn-rest-val');
  const badge  = card.querySelector('.forn-pago-badge');
  const pagoTxt = card.querySelector('.forn-pago-valor-txt');

  if (barra) {
    barra.style.width = pct + '%';
    barra.className   = 'barra-pago-fill forn-barra-fill ' + (quit ? 'bg-success' : pct >= 50 ? 'bg-info' : 'bg-warning');
  }
  if (restEl) {
    restEl.textContent = brl(rest);
    restEl.className   = 'fw-bold forn-rest-val ' + (quit ? 'text-success' : 'text-danger');
  }
  if (badge) {
    badge.textContent = quit ? '✓ Quitado' : (pct > 0 ? pct + '% pago' : 'Não iniciado');
    badge.className   = 'forn-pago-badge ms-2 flex-shrink-0 ' + (quit ? 'bg-success text-white' : 'bg-warning text-dark');
  }
  if (pagoTxt) pagoTxt.textContent = 'R$ ' + pago.toLocaleString('pt-BR', { minimumFractionDigits: 2 });

  atualizarChipsGeraisNoivos();
}

/* Somar novo pagamento ao valor já pago */
document.querySelectorAll('.btn-add-pagamento-noivos').forEach(btn => {
  btn.addEventListener('click', async () => {
    const fid   = btn.dataset.id;
    const card  = document.getElementById('forn-' + fid);
    const input = card.querySelector('.forn-input-add-noivos');
    const total = parseFloat(input.dataset.total || 0);
    const valor = parseBrl(input.value);

    if (!valor || valor <= 0) { toast('Informe um valor maior que zero.', 'verm'); return; }

    const orig = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    btn.disabled  = true;

    try {
      const r = await ajax({
        adicionar_pagamento: '1',
        fornecedor_id:       fid,
        valor_pago:          valor.toString(),
      });

      if (r.ok) {
        const pago = parseFloat(r.valor_pago);
        const quit = parseFloat(r.valor_rest) <= 0;
        atualizarCardFornecedorNoivos(card, pago, total);
        input.value = '';
        toast(quit ? 'Pagamento quitado! 🎉' : 'Pagamento adicionado!', quit ? 'verde' : 'info');
      } else {
        toast(r.msg || 'Erro ao salvar pagamento.', 'verm');
      }
    } catch {
      toast('Erro de conexão. Tente novamente.', 'verm');
    }

    btn.innerHTML = orig;
    btn.disabled  = false;
  });
});

/* Alternar para o modo de corrigir o valor pago */
document.querySelectorAll('.btn-editar-pago-noivos').forEach(btn => {
  btn.addEventListener('click', () => {
    const fid       = btn.dataset.id;
    const card      = document.getElementById('forn-' + fid);
    const addWrap   = card.querySelector('.forn-add-wrap-noivos');
    const editWrap  = card.querySelector('.forn-edit-wrap-noivos');
    const editInput = editWrap.querySelector('.forn-input-edit-noivos');
    editInput.value = parseFloat(card.dataset.pago || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
    addWrap.style.display  = 'none';
    editWrap.style.display = 'flex';
    editInput.focus();
    editInput.select();
  });
});

/* Cancelar a correção */
document.querySelectorAll('.btn-cancelar-edit-noivos').forEach(btn => {
  btn.addEventListener('click', () => {
    const card = btn.closest('.forn-card');
    card.querySelector('.forn-edit-wrap-noivos').style.display = 'none';
    card.querySelector('.forn-add-wrap-noivos').style.display  = 'flex';
  });
});

/* Salvar a correção (sobrescreve o valor pago) */
document.querySelectorAll('.btn-salvar-edit-noivos').forEach(btn => {
  btn.addEventListener('click', async () => {
    const fid   = btn.dataset.id;
    const card  = document.getElementById('forn-' + fid);
    const input = card.querySelector('.forn-input-edit-noivos');
    const total = parseFloat(input.dataset.total || 0);
    let   valor = parseBrl(input.value);

    if (valor < 0) { toast('O valor não pode ser negativo.', 'verm'); return; }

    if (valor > total) {
      toast('Valor maior que o contrato! Ajustado para o total.', 'info');
      valor = total;
    }

    const orig = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    btn.disabled  = true;

    try {
      const r = await ajax({
        atualizar_valor_pago: '1',
        fornecedor_id:        fid,
        valor_pago:           valor.toString(),
      });

      if (r.ok) {
        const pago = parseFloat(r.valor_pago);
        atualizarCardFornecedorNoivos(card, pago, total);
        card.querySelector('.forn-edit-wrap-noivos').style.display = 'none';
        card.querySelector('.forn-add-wrap-noivos').style.display  = 'flex';
        toast('Valor corrigido!', 'info');
      } else {
        toast(r.msg || 'Erro ao salvar pagamento.', 'verm');
      }
    } catch {
      toast('Erro de conexão. Tente novamente.', 'verm');
    }

    btn.innerHTML = orig;
    btn.disabled  = false;
  });
});

document.querySelectorAll('.forn-input-add-noivos, .forn-input-edit-noivos').forEach(input => {
  input.addEventListener('blur', () => {
    if (input.value.trim() === '') return;
    const n = parseBrl(input.value);
    if (!isNaN(n)) {
      input.value = n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
  });
  input.addEventListener('focus', () => { input.select(); });
});

/* ============================================================
   CONVIDADOS
   ============================================================ */
function deltaCntTotal(n) {
  const e = document.getElementById('cnt-total');
  if (e) e.textContent = +e.textContent + n;
}
function deltaCntStatus(conf, n) {
  const e = document.getElementById(conf ? 'cnt-conf' : 'cnt-pend');
  if (e) e.textContent = +e.textContent + n;
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
        deltaCntStatus(novo, 1);
        deltaCntStatus(!novo, -1);
        toast(novo ? 'Presença confirmada!' : 'Marcado como pendente.', novo ? 'verde' : 'info');
      }
    } catch {
      badge.innerHTML = orig;
      toast('Erro ao atualizar convidado.', 'verm');
    }
  });
}

let pendingRow  = null;
const modalExcl = new bootstrap.Modal(document.getElementById('modalExcluir'));

function bindExcluirConv(btn) {
  btn.addEventListener('click', () => {
    pendingRow = btn.closest('.conv-row');
    modalExcl.show();
  });
}

document.getElementById('btnConfExcluir').addEventListener('click', async () => {
  if (!pendingRow) return;
  const row  = pendingRow;
  const id   = row.dataset.id;
  const conf = +row.dataset.conf;
  pendingRow = null;
  modalExcl.hide();
  try {
    const r = await ajax({ excluir_convidado_noivos: '1', convidado_id: id });
    if (r.ok) {
      row.style.opacity   = '0';
      row.style.transform = 'scale(.95)';
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
});

/* ---- Repetidor de acompanhantes (nome + faixa etária) ---- */
function escapeHtmlAcomp(str) {
  const d = document.createElement('div');
  d.textContent = str || '';
  return d.innerHTML;
}

function linhaAcompanhanteHtml(prefixo, dados) {
  dados = dados || {};
  const comId = prefixo === 'edit';
  return '' +
    '<div class="row g-2 align-items-center acomp-' + prefixo + '-linha">' +
      (comId ? '<input type="hidden" name="id_acompanhante_edit[]" value="' + (dados.id || '') + '">' : '') +
      '<div class="col-7">' +
        '<input type="text" name="nome_acompanhante_' + (comId ? 'edit' : 'novo') + '[]" class="form-control form-control-sm campo-nome-acomp" placeholder="Nome do acompanhante" value="' + escapeHtmlAcomp(dados.nome || '') + '">' +
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

const modalEditConv = new bootstrap.Modal(document.getElementById('modalEditConvidado'));

function bindEditConv(btn) {
  btn.addEventListener('click', () => {
    document.getElementById('econv-id').value        = btn.dataset.id;
    document.getElementById('econv-nome').value       = btn.dataset.nome;
    document.getElementById('econv-categoria').value  = btn.dataset.categoria;
    document.getElementById('econv-telefone').value   = btn.dataset.telefone;

    const listaEdit = document.getElementById('acomp-edit-lista');
    listaEdit.innerHTML = '';
    let acompanhantes = [];
    try { acompanhantes = JSON.parse(btn.dataset.acompanhantesJson || '[]'); } catch (err) {}
    acompanhantes.forEach(a => adicionarLinhaAcompanhante('acomp-edit-lista', 'edit', a));

    modalEditConv.show();
  });
}

function bindConvRow(row) {
  const t = row.querySelector('.btn-toggle-conv');
  const x = row.querySelector('.btn-excluir-conv');
  const ed = row.querySelector('.btn-edit-conv');
  if (ed) bindEditConv(ed);
  if (t) bindToggleConv(t);
  if (x) bindExcluirConv(x);
}

document.querySelectorAll('.conv-row').forEach(bindConvRow);

document.getElementById('busca-conv').addEventListener('input', function () {
  const q = this.value.toLowerCase().trim();
  document.querySelectorAll('#lista-convidados .conv-row').forEach(row => {
    row.style.display = (row.dataset.nome || '').includes(q) ? '' : 'none';
  });
  document.querySelectorAll('#lista-convidados .grupo-sec').forEach(sec => {
    const temVisivel = [...sec.querySelectorAll('.conv-row')].some(r => r.style.display !== 'none');
    sec.style.display = temVisivel ? '' : 'none';
  });
});

const GRUPO_ICONS = { 'Família': 'bi-house-heart-fill', 'Amigos': 'bi-emoji-sunglasses-fill', 'Outros': 'bi-collection-fill' };

function montarLinhaConvidado(r) {
  const extrasParts = [];
  if (r.telefone) extrasParts.push(`<div><i class="bi bi-whatsapp me-1 text-success"></i>${r.telefone}</div>`);
  if (r.acompanhantes && r.acompanhantes.length) {
    extrasParts.push(`<div><i class="bi bi-people me-1"></i>${r.acompanhantes.map(a => escapeHtmlAcomp(a.nome)).join(', ')}</div>`);
  }
  const extrasHtml = extrasParts.length
    ? `<div class="text-muted border-top pt-1 mt-1" style="font-size:.67rem;line-height:1.5;">${extrasParts.join('')}</div>`
    : '';

  const conf = r.confirmado === 1;
  return `
    <div class="conv-row ${conf ? 'conf' : 'pend'} p-2 mb-2 bg-light shadow-sm" data-id="${r.id}" data-conf="${conf ? '1' : '0'}" data-nome="${r.nome.toLowerCase()}">
      <div class="d-flex justify-content-between align-items-start mb-1">
        <h6 class="mb-0 small fw-bold text-dark text-truncate pe-2" title="${r.nome}">${r.nome}</h6>
        <div class="d-flex align-items-center gap-1 flex-shrink-0">
          <button type="button" class="btn p-0 border-0 bg-transparent btn-toggle-conv" data-id="${r.id}">
            <span class="badge ${conf ? 'bg-success' : 'bg-warning text-dark'} rounded-pill" style="font-size:.6rem;">${conf ? '<i class="bi bi-check-circle-fill me-1"></i> Confirmado' : '<i class="bi bi-hourglass-split me-1"></i> Pendente'}</span>
          </button>
          <button type="button" class="btn p-0 border-0 bg-transparent text-primary btn-edit-conv"
                  data-id="${r.id}" data-nome="${r.nome}" data-categoria="${r.categoria}" data-telefone="${r.telefone}"
                  data-acompanhantes-json='${JSON.stringify(r.acompanhantes || [])}' title="Editar">
            <i class="bi bi-pencil fs-6"></i>
          </button>
          <button type="button" class="btn p-0 border-0 bg-transparent text-danger btn-excluir-conv" data-id="${r.id}" title="Remover">
            <i class="bi bi-trash fs-6"></i>
          </button>
        </div>
      </div>
      ${extrasHtml}
    </div>`;
}

document.getElementById('form-convidado').addEventListener('submit', async (e) => {
  e.preventDefault();
  const nome = document.getElementById('conv-nome').value.trim();
  if (!nome) return;

  const btn  = document.getElementById('btn-salvar-convidado');
  const orig = btn.innerHTML;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>...';
  btn.disabled = true;

  const nomesAcompAdd  = Array.from(document.querySelectorAll('#acomp-add-lista .campo-nome-acomp')).map(el => el.value.trim());
  const faixasAcompAdd = Array.from(document.querySelectorAll('#acomp-add-lista .campo-faixa-acomp')).map(el => el.value);

  try {
    const r = await ajax({
      adicionar_convidado_noivos: '1',
      nome_convidado: nome,
      categoria_convidado: document.getElementById('conv-categoria').value.trim(),
      telefone_convidado: document.getElementById('conv-telefone').value.trim(),
      nome_acompanhante_novo: nomesAcompAdd,
      faixa_acompanhante_novo: faixasAcompAdd,
    });

    if (r.ok) {
      document.querySelector('#lista-convidados > p.text-center.text-muted')?.remove();

      const cat = r.categoria || 'Outros';
      let grupoEl = [...document.querySelectorAll('#lista-convidados .grupo-sec')].find(el => el.dataset.grupo === cat);
      if (!grupoEl) {
        const icone = GRUPO_ICONS[cat] || 'bi-tag-fill';
        document.getElementById('lista-convidados').insertAdjacentHTML('beforeend', `
          <div class="grupo-sec" data-grupo="${cat}">
            <div class="badge bg-secondary text-white w-100 text-start px-3 py-2 rounded-2 mb-1 mt-2" style="font-size:.72rem;">
              <i class="bi ${icone} me-1"></i> ${cat} (<span class="cnt-grp">0</span>)
            </div>
          </div>`);
        grupoEl = [...document.querySelectorAll('#lista-convidados .grupo-sec')].find(el => el.dataset.grupo === cat);
      }

      grupoEl.insertAdjacentHTML('beforeend', montarLinhaConvidado(r));
      const cntG = grupoEl.querySelector('.cnt-grp');
      if (cntG) cntG.textContent = grupoEl.querySelectorAll('.conv-row').length;

      const novaRow = grupoEl.querySelector(`.conv-row[data-id="${r.id}"]`);
      if (novaRow) bindConvRow(novaRow);

      deltaCntTotal(1);
      deltaCntStatus(r.confirmado === 1, 1);

      document.getElementById('form-convidado').reset();
      document.getElementById('acomp-add-lista').innerHTML = '';
      bootstrap.Modal.getInstance(document.getElementById('modalAddConvidado'))?.hide();
      toast('Convidado adicionado!', 'verde');
    } else {
      toast(r.msg || 'Erro ao salvar.', 'verm');
    }
  } catch {
    toast('Erro de conexão.', 'verm');
  }
  btn.innerHTML = orig;
  btn.disabled = false;
});

document.getElementById('form-edit-convidado').addEventListener('submit', async (e) => {
  e.preventDefault();
  const id   = document.getElementById('econv-id').value;
  const nome = document.getElementById('econv-nome').value.trim();
  if (!nome) return;

  const btn  = document.getElementById('btn-salvar-edit-convidado');
  const orig = btn.innerHTML;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>...';
  btn.disabled = true;

  const idsAcompEdit    = Array.from(document.querySelectorAll('#acomp-edit-lista .acomp-edit-linha input[name="id_acompanhante_edit[]"]')).map(el => el.value);
  const nomesAcompEdit  = Array.from(document.querySelectorAll('#acomp-edit-lista .campo-nome-acomp')).map(el => el.value.trim());
  const faixasAcompEdit = Array.from(document.querySelectorAll('#acomp-edit-lista .campo-faixa-acomp')).map(el => el.value);

  try {
    const r = await ajax({
      editar_convidado_noivos: '1',
      convidado_id: id,
      nome_convidado: nome,
      categoria_convidado: document.getElementById('econv-categoria').value.trim(),
      telefone_convidado: document.getElementById('econv-telefone').value.trim(),
      id_acompanhante_edit: idsAcompEdit,
      nome_acompanhante_edit: nomesAcompEdit,
      faixa_acompanhante_edit: faixasAcompEdit,
    });

    if (r.ok) {
      const row = document.querySelector(`.conv-row[data-id="${r.id}"]`);
      if (row) {
        const oldGrupoEl = row.closest('.grupo-sec');
        const cat = r.categoria || 'Outros';

        row.dataset.nome = r.nome.toLowerCase();
        const h6 = row.querySelector('h6');
        if (h6) { h6.textContent = r.nome; h6.title = r.nome; }

        row.querySelector('.text-muted.border-top')?.remove();
        const extrasParts = [];
        if (r.telefone) extrasParts.push(`<div><i class="bi bi-whatsapp me-1 text-success"></i>${r.telefone}</div>`);
        if (r.acompanhantes && r.acompanhantes.length) {
          extrasParts.push(`<div><i class="bi bi-people me-1"></i>${r.acompanhantes.map(a => escapeHtmlAcomp(a.nome)).join(', ')}</div>`);
        }
        if (extrasParts.length) {
          row.insertAdjacentHTML('beforeend', `<div class="text-muted border-top pt-1 mt-1" style="font-size:.67rem;line-height:1.5;">${extrasParts.join('')}</div>`);
        }

        const btnEdit = row.querySelector('.btn-edit-conv');
        if (btnEdit) {
          btnEdit.dataset.nome              = r.nome;
          btnEdit.dataset.categoria         = cat;
          btnEdit.dataset.telefone          = r.telefone;
          btnEdit.dataset.acompanhantesJson = JSON.stringify(r.acompanhantes || []);
        }

        // Move para o grupo certo, se a categoria mudou
        if (!oldGrupoEl || oldGrupoEl.dataset.grupo !== cat) {
          let novoGrupoEl = [...document.querySelectorAll('#lista-convidados .grupo-sec')].find(el => el.dataset.grupo === cat);
          if (!novoGrupoEl) {
            const icone = GRUPO_ICONS[cat] || 'bi-tag-fill';
            document.getElementById('lista-convidados').insertAdjacentHTML('beforeend', `
              <div class="grupo-sec" data-grupo="${cat}">
                <div class="badge bg-secondary text-white w-100 text-start px-3 py-2 rounded-2 mb-1 mt-2" style="font-size:.72rem;">
                  <i class="bi ${icone} me-1"></i> ${cat} (<span class="cnt-grp">0</span>)
                </div>
              </div>`);
            novoGrupoEl = [...document.querySelectorAll('#lista-convidados .grupo-sec')].find(el => el.dataset.grupo === cat);
          }
          novoGrupoEl.appendChild(row);
          const novoCnt = novoGrupoEl.querySelector('.cnt-grp');
          if (novoCnt) novoCnt.textContent = novoGrupoEl.querySelectorAll('.conv-row').length;

          if (oldGrupoEl) {
            const restantes = oldGrupoEl.querySelectorAll('.conv-row');
            const oldCnt = oldGrupoEl.querySelector('.cnt-grp');
            if (oldCnt) oldCnt.textContent = restantes.length;
            if (restantes.length === 0) oldGrupoEl.remove();
          }
        }
      }

      modalEditConv.hide();
      toast('Convidado atualizado!', 'verde');
    } else {
      toast(r.msg || 'Erro ao salvar.', 'verm');
    }
  } catch {
    toast('Erro de conexão.', 'verm');
  }
  btn.innerHTML = orig;
  btn.disabled = false;
});

/* ============================================================
   TRILHA SONORA (MÚSICAS)
   ============================================================ */
function atualizarContadoresMusicas() {
  const total = document.querySelectorAll('#grid-musicas .musica-card-wrap').length;
  const txt   = total + ' música' + (total !== 1 ? 's' : '');
  const badge = document.getElementById('musicas-count-badge');
  if (badge) badge.textContent = txt;
}

function bindBotoesMusica() {
  document.querySelectorAll('.btn-excluir-musica').forEach(btn => {
    btn.onclick = () => {
      const id   = btn.dataset.id;
      const wrap = btn.closest('.musica-card-wrap');
      if (confirm('Deseja realmente remover esta sugestão de música?')) {
        ajax({ excluir_musica_noivos: '1', musica_id: id }).then(r => {
          if (r.ok) {
            wrap.style.transition = 'opacity .25s, transform .25s';
            wrap.style.opacity    = '0';
            wrap.style.transform  = 'scale(.92)';
            setTimeout(() => {
              wrap.remove();
              const grid = document.getElementById('grid-musicas');
              if (grid && !grid.querySelector('.musica-card-wrap')) {
                document.getElementById('lista-musicas-wrap').innerHTML =
                  `<div class="text-center py-5 text-muted" id="musicas-vazia">
                    <i class="bi bi-music-note-list fs-1 d-block mb-2" style="opacity:.25;"></i>
                    <small>Nenhuma música sugerida ainda.</small>
                  </div>`;
              }
              atualizarContadoresMusicas();
            }, 280);
            toast('Música removida.', 'verm');
          }
        }).catch(() => toast('Erro ao remover.', 'verm'));
      }
    };
  });
}

document.getElementById('form-musica')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const momento = document.getElementById('musica-momento').value.trim();
  const titulo  = document.getElementById('musica-titulo').value.trim();
  const link    = document.getElementById('musica-link').value.trim();

  const btn  = document.getElementById('btn-salvar-musica');
  const orig = btn.innerHTML;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>...';
  btn.disabled = true;

  try {
    const r = await ajax({ adicionar_musica_noivos: '1', momento_musica: momento, titulo_musica: titulo, link_musica: link });
    if (r.ok) {
      document.getElementById('musicas-vazia')?.remove();
      let grid = document.getElementById('grid-musicas');
      if (!grid) {
        document.getElementById('lista-musicas-wrap').innerHTML = `<div class="row g-3" id="grid-musicas"></div>`;
        grid = document.getElementById('grid-musicas');
      }

      const linkHtml = r.link ? `<a href="${r.link}" target="_blank" class="small text-decoration-none mt-1 d-inline-block"><i class="bi bi-link-45deg"></i> Ouvir Referência</a>` : '';

      const html = `
        <div class="col-12 musica-card-wrap" data-id="${r.id}">
          <div class="card border-0 shadow-sm rounded-3 bg-white">
            <div class="card-body p-3 d-flex align-items-center justify-content-between gap-3">
              <div class="d-flex align-items-center gap-3 w-100">
                 <div class="flex-grow-1">
                   <div class="d-flex align-items-center gap-2 mb-1">
                     <div class="fw-bold text-uppercase text-muted" style="font-size:.65rem; letter-spacing:.05em;">${r.momento}</div>
                     <span class="badge bg-secondary opacity-75" style="font-size:.55rem;"><i class="bi bi-hourglass-split me-1"></i>Pendente</span>
                   </div>
                   <h6 class="mb-0 fw-bold text-dark" style="font-size:.9rem;">${r.titulo}</h6>
                   ${linkHtml}
                 </div>
              </div>
              <button type="button" class="btn p-1 border-0 text-danger btn-excluir-musica flex-shrink-0" data-id="${r.id}">
                <i class="bi bi-trash-fill"></i>
              </button>
            </div>
          </div>
        </div>`;

      grid.insertAdjacentHTML('beforeend', html);
      bindBotoesMusica();
      document.getElementById('form-musica').reset();
      atualizarContadoresMusicas();
      toast('Música sugerida com sucesso!', 'verde');
      document.getElementById('musica-momento').focus();
    } else {
      toast(r.msg || 'Erro ao salvar.', 'verm');
    }
  } catch {
    toast('Erro de conexão.', 'verm');
  }
  btn.innerHTML = orig;
  btn.disabled = false;
});

bindBotoesMusica();

/* ---- MODAL DE UPLOADS (documentos/arquivos do evento) ---- */
function cssEscape(str) {
  if (typeof CSS !== 'undefined' && CSS.escape) return CSS.escape(str);
  return str.replace(/[^\w-]/g, c => '\\' + c);
}

function escDoc(str) {
  return String(str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function docItemHtml(d) {
  const nomeEsc     = escDoc(d.nome_original);
  const arquivoEsc  = escDoc(d.nome_arquivo);
  const thumb = d.is_imagem
    ? `<img src="./uploads/${arquivoEsc}" alt="">`
    : '<i class="bi bi-file-earmark-pdf-fill"></i>';
  return `
    <div class="doc-item position-relative" data-id="${d.id}" data-arquivo="${arquivoEsc}" data-nome="${nomeEsc}" data-imagem="${d.is_imagem ? '1' : '0'}" title="${nomeEsc}">
      <span class="doc-item-remove" title="Excluir"><i class="bi bi-x-lg"></i></span>
      <div class="doc-item-thumb">${thumb}</div>
      <div class="doc-item-nome text-truncate">${nomeEsc}</div>
      <div class="doc-item-meta">${d.tamanho_fmt}</div>
    </div>`;
}

function garantirGrupoDoc(categoria) {
  document.getElementById('docs-vazia')?.remove();
  const wrap = document.getElementById('docs-lista-grupos');
  let grupo = wrap.querySelector(`.doc-grupo[data-categoria="${cssEscape(categoria)}"]`);
  if (!grupo) {
    grupo = document.createElement('div');
    grupo.className = 'doc-grupo mb-4';
    grupo.dataset.categoria = categoria;
    grupo.innerHTML = `
      <div class="doc-grupo-header d-flex align-items-center gap-2 px-2 py-2 rounded-3 mb-2" style="background:var(--color-primary-light);">
        <i class="bi bi-folder-fill" style="color:var(--color-primary-dark);"></i>
        <span class="fw-bold small" style="color:var(--color-primary-dark);">${escDoc(categoria)}</span>
        <span class="badge rounded-pill cnt-grp-doc" style="background:var(--color-primary-dark);">0</span>
      </div>
      <div class="doc-lista-items d-flex flex-wrap gap-2"></div>`;
    if (filtroDocAtivo !== 'Todos' && filtroDocAtivo !== categoria) grupo.style.display = 'none';
    wrap.appendChild(grupo);
    garantirFiltroDocBtn(categoria);
  }
  return grupo;
}

function inserirDocItem(d) {
  const grupo = garantirGrupoDoc(d.categoria);
  const lista = grupo.querySelector('.doc-lista-items');
  lista.insertAdjacentHTML('afterbegin', docItemHtml(d));
  const cnt = grupo.querySelector('.cnt-grp-doc');
  if (cnt) cnt.textContent = lista.querySelectorAll('.doc-item').length;
  atualizarContadorFiltroDoc(d.categoria);
}

/* ---- Filtro por categoria (barra de pills acima da lista) ---- */
let filtroDocAtivo = 'Todos';

function aplicarFiltroDocs() {
  document.querySelectorAll('#docs-lista-grupos .doc-grupo').forEach(g => {
    g.style.display = (filtroDocAtivo === 'Todos' || g.dataset.categoria === filtroDocAtivo) ? '' : 'none';
  });
}

function pintarFiltroDocBtn(btn, ativo) {
  btn.classList.toggle('active', ativo);
  btn.style.background   = ativo ? 'var(--color-primary-dark)' : 'var(--color-primary-light)';
  btn.style.color        = ativo ? '#fff' : 'var(--color-primary-dark)';
  btn.style.borderColor  = ativo ? 'var(--color-primary-dark)' : 'var(--color-primary)';
  const badge = btn.querySelector('.badge');
  if (badge) {
    badge.style.background = ativo ? 'rgba(255,255,255,.3)' : 'rgba(169,116,79,.18)';
    badge.style.color      = ativo ? '#fff' : 'var(--color-primary-dark)';
  }
}

function bindFiltroDocBtn(btn) {
  btn.addEventListener('click', function () {
    filtroDocAtivo = this.dataset.cat;
    document.querySelectorAll('.doc-filtro-btn').forEach(b => pintarFiltroDocBtn(b, b === this));
    aplicarFiltroDocs();
  });
}

function garantirFiltroDocBtn(categoria) {
  const wrap = document.getElementById('docs-filtros');
  if (!wrap) return;
  let btn = wrap.querySelector(`.doc-filtro-btn[data-cat="${cssEscape(categoria)}"]`);
  if (!btn) {
    btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-sm rounded-pill doc-filtro-btn';
    btn.dataset.cat = categoria;
    btn.innerHTML = `${escDoc(categoria)} <span class="badge rounded-pill ms-1">0</span>`;
    pintarFiltroDocBtn(btn, false);
    bindFiltroDocBtn(btn);
    wrap.appendChild(btn);
  }
}

function atualizarContadorFiltroDoc(categoria) {
  const grupo = document.querySelector(`#docs-lista-grupos .doc-grupo[data-categoria="${cssEscape(categoria)}"]`);
  const qtd   = grupo ? grupo.querySelectorAll('.doc-item').length : 0;
  const btn   = document.querySelector(`.doc-filtro-btn[data-cat="${cssEscape(categoria)}"]`);
  const badge = btn?.querySelector('.badge');
  if (badge) badge.textContent = qtd;

  const total      = document.querySelectorAll('#docs-lista-grupos .doc-item').length;
  const badgeTodos = document.querySelector('.doc-filtro-btn[data-cat="Todos"] .badge');
  if (badgeTodos) badgeTodos.textContent = total;
}

document.querySelectorAll('.doc-filtro-btn').forEach(bindFiltroDocBtn);

// Categoria: "Outra categoria (digitar)" revela um campo de texto livre
document.getElementById('doc-categoria')?.addEventListener('change', function () {
  const campoOutro = document.getElementById('doc-categoria-outra');
  const ehOutro = this.value === '__outro__';
  campoOutro.classList.toggle('d-none', !ehOutro);
  if (ehOutro) campoOutro.focus();
});

// Enviar arquivo
document.getElementById('btn-add-doc')?.addEventListener('click', async () => {
  const selCat     = document.getElementById('doc-categoria');
  const campoOutro = document.getElementById('doc-categoria-outra');
  const ehOutro    = selCat.value === '__outro__';
  const categoria  = ehOutro ? campoOutro.value.trim() : selCat.value;
  const inputArq   = document.getElementById('doc-arquivo');
  const arquivo    = inputArq.files[0];

  if (ehOutro && !categoria) { toast('Digite o nome da categoria.', 'verm'); campoOutro.focus(); return; }
  if (!arquivo) { toast('Selecione um arquivo para enviar.', 'verm'); return; }

  const btn  = document.getElementById('btn-add-doc');
  const orig = btn.innerHTML;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Enviando…';
  btn.disabled  = true;
  try {
    const r = await ajax({ upload_documento: '1', categoria_documento: categoria, arquivo_documento: arquivo });
    if (r.ok) {
      inserirDocItem(r.documento);
      inputArq.value = '';
      toast('Arquivo enviado!', 'verde');
    } else {
      toast(r.msg || 'Erro ao enviar arquivo.', 'verm');
    }
  } catch { toast('Erro de conexão. Tente novamente.', 'verm'); }
  btn.innerHTML = orig;
  btn.disabled  = false;
});

// Clique num arquivo abre a prévia (dentro da própria modal); clique no "x" exclui
document.getElementById('docs-lista-grupos')?.addEventListener('click', async e => {
  const removeBtn = e.target.closest('.doc-item-remove');
  if (removeBtn) {
    if (!confirm('Excluir este arquivo? Esta ação não pode ser desfeita.')) return;
    const item      = removeBtn.closest('.doc-item');
    const grupo     = removeBtn.closest('.doc-grupo');
    const id        = item.dataset.id;
    const categoria = grupo?.dataset.categoria;
    try {
      const r = await ajax({ excluir_documento: '1', documento_id: id });
      if (r.ok) {
        item.style.transition = 'opacity .25s, transform .25s';
        item.style.opacity    = '0';
        item.style.transform  = 'scale(.9)';
        setTimeout(() => {
          item.remove();
          if (grupo) {
            const rest = grupo.querySelectorAll('.doc-item').length;
            const cnt  = grupo.querySelector('.cnt-grp-doc');
            if (cnt) cnt.textContent = rest;
            if (rest === 0) grupo.remove();
          }
          if (categoria) atualizarContadorFiltroDoc(categoria);
          if (!document.querySelector('#docs-lista-grupos .doc-item') && !document.getElementById('docs-vazia')) {
            document.getElementById('docs-lista-grupos').insertAdjacentHTML('afterbegin',
              '<div class="text-center py-5 text-muted" id="docs-vazia"><i class="bi bi-folder2-open fs-1 d-block mb-2" style="opacity:.2;"></i><small>Nenhum arquivo enviado ainda.</small></div>');
          }
        }, 260);
        toast('Arquivo excluído.', 'verm');
      }
    } catch { toast('Erro ao excluir arquivo.', 'verm'); }
    return;
  }

  const item = e.target.closest('.doc-item');
  if (item) abrirPreviewDoc(item.dataset);
});

function abrirPreviewDoc(ds) {
  const caminho   = './uploads/' + encodeURIComponent(ds.arquivo);
  const conteudo  = document.getElementById('docs-preview-conteudo');
  conteudo.innerHTML = ds.imagem === '1'
    ? `<img src="${caminho}" alt="">`
    : `<iframe src="${caminho}"></iframe>`;
  document.getElementById('docs-preview-nome').textContent = ds.nome;
  document.getElementById('doc-preview-abrir').href = caminho;
  document.getElementById('docs-view-lista').classList.add('d-none');
  document.getElementById('docs-view-preview').classList.remove('d-none');
}

document.getElementById('btn-doc-voltar')?.addEventListener('click', () => {
  document.getElementById('docs-view-preview').classList.add('d-none');
  document.getElementById('docs-view-lista').classList.remove('d-none');
  document.getElementById('docs-preview-conteudo').innerHTML = '';
});

document.getElementById('modalDocumentos')?.addEventListener('hidden.bs.modal', () => {
  document.getElementById('docs-view-preview')?.classList.add('d-none');
  document.getElementById('docs-view-lista')?.classList.remove('d-none');
  const conteudo = document.getElementById('docs-preview-conteudo');
  if (conteudo) conteudo.innerHTML = '';
});
</script>
</body>
</html>