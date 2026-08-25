<?php
session_start();
require_once 'sessao_timeout.inc.php';
verificar_sessao_ativa();

// Importa a conexão com o banco de dados
require_once 'conexao.php';

// ============================================================
// TRAVA DE SEGURANÇA: Admin e Assistente acessam esta página
// ============================================================
if (!isset($_SESSION['usuario_tipo']) || !in_array($_SESSION['usuario_tipo'], ['admin', 'assistente'])) {
    header("Location: index.php?sessao_expirada=1");
    exit;
}

// Variável global para facilitação de regras de acesso do Admin
$is_admin = ($_SESSION['usuario_tipo'] === 'admin');

// ============================================================
// CSRF: Gera token de sessão para proteção dos formulários
// ============================================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function validar_csrf(): void {
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        die("Requisição inválida. Token CSRF ausente ou incorreto.");
    }
}

$data_hoje = date('Y-m-d');

require_once 'notificacoes.inc.php';

// ============================================================
// MIGRAÇÃO AUTOMÁTICA DA TABELA (executa uma vez se necessário)
// Garante que a tabela suporte múltiplas anotações por dia com horário
// ============================================================
try {
    $cols = $pdo->query("SHOW COLUMNS FROM calendario_anotacoes")->fetchAll(PDO::FETCH_COLUMN);
    
    // 1. Adiciona a coluna 'id' como Primary Key (se não existir)
    if (!in_array('id', $cols)) {
        try { $pdo->exec("ALTER TABLE calendario_anotacoes DROP PRIMARY KEY"); } catch(Exception $e) {}
        $pdo->exec("ALTER TABLE calendario_anotacoes ADD COLUMN id INT AUTO_INCREMENT PRIMARY KEY FIRST");
    }

    // 2. Adiciona a coluna 'hora_nota' (se não existir)
    if (!in_array('hora_nota', $cols)) {
        $pdo->exec("ALTER TABLE calendario_anotacoes ADD COLUMN hora_nota TIME NULL AFTER data_nota");
    }

    // 3. Remove a restrição única por data (se existir)
    try {
        $pdo->exec("ALTER TABLE calendario_anotacoes DROP INDEX data_nota");
    } catch(Exception $e) {
        // Ignora silenciosamente se o índice já não existir
    }

} catch (Exception $e) {
    error_log("[MIGRAÇÃO calendario_anotacoes] " . $e->getMessage());
}

// ============================================================
// 1. NAVEGAÇÃO DO CALENDÁRIO
// ============================================================
$mes_atual = filter_input(INPUT_GET, 'mes', FILTER_VALIDATE_INT);
$ano_atual = filter_input(INPUT_GET, 'ano', FILTER_VALIDATE_INT);

if (!$mes_atual || $mes_atual < 1 || $mes_atual > 12) {
    $mes_atual = (int)date('m');
}
if (!$ano_atual || $ano_atual < 2000 || $ano_atual > 2100) {
    $ano_atual = (int)date('Y');
}

$mes_atual_str = str_pad($mes_atual, 2, "0", STR_PAD_LEFT);
$mes_anterior = $mes_atual - 1;
$ano_anterior = $ano_atual;
if ($mes_anterior == 0) { $mes_anterior = 12; $ano_anterior--; }

$mes_seguinte = $mes_atual + 1;
$ano_seguinte = $ano_atual;
if ($mes_seguinte == 13) { $mes_seguinte = 1; $ano_seguinte++; }


// ============================================================
// BLOCO DE PROCESSAMENTO POST (HANDLERS)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    // 2. SALVAR ANOTAÇÃO DIÁRIA (AJUSTADO PARA AJAX)
    if (isset($_POST['salvar_anotacao'])) {
        validar_csrf();

        $data_nota  = $_POST['data_nota'] ?? '';
        $hora_nota  = !empty(trim($_POST['hora_nota'] ?? '')) ? trim($_POST['hora_nota']) : null;
        $anotacao   = trim($_POST['anotacao'] ?? '');
        $nota_id    = (int)($_POST['nota_id'] ?? 0); // 0 = nova anotação
        $mes_ret    = (int)($_POST['mes'] ?? $mes_atual);
        $ano_ret    = (int)($_POST['ano'] ?? $ano_atual);

        if (!empty($data_nota) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_nota)) {
            if (empty($anotacao)) {
                // Se a anotação foi apagada (texto vazio), exclui o registro
                if ($nota_id > 0) {
                    $stmt = $pdo->prepare("DELETE FROM calendario_anotacoes WHERE id = ?");
                    $stmt->execute([$nota_id]);
                    
                    if ($is_ajax) {
                        header('Content-Type: application/json');
                        echo json_encode(['sucesso' => true, 'acao' => 'excluida', 'id' => $nota_id]);
                        exit;
                    }
                    $_SESSION['msg_sucesso'] = "Anotação removida.";
                }
            } else {
                if ($nota_id > 0) {
                    // Atualiza anotação existente
                    $stmt = $pdo->prepare("UPDATE calendario_anotacoes SET hora_nota = ?, anotacao = ? WHERE id = ?");
                    $stmt->execute([$hora_nota, $anotacao, $nota_id]);
                    $id_final = $nota_id;
                    $acao = 'atualizada';
                } else {
                    // Insere nova anotação para aquela data
                    $stmt = $pdo->prepare("INSERT INTO calendario_anotacoes (data_nota, hora_nota, anotacao) VALUES (?, ?, ?)");
                    $stmt->execute([$data_nota, $hora_nota, $anotacao]);
                    $id_final = $pdo->lastInsertId();
                    $acao = 'criada';
                }
                
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'sucesso' => true,
                        'acao' => $acao,
                        'nota' => [
                            'id' => $id_final,
                            'data_nota' => $data_nota,
                            'hora_nota' => $hora_nota,
                            'anotacao' => $anotacao
                        ]
                    ]);
                    exit;
                }
                $_SESSION['msg_sucesso'] = "Anotação guardada com sucesso!";
            }
            
            if (!$is_ajax) {
                header("Location: painel_admin.php?mes=$mes_ret&ano=$ano_ret");
                exit;
            }
        }
    }

    // 2b. EXCLUIR ANOTAÇÃO INDIVIDUAL (AJUSTADO PARA AJAX)
    if (isset($_POST['excluir_anotacao'])) {
        validar_csrf();
        $nota_id = (int)($_POST['nota_id'] ?? 0);
        $mes_ret = (int)($_POST['mes'] ?? $mes_atual);
        $ano_ret = (int)($_POST['ano'] ?? $ano_atual);

        if ($nota_id > 0) {
            $pdo->prepare("DELETE FROM calendario_anotacoes WHERE id = ?")->execute([$nota_id]);
            
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['sucesso' => true]);
                exit;
            }
            
            $_SESSION['msg_sucesso'] = "Anotação excluída.";
        }
        
        if (!$is_ajax) {
            header("Location: painel_admin.php?mes=$mes_ret&ano=$ano_ret");
            exit;
        }
    }

    // 3. EXCLUIR EVENTO
    if (isset($_POST['excluir_evento'])) {
        validar_csrf();
        $evento_id = (int)($_POST['evento_id'] ?? 0);

        if ($evento_id > 0) {
            try {
                $pdo->beginTransaction();

                // Descobre o cliente dono do evento e as fotos do mural, antes de apagar tudo
                $stmt_cli = $pdo->prepare("SELECT cliente_id FROM eventos WHERE id = ?");
                $stmt_cli->execute([$evento_id]);
                $cliente_id = $stmt_cli->fetchColumn();

                $stmt_fotos = $pdo->prepare("SELECT nome_imagem FROM inspiracoes_fotos WHERE evento_id = ?");
                $stmt_fotos->execute([$evento_id]);
                $fotos_para_apagar = $stmt_fotos->fetchAll(PDO::FETCH_COLUMN);

                // checklist, convidados, inspiracoes_fotos, playlist_evento e
                // servicos_assessoria já têm ON DELETE CASCADE no banco — mas
                // mesas, musicas_evento e notas_evento não têm, então precisam
                // ser apagadas manualmente aqui, senão ficam órfãs.
                $pdo->prepare("DELETE FROM checklist WHERE evento_id = ?")->execute([$evento_id]);
                $pdo->prepare("DELETE FROM convidados WHERE evento_id = ?")->execute([$evento_id]);
                $pdo->prepare("DELETE FROM fornecedores_evento WHERE evento_id = ?")->execute([$evento_id]);
                $pdo->prepare("DELETE FROM mesas WHERE evento_id = ?")->execute([$evento_id]);
                $pdo->prepare("DELETE FROM musicas_evento WHERE evento_id = ?")->execute([$evento_id]);
                $pdo->prepare("DELETE FROM notas_evento WHERE evento_id = ?")->execute([$evento_id]);
                $pdo->prepare("DELETE FROM eventos WHERE id = ?")->execute([$evento_id]);

                // Só remove o cliente se este era o único evento dele —
                // um cliente pode ter mais de um casamento cadastrado.
                if ($cliente_id) {
                    $stmt_outros = $pdo->prepare("SELECT COUNT(*) FROM eventos WHERE cliente_id = ?");
                    $stmt_outros->execute([$cliente_id]);
                    if ((int)$stmt_outros->fetchColumn() === 0) {
                        $pdo->prepare("DELETE FROM clientes WHERE id = ?")->execute([$cliente_id]);
                    }
                }

                $pdo->commit();

                foreach ($fotos_para_apagar as $nome_imagem) {
                    $caminho = __DIR__ . '/uploads/' . $nome_imagem;
                    if (is_file($caminho)) { @unlink($caminho); }
                }

                $_SESSION['msg_sucesso'] = "Evento e todos os seus dados removidos!";
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("[EXCLUIR EVENTO] " . $e->getMessage());
                $_SESSION['msg_erro'] = "Erro ao excluir o evento. Tente novamente.";
            }
        }
        header("Location: painel_admin.php");
        exit;
    }

    // 4. CADASTRAR NOVO EVENTO
    if (isset($_POST['cadastrar_evento'])) {
        validar_csrf();

        $nome_noiva       = trim($_POST['nome_noiva'] ?? '');
        $nome_noivo       = trim($_POST['nome_noivo'] ?? '');
        $nome_cliente     = $nome_noiva . ' & ' . $nome_noivo;
        $email_cliente    = trim($_POST['email_cliente'] ?? '');
        $cpf_cliente      = preg_replace('/[^0-9]/', '', trim($_POST['cpf_cliente'] ?? ''));
        $cpf_cliente      = $cpf_cliente !== '' ? $cpf_cliente : null; // NULL em vez de '' — a coluna é UNIQUE e '' duplicada quebra o 2º cadastro sem CPF
        $telefone_cliente = trim($_POST['telefone_cliente'] ?? '');
        $senha_raw        = trim($_POST['senha_cliente'] ?? '');
        $data_evento      = $_POST['data_evento'] ?? '';
        $hora_evento      = !empty($_POST['hora_evento']) ? $_POST['hora_evento'] : null;
        $modelo_checklist = $_POST['modelo_checklist'] ?? '';

        // Impede usar o e-mail de uma conta da equipe (admin/assistente) como
        // login do casal — evita ambiguidade de identidade entre as duas tabelas.
        $stmt_email_equipe = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
        $stmt_email_equipe->execute([$email_cliente]);
        $email_e_da_equipe = (bool)$stmt_email_equipe->fetch();

        if ($email_e_da_equipe) {
            $_SESSION['msg_erro'] = "Este e-mail já pertence a uma conta da equipe (admin/assistente) e não pode ser usado para o login do casal.";
            header("Location: painel_admin.php");
            exit;
        }

        if (!empty($nome_noiva) && !empty($nome_noivo) && !empty($email_cliente) && !empty($data_evento)) {

            $senha_raw = empty($senha_raw) ? '123456' : $senha_raw;
            $senha_hash = password_hash($senha_raw, PASSWORD_BCRYPT);

            // Bloqueia duplicidade se o e-mail (sempre) ou o CPF (quando informado)
            // já tiver um casamento futuro cadastrado — antes só checava por CPF,
            // então cadastros repetidos com CPF em branco criavam eventos duplicados.
            $cadastro_liberado = true;
            $stmt_check = $pdo->prepare("
                SELECT e.data_evento
                FROM clientes c
                INNER JOIN eventos e ON c.id = e.cliente_id
                WHERE c.email = ? OR (c.cpf IS NOT NULL AND c.cpf = ?)
            ");
            $stmt_check->execute([$email_cliente, $cpf_cliente]);
            foreach ($stmt_check->fetchAll(PDO::FETCH_ASSOC) as $ev) {
                if ($ev['data_evento'] >= $data_hoje) {
                    $cadastro_liberado = false;
                    break;
                }
            }

            if (!$cadastro_liberado) {
                $_SESSION['msg_erro'] = "Atenção: Este e-mail (ou CPF) já possui um casamento futuro cadastrado.";
            } else {
                try {
                    $pdo->beginTransaction();

                    $stmt_cli_check = $pdo->prepare("SELECT id FROM clientes WHERE email = ? LIMIT 1");
                    $stmt_cli_check->execute([$email_cliente]);
                    $cliente_existente = $stmt_cli_check->fetch(PDO::FETCH_ASSOC);

                    if ($cliente_existente) {
                        $cliente_id = $cliente_existente['id'];
                        $stmt_cli_up = $pdo->prepare("UPDATE clientes SET nome = ?, cpf = ?, telefone = ? WHERE id = ?");
                        $stmt_cli_up->execute([$nome_cliente, $cpf_cliente, $telefone_cliente, $cliente_id]);
                    } else {
                        $stmt_cli = $pdo->prepare("INSERT INTO clientes (nome, email, cpf, telefone, senha) VALUES (?, ?, ?, ?, ?)");
                        $stmt_cli->execute([$nome_cliente, $email_cliente, $cpf_cliente, $telefone_cliente, $senha_hash]);
                        $cliente_id = $pdo->lastInsertId();
                    }

                    $stmt_eve = $pdo->prepare("
                        INSERT INTO eventos (cliente_id, data_evento, hora_evento, tipo_ceremonia, local_ceremonia, tipo_assessoria)
                        VALUES (?, ?, ?, 'Igreja', '', 'Básica')
                    ");
                    $stmt_eve->execute([$cliente_id, $data_evento, $hora_evento]);
                    $evento_id = $pdo->lastInsertId();

                    if (!empty($modelo_checklist)) {
                        $stmt_mod = $pdo->prepare("SELECT * FROM checklist_modelos WHERE tipo_padrao = ?");
                        $stmt_mod->execute([$modelo_checklist]);
                        $modelos = $stmt_mod->fetchAll(PDO::FETCH_ASSOC);

                        if (!empty($modelos)) {
                            $stmt_ins_task = $pdo->prepare("
                                INSERT INTO checklist (evento_id, etapa, tarefa, descricao, origem, status)
                                VALUES (?, ?, ?, ?, 'Assessoria', 'pendente')
                            ");
                            foreach ($modelos as $m) {
                                $stmt_ins_task->execute([$evento_id, $m['etapa'], $m['tarefa'], $m['descricao']]);
                            }
                        }
                    }

                    $pdo->commit();
                    $_SESSION['msg_sucesso'] = "Casal, contrato e cronograma configurados com sucesso!";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log("[CADASTRAR EVENTO] " . $e->getMessage());
                    $_SESSION['msg_erro'] = "Erro interno ao cadastrar o casamento. Tente novamente.";
                }
            }
        } else {
            $_SESSION['msg_erro'] = "Preencha todos os campos obrigatórios.";
        }
        header("Location: painel_admin.php");
        exit;
    }

    // 5. RESETAR SENHA
    if (isset($_POST['resetar_senha'])) {
        validar_csrf();
        $cliente_id = (int)($_POST['cliente_id'] ?? 0);
        $nova_senha = trim($_POST['nova_senha'] ?? '');

        if ($cliente_id > 0 && !empty($nova_senha)) {
            $nova_senha_hash = password_hash($nova_senha, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE clientes SET senha = ? WHERE id = ?")->execute([$nova_senha_hash, $cliente_id]);
            $_SESSION['msg_sucesso'] = "Senha redefinida com sucesso para o cliente!";
        } else {
            $_SESSION['msg_erro'] = "A nova senha não pode estar vazia.";
        }
        header("Location: painel_admin.php");
        exit;
    }

    // 6. EDITAR DATA
    if (isset($_POST['editar_data'])) {
        validar_csrf();
        $evento_id = (int)($_POST['evento_id_data'] ?? 0);
        $nova_data = $_POST['nova_data'] ?? '';
        $nova_hora = !empty($_POST['nova_hora']) ? $_POST['nova_hora'] : null;

        if ($evento_id > 0 && !empty($nova_data) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $nova_data)) {
            $pdo->prepare("UPDATE eventos SET data_evento = ?, hora_evento = ? WHERE id = ?")->execute([$nova_data, $nova_hora, $evento_id]);
            $_SESSION['msg_sucesso'] = "Data e horário do evento alterados com sucesso!";
        } else {
            $_SESSION['msg_erro'] = "A nova data informada é inválida.";
        }
        header("Location: painel_admin.php");
        exit;
    }

    // 7. EDITAR CADASTRO DO CASAL
    if (isset($_POST['editar_cadastro_casal'])) {
        validar_csrf();
        $cliente_id       = (int)($_POST['cliente_id_cadastro'] ?? 0);
        $nome_cliente     = trim($_POST['nome_cadastro'] ?? '');
        $email_cliente    = trim($_POST['email_cadastro'] ?? '');
        $telefone_cliente = trim($_POST['telefone_cadastro'] ?? '');

        if ($cliente_id > 0 && !empty($nome_cliente) && filter_var($email_cliente, FILTER_VALIDATE_EMAIL)) {
            $pdo->prepare("UPDATE clientes SET nome = ?, email = ?, telefone = ? WHERE id = ?")
                ->execute([$nome_cliente, $email_cliente, $telefone_cliente, $cliente_id]);
            $_SESSION['msg_sucesso'] = "Dados do casal atualizados com sucesso!";
        } else {
            $_SESSION['msg_erro'] = "Preencha o nome e um e-mail válido para o casal.";
        }
        header("Location: painel_admin.php");
        exit;
    }
}

// ============================================================
// 8. CARREGAMENTO DE DADOS PARA A ESTRUTURA VISUAL
// ============================================================
$lista_casamentos = $pdo->query("
    SELECT e.id AS evento_id, e.data_evento, e.hora_evento,
           c.id AS cliente_id, c.nome AS nome_noivos, c.email AS email_noivos, c.telefone AS telefone_noivos
    FROM eventos e
    INNER JOIN clientes c ON e.cliente_id = c.id
    ORDER BY e.data_evento ASC, e.hora_evento ASC
")->fetchAll();

$eventos_por_data = [];
$casamentos_realizados = [];
$casamentos_futuros = [];

foreach ($lista_casamentos as $cas) {
    $eventos_por_data[$cas['data_evento']][] = $cas;
    if ($cas['data_evento'] < $data_hoje) {
        $casamentos_realizados[] = $cas;
    } else {
        $casamentos_futuros[] = $cas;
    }
}

// Carrega TODAS as anotações do mês (múltiplas por dia, ordenadas por horário)
$stmt_notas = $pdo->prepare("
    SELECT id, data_nota, hora_nota, anotacao 
    FROM calendario_anotacoes 
    WHERE data_nota LIKE ? 
    ORDER BY data_nota ASC, hora_nota ASC
");
$stmt_notas->execute(["$ano_atual-$mes_atual_str-%"]);
$notas_do_mes_raw = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

// Agrupa as anotações por data (pode ter múltiplas por dia)
$anotacoes_do_mes = []; 
foreach ($notas_do_mes_raw as $nota) {
    $anotacoes_do_mes[$nota['data_nota']][] = $nota;
}

$numero_dias_mes     = (int)date('t', strtotime("$ano_atual-$mes_atual_str-01"));
$primeiro_dia_semana = (int)date('w', strtotime("$ano_atual-$mes_atual_str-01"));

$casamentos_feitos_no_mes = 0;
foreach ($lista_casamentos as $cas) {
    $dt = new DateTimeImmutable($cas['data_evento']);
    if ((int)$dt->format('m') === $mes_atual && (int)$dt->format('Y') === $ano_atual && $cas['data_evento'] < $data_hoje) {
        $casamentos_feitos_no_mes++;
    }
}

$meses_pt = [
    '01' => 'Janeiro', '02' => 'Fevereiro', '03' => 'Março', '04' => 'Abril', '05' => 'Maio', '06' => 'Junho',
    '07' => 'Julho', '08' => 'Agosto', '09' => 'Setembro', '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro'
];

$msg_erro_session    = $_SESSION['msg_erro'] ?? "";
$msg_sucesso_session = $_SESSION['msg_sucesso'] ?? "";
unset($_SESSION['msg_erro'], $_SESSION['msg_sucesso']);

// Notificações globais (atividade dos noivos em todos os casamentos)
$notificacoes = buscar_notificacoes($pdo, null, 15);
$ultima_vista = ultima_visualizacao_notificacoes($pdo, $_SESSION['usuario_tipo'], (int)($_SESSION['usuario_id'] ?? 0));
$nao_lidas    = contar_nao_lidas($notificacoes, $ultima_vista);
$notificacoes = array_values(array_filter($notificacoes, fn($item) => !$ultima_vista || $item['quando'] > $ultima_vista));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel da Assessoria - Meu Evento PRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/estilo.css?v=13">
    <style>
        .navbar .container.flex-nowrap { flex-wrap: nowrap; }
        .logo-nav-admin { height: 40px; flex-shrink: 0; }
        .barra-icones-admin { flex-shrink: 1; }
        .btn-icon-nav {
            width: 38px; height: 38px; padding: 0; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
        }

        @media (max-width: 480px) {
            .logo-nav-admin { height: 34px; }
            .btn-icon-nav { width: 32px; height: 32px; font-size: .85rem; }
            .barra-icones-admin { gap: .35rem !important; }
        }

        .nav-tabs .nav-link { color: #6c757d; font-weight: 500; }
        .nav-tabs .nav-link.active { color: #0d6efd; font-weight: bold; border-bottom: 3px solid #0d6efd; background-color: transparent;}
        .celula-dia { cursor: pointer; transition: background 0.2s; }
        .celula-dia:hover { background-color: #f8f9fa; }
        .indicador-nota { position: absolute; bottom: 4px; right: 4px; width: 8px; height: 8px; background-color: #0d6efd; border-radius: 50%; }
        .toast-container { z-index: 1060; }

        .btn-novo-casamento {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .btn-novo-casamento:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,.25) !important;
        }

        @media (max-width: 767.98px) {
            .badges-resumo-painel { gap: .4rem !important; overflow-x: auto; -webkit-overflow-scrolling: touch; font-size: .75rem !important; justify-content: center; }
            .badges-resumo-painel span { padding: .3rem .55rem !important; }

            .titulo-painel-wrap { width: 100%; text-align: center; }
        }

        /* Estilos do modal de anotações por horário */
        .nota-item {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 10px;
            position: relative;
            transition: box-shadow 0.15s;
        }
        .nota-item:hover { box-shadow: 0 2px 8px rgba(13,110,253,0.10); }
        .nota-item .hora-badge {
            display: inline-block;
            background: #0d6efd;
            color: #fff;
            font-size: 0.78rem;
            font-weight: 700;
            border-radius: 20px;
            padding: 2px 10px;
            margin-bottom: 6px;
            letter-spacing: 0.5px;
        }
        .nota-item .hora-badge.sem-hora {
            background: #6c757d;
        }

        /* ---- CLASSES CSS PARA TEXTO TRUNCADO E BOTÃO ---- */
        .nota-item-texto {
            font-size: 0.88rem;
            color: #343a40;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .nota-truncada {
            display: -webkit-box;
            -webkit-line-clamp: 2; /* Mostra no máximo 2 linhas */
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .nota-expandida {
            display: block;
        }
        .btn-ler-mais {
            font-size: 0.75rem;
            cursor: pointer;
            user-select: none;
            color: #0d6efd;
            font-weight: bold;
            display: inline-block;
            margin-top: 4px;
        }
        .btn-ler-mais:hover {
            text-decoration: underline;
        }
        /* ------------------------------------------------ */

        .btn-editar-nota, .btn-excluir-nota {
            font-size: 0.78rem;
            padding: 2px 8px;
        }
        .form-nova-nota {
            background: #eaf1ff;
            border: 1.5px dashed #0d6efd;
            border-radius: 10px;
            padding: 14px;
            margin-top: 10px;
        }
        #lista-notas-dia:empty::after {
            content: 'Nenhuma anotação para este dia. Adicione abaixo!';
            color: #adb5bd;
            font-size: 0.85rem;
            display: block;
            text-align: center;
            padding: 18px 0 10px;
        }

        /* ---- SINO DE NOTIFICAÇÕES: evita o dropdown estourar a tela em celular ---- */
        @media (max-width: 480px) {
            #dropdown-notificacoes .dropdown-menu.show {
                position: fixed !important;
                inset: auto 0.75rem auto 0.75rem !important;
                top: 4.5rem !important;
                transform: none !important;
                width: auto !important;
                max-width: none !important;
            }
        }

        /* ==================================================================
           AJUSTES MOBILE
           ================================================================== */
        @media (max-width: 767.98px) {
            /* Navbar: logo menor e botões podem quebrar linha sem estourar a tela */
            .navbar .container {
                flex-wrap: wrap;
                row-gap: 0.5rem;
            }
            .navbar-brand img { height: 26px !important; }
            .navbar .d-flex.align-items-center.gap-2 {
                flex-wrap: wrap;
                justify-content: flex-end;
                gap: 0.35rem !important;
            }

            /* Cabeçalho do painel: botão "Novo Casamento" ocupa a largura toda */
            .container.my-4 > .card .p-4.d-flex.justify-content-between {
                flex-direction: column;
                align-items: stretch !important;
            }
            .container.my-4 > .card .p-4.d-flex.justify-content-between > button {
                width: 100%;
            }

            /* Calendário: reduz células para caber sem cortar */
            .table-calendario th, .table-calendario td { padding: 0.25rem !important; }
            .table-calendario td { height: 40px !important; width: 40px !important; }
            .table-calendario td span { width: 26px !important; height: 26px !important; font-size: 0.8rem; }
        }

        @media (max-width: 400px) {
            .navbar-brand img { height: 22px !important; }
            .table-calendario td { height: 36px !important; width: 36px !important; }
            .table-calendario td span { width: 24px !important; height: 24px !important; font-size: 0.75rem; }
        }
    </style>
</head>
<body class="bg-light">

<div class="toast-container position-fixed top-0 end-0 p-3">
    <?php if (!empty($msg_sucesso_session)): ?>
    <div class="toast align-items-center text-bg-success border-0 show" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="4000">
        <div class="d-flex">
            <div class="toast-body fw-bold">
                <i class="bi bi-check-circle-fill me-2"></i> <?= htmlspecialchars($msg_sucesso_session) ?>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($msg_erro_session)): ?>
    <div class="toast align-items-center text-bg-danger border-0 show" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="5000">
        <div class="d-flex">
            <div class="toast-body fw-bold">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($msg_erro_session) ?>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
    <?php endif; ?>
</div>

<nav class="navbar navbar-dark bg-dark shadow-sm">
    <div class="container flex-nowrap">
        <span class="navbar-brand flex-shrink-0">
            <img src="img/LOGO MEP NAV.svg" alt="Meu Evento PRO" class="logo-nav-admin">
        </span>
        <div class="d-flex align-items-center gap-2 barra-icones-admin">
            <span class="text-white small d-none d-lg-block text-nowrap">
                <i class="bi bi-person-circle"></i> Logado como:
                <strong><?= $is_admin ? 'Assessoria Geral' : 'Assistente' ?></strong>
            </span>
            <div class="dropdown" id="dropdown-notificacoes">
                <button class="btn btn-sm btn-outline-light rounded-circle position-relative btn-icon-nav" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-bell-fill"></i>
                    <?php if ($nao_lidas > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem;">
                            <?= $nao_lidas > 9 ? '9+' : $nao_lidas ?>
                        </span>
                    <?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-lg border-0 p-0" style="width:360px;max-height:440px;overflow-y:auto;">
                    <div class="px-3 py-2 border-bottom bg-light d-flex justify-content-between align-items-center">
                        <span class="fw-bold small text-uppercase text-muted"><i class="bi bi-bell me-1"></i> Atividade dos casais</span>
                        <button type="button" id="btn-marcar-lidas" class="btn btn-link btn-sm p-0 text-decoration-none">Marcar lidas</button>
                    </div>
                    <div id="lista-notificacoes">
                    <?php if (empty($notificacoes)): ?>
                        <div class="text-center text-muted p-4 small">
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i> Nenhuma atividade ainda.
                        </div>
                    <?php else: foreach ($notificacoes as $n): ?>
                        <a href="gerenciar.php?id=<?= (int)$n['evento_id'] ?>" class="notif-item d-flex align-items-start gap-2 px-3 py-2 border-bottom text-decoration-none">
                            <i class="bi <?= htmlspecialchars($n['icone'], ENT_QUOTES, 'UTF-8') ?> mt-1"></i>
                            <div class="flex-fill" style="min-width:0;">
                                <div class="small fw-bold text-dark"><?= htmlspecialchars($n['evento_nome'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="small text-body"><?= htmlspecialchars($n['texto'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="text-muted" style="font-size:.7rem;"><?= tempo_relativo($n['quando']) ?></div>
                            </div>
                        </a>
                    <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($is_admin): ?>
            <a href="gerenciar_equipe.php" class="btn btn-sm btn-warning fw-bold text-dark border-0 shadow-sm">
                <i class="bi bi-people-fill"></i> <span class="d-none d-sm-inline">Equipe</span>
            </a>
            <?php endif; ?>

            <a href="referencias.php" class="btn btn-sm btn-light fw-bold text-dark border-0">
                <i class="bi bi-geo-alt-fill text-secondary"></i> <span class="d-none d-sm-inline">Referências</span>
            </a>
            <a href="modelos_checklist.php" class="btn btn-sm btn-light fw-bold text-dark border-0">
                <i class="bi bi-gear-fill text-secondary"></i> <span class="d-none d-sm-inline">Checklists</span>
            </a>
            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalConfirmarSaida">
                <i class="bi bi-box-arrow-right"></i> <span class="d-none d-sm-inline">Sair</span>
            </button>
        </div>
    </div>
</nav>

<div class="container my-4">

    <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="p-4 d-flex justify-content-between align-items-center flex-wrap gap-3" style="background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%); color: white;">
                <div class="titulo-painel-wrap">
                    <h2 class="fw-bold mb-1" style="letter-spacing: -0.5px;">
                        <i class="bi bi-calendar-heart text-warning me-2"></i> Painel da Assessoria
                    </h2>
                    <p class="mb-3 text-white-50">Bem-vinda! Aqui está o resumo geral dos seus eventos e compromissos.</p>

                    <div class="d-flex flex-nowrap gap-2 mt-2 badges-resumo-painel" style="font-size: 0.90rem;">
                        <span class="bg-white bg-opacity-10 px-3 py-1 rounded-pill shadow-sm text-nowrap">
                            <i class="bi bi-calendar3 me-1 text-info"></i> <?= date('d/m/Y') ?>
                        </span>
                        <span class="bg-white bg-opacity-10 px-3 py-1 rounded-pill shadow-sm text-nowrap">
                            <i class="bi bi-heart-pulse-fill me-1 text-danger"></i> <strong><?= count($casamentos_futuros) ?></strong> Ativos
                        </span>
                        <span class="bg-white bg-opacity-10 px-3 py-1 rounded-pill shadow-sm text-nowrap">
                            <i class="bi bi-check2-circle me-1 text-success"></i> <strong><?= count($casamentos_realizados) ?></strong> Realizados
                        </span>
                    </div>
                </div>
                
                <button type="button" class="btn btn-novo-casamento btn-light fw-bold rounded-pill shadow px-4 py-2" data-bs-toggle="modal" data-bs-target="#modalNovoCasamento" style="color:var(--color-primary-dark); font-size:1rem;">
                    <i class="bi bi-plus-lg me-2"></i> Novo Casamento
                </button>
            </div>
        </div>
    </div>

    <div class="row g-4">
        
        <div class="col-xl-7 col-lg-12">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-bottom pt-3 pb-0">
                    <ul class="nav nav-tabs border-0 flex-nowrap" id="eventoTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="futuros-tab" data-bs-toggle="tab" data-bs-target="#futuros" type="button" role="tab">
                                <i class="bi bi-calendar-event me-1"></i> Próximos 
                                <span class="badge bg-primary ms-1"><?= count($casamentos_futuros) ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="historico-tab" data-bs-toggle="tab" data-bs-target="#historico" type="button" role="tab">
                                <i class="bi bi-clock-history me-1"></i> Histórico
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="buscar-tab" data-bs-toggle="tab" data-bs-target="#buscar" type="button" role="tab" title="Buscar Noivos" aria-label="Buscar Noivos">
                                <i class="bi bi-search"></i>
                            </button>
                        </li>
                        <li class="nav-item d-md-none" role="presentation">
                            <button class="nav-link" type="button" id="btn-ir-calendario" title="Ir para o Calendário" aria-label="Ir para o Calendário">
                                <i class="bi bi-calendar3"></i>
                            </button>
                        </li>
                    </ul>
                </div>
                
                <div class="card-body p-0">
                    <div class="tab-content" id="eventoTabsContent">
                        
                        <div class="tab-pane fade show active p-3" id="futuros" role="tabpanel">

                            <!-- Visão mobile: cards empilhados (evita tabela cortando ações no scroll horizontal) -->
                            <div class="d-md-none">
                                <?php if (empty($casamentos_futuros)): ?>
                                    <p class="text-center text-muted py-5"><i class="bi bi-inbox fs-3 d-block mb-2"></i>Nenhum casamento futuro agendado.</p>
                                <?php else: ?>
                                    <?php foreach ($casamentos_futuros as $i => $cas): ?>
                                    <div class="border rounded-3 p-3 mb-2<?= $i >= 5 ? ' d-none casamento-extra-futuros' : '' ?>">
                                        <span class="text-dark fw-bold fs-6 d-block mb-1"><?= htmlspecialchars($cas['nome_noivos']) ?></span>
                                        <div class="d-flex justify-content-between align-items-start gap-2">
                                            <div class="text-muted" style="font-size: 0.8rem;">
                                                <i class="bi bi-envelope"></i> <?= htmlspecialchars($cas['email_noivos']) ?><br>
                                                <?php if (!empty($cas['telefone_noivos'])): ?>
                                                    <i class="bi bi-whatsapp text-success"></i> <?= htmlspecialchars($cas['telefone_noivos']) ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="badge bg-primary bg-opacity-10 text-primary p-2 border border-primary border-opacity-25 rounded-3 text-start position-relative flex-shrink-0" style="min-width: 110px;">
                                                <i class="bi bi-calendar3 me-1"></i> <?= date('d/m/Y', strtotime($cas['data_evento'])) ?>
                                                <?php if (!empty($cas['hora_evento'])): ?>
                                                    <br><i class="bi bi-clock me-1"></i> <?= date('H:i', strtotime($cas['hora_evento'])) ?>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-sm btn-link text-primary p-0 position-absolute bottom-0 end-0 me-2 mb-1 btn-abrir-modal-data"
                                                        data-evento-id="<?= (int)$cas['evento_id'] ?>" data-data="<?= htmlspecialchars($cas['data_evento']) ?>" data-hora="<?= htmlspecialchars($cas['hora_evento'] ?? '') ?>"
                                                        data-bs-toggle="modal" data-bs-target="#modalEditarData" title="Editar Data">
                                                    <i class="bi bi-pencil-square"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="d-flex justify-content-center flex-wrap gap-1 mt-2">
                                            <button type="button" class="btn btn-sm btn-light border fw-bold text-warning btn-abrir-modal-senha" data-cliente-id="<?= (int)$cas['cliente_id'] ?>" data-bs-toggle="modal" data-bs-target="#modalResetSenha" title="Resetar Senha"><i class="bi bi-key"></i></button>
                                            <button type="button" class="btn btn-sm btn-light border fw-bold text-primary btn-abrir-modal-cadastro" data-cliente-id="<?= (int)$cas['cliente_id'] ?>" data-nome="<?= htmlspecialchars($cas['nome_noivos'], ENT_QUOTES, 'UTF-8') ?>" data-email="<?= htmlspecialchars($cas['email_noivos'], ENT_QUOTES, 'UTF-8') ?>" data-telefone="<?= htmlspecialchars($cas['telefone_noivos'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#modalEditarCadastro" title="Editar Cadastro"><i class="bi bi-pencil-square"></i></button>
                                            <a href="gerenciar.php?id=<?= (int)$cas['evento_id'] ?>" class="btn btn-sm btn-primary shadow-sm" title="Gerenciar"><i class="bi bi-gear-fill"></i></a>
                                            <a href="relatorio_pdf.php?id=<?= (int)$cas['evento_id'] ?>&secoes=todos" target="_blank" class="btn btn-sm btn-outline-danger shadow-sm" title="Exportar PDF"><i class="bi bi-file-earmark-pdf-fill"></i></a>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Tem certeza que deseja excluir o evento de <?= htmlspecialchars($cas['nome_noivos']) ?>? Todos os dados serão perdidos!');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                <input type="hidden" name="evento_id" value="<?= (int)$cas['evento_id'] ?>">
                                                <button type="submit" name="excluir_evento" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <!-- Visão desktop: tabela -->
                            <div class="table-responsive d-none d-md-block">
                                <table class="table table-hover align-middle mb-0 small">
                                    <thead class="table-light text-muted">
                                        <tr>
                                            <th>Casal & Contatos</th>
                                            <th width="25%">Data</th>
                                            <th width="20%" class="text-center">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($casamentos_futuros)): ?>
                                        <tr><td colspan="3" class="text-center text-muted py-5"><i class="bi bi-inbox fs-3 d-block mb-2"></i>Nenhum casamento futuro agendado.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($casamentos_futuros as $i => $cas): ?>
                                        <tr class="<?= $i >= 5 ? 'd-none casamento-extra-futuros' : '' ?>">
                                            <td>
                                                <span class="text-dark fw-bold fs-6"><?= htmlspecialchars($cas['nome_noivos']) ?></span><br>
                                                <div class="text-muted mt-1" style="font-size: 0.8rem;">
                                                    <i class="bi bi-envelope"></i> <?= htmlspecialchars($cas['email_noivos']) ?><br>
                                                    <?php if (!empty($cas['telefone_noivos'])): ?>
                                                        <i class="bi bi-whatsapp text-success"></i> <?= htmlspecialchars($cas['telefone_noivos']) ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="badge bg-primary bg-opacity-10 text-primary p-2 border border-primary border-opacity-25 rounded-3 text-start w-100 position-relative">
                                                    <i class="bi bi-calendar3 me-1"></i> <?= date('d/m/Y', strtotime($cas['data_evento'])) ?>
                                                    <?php if (!empty($cas['hora_evento'])): ?>
                                                        <br><i class="bi bi-clock me-1"></i> <?= date('H:i', strtotime($cas['hora_evento'])) ?>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-sm btn-link text-primary p-0 position-absolute bottom-0 end-0 me-2 mb-1 btn-abrir-modal-data"
                                                            data-evento-id="<?= (int)$cas['evento_id'] ?>" data-data="<?= htmlspecialchars($cas['data_evento']) ?>" data-hora="<?= htmlspecialchars($cas['hora_evento'] ?? '') ?>"
                                                            data-bs-toggle="modal" data-bs-target="#modalEditarData" title="Editar Data">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="d-flex justify-content-center gap-1">
                                                    <button type="button" class="btn btn-sm btn-light border fw-bold text-warning btn-abrir-modal-senha" data-cliente-id="<?= (int)$cas['cliente_id'] ?>" data-bs-toggle="modal" data-bs-target="#modalResetSenha" title="Resetar Senha"><i class="bi bi-key"></i></button>
                                                    <button type="button" class="btn btn-sm btn-light border fw-bold text-primary btn-abrir-modal-cadastro" data-cliente-id="<?= (int)$cas['cliente_id'] ?>" data-nome="<?= htmlspecialchars($cas['nome_noivos'], ENT_QUOTES, 'UTF-8') ?>" data-email="<?= htmlspecialchars($cas['email_noivos'], ENT_QUOTES, 'UTF-8') ?>" data-telefone="<?= htmlspecialchars($cas['telefone_noivos'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#modalEditarCadastro" title="Editar Cadastro"><i class="bi bi-pencil-square"></i></button>
                                                    <a href="gerenciar.php?id=<?= (int)$cas['evento_id'] ?>" class="btn btn-sm btn-primary shadow-sm" title="Gerenciar"><i class="bi bi-gear-fill"></i></a>
                                                    <a href="relatorio_pdf.php?id=<?= (int)$cas['evento_id'] ?>&secoes=todos" target="_blank" class="btn btn-sm btn-outline-danger shadow-sm" title="Exportar PDF"><i class="bi bi-file-earmark-pdf-fill"></i></a>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Tem certeza que deseja excluir o evento de <?= htmlspecialchars($cas['nome_noivos']) ?>? Todos os dados serão perdidos!');">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                        <input type="hidden" name="evento_id" value="<?= (int)$cas['evento_id'] ?>">
                                                        <button type="submit" name="excluir_evento" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if (count($casamentos_futuros) > 5): ?>
                            <div class="text-center mt-2 mb-1">
                                <button type="button" class="btn rounded-pill btn-ver-mais-casamentos" data-alvo="casamento-extra-futuros">
                                    <i class="bi bi-chevron-down me-1"></i> Ver mais <span class="text-muted">(<?= count($casamentos_futuros) - 5 ?>)</span>
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="tab-pane fade p-3" id="historico" role="tabpanel">

                            <!-- Visão mobile: cards empilhados -->
                            <div class="d-md-none opacity-75">
                                <?php if (empty($casamentos_realizados)): ?>
                                    <p class="text-center text-muted py-5"><i class="bi bi-inbox fs-3 d-block mb-2"></i>Nenhum histórico disponível.</p>
                                <?php else: ?>
                                    <?php foreach ($casamentos_realizados as $i => $cas): ?>
                                    <div class="border rounded-3 p-3 mb-2<?= $i >= 5 ? ' d-none casamento-extra-historico' : '' ?>">
                                        <span class="text-dark fw-bold"><?= htmlspecialchars($cas['nome_noivos']) ?></span>
                                        <div class="text-muted mt-1" style="font-size: 0.8rem;">
                                            <i class="bi bi-envelope"></i> <?= htmlspecialchars($cas['email_noivos']) ?>
                                        </div>
                                        <span class="badge bg-light text-secondary border p-2 w-100 text-start mt-2">
                                            <i class="bi bi-calendar-check me-1"></i> <?= date('d/m/Y', strtotime($cas['data_evento'])) ?>
                                        </span>
                                        <div class="d-flex justify-content-center flex-wrap gap-1 mt-2">
                                            <a href="gerenciar.php?id=<?= (int)$cas['evento_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Ver Arquivo"><i class="bi bi-folder2-open"></i></a>
                                            <a href="relatorio_pdf.php?id=<?= (int)$cas['evento_id'] ?>&secoes=todos" target="_blank" class="btn btn-sm btn-outline-danger" title="Exportar PDF"><i class="bi bi-file-earmark-pdf-fill"></i></a>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Excluir histórico de <?= htmlspecialchars($cas['nome_noivos']) ?>?');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                <input type="hidden" name="evento_id" value="<?= (int)$cas['evento_id'] ?>">
                                                <button type="submit" name="excluir_evento" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <!-- Visão desktop: tabela -->
                            <div class="table-responsive d-none d-md-block">
                                <table class="table table-hover align-middle mb-0 small opacity-75">
                                    <thead class="table-light text-muted">
                                        <tr>
                                            <th>Casal & Contatos</th>
                                            <th width="25%">Data Realizada</th>
                                            <th width="20%" class="text-center">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php if (empty($casamentos_realizados)): ?>
                                        <tr><td colspan="3" class="text-center text-muted py-5"><i class="bi bi-inbox fs-3 d-block mb-2"></i>Nenhum histórico disponível.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($casamentos_realizados as $i => $cas): ?>
                                        <tr class="<?= $i >= 5 ? 'd-none casamento-extra-historico' : '' ?>">
                                            <td>
                                                <span class="text-dark fw-bold"><?= htmlspecialchars($cas['nome_noivos']) ?></span><br>
                                                <div class="text-muted mt-1" style="font-size: 0.8rem;">
                                                    <i class="bi bi-envelope"></i> <?= htmlspecialchars($cas['email_noivos']) ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-secondary border p-2 w-100 text-start">
                                                    <i class="bi bi-calendar-check me-1"></i> <?= date('d/m/Y', strtotime($cas['data_evento'])) ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <div class="d-flex justify-content-center gap-1">
                                                    <a href="gerenciar.php?id=<?= (int)$cas['evento_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Ver Arquivo"><i class="bi bi-folder2-open"></i></a>
                                                    <a href="relatorio_pdf.php?id=<?= (int)$cas['evento_id'] ?>&secoes=todos" target="_blank" class="btn btn-sm btn-outline-danger" title="Exportar PDF"><i class="bi bi-file-earmark-pdf-fill"></i></a>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Excluir histórico de <?= htmlspecialchars($cas['nome_noivos']) ?>?');">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                        <input type="hidden" name="evento_id" value="<?= (int)$cas['evento_id'] ?>">
                                                        <button type="submit" name="excluir_evento" class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if (count($casamentos_realizados) > 5): ?>
                            <div class="text-center mt-2 mb-1">
                                <button type="button" class="btn rounded-pill btn-ver-mais-casamentos" data-alvo="casamento-extra-historico">
                                    <i class="bi bi-chevron-down me-1"></i> Ver mais <span class="text-muted">(<?= count($casamentos_realizados) - 5 ?>)</span>
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="tab-pane fade p-3" id="buscar" role="tabpanel">

                            <div class="mb-3">
                                <div class="input-group">
                                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                                    <input type="text" id="busca-noivos" class="form-control border-start-0 ps-0" placeholder="Buscar por nome, e-mail ou telefone...">
                                </div>
                            </div>

                            <?php if (empty($lista_casamentos)): ?>
                                <p class="text-center text-muted py-5"><i class="bi bi-inbox fs-3 d-block mb-2"></i>Nenhum casal cadastrado.</p>
                            <?php else: ?>

                            <p class="text-center text-muted py-5 busca-noivos-vazio" style="display:none;"><i class="bi bi-search fs-3 d-block mb-2"></i>Nenhum casal encontrado.</p>

                            <!-- Visão mobile: cards empilhados -->
                            <div class="d-md-none">
                                <?php foreach ($lista_casamentos as $cas):
                                    $realizado = $cas['data_evento'] < $data_hoje;
                                    $termo_busca = mb_strtolower($cas['nome_noivos'] . ' ' . $cas['email_noivos'] . ' ' . ($cas['telefone_noivos'] ?? ''), 'UTF-8');
                                ?>
                                <div class="border rounded-3 p-3 mb-2 linha-busca-noivos" data-search="<?= htmlspecialchars($termo_busca, ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <span class="text-dark fw-bold fs-6 d-block mb-1"><?= htmlspecialchars($cas['nome_noivos']) ?></span>
                                        <span class="badge <?= $realizado ? 'bg-secondary' : 'bg-primary' ?> bg-opacity-75 flex-shrink-0"><?= $realizado ? 'Realizado' : 'Futuro' ?></span>
                                    </div>
                                    <div class="text-muted" style="font-size: 0.8rem;">
                                        <i class="bi bi-envelope"></i> <?= htmlspecialchars($cas['email_noivos']) ?><br>
                                        <?php if (!empty($cas['telefone_noivos'])): ?>
                                            <i class="bi bi-whatsapp text-success"></i> <?= htmlspecialchars($cas['telefone_noivos']) ?><br>
                                        <?php endif; ?>
                                        <i class="bi bi-calendar3"></i> <?= date('d/m/Y', strtotime($cas['data_evento'])) ?>
                                    </div>
                                    <div class="d-flex justify-content-center flex-wrap gap-1 mt-2">
                                        <button type="button" class="btn btn-sm btn-light border fw-bold text-warning btn-abrir-modal-senha" data-cliente-id="<?= (int)$cas['cliente_id'] ?>" data-bs-toggle="modal" data-bs-target="#modalResetSenha" title="Resetar Senha"><i class="bi bi-key"></i></button>
                                        <button type="button" class="btn btn-sm btn-light border fw-bold text-primary btn-abrir-modal-cadastro" data-cliente-id="<?= (int)$cas['cliente_id'] ?>" data-nome="<?= htmlspecialchars($cas['nome_noivos'], ENT_QUOTES, 'UTF-8') ?>" data-email="<?= htmlspecialchars($cas['email_noivos'], ENT_QUOTES, 'UTF-8') ?>" data-telefone="<?= htmlspecialchars($cas['telefone_noivos'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#modalEditarCadastro" title="Editar Cadastro"><i class="bi bi-pencil-square"></i></button>
                                        <a href="gerenciar.php?id=<?= (int)$cas['evento_id'] ?>" class="btn btn-sm btn-primary shadow-sm" title="Gerenciar"><i class="bi bi-gear-fill"></i></a>
                                        <a href="relatorio_pdf.php?id=<?= (int)$cas['evento_id'] ?>&secoes=todos" target="_blank" class="btn btn-sm btn-outline-danger shadow-sm" title="Exportar PDF"><i class="bi bi-file-earmark-pdf-fill"></i></a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Visão desktop: tabela -->
                            <div class="table-responsive d-none d-md-block">
                                <table class="table table-hover align-middle mb-0 small">
                                    <thead class="table-light text-muted">
                                        <tr>
                                            <th>Casal & Contatos</th>
                                            <th width="15%">Status</th>
                                            <th width="20%">Data</th>
                                            <th width="20%" class="text-center">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($lista_casamentos as $cas):
                                        $realizado = $cas['data_evento'] < $data_hoje;
                                        $termo_busca = mb_strtolower($cas['nome_noivos'] . ' ' . $cas['email_noivos'] . ' ' . ($cas['telefone_noivos'] ?? ''), 'UTF-8');
                                    ?>
                                    <tr class="linha-busca-noivos" data-search="<?= htmlspecialchars($termo_busca, ENT_QUOTES, 'UTF-8') ?>">
                                        <td>
                                            <span class="text-dark fw-bold fs-6"><?= htmlspecialchars($cas['nome_noivos']) ?></span><br>
                                            <div class="text-muted mt-1" style="font-size: 0.8rem;">
                                                <i class="bi bi-envelope"></i> <?= htmlspecialchars($cas['email_noivos']) ?><br>
                                                <?php if (!empty($cas['telefone_noivos'])): ?>
                                                    <i class="bi bi-whatsapp text-success"></i> <?= htmlspecialchars($cas['telefone_noivos']) ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge <?= $realizado ? 'bg-secondary' : 'bg-primary' ?> bg-opacity-75"><?= $realizado ? 'Realizado' : 'Futuro' ?></span>
                                        </td>
                                        <td>
                                            <i class="bi bi-calendar3 me-1"></i> <?= date('d/m/Y', strtotime($cas['data_evento'])) ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="d-flex justify-content-center gap-1">
                                                <button type="button" class="btn btn-sm btn-light border fw-bold text-warning btn-abrir-modal-senha" data-cliente-id="<?= (int)$cas['cliente_id'] ?>" data-bs-toggle="modal" data-bs-target="#modalResetSenha" title="Resetar Senha"><i class="bi bi-key"></i></button>
                                                <button type="button" class="btn btn-sm btn-light border fw-bold text-primary btn-abrir-modal-cadastro" data-cliente-id="<?= (int)$cas['cliente_id'] ?>" data-nome="<?= htmlspecialchars($cas['nome_noivos'], ENT_QUOTES, 'UTF-8') ?>" data-email="<?= htmlspecialchars($cas['email_noivos'], ENT_QUOTES, 'UTF-8') ?>" data-telefone="<?= htmlspecialchars($cas['telefone_noivos'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#modalEditarCadastro" title="Editar Cadastro"><i class="bi bi-pencil-square"></i></button>
                                                <a href="gerenciar.php?id=<?= (int)$cas['evento_id'] ?>" class="btn btn-sm btn-primary shadow-sm" title="Gerenciar"><i class="bi bi-gear-fill"></i></a>
                                                <a href="relatorio_pdf.php?id=<?= (int)$cas['evento_id'] ?>&secoes=todos" target="_blank" class="btn btn-sm btn-outline-danger shadow-sm" title="Exportar PDF"><i class="bi bi-file-earmark-pdf-fill"></i></a>
                                            </div>
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
            </div>
        </div>

        <div class="col-xl-5 col-lg-12">
            <div class="card border-0 shadow-sm rounded-4 p-4 h-100" id="calendario-wrapper" style="transition: opacity 0.3s ease;">
                
                <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-3">
                    <h5 class="mb-0 text-dark fw-bold">
                        <i class="bi bi-calendar3 text-primary"></i> <?= $meses_pt[$mes_atual_str] ?> <?= $ano_atual ?>
                    </h5>
                    <div class="btn-group shadow-sm">
                        <a href="painel_admin.php?mes=<?= $mes_anterior ?>&ano=<?= $ano_anterior ?>" class="btn btn-sm btn-outline-secondary btn-nav-calendario" title="Mês Anterior"><i class="bi bi-chevron-left"></i></a>
                        <a href="painel_admin.php" class="btn btn-sm btn-light border text-secondary font-monospace" style="font-size:0.75rem;">Hoje</a>
                        <a href="painel_admin.php?mes=<?= $mes_seguinte ?>&ano=<?= $ano_seguinte ?>" class="btn btn-sm btn-outline-secondary btn-nav-calendario" title="Próximo Mês"><i class="bi bi-chevron-right"></i></a>
                    </div>
                </div>

                <div class="d-flex justify-content-center gap-3 small mb-3 text-muted">
                    <span class="d-flex align-items-center gap-1"><span class="rounded-circle" style="width:10px; height:10px; background-color:#198754;"></span> A Fazer</span>
                    <span class="d-flex align-items-center gap-1"><span class="rounded-circle" style="width:10px; height:10px; background-color:#dc3545;"></span> Feitos</span>
                    <span class="d-flex align-items-center gap-1"><span class="rounded-circle border border-2 border-warning" style="width:10px; height:10px;"></span> Hoje</span>
                    <span class="d-flex align-items-center gap-1"><span class="rounded-circle" style="width:10px; height:10px; background-color:#0d6efd;"></span> Nota</span>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered text-center align-middle m-0 table-calendario">
                        <thead class="table-light text-uppercase" style="font-size: 0.70rem; letter-spacing: 0.5px;">
                            <tr>
                                <th class="text-danger">Dom</th>
                                <th>Seg</th><th>Ter</th><th>Qua</th><th>Qui</th><th>Sex</th>
                                <th>Sáb</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                            <?php
                            for ($i = 0; $i < $primeiro_dia_semana; $i++) {
                                echo "<td class='bg-light-subtle text-muted border-light'></td>";
                            }

                            $dia_semana_corrente = $primeiro_dia_semana;
                            for ($dia = 1; $dia <= $numero_dias_mes; $dia++) {
                                if ($dia_semana_corrente == 7) { echo "</tr><tr>"; $dia_semana_corrente = 0; }

                                $data_verificacao = sprintf("%s-%s-%02d", $ano_atual, $mes_atual_str, $dia);
                                $e_hoje           = ($data_verificacao === $data_hoje);
                                $estilo_celula    = "";
                                $tooltip_attrs    = "";

                                if (isset($eventos_por_data[$data_verificacao])) {
                                    $cor = ($data_verificacao < $data_hoje) ? '#dc3545' : '#198754';
                                    $estilo_celula = "background-color:{$cor} !important; color:white !important; font-weight:bold; border-radius:50%;";

                                    $casais_nomes = [];
                                    foreach ($eventos_por_data[$data_verificacao] as $ev) {
                                        $hora_str = !empty($ev['hora_evento']) ? ' às ' . date('H:i', strtotime($ev['hora_evento'])) : '';
                                        $casais_nomes[] = 'Casamento: ' . htmlspecialchars($ev['nome_noivos'], ENT_QUOTES) . $hora_str;
                                    }
                                    $tooltip_attrs = "data-bs-toggle='tooltip' data-bs-placement='top' title='" . implode(' | ', $casais_nomes) . "'";
                                }

                                // Anotações do dia (pode ser array com múltiplas)
                                $notas_dia = $anotacoes_do_mes[$data_verificacao] ?? [];
                                $tem_nota  = !empty($notas_dia);

                                // Serializa as notas para passar ao JS via data-attribute (JSON)
                                $notas_json = htmlspecialchars(json_encode($notas_dia, JSON_UNESCAPED_UNICODE), ENT_QUOTES);

                                $borda_hoje = $e_hoje ? "outline: 2px solid #ffc107; outline-offset: -2px;" : "";

                                echo "<td $tooltip_attrs style='height:48px; width:48px; position:relative; $borda_hoje' 
                                          class='celula-dia' data-date='$data_verificacao' data-notas='$notas_json'>";

                                if (!empty($estilo_celula)) {
                                    echo "<span class='d-flex align-items-center justify-content-center mx-auto shadow-sm' style='width:30px; height:30px; $estilo_celula'>$dia</span>";
                                } else {
                                    echo "<span class='d-flex align-items-center justify-content-center mx-auto' style='width:30px; height:30px; font-weight: 500;'>$dia</span>";
                                }

                                if ($tem_nota) {
                                    echo "<div class='indicador-nota shadow-sm'></div>";
                                }

                                echo "</td>";
                                $dia_semana_corrente++;
                            }

                            while ($dia_semana_corrente < 7) {
                                echo "<td class='bg-light-subtle text-muted border-light'></td>";
                                $dia_semana_corrente++;
                            }
                            ?>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 pt-3 border-top text-center">
                    <div class="fw-bold text-secondary small">
                        Realizados este mês: <span class="badge bg-dark px-2 ms-1"><?= str_pad($casamentos_feitos_no_mes, 2, "0", STR_PAD_LEFT) ?></span>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="modal fade" id="modalNovoCasamento" tabindex="-1" aria-labelledby="modalNovoCasamentoLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-success text-white border-0 rounded-top-4">
                <h5 class="modal-title fw-bold" id="modalNovoCasamentoLabel">
                    <i class="bi bi-plus-circle-fill me-2"></i> Adicionar Novo Casamento
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST" action="" class="needs-validation" autocomplete="off" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="cadastrar_evento" value="1">

                    <h6 class="fw-bold text-success mb-3 border-bottom pb-2">Acesso e Dados dos Noivos</h6>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-secondary small">Nome (Noiva / Cônjuge 1) *</label>
                            <input type="text" name="nome_noiva" class="form-control bg-light" placeholder="Ex: Ana Maria" autocomplete="off" required>
                            <div class="invalid-feedback">O nome é obrigatório.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-secondary small">Nome (Noivo / Cônjuge 2) *</label>
                            <input type="text" name="nome_noivo" class="form-control bg-light" placeholder="Ex: Lucas Mendes" autocomplete="off" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-secondary small">E-mail de Login *</label>
                            <input type="email" name="email_cliente" class="form-control bg-light" placeholder="exemplo@email.com" autocomplete="off" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-secondary small">WhatsApp / Telefone</label>
                            <input type="tel" name="telefone_cliente" id="input-telefone" class="form-control bg-light" placeholder="(00) 00000-0000" autocomplete="off">
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-secondary small">CPF do Contratante</label>
                            <input type="text" name="cpf_cliente" id="input-cpf" class="form-control bg-light" placeholder="000.000.000-00" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-secondary small">Senha Inicial <span class="text-muted fw-normal">(vazio = 123456)</span></label>
                            <input type="password" name="senha_cliente" class="form-control bg-light" placeholder="Senha do portal" autocomplete="new-password">
                        </div>
                    </div>

                    <h6 class="fw-bold text-success mb-3 border-bottom pb-2">Detalhes do Grande Dia</h6>

                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-secondary small">Data do Evento *</label>
                            <input type="date" name="data_evento" class="form-control bg-light" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-secondary small">Horário</label>
                            <input type="time" name="hora_evento" class="form-control bg-light">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold text-secondary small">Checklist Padrão</label>
                            <select name="modelo_checklist" class="form-select bg-light">
                                <option value="">Não importar tarefas</option>
                                <option value="com_recepcao">Modelo Padrão (Com Recepção)</option>
                                <option value="sem_recepcao">Simplificado (Sem Recepção)</option>
                            </select>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success px-4 fw-bold shadow-sm">
                            <i class="bi bi-folder-plus me-1"></i> Criar Casamento
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAnotacaoDia" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-md">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-primary text-white border-0 rounded-top-4 pb-2">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-calendar2-event-fill me-2"></i>
                    Agenda de <span id="label-data-titulo" class="ms-1"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>

            <div class="modal-body p-4">

                <div id="lista-notas-dia" class="mb-3"></div>

                <div class="form-nova-nota" id="form-nota-wrapper">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <i class="bi bi-plus-circle-fill text-primary fs-5"></i>
                        <span class="fw-bold text-primary" id="label-form-nota" style="font-size:0.9rem;">Nova anotação</span>
                        <button type="button" class="btn btn-sm btn-link text-secondary ms-auto p-0" id="btn-cancelar-edicao" style="display:none;" title="Cancelar edição">
                            <i class="bi bi-x-lg"></i> Cancelar
                        </button>
                    </div>
                    <form method="POST" action="" id="form-salvar-nota">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="salvar_anotacao" value="1">
                        <input type="hidden" name="data_nota" id="input-data-modal">
                        <input type="hidden" name="nota_id" id="input-nota-id" value="0">
                        <input type="hidden" name="mes" value="<?= $mes_atual ?>">
                        <input type="hidden" name="ano" value="<?= $ano_atual ?>">

                        <div class="mb-3">
                            <label class="form-label fw-bold text-secondary small mb-1">
                                <i class="bi bi-clock me-1"></i> Horário <span class="text-muted fw-normal">(opcional)</span>
                            </label>
                            <input type="time" name="hora_nota" id="input-hora-nota" class="form-control bg-white" style="max-width:160px;">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold text-secondary small mb-1">
                                <i class="bi bi-pencil me-1"></i> Anotação
                            </label>
                            <textarea class="form-control bg-white" name="anotacao" id="textarea-anotacao-modal" rows="3"
                                      placeholder="Descreva o compromisso ou lembrete..." required></textarea>
                        </div>

                        <div class="d-flex gap-2 justify-content-end">
                            <button type="submit" class="btn btn-primary fw-bold px-4 shadow-sm" id="btn-salvar-nota">
                                <i class="bi bi-floppy-fill me-1"></i> Salvar
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<div class="modal fade" id="modalResetSenha" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h6 class="modal-title fw-bold text-warning"><i class="bi bi-key-fill me-1"></i> Resetar Senha</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="resetar_senha" value="1">
                <input type="hidden" name="cliente_id" id="input-cliente-id-reset">
                <div class="modal-body">
                    <label class="form-label small fw-bold text-muted">Nova Senha do Casal</label>
                    <input type="password" name="nova_senha" class="form-control bg-light" placeholder="Digite a nova senha" minlength="6" required>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="submit" class="btn btn-warning w-100 fw-bold">Confirmar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditarData" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h6 class="modal-title fw-bold text-primary"><i class="bi bi-calendar-event me-1"></i> Reagendar</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="editar_data" value="1">
                <input type="hidden" name="evento_id_data" id="input-evento-id-data">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Nova Data</label>
                        <input type="date" name="nova_data" id="input-nova-data" class="form-control bg-light" required>
                    </div>
                    <div>
                        <label class="form-label small fw-bold text-muted">Novo Horário</label>
                        <input type="time" name="nova_hora" id="input-nova-hora" class="form-control bg-light">
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="submit" class="btn btn-primary w-100 fw-bold">Salvar Data</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditarCadastro" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-bottom-0 pb-0">
                <h6 class="modal-title fw-bold text-primary"><i class="bi bi-pencil-square me-1"></i> Editar Cadastro do Casal</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="editar_cadastro_casal" value="1">
                <input type="hidden" name="cliente_id_cadastro" id="input-cliente-id-cadastro">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Nome do Casal</label>
                        <input type="text" name="nome_cadastro" id="input-nome-cadastro" class="form-control bg-light" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">E-mail</label>
                        <input type="email" name="email_cadastro" id="input-email-cadastro" class="form-control bg-light" required>
                    </div>
                    <div>
                        <label class="form-label small fw-bold text-muted">Telefone / WhatsApp</label>
                        <input type="text" name="telefone_cadastro" id="input-telefone-cadastro" class="form-control bg-light">
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="submit" class="btn btn-primary w-100 fw-bold">Salvar Alterações</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalConfirmarSaida" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 p-2 text-center">
            <div class="pt-4 pb-2 px-3">
                <div class="mx-auto mb-3 rounded-circle bg-danger bg-opacity-10 d-flex align-items-center justify-content-center" style="width:64px;height:64px;">
                    <i class="bi bi-box-arrow-right text-danger fs-3"></i>
                </div>
                <h6 class="fw-bold mb-1">Sair do sistema?</h6>
                <p class="text-muted small mb-0">Você precisará fazer login novamente para acessar o painel.</p>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://unpkg.com/imask"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // Inicializa Toasts do Bootstrap
    const toastElList = document.querySelectorAll('.toast');
    [...toastElList].map(toastEl => new bootstrap.Toast(toastEl));

    // Aba "Buscar Noivos": filtra por nome, e-mail ou telefone
    const buscaNoivos = document.getElementById('busca-noivos');
    if (buscaNoivos) {
        buscaNoivos.addEventListener('input', function () {
            const termo = this.value.toLowerCase().trim();
            let visiveis = 0;
            document.querySelectorAll('.linha-busca-noivos').forEach(function (el) {
                const encontrado = !termo || el.dataset.search.includes(termo);
                el.style.display = encontrado ? '' : 'none';
                if (encontrado) visiveis++;
            });
            document.querySelectorAll('.busca-noivos-vazio').forEach(function (el) {
                el.style.display = visiveis === 0 ? '' : 'none';
            });
        });
    }

    // Ícone de calendário (só mobile): rola a página até o card do calendário
    document.getElementById('btn-ir-calendario')?.addEventListener('click', function () {
        document.getElementById('calendario-wrapper')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    // Botões "Ver mais": revela o restante dos casamentos (Próximos / Histórico)
    document.querySelectorAll('.btn-ver-mais-casamentos').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.' + this.dataset.alvo).forEach(function (el) {
                el.classList.remove('d-none');
            });
            this.remove();
        });
    });

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

    // Clicar em uma notificação também marca como lida
    document.getElementById('lista-notificacoes')?.addEventListener('click', function (e) {
        const item = e.target.closest('.notif-item');
        if (!item) return;
        const badge = document.querySelector('#dropdown-notificacoes .badge');
        if (badge) badge.remove();
        fetch('notificacoes_marcar_lidas.php', { method: 'POST', keepalive: true }).catch(() => {});
    });

    // Inicializa Tooltips do Bootstrap
    function initTooltips() {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            new bootstrap.Tooltip(el);
        });
    }
    initTooltips();

    // Validação visual de Formulários (Bootstrap)
    const forms = document.querySelectorAll('.needs-validation');
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        }, false);
    });

    // Máscaras de Inputs no Modal Novo Casamento
    const elCpf = document.getElementById('input-cpf');
    const elTel = document.getElementById('input-telefone');
    if (elCpf) { IMask(elCpf, { mask: '000.000.000-00' }); }
    if (elTel) { IMask(elTel, { mask: '(00) 00000-0000' }); }

    // ================================================================
    // LÓGICA DO MODAL DE ANOTAÇÕES POR HORÁRIO
    // ================================================================

    /**
     * Alterna entre texto expandido e truncado.
     * Esta função precisa estar disponível globalmente para ser chamada pelo onclick do botão.
     */
    window.toggleNota = function(idStr, btnElement) {
        const divTexto = document.getElementById(idStr);
        if (!divTexto) return;
        
        if (divTexto.classList.contains('nota-truncada')) {
            // Expandir
            divTexto.classList.remove('nota-truncada');
            divTexto.classList.add('nota-expandida');
            btnElement.innerHTML = 'Ler menos <i class="bi bi-chevron-up"></i>';
        } else {
            // Retrair
            divTexto.classList.remove('nota-expandida');
            divTexto.classList.add('nota-truncada');
            btnElement.innerHTML = 'Ler mais <i class="bi bi-chevron-down"></i>';
        }
    };

    /**
     * Renderiza a lista de anotações existentes no modal.
     */
    function renderizarNotas(notas) {
        const lista = document.getElementById('lista-notas-dia');
        lista.innerHTML = '';

        if (!notas || notas.length === 0) {
            return;
        }

        // Ordena por horário (sem hora vai para o final)
        notas.sort((a, b) => {
            if (!a.hora_nota && !b.hora_nota) return 0;
            if (!a.hora_nota) return 1;
            if (!b.hora_nota) return -1;
            return a.hora_nota.localeCompare(b.hora_nota);
        });

        notas.forEach(nota => {
            const horaLabel = nota.hora_nota
                ? `<span class="hora-badge"><i class="bi bi-clock-fill me-1"></i>${nota.hora_nota.substring(0,5)}</span>`
                : `<span class="hora-badge sem-hora"><i class="bi bi-clock me-1"></i>Sem horário</span>`;

            // ---- LÓGICA DE TRUNCAMENTO DE TEXTO ----
            const divTextoId = `desc-nota-${nota.id}`;
            const textoEscapado = escapeHtml(nota.anotacao);
            let btnLerMais = '';
            
            // Se o texto for maior que ~80 caracteres, insere o botão de Ler Mais
            if (textoEscapado.length > 80) {
                btnLerMais = `
                    <div class="btn-ler-mais mt-1" onclick="toggleNota('${divTextoId}', this)">
                        Ler mais <i class="bi bi-chevron-down"></i>
                    </div>`;
            }
            
            // O texto da anotação começa com a classe 'nota-truncada'
            const blocoTexto = `
                <div id="${divTextoId}" class="nota-item-texto mt-1 nota-truncada">
                    ${textoEscapado}
                </div>
                ${btnLerMais}
            `;
            // ----------------------------------------

            const div = document.createElement('div');
            div.className = 'nota-item';
            div.dataset.id = nota.id;
            div.innerHTML = `
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="flex-grow-1">
                        ${horaLabel}
                        ${blocoTexto}
                    </div>
                    <div class="d-flex flex-column gap-1 flex-shrink-0">
                        <button type="button" class="btn btn-sm btn-outline-primary btn-editar-nota"
                                data-id="${nota.id}"
                                data-hora="${nota.hora_nota || ''}"
                                data-texto="${escapeAttr(nota.anotacao)}"
                                title="Editar">
                            <i class="bi bi-pencil-fill"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger btn-excluir-nota"
                                data-id="${nota.id}"
                                title="Excluir">
                            <i class="bi bi-trash-fill"></i>
                        </button>
                    </div>
                </div>
            `;
            lista.appendChild(div);
        });
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    function escapeAttr(str) {
        return (str || '').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /** Reseta o formulário de nota para o estado "nova anotação" */
    function resetarFormNota() {
        document.getElementById('input-nota-id').value = '0';
        document.getElementById('input-hora-nota').value = '';
        document.getElementById('textarea-anotacao-modal').value = '';
        document.getElementById('label-form-nota').textContent = 'Nova anotação';
        document.getElementById('btn-cancelar-edicao').style.display = 'none';
        document.getElementById('btn-salvar-nota').innerHTML = '<i class="bi bi-floppy-fill me-1"></i> Salvar';
    }

    // Quando o modal abre, reseta o formulário
    document.getElementById('modalAnotacaoDia').addEventListener('show.bs.modal', resetarFormNota);

    // ================================================================
    // SALVAR E EDITAR ANOTAÇÃO (AJAX)
    // ================================================================
    const formSalvarNota = document.getElementById('form-salvar-nota');
    if (formSalvarNota) {
        formSalvarNota.addEventListener('submit', function (e) {
            e.preventDefault(); // Impede o reload da página

            const btnSalvar = document.getElementById('btn-salvar-nota');
            const iconeOriginal = btnSalvar.innerHTML;
            btnSalvar.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
            btnSalvar.disabled = true;

            const formData = new FormData(this);

            fetch('painel_admin.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.sucesso) {
                    const dataModal = document.getElementById('input-data-modal').value;
                    const celula = document.querySelector(`.celula-dia[data-date="${dataModal}"]`);
                    
                    let notas = [];
                    if (celula) {
                        notas = JSON.parse(celula.getAttribute('data-notas') || '[]');
                    }

                    // Se a ação foi "excluída" (ocorre se salvou a nota com o texto vazio)
                    if (data.acao === 'excluida') {
                        notas = notas.filter(n => String(n.id) !== String(data.id));
                    } 
                    // Se foi inserida ou atualizada
                    else {
                        if (data.acao === 'criada') {
                            notas.push(data.nota);
                        } else if (data.acao === 'atualizada') {
                            const index = notas.findIndex(n => String(n.id) === String(data.nota.id));
                            if (index !== -1) {
                                notas[index] = data.nota;
                            } else {
                                notas.push(data.nota);
                            }
                        }
                    }

                    // Atualiza a célula (HTML e atributos JS)
                    if (celula) {
                        celula.setAttribute('data-notas', JSON.stringify(notas));
                        
                        const indicadorExistente = celula.querySelector('.indicador-nota');
                        if (notas.length > 0 && !indicadorExistente) {
                            celula.insertAdjacentHTML('beforeend', "<div class='indicador-nota shadow-sm'></div>");
                        } else if (notas.length === 0 && indicadorExistente) {
                            indicadorExistente.remove();
                        }
                    }

                    // Atualiza a visualização do Modal
                    renderizarNotas(notas);
                    resetarFormNota();
                } else {
                    alert('Ocorreu um problema ao salvar a anotação.');
                }
            })
            .catch(error => {
                console.error('Erro no salvamento:', error);
                alert('Erro de comunicação. Tente novamente.');
            })
            .finally(() => {
                btnSalvar.innerHTML = iconeOriginal;
                btnSalvar.disabled = false;
            });
        });
    }

    // Delegação de cliques para botões editar/excluir dentro da lista de notas
    document.getElementById('lista-notas-dia').addEventListener('click', function (e) {

        // Editar nota (apenas joga os dados pro formulário)
        const btnEdit = e.target.closest('.btn-editar-nota');
        if (btnEdit) {
            document.getElementById('input-nota-id').value        = btnEdit.dataset.id;
            document.getElementById('input-hora-nota').value      = btnEdit.dataset.hora || '';
            document.getElementById('textarea-anotacao-modal').value = btnEdit.dataset.texto || '';
            document.getElementById('label-form-nota').textContent = 'Editando anotação';
            document.getElementById('btn-cancelar-edicao').style.display = 'inline-block';
            document.getElementById('btn-salvar-nota').innerHTML  = '<i class="bi bi-floppy-fill me-1"></i> Atualizar';
            document.getElementById('textarea-anotacao-modal').focus();
            return;
        }

        // Excluir nota (VIA AJAX)
        const btnDel = e.target.closest('.btn-excluir-nota');
        if (btnDel) {
            if (confirm('Deseja excluir esta anotação?')) {
                const notaId = btnDel.dataset.id;
                const csrfToken = document.querySelector('input[name="csrf_token"]').value;
                const dataModal = document.getElementById('input-data-modal').value;

                const formData = new FormData();
                formData.append('csrf_token', csrfToken);
                formData.append('excluir_anotacao', '1');
                formData.append('nota_id', notaId);

                const iconeOriginal = btnDel.innerHTML;
                btnDel.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
                btnDel.disabled = true;

                fetch('painel_admin.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.sucesso) {
                        const celula = document.querySelector(`.celula-dia[data-date="${dataModal}"]`);
                        if (celula) {
                            let notas = JSON.parse(celula.getAttribute('data-notas') || '[]');
                            notas = notas.filter(n => String(n.id) !== String(notaId));
                            celula.setAttribute('data-notas', JSON.stringify(notas));
                            
                            if (notas.length === 0) {
                                const indicador = celula.querySelector('.indicador-nota');
                                if (indicador) indicador.remove();
                            }
                        }
                        
                        const notaItem = btnDel.closest('.nota-item');
                        if (notaItem) notaItem.remove();

                        if (document.getElementById('input-nota-id').value === String(notaId)) {
                            resetarFormNota();
                        }
                    }
                })
                .catch(error => {
                    console.error('Erro na exclusão:', error);
                    alert('Houve um erro ao tentar excluir. Tente novamente.');
                    btnDel.innerHTML = iconeOriginal;
                    btnDel.disabled = false;
                });
            }
        }
    });

    // Botão cancelar edição
    document.getElementById('btn-cancelar-edicao').addEventListener('click', resetarFormNota);

    // ================================================================
    // DELEGAÇÃO GERAL DE CLIQUES
    // ================================================================
    document.addEventListener('click', async function (e) {

        // Modal Editar Data do evento
        const btnData = e.target.closest('.btn-abrir-modal-data');
        if (btnData) {
            document.getElementById('input-evento-id-data').value = btnData.dataset.eventoId || '';
            document.getElementById('input-nova-data').value      = btnData.dataset.data || '';
            document.getElementById('input-nova-hora').value      = btnData.dataset.hora || '';
        }

        // Modal Resetar Senha
        const btnSenha = e.target.closest('.btn-abrir-modal-senha');
        if (btnSenha) {
            document.getElementById('input-cliente-id-reset').value = btnSenha.dataset.clienteId || '';
        }

        // Modal Editar Cadastro do Casal
        const btnCadastro = e.target.closest('.btn-abrir-modal-cadastro');
        if (btnCadastro) {
            document.getElementById('input-cliente-id-cadastro').value = btnCadastro.dataset.clienteId || '';
            document.getElementById('input-nome-cadastro').value       = btnCadastro.dataset.nome || '';
            document.getElementById('input-email-cadastro').value      = btnCadastro.dataset.email || '';
            document.getElementById('input-telefone-cadastro').value   = btnCadastro.dataset.telefone || '';
        }

        // Clique na célula do calendário → abre modal de anotações
        const celula = e.target.closest('.celula-dia');
        if (celula) {
            const dataAlvo  = celula.getAttribute('data-date');
            const notasJson = celula.getAttribute('data-notas') || '[]';
            const partes    = dataAlvo.split('-');
            const formatada = partes[2] + '/' + partes[1] + '/' + partes[0];

            document.getElementById('input-data-modal').value      = dataAlvo;
            document.getElementById('label-data-titulo').textContent = formatada;

            let notas = [];
            try { notas = JSON.parse(notasJson); } catch(err) { notas = []; }
            renderizarNotas(notas);

            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAnotacaoDia')).show();
            return;
        }

        // Navegação AJAX do Calendário
        const navBtn = e.target.closest('.btn-nav-calendario');
        if (navBtn) {
            e.preventDefault();
            const url     = navBtn.href;
            const wrapper = document.getElementById('calendario-wrapper');

            wrapper.style.opacity       = '0.5';
            wrapper.style.pointerEvents = 'none';

            try {
                const response = await fetch(url);
                const html     = await response.text();
                const doc      = new DOMParser().parseFromString(html, 'text/html');
                const novo     = doc.getElementById('calendario-wrapper');

                if (novo) {
                    wrapper.innerHTML = novo.innerHTML;
                    initTooltips();
                }
            } catch (err) {
                console.error('Erro na navegação AJAX:', err);
                window.location.href = url;
            } finally {
                wrapper.style.opacity       = '1';
                wrapper.style.pointerEvents = 'auto';
            }
        }
    });
});
</script>
</body>
</html>