<?php
// Página pública do casal: /convite/{slug} (reescrito pelo .htaccess para cá).
// Sem login — qualquer convidado com o link abre. Rascunho (ativo = 0) só é
// visível para os próprios noivos logados, como prévia.
session_start();
require_once 'conexao.php';
require_once 'tenant.php';
require_once 'site_convite.inc.php';

site_garantir_schema($pdo);

$slug = strtolower(trim($_GET['s'] ?? ''));

// /convite/modelo: página de demonstração (conteúdo fictício) que os noivos veem antes de contratar o site.
$modo_modelo = ($slug === 'modelo');
if ($modo_modelo) {
    $site = site_modelo_linha();
    $eh_dono = false;
} else {
    $stmt = $pdo->prepare("SELECT s.*, e.data_evento, e.hora_evento, e.cliente_id, e.assessoria_id, c.nome AS nome1, c.nome_secundario AS nome2
        FROM site_convite s
        INNER JOIN eventos e ON e.id = s.evento_id
        INNER JOIN clientes c ON c.id = e.cliente_id
        WHERE s.slug = ?");
    $stmt->execute([$slug]);
    $site = $stmt->fetch();

    $eh_dono = $site && (
        (($_SESSION['usuario_tipo'] ?? '') === 'noivos' && (int)($_SESSION['usuario_id'] ?? 0) === (int)$site['cliente_id'])
        || (in_array($_SESSION['usuario_tipo'] ?? '', ['admin', 'assistente', 'desenvolvedor'], true)
            && eh_registro_da_assessoria_atual($site['assessoria_id'] !== null ? (int)$site['assessoria_id'] : null))
    );
}

// O site é um item contratado: só abre depois que a Central liberar o acesso do evento (após o pagamento).
$acesso_ok = $modo_modelo || ($site && site_acesso_liberado($pdo, (int)$site['evento_id']));
if (!$site || !$acesso_ok || (!$site['ativo'] && !$eh_dono)) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="pt-br"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Em breve</title></head>'
       . '<body style="font-family:Georgia,serif;text-align:center;padding:4rem 1rem;color:#555"><h1>Em breve</h1><p>Este site ainda não está disponível.</p></body></html>';
    exit;
}

$evento_id = (int)$site['evento_id'];
$base = site_base_path();
$pasta_fotos = $modo_modelo ? $base . '/img/site_modelo/' : $base . '/uploads/';   // o modelo usa fotos fixas do projeto
$url_pagina = $base . '/convite/' . $site['slug'];
$cor = site_hex_ou_padrao($site['cor_destaque'], '#3f503a');
$fundo = site_hex_ou_padrao($site['cor_fundo'], '#f7f6f2');

[$nome1, $nome2] = site_nomes_casal(site_extras($site)['textos'], (string)$site['nome1'], $site['nome2'] ?? null);
$nomes = $nome2 !== '' ? "$nome1 & $nome2" : $nome1;
$monograma = mb_strtoupper(mb_substr($nome1, 0, 1) . ($nome2 !== '' ? '&' . mb_substr($nome2, 0, 1) : ''));

$meses = ['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
$ts = strtotime($site['data_evento']);
$data_extenso = date('j', $ts) . ' de ' . $meses[(int)date('n', $ts) - 1] . ' de ' . date('Y', $ts);
$alvo_js = date('Y-m-d', $ts) . 'T' . (!empty($site['hora_evento']) ? substr($site['hora_evento'], 0, 8) : '00:00:00');

$prog = site_json_lista($site['programacao']);
$hosp = site_json_lista($site['hospedagem']);
$galeria = site_json_lista($site['galeria']);
$presentes = site_json_lista($site['presentes']);

// Extras editáveis: textos de cada seção, seções ocultas, fotos soltas e legendas.
$ex = site_extras($site);
$oculta = fn(string $k): bool => in_array($k, $ex['ocultas'], true);
$t = fn(string $k, string $padrao = ''): string => site_texto($ex, $k, $padrao);
$prog_todos = $prog;   // a capa e a agenda (.ics) usam a programação mesmo com a seção oculta
if ($oculta('programacao')) $prog = [];
if ($oculta('hospedagem')) $hosp = [];
if ($oculta('galeria')) $galeria = [];
if ($oculta('presentes')) $presentes = [];

/* ---------- Calendário (.ics) de um item da programação ---------- */
if (isset($_GET['ics'])) {
    $item = $prog_todos[(int)$_GET['ics']] ?? null;
    if ($item) {
        $hora = preg_match('/^(\d{1,2}):(\d{2})/', $item['hora'] ?? '', $m) ? sprintf('%02d%s00', $m[1], $m[2]) : '000000';
        $ini = date('Ymd', $ts) . 'T' . $hora;
        $fim = date('Ymd\THis', strtotime(date('Y-m-d', $ts) . ' ' . substr($hora, 0, 2) . ':' . substr($hora, 2, 2) . ' +3 hours'));
        $esc = fn($t) => str_replace([',', ';', "\n"], ['\,', '\;', '\n'], (string)$t);
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="evento.ics"');
        echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Meu Evento PRO//PT\r\nBEGIN:VEVENT\r\n"
           . "UID:" . $evento_id . "-" . (int)$_GET['ics'] . "@meueventopro\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n"
           . "DTSTART:$ini\r\nDTEND:$fim\r\nSUMMARY:" . $esc(($item['titulo'] ?? '') . ' - ' . $nomes) . "\r\n"
           . "LOCATION:" . $esc(trim(($item['local'] ?? '') . ' ' . ($item['endereco'] ?? ''))) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        exit;
    }
}

/* ---------- Envios dos convidados (recado / presente) ---------- */
$flash = $_GET['ok'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($modo_modelo) { header('Location: ' . $url_pagina); exit; }   // demonstração: não grava nada
    $acao = $_POST['acao'] ?? '';
    $agora = time();
    $limite_ok = ($agora - ($_SESSION['site_ultimo_envio'] ?? 0)) >= 15;   // freio simples contra spam
    $nome = mb_substr(trim($_POST['nome'] ?? ''), 0, 100);

    if (!empty($_POST['site_url_extra']) || !$limite_ok || $nome === '' || ($acao === 'recado' && in_array('recados', site_extras($site)['ocultas'], true))) {
        // honeypot preenchido, envio rápido demais ou sem nome: ignora em silêncio
        header('Location: ' . $url_pagina . '#' . ($acao === 'presente' ? 'presentes' : 'recados'));
        exit;
    }

    if ($acao === 'recado') {
        $texto = mb_substr(trim($_POST['mensagem'] ?? ''), 0, 600);
        if ($texto !== '') {
            $pdo->prepare("INSERT INTO site_recados (evento_id, nome, mensagem) VALUES (?, ?, ?)")->execute([$evento_id, $nome, $texto]);
            $_SESSION['site_ultimo_envio'] = $agora;
        }
        header('Location: ' . $url_pagina . '?ok=recado#recados');
        exit;
    }

    if ($acao === 'presente') {
        $escolhido = null;
        foreach ($presentes as $p) { if (($p['id'] ?? '') === ($_POST['presente_id'] ?? '')) $escolhido = $p; }
        if ($escolhido) {
            $estado_p = site_presentes_estado($pdo, $evento_id, [$escolhido])[$escolhido['id']];
            $livre = $estado_p['livre'];
            $qtd = $livre ? 1 : max(1, (int)($_POST['cotas'] ?? 1));
            if (!$livre && $qtd > $estado_p['disponiveis']) {   // alguém pegou as últimas cotas enquanto a pessoa decidia
                header('Location: ' . $url_pagina . '?ok=esgotado#presentes');
                exit;
            }
            $valor = $livre
                ? (float)str_replace(',', '.', preg_replace('/[^\d,\.]/', '', (string)($_POST['valor'] ?? '0')))
                : (float)$escolhido['valor'] * $qtd;
            if ($valor > 0 && $valor < 1000000) {
                $pdo->prepare("INSERT INTO site_contribuicoes (evento_id, presente_id, presente_nome, valor, cotas, nome_convidado, mensagem) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$evento_id, $escolhido['id'], $escolhido['nome'], round($valor, 2), $qtd, $nome, mb_substr(trim($_POST['mensagem'] ?? ''), 0, 300) ?: null]);
                $_SESSION['site_ultimo_envio'] = $agora;
                $_SESSION['site_ultimo_presente'] = ['nome' => $escolhido['nome'], 'valor' => round($valor, 2), 'cotas' => $qtd];
                header('Location: ' . $url_pagina . '?ok=presente#presentes');
                exit;
            }
        }
        header('Location: ' . $url_pagina . '?ok=erro#presentes');
        exit;
    }
}

if ($modo_modelo) {
    $recados = site_modelo_conteudo(fn($a, $p) => $a)['recados'];
    $estado_pres = [];   // cotas de demonstração, definidas no próprio modelo
    foreach ($presentes as $p) {
        $livre = (float)$p['valor'] <= 0; $total = $livre ? 0 : max(1, (int)$p['cotas']); [$ok, $and] = $p['demo'] ?? [0, 0];
        $estado_pres[$p['id']] = ['livre' => $livre, 'total' => $total, 'recebidas' => $ok, 'andamento' => $and, 'disponiveis' => $livre ? PHP_INT_MAX : max(0, $total - $ok - $and)];
    }
} else {
    $stmt = $pdo->prepare("SELECT nome, mensagem FROM site_recados WHERE evento_id = ? AND aprovado = 1 ORDER BY id DESC LIMIT 60");
    $stmt->execute([$evento_id]);
    $recados = $stmt->fetchAll();
    $estado_pres = $presentes ? site_presentes_estado($pdo, $evento_id, $presentes) : [];
}
$ultimo_presente = $flash === 'presente' ? ($_SESSION['site_ultimo_presente'] ?? null) : null;

$tem_historia = trim((string)$site['historia']) !== '' && !$oculta('historia');
$tem_dress = trim((string)($site['dress_eles'] . $site['dress_elas'] . $site['cores_evitar'])) !== '' && !$oculta('traje');
$tem_pix = trim((string)$site['pix_chave']) !== '';

$menu = [['inicio', 'Início']];
if ($tem_historia) $menu[] = ['historia', 'História'];
if ($prog) $menu[] = ['programacao', 'Programação'];
if ($tem_dress) $menu[] = ['traje', 'Traje'];
if ($hosp) $menu[] = ['hospedagem', 'Hospedagem'];
if ($galeria) $menu[] = ['galeria', 'Fotos'];
if ($presentes) $menu[] = ['presentes', 'Presentes'];
if (!$oculta('recados')) $menu[] = ['recados', 'Recados'];

$maps = fn($t) => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($t);
$waze = fn($t) => 'https://waze.com/ul?q=' . rawurlencode($t) . '&navigate=yes';
$capa_url = $site['capa_foto'] ? $pasta_fotos . rawurlencode($site['capa_foto']) : null;

$fonte = array_key_exists($site['fonte_nomes'] ?? '', SITE_FONTES_NOMES) ? $site['fonte_nomes'] : 'Great Vibes';
$hora_cer = '';
foreach ($prog_todos as $p) { if (!empty($p['hora'])) { $hora_cer = $p['hora']; break; } }
$quando = $data_extenso . ($hora_cer !== '' ? ' · ' . mb_strtolower($prog_todos[0]['titulo'] ?? 'cerimônia') . ' às ' . $hora_cer : '');
$onde = trim((string)$site['local_resumo']);
$intro = trim((string)($site['frase_intro'] ?? ''));
$ini1 = mb_strtoupper(mb_substr($nome1, 0, 1));
$ini2 = $nome2 !== '' ? mb_strtoupper(mb_substr($nome2, 0, 1)) : '';
$titulo_pagina = $nomes . ' — ' . ($site['titulo'] ?: 'Nosso casamento');
$tem_hospedagem = !empty($hosp);
// Faixas de foto entre as seções (efeito de rolagem com a imagem fixa): usa as fotos da
// galeria, na ordem; sem galeria, aproveita a foto da história e a da capa.
// Fundo da capa e faixas: usam as fotos escolhidas pelo casal; sem escolha, repartem as da galeria.
$lado = site_historia_lado($site, $ex);   // fotos ao lado do texto da história
$fotos_pool = $galeria ?: array_slice($lado, 0, 1);
$n_capa = $fotos_pool ? min(3, max(1, (int)ceil(count($fotos_pool) / 2))) : 0;
$fotos_capa = $ex['fundo'] ?: array_slice($fotos_pool, 0, $n_capa);
$fotos_faixa = $ex['faixas'] ?: (array_slice($fotos_pool, $ex['fundo'] ? 0 : $n_capa) ?: $fotos_pool);
$faixa = function () use (&$fotos_faixa, $pasta_fotos): void {
    $arq = array_shift($fotos_faixa);
    if (!$arq) return;
    $url = $pasta_fotos . rawurlencode($arq);
    echo '<div class="faixa" role="presentation" style="--img:url(\'' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '\')"><div class="faixa-fixa"></div></div>';
};
$cores_evitar = array_filter(array_map('trim', preg_split('/[,;]+/', (string)$site['cores_evitar'])));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= site_h($titulo_pagina) ?></title>
    <meta property="og:title" content="<?= site_h($titulo_pagina) ?>">
    <meta property="og:description" content="<?= site_h($data_extenso) ?><?= $onde !== '' ? ' · ' . site_h($onde) : '' ?>">
    <?php if ($capa_url): ?><meta property="og:image" content="<?= site_h($capa_url) ?>"><?php endif; ?>
    <script>
        // Animação de boas-vindas: só na 1ª abertura da sessão e sem "movimento reduzido".
        (function () {
            try {
                if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches && !sessionStorage.getItem('bv_<?= $evento_id ?>')) {
                    document.documentElement.classList.add('com-boas-vindas');
                }
            } catch (e) {}
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Allura&family=Great+Vibes&family=Parisienne&family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=Lora:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: <?= $cor ?>; --bg: <?= $fundo ?>; --surface: #fff; --surface-muted: #efe6dc;
            --text: #2d312b; --muted: #4f574b; --border: #d9d6cf; --accent: #304c60;
            --script: '<?= $fonte ?>', cursive; --serif: 'Cormorant Garamond', Georgia, serif; --body: 'Lora', Georgia, serif;
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; scroll-padding-top: 76px; }
        body { margin: 0; background: var(--bg); color: var(--text); font-family: var(--body); line-height: 1.75; font-size: 1.02rem; }
        h1, h2, h3, p { margin: 0; }
        a { color: var(--primary); }
        img { max-width: 100%; }

        /* ---------- Boas-vindas ---------- */
        .boas-vindas { display: none; }
        html.com-boas-vindas .boas-vindas { display: flex; position: fixed; inset: 0; z-index: 100; background: var(--bg); align-items: center; justify-content: center; transition: opacity .9s ease, visibility .9s; }
        html.com-boas-vindas .boas-vindas.saindo { opacity: 0; visibility: hidden; }
        .moldura { position: relative; padding: 3.2rem 2.4rem; text-align: center; border: 1px solid var(--primary); outline: 1px solid var(--primary); outline-offset: 7px; max-width: 86vw; animation: sobe 1.1s ease both; }
        .moldura::before, .moldura::after { content: ''; position: absolute; width: 14px; height: 14px; background: var(--bg); border: 1px solid var(--primary); transform: rotate(45deg); left: 50%; margin-left: -7px; }
        .moldura::before { top: -8px; } .moldura::after { bottom: -8px; }
        .moldura .nomes-bv { font-family: var(--script); font-size: clamp(2.6rem, 11vw, 4.4rem); color: var(--primary); line-height: 1.15; }
        .moldura small { display: block; margin-top: .8rem; font-family: var(--serif); letter-spacing: .3em; text-transform: uppercase; font-size: .85rem; color: var(--muted); }
        @keyframes sobe { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }

        /* ---------- Cabeçalho ---------- */
        header.topo { position: sticky; top: 0; z-index: 50; background: color-mix(in srgb, var(--bg) 92%, transparent); backdrop-filter: blur(8px); border-bottom: 1px solid var(--border); }
        .topo-in { max-width: 1120px; margin: 0 auto; padding: .55rem 1.2rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
        .mono { display: inline-flex; align-items: center; justify-content: center; gap: .1rem; min-width: 52px; height: 52px; padding: 0 .7rem; border-radius: 999px; white-space: nowrap; border: 1px solid var(--primary); color: var(--primary); font-family: var(--script); font-size: 1.35rem; text-decoration: none; line-height: 1; background: var(--surface); }
        .mono i { font-size: .55rem; }
        nav.menu ul { list-style: none; display: flex; gap: 1.5rem; margin: 0; padding: 0; }
        nav.menu a { text-decoration: none; color: var(--text); font-family: var(--serif); font-size: 1.05rem; font-weight: 500; letter-spacing: .04em; }
        nav.menu a:hover { color: var(--primary); }
        .btn-menu { display: none; background: none; border: 1px solid var(--border); border-radius: 999px; padding: .4rem .9rem; font: inherit; font-family: var(--serif); color: var(--text); cursor: pointer; align-items: center; gap: .5rem; }
        .btn-menu span.barras { display: grid; gap: 3px; } .btn-menu span.barras span { display: block; width: 16px; height: 2px; background: var(--text); }

        /* ---------- Capa ---------- */
        .capa { position: relative; overflow: hidden; background:
            radial-gradient(70% 60% at 12% 8%, rgba(120,160,215,.55) 0%, transparent 70%),
            radial-gradient(60% 50% at 90% 0%, rgba(160,190,230,.45) 0%, transparent 70%),
            radial-gradient(80% 45% at 55% 55%, rgba(255,248,232,.95) 0%, transparent 75%),
            radial-gradient(70% 28% at 20% 100%, rgba(120,150,125,.50) 0%, transparent 75%),
            radial-gradient(70% 24% at 85% 100%, rgba(130,160,170,.45) 0%, transparent 75%),
            linear-gradient(180deg, #dbe7f3 0%, #f6efe2 62%, #e9e4d6 100%); }
        .capa::after { content: ''; position: absolute; inset: 0; background: radial-gradient(circle at 30% 25%, rgba(255,255,255,.35), transparent 40%); pointer-events: none; }
        .capa-in { position: relative; z-index: 1; max-width: 1120px; margin: 0 auto; padding: 4.5rem 1.2rem 5rem; display: grid; grid-template-columns: 1.15fr .85fr; gap: 3rem; align-items: center; min-height: min(calc(100vh - 64px), 820px); }
        .capa .frase { font-family: var(--serif); letter-spacing: .32em; text-transform: uppercase; font-size: .95rem; color: var(--muted); }
        .capa h1 { font-family: var(--script); font-weight: 400; font-size: clamp(3.6rem, 11vw, 7.2rem); color: var(--primary); line-height: 1.02; margin: .6rem 0 1.4rem; }
        .capa h1 .e { display: block; font-size: .55em; color: var(--accent); line-height: 1; }
        /* O bloco dos nomes tem a largura do maior nome; nomes e "&" ficam centralizados nele */
        .capa h1 { width: max-content; max-width: none; }
        .capa h1 .n { display: block; white-space: nowrap; text-align: center; }
        .capa h1 .e { text-align: center; }
        .capa-in > div:first-child { min-width: 0; }
        .moldura .nomes-bv { white-space: nowrap; }
        .detalhes { display: grid; gap: .7rem; margin-bottom: 1.8rem; }
        .detalhes dt { font-family: var(--serif); text-transform: uppercase; letter-spacing: .22em; font-size: .78rem; color: var(--muted); }
        .detalhes dd { margin: 0; font-size: 1.08rem; }
        .contagem-leg { font-family: var(--serif); font-style: italic; color: var(--muted); margin-bottom: .6rem; }
        .contagem { display: flex; gap: .8rem; margin-bottom: 1.8rem; }
        .contagem div { min-width: 78px; text-align: center; padding: .7rem .4rem; background: rgba(255,255,255,.72); border: 1px solid var(--border); border-radius: 12px; }
        .contagem b { display: block; font-family: var(--serif); font-size: 2.3rem; font-weight: 600; line-height: 1.05; color: var(--primary); }
        .contagem small { font-size: .68rem; letter-spacing: .2em; text-transform: uppercase; color: var(--muted); }
        .acoes-capa { display: flex; gap: .8rem; flex-wrap: wrap; }
        .btn { display: inline-flex; align-items: center; gap: .5rem; padding: .8rem 1.7rem; border-radius: 999px; background: var(--primary); color: #fff !important; text-decoration: none; font-family: var(--serif); font-size: 1.08rem; font-weight: 600; letter-spacing: .04em; border: 1px solid var(--primary); cursor: pointer; transition: transform .2s, box-shadow .2s; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,.14); }
        .btn.sec { background: transparent; color: var(--primary) !important; }
        .btn.mini { padding: .45rem 1.1rem; font-size: .95rem; }
        .midia-capa { position: relative; }
        .midia-capa .foto { width: 100%; aspect-ratio: 4 / 5; object-fit: cover; border-radius: 200px 200px 14px 14px; border: 8px solid rgba(255,255,255,.9); box-shadow: 0 24px 60px rgba(40,50,40,.28); display: block; }
        .midia-capa .sem-foto { aspect-ratio: 4 / 5; border-radius: 200px 200px 14px 14px; border: 8px solid rgba(255,255,255,.9); background: rgba(255,255,255,.55); display: flex; align-items: center; justify-content: center; font-family: var(--script); font-size: 5rem; color: var(--primary); box-shadow: 0 24px 60px rgba(40,50,40,.2); }

        /* ---------- Capa: o fundo troca de foto enquanto a página rola ---------- */
        .capa-fundo { position: absolute; inset: 0; z-index: 0; overflow: hidden; pointer-events: none; }
        .fundo-foto { position: absolute; inset: -4%; background-size: cover; background-position: center; opacity: 0; will-change: opacity, transform; }
        .capa-fundo::after { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, rgba(247,246,242,.62) 0%, rgba(247,246,242,.42) 55%, rgba(247,246,242,.30) 100%); opacity: var(--veu, 0); }
        .palco.com-fotos { height: calc(100svh - 64px + var(--fotos) * 75svh); }
        .palco.com-fotos .capa { position: sticky; top: 64px; height: calc(100svh - 64px); min-height: 0; display: flex; align-items: center; }
        .palco.com-fotos .capa-in { min-height: 0; width: 100%; padding-top: 1.5rem; padding-bottom: 1.5rem; }
        @media (max-width: 860px) {
            /* No celular a capa é mais alta que a tela: sem "prender", o fundo troca ao rolar pela própria capa */
            .palco.com-fotos { height: auto; }
            .palco.com-fotos .capa { position: relative; top: 0; height: auto; display: block; }
        }
        @media (prefers-reduced-motion: reduce) { .palco.com-fotos { height: auto; } .palco.com-fotos .capa { position: relative; top: 0; height: auto; display: block; } }

        /* ---------- Faixas de foto com rolagem (parallax) ---------- */
        .faixa { position: relative; width: 100%; height: clamp(20rem, 30vw, 34rem); background: var(--surface-muted) var(--img) center / cover no-repeat fixed; }
        .faixa-fixa { display: none; }
        @media (max-width: 860px) {
            /* iOS/celular ignora background-attachment: fixed — a janela recorta uma foto fixa na tela */
            .faixa { height: clamp(10rem, 42vw, 16rem); background: none; clip-path: inset(0); }
            .faixa-fixa { display: block; position: fixed; inset: 0 0 auto; height: 100lvh; background: var(--surface-muted) var(--img) center / cover no-repeat; pointer-events: none; }
        }
        @media (prefers-reduced-motion: reduce) {
            .faixa { background-attachment: scroll; }
            .faixa-fixa { display: none !important; }
        }
        @media (max-width: 860px) and (prefers-reduced-motion: reduce) { .faixa { background: var(--surface-muted) var(--img) center / cover no-repeat; clip-path: none; } }

        /* ---------- Seções ---------- */
        section.bloco { padding: 5.5rem 1.2rem; }
        section.bloco.surface { background: var(--surface); }
        section.bloco.muted { background: var(--surface-muted); }
        .wrap { max-width: 1040px; margin: 0 auto; }
        .wrap.estreito { max-width: 700px; text-align: center; }
        .intro { font-family: var(--serif); font-size: clamp(1.4rem, 3.4vw, 1.9rem); font-style: italic; line-height: 1.5; color: var(--primary); }
        .titulo-sec { text-align: center; margin-bottom: 3rem; }
        .titulo-sec .sobre { font-family: var(--serif); letter-spacing: .3em; text-transform: uppercase; font-size: .82rem; color: var(--muted); }
        .titulo-sec h2 { font-family: var(--script); font-weight: 400; font-size: clamp(2.8rem, 8vw, 4.4rem); color: var(--primary); line-height: 1.2; }
        .titulo-sec p { max-width: 560px; margin: .6rem auto 0; color: var(--muted); }
        .ornamento { display: block; width: 90px; height: 1px; margin: .9rem auto 0; background: linear-gradient(90deg, transparent, var(--primary), transparent); }
        .historia { display: grid; grid-template-columns: <?= $lado ? '1fr 1fr' : '1fr' ?>; gap: 3rem; align-items: center; }
        .historia .texto { white-space: pre-line; font-size: 1.08rem; }
        /* História com fotos de fundo em carrossel: o texto fica num cartão sobre as fotos */
        section.com-carrossel { position: relative; overflow: hidden; background: #2d312b; padding-block: 6.5rem 7rem; }
        .hist-slides { position: absolute; inset: 0; z-index: 0; }
        .hist-slide { position: absolute; inset: 0; background-size: cover; background-position: center; opacity: 0; transition: opacity 1.6s ease; }
        .hist-slide.ativo { opacity: 1; animation: zoomLento 9s ease-out both; }
        @keyframes zoomLento { from { transform: scale(1); } to { transform: scale(1.07); } }
        .hist-slides::after { content: ''; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(20,24,18,.50), rgba(20,24,18,.34) 50%, rgba(20,24,18,.55)); }
        section.com-carrossel > .wrap { position: relative; z-index: 1; }
        section.com-carrossel .titulo-sec h2, section.com-carrossel .titulo-sec .sobre, section.com-carrossel .titulo-sec p { color: #fff; text-shadow: 0 2px 14px rgba(0,0,0,.45); }
        section.com-carrossel .ornamento { background: linear-gradient(90deg, transparent, #fff, transparent); }
        section.com-carrossel .historia { background: rgba(255,253,249,.90); backdrop-filter: blur(6px); border-radius: 20px; padding: clamp(1.4rem, 4vw, 2.6rem); box-shadow: 0 24px 60px rgba(0,0,0,.30); max-width: 820px; margin: 0 auto; }
        .hist-pontos { position: absolute; z-index: 2; left: 0; right: 0; bottom: 1.8rem; display: flex; justify-content: center; gap: .55rem; }
        .hist-pontos button { width: 10px; height: 10px; padding: 0; border-radius: 50%; border: 1px solid #fff; background: transparent; cursor: pointer; transition: background .3s, transform .3s; }
        .hist-pontos button.ativo { background: #fff; transform: scale(1.25); }
        .moldura-foto { padding: 12px; background: var(--bg); border: 1px solid var(--border); box-shadow: 0 16px 44px rgba(0,0,0,.12); }
        .moldura-foto img { display: block; width: 100%; }
        .moldura-foto figcaption, .lado-leg { text-align: center; font-family: var(--serif); font-style: italic; font-size: 1.1rem; color: var(--muted); margin-top: .6rem; min-height: 1.6em; }
        /* Registro da história: fotos (polaroids) que vão aparecendo conforme a rolagem, ao lado do texto */
        .historia.com-fotos { align-items: stretch; }
        .historia.com-fotos .texto { align-self: start; }   /* altura natural do texto (necessária para decidir o layout das fotos) */
        .lado-pilha { display: flex; flex-direction: column; justify-content: space-around; gap: 2.2rem; padding: .5rem 0; }
        .lado-item { margin: 0 auto; width: min(100%, var(--lado-w, 340px)); background: #fff; padding: 12px 12px 14px; border: 1px solid var(--border); box-shadow: 0 14px 34px rgba(0,0,0,.16); }
        .lado-foto { overflow: hidden; aspect-ratio: 4 / 5; background: var(--surface-muted); }
        .lado-item img { display: block; width: 100%; height: 100%; object-fit: cover; }
        .lado-item figcaption { text-align: center; font-family: var(--serif); font-style: italic; font-size: 1.1rem; color: var(--muted); padding-top: .6rem; min-height: 1.7em; }
        /* Texto curto + muitas fotos: as fotos passam para uma grade abaixo do texto (sem buraco em branco) */
        .historia.lado-abaixo { grid-template-columns: 1fr; gap: 2.4rem; }
        .historia.lado-abaixo .texto { max-width: 760px; margin: 0 auto; }
        .historia.lado-abaixo .lado-pilha { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 1.8rem; justify-items: center; padding: 0; }
        .historia.lado-abaixo .lado-item { width: 100%; max-width: 270px; --atraso: var(--col, 0); }
        /* Entrada animada: a polaroid "cai" sobre a página, assenta com um pequeno balanço e a foto faz um zoom suave */
        .lado-item.reveal { opacity: 0; filter: blur(5px); transform: translateY(80px) rotate(calc(var(--giro) * 4)) scale(.82);
            transition: opacity .7s ease, filter .8s ease, transform 1.1s cubic-bezier(.2, .9, .3, 1.28); transition-delay: calc(var(--atraso, 0) * 130ms); }
        .lado-item.reveal.visivel { opacity: 1; filter: none; transform: rotate(var(--giro)); }
        .lado-item img { transform: scale(1.18); transition: transform 1.6s cubic-bezier(.2, .8, .2, 1); transition-delay: calc(var(--atraso, 0) * 130ms + .15s); }
        .lado-item.visivel img { transform: scale(1); }
        .lado-item img { backface-visibility: hidden; }
        @media (prefers-reduced-motion: reduce) { .lado-item.reveal { opacity: 1; filter: none; transform: rotate(var(--giro)); transition: none; } .lado-item img { transform: none; transition: none; } }
        .locais { display: grid; gap: 1.8rem; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
        .local { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,.06); display: flex; flex-direction: column; }
        .local img { width: 100%; aspect-ratio: 16 / 11; object-fit: cover; display: block; }
        .local .corpo { padding: 1.6rem; text-align: center; flex: 1; display: flex; flex-direction: column; }
        .local .hora { font-family: var(--serif); font-size: 2rem; font-weight: 600; color: var(--accent); line-height: 1; }
        .local h3 { font-family: var(--script); font-weight: 400; font-size: 2.6rem; color: var(--primary); line-height: 1.2; margin: .3rem 0; }
        .local .nome-local { font-weight: 500; }
        .local .end { color: var(--muted); font-size: .94rem; }
        .local .botoes { display: flex; gap: .5rem; flex-wrap: wrap; justify-content: center; margin-top: auto; padding-top: 1.2rem; }
        .trajes { display: grid; gap: 1.8rem; grid-template-columns: repeat(auto-fit, minmax(290px, 1fr)); }
        .traje { background: var(--bg); border: 1px solid var(--border); border-radius: 16px; padding: 2rem 1.6rem; text-align: center; }
        .traje h3 { font-family: var(--script); font-weight: 400; font-size: 2.6rem; color: var(--primary); }
        .desc { color: var(--muted); font-size: .94rem; margin-top: .4rem; }
        .traje-foto { width: 100%; max-height: 360px; object-fit: cover; border-radius: 12px; margin-bottom: 1rem; }
        .hotel-foto { width: 100%; aspect-ratio: 16 / 10; object-fit: cover; border-radius: 10px; margin-bottom: .6rem; }
        .slide { margin: 0; flex: 0 0 min(78vw, 360px); scroll-snap-align: center; }
        .slide img { width: 100%; }
        .slide figcaption { text-align: center; font-family: var(--serif); font-style: italic; font-size: 1.1rem; color: var(--muted); margin-top: .5rem; }
        footer.rodape .contato { margin-top: 1.4rem; font-size: .98rem; }
        .evitar { margin-top: 2.2rem; text-align: center; }
        .evitar h3 { font-family: var(--serif); font-size: 1.3rem; font-weight: 600; margin-bottom: .8rem; }
        .chips { display: flex; flex-wrap: wrap; gap: .6rem; justify-content: center; }
        .chip { display: inline-flex; align-items: center; gap: .5rem; padding: .35rem 1rem .35rem .4rem; background: var(--surface); border: 1px solid var(--border); border-radius: 999px; font-size: .92rem; }
        .chip i { width: 22px; height: 22px; border-radius: 50%; border: 1px solid rgba(0,0,0,.18); display: block; }
        .hoteis { display: grid; gap: 1.2rem; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
        .hotel { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 1.4rem; display: flex; flex-direction: column; gap: .4rem; }
        .hotel h3 { font-family: var(--serif); font-size: 1.45rem; font-weight: 600; color: var(--primary); }
        .hotel p { color: var(--muted); font-size: .94rem; }
        .hotel .btn { align-self: flex-start; margin-top: auto; }
        .carrossel { display: flex; gap: 1rem; overflow-x: auto; scroll-snap-type: x mandatory; padding: .4rem .2rem 1.2rem; -webkit-overflow-scrolling: touch; }
        .carrossel img { height: 440px; object-fit: cover; border-radius: 14px; scroll-snap-align: center; cursor: zoom-in; border: 6px solid var(--surface); box-shadow: 0 10px 28px rgba(0,0,0,.14); }
        .presentes { display: grid; gap: 1.4rem; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); align-items: stretch; }
        .presente { background: var(--surface); border: 1px solid var(--border); border-radius: 18px; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 8px 26px rgba(0,0,0,.06); transition: transform .25s, box-shadow .25s; }
        .presente:hover { transform: translateY(-4px); box-shadow: 0 16px 36px rgba(0,0,0,.12); }
        .presente-img { aspect-ratio: 16 / 9; overflow: hidden; background: var(--surface-muted); }
        .presente-img img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .5s; }
        .presente:hover .presente-img img { transform: scale(1.05); }
        .presente-corpo { padding: 1.4rem 1.4rem 1.5rem; display: flex; flex-direction: column; flex: 1; gap: .7rem; }
        .presente h3 { font-family: var(--serif); font-size: 1.65rem; font-weight: 600; line-height: 1.15; color: var(--text); }
        .presente .desc { margin: 0; }
        .presente-rodape { margin-top: auto; padding-top: 1rem; border-top: 1px solid var(--border); display: flex; flex-direction: column; gap: .55rem; }
        .preco-linha small { color: var(--muted); font-size: .85rem; }
        .cotas-info { display: flex; flex-direction: column; font-size: .88rem; line-height: 1.4; }
        .cotas-info span { color: var(--muted); }
        .progresso { display: flex; height: 7px; background: color-mix(in srgb, var(--primary) 12%, #fff); border-radius: 99px; overflow: hidden; }
        .progresso .ok { background: var(--primary); } .progresso .and { background: color-mix(in srgb, var(--primary) 45%, #fff); }
        .presente .btn.presentear { width: 100%; justify-content: center; margin-top: .3rem; border-radius: 10px; font-family: inherit; font-size: .95rem; padding: .85rem 1rem; }
        .presente .btn:disabled { background: #bdb8b2; border-color: #bdb8b2; cursor: not-allowed; transform: none; box-shadow: none; font-size: .82rem; }
        .presente.esgotado { opacity: .8; }
        .rotulo-cotas { display: block; margin-bottom: .4rem; font-size: .95rem; } .rotulo-cotas small { color: var(--muted); }
        .total-cotas { margin: -.2rem 0 .9rem; color: var(--muted); font-size: .95rem; }
        input[type=number] { width: 100%; padding: .8rem 1rem; margin-bottom: .8rem; border: 1px solid var(--border); border-radius: 10px; font: inherit; background: var(--bg); color: var(--text); }
        .preco { font-family: var(--serif); font-size: 2rem; font-weight: 600; color: var(--accent); line-height: 1; }
        form.caixa { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 1.6rem; max-width: 560px; margin: 0 auto 2.4rem; box-shadow: 0 10px 30px rgba(0,0,0,.05); }
        form.caixa h3 { font-family: var(--script); font-weight: 400; font-size: 2.4rem; color: var(--primary); margin-bottom: .6rem; text-align: center; }
        input[type=text], textarea { width: 100%; padding: .8rem 1rem; margin-bottom: .8rem; border: 1px solid var(--border); border-radius: 10px; font: inherit; background: var(--bg); color: var(--text); }
        input:focus, textarea:focus { outline: 2px solid var(--primary); outline-offset: 1px; }
        .aviso { background: #edf3ea; color: #2f5a2c; border-radius: 10px; padding: .9rem 1.1rem; margin: 0 auto 1.6rem; max-width: 560px; text-align: center; }
        .aviso.erro { background: #fbeaec; color: #8c2f39; }
        .pix-box { background: var(--surface); border: 1px solid var(--primary); border-radius: 14px; padding: 1.5rem; max-width: 560px; margin: 0 auto 2rem; text-align: center; }
        .pix-box code { display: block; background: var(--bg); padding: .7rem; border-radius: 8px; margin: .7rem 0; word-break: break-all; }
        .mural { display: grid; gap: 1.1rem; grid-template-columns: repeat(auto-fill, minmax(270px, 1fr)); }
        .recado { background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--primary); border-radius: 12px; padding: 1.1rem 1.3rem; }
        .recado b { font-family: var(--script); font-weight: 400; font-size: 1.8rem; color: var(--primary); line-height: 1.2; }
        .recado p { white-space: pre-line; font-size: .96rem; }
        footer.rodape { text-align: center; padding: 4rem 1.2rem 3rem; background: var(--primary); color: #f7f6f2; }
        footer.rodape .mono { background: transparent; color: #f7f6f2; border-color: rgba(247,246,242,.6); min-width: 76px; height: 76px; padding: 0 1.1rem; font-size: 2rem; margin-bottom: 1.2rem; }
        footer.rodape .verso { max-width: 600px; margin: 0 auto; font-family: var(--serif); font-style: italic; font-size: 1.4rem; line-height: 1.5; }
        footer.rodape small { display: block; margin-top: 2rem; opacity: .6; font-size: .78rem; letter-spacing: .12em; }
        .rascunho { position: fixed; bottom: 0; left: 0; right: 0; background: #f59e0b; color: #000; text-align: center; padding: .5rem; z-index: 60; font-size: .82rem; font-weight: 600; font-family: system-ui, sans-serif; }
        .reveal { opacity: 0; transform: translateY(20px); transition: opacity .8s ease, transform .8s ease; }
        .reveal.visivel { opacity: 1; transform: none; }
        dialog { border: 0; padding: 0; background: transparent; max-width: 94vw; max-height: 94vh; }
        dialog::backdrop { background: rgba(0,0,0,.85); }
        dialog img { max-width: 94vw; max-height: 90vh; border-radius: 8px; display: block; }
        @media (max-width: 860px) {
            .capa-in { grid-template-columns: 1fr; padding-top: 2.5rem; min-height: 0; text-align: center; }
            .midia-capa { order: -1; max-width: 300px; margin: 0 auto; }
            .contagem, .acoes-capa { justify-content: center; }
            .capa h1 { margin-left: auto; margin-right: auto; }
            .detalhes { text-align: center; }
            .historia { grid-template-columns: 1fr; }
            .btn-menu { display: inline-flex; }
            nav.menu { display: none; position: absolute; top: 100%; left: 0; right: 0; background: var(--bg); border-bottom: 1px solid var(--border); padding: .5rem 1.2rem 1rem; }
            nav.menu.aberto { display: block; }
            nav.menu ul { flex-direction: column; gap: .2rem; } nav.menu a { display: block; padding: .6rem 0; }
            .carrossel img { height: 360px; }
        }
        @media (max-width: 420px) { .contagem div { min-width: 64px; } .contagem b { font-size: 1.9rem; } }
        @media (prefers-reduced-motion: reduce) { .reveal { opacity: 1; transform: none; transition: none; } html { scroll-behavior: auto; } }
    </style>
</head>
<body>
<?php if ($modo_modelo): ?><div class="rascunho" style="background:#3f503a;color:#fff"><i class="bi bi-stars"></i> Modelo de demonstração — é assim que o site do seu casamento pode ficar. Textos, nomes e fotos aqui são fictícios.</div><?php endif; ?>
<?php if (!$site['ativo'] && !$modo_modelo): ?><div class="rascunho">Prévia: só você enxerga esta página. Marque "Publicar" no editor para liberar o link aos convidados.</div><?php endif; ?>

<div class="boas-vindas" id="boasVindas" aria-hidden="true">
    <div class="moldura">
        <div class="nomes-bv"><?= site_h($nome1) ?><?= $nome2 !== '' ? ' &amp; ' . site_h($nome2) : '' ?></div>
        <small><?= site_h(mb_strtoupper($site['titulo'] ?: 'Nós vamos casar')) ?></small>
    </div>
</div>

<header class="topo">
    <div class="topo-in">
        <a class="mono" href="#inicio" aria-label="Início"><?= site_h($ini1) ?><?php if ($ini2): ?><i class="bi bi-heart-fill"></i><?= site_h($ini2) ?><?php endif; ?></a>
        <button type="button" class="btn-menu" id="btnMenu" aria-expanded="false" aria-controls="menuPrincipal"><span class="barras"><span></span><span></span><span></span></span> Menu</button>
        <nav class="menu menu" id="menuPrincipal"><ul>
            <?php foreach ($menu as [$id, $rotulo]): ?><li><a href="#<?= $id ?>"><?= $rotulo ?></a></li><?php endforeach; ?>
        </ul></nav>
    </div>
</header>

<main>
<div class="palco<?= $fotos_capa ? ' com-fotos' : '' ?>" id="palco" style="--fotos:<?= count($fotos_capa) ?>">
<section class="capa" id="inicio">
    <div class="capa-fundo" aria-hidden="true">
        <?php foreach ($fotos_capa as $arq): ?><div class="fundo-foto" style="background-image:url('<?= site_h($pasta_fotos . rawurlencode($arq)) ?>')"></div><?php endforeach; ?>
    </div>
    <div class="capa-in">
        <div>
            <p class="frase"><?= site_h($site['titulo'] ?: 'Nós vamos casar') ?></p>
            <h1 id="nomesCapa"><span class="n"><?= site_h($nome1) ?></span><?php if ($nome2 !== ''): ?><span class="e">&amp;</span><span class="n"><?= site_h($nome2) ?></span><?php endif; ?></h1>
            <dl class="detalhes">
                <div><dt>Quando</dt><dd><?= site_h($quando) ?></dd></div>
                <?php if ($onde !== ''): ?><div><dt>Onde</dt><dd><?= site_h($onde) ?></dd></div><?php endif; ?>
            </dl>
            <p class="contagem-leg">Contagem para o dia do casamento</p>
            <div class="contagem" id="contagem" data-alvo="<?= site_h($alvo_js) ?>">
                <div><b data-u="d">—</b><small>Dias</small></div><div><b data-u="h">—</b><small>Horas</small></div>
                <div><b data-u="m">—</b><small>Min</small></div><div><b data-u="s">—</b><small>Seg</small></div>
            </div>
            <div class="acoes-capa">
                <a class="btn" href="<?= $modo_modelo ? '#detalhes' : $base . '/confirmar.php?evento=' . $evento_id ?>"><i class="bi bi-envelope-heart"></i> Confirmar presença</a>
                <?php if ($prog): ?><a class="btn sec" href="#programacao">Ver programação</a><?php endif; ?>
            </div>
        </div>
        <div class="midia-capa">
            <?php if ($capa_url): ?><img class="foto" src="<?= site_h($capa_url) ?>" alt="<?= site_h($nomes) ?>">
            <?php else: ?><div class="sem-foto"><?= site_h($ini1) ?><?= $ini2 ? '&amp;' . site_h($ini2) : '' ?></div><?php endif; ?>
        </div>
    </div>
</section>
</div>

<?php if ($intro !== ''): ?>
<section class="bloco" id="detalhes"><div class="wrap estreito reveal"><p class="intro"><?= site_h($intro) ?></p></div></section>
<?php endif; ?>

<?php if ($tem_historia): ?>
<?php $hist_fotos = $ex['historia_fotos']; ?>
<section class="bloco surface<?= $hist_fotos ? ' com-carrossel' : '' ?>" id="historia">
<?php if ($hist_fotos): ?>
    <div class="hist-slides" aria-hidden="true">
        <?php foreach ($hist_fotos as $k => $arq): ?><div class="hist-slide<?= $k === 0 ? ' ativo' : '' ?>" style="background-image:url('<?= site_h($pasta_fotos . rawurlencode($arq)) ?>')"></div><?php endforeach; ?>
    </div>
    <?php if (count($hist_fotos) > 1): ?><div class="hist-pontos" role="tablist" aria-label="Fotos da nossa história"><?php foreach ($hist_fotos as $k => $_): ?><button type="button" class="<?= $k === 0 ? 'ativo' : '' ?>" aria-label="Foto <?= $k + 1 ?>"></button><?php endforeach; ?></div><?php endif; ?>
<?php endif; ?>
<div class="wrap reveal">
    <div class="titulo-sec"><p class="sobre">Um pouco de nós</p><h2><?= site_h($t('historia_titulo', 'Nossa história')) ?></h2><span class="ornamento"></span><?php if ($d = $t('historia_desc')): ?><p><?= site_h($d) ?></p><?php endif; ?></div>
    <div class="historia<?= count($lado) > 1 ? ' com-fotos' : '' ?>">
        <div class="texto"><?= site_h($site['historia']) ?></div>
        <?php if (count($lado) === 1): $leg = $ex['legendas'][$lado[0]] ?? ''; ?>
            <figure class="moldura-foto" style="margin:0"><img src="<?= $pasta_fotos ?><?= rawurlencode($lado[0]) ?>" alt="<?= site_h($leg ?: 'Nossa história') ?>" loading="lazy"><?php if ($leg !== ''): ?><figcaption><?= site_h($leg) ?></figcaption><?php endif; ?></figure>
        <?php elseif (count($lado) > 1): ?>
            <div class="lado-pilha" aria-label="Fotos da nossa história">
                <?php foreach ($lado as $k => $arq): $leg = $ex['legendas'][$arq] ?? ''; ?>
                    <figure class="lado-item reveal" style="--giro: <?= $k % 2 === 0 ? '-2' : '2' ?>deg; --col: <?= $k % 4 ?>">
                        <div class="lado-foto"><img src="<?= $pasta_fotos ?><?= rawurlencode($arq) ?>" alt="<?= site_h($leg ?: 'Nossa história') ?>" <?= $k === 0 ? '' : 'loading="lazy"' ?>></div>
                        <?php if ($leg !== ''): ?><figcaption><?= site_h($leg) ?></figcaption><?php endif; ?>
                    </figure>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div></section>
<?php endif; ?>

<?php $faixa(); ?>
<?php if ($prog): ?>
<section class="bloco" id="programacao"><div class="wrap reveal">
    <div class="titulo-sec"><p class="sobre">O grande dia</p><h2><?= site_h($t('programacao_titulo', 'Programação')) ?></h2><span class="ornamento"></span><?php if ($d = $t('programacao_desc')): ?><p><?= site_h($d) ?></p><?php endif; ?></div>
    <div class="locais">
    <?php foreach ($prog as $i => $p): $end = trim(($p['local'] ?? '') . ' ' . ($p['endereco'] ?? '')); ?>
        <article class="local">
            <?php if (!empty($p['foto'])): ?><img src="<?= $pasta_fotos ?><?= rawurlencode($p['foto']) ?>" alt="<?= site_h($p['local'] ?? '') ?>" loading="lazy"><?php endif; ?>
            <div class="corpo">
                <?php if (!empty($p['hora'])): ?><div class="hora"><?= site_h($p['hora']) ?></div><?php endif; ?>
                <h3><?= site_h($p['titulo']) ?></h3>
                <?php if (!empty($p['local'])): ?><p class="nome-local"><?= site_h($p['local']) ?></p><?php endif; ?>
                <?php if (!empty($p['endereco'])): ?><p class="end"><?= site_h($p['endereco']) ?></p><?php endif; ?>
                <?php if (!empty($p['descricao'])): ?><p class="desc"><?= nl2br(site_h($p['descricao'])) ?></p><?php endif; ?>
                <div class="botoes">
                    <a class="btn mini sec" href="?ics=<?= $i ?>"><i class="bi bi-calendar-plus"></i> Adicionar à agenda</a>
                    <?php if ($end !== ''): ?>
                        <a class="btn mini" target="_blank" rel="noopener" href="<?= site_h($maps($end)) ?>"><i class="bi bi-geo-alt"></i> Maps</a>
                        <a class="btn mini" target="_blank" rel="noopener" href="<?= site_h($waze($end)) ?>"><i class="bi bi-sign-turn-right"></i> Waze</a>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
    </div>
</div></section>
<?php endif; ?>

<?php if ($tem_dress): ?>
<section class="bloco surface" id="traje"><div class="wrap reveal">
    <div class="titulo-sec"><p class="sobre">Como se vestir</p><h2><?= site_h($t('traje_titulo', 'Dress code')) ?></h2><span class="ornamento"></span><?php if ($d = $t('traje_desc')): ?><p><?= site_h($d) ?></p><?php endif; ?></div>
    <div class="trajes">
        <?php if (trim((string)$site['dress_eles']) !== ''): ?><div class="traje"><?php if ($ex['traje_eles_foto']): ?><img class="traje-foto" src="<?= $pasta_fotos ?><?= rawurlencode($ex['traje_eles_foto']) ?>" alt="Traje masculino" loading="lazy"><?php endif; ?><h3>Para eles</h3><p><?= nl2br(site_h($site['dress_eles'])) ?></p></div><?php endif; ?>
        <?php if (trim((string)$site['dress_elas']) !== ''): ?><div class="traje"><?php if ($ex['traje_elas_foto']): ?><img class="traje-foto" src="<?= $pasta_fotos ?><?= rawurlencode($ex['traje_elas_foto']) ?>" alt="Traje feminino" loading="lazy"><?php endif; ?><h3>Para elas</h3><p><?= nl2br(site_h($site['dress_elas'])) ?></p></div><?php endif; ?>
    </div>
    <?php if ($cores_evitar): ?>
        <div class="evitar"><h3>Cores a evitar</h3>
            <div class="chips"><?php foreach ($cores_evitar as $c): ?><span class="chip"><i style="background:<?= site_h(site_cor_do_nome($c)) ?>"></i><?= site_h($c) ?></span><?php endforeach; ?></div>
        </div>
    <?php endif; ?>
</div></section>
<?php endif; ?>

<?php $faixa(); ?>
<?php if ($tem_hospedagem): ?>
<section class="bloco" id="hospedagem"><div class="wrap reveal">
    <div class="titulo-sec"><p class="sobre">Para quem vem de longe</p><h2><?= site_h($t('hospedagem_titulo', 'Hospedagem')) ?></h2><span class="ornamento"></span><?php if ($d = $t('hospedagem_desc')): ?><p><?= site_h($d) ?></p><?php endif; ?></div>
    <div class="hoteis">
    <?php foreach ($hosp as $h): $end = trim(($h['nome'] ?? '') . ' ' . ($h['endereco'] ?? '')); ?>
        <div class="hotel">
            <?php if (!empty($h['foto'])): ?><img class="hotel-foto" src="<?= $pasta_fotos ?><?= rawurlencode($h['foto']) ?>" alt="<?= site_h($h['nome']) ?>" loading="lazy"><?php endif; ?>
            <h3><?= site_h($h['nome']) ?></h3>
            <?php if (!empty($h['endereco'])): ?><p><?= site_h($h['endereco']) ?></p><?php endif; ?>
            <?php if (!empty($h['descricao'])): ?><p><?= nl2br(site_h($h['descricao'])) ?></p><?php endif; ?>
            <a class="btn mini sec" target="_blank" rel="noopener" href="<?= site_h($maps($end)) ?>"><i class="bi bi-geo-alt"></i> Ver no mapa</a></div>
    <?php endforeach; ?>
    </div>
</div></section>
<?php endif; ?>

<?php if ($galeria): ?>
<section class="bloco surface" id="galeria"><div class="wrap reveal">
    <div class="titulo-sec"><p class="sobre">Momentos</p><h2><?= site_h($t('galeria_titulo', 'Entre olhares e detalhes')) ?></h2><span class="ornamento"></span><?php if ($d = $t('galeria_desc')): ?><p><?= site_h($d) ?></p><?php endif; ?></div>
    <div class="carrossel">
        <?php foreach ($galeria as $arq): $leg = $ex['legendas'][$arq] ?? ''; ?>
            <figure class="slide"><img src="<?= $pasta_fotos ?><?= rawurlencode($arq) ?>" alt="<?= site_h($leg) ?>" loading="lazy" onclick="abrirFoto(this.src)"><?php if ($leg !== ''): ?><figcaption><?= site_h($leg) ?></figcaption><?php endif; ?></figure>
        <?php endforeach; ?>
    </div>
</div></section>
<dialog id="lightbox" onclick="this.close()"><img src="" alt=""></dialog>
<?php endif; ?>

<?php if ($presentes): ?>
<section class="bloco" id="presentes"><div class="wrap reveal">
    <div class="titulo-sec"><p class="sobre">Com carinho</p><h2><?= site_h($t('presentes_titulo', 'Lista de presentes')) ?></h2><span class="ornamento"></span><?php if ($d = $t('presentes_desc', 'Se quiser nos presentear, escolha uma opção abaixo. Cada gesto fará parte do nosso começo.')): ?><p><?= site_h($d) ?></p><?php endif; ?></div>
    <?php if ($ultimo_presente): ?>
        <div class="pix-box">
            <strong>Obrigado! Agora é só fazer o Pix de R$ <?= number_format($ultimo_presente['valor'], 2, ',', '.') ?></strong>
            <?php if ($tem_pix): ?>
                <code id="chavePix"><?= site_h($site['pix_chave']) ?></code>
                <?php if ($site['pix_titular']): ?><small>Titular: <?= site_h($site['pix_titular']) ?></small><br><?php endif; ?>
                <button type="button" class="btn mini" style="margin-top:.6rem" onclick="navigator.clipboard.writeText(document.getElementById('chavePix').textContent); this.textContent='Copiado!'">Copiar chave Pix</button>
            <?php else: ?><p>Os noivos entrarão em contato com os dados para o pagamento.</p><?php endif; ?>
        </div>
    <?php elseif ($flash === 'esgotado'): ?><div class="aviso erro">Essas cotas acabaram de ser escolhidas por outra pessoa. Escolha outra quantidade ou outro presente.</div>
    <?php elseif ($flash === 'erro'): ?><div class="aviso erro">Não foi possível registrar. Confira o nome e o valor.</div><?php endif; ?>
    <div class="presentes">
    <?php foreach ($presentes as $p):
        $est = $estado_pres[$p['id']]; $livre = $est['livre']; $esgotado = !$livre && $est['disponiveis'] <= 0;
        $pct_ok = !$livre && $est['total'] ? min(100, round($est['recebidas'] / $est['total'] * 100)) : 0;
        $pct_and = !$livre && $est['total'] ? min(100 - $pct_ok, round($est['andamento'] / $est['total'] * 100)) : 0;
    ?>
        <article class="presente<?= $esgotado ? ' esgotado' : '' ?>">
            <?php if (!empty($p['foto'])): ?><div class="presente-img"><img src="<?= $pasta_fotos ?><?= rawurlencode($p['foto']) ?>" alt="<?= site_h($p['nome']) ?>" loading="lazy"></div><?php endif; ?>
            <div class="presente-corpo">
                <h3><?= site_h($p['nome']) ?></h3>
                <?php if (!empty($p['descricao'])): ?><p class="desc"><?= site_h($p['descricao']) ?></p><?php endif; ?>
                <div class="presente-rodape">
                    <div class="preco-linha">
                        <?php if ($livre): ?><span class="preco">Valor livre</span>
                        <?php else: ?><span class="preco">R$ <?= number_format((float)$p['valor'], 2, ',', '.') ?></span> <small>por cota</small><?php endif; ?>
                    </div>
                    <?php if ($livre): ?>
                        <div class="cotas-info"><strong><?= $est['recebidas'] ?> presente<?= $est['recebidas'] === 1 ? '' : 's' ?> recebido<?= $est['recebidas'] === 1 ? '' : 's' ?></strong><span>Escolha o valor que couber no seu carinho</span></div>
                    <?php else: ?>
                        <div class="cotas-info">
                            <strong><?= $est['recebidas'] ?> de <?= $est['total'] ?> cota<?= $est['total'] === 1 ? '' : 's' ?> presenteada<?= $est['total'] === 1 ? '' : 's' ?></strong>
                            <span><?php if ($est['andamento'] > 0): ?><?= $est['andamento'] ?> cota<?= $est['andamento'] === 1 ? '' : 's' ?> com pagamento em andamento · <?php endif; ?><?= $est['disponiveis'] ?> <?= $est['disponiveis'] === 1 ? 'disponível' : 'disponíveis' ?></span>
                        </div>
                        <div class="progresso" role="progressbar" aria-valuenow="<?= $pct_ok ?>" aria-valuemin="0" aria-valuemax="100"><span class="ok" style="width:<?= $pct_ok ?>%"></span><span class="and" style="width:<?= $pct_and ?>%"></span></div>
                    <?php endif; ?>
                    <?php if ($esgotado): ?>
                        <button class="btn presentear" type="button" disabled>Todas as cotas já foram escolhidas</button>
                    <?php else: ?>
                        <button class="btn presentear" type="button" onclick="abrirPresente('<?= site_h($p['id']) ?>', <?= site_h(json_encode($p['nome'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>, <?= $livre ? 'true' : 'false' ?>, <?= $livre ? 0 : (int)$est['disponiveis'] ?>, <?= (float)$p['valor'] ?>)">Presentear</button>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
    </div>
    <form method="post" class="caixa" id="formPresente" style="display:none; margin-top:2.2rem">
        <input type="hidden" name="acao" value="presente"><input type="hidden" name="presente_id" id="presenteId">
        <h3 id="presenteTitulo"></h3>
        <input type="text" name="nome" placeholder="Seu nome" required maxlength="100">
        <div id="blocoCotas" style="display:none">
            <label for="presenteCotas" class="rotulo-cotas">Quantas cotas deseja presentear? <small id="cotasMax"></small></label>
            <input type="number" name="cotas" id="presenteCotas" min="1" value="1" inputmode="numeric">
            <p class="total-cotas">Total: <strong id="presenteTotal"></strong></p>
        </div>
        <input type="text" name="valor" id="presenteValor" placeholder="Valor que deseja dar (R$)" inputmode="decimal" style="display:none">
        <textarea name="mensagem" rows="2" maxlength="300" placeholder="Mensagem (opcional)"></textarea>
        <input type="text" name="site_url_extra" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
        <button class="btn" type="submit" style="width:100%;justify-content:center">Continuar para o Pix</button>
    </form>
</div></section>
<?php endif; ?>

<?php if (!$oculta('recados')): ?>
<?php $faixa(); ?>
<section class="bloco <?= $presentes ? 'surface' : '' ?>" id="recados"><div class="wrap reveal">
    <div class="titulo-sec"><p class="sobre">Mural</p><h2><?= site_h($t('recados_titulo', 'Deixe seu recado')) ?></h2><span class="ornamento"></span><?php if ($d = $t('recados_desc', 'Sua mensagem aparece aqui depois de aprovada pelos noivos.')): ?><p><?= site_h($d) ?></p><?php endif; ?></div>
    <?php if ($flash === 'recado'): ?><div class="aviso">Recado enviado! Ele aparecerá aqui assim que os noivos aprovarem.</div><?php endif; ?>
    <form method="post" class="caixa">
        <input type="hidden" name="acao" value="recado">
        <input type="text" name="nome" placeholder="Seu nome" required maxlength="100">
        <textarea name="mensagem" rows="3" required maxlength="600" placeholder="Escreva uma mensagem para os noivos"></textarea>
        <input type="text" name="site_url_extra" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
        <button class="btn" type="submit" style="width:100%;justify-content:center">Enviar recado</button>
    </form>
    <div class="mural">
        <?php foreach ($recados as $r): ?><div class="recado"><b><?= site_h($r['nome']) ?></b><p><?= site_h($r['mensagem']) ?></p></div><?php endforeach; ?>
    </div>
</div></section>
<?php endif; ?>
</main>

<footer class="rodape">
    <div class="mono"><?= site_h($ini1) ?><?php if ($ini2): ?><i class="bi bi-heart-fill"></i><?= site_h($ini2) ?><?php endif; ?></div>
    <?php if (trim((string)$site['versiculo']) !== ''): ?><p class="verso">“<?= site_h($site['versiculo']) ?>”</p><?php endif; ?>
    <?php if ($t('contato') !== ''): ?><p class="contato"><i class="bi bi-envelope-heart"></i> <?= site_h($t('contato')) ?></p><?php endif; ?>
    <small>FEITO COM MEU EVENTO PRO</small>
</footer>

<script>
(function () {
    var alvo = new Date(document.getElementById('contagem').dataset.alvo).getTime();
    var els = {}; document.querySelectorAll('#contagem [data-u]').forEach(function (e) { els[e.dataset.u] = e; });
    function tick() {
        var f = Math.max(0, alvo - Date.now());
        els.d.textContent = Math.floor(f / 864e5); els.h.textContent = Math.floor(f % 864e5 / 36e5);
        els.m.textContent = Math.floor(f % 36e5 / 6e4); els.s.textContent = Math.floor(f % 6e4 / 1e3);
    }
    tick(); setInterval(tick, 1000);

    var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (es) {
        es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('visivel'); io.unobserve(e.target); } });
    }, { threshold: .06 }) : null;
    document.querySelectorAll('.reveal').forEach(function (s) { io ? io.observe(s) : s.classList.add('visivel'); });

    // Fundo da capa: cada foto aparece em fade conforme a rolagem avança pelo "palco".
    var palco = document.getElementById('palco'), fotos = palco ? palco.querySelectorAll('.fundo-foto') : [];
    if (fotos.length) {
        var capa = palco.querySelector('.capa'), reduz = window.matchMedia('(prefers-reduced-motion: reduce)').matches, agendado = false;
        function atualizaFundo() {
            agendado = false;
            var r = palco.getBoundingClientRect(), prende = getComputedStyle(capa).position === 'sticky';
            var faixa = prende ? Math.max(1, palco.offsetHeight - capa.offsetHeight) : Math.max(1, capa.offsetHeight * 0.85);
            var p = Math.min(1, Math.max(0, (-r.top + (prende ? 64 : 0)) / faixa)), n = fotos.length, veu = 0;
            fotos.forEach(function (f, i) {
                var o = Math.min(1, Math.max(0, p * n - i));
                f.style.opacity = reduz ? (i === 0 ? 1 : 0) : o;
                f.style.transform = 'scale(' + (1.04 + p * 0.04) + ') translateY(' + (-p * 2) + '%)';
                veu = Math.max(veu, o);
            });
            capa.style.setProperty('--veu', veu);
        }
        window.addEventListener('scroll', function () { if (!agendado) { agendado = true; requestAnimationFrame(atualizaFundo); } }, { passive: true });
        window.addEventListener('resize', atualizaFundo);
        atualizaFundo();
    }

    // Carrossel de fotos de fundo da história: troca sozinho; os pontos permitem escolher.
    var slides = document.querySelectorAll('.hist-slide'), pontos = document.querySelectorAll('.hist-pontos button');
    if (slides.length > 1) {
        var atual = 0, timer = null, reduzSl = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        function irPara(n) {
            slides[atual].classList.remove('ativo'); if (pontos[atual]) pontos[atual].classList.remove('ativo');
            atual = (n + slides.length) % slides.length;
            slides[atual].classList.add('ativo'); if (pontos[atual]) pontos[atual].classList.add('ativo');
        }
        function iniciar() { if (!reduzSl && !timer) timer = setInterval(function () { irPara(atual + 1); }, 5500); }
        function parar() { clearInterval(timer); timer = null; }
        pontos.forEach(function (p, i) { p.addEventListener('click', function () { parar(); irPara(i); iniciar(); }); });
        document.addEventListener('visibilitychange', function () { document.hidden ? parar() : iniciar(); });
        iniciar();
    }

    // Nomes auto-ajustáveis: cada nome fica em uma linha e a letra encolhe até caber (capa e animação de boas-vindas)
    function ajustaNomes() {
        var h = document.getElementById('nomesCapa');
        if (h) {
            h.style.fontSize = '';
            var fs = parseFloat(getComputedStyle(h).fontSize), largura = h.parentElement.clientWidth, maior = 0;
            h.querySelectorAll('.n').forEach(function (n) { maior = Math.max(maior, n.offsetWidth); });
            if (maior > largura && maior > 0) h.style.fontSize = Math.max(32, fs * largura / maior * 0.98) + 'px';
        }
        var bvn = document.querySelector('.nomes-bv');
        if (bvn) {
            bvn.style.fontSize = '';
            var f2 = parseFloat(getComputedStyle(bvn).fontSize), disp = Math.min(window.innerWidth * 0.86, 640) - 90;
            var natural = bvn.scrollWidth;
            if (natural > disp && natural > 0) bvn.style.fontSize = Math.max(24, f2 * disp / natural * 0.98) + 'px';
        }
    }
    ajustaNomes();
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(ajustaNomes);
    window.addEventListener('resize', ajustaNomes);
    window.addEventListener('load', ajustaNomes);

    // Fotos da história: se ficarem bem mais altas que o texto, vão para uma grade abaixo dele
    function ajustaHistoria() {
        var cx = document.querySelector('.historia.com-fotos');
        if (!cx) return;
        cx.classList.remove('lado-abaixo');
        var texto = cx.querySelector('.texto'), pilha = cx.querySelector('.lado-pilha');
        if (!texto || !pilha) return;
        pilha.style.removeProperty('--lado-w');
        if (window.innerWidth <= 860) return;   // no celular já é uma coluna só
        function altura() {
            var itens = pilha.children, soma = 0;
            for (var i = 0; i < itens.length; i++) soma += itens[i].offsetHeight;
            return soma + parseFloat(getComputedStyle(pilha).rowGap || 0) * (itens.length - 1);
        }
        var h = texto.offsetHeight, a = altura();
        if (a <= h * 1.1) return;                       // cabe ao lado do texto
        var r = h / a;
        if (r < 0.62) { cx.classList.add('lado-abaixo'); return; }   // fotos demais para o texto: grade abaixo
        // um pouco mais altas que o texto: encolhe as polaroids para acompanharem o texto
        pilha.style.setProperty('--lado-w', Math.max(210, 340 * r) + 'px');
        if (altura() > h * 1.15 + 40) cx.classList.add('lado-abaixo');
    }
    ajustaHistoria();
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(ajustaHistoria);
    window.addEventListener('resize', ajustaHistoria);
    window.addEventListener('load', ajustaHistoria);

    <?php if ($modo_modelo): ?>
    // Demonstração: os formulários não enviam nada
    document.querySelectorAll('form.caixa').forEach(function (f) {
        f.addEventListener('submit', function (e) { e.preventDefault(); alert('Esta é uma página de demonstração. No site real, a mensagem do convidado chegaria para os noivos.'); });
    });
    <?php endif; ?>

    var bv = document.getElementById('boasVindas');
    if (document.documentElement.classList.contains('com-boas-vindas')) {
        setTimeout(function () { bv.classList.add('saindo'); try { sessionStorage.setItem('bv_<?= $evento_id ?>', '1'); } catch (e) {} }, 2300);
    }

    var btn = document.getElementById('btnMenu'), menu = document.getElementById('menuPrincipal');
    btn.addEventListener('click', function () { var a = menu.classList.toggle('aberto'); btn.setAttribute('aria-expanded', a); });
    menu.addEventListener('click', function (e) { if (e.target.tagName === 'A') { menu.classList.remove('aberto'); btn.setAttribute('aria-expanded', false); } });
})();
function abrirFoto(src) { var d = document.getElementById('lightbox'); d.querySelector('img').src = src; d.showModal(); }
function abrirPresente(id, nome, livre, disponiveis, valorCota) {
    document.getElementById('presenteId').value = id;
    document.getElementById('presenteTitulo').textContent = nome;
    var v = document.getElementById('presenteValor'); v.style.display = livre ? 'block' : 'none'; v.required = livre;
    var bloco = document.getElementById('blocoCotas'), qtd = document.getElementById('presenteCotas');
    var mostraCotas = !livre && disponiveis > 1;
    bloco.style.display = mostraCotas ? 'block' : 'none';
    qtd.max = Math.max(1, disponiveis); qtd.value = 1; qtd.disabled = !mostraCotas;
    document.getElementById('cotasMax').textContent = '(até ' + disponiveis + ')';
    var fmt = function (n) { return 'R$ ' + n.toFixed(2).replace('.', ','); };
    var total = document.getElementById('presenteTotal');
    function atualiza() { var q = Math.min(Math.max(1, parseInt(qtd.value) || 1), disponiveis); total.textContent = livre ? '' : fmt(valorCota * q); }
    qtd.oninput = atualiza; atualiza();
    var f = document.getElementById('formPresente'); f.style.display = 'block'; f.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
</script>
</body>
</html>
