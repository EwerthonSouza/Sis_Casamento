<?php
session_start();
require_once 'sessao_timeout.inc.php';
verificar_sessao_ativa();

$tipo_usuario = $_SESSION['usuario_tipo'] ?? '';
$eh_equipe = in_array($tipo_usuario, ['admin', 'assistente', 'desenvolvedor'], true);
if (!$eh_equipe && $tipo_usuario !== 'noivos') {
    header("Location: index.php?sessao_expirada=1");
    exit;
}

require_once 'conexao.php';
require_once 'tenant.php';
require_once 'site_convite.inc.php';

// Noivos só editam o próprio evento (ignora a URL); a equipe escolhe o evento
// por ?id= e fica presa ao módulo ativo e à própria assessoria, igual às demais páginas.
if ($eh_equipe) {
    $evento_id = (int)($_GET['id'] ?? 0);
    if (!$evento_id) { header("Location: painel_admin.php"); exit; }
} else {
    if (empty($_SESSION['evento_id'])) { header("Location: hub_eventos_cliente.php"); exit; }
    $evento_id = (int)$_SESSION['evento_id'];
}

$stmt = $pdo->prepare("SELECT e.*, c.nome AS nome_cliente, c.nome_secundario FROM eventos e INNER JOIN clientes c ON e.cliente_id = c.id WHERE e.id = ?");
$stmt->execute([$evento_id]);
$evento = $stmt->fetch();
if (!$evento) {
    header($eh_equipe ? "Location: painel_admin.php" : "Location: hub_eventos_cliente.php");
    exit;
}
if ($eh_equipe) {
    $modulo_ativo = $_SESSION['modulo_ativo'] ?? null;
    if (!$modulo_ativo || $evento['tipo_evento'] !== $modulo_ativo
        || !eh_registro_da_assessoria_atual($evento['assessoria_id'] ?? null)) {
        header("Location: painel_admin.php");
        exit;
    }
} elseif ((int)$evento['cliente_id'] !== (int)$_SESSION['usuario_id']) {
    header("Location: hub_eventos_cliente.php");
    exit;
}
$url_voltar = $eh_equipe ? 'gerenciar.php?id=' . $evento_id : 'noivos.php';
$url_editor = 'site_editar.php' . ($eh_equipe ? '?id=' . $evento_id : '');

site_garantir_schema($pdo);

// O site é um item contratado: o editor só abre depois que a Central libera o acesso (após o pagamento).
if (!site_acesso_liberado($pdo, $evento_id)) {
    header('Location: ' . $url_voltar);
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Primeira visita: cria o rascunho já preenchido com o que o sistema sabe do evento.
$stmt = $pdo->prepare("SELECT * FROM site_convite WHERE evento_id = ?");
$stmt->execute([$evento_id]);
$site = $stmt->fetch();
if (!$site) {
    // Primeira abertura depois da liberação: já nasce com o site-modelo preenchido (o casal troca textos e fotos).
    $slug = site_slug_unico($pdo, trim(explode(' ', $evento['nome_cliente'])[0] . '-e-' . explode(' ', (string)($evento['nome_secundario'] ?? ''))[0], '-'), $evento_id);
    site_aplicar_modelo($pdo, $evento_id, $slug);
    $stmt = $pdo->prepare("SELECT * FROM site_convite WHERE evento_id = ?");
    $stmt->execute([$evento_id]);
    $site = $stmt->fetch();
    $_SESSION['site_msg_ok'] = 'Seu site já veio preenchido com um modelo. Troque os textos e as fotos pelos de vocês e, quando estiver pronto, marque "Publicar".';
}

$msg_ok = $_SESSION['site_msg_ok'] ?? null;
$msg_erro = $_SESSION['site_msg_erro'] ?? null;
unset($_SESSION['site_msg_ok'], $_SESSION['site_msg_erro']);

const SITE_ABAS = ['capa', 'historia', 'programacao', 'traje', 'hospedagem', 'fotos', 'presentes', 'recados'];

function site_voltar(?string $ok = null, ?string $erro = null, string $aba = ''): void {
    if ($ok) $_SESSION['site_msg_ok'] = $ok;
    if ($erro) $_SESSION['site_msg_erro'] = $erro;
    header('Location: ' . $GLOBALS['url_editor'] . (in_array($aba, SITE_ABAS, true) ? '#' . $aba : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aba = (string)($_POST['aba'] ?? '');
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        if (!empty($_POST['foto_ajax'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => 'Sessão expirada. Recarregue a página.']);
            exit;
        }
        site_voltar(null, 'Sessão expirada. Tente novamente.', $aba);
    }

    // Fotos: enviadas/removidas na hora (AJAX), sem esperar o botão "Salvar meu site".
    if (!empty($_POST['foto_ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(site_foto_ajax($pdo, $evento_id, $site), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Botões de moderação (recados / presentes recebidos): name="mod" value="acao:id"
    if (!empty($_POST['mod'])) {
        [$acao_mod, $id_mod] = array_pad(explode(':', (string)$_POST['mod'], 2), 2, '0');
        $id_mod = (int)$id_mod;
        switch ($acao_mod) {
            case 'recado_aprovar':  $pdo->prepare("UPDATE site_recados SET aprovado = 1 WHERE id = ? AND evento_id = ?")->execute([$id_mod, $evento_id]); break;
            case 'recado_excluir':  $pdo->prepare("DELETE FROM site_recados WHERE id = ? AND evento_id = ?")->execute([$id_mod, $evento_id]); break;
            case 'contrib_recebido': $pdo->prepare("UPDATE site_contribuicoes SET recebido = 1 WHERE id = ? AND evento_id = ?")->execute([$id_mod, $evento_id]); break;
            case 'contrib_excluir': $pdo->prepare("DELETE FROM site_contribuicoes WHERE id = ? AND evento_id = ?")->execute([$id_mod, $evento_id]); break;
        }
        site_voltar(null, null, $aba);
    }

    if (($_POST['acao'] ?? '') === 'salvar') {
        $slug = strtolower(trim($_POST['slug'] ?? ''));
        if (!site_slug_valido($slug)) {
            site_voltar(null, 'O endereço do site deve ter de 3 a 60 caracteres: letras minúsculas, números e hífen.', 'capa');
        }
        if (!site_slug_disponivel($pdo, $slug, $evento_id)) {
            site_voltar(null, 'Esse endereço já está em uso por outro casal. Escolha outro.', 'capa');
        }

        $ex = site_extras($site);

        // As fotos já foram salvas na hora (site_foto_ajax); aqui só preservamos o que está no banco.
        $capa      = $site['capa_foto'];
        $hist_foto = $site['historia_foto'];
        $galeria   = site_json_lista($site['galeria']);

        // Legendas: parte das que já existem (só de fotos que ainda existem) e aplica o que veio no formulário.
        // Assim, uma página aberta antes de novas fotos serem enviadas não apaga as legendas delas ao salvar.
        $fotos_com_legenda = array_merge($galeria, site_historia_lado($site, $ex));
        $legendas_novas = [];
        foreach ($ex['legendas'] as $arq => $texto) {
            if (in_array($arq, $fotos_com_legenda, true)) $legendas_novas[$arq] = $texto;
        }
        foreach ((array)($_POST['legenda'] ?? []) as $arq => $texto) {
            if (!in_array($arq, $fotos_com_legenda, true)) continue;
            $texto = mb_substr(trim((string)$texto), 0, 120);
            if ($texto === '') unset($legendas_novas[$arq]); else $legendas_novas[$arq] = $texto;
        }
        $ex['legendas'] = $legendas_novas;

        $chaves_txt = ['contato', 'nome1', 'nome2'];
        foreach (array_keys(SITE_SECOES) as $k) { $chaves_txt[] = $k . '_titulo'; $chaves_txt[] = $k . '_desc'; }
        $ex['textos'] = [];
        foreach ($chaves_txt as $k) {
            $v = mb_substr(trim((string)($_POST['txt'][$k] ?? '')), 0, 600);
            if ($v !== '') $ex['textos'][$k] = $v;
        }
        $mostrar = array_keys((array)($_POST['mostrar'] ?? []));
        $ex['ocultas'] = array_values(array_diff(array_keys(SITE_SECOES), $mostrar));

        $programacao = site_linhas_com_foto('prog', 'prog_foto', ['titulo', 'hora', 'local', 'endereco', 'descricao'], 'titulo', $site['programacao'], $evento_id, 'loc');
        $hospedagem  = site_linhas_com_foto('hosp', 'hosp_foto', ['nome', 'endereco', 'descricao'], 'nome', $site['hospedagem'], $evento_id, 'hotel');

        // Presentes: valor por cota, nº de cotas e foto (a foto já foi enviada na hora; aqui só confirmamos o nome)
        $presentes = [];
        $fotos_antigas_pres = array_filter(array_column(site_json_lista($site['presentes']), 'foto'));
        $fotos_mantidas_pres = [];
        foreach (($_POST['presentes'] ?? []) as $p) {
            $nome = is_array($p) ? mb_substr(trim((string)($p['nome'] ?? '')), 0, 120) : '';
            if ($nome === '') continue;
            $valor = (float)str_replace(',', '.', preg_replace('/[^\d,\.]/', '', (string)($p['valor'] ?? '0')));
            $f = (string)($p['foto'] ?? '');
            $foto = (in_array($f, $fotos_antigas_pres, true) || preg_match('/^site_pres_' . $evento_id . '_[a-f0-9]+\.(jpg|jpeg|png|webp)$/', $f)) ? $f : '';
            if ($foto !== '') $fotos_mantidas_pres[] = $foto;
            $presentes[] = [
                'id' => preg_match('/^[a-f0-9]{8}$/', $p['id'] ?? '') ? $p['id'] : bin2hex(random_bytes(4)),
                'nome' => $nome,
                'valor' => max(0, round($valor, 2)),
                'cotas' => max(1, min(999, (int)($p['cotas'] ?? 1))),
                'descricao' => mb_substr(trim((string)($p['descricao'] ?? '')), 0, 200),
                'foto' => $foto,
            ];
        }
        foreach ($fotos_antigas_pres as $f) { if (!in_array($f, $fotos_mantidas_pres, true)) site_apagar_imagem($f); }

        $pdo->prepare("
            UPDATE site_convite SET slug = ?, ativo = ?, titulo = ?, local_resumo = ?, capa_foto = ?, historia = ?,
                historia_foto = ?, cor_destaque = ?, cor_fundo = ?, dress_eles = ?, dress_elas = ?, cores_evitar = ?,
                versiculo = ?, pix_chave = ?, pix_titular = ?, programacao = ?, hospedagem = ?, galeria = ?, presentes = ?,
                fonte_nomes = ?, frase_intro = ?, extras = ?
            WHERE evento_id = ?
        ")->execute([
            $slug,
            empty($_POST['ativo']) ? 0 : 1,
            mb_substr(trim($_POST['titulo'] ?? ''), 0, 120),
            mb_substr(trim($_POST['local_resumo'] ?? ''), 0, 150),
            $capa ?: null,
            mb_substr(trim($_POST['historia'] ?? ''), 0, 5000),
            $hist_foto ?: null,
            site_hex_ou_padrao($_POST['cor_destaque'] ?? null, '#3f503a'),
            site_hex_ou_padrao($_POST['cor_fundo'] ?? null, '#f7f6f2'),
            mb_substr(trim($_POST['dress_eles'] ?? ''), 0, 1000),
            mb_substr(trim($_POST['dress_elas'] ?? ''), 0, 1000),
            mb_substr(trim($_POST['cores_evitar'] ?? ''), 0, 255),
            mb_substr(trim($_POST['versiculo'] ?? ''), 0, 500),
            mb_substr(trim($_POST['pix_chave'] ?? ''), 0, 150),
            mb_substr(trim($_POST['pix_titular'] ?? ''), 0, 100),
            json_encode($programacao, JSON_UNESCAPED_UNICODE),
            json_encode($hospedagem, JSON_UNESCAPED_UNICODE),
            json_encode($galeria, JSON_UNESCAPED_UNICODE),
            json_encode($presentes, JSON_UNESCAPED_UNICODE),
            array_key_exists($_POST['fonte_nomes'] ?? '', SITE_FONTES_NOMES) ? $_POST['fonte_nomes'] : 'Great Vibes',
            mb_substr(trim($_POST['frase_intro'] ?? ''), 0, 400),
            json_encode($ex, JSON_UNESCAPED_UNICODE),
            $evento_id,
        ]);
        site_voltar('Site salvo com sucesso!', null, $aba);
    }
}

$ex = site_extras($site);
$prog = site_json_lista($site['programacao']);
$hosp = site_json_lista($site['hospedagem']);
$galeria = site_json_lista($site['galeria']);
$presentes = site_json_lista($site['presentes']);
$base = site_base_path();
$url_site = site_url_completa($site['slug']);

$stmt = $pdo->prepare("SELECT * FROM site_recados WHERE evento_id = ? ORDER BY aprovado ASC, id DESC");
$stmt->execute([$evento_id]);
$recados = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM site_contribuicoes WHERE evento_id = ? ORDER BY recebido ASC, id DESC");
$stmt->execute([$evento_id]);
$contribs = $stmt->fetchAll();
$total_recebido = array_sum(array_map(fn($c) => $c['recebido'] ? (float)$c['valor'] : 0, $contribs));
$pendentes_recados = count(array_filter($recados, fn($r) => !$r['aprovado']));
$pendentes_contribs = count(array_filter($contribs, fn($c) => !$c['recebido']));

// Indicadores de preenchimento por seção (bolinha verde no menu) e progresso geral do site.
$ok_secao = [
    'capa'        => (bool)($site['capa_foto'] || $ex['fundo'] || trim((string)$site['frase_intro']) !== ''),
    'historia'    => trim((string)$site['historia']) !== '',
    'programacao' => !empty($prog),
    'traje'       => trim((string)($site['dress_eles'] . $site['dress_elas'] . $site['cores_evitar'])) !== '',
    'hospedagem'  => !empty($hosp),
    'fotos'       => !empty($galeria),
    'presentes'   => !empty($presentes) && trim((string)$site['pix_chave']) !== '',
];
$progresso = (int)round(count(array_filter($ok_secao)) / count($ok_secao) * 100);
[$nome_padrao_1, $nome_padrao_2] = site_nomes_casal([], (string)$evento['nome_cliente'], $evento['nome_secundario'] ?? null);
$nomes_casal = trim($evento['nome_cliente'] . (!empty($evento['nome_secundario']) ? ' & ' . $evento['nome_secundario'] : ''));
$qtd_fotos = count($galeria) + count($ex['fundo']) + count($ex['faixas']) + count($ex['historia_fotos']) + count(site_historia_lado($site, $ex));

/** Bloco padrão do topo de cada aba: mostrar/ocultar a seção + título + descrição. */
function site_cabecalho_secao(string $chave, array $ex, string $padrao_titulo, string $dica_desc): void {
    $oculta = in_array($chave, $ex['ocultas'], true);
    ?>
    <div class="cab-secao">
        <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="mostrar[<?= $chave ?>]" value="1" id="mostrar_<?= $chave ?>" <?= $oculta ? '' : 'checked' ?>>
            <label class="form-check-label fw-bold" for="mostrar_<?= $chave ?>">Mostrar esta seção no site</label>
        </div>
        <div class="row g-2">
            <div class="col-md-5"><label class="form-label small fw-bold">Título da seção</label>
                <input type="text" class="form-control" name="txt[<?= $chave ?>_titulo]" maxlength="120" value="<?= site_h($ex['textos'][$chave . '_titulo'] ?? '') ?>" placeholder="<?= site_h($padrao_titulo) ?>"></div>
            <div class="col-md-7"><label class="form-label small fw-bold">Descrição (aparece abaixo do título)</label>
                <input type="text" class="form-control" name="txt[<?= $chave ?>_desc]" maxlength="600" value="<?= site_h($ex['textos'][$chave . '_desc'] ?? '') ?>" placeholder="<?= site_h($dica_desc) ?>"></div>
        </div>
    </div>
    <?php
}

/** Campo de foto única: o JS desenha a miniatura e envia/remove na hora (sem esperar o "Salvar meu site"). */
function site_campo_foto(string $rotulo, string $campo, string $dica = ''): void {
    ?>
    <div class="mb-3">
        <label class="form-label small fw-bold"><?= site_h($rotulo) ?></label>
        <div class="foto-area" data-campo="<?= $campo ?>" data-tipo="unica"></div>
        <label class="dropzone"><input type="file" class="upload-foto" data-campo="<?= $campo ?>" accept="image/jpeg,image/png,image/webp">
            <i class="bi bi-cloud-arrow-up"></i><span><strong>Escolher foto</strong><small>JPG, PNG ou WEBP</small></span></label>
        <div class="form-text"><span class="foto-status" data-status="<?= $campo ?>"></span><?= site_h($dica) ?></div>
    </div>
    <?php
}

/** Grade de fotos: envio de várias de uma vez, também salvo na hora; remover também age na hora. */
function site_campo_lista_fotos(string $rotulo, string $campo, string $dica, bool $legendas = false): void {
    ?>
    <div class="mb-3">
        <label class="form-label small fw-bold"><?= site_h($rotulo) ?></label>
        <div class="foto-area d-flex flex-wrap gap-3 mb-2" data-campo="<?= $campo ?>" data-tipo="lista" data-legendas="<?= $legendas ? 1 : 0 ?>"></div>
        <label class="dropzone"><input type="file" class="upload-foto" data-campo="<?= $campo ?>" accept="image/jpeg,image/png,image/webp" multiple>
            <i class="bi bi-images"></i><span><strong>Escolher fotos</strong><small>Você pode selecionar várias de uma vez</small></span></label>
        <div class="form-text"><span class="foto-status" data-status="<?= $campo ?>"></span><?= site_h($dica) ?></div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php include __DIR__ . '/pwa_head.inc.php'; ?>
    <title>Meu Site do Casamento - Meu Evento PRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="css/estilo.css?v=19">
    <style>
        :root { --ed-brand: #a9744f; --ed-brand-d: #8b5e3c; --ed-brand-l: #f4e9df; --ed-ink: #2b2623; --ed-muted: #7a6f67; --ed-line: #eadfd5; --ed-bg: #f7f2ed; }
        body { background: var(--ed-bg); color: var(--ed-ink); }
        .ed-top { background: linear-gradient(135deg, var(--ed-brand) 0%, var(--ed-brand-d) 100%); color: #fff; box-shadow: 0 2px 14px rgba(80,50,25,.25); }
        .ed-top .in { max-width: 1180px; margin: 0 auto; padding: .7rem 1.2rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
        .ed-top a.voltar { color: #fff; text-decoration: none; font-weight: 600; font-size: .9rem; display: inline-flex; align-items: center; gap: .4rem; padding: .4rem .9rem; border: 1px solid rgba(255,255,255,.45); border-radius: 999px; }
        .ed-top a.voltar:hover { background: rgba(255,255,255,.15); }
        .ed-top .marca { font-weight: 700; letter-spacing: .02em; display: flex; align-items: center; gap: .5rem; }
        .ed-shell { max-width: 1180px; margin: 0 auto; padding: 1.4rem 1.2rem 5.5rem; }

        /* Banner */
        .ed-hero { position: relative; overflow: hidden; border-radius: 24px; padding: 1.8rem; color: #fff; margin-bottom: 1.4rem;
            background: radial-gradient(120% 140% at 100% 0%, rgba(255,226,190,.35) 0%, transparent 55%), linear-gradient(135deg, #b88358 0%, #7f5535 100%);
            box-shadow: 0 18px 40px rgba(110,72,40,.28); }
        .ed-hero::after { content: ''; position: absolute; right: -60px; bottom: -80px; width: 280px; height: 280px; border-radius: 50%; border: 1px solid rgba(255,255,255,.25); box-shadow: 0 0 0 38px rgba(255,255,255,.05), 0 0 0 78px rgba(255,255,255,.03); pointer-events: none; }
        .ed-hero > * { position: relative; z-index: 1; }
        .ed-eyebrow { font-size: .72rem; letter-spacing: .22em; text-transform: uppercase; opacity: .85; }
        .ed-hero h1 { font-size: clamp(1.5rem, 4vw, 2.2rem); font-weight: 800; margin: .2rem 0 .5rem; letter-spacing: -.01em; }
        .ed-pill { display: inline-flex; align-items: center; gap: .4rem; padding: .28rem .8rem; border-radius: 999px; font-size: .78rem; font-weight: 700; background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.35); }
        .ed-pill i { font-size: .6rem; }
        .ed-pill.on i { color: #6ee7a0; } .ed-pill.off i { color: #fcd34d; }
        .ed-prog { margin-top: 1rem; max-width: 420px; }
        .ed-prog .barra { height: 8px; background: rgba(255,255,255,.25); border-radius: 99px; overflow: hidden; }
        .ed-prog .barra span { display: block; height: 100%; background: linear-gradient(90deg, #ffe6b8, #fff); border-radius: 99px; transition: width .6s; }
        .ed-prog small { opacity: .9; }
        .ed-link { background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.3); border-radius: 16px; padding: .9rem; margin-top: 1.2rem; backdrop-filter: blur(4px); }
        .ed-link .linha { display: flex; gap: .5rem; flex-wrap: wrap; }
        .ed-link input { flex: 1 1 260px; min-width: 0; background: rgba(255,255,255,.95); border: 0; border-radius: 10px; padding: .6rem .85rem; font-size: .9rem; color: var(--ed-ink); }
        .ed-btn { display: inline-flex; align-items: center; gap: .45rem; border: 0; border-radius: 10px; padding: .6rem 1.05rem; font-weight: 700; font-size: .88rem; text-decoration: none; cursor: pointer; transition: transform .15s, box-shadow .15s; }
        .ed-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(0,0,0,.18); }
        .ed-btn.claro { background: #fff; color: var(--ed-brand-d); }
        .ed-btn.vazado { background: transparent; color: #fff; border: 1px solid rgba(255,255,255,.6); }
        .ed-stats { display: flex; gap: .7rem; flex-wrap: wrap; margin-top: 1rem; }
        .ed-stat { background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.28); border-radius: 12px; padding: .5rem .9rem; font-size: .8rem; display: flex; align-items: center; gap: .55rem; }
        .ed-stat b { font-size: 1.15rem; }

        /* Layout: menu lateral + conteúdo */
        .ed-grid { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 1.4rem; align-items: start; }
        .ed-side { position: sticky; top: 1rem; }
        .abas { list-style: none; margin: 0; padding: .6rem; display: flex; flex-direction: column; gap: .2rem; background: #fff; border: 1px solid var(--ed-line); border-radius: 20px; box-shadow: 0 6px 22px rgba(90,60,35,.06); }
        .abas li { display: block; }
        .abas .nav-link { width: 100%; display: flex; align-items: center; gap: .7rem; padding: .7rem .8rem; border: 0; border-radius: 12px; background: transparent; color: #5b524b; font-weight: 600; font-size: .92rem; text-align: left; transition: background .15s, color .15s; }
        .abas .nav-link .ic { width: 34px; height: 34px; border-radius: 10px; background: var(--ed-brand-l); color: var(--ed-brand-d); display: inline-flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; transition: all .15s; }
        .abas .nav-link .txt { flex: 1; }
        .abas .nav-link:hover { background: #faf5f0; }
        .abas .nav-link.active { background: linear-gradient(135deg, var(--ed-brand), var(--ed-brand-d)); color: #fff; box-shadow: 0 6px 16px rgba(139,94,60,.35); }
        .abas .nav-link.active .ic { background: rgba(255,255,255,.22); color: #fff; }
        .abas .estado { width: 9px; height: 9px; border-radius: 50%; background: #e4d9ce; flex-shrink: 0; }
        .abas .estado.ok { background: #22c55e; }
        .abas .nav-link.active .estado { box-shadow: 0 0 0 2px rgba(255,255,255,.5); }
        .abas .badge { font-size: .68rem; }

        /* Cartões e cabeçalhos de seção */
        .secao { background: #fff; border: 1px solid var(--ed-line); border-radius: 22px; padding: 1.7rem; box-shadow: 0 10px 30px rgba(90,60,35,.07); }
        .sec-head { display: flex; align-items: center; gap: .9rem; padding-bottom: 1.1rem; margin-bottom: 1.3rem; border-bottom: 1px solid var(--ed-line); }
        .sec-head .icone { width: 48px; height: 48px; border-radius: 14px; background: linear-gradient(135deg, var(--ed-brand), var(--ed-brand-d)); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 1.35rem; box-shadow: 0 8px 18px rgba(139,94,60,.3); flex-shrink: 0; }
        .sec-head h2 { font-size: 1.25rem; font-weight: 800; margin: 0; }
        .sec-head p { margin: .1rem 0 0; color: var(--ed-muted); font-size: .88rem; }
        .cab-secao { background: linear-gradient(180deg, #fbf8f5, #f7f2ed); border: 1px solid var(--ed-line); border-radius: 16px; padding: 1rem; margin-bottom: 1.4rem; }
        .form-label { color: #4a413a; }
        .form-control, .form-select { border-color: #e4d9ce; border-radius: 11px; padding: .6rem .85rem; background-color: #fffdfb; }
        .form-control:focus, .form-select:focus { border-color: var(--ed-brand); box-shadow: 0 0 0 .22rem rgba(169,116,79,.18); }
        .form-check-input:checked { background-color: var(--ed-brand-d); border-color: var(--ed-brand-d); }
        .form-switch { padding-left: 3.3em; min-height: 1.6em; }
        .form-switch .form-check-input { width: 2.6em; height: 1.35em; margin-left: -3.3em; cursor: pointer; }
        .form-switch .form-check-label { cursor: pointer; }
        .form-text { color: var(--ed-muted); }
        .linha-dinamica { background: #fbf8f5; border: 1px solid var(--ed-line); border-radius: 16px; padding: 1rem; margin-bottom: .8rem; }
        .btn-add { display: inline-flex; align-items: center; gap: .45rem; padding: .55rem 1.1rem; border-radius: 12px; border: 1.5px dashed var(--ed-brand); background: #fffaf5; color: var(--ed-brand-d); font-weight: 700; font-size: .88rem; }
        .btn-add:hover { background: var(--ed-brand-l); }

        /* Fotos */
        .thumb { width: 84px; height: 84px; object-fit: cover; border-radius: 12px; border: 1px solid var(--ed-line); }
        .item-foto { width: 160px; background: #fbf8f5; border: 1px solid var(--ed-line); border-radius: 14px; padding: .5rem; }
        .item-foto .thumb { width: 100%; height: 100px; border: 0; }
        .foto-area:empty { display: none; }
        .foto-area[data-tipo="unica"] .item-foto { width: 150px; }
        .dropzone { position: relative; display: flex; align-items: center; gap: .9rem; padding: 1rem 1.2rem; border: 2px dashed #d8c4b2; border-radius: 16px; background: #fffaf5; cursor: pointer; transition: all .15s; margin: 0; }
        .dropzone:hover { border-color: var(--ed-brand); background: var(--ed-brand-l); }
        .dropzone input { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
        .dropzone i { font-size: 1.9rem; color: var(--ed-brand); }
        .dropzone span { display: flex; flex-direction: column; line-height: 1.25; }
        .dropzone small { color: var(--ed-muted); }
        .upload-linha { border-style: dashed !important; }

        /* Barra de salvar */
        .barra-salvar { position: sticky; bottom: 1rem; z-index: 20; margin-top: 1.2rem; background: rgba(255,255,255,.92); backdrop-filter: blur(10px); border: 1px solid var(--ed-line); border-radius: 18px; padding: .8rem 1rem; box-shadow: 0 12px 34px rgba(80,50,25,.2); display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .barra-salvar .dica { flex: 1 1 260px; font-size: .8rem; color: var(--ed-muted); display: flex; gap: .5rem; align-items: center; }
        .btn-salvar { border: 0; border-radius: 14px; padding: .8rem 1.8rem; font-weight: 800; color: #fff; background: linear-gradient(135deg, #2f9e5b, #1e7a44); box-shadow: 0 8px 20px rgba(30,122,68,.35); display: inline-flex; align-items: center; gap: .5rem; flex: 0 0 auto; }
        .btn-salvar:hover { filter: brightness(1.06); transform: translateY(-1px); }

        /* Janela de confirmação ao remover */
        .modal-confirma .modal-content { border: 0; border-radius: 22px; box-shadow: 0 24px 60px rgba(60,35,20,.35); }
        .confirma-icone { width: 58px; height: 58px; margin: 0 auto .9rem; border-radius: 50%; background: #fdeaea; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; }
        .confirma-mini { width: 96px; height: 72px; object-fit: cover; border-radius: 12px; margin: 0 auto .9rem; display: block; border: 1px solid var(--ed-line); }
        .confirma-mini:not([hidden]) + h5 { margin-top: 0; }

        /* Prévia em moldura de celular */
        .modal-previa .modal-content { background: transparent; border: 0; }
        .previa-bar { display: flex; justify-content: center; gap: .5rem; margin-bottom: .8rem; flex-wrap: wrap; }
        .previa-bar button { border: 1px solid rgba(255,255,255,.5); background: rgba(255,255,255,.12); color: #fff; border-radius: 999px; padding: .4rem 1rem; font-size: .85rem; font-weight: 600; }
        .previa-bar button.ativo { background: #fff; color: var(--ed-brand-d); }
        .aparelho { margin: 0 auto; background: #1b1714; border-radius: 42px; padding: 14px; box-shadow: 0 30px 70px rgba(0,0,0,.5); transition: width .3s; width: 392px; max-width: 100%; }
        .aparelho.desk { width: min(1100px, 100%); border-radius: 18px; padding: 10px; }
        .aparelho iframe { width: 100%; height: 76vh; border: 0; border-radius: 30px; background: #fff; display: block; }
        .aparelho.desk iframe { border-radius: 10px; }

        @media (max-width: 900px) {
            .ed-grid { grid-template-columns: 1fr; }
            .ed-side { position: sticky; top: 0; z-index: 30; margin: 0 -1.2rem; padding: .5rem 1.2rem; background: rgba(247,242,237,.96); backdrop-filter: blur(8px); }
            .abas { flex-direction: row; overflow-x: auto; padding: .4rem; border-radius: 16px; }
            .abas .nav-link { white-space: nowrap; width: auto; padding: .5rem .8rem; }
            .abas .nav-link .ic { width: 28px; height: 28px; font-size: .9rem; }
            .abas .estado { display: none; }
            .secao { padding: 1.2rem; }
            .ed-hero { padding: 1.3rem; }
        }
    </style>
</head>
<body>
<header class="ed-top">
  <div class="in">
    <a href="<?= site_h($url_voltar) ?>" class="voltar"><i class="bi bi-arrow-left"></i> Voltar ao painel</a>
    <span class="marca"><i class="bi bi-globe2"></i> Meu Site do Casamento</span>
  </div>
</header>

<div class="ed-shell">
    <?php if ($msg_ok): ?><div class="alert alert-success shadow-sm"><i class="bi bi-check-circle-fill me-1"></i> <?= site_h($msg_ok) ?></div><?php endif; ?>
    <?php if ($msg_erro): ?><div class="alert alert-danger shadow-sm"><i class="bi bi-exclamation-triangle-fill me-1"></i> <?= site_h($msg_erro) ?></div><?php endif; ?>

    <section class="ed-hero">
        <div class="ed-eyebrow">Convite online do casal</div>
        <h1><?= site_h($nomes_casal) ?></h1>
        <span class="ed-pill <?= $site['ativo'] ? 'on' : 'off' ?>"><i class="bi bi-circle-fill"></i> <?= $site['ativo'] ? 'Publicado — visível para os convidados' : 'Rascunho — só você enxerga' ?></span>
        <div class="ed-prog">
            <div class="d-flex justify-content-between mb-1"><small>Seu site está <strong><?= $progresso ?>%</strong> completo</small><small><?= count(array_filter($ok_secao)) ?>/<?= count($ok_secao) ?> seções</small></div>
            <div class="barra"><span style="width: <?= $progresso ?>%"></span></div>
        </div>
        <div class="ed-stats">
            <div class="ed-stat"><i class="bi bi-images"></i><span><b><?= $qtd_fotos ?></b> fotos</span></div>
            <div class="ed-stat"><i class="bi bi-chat-heart"></i><span><b><?= count($recados) ?></b> recados<?= $pendentes_recados ? ' · ' . $pendentes_recados . ' p/ aprovar' : '' ?></span></div>
            <div class="ed-stat"><i class="bi bi-gift"></i><span><b><?= count($contribs) ?></b> presentes<?= $total_recebido > 0 ? ' · R$ ' . number_format($total_recebido, 2, ',', '.') : '' ?></span></div>
        </div>
        <div class="ed-link">
            <div class="linha">
                <input type="text" id="linkSite" value="<?= site_h($url_site) ?>" readonly aria-label="Link do site">
                <button type="button" class="ed-btn claro" id="btnCopiar"><i class="bi bi-clipboard"></i> Copiar link</button>
                <button type="button" class="ed-btn claro" data-bs-toggle="modal" data-bs-target="#modalPrevia"><i class="bi bi-phone"></i> Pré-visualizar</button>
                <a class="ed-btn vazado" href="<?= site_h($url_site) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> Abrir site</a>
            </div>
        </div>
    </section>

    <form method="post" enctype="multipart/form-data" id="formSite" class="ed-grid">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        <input type="hidden" name="acao" value="salvar">
        <input type="hidden" name="aba" id="abaAtual" value="capa">

        <aside class="ed-side">
        <ul class="nav abas" id="abas" role="tablist">
<?php
$menu_abas = [
    'capa' => ['bi-stars', 'Capa e aparência'], 'historia' => ['bi-heart', 'Nossa história'], 'programacao' => ['bi-calendar-event', 'Programação'],
    'traje' => ['bi-person-standing-dress', 'Dress code'], 'hospedagem' => ['bi-building', 'Hospedagem'], 'fotos' => ['bi-images', 'Fotos'],
    'presentes' => ['bi-gift', 'Presentes'], 'recados' => ['bi-chat-heart', 'Recados'],
];
foreach ($menu_abas as $k => [$icone, $rotulo]):
    $badge = $k === 'presentes' ? $pendentes_contribs : ($k === 'recados' ? $pendentes_recados : 0);
?>
            <li><button type="button" class="nav-link<?= $k === 'capa' ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#aba-<?= $k ?>">
                <span class="ic"><i class="bi <?= $icone ?>"></i></span><span class="txt"><?= $rotulo ?></span>
                <?php if ($badge): ?><span class="badge bg-warning text-dark"><?= $badge ?></span><?php elseif (isset($ok_secao[$k])): ?><span class="estado<?= $ok_secao[$k] ? ' ok' : '' ?>" title="<?= $ok_secao[$k] ? 'Preenchido' : 'Ainda vazio' ?>"></span><?php endif; ?>
            </button></li>
<?php endforeach; ?>
        </ul>
        </aside>

        <div class="ed-main">
        <div class="tab-content">

        <!-- ================= CAPA ================= -->
        <div class="tab-pane fade show active" id="aba-capa"><div class="secao">
            <div class="sec-head"><span class="icone"><i class="bi bi-stars"></i></span><div><h2>Capa e aparência</h2><p>Nomes, cores, letra e fotos da primeira tela do site.</p></div></div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" name="ativo" value="1" id="ativo" <?= $site['ativo'] ? 'checked' : '' ?>>
                <label class="form-check-label fw-bold" for="ativo">Publicar o site (deixar visível para os convidados)</label>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small fw-bold">Endereço do site</label>
                    <div class="input-group"><span class="input-group-text small">/convite/</span>
                        <input type="text" name="slug" class="form-control" value="<?= site_h($site['slug']) ?>" required pattern="[a-z0-9]+(-[a-z0-9]+)*" minlength="3" maxlength="60"></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-bold">Nome na capa — pessoa 1</label>
                    <input type="text" name="txt[nome1]" class="form-control" maxlength="60" value="<?= site_h($ex['textos']['nome1'] ?? '') ?>" placeholder="<?= site_h($nome_padrao_1) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-bold">Nome na capa — pessoa 2</label>
                    <input type="text" name="txt[nome2]" class="form-control" maxlength="60" value="<?= site_h($ex['textos']['nome2'] ?? '') ?>" placeholder="<?= site_h($nome_padrao_2) ?>">
                    <div class="form-text">Deixe os dois em branco para usar os nomes do cadastro do casal.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-bold">Título da capa</label>
                    <input type="text" name="titulo" class="form-control" value="<?= site_h($site['titulo']) ?>" maxlength="120" placeholder="Nós vamos casar">
                </div>
                <div class="col-12">
                    <label class="form-label small fw-bold">Cidade / local resumido (aparece na capa)</label>
                    <input type="text" name="local_resumo" class="form-control" value="<?= site_h($site['local_resumo']) ?>" maxlength="150" placeholder="Ex: Boa Vista - RR">
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-bold">Estilo da letra dos nomes</label>
                    <select name="fonte_nomes" class="form-select">
                        <?php foreach (SITE_FONTES_NOMES as $valor => $rotulo): ?>
                            <option value="<?= site_h($valor) ?>" <?= ($site['fonte_nomes'] ?: 'Great Vibes') === $valor ? 'selected' : '' ?>><?= site_h($rotulo) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3"><label class="form-label small fw-bold">Cor de destaque</label>
                    <input type="color" name="cor_destaque" class="form-control form-control-color w-100" value="<?= site_h(site_hex_ou_padrao($site['cor_destaque'], '#3f503a')) ?>"></div>
                <div class="col-md-3"><label class="form-label small fw-bold">Cor de fundo</label>
                    <input type="color" name="cor_fundo" class="form-control form-control-color w-100" value="<?= site_h(site_hex_ou_padrao($site['cor_fundo'], '#f7f6f2')) ?>"></div>
                <div class="col-12">
                    <label class="form-label small fw-bold">Frase de abertura (logo abaixo da capa)</label>
                    <textarea name="frase_intro" class="form-control" rows="2" maxlength="400" placeholder="Ex: Depois de tantos caminhos compartilhados, chegou a hora de celebrar o nosso sim ao lado de quem amamos."><?= site_h($site['frase_intro']) ?></textarea>
                </div>
                <div class="col-12">
                    <?php site_campo_foto('Foto do casal na capa (fica ao lado dos nomes, em arco)', 'capa', 'Prefira foto na vertical (retrato).'); ?>
                </div>
                <div class="col-12">
                    <?php site_campo_lista_fotos('Fotos do fundo da capa (trocam sozinhas enquanto a pessoa rola a página)', 'fundo', 'Até 6 fotos, de preferência na horizontal. Se deixar vazio, o site usa as fotos da galeria.'); ?>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-bold">Contato no rodapé (e-mail ou WhatsApp)</label>
                    <input type="text" class="form-control" name="txt[contato]" maxlength="200" value="<?= site_h($ex['textos']['contato'] ?? '') ?>" placeholder="Ex: contato@casal.com ou (95) 99999-0000">
                </div>
                <div class="col-12">
                    <label class="form-label small fw-bold">Frase final (rodapé)</label>
                    <textarea name="versiculo" class="form-control" rows="2" maxlength="500" placeholder="Uma frase ou versículo para o rodapé"><?= site_h($site['versiculo']) ?></textarea>
                </div>
            </div>
        </div></div>

        <!-- ================= HISTÓRIA ================= -->
        <div class="tab-pane fade" id="aba-historia"><div class="secao">
            <div class="sec-head"><span class="icone"><i class="bi bi-heart"></i></span><div><h2>Nossa história</h2><p>Conte como tudo começou. As fotos de fundo passam em carrossel.</p></div></div>
            <?php site_cabecalho_secao('historia', $ex, 'Nossa história', 'Ex: Como tudo começou'); ?>
            <label class="form-label small fw-bold">Texto da história</label>
            <textarea name="historia" class="form-control mb-3" rows="8" maxlength="5000" placeholder="Conte como vocês se conheceram, o pedido, o que esperam do grande dia..."><?= site_h($site['historia']) ?></textarea>
            <?php site_campo_lista_fotos('Fotos de fundo da história (passam em carrossel enquanto a pessoa lê)', 'hist_fotos', 'Até 8 fotos, de preferência na horizontal. Elas ficam atrás do texto e trocam sozinhas a cada poucos segundos.'); ?>
            <?php site_campo_lista_fotos('Fotos ao lado do texto — registro da nossa história', 'historia_lado', 'Até 10 fotos do casal (primeiro encontro, viagens, o pedido...). No site elas passam em carrossel ao lado do texto; as legendas são salvas com o botão "Salvar meu site".', true); ?>
        </div></div>

        <!-- ================= PROGRAMAÇÃO ================= -->
        <div class="tab-pane fade" id="aba-programacao"><div class="secao">
            <div class="sec-head"><span class="icone"><i class="bi bi-calendar-event"></i></span><div><h2>Programação</h2><p>Cerimônia, recepção e outros momentos, com mapa e agenda.</p></div></div>
            <?php site_cabecalho_secao('programacao', $ex, 'Programação', 'Ex: Confira onde e quando vai acontecer'); ?>
            <div id="lista-prog"></div>
            <button type="button" class="btn-add" onclick="addLinha('prog')"><i class="bi bi-plus-lg"></i> Adicionar evento</button>
            <div class="form-text">Ex: Cerimônia, Recepção. O endereço gera os botões de Maps e Waze; a descrição e a foto do local são opcionais.</div>
        </div></div>

        <!-- ================= DRESS CODE ================= -->
        <div class="tab-pane fade" id="aba-traje"><div class="secao">
            <div class="sec-head"><span class="icone"><i class="bi bi-person-standing-dress"></i></span><div><h2>Dress code</h2><p>Oriente os convidados sobre o traje e as cores a evitar.</p></div></div>
            <?php site_cabecalho_secao('traje', $ex, 'Dress code', 'Ex: Pedimos a gentileza de seguir o traje abaixo'); ?>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label small fw-bold">Para eles</label><textarea name="dress_eles" class="form-control mb-2" rows="3" maxlength="1000"><?= site_h($site['dress_eles']) ?></textarea>
                    <?php site_campo_foto('Foto/ilustração (opcional)', 'traje_eles'); ?></div>
                <div class="col-md-6"><label class="form-label small fw-bold">Para elas</label><textarea name="dress_elas" class="form-control mb-2" rows="3" maxlength="1000"><?= site_h($site['dress_elas']) ?></textarea>
                    <?php site_campo_foto('Foto/ilustração (opcional)', 'traje_elas'); ?></div>
                <div class="col-12"><label class="form-label small fw-bold">Cores a evitar (separe por vírgula)</label>
                    <input type="text" name="cores_evitar" class="form-control" value="<?= site_h($site['cores_evitar']) ?>" maxlength="255" placeholder="Ex: Branco, Off-white, Azul Serenity"></div>
            </div>
        </div></div>

        <!-- ================= HOSPEDAGEM ================= -->
        <div class="tab-pane fade" id="aba-hospedagem"><div class="secao">
            <div class="sec-head"><span class="icone"><i class="bi bi-building"></i></span><div><h2>Hospedagem</h2><p>Sugestões de hotéis para quem vem de fora.</p></div></div>
            <?php site_cabecalho_secao('hospedagem', $ex, 'Hospedagem', 'Ex: Sugestões para quem vem de longe'); ?>
            <div id="lista-hosp"></div>
            <button type="button" class="btn-add" onclick="addLinha('hosp')"><i class="bi bi-plus-lg"></i> Adicionar hotel</button>
        </div></div>

        <!-- ================= FOTOS ================= -->
        <div class="tab-pane fade" id="aba-fotos"><div class="secao">
            <div class="sec-head"><span class="icone"><i class="bi bi-images"></i></span><div><h2>Fotos</h2><p>Galeria com legendas e as faixas de foto entre as seções.</p></div></div>
            <?php site_cabecalho_secao('galeria', $ex, 'Entre olhares e detalhes', 'Ex: Alguns momentos que guardamos com carinho'); ?>
            <?php site_campo_lista_fotos('Galeria (carrossel de fotos)', 'galeria', 'Até 24 fotos. Selecione várias de uma vez; as legendas são salvas com o botão "Salvar meu site".', true); ?>
            <?php site_campo_lista_fotos('Faixas de foto entre as seções (efeito de rolagem)', 'faixas', 'Até 6 fotos, de preferência na horizontal. Se deixar vazio, o site usa as fotos da galeria.'); ?>
        </div></div>

        <!-- ================= PRESENTES ================= -->
        <div class="tab-pane fade" id="aba-presentes"><div class="secao">
            <div class="sec-head"><span class="icone"><i class="bi bi-gift"></i></span><div><h2>Lista de presentes</h2><p>Itens, chave Pix e acompanhamento do que foi recebido.</p></div></div>
            <?php site_cabecalho_secao('presentes', $ex, 'Lista de presentes', 'Ex: Se quiser nos presentear, escolha uma opção abaixo'); ?>
            <div class="row g-3 mb-3">
                <div class="col-md-6"><label class="form-label small fw-bold">Chave Pix para receber</label><input type="text" name="pix_chave" class="form-control" value="<?= site_h($site['pix_chave']) ?>" maxlength="150" placeholder="CPF, e-mail, telefone ou chave aleatória"></div>
                <div class="col-md-6"><label class="form-label small fw-bold">Nome do titular do Pix</label><input type="text" name="pix_titular" class="form-control" value="<?= site_h($site['pix_titular']) ?>" maxlength="100"></div>
            </div>
            <div id="lista-presentes"></div>
            <button type="button" class="btn-add" onclick="addLinha('presentes')"><i class="bi bi-plus-lg"></i> Adicionar presente</button>
            <div class="form-text mb-4">Valor 0 = o convidado escolhe quanto quer dar. Com valor por cota, o cartão mostra "X de N cotas presenteadas" e a barra de progresso.</div>

            <h6 class="fw-bold mt-4 pt-3 border-top"><i class="bi bi-cash-coin me-1"></i> Presentes registrados <small class="text-muted fw-normal">(confirmados: R$ <?= number_format($total_recebido, 2, ',', '.') ?>)</small></h6>
            <?php if (!$contribs): ?><p class="text-muted small mb-0">Ninguém registrou presente ainda. Quando um convidado informar que fez o Pix, aparece aqui para você conferir no seu banco e marcar como recebido.</p><?php endif; ?>
            <?php foreach ($contribs as $c): ?>
                <div class="linha-dinamica"><div class="d-flex justify-content-between gap-2">
                    <div><strong><?= site_h($c['nome_convidado']) ?></strong> · <?= site_h($c['presente_nome']) ?> · <strong>R$ <?= number_format((float)$c['valor'], 2, ',', '.') ?></strong><?= (int)($c['cotas'] ?? 1) > 1 ? ' <span class="text-muted">(' . (int)$c['cotas'] . ' cotas)</span>' : '' ?>
                        <?= $c['recebido'] ? '<span class="badge bg-success">recebido</span>' : '<span class="badge bg-warning text-dark">aguardando conferência</span>' ?>
                        <?php if ($c['mensagem']): ?><div class="small text-muted"><?= site_h($c['mensagem']) ?></div><?php endif; ?></div>
                    <div class="d-flex gap-1 align-items-start flex-shrink-0">
                        <?php if (!$c['recebido']): ?><button type="submit" form="formModera" name="mod" value="contrib_recebido:<?= (int)$c['id'] ?>" class="btn btn-sm btn-success" title="Marcar como recebido"><i class="bi bi-check-lg"></i></button><?php endif; ?>
                        <button type="submit" form="formModera" name="mod" value="contrib_excluir:<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-danger" title="Excluir" data-confirma="Excluir este registro de presente? Essa ação não pode ser desfeita." data-confirma-titulo="Excluir registro"><i class="bi bi-trash"></i></button>
                    </div>
                </div></div>
            <?php endforeach; ?>
        </div></div>

        <!-- ================= RECADOS ================= -->
        <div class="tab-pane fade" id="aba-recados"><div class="secao">
            <div class="sec-head"><span class="icone"><i class="bi bi-chat-heart"></i></span><div><h2>Recados dos convidados</h2><p>Aprove as mensagens que vão aparecer no mural.</p></div></div>
            <?php site_cabecalho_secao('recados', $ex, 'Deixe seu recado', 'Ex: Sua mensagem aparece aqui depois de aprovada'); ?>
            <?php if (!$recados): ?><p class="text-muted small mb-0">Nenhum recado ainda.</p><?php endif; ?>
            <?php foreach ($recados as $r): ?>
                <div class="linha-dinamica"><div class="d-flex justify-content-between gap-2">
                    <div><strong><?= site_h($r['nome']) ?></strong> <?= $r['aprovado'] ? '<span class="badge bg-success">publicado</span>' : '<span class="badge bg-warning text-dark">pendente</span>' ?>
                        <div class="small mt-1"><?= nl2br(site_h($r['mensagem'])) ?></div></div>
                    <div class="d-flex gap-1 align-items-start flex-shrink-0">
                        <?php if (!$r['aprovado']): ?><button type="submit" form="formModera" name="mod" value="recado_aprovar:<?= (int)$r['id'] ?>" class="btn btn-sm btn-success" title="Aprovar"><i class="bi bi-check-lg"></i></button><?php endif; ?>
                        <button type="submit" form="formModera" name="mod" value="recado_excluir:<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-danger" title="Excluir" data-confirma="Excluir este recado do mural? Essa ação não pode ser desfeita." data-confirma-titulo="Excluir recado"><i class="bi bi-trash"></i></button>
                    </div>
                </div></div>
            <?php endforeach; ?>
        </div></div>

        </div><!-- /tab-content -->

        <div class="barra-salvar">
            <div class="dica"><i class="bi bi-info-circle fs-5"></i><span><strong>As fotos são salvas assim que você as escolhe.</strong> Este botão salva os textos e as configurações.</span></div>
            <button type="submit" class="btn-salvar"><i class="bi bi-check-lg"></i> Salvar meu site</button>
        </div>
        </div><!-- /ed-main -->
    </form>

    <form method="post" id="formModera">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        <input type="hidden" name="aba" id="abaModera" value="recados">
    </form>
</div>

<div class="modal fade modal-confirma" id="modalConfirma" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-body text-center p-4">
        <div class="confirma-icone"><i class="bi bi-trash3"></i></div>
        <img id="confirmaMiniatura" class="confirma-mini" alt="" hidden>
        <h5 class="fw-bold mb-1" id="confirmaTitulo">Remover</h5>
        <p class="text-muted mb-4" id="confirmaTexto" style="font-size:.92rem">Tem certeza?</p>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-light flex-fill fw-bold" id="confirmaNao" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-danger flex-fill fw-bold" id="confirmaSim"><i class="bi bi-trash3 me-1"></i> Remover</button>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade modal-previa" id="modalPrevia" tabindex="-1" aria-label="Pré-visualização do site">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="previa-bar">
        <button type="button" class="ativo" data-modo="cel"><i class="bi bi-phone"></i> Celular</button>
        <button type="button" data-modo="desk"><i class="bi bi-laptop"></i> Computador</button>
        <button type="button" id="previaRecarregar"><i class="bi bi-arrow-clockwise"></i> Atualizar</button>
        <button type="button" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Fechar</button>
      </div>
      <div class="aparelho" id="aparelho"><iframe id="previaFrame" title="Prévia do site" src="about:blank"></iframe></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const base = <?= json_encode($base) ?>;
const csrf = <?= json_encode($csrf_token) ?>;
const urlEditor = <?= json_encode($url_editor) ?>;
const fotos = {
    capa: <?= json_encode($site['capa_foto'] ? [$site['capa_foto']] : []) ?>,
    historia_lado: <?= json_encode(site_historia_lado($site, $ex)) ?>,
    traje_eles: <?= json_encode($ex['traje_eles_foto'] ? [$ex['traje_eles_foto']] : []) ?>,
    traje_elas: <?= json_encode($ex['traje_elas_foto'] ? [$ex['traje_elas_foto']] : []) ?>,
    galeria: <?= json_encode($galeria) ?>, fundo: <?= json_encode($ex['fundo']) ?>,
    faixas: <?= json_encode($ex['faixas']) ?>, hist_fotos: <?= json_encode($ex['historia_fotos']) ?>
};
const legendas = <?= json_encode((object)$ex['legendas'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const dados = {
    prog: <?= json_encode($prog, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    hosp: <?= json_encode($hosp, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    presentes: <?= json_encode($presentes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>
};
const campos = {
    prog: [['titulo', 'Evento (ex: Cerimônia)', 'col-md-5'], ['hora', 'Horário (ex: 16:30)', 'col-md-3'], ['local', 'Nome do local', 'col-md-4'], ['endereco', 'Endereço completo (para o mapa)', 'col-12'], ['descricao', 'Descrição (opcional)', 'col-12']],
    hosp: [['nome', 'Nome do hotel', 'col-md-5'], ['endereco', 'Endereço', 'col-md-7'], ['descricao', 'Descrição (ex: a 5 min do local, café da manhã incluso)', 'col-12']],
    presentes: [['nome', 'Tema do presente (ex: Lua de mel)', 'col-md-6'], ['valor', 'Valor de cada cota R$ (0 = valor livre)', 'col-md-3'], ['cotas', 'Nº de cotas', 'col-md-3'], ['descricao', 'Descrição (opcional)', 'col-12']]
};
const comFoto = { prog: 'prog_foto', hosp: 'hosp_foto', presentes: 'presentes_foto' };
const esc = v => String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
let contador = 0;
function addLinha(tipo, valores) {
    valores = valores || {};
    const i = contador++;
    const div = document.createElement('div');
    div.className = 'linha-dinamica';
    let html = '<div class="row g-2">';
    campos[tipo].forEach(([c, ph, col]) => {
        html += '<div class="' + col + '"><input type="text" class="form-control form-control-sm" name="' + tipo + '[' + i + '][' + c + ']" placeholder="' + esc(ph) + '" value="' + esc(c === 'cotas' && !valores[c] ? 1 : valores[c]) + '"' + (c === 'cotas' ? ' inputmode="numeric"' : '') + ' maxlength="' + (c === 'descricao' ? 400 : 200) + '"></div>';
    });
    if (tipo === 'presentes') html += '<input type="hidden" name="presentes[' + i + '][id]" value="' + esc(valores.id) + '">';
    if (comFoto[tipo]) {
        html += '<div class="col-12"><div class="d-flex align-items-center gap-2"><div class="linha-foto"></div>'
              + '<div class="flex-grow-1"><label class="small text-muted">' + (tipo === 'presentes' ? 'Imagem do presente (aparece no topo do cartão)' : 'Foto (opcional)') + ' — salva assim que você escolhe</label>'
              + '<input type="file" class="form-control form-control-sm upload-linha" accept="image/jpeg,image/png,image/webp">'
              + '<div class="small linha-status"></div>'
              + '<input type="hidden" class="linha-foto-val" name="' + tipo + '[' + i + '][foto]" value="' + esc(valores.foto) + '"></div></div></div>';
    }
    html += '</div><button type="button" class="btn btn-sm btn-link text-danger p-0 mt-1" onclick="removerLinha(this)"><i class="bi bi-trash"></i> remover</button>';
    div.innerHTML = html;
    document.getElementById('lista-' + tipo).appendChild(div);
    if (comFoto[tipo]) ligaFotoLinha(div, tipo, valores._idx === undefined ? -1 : valores._idx);
}
Object.keys(dados).forEach(t => dados[t].forEach((v, k) => { v._idx = k; addLinha(t, v); }));

/* ---------- Fotos: envio e remoção na hora ---------- */
function mostraStatus(campo, texto, erro) {
    const el = document.querySelector('[data-status="' + campo + '"]');
    if (!el) return;
    el.textContent = texto ? texto + ' ' : '';
    el.className = 'foto-status ' + (erro ? 'text-danger fw-bold' : 'text-success fw-bold');
    if (texto && !erro) setTimeout(() => { if (el.textContent === texto + ' ') el.textContent = ''; }, 3500);
}
function pedido(dadosForm) {
    dadosForm.append('csrf_token', csrf); dadosForm.append('foto_ajax', '1');
    return fetch(urlEditor, { method: 'POST', body: dadosForm, headers: { 'X-Requested-With': 'fetch' } })
        .then(r => r.json()).catch(() => ({ ok: false, msg: 'Falha de conexão. Tente de novo.' }));
}
function desenhaFotos(campo) {
    const area = document.querySelector('.foto-area[data-campo="' + campo + '"]');
    if (!area) return;
    const comLegenda = area.dataset.legendas === '1';
    // preserva legendas digitadas e ainda não salvas
    area.querySelectorAll('input[data-leg]').forEach(i => { legendas[i.dataset.leg] = i.value; });
    area.innerHTML = '';
    fotos[campo].forEach(arq => {
        const item = document.createElement('div');
        item.className = 'item-foto';
        item.innerHTML = '<img src="' + esc(base) + '/uploads/' + esc(arq) + '" class="thumb d-block mb-1" alt="">'
            + (comLegenda ? '<input type="text" class="form-control form-control-sm mb-1" name="legenda[' + esc(arq) + ']" data-leg="' + esc(arq) + '" maxlength="120" placeholder="Legenda" value="' + esc(legendas[arq] || '') + '">' : '')
            + '<button type="button" class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-trash"></i> remover</button>';
        item.querySelector('button').onclick = () => removeFoto(campo, arq);
        area.appendChild(item);
    });
}
function removeFoto(campo, arq) {
    confirmar({ titulo: 'Remover foto', texto: 'Tem certeza que deseja remover esta foto? Ela será apagada do site.', miniatura: base + '/uploads/' + arq }).then(sim => {
        if (!sim) return;
        const f = new FormData(); f.append('campo', campo); f.append('op', 'remove'); f.append('arquivo', arq);
        mostraStatus(campo, 'Removendo...');
        pedido(f).then(r => {
            if (!r.ok) return mostraStatus(campo, r.msg, true);
            fotos[campo] = r.fotos; desenhaFotos(campo); mostraStatus(campo, 'Foto removida ✓');
        });
    });
}
document.querySelectorAll('.upload-foto').forEach(inp => {
    inp.addEventListener('change', () => {
        if (!inp.files.length) return;
        const campo = inp.dataset.campo, f = new FormData();
        f.append('campo', campo); f.append('op', 'add');
        [...inp.files].forEach(a => f.append('arq[]', a));
        mostraStatus(campo, 'Enviando...');
        pedido(f).then(r => {
            inp.value = '';
            if (!r.ok) return mostraStatus(campo, r.msg, true);
            fotos[campo] = r.fotos; desenhaFotos(campo); mostraStatus(campo, 'Foto salva ✓');
        });
    });
});
Object.keys(fotos).forEach(desenhaFotos);

function ligaFotoLinha(div, tipo, idx) {
    const val = div.querySelector('.linha-foto-val'), caixa = div.querySelector('.linha-foto'), st = div.querySelector('.linha-status'), inp = div.querySelector('.upload-linha');
    function desenha() {
        caixa.innerHTML = val.value ? '<img src="' + esc(base) + '/uploads/' + esc(val.value) + '" class="thumb" style="width:56px;height:56px" alt=""><button type="button" class="btn btn-sm btn-link text-danger p-0 d-block">remover foto</button>' : '';
        const b = caixa.querySelector('button');
        if (b) b.onclick = () => confirmar({ titulo: 'Remover foto', texto: 'Tem certeza que deseja remover esta foto?', miniatura: base + '/uploads/' + val.value }).then(sim => {
            if (!sim) return;
            const f = new FormData(); f.append('campo', tipo); f.append('op', 'remove'); f.append('idx', idx); f.append('arquivo', val.value);
            pedido(f).then(r => { if (r.ok) { val.value = ''; desenha(); st.textContent = 'Foto removida ✓'; st.className = 'small linha-status text-success'; } else { st.textContent = r.msg; st.className = 'small linha-status text-danger'; } });
        });
    }
    desenha();
    inp.addEventListener('change', () => {
        if (!inp.files.length) return;
        const f = new FormData(); f.append('campo', tipo); f.append('op', 'add'); f.append('idx', idx); f.append('arq', inp.files[0]);
        st.textContent = 'Enviando...'; st.className = 'small linha-status text-muted';
        pedido(f).then(r => {
            inp.value = '';
            if (!r.ok) { st.textContent = r.msg; st.className = 'small linha-status text-danger'; return; }
            val.value = r.foto; desenha();
            st.textContent = r.gravada ? 'Foto salva ✓' : 'Foto enviada ✓ — ela fica no evento ao clicar em "Salvar meu site" (o evento é novo)';
            st.className = 'small linha-status text-success';
        });
    });
}

/* ---------- Confirmação (modal) antes de qualquer remoção ---------- */
const modalConfirmaEl = document.getElementById('modalConfirma');
const modalConfirma = new bootstrap.Modal(modalConfirmaEl);
function confirmar({ titulo = 'Remover', texto = 'Tem certeza?', botao = 'Remover', miniatura = '' } = {}) {
    return new Promise(resolve => {
        document.getElementById('confirmaTitulo').textContent = titulo;
        document.getElementById('confirmaTexto').textContent = texto;
        const mini = document.getElementById('confirmaMiniatura');
        mini.hidden = !miniatura; if (miniatura) mini.src = miniatura;
        const sim = document.getElementById('confirmaSim');
        sim.innerHTML = '<i class="bi bi-trash3 me-1"></i> ' + botao;
        let decidiu = false;
        const fim = valor => { if (decidiu) return; decidiu = true; sim.removeEventListener('click', aoSim); modalConfirmaEl.removeEventListener('hidden.bs.modal', aoFechar); resolve(valor); };
        const aoSim = () => { fim(true); modalConfirma.hide(); };
        const aoFechar = () => fim(false);
        sim.addEventListener('click', aoSim);
        modalConfirmaEl.addEventListener('hidden.bs.modal', aoFechar);
        modalConfirma.show();
    });
}
function removerLinha(botao) {
    confirmar({ titulo: 'Remover item', texto: 'Tem certeza que deseja remover este item? Ele só some do site depois que você clicar em "Salvar meu site".' })
        .then(sim => { if (sim) botao.parentNode.remove(); });
}
// Botões de excluir recado/presente registrado (enviam o formulário de moderação)
document.addEventListener('click', function (e) {
    const b = e.target.closest('[data-confirma]');
    if (!b || b.dataset.confirmado) return;
    e.preventDefault();
    confirmar({ titulo: b.dataset.confirmaTitulo || 'Excluir', texto: b.dataset.confirma, botao: 'Excluir' }).then(sim => {
        if (!sim) return;
        b.dataset.confirmado = '1';
        document.getElementById(b.getAttribute('form')).requestSubmit(b);
    });
});

// Copiar link + pré-visualização (a página aberta carrega o site publicado ou o rascunho, que o dono enxerga)
document.getElementById('btnCopiar').addEventListener('click', function () {
    const campo = document.getElementById('linkSite');
    (navigator.clipboard ? navigator.clipboard.writeText(campo.value) : Promise.reject()).catch(() => { campo.select(); document.execCommand('copy'); });
    const original = this.innerHTML; this.innerHTML = '<i class="bi bi-check2"></i> Copiado!';
    setTimeout(() => { this.innerHTML = original; }, 1800);
});
(function () {
    const modal = document.getElementById('modalPrevia'), frame = document.getElementById('previaFrame'), aparelho = document.getElementById('aparelho');
    const carrega = () => { frame.src = <?= json_encode($url_site) ?> + '?_previa=' + Date.now(); };
    modal.addEventListener('show.bs.modal', carrega);
    modal.addEventListener('hidden.bs.modal', () => { frame.src = 'about:blank'; });
    document.getElementById('previaRecarregar').addEventListener('click', carrega);
    modal.querySelectorAll('[data-modo]').forEach(b => b.addEventListener('click', () => {
        modal.querySelectorAll('[data-modo]').forEach(x => x.classList.toggle('ativo', x === b));
        aparelho.classList.toggle('desk', b.dataset.modo === 'desk');
    }));
})();

// Lembra a aba aberta: abre pela #âncora e a repassa ao salvar/moderar.
(function () {
    const abas = ['capa', 'historia', 'programacao', 'traje', 'hospedagem', 'fotos', 'presentes', 'recados'];
    const hash = location.hash.slice(1);
    if (abas.includes(hash)) {
        const botao = document.querySelector('[data-bs-target="#aba-' + hash + '"]');
        if (botao) new bootstrap.Tab(botao).show();
    }
    document.querySelectorAll('#abas [data-bs-toggle="tab"]').forEach(function (b) {
        b.addEventListener('shown.bs.tab', function () {
            const nome = b.dataset.bsTarget.replace('#aba-', '');
            document.getElementById('abaAtual').value = nome;
            document.getElementById('abaModera').value = nome;
            history.replaceState(null, '', '#' + nome);
        });
    });
    const inicial = abas.includes(hash) ? hash : 'capa';
    document.getElementById('abaAtual').value = inicial;
    document.getElementById('abaModera').value = inicial;
})();
</script>
</body>
</html>
