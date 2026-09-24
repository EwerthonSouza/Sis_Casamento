<?php
session_start();
require_once 'sessao_timeout.inc.php';
verificar_sessao_ativa();

require_once 'conexao.php';
require_once 'modulos_evento.inc.php';
require_once 'notificacoes.inc.php';

// ============================================================
// TRAVA DE SEGURANÇA: Admin, Assistente e Desenvolvedor acessam esta página
// ============================================================
if (!isset($_SESSION['usuario_tipo']) || !in_array($_SESSION['usuario_tipo'], ['admin', 'assistente', 'desenvolvedor'])) {
    header("Location: index.php?sessao_expirada=1");
    exit;
}

garantir_coluna_tipo_evento($pdo);
garantir_tabela_modulos_config($pdo);
garantir_tabela_modulos_liberados($pdo);
garantir_tabela_solicitacoes_upgrade($pdo);
garantir_tabela_planos_modulo($pdo);

$modulos_liberados = modulos_liberados_sessao($pdo);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Salvar a cor de identidade visual escolhida para o módulo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_cor') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Sessão inválida. Recarregue a página e tente novamente.");
    }
    $tipo_cor = $_POST['tipo_evento'] ?? '';
    $cor_escolhida = $_POST['cor'] ?? '';
    if (modulo_evento_valido($tipo_cor) && in_array($tipo_cor, $modulos_liberados, true)) {
        salvar_cor_modulo_evento($pdo, $tipo_cor, $cor_escolhida);
    }
    header("Location: hub_modulos.php");
    exit;
}

// Pedido de upgrade de plano pra um módulo bloqueado — só registra o pedido,
// quem libera de verdade é o desenvolvedor em dev_painel.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'solicitar_upgrade') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Sessão inválida. Recarregue a página e tente novamente.");
    }
    $tipo_solicitado = $_POST['tipo_evento'] ?? '';
    if (modulo_evento_valido($tipo_solicitado) && !in_array($tipo_solicitado, $modulos_liberados, true)) {
        criar_solicitacao_upgrade($pdo, (int)$_SESSION['usuario_id'], $tipo_solicitado);
        $_SESSION['msg_sucesso_hub'] = "Pedido enviado! O desenvolvedor vai liberar assim que confirmar o pagamento.";
    }
    header("Location: hub_modulos.php");
    exit;
}

$msg_sucesso = $_SESSION['msg_sucesso_hub'] ?? null;
unset($_SESSION['msg_sucesso_hub']);

// Escolha do módulo: nunca aceita o tipo vindo da URL sem checar a whitelist
// E sem checar se o desenvolvedor liberou esse módulo pra este usuário.
if (isset($_GET['modulo']) && modulo_evento_valido($_GET['modulo']) && in_array($_GET['modulo'], $modulos_liberados, true)) {
    $_SESSION['modulo_ativo'] = $_GET['modulo'];
    header("Location: painel_admin.php");
    exit;
}

$eh_desenvolvedor = ($_SESSION['usuario_tipo'] === 'desenvolvedor');

$stmt_contagem = $pdo->query("SELECT tipo_evento, COUNT(*) AS total FROM eventos GROUP BY tipo_evento");
$contagem_por_modulo = array_fill_keys(MODULOS_EVENTO_VALIDOS, 0);
foreach ($stmt_contagem->fetchAll() as $linha) {
    if (isset($contagem_por_modulo[$linha['tipo_evento']])) {
        $contagem_por_modulo[$linha['tipo_evento']] = (int)$linha['total'];
    }
}

$cards_modulo = [
    ['tipo' => 'casamento',   'icone' => 'bi-heart-fill'],
    ['tipo' => 'aniversario', 'icone' => 'bi-balloon-fill'],
    ['tipo' => 'corporativo', 'icone' => 'bi-briefcase-fill'],
    ['tipo' => 'academico',   'icone' => 'bi-mortarboard-fill'],
];

// Separa o que o usuário já administra do que ainda é só uma oferta de upgrade —
// os ativos ficam em destaque na área principal, os bloqueados viram uma lista
// compacta "pra adquirir" de canto, em vez de cards do mesmo tamanho misturados.
// Tudo buscado em lote (1 consulta por tabela) em vez de 1 consulta por módulo.
$cores_modulos = cores_todos_modulos($pdo);
$planos_modulos = planos_todos_modulos($pdo);
$tipos_pendentes = tipos_solicitacao_pendente_usuario($pdo, (int)$_SESSION['usuario_id']);
// Mesmo controle "item a item" usado dentro do painel (chave por notificação),
// pra bater com o que a pessoa já marcou como visto lá dentro — o antigo
// controle por escopo/timestamp nunca era atualizado por ninguém, então o selo
// aqui no hub nunca baixava.
$vistas_notif_usuario = chaves_vistas_usuario($pdo, $_SESSION['usuario_tipo'], (int)($_SESSION['usuario_id'] ?? 0));

$modulos_ativos = [];
$modulos_bloqueados = [];
foreach ($cards_modulo as $card) {
    $item = $card;
    $item['labels'] = labels_modulo_evento($card['tipo']);
    $item['cor'] = $cores_modulos[$card['tipo']];
    $item['modal_id'] = 'modalDetalhes' . ucfirst($card['tipo']);
    if (in_array($card['tipo'], $modulos_liberados, true)) {
        $notif_modulo = buscar_notificacoes($pdo, null, 50, $card['tipo']);
        $item['notificacoes_novas'] = contar_nao_vistas($notif_modulo, $vistas_notif_usuario);
        $modulos_ativos[] = $item;
    } else {
        $item['plano'] = $planos_modulos[$card['tipo']];
        $item['recursos'] = RECURSOS_MODULO[$card['tipo']];
        $item['pedido_pendente'] = in_array($card['tipo'], $tipos_pendentes, true);
        $modulos_bloqueados[] = $item;
    }
}

// Versões bem clareadas da cor de cada módulo — usadas só no gradiente decorativo
// do painel de boas-vindas, pra representar os 4 tipos de evento ali mesmo.
$cores_por_tipo = array_column(array_merge($modulos_ativos, $modulos_bloqueados), 'cor', 'tipo');
$pastel_casamento   = ajustar_cor($cores_por_tipo['casamento']   ?? CORES_MODULO_PADRAO['casamento'], 0.82);
$pastel_aniversario = ajustar_cor($cores_por_tipo['aniversario'] ?? CORES_MODULO_PADRAO['aniversario'], 0.82);
$pastel_corporativo = ajustar_cor($cores_por_tipo['corporativo'] ?? CORES_MODULO_PADRAO['corporativo'], 0.82);
$pastel_academico   = ajustar_cor($cores_por_tipo['academico']   ?? CORES_MODULO_PADRAO['academico'], 0.82);
// As manchas desfocadas precisam de uma cor menos "lavada" que o fundo, senão o
// blur + baixa opacidade some de vez com um tom já quase branco.
$blob_casamento   = ajustar_cor($cores_por_tipo['casamento']   ?? CORES_MODULO_PADRAO['casamento'], 0.5);
$blob_corporativo = ajustar_cor($cores_por_tipo['corporativo'] ?? CORES_MODULO_PADRAO['corporativo'], 0.5);

// Números rápidos pra dar cara de painel de verdade, não só um seletor estático.
$total_eventos_usuario = array_sum(array_map(fn($c) => $contagem_por_modulo[$c['tipo']], $modulos_ativos));
$pedidos_pendentes_usuario = count(array_filter($modulos_bloqueados, fn($c) => $c['pedido_pendente']));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Selecionar Módulo - Meu Evento PRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/estilo.css?v=16">
    <style>
        body {
            font-family: 'Poppins', 'Inter', system-ui, sans-serif;
            background:
                radial-gradient(circle at 100% 0%, rgba(99,102,241,.06) 0%, transparent 40%),
                radial-gradient(circle at 0% 100%, rgba(214,51,108,.05) 0%, transparent 40%),
                #f7f7fb;
            min-height: 100vh;
        }

        .navbar-hub {
            background: linear-gradient(120deg, #16181d 0%, #2b2f3a 100%);
        }

        @keyframes fadeInUpHub {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Painel que envolve a saudação + o título dos módulos — dá peso visual
           ao topo da página em vez de deixar o texto solto sobre o fundo. */
        .painel-boas-vindas {
            position: relative;
            overflow: hidden;
            text-align: center;
            isolation: isolate;
            background: #ffffff;
            border: 1px solid #ece9fb;
            border-radius: 24px;
            padding: 1.5rem 2rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 12px 34px rgba(99,102,241,.09);
        }
        /* Degradê "fluindo" numa camada 3x mais larga que só desliza (transform
           roda na GPU) — animar background-position repintava o painel
           inteiro a cada quadro, sem parar, enquanto a página estava aberta. */
        .painel-boas-vindas::before {
            content: '';
            position: absolute;
            top: 0; bottom: 0; left: 0;
            width: 300%;
            z-index: -1;
            background: linear-gradient(120deg, #ffffff, #f3ecff, #e6f0ff, #fdecf7, #eef0fd, #ffffff);
            animation: gradienteFluidoHub 18s ease-in-out infinite alternate;
            pointer-events: none;
        }
        @keyframes gradienteFluidoHub {
            from { transform: translate3d(0, 0, 0); }
            to   { transform: translate3d(-66.666%, 0, 0); }
        }
        .decor-blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(46px);
            opacity: .4;
            pointer-events: none;
            animation: flutuarBlob 12s ease-in-out infinite;
        }
        .decor-blob-1 { width: 220px; height: 220px; background: #c4b5fd; top: -90px; left: -70px; }
        .decor-blob-2 { width: 260px; height: 260px; background: #93c5fd; bottom: -110px; right: -90px; animation-delay: -6s; }
        @keyframes flutuarBlob {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(18px, 14px) scale(1.08); }
        }

        .divisor-hero {
            position: relative;
            width: 44px; height: 3px;
            border-radius: 999px;
            background: linear-gradient(90deg, #6366f1, #a855f7);
            margin: .85rem auto;
        }

        /* Sequência de entrada: saudação primeiro, depois o título dos módulos,
           depois a lista de módulos em si — cada bloco com seu próprio atraso. */
        .boas-vindas-hub {
            position: relative;
            text-align: center;
            animation: fadeInUpHub .55s ease both;
        }
        .saudacao-hub {
            display: block;
            font-size: .82rem;
            font-weight: 600;
            color: #8b8e9a;
            margin-bottom: .1rem;
        }
        .nome-hub {
            font-size: clamp(1.3rem, 3vw, 1.8rem);
            font-weight: 800;
            letter-spacing: -.5px;
            margin: 0;
            color: #16181d;
        }

        .hero-hub {
            position: relative;
            text-align: center;
            animation: fadeInUpHub .55s ease both;
        }
        .hero-hub.hero-hub-atraso { animation-delay: .35s; }
        .hero-hub h2 { font-weight: 800; letter-spacing: -.5px; font-size: 1.3rem; margin-bottom: .35rem; }
        .hero-hub p { font-size: .85rem; }

        .conteudo-modulos-atraso {
            animation: fadeInUpHub .55s ease both;
            animation-delay: .7s;
        }
        .stats-hub-atraso {
            animation: fadeInUpHub .55s ease both;
            animation-delay: .5s;
        }

        .stat-chip-hub {
            display: flex;
            align-items: center;
            gap: .85rem;
            background: #fff;
            border: 1px solid #eceef3;
            border-radius: 18px;
            padding: 1rem 1.1rem;
            height: 100%;
            box-shadow: 0 4px 14px rgba(20, 20, 43, .05);
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .stat-chip-hub:hover { transform: translateY(-3px); box-shadow: 0 12px 26px rgba(20,20,43,.09); }
        .stat-icone-hub {
            width: 44px; height: 44px; border-radius: 13px;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 1.15rem;
            flex-shrink: 0;
        }
        .stat-valor-hub { font-size: 1.3rem; font-weight: 800; color: #16181d; line-height: 1.1; }
        .stat-rotulo-hub { font-size: .74rem; color: #8b8e9a; font-weight: 600; }

        .cabecalho-secao-hub {
            display: flex;
            align-items: center;
            gap: .65rem;
        }
        .icone-secao-hub {
            width: 34px; height: 34px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: .95rem;
            flex-shrink: 0;
        }
        .sub-rotulo-secao-hub { font-size: .76rem; color: #a4a7b3; font-weight: 500; }

        .footer-hub {
            color: #b3b6c1;
            font-size: .8rem;
        }

        .card-modulo {
            text-decoration: none;
            color: inherit;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
            border: 1px solid #eceef3;
            border-radius: 20px;
            background: #fff;
            box-shadow: 0 4px 14px rgba(20, 20, 43, .05);
        }
        .card-modulo::before {
            content: '';
            position: absolute; top: 0; left: 0; right: 0; height: 5px;
            background: var(--cor-solida-modulo, #6366f1);
        }
        .card-modulo:hover {
            transform: translateY(-6px);
            box-shadow: 0 18px 34px -10px var(--sombra-modulo, rgba(0,0,0,.18));
            border-color: var(--sombra-modulo, #d8dae6);
        }
        .icone-modulo {
            width: 68px; height: 68px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 22px;
            font-size: 1.7rem;
            color: #fff;
            box-shadow: 0 8px 18px -4px var(--sombra-modulo, rgba(0,0,0,.25));
            transition: transform .22s ease;
        }
        .icone-modulo-compacto {
            width: 48px; height: 48px;
            border-radius: 15px;
            font-size: 1.2rem;
        }
        .card-modulo:hover .icone-modulo { transform: scale(1.08) rotate(-3deg); }
        .card-modulo-compacto { border-radius: 16px; }
        .card-modulo-compacto h6 { font-size: .92rem; }

        /* Um "tique" de vida em cada ícone, combinando com o que ele representa. */
        .icone-modulo i[class*="icone-anim-"] { display: inline-block; }
        .icone-anim-casamento { animation: pulsarCoracao 1.4s ease-in-out infinite; }
        @keyframes pulsarCoracao {
            0%, 100% { transform: scale(1); }
            50%      { transform: scale(1.18); }
        }
        .icone-anim-aniversario { animation: subirBalao 2.2s ease-in-out infinite; }
        @keyframes subirBalao {
            0%, 100% { transform: translateY(0); }
            50%      { transform: translateY(-5px); }
        }
        .icone-anim-corporativo { animation: abrirPasta 2.4s ease-in-out infinite; transform-origin: bottom center; }
        @keyframes abrirPasta {
            0%, 100% { transform: scaleY(1) rotate(0deg); }
            50%      { transform: scaleY(1.1) rotate(-6deg); }
        }
        .icone-anim-academico { animation: rodarChapeu 3.2s linear infinite; }
        @keyframes rodarChapeu {
            from { transform: rotate(0deg); }
            to   { transform: rotate(360deg); }
        }
        @media (prefers-reduced-motion: reduce) {
            .icone-anim-casamento, .icone-anim-aniversario, .icone-anim-corporativo, .icone-anim-academico {
                animation: none;
            }
        }

        /* Transição ao entrar num módulo: o ícone nasce exatamente onde estava
           o card clicado (--origem-x/--origem-y) e cresce dali mesmo, dando a
           sensação de "sair do card e se aproximar" da tela — por cima da
           própria página, sem fundo ainda —, e só depois o degradê do módulo
           aparece cobrindo a tela — ~2.5s no total, dando tempo da página de
           trás carregar antes de trocar mesmo. */
        .overlay-transicao-modulo {
            position: fixed; inset: 0; z-index: 2000;
            pointer-events: none;
            overflow: hidden;
        }
        .overlay-transicao-modulo.ativa {
            pointer-events: all;
        }
        /* Só transform/opacity são animados aqui (rodam na GPU, sem repintar a
           tela a cada quadro). O degradê "fluindo" é uma camada 2x maior que
           desliza com translate, em vez de animar background-position. */
        .overlay-transicao-fundo {
            position: absolute; inset: 0; z-index: 0;
            overflow: hidden;
            opacity: 0;
        }
        .overlay-transicao-fundo::before {
            content: '';
            position: absolute;
            top: -50%; left: -50%;
            width: 200%; height: 200%;
            background-image: linear-gradient(135deg, var(--cor-transicao, #6366f1), var(--cor-transicao-escura, #3730a3), var(--cor-transicao, #6366f1));
        }
        .overlay-transicao-modulo.ativa .overlay-transicao-fundo {
            animation: aparecerFundoTransicao .8s cubic-bezier(.4, 0, .2, 1) 1s forwards;
        }
        .overlay-transicao-modulo.ativa .overlay-transicao-fundo::before {
            animation: fluirGradienteTransicao 3s ease-in-out infinite alternate;
        }
        @keyframes fluirGradienteTransicao {
            from { transform: translate3d(0, 0, 0); }
            to   { transform: translate3d(25%, 25%, 0); }
        }
        @keyframes aparecerFundoTransicao {
            from { opacity: 0; }
            to   { opacity: 1; }
        }
        /* O ícone já nasce no tamanho FINAL (21rem = 3.5rem x 6) e é reduzido
           pela escala; ao crescer ele só volta pro tamanho natural (scale 1).
           Assim nunca é ampliado além do que foi desenhado — fica nítido do
           começo ao fim, sem serrilhar. O centro dele fica sempre no ponto
           (--origem-x/--origem-y) → (--destino-x/--destino-y). */
        .overlay-transicao-conteudo {
            position: fixed;
            left: 0; top: 0;
            z-index: 2;
            color: rgba(255,255,255,.95);
            text-shadow: 0 12px 90px rgba(0,0,0,.3);
            font-size: 21rem;
            line-height: 1;
            opacity: 0;
            transform: translate3d(var(--origem-x, 50vw), var(--origem-y, 50vh), 0) translate(-50%, -50%) scale(.067);
            backface-visibility: hidden;
        }
        .overlay-transicao-conteudo i { display: block; }
        .overlay-transicao-modulo.ativa .overlay-transicao-conteudo {
            animation: crescerIconeTransicao 1.8s cubic-bezier(.16, 1, .3, 1) forwards;
        }
        @keyframes crescerIconeTransicao {
            0%   { opacity: 0; transform: translate3d(var(--origem-x, 50vw), var(--origem-y, 50vh), 0) translate(-50%, -50%) scale(.067); }
            15%  { opacity: 1; }
            100% { opacity: 1; transform: translate3d(var(--destino-x, 50vw), var(--destino-y, 50vh), 0) translate(-50%, -50%) scale(1); }
        }
        /* Durante a transição, pausa as animações contínuas da página de trás
           (degradê do painel, blobs com blur, ícones pulsando) — elas repintam
           a cada quadro e roubam fluidez do efeito. */
        body.transicao-modulo-ativa .painel-boas-vindas::before,
        body.transicao-modulo-ativa .decor-blob,
        body.transicao-modulo-ativa [class*="icone-anim-"] {
            animation-play-state: paused;
        }
        /* Vários ícones menores subindo pela tela atrás do ícone principal,
           tipo "vários balões soltos", pra dar mais vida ao efeito, surgindo
           junto com o fundo (depois que o ícone principal já se aproximou). */
        .overlay-transicao-extra {
            position: absolute;
            inset: 0;
            z-index: 1;
            overflow: hidden;
            pointer-events: none;
        }
        .overlay-transicao-extra i {
            position: absolute;
            bottom: -15%;
            color: rgba(255,255,255,.55);
            opacity: 0;
            will-change: transform, opacity;
            animation-name: flutuarIconeExtra;
            animation-timing-function: ease-in;
            animation-iteration-count: 1;
            animation-fill-mode: forwards;
        }
        @keyframes flutuarIconeExtra {
            0%   { opacity: 0; transform: translate3d(0, 0, 0) rotate(0deg); }
            12%  { opacity: .8; }
            80%  { opacity: .6; }
            100% { opacity: 0; transform: translate3d(0, -115vh, 0) rotate(var(--giro-extra, 15deg)); }
        }
        @media (prefers-reduced-motion: reduce) {
            .overlay-transicao-fundo, .overlay-transicao-fundo::before { animation: none !important; }
            .overlay-transicao-modulo.ativa .overlay-transicao-fundo { opacity: 1; }
            .overlay-transicao-modulo.ativa .overlay-transicao-conteudo { animation: none !important; opacity: 1; transform: translate3d(var(--destino-x, 50vw), var(--destino-y, 50vh), 0) translate(-50%, -50%) scale(.2); }
            .overlay-transicao-extra { display: none !important; }
        }

        .badge-contagem {
            display: inline-block;
            background: #f1f2f7;
            color: #5b5f6d;
            font-size: .76rem;
            font-weight: 600;
            padding: .28rem .7rem;
            border-radius: 999px;
        }
        .badge-contagem-compacta { font-size: .68rem; padding: .22rem .55rem; }
        .dica-entrar {
            display: block;
            font-size: .74rem;
            font-weight: 700;
            color: var(--sombra-modulo, #6366f1);
            opacity: 0;
            max-height: 0;
            transform: translateY(-4px);
            transition: opacity .2s ease, transform .2s ease, max-height .2s ease;
        }
        .card-modulo:hover .dica-entrar {
            opacity: 1;
            max-height: 1.5rem;
            transform: translateY(0);
        }
        .form-cor-modulo { z-index: 2; }
        .form-cor-modulo .form-control-color { width: 2.1rem; height: 1.9rem; padding: .15rem; border-radius: 8px; }
        .form-cor-modulo label { font-size: .74rem; }
        .form-cor-modulo .btn-outline-secondary { border-radius: 8px; }

        .selo-notif-modulo {
            position: absolute; top: 10px; right: 10px; z-index: 2;
            background: #ef4444; color: #fff;
            font-size: .68rem; font-weight: 800;
            padding: .2rem .45rem;
            border-radius: 999px;
            border: 2px solid #fff;
            box-shadow: 0 3px 8px rgba(239,68,68,.4);
        }
        .card-modulo-compacto .form-cor-modulo .form-control-color { width: 1.7rem; height: 1.6rem; }
        .card-modulo-compacto .form-cor-modulo .btn-outline-secondary { padding: .2rem .5rem; }

        .rotulo-secao-hub {
            font-size: .82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: #5b5f6d;
        }
        .aviso-vazio-ativos {
            background: #fff;
            border: 1.5px dashed #d8dae6;
            border-radius: 16px;
            padding: 2.5rem 1.5rem;
            text-align: center;
            color: #8b8e9a;
        }

        /* Painel de upgrade: fica "de canto" (sidebar), listando os módulos
           bloqueados de forma compacta em vez de cards do mesmo tamanho dos ativos. */
        .painel-upsell {
            background: linear-gradient(160deg, #fff 0%, #f5f3ff 100%);
            border: 1px solid #ece9fb;
            border-radius: 20px;
            padding: 1.25rem;
            position: sticky;
            top: 1.5rem;
            box-shadow: 0 6px 20px rgba(99,102,241,.08);
        }
        .item-upsell {
            display: flex;
            align-items: center;
            gap: .75rem;
            background: #fff;
            border: 1px solid #eceef3;
            border-radius: 14px;
            padding: .6rem .75rem;
            cursor: pointer;
            transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
        }
        .item-upsell:hover {
            transform: translateX(3px);
            box-shadow: 0 6px 16px rgba(99,102,241,.15);
            border-color: #c7cbf7;
        }
        .icone-upsell {
            width: 40px; height: 40px; border-radius: 12px;
            background: #ced3db;
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.05rem;
            flex-shrink: 0;
            transition: background .15s ease;
        }
        .item-upsell:hover .icone-upsell { background: #6366f1; }
        .nome-upsell { font-weight: 700; font-size: .86rem; color: #16181d; }
        .preco-upsell { font-size: .76rem; color: #8b8e9a; font-weight: 600; }
        .seta-upsell { color: #c7cbf7; transition: transform .15s ease, color .15s ease; flex-shrink: 0; }
        .item-upsell:hover .seta-upsell { transform: translateX(3px); color: #6366f1; }
        .min-width-0 { min-width: 0; }

        .btn-solicitar-upgrade {
            background: linear-gradient(135deg, #6366f1, #a855f7);
            color: #fff;
            font-weight: 700;
            border-radius: 999px;
            border: none;
            font-size: .88rem;
            transition: transform .15s ease, box-shadow .15s ease;
        }
        .btn-solicitar-upgrade:hover {
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(99,102,241,.35);
        }
        .badge-pedido-enviado {
            display: inline-block;
            background: #fff7e6;
            color: #b8860b;
            font-size: .82rem;
            font-weight: 600;
            padding: .55rem .8rem;
            border-radius: 999px;
            border: 1px solid #f4dfa3;
        }

        .selo-promo-mini {
            display: inline-block;
            background: linear-gradient(135deg, #f97316, #ef4444);
            color: #fff;
            font-size: .6rem;
            font-weight: 800;
            letter-spacing: .4px;
            padding: .12rem .4rem;
            border-radius: 999px;
            vertical-align: middle;
        }
        .preco-riscado {
            text-decoration: line-through;
            color: #a4a7b3;
            font-size: .8rem;
        }

        .modal-header-plano { position: relative; }
        .icone-modal-plano {
            width: 46px; height: 46px; border-radius: 14px;
            background: rgba(255,255,255,.2);
            border: 1px solid rgba(255,255,255,.3);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        .preco-modal {
            font-size: 1.9rem;
            font-weight: 800;
            color: #16181d;
        }
        .preco-modal span { font-size: .8rem; font-weight: 500; color: #8b8e9a; }
        .lista-recursos-plano {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: .6rem;
        }
        .lista-recursos-plano li {
            font-size: .88rem;
            color: #383b46;
            display: flex;
            align-items: flex-start;
            gap: .5rem;
        }
        .lista-recursos-plano li i { color: #16a34a; margin-top: .15rem; flex-shrink: 0; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark navbar-hub shadow-sm">
    <div class="container flex-nowrap">
        <span class="navbar-brand flex-shrink-0">
            <img src="img/LOGO MEP NAV.svg" alt="Meu Evento PRO" class="logo-nav-admin" style="height:40px;">
        </span>
        <div class="d-flex align-items-center gap-2">
            <?php if ($eh_desenvolvedor): ?>
            <a href="dev_painel.php" class="btn btn-sm btn-outline-light rounded-pill"><i class="bi bi-braces-asterisk"></i> Painel do Desenvolvedor</a>
            <?php endif; ?>
            <a href="logout.php" class="btn btn-sm btn-outline-light rounded-pill"><i class="bi bi-box-arrow-right"></i> Sair</a>
        </div>
    </div>
</nav>

<?php $primeiro_acesso = !empty($_SESSION['primeiro_acesso']); ?>
<div class="container my-5">
    <div class="painel-boas-vindas" style="background-image: linear-gradient(120deg, #ffffff, <?= htmlspecialchars($pastel_casamento) ?>, <?= htmlspecialchars($pastel_aniversario) ?>, <?= htmlspecialchars($pastel_corporativo) ?>, <?= htmlspecialchars($pastel_academico) ?>, #ffffff);">
        <div class="decor-blob decor-blob-1" style="background: <?= htmlspecialchars($blob_casamento) ?>;"></div>
        <div class="decor-blob decor-blob-2" style="background: <?= htmlspecialchars($blob_corporativo) ?>;"></div>

        <div class="boas-vindas-hub">
            <span class="saudacao-hub"><?= $primeiro_acesso ? '🎉 Seja bem-vindo(a),' : '👋 Bem-vindo(a) de volta,' ?></span>
            <h1 class="nome-hub"><?= htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário', ENT_QUOTES, 'UTF-8') ?>!</h1>
        </div>

        <div class="divisor-hero"></div>

        <div class="hero-hub hero-hub-atraso">
            <h2>Qual tipo de evento você vai administrar?</h2>
            <p class="text-muted mb-0">Escolha um módulo para continuar. Você pode trocar de módulo a qualquer momento pelo painel.</p>
        </div>
    </div>

    <?php if ($msg_sucesso): ?>
    <div class="alert alert-success text-center rounded-4 mb-4"><i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($msg_sucesso) ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4 stats-hub-atraso">
        <div class="col-6 col-md-3">
            <div class="stat-chip-hub">
                <div class="stat-icone-hub" style="background: linear-gradient(135deg, #6366f1, #a855f7);"><i class="bi bi-unlock-fill"></i></div>
                <div>
                    <div class="stat-valor-hub"><?= count($modulos_ativos) ?>/<?= count($cards_modulo) ?></div>
                    <div class="stat-rotulo-hub">Módulos ativos</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-chip-hub">
                <div class="stat-icone-hub" style="background: linear-gradient(135deg, #16a34a, #22c55e);"><i class="bi bi-calendar-event-fill"></i></div>
                <div>
                    <div class="stat-valor-hub"><?= $total_eventos_usuario ?></div>
                    <div class="stat-rotulo-hub">Eventos cadastrados</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-chip-hub">
                <div class="stat-icone-hub" style="background: linear-gradient(135deg, #f59e0b, #f97316);"><i class="bi bi-stars"></i></div>
                <div>
                    <div class="stat-valor-hub"><?= count($modulos_bloqueados) ?></div>
                    <div class="stat-rotulo-hub">Módulos p/ contratar</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-chip-hub">
                <div class="stat-icone-hub" style="background: linear-gradient(135deg, #0ea5e9, #38bdf8);"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="stat-valor-hub"><?= $pedidos_pendentes_usuario ?></div>
                    <div class="stat-rotulo-hub">Pedidos pendentes</div>
                </div>
            </div>
        </div>
    </div>

    <?php $tem_modulos_bloqueados = !empty($modulos_bloqueados); ?>
    <div class="row g-4 conteudo-modulos-atraso">
        <div class="<?= $tem_modulos_bloqueados ? 'col-lg-8' : 'col-lg-12' ?>">
            <div class="cabecalho-secao-hub mb-3">
                <div class="icone-secao-hub" style="background: linear-gradient(135deg, #16a34a, #22c55e);"><i class="bi bi-check-lg"></i></div>
                <div>
                    <div class="rotulo-secao-hub mb-0">Seus módulos ativos</div>
                    <div class="sub-rotulo-secao-hub">Clique para entrar ou personalize a cor de cada um</div>
                </div>
            </div>

            <?php if (empty($modulos_ativos)): ?>
            <div class="aviso-vazio-ativos">
                <i class="bi bi-lock-fill fs-2 d-block mb-2"></i>
                Nenhum módulo liberado para o seu usuário ainda. Fale com o desenvolvedor do sistema.
            </div>
            <?php else: ?>
            <div class="row g-3">
                <?php foreach ($modulos_ativos as $card): ?>
                <div class="<?= $tem_modulos_bloqueados ? 'col-6 col-xl-4' : 'col-6 col-lg-4 col-xl-3' ?>">
                    <div class="card card-modulo card-modulo-compacto h-100 text-center p-3 position-relative" style="--sombra-modulo: <?= htmlspecialchars($card['cor']) ?>66; --cor-solida-modulo: <?= htmlspecialchars($card['cor']) ?>;">
                        <?php if ($card['notificacoes_novas'] > 0): ?>
                        <span class="selo-notif-modulo" title="<?= $card['notificacoes_novas'] ?> notificação(ões) nova(s) neste módulo">
                            <i class="bi bi-bell-fill"></i> <?= $card['notificacoes_novas'] > 9 ? '9+' : $card['notificacoes_novas'] ?>
                        </span>
                        <?php endif; ?>
                        <a href="hub_modulos.php?modulo=<?= urlencode($card['tipo']) ?>" class="text-decoration-none text-reset stretched-link">
                            <div class="icone-modulo icone-modulo-compacto mx-auto mb-2" style="background-color: <?= htmlspecialchars($card['cor']) ?>;">
                                <i class="bi <?= htmlspecialchars($card['icone']) ?> icone-anim-<?= htmlspecialchars($card['tipo']) ?>"></i>
                            </div>
                            <h6 class="fw-bold mb-1"><?= htmlspecialchars($card['labels']['nome_modulo']) ?></h6>
                            <span class="badge-contagem badge-contagem-compacta"><?= $contagem_por_modulo[$card['tipo']] ?> evento(s)</span>
                            <span class="dica-entrar"><i class="bi bi-arrow-right-circle-fill me-1"></i>Entrar no painel</span>
                        </a>
                        <div class="mt-2 pt-2 border-top position-relative form-cor-modulo">
                            <form method="POST" action="hub_modulos.php" class="d-flex align-items-center justify-content-center gap-2">
                                <input type="hidden" name="acao" value="salvar_cor">
                                <input type="hidden" name="tipo_evento" value="<?= htmlspecialchars($card['tipo']) ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <label class="small text-muted mb-0" for="cor-<?= $card['tipo'] ?>">Cor</label>
                                <input type="color" id="cor-<?= $card['tipo'] ?>" name="cor" value="<?= htmlspecialchars($card['cor']) ?>" class="form-control form-control-color form-control-sm" title="Escolher a cor deste módulo">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Salvar cor"><i class="bi bi-check-lg"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($tem_modulos_bloqueados): ?>
        <div class="col-lg-4">
            <div class="painel-upsell">
                <div class="cabecalho-secao-hub mb-3">
                    <div class="icone-secao-hub" style="background: linear-gradient(135deg, #6366f1, #a855f7);"><i class="bi bi-stars"></i></div>
                    <div>
                        <div class="rotulo-secao-hub mb-0">Disponíveis para contratar</div>
                        <div class="sub-rotulo-secao-hub">Clique pra ver detalhes do plano</div>
                    </div>
                </div>
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($modulos_bloqueados as $card): ?>
                    <div class="item-upsell" data-bs-toggle="modal" data-bs-target="#<?= $card['modal_id'] ?>" role="button" tabindex="0" title="Ver detalhes do plano">
                        <div class="icone-upsell"><i class="bi <?= htmlspecialchars($card['icone']) ?>"></i></div>
                        <div class="flex-grow-1 min-width-0">
                            <div class="nome-upsell text-truncate">
                                <?= htmlspecialchars($card['labels']['nome_modulo']) ?>
                                <?php if ($card['plano']['em_promocao']): ?><span class="selo-promo-mini">PROMO</span><?php endif; ?>
                            </div>
                            <div class="preco-upsell">
                                <?php if ($card['plano']['em_promocao']): ?>
                                    <span class="preco-riscado">R$ <?= number_format($card['plano']['preco_normal'], 2, ',', '.') ?></span>
                                <?php endif; ?>
                                R$ <?= number_format($card['plano']['preco'], 2, ',', '.') ?>/mês
                            </div>
                        </div>
                        <i class="bi bi-chevron-right seta-upsell"></i>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="footer-hub text-center mt-5 pt-3">
        <i class="bi bi-shield-check me-1"></i> Meu Evento PRO · Gestão completa para assessorias de eventos
    </div>
</div>

<?php foreach ($modulos_bloqueados as $card): ?>
<div class="modal fade" id="<?= $card['modal_id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4 overflow-hidden">
            <div class="modal-header modal-header-plano border-0 text-white p-4" style="background-color: <?= htmlspecialchars($card['cor']) ?>;">
                <div class="d-flex align-items-center gap-3">
                    <div class="icone-modal-plano"><i class="bi <?= htmlspecialchars($card['icone']) ?>"></i></div>
                    <div>
                        <h5 class="fw-bold mb-0"><?= htmlspecialchars($card['labels']['nome_modulo']) ?></h5>
                        <div class="opacity-75 small"><?= htmlspecialchars($card['plano']['nome']) ?></div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body p-4">
                <?php if ($card['plano']['em_promocao']): ?>
                <div class="mb-1">
                    <span class="selo-promo-mini">PROMOÇÃO</span>
                    <span class="preco-riscado ms-2">R$ <?= number_format($card['plano']['preco_normal'], 2, ',', '.') ?></span>
                </div>
                <?php endif; ?>
                <div class="preco-modal mb-1">
                    R$ <?= number_format($card['plano']['preco'], 2, ',', '.') ?><span>/mês</span>
                </div>
                <?php if ($card['plano']['em_promocao']): ?>
                <div class="text-muted small mb-3">
                    <i class="bi bi-clock-history me-1"></i> Oferta válida até <?= date('d/m/Y', strtotime($card['plano']['promocao_fim'])) ?>
                </div>
                <?php else: ?>
                <div class="mb-3"></div>
                <?php endif; ?>
                <div class="fw-bold small text-uppercase text-muted mb-2">O que está incluso</div>
                <ul class="lista-recursos-plano mb-0">
                    <?php foreach ($card['recursos'] as $recurso): ?>
                    <li><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($recurso) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="modal-footer border-0 p-4 pt-0">
                <?php if ($card['pedido_pendente']): ?>
                    <span class="badge-pedido-enviado w-100 text-center py-2">
                        <i class="bi bi-hourglass-split me-1"></i> Pedido enviado — aguardando liberação
                    </span>
                <?php else: ?>
                    <form method="POST" action="hub_modulos.php" class="w-100">
                        <input type="hidden" name="acao" value="solicitar_upgrade">
                        <input type="hidden" name="tipo_evento" value="<?= htmlspecialchars($card['tipo']) ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <button type="submit" class="btn btn-solicitar-upgrade w-100 py-2">
                            <i class="bi bi-unlock-fill me-1"></i> Solicitar upgrade
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<div class="overlay-transicao-modulo" id="overlay-transicao-modulo">
    <div class="overlay-transicao-fundo"></div>
    <div class="overlay-transicao-extra" id="overlay-transicao-extra"></div>
    <div class="overlay-transicao-conteudo" id="overlay-transicao-conteudo">
        <i class="bi" id="overlay-transicao-icone"></i>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Efeito ao entrar num módulo: nasce exatamente do ponto onde o card foi
// clicado, cobre a tela com um degradê animado na cor daquele módulo, e o
// ícone clicado cresce continuamente por 2,5s — dando tempo da página de trás
// carregar antes de trocar de fato pra painel_admin.php.
function escurecerCorHex(hex, percentual) {
    hex = hex.replace('#', '');
    let r = parseInt(hex.substr(0, 2), 16), g = parseInt(hex.substr(2, 2), 16), b = parseInt(hex.substr(4, 2), 16);
    r = Math.round(r * (1 + percentual));
    g = Math.round(g * (1 + percentual));
    b = Math.round(b * (1 + percentual));
    const paraHex = v => Math.max(0, Math.min(255, v)).toString(16).padStart(2, '0');
    return '#' + paraHex(r) + paraHex(g) + paraHex(b);
}

document.querySelectorAll('.card-modulo:not(.bloqueado) > a[href*="modulo="]').forEach(function (link) {
    link.addEventListener('click', function (e) {
        e.preventDefault();
        const destino = this.getAttribute('href');
        const card = this.closest('.card-modulo');
        const overlay = document.getElementById('overlay-transicao-modulo');
        const overlayIcone = document.getElementById('overlay-transicao-icone');
        const overlayExtra = document.getElementById('overlay-transicao-extra');
        if (!overlay || !card) { window.location.href = destino; return; }

        const cor = getComputedStyle(card).getPropertyValue('--cor-solida-modulo').trim() || '#6366f1';
        const iconeCard = card.querySelector('.icone-modulo i');
        const classesIcone = iconeCard ? Array.from(iconeCard.classList).filter(c => c.startsWith('bi-') && !c.startsWith('icone-anim')) : ['bi-grid-3x3-gap-fill'];
        const elementoOrigem = iconeCard || card;
        const rectOrigem = elementoOrigem.getBoundingClientRect();
        const centroOrigemX = rectOrigem.left + rectOrigem.width / 2;
        const centroOrigemY = rectOrigem.top + rectOrigem.height / 2;

        overlayIcone.className = 'bi ' + classesIcone.join(' ');

        // O CSS centraliza o ícone no ponto informado (translate -50%), então
        // basta passar o centro do ícone do card (origem) e o centro da tela
        // (destino) — ele sai de cima do card e viaja até o meio enquanto cresce.
        overlay.style.setProperty('--origem-x', centroOrigemX + 'px');
        overlay.style.setProperty('--origem-y', centroOrigemY + 'px');
        overlay.style.setProperty('--destino-x', (window.innerWidth / 2) + 'px');
        overlay.style.setProperty('--destino-y', (window.innerHeight / 2) + 'px');
        overlay.style.setProperty('--cor-transicao', cor);
        overlay.style.setProperty('--cor-transicao-escura', escurecerCorHex(cor, -0.4));

        // Um bando de ícones menores subindo atrás, tipo vários balões soltos,
        // cada um com posição, tamanho, duração e atraso diferentes — surgindo
        // só depois que o ícone principal já cresceu e o fundo começou a aparecer.
        if (overlayExtra) {
            overlayExtra.innerHTML = '';
            const reduzMovimento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (!reduzMovimento) {
                const total = 14;
                for (let i = 0; i < total; i++) {
                    const icone = document.createElement('i');
                    icone.className = 'bi ' + classesIcone.join(' ');
                    const tamanho = 1.1 + Math.random() * 2.2;
                    const atraso = 1.0 + Math.random() * 1.1;
                    const duracao = 2.2 + Math.random() * 1.4;
                    const giro = (Math.random() > 0.5 ? 1 : -1) * (10 + Math.random() * 30);
                    icone.style.left = (Math.random() * 92) + '%';
                    icone.style.fontSize = tamanho + 'rem';
                    icone.style.animationDelay = atraso + 's';
                    icone.style.animationDuration = duracao + 's';
                    icone.style.setProperty('--giro-extra', giro + 'deg');
                    overlayExtra.appendChild(icone);
                }
            }
        }

        document.body.classList.add('transicao-modulo-ativa');
        requestAnimationFrame(() => overlay.classList.add('ativa'));
        setTimeout(() => { window.location.href = destino; }, 2500);
    });
});
</script>
</body>
</html>
