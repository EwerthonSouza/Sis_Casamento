<?php
// Usa variáveis de ambiente do Docker quando definidas; caso contrário,
// cai nos padrões do XAMPP.
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'sistema_eventos';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: ''; // Padrão do XAMPP é vazio
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    // Fixa o fuso só nesta conexão (não é global, não afeta outros sistemas
    // que compartilham este mesmo servidor MySQL). Assessoria opera em Boa
    // Vista/RR (UTC-4). Brasil não tem mais horário de verão desde 2019,
    // então "-04:00" é seguro e fixo.
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '-04:00'",
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

// Cada página roda seus próprios checks de "essa coluna existe? se não, ALTER TABLE"
// a cada requisição (inclusive em cada chamada AJAX). Uma vez confirmado que o schema
// já tem tudo, marca em disco pra nunca mais bater no banco só pra verificar de novo.
function schema_ja_verificado(string $chave): bool {
    return file_exists(__DIR__ . "/uploads/.schema_ok_$chave");
}
function marcar_schema_verificado(string $chave): void {
    @file_put_contents(__DIR__ . "/uploads/.schema_ok_$chave", '1');
}

// Garante a coluna tipo_evento em eventos (módulos Casamentos/Aniversários/
// Corporativo/Acadêmico). Chamada em ~12 páginas a cada request — com o marcador,
// só bate no banco na primeira vez depois do deploy; depois disso é um file_exists().
function garantir_coluna_tipo_evento(PDO $pdo): void {
    if (schema_ja_verificado('coluna_tipo_evento')) return;
    try {
        $pdo->query("SELECT tipo_evento FROM eventos LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE eventos ADD COLUMN tipo_evento VARCHAR(20) NOT NULL DEFAULT 'casamento'");
    }
    marcar_schema_verificado('coluna_tipo_evento');
}

function garantir_tabela_modulos_config(PDO $pdo): void {
    if (schema_ja_verificado('tabela_modulos_config')) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS modulos_config (
        tipo_evento VARCHAR(20) NOT NULL PRIMARY KEY,
        cor_tema VARCHAR(7) NOT NULL
    )");
    marcar_schema_verificado('tabela_modulos_config');
}

// Controla quais módulos cada usuário da equipe (admin/assistente) pode ver no hub.
// Só o desenvolvedor (usuarios.tipo = 'desenvolvedor') mexe nisso, em dev_painel.php.
function garantir_tabela_modulos_liberados(PDO $pdo): void {
    if (schema_ja_verificado('tabela_modulos_liberados')) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios_modulos_liberados (
        usuario_id INT NOT NULL,
        tipo_evento VARCHAR(20) NOT NULL,
        PRIMARY KEY (usuario_id, tipo_evento),
        CONSTRAINT fk_uml_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    )");
    marcar_schema_verificado('tabela_modulos_liberados');
}

// Registra quem cadastrou cada usuário da equipe (o admin "dono" de cada assistente) —
// usado no painel do desenvolvedor pra saber de qual assessoria/cliente é cada um,
// à medida que o sistema passar a atender várias assessorias diferentes.
function garantir_coluna_criado_por(PDO $pdo): void {
    if (schema_ja_verificado('coluna_criado_por')) return;
    try {
        $pdo->query("SELECT criado_por FROM usuarios LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN criado_por INT NULL");
    }
    marcar_schema_verificado('coluna_criado_por');
}

// Segundo nome do cliente (ex: responsável pelo evento acadêmico/corporativo)
// guardado à parte — só em Casamentos os dois nomes são pares (Noiva & Noivo);
// nos outros módulos o 2º nome é um papel diferente (responsável), então não
// pode ser concatenado direto no título em todo canto que exibe o nome do
// cliente (fica parecendo duas instituições, não uma instituição + pessoa).
function garantir_coluna_nome_secundario_cliente(PDO $pdo): void {
    if (schema_ja_verificado('coluna_nome_secundario_cliente')) return;
    try {
        $pdo->query("SELECT nome_secundario FROM clientes LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE clientes ADD COLUMN nome_secundario VARCHAR(150) NULL");
    }
    marcar_schema_verificado('coluna_nome_secundario_cliente');
}

// Modelos de checklist (checklist_modelos) eram 100% globais — um evento de
// Aniversário importava o mesmo "Cronograma Padrão" cadastrado para
// Casamentos. Agora cada módulo tem sua própria lista de modelos; os que já
// existiam ficam em 'casamento' (default), os demais módulos começam vazios.
function garantir_coluna_tipo_evento_checklist_modelos(PDO $pdo): void {
    if (schema_ja_verificado('coluna_tipo_evento_checklist_modelos')) return;
    try {
        $pdo->query("SELECT tipo_evento FROM checklist_modelos LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE checklist_modelos ADD COLUMN tipo_evento VARCHAR(20) NOT NULL DEFAULT 'casamento'");
    }
    marcar_schema_verificado('coluna_tipo_evento_checklist_modelos');
}

// Sobrenome do convidado — opcional, separado do nome (primeiro nome/família)
// pra poder diferenciar dois convidados com o mesmo primeiro nome no mesmo
// evento (ver aviso de nome duplicado em convidados.php e páginas irmãs).
function garantir_coluna_sobrenome_convidado(PDO $pdo): void {
    if (schema_ja_verificado('coluna_sobrenome_convidado')) return;
    try {
        $pdo->query("SELECT sobrenome FROM convidados LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE convidados ADD COLUMN sobrenome VARCHAR(100) NULL");
    }
    marcar_schema_verificado('coluna_sobrenome_convidado');
}

// Pedidos de upgrade de plano: o admin/assistente clica em "Solicitar upgrade" num
// módulo bloqueado no hub, e o pedido fica pendente até o desenvolvedor liberar
// (ou dispensar) manualmente em dev_painel.php. Sem cobrança automática nenhuma —
// é só o aviso de que alguém quer aquele módulo.
function garantir_tabela_solicitacoes_upgrade(PDO $pdo): void {
    if (schema_ja_verificado('tabela_solicitacoes_upgrade')) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS solicitacoes_upgrade (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        tipo_evento VARCHAR(20) NOT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atendido TINYINT(1) NOT NULL DEFAULT 0,
        CONSTRAINT fk_solic_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    )");
    marcar_schema_verificado('tabela_solicitacoes_upgrade');
}

// Marca quando cada usuário da equipe logou pela última vez — usado só pra saber
// se é o primeiro acesso dele (pra não dizer "bem-vindo DE VOLTA" pra quem nunca
// entrou no sistema antes).
function garantir_coluna_ultimo_login_usuarios(PDO $pdo): void {
    if (schema_ja_verificado('coluna_ultimo_login_usuarios')) return;
    try {
        $pdo->query("SELECT ultimo_login FROM usuarios LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN ultimo_login DATETIME NULL");
    }
    marcar_schema_verificado('coluna_ultimo_login_usuarios');
}

// Preço (e promoção opcional, com período) de cada módulo — editável pelo
// desenvolvedor em dev_painel.php. Sem linha aqui, cai nos valores padrão
// de PLANOS_MODULO (modulos_evento.inc.php).
function garantir_tabela_planos_modulo(PDO $pdo): void {
    if (schema_ja_verificado('tabela_planos_modulo')) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS planos_modulo_config (
        tipo_evento VARCHAR(20) NOT NULL PRIMARY KEY,
        preco_normal DECIMAL(10,2) NOT NULL,
        preco_promocional DECIMAL(10,2) NULL,
        promocao_inicio DATE NULL,
        promocao_fim DATE NULL
    )");
    marcar_schema_verificado('tabela_planos_modulo');
}

// Essas tabelas são sempre consultadas filtrando por evento_id (mesas do evento,
// fornecedores do evento, anotações do evento, comentários do evento...), mas
// foram criadas antes de o índice entrar no CREATE TABLE de cada uma — ficaram
// fazendo table scan completo em toda consulta. Passa despercebido com poucos
// registros de teste, mas pesa à medida que cada evento acumula mais linhas.
function garantir_indices_performance(PDO $pdo): void {
    if (schema_ja_verificado('indices_performance_v1')) return;
    $indices = [
        'mesas'                 => 'idx_mesas_evento',
        'fornecedores_evento'   => 'idx_fornecedores_evento',
        'checklist_comentarios' => 'idx_comentarios_evento',
        'notas_evento'          => 'idx_notas_evento',
    ];
    foreach ($indices as $tabela => $indice) {
        try {
            $pdo->exec("CREATE INDEX $indice ON $tabela (evento_id)");
        } catch (Exception $e) {
            // Tabela pode não existir ainda nesta instalação, ou o índice já existir — ignora.
        }
    }
    marcar_schema_verificado('indices_performance_v1');
}
garantir_indices_performance($pdo);

// eventos.tipo_evento é filtrado em quase toda consulta do sistema multi-módulo
// (listagem do painel, notificações, contagens do hub...) desde que os módulos
// foram introduzidos, mas ficou sem índice — passa despercebido com poucos
// eventos de teste, mas pesa conforme a base cresce. Marcador própria porque
// 'indices_performance_v1' já rodou antes de este índice existir.
function garantir_indice_tipo_evento(PDO $pdo): void {
    if (schema_ja_verificado('indice_tipo_evento_v1')) return;
    try {
        $pdo->exec("CREATE INDEX idx_eventos_tipo_evento ON eventos (tipo_evento)");
    } catch (Exception $e) {
        // Índice já existe, ou coluna ainda não existe nesta instalação — ignora.
    }
    marcar_schema_verificado('indice_tipo_evento_v1');
}
garantir_indice_tipo_evento($pdo);

/* ============================================================
   MULTI-TENANT (assessoria_id) — passo 1 do plano de vender o
   sistema pra várias assessorias, não só a atual.
   Ver C:\docker\sis-casamento\PLANO-MULTI-TENANT.md e o vault
   Cerebro_MAX/SisCasamento/08-DECISOES-TECNICAS/decisoes.md
   (entrada 2026-09-17/21) pro desenho completo e o porquê.
   ============================================================ */

// Tabela raiz do multi-tenant + a assessoria "padrão", que recebe todo o
// dado que já existe hoje (única assessoria até agora). Sem isso a etapa
// seguinte (colunas assessoria_id) não tem pra onde apontar o backfill.
function garantir_tabela_assessorias(PDO $pdo): int {
    if (!schema_ja_verificado('tabela_assessorias')) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS assessorias (
            id        INT AUTO_INCREMENT PRIMARY KEY,
            nome      VARCHAR(255) NOT NULL,
            slug      VARCHAR(100) NOT NULL UNIQUE,
            status    ENUM('ativa','inativa','suspensa') NOT NULL DEFAULT 'ativa',
            criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        marcar_schema_verificado('tabela_assessorias');
    }
    // Não depende do marcador acima: mesmo que a tabela já existisse (ex.:
    // criada numa sessão anterior que falhou antes de inserir a linha), este
    // INSERT só roda se ainda não houver nenhuma assessoria — idempotente.
    $existe = (int)$pdo->query("SELECT COUNT(*) FROM assessorias")->fetchColumn();
    if ($existe === 0) {
        $pdo->prepare("INSERT INTO assessorias (nome, slug, status) VALUES (?, ?, 'ativa')")
            ->execute(['Vandernayla Thainá', 'vandernayla-thaina']);
    }
    return (int)$pdo->query("SELECT id FROM assessorias ORDER BY id ASC LIMIT 1")->fetchColumn();
}

/**
 * Adiciona assessoria_id em toda tabela de dado operacional (ver classificação
 * completa na sessão de 2026-09-28 no histórico do Cerebro_MAX) e aponta todo
 * o dado já existente pra assessoria padrão — nenhuma linha fica órfã.
 *
 * Cada tabela é tratada em passos tolerantes a repetição (coluna pode já
 * existir, índice/FK podem já existir) — se a função for interrompida no meio
 * (ex.: erro numa tabela específica), rodar de novo completa o que faltou sem
 * duplicar nada. Só marca como verificado no fim, com todas as tabelas ok.
 *
 * IMPORTANTE (mesma lição do incidente de 10/09/2026 — ver
 * PROJECT_CONTEXT.md armadilha #11): o marcador em arquivo sobrevive a uma
 * restauração de banco. Depois de restaurar um banco a partir de backup,
 * apagar uploads/.schema_ok_assessoria_id_v1 antes de confiar que rodou.
 */
function garantir_coluna_assessoria_id(PDO $pdo): void {
    if (schema_ja_verificado('assessoria_id_v1')) return;

    $assessoria_padrao_id = garantir_tabela_assessorias($pdo);

    // Tabelas de dado operacional — todas ganham assessoria_id NOT NULL.
    // usuarios é tratada à parte logo abaixo (permite NULL, pra contas
    // tipo='desenvolvedor', que enxergam todas as assessorias).
    $tabelas = [
        'clientes', 'eventos', 'checklist', 'checklist_comentarios',
        'checklist_modelos', 'convidados', 'documentos_evento',
        'fornecedores_evento', 'fornecedores_pagamentos', 'inspiracoes_fotos',
        'mapa_elementos', 'mesas', 'musicas_evento', 'notas_evento',
        'notas_comentarios', 'referencias_fornecedores', 'servicos_assessoria',
        'calendario_anotacoes',
    ];

    foreach ($tabelas as $tabela) {
        try { $pdo->query("SELECT assessoria_id FROM $tabela LIMIT 1"); }
        catch (Exception $e) {
            try { $pdo->exec("ALTER TABLE $tabela ADD COLUMN assessoria_id INT NULL"); }
            catch (Exception $e2) { continue; } // tabela pode não existir nesta instalação
        }
        // Backfill: como hoje só existe a assessoria padrão, todo dado atual
        // é dela. UPDATE só toca quem ainda estiver NULL — repetir não duplica.
        try { $pdo->exec("UPDATE $tabela SET assessoria_id = $assessoria_padrao_id WHERE assessoria_id IS NULL"); }
        catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE $tabela MODIFY assessoria_id INT NOT NULL"); }
        catch (Exception $e) {}
        try { $pdo->exec("CREATE INDEX idx_{$tabela}_assessoria ON $tabela (assessoria_id)"); }
        catch (Exception $e) {} // índice já existe
        try { $pdo->exec("ALTER TABLE $tabela ADD CONSTRAINT fk_{$tabela}_assessoria FOREIGN KEY (assessoria_id) REFERENCES assessorias(id)"); }
        catch (Exception $e) {} // constraint já existe
    }

    // usuarios: NULL fica reservado pra contas tipo='desenvolvedor' (enxergam
    // todas as assessorias) — só backfill quem já existe hoje (admin/assistente).
    try { $pdo->query("SELECT assessoria_id FROM usuarios LIMIT 1"); }
    catch (Exception $e) {
        try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN assessoria_id INT NULL"); } catch (Exception $e2) {}
    }
    try {
        $pdo->exec("UPDATE usuarios SET assessoria_id = $assessoria_padrao_id WHERE assessoria_id IS NULL AND tipo IN ('admin', 'assistente')");
    } catch (Exception $e) {}
    try { $pdo->exec("CREATE INDEX idx_usuarios_assessoria ON usuarios (assessoria_id)"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE usuarios ADD CONSTRAINT fk_usuarios_assessoria FOREIGN KEY (assessoria_id) REFERENCES assessorias(id)"); } catch (Exception $e) {}

    marcar_schema_verificado('assessoria_id_v1');
}
garantir_coluna_assessoria_id($pdo);
?>