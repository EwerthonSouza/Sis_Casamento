<?php
/*
 * SÓ NO AMBIENTE LOCAL (Docker) — carregado antes de toda página pelo
 * auto_prepend_file de docker/php-dev.ini. Não existe no servidor online.
 * Tudo aqui só vale quando o endereço é localhost/127.0.0.1: acessando pelo
 * IP da rede (iPhone em http://192.168.x.x) nada muda.
 *
 * Feito pra pré-visualizações que abrem o site dentro de um iframe (ex.: a
 * extensão "Mobile Preview" do VS Code):
 *  1. Sessão: o cookie padrão (SameSite=Lax, "de sessão") é descartado dentro
 *     do iframe — o login "não pega" ou some ao recarregar.
 *  2. Última página: a extensão volta pro endereço inicial em várias
 *     situações (trocar de aba/arquivo). O sistema lembra a última página
 *     aberta e leva de volta pra ela.
 *  3. Recarregar sozinho: quando algum arquivo do projeto muda, a página se
 *     recarrega sozinha (voltando pra mesma posição de rolagem).
 */
if (PHP_SAPI === 'cli' || !preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $_SERVER['HTTP_HOST'] ?? '')) {
    return;
}

// ---------- 1. Sessão válida dentro de iframe ----------
// SameSite=None exige Secure — o navegador aceita em http://localhost.
// Validade de 7 dias pra sobreviver a recarregamentos da pré-visualização;
// a regra de 30 min de inatividade (sessao_timeout.inc.php) continua valendo.
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_lifetime', (string)(7 * 24 * 60 * 60));
ini_set('session.gc_maxlifetime', (string)(7 * 24 * 60 * 60));

$dev_script  = basename($_SERVER['SCRIPT_NAME'] ?? '');
$dev_metodo  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$dev_uri     = $_SERVER['REQUEST_URI'] ?? '/';
// Navegação de verdade (página aberta), não fetch/AJAX nem imagem.
$dev_destino = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? 'document';
$dev_eh_navegacao = in_array($dev_destino, ['document', 'iframe'], true);

function dev_cookie(string $nome, string $valor, int $validade): void {
    setcookie($nome, $valor, [
        'expires'  => $validade,
        'path'     => '/',
        'secure'   => true,
        'samesite' => 'None',
    ]);
}

// ---------- 2. Última página ----------
$dev_nao_lembrar = ['index.php', 'logout.php', 'relatorio_pdf.php', 'confirmar.php', 'livereload.php'];
if ($dev_script === 'logout.php') {
    dev_cookie('mp_ultima_pagina', '', time() - 3600); // saiu: esquece
} elseif ($dev_script === 'index.php' && $dev_metodo === 'GET' && ($_SERVER['QUERY_STRING'] ?? '') === ''
          && !empty($_COOKIE['PHPSESSID']) && !empty($_COOKIE['mp_ultima_pagina'])) {
    // Voltou pro endereço inicial com login ativo: leva pra última página.
    // (Se a sessão tiver vencido, a própria página manda de volta pro login.)
    $ultima = $_COOKIE['mp_ultima_pagina'];
    if (preg_match('#^/[A-Za-z0-9_\-]+\.php(\?[^\s]*)?$#', $ultima)) { // só caminho interno
        header('Location: ' . $ultima);
        exit;
    }
} elseif ($dev_metodo === 'GET' && $dev_eh_navegacao && !in_array($dev_script, $dev_nao_lembrar, true)
          && preg_match('/\.php$/', $dev_script)) {
    dev_cookie('mp_ultima_pagina', $dev_uri, time() + 7 * 24 * 60 * 60);
}

// ---------- 3. Recarregar sozinho quando um arquivo muda ----------
if ($dev_eh_navegacao && $dev_script !== 'livereload.php') {
    ob_start(function ($html) {
        // Só em página HTML completa (JSON, PDF etc. passam intactos)
        foreach (headers_list() as $h) {
            if (stripos($h, 'Content-Type:') === 0 && stripos($h, 'text/html') === false) {
                return $html;
            }
        }
        $pos = strripos($html, '</body>');
        if ($pos === false) {
            return $html;
        }
        $script = <<<'JS'
<script>
/* SÓ LOCAL (docker/dev-session.php): recarrega sozinho quando algum arquivo
   do projeto muda, voltando pra mesma posição de rolagem. */
(function () {
    var chave = 'lr_scroll:' + location.pathname + location.search, versao = null;
    try {
        var y = sessionStorage.getItem(chave);
        if (y !== null) {
            sessionStorage.removeItem(chave);
            window.addEventListener('load', function () { window.scrollTo(0, parseInt(y, 10) || 0); });
        }
    } catch (e) {}
    function checar() {
        if (document.hidden) return; // aba escondida: confere quando voltar
        fetch('/docker/livereload.php', { cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (versao === null) { versao = d.v; return; }
                if (d.v !== versao) {
                    try { sessionStorage.setItem(chave, String(window.scrollY)); } catch (e) {}
                    location.reload();
                }
            })
            .catch(function () {});
    }
    checar();
    setInterval(checar, 1000);
    // Voltou pra aba: confere na hora (pega o que mudou enquanto estava escondida)
    document.addEventListener('visibilitychange', function () { if (!document.hidden) checar(); });
})();
</script>
JS;
        return substr($html, 0, $pos) . $script . substr($html, $pos);
    });
}
