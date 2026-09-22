<?php
// Dicionário central de terminologia por módulo de evento.
// Um único evento sempre pertence a um "tipo_evento" gravado em eventos.tipo_evento;
// a sessão da equipe guarda o módulo escolhido no hub em $_SESSION['modulo_ativo'].
// Casamento é o default histórico — precisa continuar idêntico ao texto original.

const MODULOS_EVENTO_VALIDOS = ['casamento', 'aniversario', 'corporativo', 'academico'];

function modulo_evento_valido(?string $tipo): bool {
    return in_array($tipo, MODULOS_EVENTO_VALIDOS, true);
}

// Módulos que um usuário de equipe (admin/assistente) pode ver no hub. Enquanto o
// desenvolvedor não configurar nada pra esse usuário (linha nenhuma na tabela),
// o padrão é só liberar Casamentos — o módulo histórico, já em uso antes desta
// funcionalidade — pra não tirar acesso de ninguém sem querer nesse meio-tempo.
function modulos_liberados_usuario(PDO $pdo, int $usuario_id): array {
    $stmt = $pdo->prepare("SELECT tipo_evento FROM usuarios_modulos_liberados WHERE usuario_id = ?");
    $stmt->execute([$usuario_id]);
    $liberados = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (empty($liberados)) {
        return ['casamento'];
    }
    return array_values(array_intersect($liberados, MODULOS_EVENTO_VALIDOS));
}

// Versão em lote pra telas que listam vários usuários (dev_painel.php) — 1 consulta
// pra todo mundo em vez de 1 por usuário. Retorna [usuario_id => [tipos liberados]];
// usuário sem nenhuma linha simplesmente não aparece no array (chame com `?? ['casamento']`).
function modulos_liberados_todos_usuarios(PDO $pdo): array {
    $stmt = $pdo->query("SELECT usuario_id, tipo_evento FROM usuarios_modulos_liberados");
    $por_usuario = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        if (!in_array($linha['tipo_evento'], MODULOS_EVENTO_VALIDOS, true)) continue;
        $por_usuario[(int)$linha['usuario_id']][] = $linha['tipo_evento'];
    }
    return $por_usuario;
}

// Versão ciente da sessão: o desenvolvedor enxerga e usa todos os módulos sempre,
// sem depender de linha nenhuma em usuarios_modulos_liberados (essa tabela só
// controla o acesso de admin/assistente).
function modulos_liberados_sessao(PDO $pdo): array {
    if (($_SESSION['usuario_tipo'] ?? '') === 'desenvolvedor') {
        return MODULOS_EVENTO_VALIDOS;
    }
    return modulos_liberados_usuario($pdo, (int)($_SESSION['usuario_id'] ?? 0));
}

function salvar_modulos_liberados_usuario(PDO $pdo, int $usuario_id, array $modulos): void {
    $modulos = array_values(array_intersect($modulos, MODULOS_EVENTO_VALIDOS));
    $pdo->prepare("DELETE FROM usuarios_modulos_liberados WHERE usuario_id = ?")->execute([$usuario_id]);
    $stmt = $pdo->prepare("INSERT INTO usuarios_modulos_liberados (usuario_id, tipo_evento) VALUES (?, ?)");
    foreach ($modulos as $tipo) {
        $stmt->execute([$usuario_id, $tipo]);
    }
}

function adicionar_modulo_liberado_usuario(PDO $pdo, int $usuario_id, string $tipo): void {
    if (!modulo_evento_valido($tipo)) return;
    $atuais = modulos_liberados_usuario($pdo, $usuario_id);
    if (!in_array($tipo, $atuais, true)) {
        $atuais[] = $tipo;
        salvar_modulos_liberados_usuario($pdo, $usuario_id, $atuais);
    }
}

// Vitrine de planos pagos mostrada nos módulos bloqueados do hub — puramente
// informativa por enquanto, sem cobrança automática nenhuma. O "Solicitar upgrade"
// só grava um pedido pendente; quem libera de fato é o desenvolvedor, manualmente,
// depois de combinar o pagamento por fora (Pix, WhatsApp etc).
const PLANOS_MODULO = [
    'casamento'   => ['nome' => 'Plano Casamentos',    'preco' => 59.90],
    'aniversario' => ['nome' => 'Plano Aniversários',  'preco' => 39.90],
    'corporativo' => ['nome' => 'Plano Corporativo',   'preco' => 79.90],
    'academico'   => ['nome' => 'Plano Acadêmico',     'preco' => 69.90],
];

// Monta o array de plano (com o cálculo de promoção ativa) a partir de uma linha
// de planos_modulo_config já em mãos — compartilhado por obter_plano_modulo() e
// pela versão em lote planos_todos_modulos(), pra não duplicar a lógica do período.
function montar_plano_modulo(string $tipo, ?array $linha): array {
    $nome = PLANOS_MODULO[$tipo]['nome'] ?? ucfirst($tipo);
    $preco_normal = PLANOS_MODULO[$tipo]['preco'] ?? 0.0;
    $preco_promocional = null;
    $promocao_inicio = null;
    $promocao_fim = null;

    if ($linha) {
        $preco_normal = (float)$linha['preco_normal'];
        $preco_promocional = $linha['preco_promocional'] !== null ? (float)$linha['preco_promocional'] : null;
        $promocao_inicio = $linha['promocao_inicio'];
        $promocao_fim = $linha['promocao_fim'];
    }

    $hoje = date('Y-m-d');
    $em_promocao = $preco_promocional !== null && $promocao_inicio && $promocao_fim
        && $hoje >= $promocao_inicio && $hoje <= $promocao_fim;

    return [
        'nome' => $nome,
        'preco' => $em_promocao ? $preco_promocional : $preco_normal,
        'preco_normal' => $preco_normal,
        'preco_promocional' => $preco_promocional,
        'promocao_inicio' => $promocao_inicio,
        'promocao_fim' => $promocao_fim,
        'em_promocao' => $em_promocao,
    ];
}

// Preço efetivo de um módulo, já considerando promoção ativa (se o dia de hoje
// cair dentro do período configurado). O desenvolvedor edita tudo isso em
// dev_painel.php; sem nenhuma linha salva, usa o valor padrão de PLANOS_MODULO.
function obter_plano_modulo(PDO $pdo, string $tipo): array {
    $stmt = $pdo->prepare("SELECT preco_normal, preco_promocional, promocao_inicio, promocao_fim FROM planos_modulo_config WHERE tipo_evento = ?");
    $stmt->execute([$tipo]);
    return montar_plano_modulo($tipo, $stmt->fetch(PDO::FETCH_ASSOC) ?: null);
}

// Versão em lote — 1 consulta pra todos os módulos em vez de 1 por módulo.
// Retorna [tipo_evento => plano] pra todos os 4 tipos válidos.
function planos_todos_modulos(PDO $pdo): array {
    $stmt = $pdo->query("SELECT * FROM planos_modulo_config");
    $linhas_por_tipo = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
        $linhas_por_tipo[$linha['tipo_evento']] = $linha;
    }
    $planos = [];
    foreach (MODULOS_EVENTO_VALIDOS as $tipo) {
        $planos[$tipo] = montar_plano_modulo($tipo, $linhas_por_tipo[$tipo] ?? null);
    }
    return $planos;
}

function salvar_plano_modulo(PDO $pdo, string $tipo, float $preco_normal, ?float $preco_promocional, ?string $promocao_inicio, ?string $promocao_fim): void {
    if (!modulo_evento_valido($tipo)) return;
    $pdo->prepare("
        INSERT INTO planos_modulo_config (tipo_evento, preco_normal, preco_promocional, promocao_inicio, promocao_fim)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            preco_normal = VALUES(preco_normal),
            preco_promocional = VALUES(preco_promocional),
            promocao_inicio = VALUES(promocao_inicio),
            promocao_fim = VALUES(promocao_fim)
    ")->execute([$tipo, $preco_normal, $preco_promocional, $promocao_inicio, $promocao_fim]);
}

// O que cada plano inclui — mostrado no modal de detalhes do hub antes da pessoa
// pedir o upgrade, pra justificar o preço em vez de só exibir um valor solto.
const RECURSOS_MODULO = [
    'casamento' => [
        'Checklist de tarefas personalizado',
        'Lista de convidados com confirmação (RSVP) automática',
        'Portal exclusivo para os noivos acompanharem tudo',
        'Organização visual de mesas e fornecedores',
    ],
    'aniversario' => [
        'Checklist de tarefas personalizado',
        'Lista de convidados com confirmação (RSVP) automática',
        'Portal exclusivo para o aniversariante/família',
        'Organização de mesas, playlist e fornecedores',
    ],
    'corporativo' => [
        'Checklist de produção do evento',
        'Gestão de convidados e credenciamento',
        'Portal exclusivo para a empresa contratante',
        'Controle de fornecedores e orçamento',
    ],
    'academico' => [
        'Checklist de formatura/colação de grau',
        'Lista de formandos e convidados com RSVP',
        'Portal exclusivo para a comissão organizadora',
        'Controle de fornecedores e cerimonial',
    ],
];

function existe_solicitacao_pendente(PDO $pdo, int $usuario_id, string $tipo): bool {
    $stmt = $pdo->prepare("SELECT id FROM solicitacoes_upgrade WHERE usuario_id = ? AND tipo_evento = ? AND atendido = 0");
    $stmt->execute([$usuario_id, $tipo]);
    return (bool)$stmt->fetch();
}

// Versão em lote — 1 consulta pra saber todos os módulos com pedido pendente
// desse usuário, em vez de 1 consulta por módulo bloqueado.
function tipos_solicitacao_pendente_usuario(PDO $pdo, int $usuario_id): array {
    $stmt = $pdo->prepare("SELECT tipo_evento FROM solicitacoes_upgrade WHERE usuario_id = ? AND atendido = 0");
    $stmt->execute([$usuario_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function criar_solicitacao_upgrade(PDO $pdo, int $usuario_id, string $tipo): void {
    if (!modulo_evento_valido($tipo) || existe_solicitacao_pendente($pdo, $usuario_id, $tipo)) return;
    $pdo->prepare("INSERT INTO solicitacoes_upgrade (usuario_id, tipo_evento) VALUES (?, ?)")->execute([$usuario_id, $tipo]);
}

const CORES_MODULO_PADRAO = [
    'casamento'   => '#d6336c',
    'aniversario' => '#f59f00',
    'corporativo' => '#0d6efd',
    'academico'   => '#198754',
];

function cor_modulo_evento(PDO $pdo, ?string $tipo): string {
    $tipo = modulo_evento_valido($tipo) ? $tipo : 'casamento';
    $stmt = $pdo->prepare("SELECT cor_tema FROM modulos_config WHERE tipo_evento = ?");
    $stmt->execute([$tipo]);
    $cor = $stmt->fetchColumn();
    if ($cor && preg_match('/^#[0-9a-fA-F]{6}$/', $cor)) {
        return $cor;
    }
    return CORES_MODULO_PADRAO[$tipo];
}

// Versão em lote — 1 consulta pra pegar a cor de todos os módulos de uma vez,
// usada em telas (hub, painel do dev) que mostram os 4 módulos ao mesmo tempo.
function cores_todos_modulos(PDO $pdo): array {
    $stmt = $pdo->query("SELECT tipo_evento, cor_tema FROM modulos_config");
    $salvas = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $cores = [];
    foreach (MODULOS_EVENTO_VALIDOS as $tipo) {
        $cor = $salvas[$tipo] ?? null;
        $cores[$tipo] = ($cor && preg_match('/^#[0-9a-fA-F]{6}$/', $cor)) ? $cor : CORES_MODULO_PADRAO[$tipo];
    }
    return $cores;
}

// Cor de identidade do evento específico: o próprio cliente escolhe a dele (cor_convite,
// hoje editável em noivos.php) e ela passa a valer em todo o painel daquele evento
// (cliente e equipe). Sem cor própria ainda, cai na cor padrão do módulo (definida no hub).
function cor_painel_evento(PDO $pdo, array $evento): string {
    if (!empty($evento['cor_convite']) && preg_match('/^#[0-9a-fA-F]{6}$/', $evento['cor_convite'])) {
        return $evento['cor_convite'];
    }
    return cor_modulo_evento($pdo, $evento['tipo_evento'] ?? null);
}

// Título e subtítulo do cabeçalho do evento: só em Casamentos os dois nomes
// são pares (Noiva & Noivo) e ficam juntos num "X & Y" — nos outros módulos
// o 2º nome é o responsável pelo evento, um papel diferente, então vira uma
// linha de subtítulo separada em vez de ficar concatenado com "&" (o que
// deixava "Faculdade Central & Rodrigo Pires" parecendo duas instituições).
function titulo_subtitulo_evento(string $tipoEvento, string $nomePrincipal, ?string $nomeSecundario, string $subtituloBase): array {
    $nomeSecundario = trim((string)$nomeSecundario);
    if ($tipoEvento === 'casamento') {
        $titulo = $nomePrincipal . ($nomeSecundario !== '' ? ' & ' . $nomeSecundario : '');
        return [$titulo, $subtituloBase];
    }
    $subtitulo = $nomeSecundario !== '' ? 'Responsável: ' . $nomeSecundario . ' · ' . $subtituloBase : $subtituloBase;
    return [$nomePrincipal, $subtitulo];
}

function salvar_cor_modulo_evento(PDO $pdo, string $tipo, string $cor): bool {
    if (!modulo_evento_valido($tipo) || !preg_match('/^#[0-9a-fA-F]{6}$/', $cor)) {
        return false;
    }
    $stmt = $pdo->prepare("
        INSERT INTO modulos_config (tipo_evento, cor_tema) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE cor_tema = VALUES(cor_tema)
    ");
    return $stmt->execute([$tipo, $cor]);
}

// Clareia (percentual positivo) ou escurece (negativo) uma cor hex — mesmo algoritmo
// da função ajustarCor() em JS (noivos.php), usado aqui do lado servidor.
function ajustar_cor(string $hex, float $percent): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    if ($percent >= 0) {
        $r += (255 - $r) * $percent;
        $g += (255 - $g) * $percent;
        $b += (255 - $b) * $percent;
    } else {
        $r *= (1 + $percent);
        $g *= (1 + $percent);
        $b *= (1 + $percent);
    }
    $toHex = fn($v) => str_pad(dechex((int)max(0, min(255, round($v)))), 2, '0', STR_PAD_LEFT);
    return '#' . $toHex($r) . $toHex($g) . $toHex($b);
}

// Todo o visual do painel (cabeçalhos em gradiente, badges, hovers, etc.) é construído
// em cima das variáveis CSS --color-primary/-dark/-light (ver css/estilo.css). Sobrescrever
// essas 3 variáveis, com base numa única cor escolhida pelo cliente/equipe, faz a cor se
// propagar pro painel inteiro sem precisar caçar cada seletor individualmente.
function estilo_tema_evento(string $corBase): string {
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $corBase)) {
        $corBase = '#a9744f';
    }
    $primaria = $corBase;
    $escura   = ajustar_cor($corBase, -0.18);
    $clara    = ajustar_cor($corBase, 0.85);
    return '<style>:root { --color-primary: ' . htmlspecialchars($primaria, ENT_QUOTES, 'UTF-8')
        . '; --color-primary-dark: ' . htmlspecialchars($escura, ENT_QUOTES, 'UTF-8')
        . '; --color-primary-light: ' . htmlspecialchars($clara, ENT_QUOTES, 'UTF-8') . '; }</style>';
}

// Par de formas decorativas (efeito "traçado à mão") no fundo do cabeçalho de
// gerenciar.php/noivos.php — corações para casamento, e um equivalente temático
// pros demais módulos, pra não ficar coração em evento de empresa/formatura.
function decoracao_hero_svg(?string $tipo): string {
    $formas = [
        'casamento' => '
            <path d="M62,42 C42,20 8,32 8,58 C8,84 42,98 62,120 C82,98 116,84 116,58 C116,32 82,20 62,42 Z" fill="none" stroke="rgba(255,222,160,.95)" stroke-width="3.5"/>
            <path d="M104,74 C90,60 68,68 68,86 C68,104 90,112 104,128 C118,112 140,104 140,86 C140,68 118,60 104,74 Z" fill="none" stroke="rgba(255,222,160,.8)" stroke-width="3.5"/>
        ',
        // Balões de festa lado a lado, sem se sobrepor (cada um com sua faixa
        // de largura própria), com cordinha e um brilho pra não ficar chapado.
        'aniversario' => '
            <path d="M38,8 C56,8 68,34 68,51 C68,69 53,82 38,93 C23,82 8,69 8,51 C8,34 20,8 38,8 Z M34,93 Q38,99 42,93 M38,99 C36,105 41,109 38,115 C35,121 40,124 38,130" fill="none" stroke="rgba(255,222,160,.95)" stroke-width="3.5"/>
            <path d="M98,6 C114,6 124,28 124,42 C124,58 111,69 98,78 C85,69 72,58 72,42 C72,28 82,6 98,6 Z M94,78 Q98,84 102,78 M98,84 C96,89 100,93 98,98 C96,103 100,106 98,111" fill="none" stroke="rgba(255,222,160,.8)" stroke-width="3.5"/>
            <path d="M150,44 C162,44 170,61 170,72 C170,84 160,93 150,100 C140,93 130,84 130,72 C130,61 138,44 150,44 Z M147,100 Q150,104 153,100 M150,104 C148,108 152,111 150,115 C148,119 152,122 150,126" fill="none" stroke="rgba(255,222,160,.65)" stroke-width="3.5"/>
            <path d="M186,86 C194,86 199,97 199,104 C199,112 192,117 186,122 C180,117 173,112 173,104 C173,97 178,86 186,86 Z M183,122 Q186,126 189,122 M186,126 C184,130 188,133 186,137" fill="none" stroke="rgba(255,222,160,.5)" stroke-width="3"/>
            <path d="M20,32 Q26,16 44,19" fill="none" stroke="rgba(255,255,255,.6)" stroke-width="3" stroke-linecap="round"/>
            <path d="M80,29 Q86,15 102,18" fill="none" stroke="rgba(255,255,255,.55)" stroke-width="2.6" stroke-linecap="round"/>
            <path d="M137,58 Q142,48 155,50" fill="none" stroke="rgba(255,255,255,.5)" stroke-width="2.2" stroke-linecap="round"/>
            <path d="M178,94 Q182,88 190,90" fill="none" stroke="rgba(255,255,255,.45)" stroke-width="2" stroke-linecap="round"/>
        ',
        // Duas maletas executivas
        'corporativo' => '
            <path d="M40,50 L40,40 Q40,30 50,30 L62,30 Q72,30 72,40 L72,50 M20,50 L92,50 Q100,50 100,58 L100,104 Q100,112 92,112 L20,112 Q12,112 12,104 L12,58 Q12,50 20,50 Z" fill="none" stroke="rgba(255,222,160,.95)" stroke-width="3.5"/>
            <path d="M96,80 L96,72 Q96,64 104,64 L112,64 Q120,64 120,72 L120,80 M80,80 L140,80 Q147,80 147,87 L147,124 Q147,131 140,131 L80,131 Q73,131 73,124 L73,87 Q73,80 80,80 Z" fill="none" stroke="rgba(255,222,160,.8)" stroke-width="3.5"/>
        ',
        // Dois capelos de formatura
        'academico' => '
            <path d="M20,55 L62,35 L104,55 L62,75 Z M50,72 L50,88 Q50,96 62,96 Q74,96 74,88 L74,72 M96,53 L96,80 Q96,88 88,90" fill="none" stroke="rgba(255,222,160,.95)" stroke-width="3.5"/>
            <path d="M64,95 L100,80 L136,95 L100,110 Z M92,107 L92,120 Q92,127 100,127 Q108,127 108,120 L108,107" fill="none" stroke="rgba(255,222,160,.8)" stroke-width="3.5"/>
        ',
    ];
    return $formas[$tipo] ?? $formas['casamento'];
}

function labels_modulo_evento(?string $tipo): array {
    $dicionario = [
        'casamento' => [
            'nome_modulo'               => 'Casamentos',
            'singular_contratante'      => 'casal',
            'botao_novo_evento'         => 'Novo Casamento',
            'modal_novo_titulo'         => 'Adicionar Novo Casamento',
            'botao_criar_evento'        => 'Criar Casamento',
            'secao_acesso_titulo'       => 'Acesso e Dados dos Noivos',
            'campo_nome1_label'         => 'Nome (Noiva / Cônjuge 1) *',
            'campo_nome1_placeholder'   => 'Ex: Ana Maria',
            'campo_nome2_label'         => 'Nome (Noivo / Cônjuge 2) *',
            'campo_nome2_placeholder'   => 'Ex: Lucas Mendes',
            'campo_nome2_obrigatorio'   => true,
            'secao_detalhes_titulo'     => 'Detalhes do Grande Dia',
            'coluna_tabela_contato'     => 'Casal & Contatos',
            'vazio_futuros'             => 'Nenhum casamento futuro agendado.',
            'vazio_cadastro'            => 'Nenhum casal cadastrado.',
            'vazio_busca'               => 'Nenhum casal encontrado.',
            'prefixo_calendario_dia'    => 'Casamento:',
            'modal_editar_cadastro_titulo' => 'Editar Cadastro do Casal',
            'label_nome_cadastro'       => 'Nome do Casal',
            'label_nova_senha'          => 'Nova Senha do Casal',
            'notif_dropdown_titulo'     => 'Atividade dos Casais',
            'header_hero_prefixo'       => 'Casamento de',
            'placeholder_nota_checklist'   => 'Nota geral para os noivos…',
            'sufixo_visivel_cliente'    => 'visível ao casal',
            'placeholder_etapa_exemplo' => 'Ex: Pré-Casamento',
            'placeholder_anotacao_cliente' => 'Escreva aqui a anotação para o casal…',
            'msg_whatsapp_convite'      => 'Confirme sua presença no casamento de',
            'label_foto_convite'        => 'Foto do casal no convite',
            'pdf_titulo_prefixo'        => 'Casamento de',
            'titulo_pagina_cliente'     => 'Nosso Casamento ♡',
            'header_hero_label_cliente' => 'Nosso Casamento',
            'subtitulo_documentos_cliente' => 'Contrato, documentos e comprovantes do casamento de vocês',
            'saudacao_convite_padrao'   => 'Contamos com a sua presença! Você poderá comparecer ao nosso grande dia?',
            'icone_convite_publico'     => 'bi-rings',
            'emoji_agradecimento'       => '💍',
            'placeholder_exemplo_momento_musica' => 'Momento (Ex: Entrada da Noiva)',
            'momentos_evento' => [
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
            ],
        ],
        'aniversario' => [
            'nome_modulo'               => 'Aniversários',
            'singular_contratante'      => 'aniversariante',
            'botao_novo_evento'         => 'Novo Aniversário',
            'modal_novo_titulo'         => 'Adicionar Novo Aniversário',
            'botao_criar_evento'        => 'Criar Aniversário',
            'secao_acesso_titulo'       => 'Acesso e Dados do Aniversariante',
            'campo_nome1_label'         => 'Nome do Aniversariante *',
            'campo_nome1_placeholder'   => 'Ex: Maria Souza',
            'campo_nome2_label'         => 'Nome de quem organiza / acompanhante',
            'campo_nome2_placeholder'   => 'Ex: Carlos Souza (opcional)',
            'campo_nome2_obrigatorio'   => false,
            'secao_detalhes_titulo'     => 'Detalhes da Festa',
            'coluna_tabela_contato'     => 'Aniversariante & Contatos',
            'vazio_futuros'             => 'Nenhum aniversário futuro agendado.',
            'vazio_cadastro'            => 'Nenhum aniversariante cadastrado.',
            'vazio_busca'               => 'Nenhum aniversariante encontrado.',
            'prefixo_calendario_dia'    => 'Aniversário:',
            'modal_editar_cadastro_titulo' => 'Editar Cadastro do Aniversariante',
            'label_nome_cadastro'       => 'Nome do Aniversariante',
            'label_nova_senha'          => 'Nova Senha do Aniversariante',
            'notif_dropdown_titulo'     => 'Atividade dos Aniversariantes',
            'header_hero_prefixo'       => 'Aniversário de',
            'placeholder_nota_checklist'   => 'Nota geral para o aniversariante…',
            'sufixo_visivel_cliente'    => 'visível ao aniversariante',
            'placeholder_etapa_exemplo' => 'Ex: Pré-Festa',
            'placeholder_anotacao_cliente' => 'Escreva aqui a anotação para o aniversariante…',
            'msg_whatsapp_convite'      => 'Confirme sua presença no aniversário de',
            'label_foto_convite'        => 'Foto no convite',
            'pdf_titulo_prefixo'        => 'Aniversário de',
            'titulo_pagina_cliente'     => 'Nosso Aniversário 🎉',
            'header_hero_label_cliente' => 'Nosso Aniversário',
            'subtitulo_documentos_cliente' => 'Contrato, documentos e comprovantes do aniversário',
            'saudacao_convite_padrao'   => 'Contamos com a sua presença! Você poderá comparecer à nossa festa?',
            'icone_convite_publico'     => 'bi-balloon-fill',
            'emoji_agradecimento'       => '🎉',
            'placeholder_exemplo_momento_musica' => 'Momento (Ex: Parabéns / Corte do Bolo)',
            'momentos_evento' => [
                'Recepção · Chegada dos Convidados',
                'Festa · Abertura / Boas-vindas',
                'Festa · Parabéns / Corte do Bolo',
                'Festa · Hora da Dança',
                'Festa · Encerramento',
                'Livre / Sem Momento Definido',
            ],
        ],
        'corporativo' => [
            'nome_modulo'               => 'Eventos Corporativos',
            'singular_contratante'      => 'responsável pela empresa',
            'botao_novo_evento'         => 'Novo Evento Corporativo',
            'modal_novo_titulo'         => 'Adicionar Novo Evento Corporativo',
            'botao_criar_evento'        => 'Criar Evento',
            'secao_acesso_titulo'       => 'Acesso e Dados do Responsável',
            'campo_nome1_label'         => 'Nome da Empresa / Responsável *',
            'campo_nome1_placeholder'   => 'Ex: Empresa XYZ Ltda',
            'campo_nome2_label'         => 'Nome do responsável pelo evento',
            'campo_nome2_placeholder'   => 'Ex: João Pereira (opcional)',
            'campo_nome2_obrigatorio'   => false,
            'secao_detalhes_titulo'     => 'Detalhes do Evento',
            'coluna_tabela_contato'     => 'Empresa & Contatos',
            'vazio_futuros'             => 'Nenhum evento corporativo futuro agendado.',
            'vazio_cadastro'            => 'Nenhum evento corporativo cadastrado.',
            'vazio_busca'               => 'Nenhum evento corporativo encontrado.',
            'prefixo_calendario_dia'    => 'Evento Corporativo:',
            'modal_editar_cadastro_titulo' => 'Editar Cadastro da Empresa',
            'label_nome_cadastro'       => 'Nome da Empresa',
            'label_nova_senha'          => 'Nova Senha do Responsável',
            'notif_dropdown_titulo'     => 'Atividade dos Responsáveis',
            'header_hero_prefixo'       => 'Evento Corporativo de',
            'placeholder_nota_checklist'   => 'Nota geral para o responsável…',
            'sufixo_visivel_cliente'    => 'visível ao responsável',
            'placeholder_etapa_exemplo' => 'Ex: Pré-Evento',
            'placeholder_anotacao_cliente' => 'Escreva aqui a anotação para o responsável…',
            'msg_whatsapp_convite'      => 'Confirme sua presença no evento corporativo de',
            'label_foto_convite'        => 'Foto no convite',
            'pdf_titulo_prefixo'        => 'Evento Corporativo de',
            'titulo_pagina_cliente'     => 'Nosso Evento',
            'header_hero_label_cliente' => 'Nosso Evento',
            'subtitulo_documentos_cliente' => 'Contrato, documentos e comprovantes do evento',
            'saudacao_convite_padrao'   => 'Contamos com a sua presença! Você poderá comparecer ao nosso evento?',
            'icone_convite_publico'     => 'bi-briefcase-fill',
            'emoji_agradecimento'       => '🤝',
            'placeholder_exemplo_momento_musica' => 'Momento (Ex: Abertura / Boas-vindas)',
            'momentos_evento' => [
                'Recepção · Credenciamento',
                'Abertura · Discurso / Boas-vindas',
                'Programação · Coffee Break',
                'Programação · Palestras / Painéis',
                'Encerramento · Networking',
                'Livre / Sem Momento Definido',
            ],
        ],
        'academico' => [
            'nome_modulo'               => 'Eventos Acadêmicos e Educacionais',
            'singular_contratante'      => 'responsável pela instituição',
            'botao_novo_evento'         => 'Novo Evento Acadêmico',
            'modal_novo_titulo'         => 'Adicionar Novo Evento Acadêmico',
            'botao_criar_evento'        => 'Criar Evento',
            'secao_acesso_titulo'       => 'Acesso e Dados do Responsável',
            'campo_nome1_label'         => 'Nome da Instituição / Turma *',
            'campo_nome1_placeholder'   => 'Ex: Colégio ABC - Turma 3º Ano',
            'campo_nome2_label'         => 'Nome do responsável pelo evento',
            'campo_nome2_placeholder'   => 'Ex: Profª. Marta Lima (opcional)',
            'campo_nome2_obrigatorio'   => false,
            'secao_detalhes_titulo'     => 'Detalhes do Evento',
            'coluna_tabela_contato'     => 'Instituição & Contatos',
            'vazio_futuros'             => 'Nenhum evento acadêmico futuro agendado.',
            'vazio_cadastro'            => 'Nenhum evento acadêmico cadastrado.',
            'vazio_busca'               => 'Nenhum evento acadêmico encontrado.',
            'prefixo_calendario_dia'    => 'Evento Acadêmico:',
            'modal_editar_cadastro_titulo' => 'Editar Cadastro da Instituição',
            'label_nome_cadastro'       => 'Nome da Instituição',
            'label_nova_senha'          => 'Nova Senha do Responsável',
            'notif_dropdown_titulo'     => 'Atividade dos Responsáveis',
            'header_hero_prefixo'       => 'Evento Acadêmico de',
            'placeholder_nota_checklist'   => 'Nota geral para o responsável…',
            'sufixo_visivel_cliente'    => 'visível ao responsável',
            'placeholder_etapa_exemplo' => 'Ex: Pré-Evento',
            'placeholder_anotacao_cliente' => 'Escreva aqui a anotação para o responsável…',
            'msg_whatsapp_convite'      => 'Confirme sua presença no evento acadêmico de',
            'label_foto_convite'        => 'Foto no convite',
            'pdf_titulo_prefixo'        => 'Evento Acadêmico de',
            'titulo_pagina_cliente'     => 'Nosso Evento',
            'header_hero_label_cliente' => 'Nosso Evento',
            'subtitulo_documentos_cliente' => 'Contrato, documentos e comprovantes do evento',
            'saudacao_convite_padrao'   => 'Contamos com a sua presença! Você poderá comparecer ao nosso evento?',
            'icone_convite_publico'     => 'bi-mortarboard-fill',
            'emoji_agradecimento'       => '🎓',
            'placeholder_exemplo_momento_musica' => 'Momento (Ex: Entrada / Abertura)',
            'momentos_evento' => [
                'Recepção · Chegada dos Convidados',
                'Cerimônia · Abertura',
                'Cerimônia · Entrega de Diplomas / Certificados',
                'Cerimônia · Discursos / Homenagens',
                'Encerramento · Confraternização',
                'Livre / Sem Momento Definido',
            ],
        ],
    ];

    return $dicionario[$tipo] ?? $dicionario['casamento'];
}
?>
