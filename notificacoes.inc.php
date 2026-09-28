<?php
/* ============================================================
   NOTIFICAÇÕES — lógica compartilhada entre painel_admin.php
   (visão global) e gerenciar.php (visão por evento).
   ============================================================ */

// Migração: tabela de controle de "última vez que cada usuário viu as notificações".
// Esse arquivo é incluído em toda página com sino de notificações — sem cache
// isso rodava em toda requisição de gerenciar.php, noivos.php e painel_admin.php.
if (!schema_ja_verificado('notificacoes')) {
    try {
        $pdo->query("SELECT 1 FROM notificacoes_lidas LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("
            CREATE TABLE notificacoes_lidas (
                usuario_tipo      VARCHAR(20) NOT NULL,
                usuario_id        INT         NOT NULL,
                ultima_visualizacao DATETIME  NOT NULL,
                PRIMARY KEY (usuario_tipo, usuario_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }
    marcar_schema_verificado('notificacoes');
}

// "Última vez que viu" passou a ser por escopo (módulo inteiro, ou um evento
// específico) — antes era só por conta, então abrir o sino num módulo/evento
// zerava o contador de todos os outros, mesmo sem ter visto nada lá.
// (Usado hoje só pelo botão "Marcar lidas em massa" — o clique individual,
// em qualquer tela, usa o controle item a item de notificacoes_vistas, logo
// abaixo, que também é o que decide o que aparece na lista.)
if (!schema_ja_verificado('notificacoes_escopo')) {
    try {
        $pdo->query("SELECT escopo FROM notificacoes_lidas LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE notificacoes_lidas ADD COLUMN escopo VARCHAR(50) NOT NULL DEFAULT 'geral'");
        try { $pdo->exec("ALTER TABLE notificacoes_lidas DROP PRIMARY KEY"); } catch (Exception $e2) {}
        $pdo->exec("ALTER TABLE notificacoes_lidas ADD PRIMARY KEY (usuario_tipo, usuario_id, escopo)");
    }
    marcar_schema_verificado('notificacoes_escopo');
}

// Controle item a item: cada notificação (tarefa concluída, comentário, RSVP,
// nota, comentário de nota...) tem uma "chave" própria (ex: "checklist:328",
// "nota:12"). Clicar numa marca só aquela chave como vista — as outras
// continuam aparecendo, em vez de um corte por data que apagava a lista
// inteira de uma vez só. Usado por painel_admin.php, gerenciar.php e
// noivos.php — o mesmo mecanismo pros três, equipe ou casal.
if (!schema_ja_verificado('notificacoes_vistas')) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notificacoes_vistas (
        usuario_tipo VARCHAR(20) NOT NULL,
        usuario_id   INT NOT NULL,
        chave        VARCHAR(60) NOT NULL,
        visto_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (usuario_tipo, usuario_id, chave)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    marcar_schema_verificado('notificacoes_vistas');
}

// Coluna de data de criação da tarefa do checklist — usada pra saber quais
// tarefas são "novas" (pra notificar o casal quando a assessoria adiciona
// checklist). Linhas antigas ganham uma data bem no passado, pra não virar
// notificação de "tarefa nova" retroativa pra quem já tinha checklist.
if (!schema_ja_verificado('checklist_criado_em')) {
    try {
        $pdo->query("SELECT criado_em FROM checklist LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE checklist ADD COLUMN criado_em DATETIME NULL");
        $pdo->exec("UPDATE checklist SET criado_em = '2000-01-01 00:00:00' WHERE criado_em IS NULL");
    }
    marcar_schema_verificado('checklist_criado_em');
}

/**
 * Busca as notificações mais recentes (tarefas concluídas pelos noivos,
 * comentários dos noivos e confirmações/recusas de presença).
 * Se $evento_id for informado, filtra só aquele evento; senão, traz de todos
 * os eventos — mas ainda restrito a $tipo_evento quando informado, pra não
 * misturar notificação de um módulo enquanto a equipe está administrando outro.
 *
 * $avisos_central_admin_id (Fase de Avisos, 2026-09-15): quando informado
 * (o id do admin logado), soma os avisos da Central (modo sino) dirigidos a
 * ele — só painel_admin.php passa isso; gerenciar.php e noivos.php nunca
 * veem avisos da Central.
 *
 * $incluir_financeiro = false tira os avisos de arquivos/comprovantes de
 * fornecedores (têm valores) — o assistente não vê dados financeiros.
 */
function buscar_notificacoes(PDO $pdo, ?int $evento_id, int $limite = 20, ?string $tipo_evento = null, ?int $avisos_central_admin_id = null, bool $incluir_financeiro = true): array
{
    $itens = [];
    $filtro_modulo = ($tipo_evento && !$evento_id) ? " AND e.tipo_evento = ?" : "";

    // 1. Tarefas concluídas pelos noivos
    $sql1 = "
        SELECT c.id, c.evento_id, c.tarefa, c.concluido_em AS quando, cl.nome AS evento_nome
        FROM checklist c
        INNER JOIN eventos e ON e.id = c.evento_id
        INNER JOIN clientes cl ON cl.id = e.cliente_id
        WHERE c.concluido_por = 'Noivos' AND c.concluido_em IS NOT NULL
    " . ($evento_id ? " AND c.evento_id = ?" : $filtro_modulo) . "
        ORDER BY c.concluido_em DESC LIMIT " . (int)$limite;
    $stmt1 = $pdo->prepare($sql1);
    $stmt1->execute($evento_id ? [$evento_id] : ($filtro_modulo ? [$tipo_evento] : []));
    foreach ($stmt1->fetchAll() as $r) {
        $itens[] = [
            'tipo'        => 'checklist',
            'chave'       => 'checklist:' . $r['id'],
            'icone'       => 'bi-check-circle-fill text-success',
            'evento_id'   => (int)$r['evento_id'],
            'evento_nome' => $r['evento_nome'],
            'texto'       => 'Concluiu a tarefa "' . $r['tarefa'] . '"',
            'quando'      => $r['quando'],
        ];
    }

    // 2. Comentários dos noivos (em tarefas ou em etapas)
    $sql2 = "
        SELECT cc.id, cc.comentario, cc.criado_em AS quando,
               COALESCE(ch.evento_id, cc.evento_id) AS evento_id,
               COALESCE(ch.tarefa, cc.etapa_nome, 'geral') AS referencia,
               cl.nome AS evento_nome
        FROM checklist_comentarios cc
        LEFT JOIN checklist ch ON ch.id = cc.checklist_id
        INNER JOIN eventos e ON e.id = COALESCE(ch.evento_id, cc.evento_id)
        INNER JOIN clientes cl ON cl.id = e.cliente_id
        WHERE cc.autor = 'Noivos'
    " . ($evento_id ? " AND COALESCE(ch.evento_id, cc.evento_id) = ?" : $filtro_modulo) . "
        ORDER BY cc.criado_em DESC LIMIT " . (int)$limite;
    $stmt2 = $pdo->prepare($sql2);
    $stmt2->execute($evento_id ? [$evento_id] : ($filtro_modulo ? [$tipo_evento] : []));
    foreach ($stmt2->fetchAll() as $r) {
        $itens[] = [
            'tipo'        => 'comentario',
            'chave'       => 'comentario:' . $r['id'],
            'icone'       => 'bi-chat-left-text-fill text-primary',
            'evento_id'   => (int)$r['evento_id'],
            'evento_nome' => $r['evento_nome'],
            'texto'       => 'Comentou em "' . $r['referencia'] . '": ' . mb_substr($r['comentario'], 0, 80, 'UTF-8') . (mb_strlen($r['comentario'], 'UTF-8') > 80 ? '…' : ''),
            'quando'      => $r['quando'],
        ];
    }

    // 3. Confirmações / recusas de presença
    $sql3 = "
        SELECT co.id, co.nome, co.resposta_rsvp, co.data_confirmacao AS quando,
               co.evento_id, cl.nome AS evento_nome
        FROM convidados co
        INNER JOIN eventos e ON e.id = co.evento_id
        INNER JOIN clientes cl ON cl.id = e.cliente_id
        WHERE co.data_confirmacao IS NOT NULL
    " . ($evento_id ? " AND co.evento_id = ?" : $filtro_modulo) . "
        ORDER BY co.data_confirmacao DESC LIMIT " . (int)$limite;
    $stmt3 = $pdo->prepare($sql3);
    $stmt3->execute($evento_id ? [$evento_id] : ($filtro_modulo ? [$tipo_evento] : []));
    foreach ($stmt3->fetchAll() as $r) {
        $recusou = ($r['resposta_rsvp'] === 'recusado');
        $itens[] = [
            'tipo'        => 'rsvp',
            'chave'       => 'rsvp:' . $r['id'],
            'icone'       => $recusou ? 'bi-x-circle-fill text-danger' : 'bi-emoji-heart-eyes-fill text-danger',
            'evento_id'   => (int)$r['evento_id'],
            'evento_nome' => $r['evento_nome'],
            'texto'       => $recusou
                ? $r['nome'] . ' avisou que não poderá comparecer'
                : $r['nome'] . ' confirmou presença',
            'quando'      => $r['quando'],
        ];
    }

    // 4. Lembretes da agenda (calendario_anotacoes com notificação ativada,
    // cujo horário já chegou) — só existe na visão global, não são de
    // nenhum evento específico.
    if (!$evento_id) {
        try {
            $sql4 = "
                SELECT id, anotacao, CONCAT(data_nota, ' ', hora_nota) AS quando
                FROM calendario_anotacoes
                WHERE notificar = 1 AND hora_nota IS NOT NULL
                  AND CONCAT(data_nota, ' ', hora_nota) <= NOW()
                ORDER BY quando DESC
                LIMIT " . (int)$limite;
            foreach ($pdo->query($sql4)->fetchAll() as $r) {
                $texto = mb_substr($r['anotacao'], 0, 100, 'UTF-8');
                if (mb_strlen($r['anotacao'], 'UTF-8') > 100) $texto .= '…';
                $itens[] = [
                    'tipo'        => 'agenda',
                    'chave'       => 'agenda:' . $r['id'],
                    'icone'       => 'bi-alarm-fill text-warning',
                    'evento_id'   => null,
                    'evento_nome' => 'Lembrete da agenda',
                    'texto'       => $texto,
                    'quando'      => $r['quando'],
                ];
            }
        } catch (Exception $e) {
            // Coluna 'notificar' pode não existir ainda num deploy antigo;
            // ignora silenciosamente em vez de quebrar o resto do sino.
        }
    }

    // 6. Notas criadas pelo casal (tabela pode não existir ainda num deploy
    // antigo que nunca abriu gerenciar.php/noivos.php pra rodar a migração —
    // ignora silenciosamente, igual ao lembrete de agenda acima).
    try {
        $sql6 = "
            SELECT n.id, n.titulo, n.criado_em AS quando, n.evento_id, cl.nome AS evento_nome
            FROM notas_evento n
            INNER JOIN eventos e ON e.id = n.evento_id
            INNER JOIN clientes cl ON cl.id = e.cliente_id
            WHERE n.origem = 'Noivos'
        " . ($evento_id ? " AND n.evento_id = ?" : $filtro_modulo) . "
            ORDER BY n.criado_em DESC LIMIT " . (int)$limite;
        $stmt6 = $pdo->prepare($sql6);
        $stmt6->execute($evento_id ? [$evento_id] : ($filtro_modulo ? [$tipo_evento] : []));
        foreach ($stmt6->fetchAll() as $r) {
            $itens[] = [
                'tipo'        => 'nota',
                'icone'       => 'bi-journal-plus text-warning',
                'evento_id'   => (int)$r['evento_id'],
                'evento_nome' => $r['evento_nome'],
                'nota_id'     => (int)$r['id'],
                'chave'       => 'nota:' . $r['id'],
                'texto'       => 'Criou a nota "' . $r['titulo'] . '"',
                'quando'      => $r['quando'],
            ];
        }
    } catch (Exception $e) {}

    // 7. Comentários do casal em notas
    try {
        $sql7 = "
            SELECT nc.id, nc.nota_id, nc.comentario, nc.criado_em AS quando,
                   n.titulo AS nota_titulo, n.evento_id, cl.nome AS evento_nome
            FROM notas_comentarios nc
            INNER JOIN notas_evento n ON n.id = nc.nota_id
            INNER JOIN eventos e ON e.id = n.evento_id
            INNER JOIN clientes cl ON cl.id = e.cliente_id
            WHERE nc.autor = 'Noivos'
        " . ($evento_id ? " AND n.evento_id = ?" : $filtro_modulo) . "
            ORDER BY nc.criado_em DESC LIMIT " . (int)$limite;
        $stmt7 = $pdo->prepare($sql7);
        $stmt7->execute($evento_id ? [$evento_id] : ($filtro_modulo ? [$tipo_evento] : []));
        foreach ($stmt7->fetchAll() as $r) {
            // Comentário do casal: mostra "Noivos" (papel), não o nome real
            // registrado do casal — é sempre o mesmo texto pra qualquer um
            // dos dois, então mostrar o nome não ajuda a diferenciar nada.
            $itens[] = [
                'tipo'        => 'nota_comentario',
                'icone'       => 'bi-chat-square-text-fill text-warning',
                'evento_id'   => (int)$r['evento_id'],
                'evento_nome' => $r['evento_nome'],
                'nota_id'     => (int)$r['nota_id'],
                'chave'       => 'nota_comentario:' . $r['id'],
                'texto'       => 'Noivos comentou na nota "' . $r['nota_titulo'] . '": ' . mb_substr($r['comentario'], 0, 80, 'UTF-8') . (mb_strlen($r['comentario'], 'UTF-8') > 80 ? '…' : ''),
                'quando'      => $r['quando'],
            ];
        }
    } catch (Exception $e) {}

    // 8. Comprovantes que o cliente (casal, aniversariante, empresa...) anexou
    // aos pagamentos dos fornecedores.
    // Colunas podem não existir ainda num deploy que nunca abriu
    // fornecedores_evento.php — ignora silenciosamente, igual às notas acima.
    // $incluir_financeiro = false (assistente): não mostra, porque tem valores.
    if ($incluir_financeiro) try {
        $sql9 = "
            SELECT p.id, p.fornecedor_id, p.valor, p.comprovante_enviado_em AS quando, f.servico, f.evento_id, e.tipo_evento, cl.nome AS evento_nome
            FROM fornecedores_pagamentos p
            INNER JOIN fornecedores_evento f ON f.id = p.fornecedor_id
            INNER JOIN eventos e ON e.id = f.evento_id
            INNER JOIN clientes cl ON cl.id = e.cliente_id
            WHERE p.comprovante_enviado_por = 'Noivos' AND p.comprovante_enviado_em IS NOT NULL
        " . ($evento_id ? " AND f.evento_id = ?" : $filtro_modulo) . "
            ORDER BY p.comprovante_enviado_em DESC LIMIT " . (int)$limite;
        $stmt9 = $pdo->prepare($sql9);
        $stmt9->execute($evento_id ? [$evento_id] : ($filtro_modulo ? [$tipo_evento] : []));
        foreach ($stmt9->fetchAll() as $r) {
            // "O casal" / "O aniversariante" / "O responsável pela empresa"... conforme o módulo
            $contratante = function_exists('labels_modulo_evento')
                ? (labels_modulo_evento($r['tipo_evento'] ?? 'casamento')['singular_contratante'] ?? 'cliente')
                : 'cliente';
            $itens[] = [
                'tipo'        => 'arquivo_fornecedor',
                'chave'       => 'forn_pgto:' . $r['id'],
                'icone'       => 'bi-receipt text-success',
                'evento_id'   => (int)$r['evento_id'],
                'evento_nome' => $r['evento_nome'],
                'texto'       => 'O ' . $contratante . ' enviou o comprovante de R$ ' . number_format((float)$r['valor'], 2, ',', '.') . ' de "' . $r['servico'] . '"',
                'quando'      => $r['quando'],
                'link'        => 'fornecedores_evento.php?id=' . (int)$r['evento_id'] . '&pagamento=' . (int)$r['fornecedor_id'],
            ];
        }
    } catch (Exception $e) {}

    // 5. Avisos da Central (modo sino), só quando o chamador informa o admin
    // logado (painel_admin.php) — nunca em gerenciar.php/noivos.php.
    if ($avisos_central_admin_id !== null) {
        $sql5 = "
            SELECT id, titulo, mensagem, criado_em AS quando
            FROM central_avisos
            WHERE modo = 'bell' AND ativo = 1
              AND (alvo_admin_ids IS NULL OR FIND_IN_SET(?, alvo_admin_ids))
            ORDER BY criado_em DESC
            LIMIT " . (int)$limite;
        $stmt5 = $pdo->prepare($sql5);
        $stmt5->execute([$avisos_central_admin_id]);
        foreach ($stmt5->fetchAll() as $r) {
            $itens[] = [
                'tipo'        => 'central',
                'chave'       => 'central:' . $r['id'],
                'icone'       => 'bi-megaphone-fill text-primary',
                'evento_id'   => null,
                'evento_nome' => 'Aviso da Central',
                'chave'       => 'central:' . $r['id'],
                'texto'       => $r['titulo'] . ($r['mensagem'] ? ': ' . mb_substr($r['mensagem'], 0, 100, 'UTF-8') : ''),
                'quando'      => $r['quando'],
            ];
        }
    }

    usort($itens, fn($a, $b) => strcmp($b['quando'], $a['quando']));
    return array_slice($itens, 0, $limite);
}

/**
 * Última vez que o usuário logado abriu o sino de notificações (ou null se nunca)
 * dentro daquele escopo específico — 'modulo:casamento', 'evento:42', etc. Cada
 * escopo tem seu próprio "visto por último", pra abrir o sino num módulo/evento
 * não zerar o contador de outro que a pessoa nem chegou a abrir.
 */
function ultima_visualizacao_notificacoes(PDO $pdo, string $usuario_tipo, int $usuario_id, string $escopo = 'geral'): ?string
{
    $stmt = $pdo->prepare("SELECT ultima_visualizacao FROM notificacoes_lidas WHERE usuario_tipo = ? AND usuario_id = ? AND escopo = ?");
    $stmt->execute([$usuario_tipo, $usuario_id, $escopo]);
    $v = $stmt->fetchColumn();
    if (!$v) return null;

    // Blindagem: se esse valor ficar no futuro por qualquer motivo (relógio do
    // servidor bagunçado num deploy, fuso horário mudando, edição manual...),
    // ele bloquearia silenciosamente TODAS as notificações pra sempre — nada
    // nunca seria "mais novo" que uma data no futuro. Trata como se nunca
    // tivesse visto, em vez de propagar o valor quebrado.
    if ($v > date('Y-m-d H:i:s')) return null;

    return $v;
}

/** Conta quantos itens da lista são mais recentes que a última visualização
 *  (usado só pelo botão "Marcar lidas em massa" — o filtro item a item de
 *  quem já foi vista individualmente é feito com contar_nao_vistas, abaixo,
 *  que é o mesmo mecanismo pra painel_admin.php, gerenciar.php e noivos.php). */
function contar_nao_lidas(array $notificacoes, ?string $ultima_vista): int
{
    if (!$ultima_vista) return count($notificacoes);
    $n = 0;
    foreach ($notificacoes as $item) {
        if ($item['quando'] > $ultima_vista) $n++;
    }
    return $n;
}

/** Chaves de notificação que esse usuário já marcou como vista individualmente */
function chaves_vistas_usuario(PDO $pdo, string $usuario_tipo, int $usuario_id): array
{
    $stmt = $pdo->prepare("SELECT chave FROM notificacoes_vistas WHERE usuario_tipo = ? AND usuario_id = ?");
    $stmt->execute([$usuario_tipo, $usuario_id]);
    return array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Marca uma ou mais chaves de notificação como vistas por esse usuário */
function marcar_notificacoes_vistas(PDO $pdo, string $usuario_tipo, int $usuario_id, array $chaves): void
{
    $chaves = array_values(array_unique(array_filter($chaves, fn($c) => is_string($c) && $c !== '' && strlen($c) <= 60)));
    if (empty($chaves)) return;
    $stmt = $pdo->prepare("INSERT IGNORE INTO notificacoes_vistas (usuario_tipo, usuario_id, chave) VALUES (?, ?, ?)");
    foreach ($chaves as $chave) {
        $stmt->execute([$usuario_tipo, $usuario_id, $chave]);
    }
}

/** Conta quantos itens da lista NÃO estão no conjunto de chaves já vistas */
function contar_nao_vistas(array $notificacoes, array $vistas): int
{
    $n = 0;
    foreach ($notificacoes as $item) {
        if (!isset($vistas[$item['chave']])) $n++;
    }
    return $n;
}

/** Formata "há X minutos/horas/dias" a partir de uma data MySQL */
function tempo_relativo(string $dataMysql): string
{
    $segundos = time() - strtotime($dataMysql);
    if ($segundos < 60) return 'agora mesmo';
    if ($segundos < 3600) return 'há ' . floor($segundos / 60) . ' min';
    if ($segundos < 86400) return 'há ' . floor($segundos / 3600) . 'h';
    return 'há ' . floor($segundos / 86400) . 'd';
}
