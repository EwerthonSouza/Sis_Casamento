<?php
/* ============================================================
   NOTIFICAÇÕES — lógica compartilhada entre painel_admin.php
   (visão global) e gerenciar.php (visão por evento).
   ============================================================ */

// Migração: tabela de controle de "última vez que cada usuário viu as notificações"
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

/**
 * Busca as notificações mais recentes (tarefas concluídas pelos noivos,
 * comentários dos noivos e confirmações/recusas de presença).
 * Se $evento_id for informado, filtra só aquele evento; senão, traz de todos.
 */
function buscar_notificacoes(PDO $pdo, ?int $evento_id, int $limite = 20): array
{
    $itens = [];

    // 1. Tarefas concluídas pelos noivos
    $sql1 = "
        SELECT c.id, c.evento_id, c.tarefa, c.concluido_em AS quando, cl.nome AS evento_nome
        FROM checklist c
        INNER JOIN eventos e ON e.id = c.evento_id
        INNER JOIN clientes cl ON cl.id = e.cliente_id
        WHERE c.concluido_por = 'Noivos' AND c.concluido_em IS NOT NULL
    " . ($evento_id ? " AND c.evento_id = ?" : "") . "
        ORDER BY c.concluido_em DESC LIMIT " . (int)$limite;
    $stmt1 = $pdo->prepare($sql1);
    $stmt1->execute($evento_id ? [$evento_id] : []);
    foreach ($stmt1->fetchAll() as $r) {
        $itens[] = [
            'tipo'        => 'checklist',
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
    " . ($evento_id ? " AND COALESCE(ch.evento_id, cc.evento_id) = ?" : "") . "
        ORDER BY cc.criado_em DESC LIMIT " . (int)$limite;
    $stmt2 = $pdo->prepare($sql2);
    $stmt2->execute($evento_id ? [$evento_id] : []);
    foreach ($stmt2->fetchAll() as $r) {
        $itens[] = [
            'tipo'        => 'comentario',
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
    " . ($evento_id ? " AND co.evento_id = ?" : "") . "
        ORDER BY co.data_confirmacao DESC LIMIT " . (int)$limite;
    $stmt3 = $pdo->prepare($sql3);
    $stmt3->execute($evento_id ? [$evento_id] : []);
    foreach ($stmt3->fetchAll() as $r) {
        $recusou = ($r['resposta_rsvp'] === 'recusado');
        $itens[] = [
            'tipo'        => 'rsvp',
            'icone'       => $recusou ? 'bi-x-circle-fill text-danger' : 'bi-emoji-heart-eyes-fill text-danger',
            'evento_id'   => (int)$r['evento_id'],
            'evento_nome' => $r['evento_nome'],
            'texto'       => $recusou
                ? $r['nome'] . ' avisou que não poderá comparecer'
                : $r['nome'] . ' confirmou presença',
            'quando'      => $r['quando'],
        ];
    }

    usort($itens, fn($a, $b) => strcmp($b['quando'], $a['quando']));
    return array_slice($itens, 0, $limite);
}

/** Última vez que o usuário logado abriu o sino de notificações (ou null se nunca) */
function ultima_visualizacao_notificacoes(PDO $pdo, string $usuario_tipo, int $usuario_id): ?string
{
    $stmt = $pdo->prepare("SELECT ultima_visualizacao FROM notificacoes_lidas WHERE usuario_tipo = ? AND usuario_id = ?");
    $stmt->execute([$usuario_tipo, $usuario_id]);
    $v = $stmt->fetchColumn();
    return $v ?: null;
}

/** Conta quantos itens da lista são mais recentes que a última visualização */
function contar_nao_lidas(array $notificacoes, ?string $ultima_vista): int
{
    if (!$ultima_vista) return count($notificacoes);
    $n = 0;
    foreach ($notificacoes as $item) {
        if ($item['quando'] > $ultima_vista) $n++;
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
