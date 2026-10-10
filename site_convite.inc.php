<?php
// Site/convite online do casal (página pública em /convite/{slug}), editado
// pelos noivos em site_editar.php. Tudo que é lista curta (programação,
// hospedagem, galeria, presentes) fica em colunas JSON dentro de site_convite;
// recados e contribuições de presente têm tabela própria porque os convidados
// escrevem nelas sem login e os noivos precisam moderar linha a linha.

function site_garantir_schema(PDO $pdo): void {
    site_garantir_schema_v1($pdo);
    // v2: estilo da letra dos nomes e frase de abertura (visual inspirado em sites de casamento autorais)
    if (!schema_ja_verificado('site_convite_v2')) {
        try { $pdo->query("SELECT fonte_nomes FROM site_convite LIMIT 1"); }
        catch (Exception $e) { $pdo->exec("ALTER TABLE site_convite ADD COLUMN fonte_nomes VARCHAR(20) NULL"); }
        try { $pdo->query("SELECT frase_intro FROM site_convite LIMIT 1"); }
        catch (Exception $e) { $pdo->exec("ALTER TABLE site_convite ADD COLUMN frase_intro VARCHAR(400) NULL"); }
        marcar_schema_verificado('site_convite_v2');
    }
    // v3: textos extras, seções ocultas, fotos de fundo/faixas/traje e legendas — tudo em um JSON só
    if (!schema_ja_verificado('site_convite_v3')) {
        try { $pdo->query("SELECT extras FROM site_convite LIMIT 1"); }
        catch (Exception $e) { $pdo->exec("ALTER TABLE site_convite ADD COLUMN extras LONGTEXT NULL"); }
        marcar_schema_verificado('site_convite_v3');
    }
    // v4: contribuições passam a registrar quantas cotas o convidado escolheu
    if (!schema_ja_verificado('site_convite_v4')) {
        try { $pdo->query("SELECT cotas FROM site_contribuicoes LIMIT 1"); }
        catch (Exception $e) { $pdo->exec("ALTER TABLE site_contribuicoes ADD COLUMN cotas INT NOT NULL DEFAULT 1"); }
        marcar_schema_verificado('site_convite_v4');
    }
    // v5: o site é um item contratado à parte. Cada evento tem um status de acesso que a Central libera depois do pagamento.
    if (!schema_ja_verificado('site_convite_v5')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS site_convite_acesso (
                evento_id     INT          NOT NULL PRIMARY KEY,
                status        VARCHAR(12)  NOT NULL DEFAULT 'solicitado',
                preco         DECIMAL(10,2) NULL,
                solicitado_em DATETIME     NULL,
                solicitado_por VARCHAR(120) NULL,
                liberado_em   DATETIME     NULL,
                liberado_por  VARCHAR(120) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        // Quem já tinha site antes desta regra continua com acesso (não trancamos ninguém por uma mudança de regra).
        $pdo->exec("INSERT IGNORE INTO site_convite_acesso (evento_id, status, liberado_em, liberado_por) SELECT evento_id, 'liberado', NOW(), 'migracao' FROM site_convite");
        marcar_schema_verificado('site_convite_v5');
    }
}

function site_garantir_schema_v1(PDO $pdo): void {
    if (schema_ja_verificado('site_convite_v1')) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS site_convite (
            evento_id      INT          NOT NULL PRIMARY KEY,
            slug           VARCHAR(80)  NOT NULL,
            ativo          TINYINT(1)   NOT NULL DEFAULT 0,
            titulo         VARCHAR(120) NULL,
            local_resumo   VARCHAR(150) NULL,
            capa_foto      VARCHAR(255) NULL,
            historia       TEXT         NULL,
            historia_foto  VARCHAR(255) NULL,
            cor_destaque   VARCHAR(7)   NULL,
            cor_fundo      VARCHAR(7)   NULL,
            dress_eles     TEXT         NULL,
            dress_elas     TEXT         NULL,
            cores_evitar   VARCHAR(255) NULL,
            versiculo      VARCHAR(500) NULL,
            pix_chave      VARCHAR(150) NULL,
            pix_titular    VARCHAR(100) NULL,
            programacao    LONGTEXT     NULL,
            hospedagem     LONGTEXT     NULL,
            galeria        LONGTEXT     NULL,
            presentes      LONGTEXT     NULL,
            atualizado_em  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_site_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS site_recados (
            id        INT AUTO_INCREMENT PRIMARY KEY,
            evento_id INT          NOT NULL,
            nome      VARCHAR(100) NOT NULL,
            mensagem  VARCHAR(600) NOT NULL,
            aprovado  TINYINT(1)   NOT NULL DEFAULT 0,
            criado_em TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_recado_evento (evento_id, aprovado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS site_contribuicoes (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            evento_id      INT           NOT NULL,
            presente_id    VARCHAR(20)   NOT NULL,
            presente_nome  VARCHAR(150)  NOT NULL,
            valor          DECIMAL(10,2) NOT NULL,
            nome_convidado VARCHAR(100)  NOT NULL,
            mensagem       VARCHAR(300)  NULL,
            recebido       TINYINT(1)    NOT NULL DEFAULT 0,
            criado_em      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_contrib_evento (evento_id, presente_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    marcar_schema_verificado('site_convite_v1');
}

function site_slugify(string $texto): string {
    $texto = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto) ?: $texto;
    $texto = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $texto));
    return trim(substr($texto, 0, 60), '-');
}

function site_slug_valido(string $slug): bool {
    return (bool)preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) && strlen($slug) >= 3 && strlen($slug) <= 60;
}

function site_slug_disponivel(PDO $pdo, string $slug, int $evento_id): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM site_convite WHERE slug = ? AND evento_id <> ?");
    $stmt->execute([$slug, $evento_id]);
    return !$stmt->fetchColumn();
}

function site_slug_unico(PDO $pdo, string $base, int $evento_id): string {
    $base = site_slugify($base);
    if (strlen($base) < 3) $base = 'casamento-' . $evento_id;
    $slug = $base;
    for ($i = 2; !site_slug_disponivel($pdo, $slug, $evento_id); $i++) {
        $slug = $base . '-' . $i;
    }
    return $slug;
}

/** Caminho base do app (ex: "" na raiz ou "/sis") — as URLs do site público
 *  são /convite/slug, então caminhos relativos quebrariam; sempre usar este prefixo. */
function site_base_path(): string {
    return rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
}

function site_url_completa(string $slug): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    return ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . site_base_path() . '/convite/' . $slug;
}

function site_json_lista(?string $json): array {
    $dados = $json ? json_decode($json, true) : [];
    return is_array($dados) ? array_values($dados) : [];
}

/** Salva uma imagem enviada em uploads/ e devolve o nome do arquivo, ou null se inválida. */
function site_salvar_imagem(array $arquivo, int $evento_id, string $prefixo): ?string {
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || @getimagesize($arquivo['tmp_name']) === false) return null;
    $nome = "site_{$prefixo}_{$evento_id}_" . bin2hex(random_bytes(5)) . '.' . $ext;
    return move_uploaded_file($arquivo['tmp_name'], __DIR__ . '/uploads/' . $nome) ? $nome : null;
}

function site_apagar_imagem(?string $nome): void {
    if (!$nome || !preg_match('/^site_[a-z]+_\d+_[a-f0-9]+\.(jpg|jpeg|png|webp)$/', $nome)) return;
    $caminho = __DIR__ . '/uploads/' . $nome;
    if (is_file($caminho)) @unlink($caminho);
}

function site_hex_ou_padrao(?string $cor, string $padrao): string {
    return ($cor && preg_match('/^#[0-9a-fA-F]{6}$/', $cor)) ? $cor : $padrao;
}

function site_h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

const SITE_FONTES_NOMES = ['Great Vibes' => 'Great Vibes (clássica)', 'Allura' => 'Allura (delicada)', 'Parisienne' => 'Parisienne (romântica)'];

/** Cor de bolinha para as "cores a evitar" mais comuns; desconhecidas ficam neutras. */
function site_cor_do_nome(string $nome): string {
    $mapa = ['serenity' => '#a7c6e2', 'azul' => '#6f9bd1', 'cinza' => '#aaa8a5', 'branco' => '#fffdf8', 'off' => '#f3ede0',
             'creme' => '#f1e6cf', 'preto' => '#2b2b2b', 'vermelho' => '#b3262d', 'vinho' => '#6d1a2b', 'verde' => '#5f8f5a',
             'rosa' => '#e8a9bd', 'amarelo' => '#ecd35b', 'dourado' => '#c9a44c', 'prata' => '#c0c0c0', 'bege' => '#d9c3a3',
             'lilás' => '#b9a4d6', 'lilas' => '#b9a4d6', 'roxo' => '#6e4a9b', 'laranja' => '#e58a3c', 'marrom' => '#7b5538', 'nude' => '#e3c4b0'];
    $n = mb_strtolower($nome);
    foreach ($mapa as $chave => $hex) { if (str_contains($n, $chave)) return $hex; }
    return '#d6cec4';
}

/** Seções que o casal pode mostrar/ocultar no site (chave => rótulo). */
const SITE_SECOES = [
    'historia' => 'Nossa história', 'programacao' => 'Programação', 'traje' => 'Dress code',
    'hospedagem' => 'Hospedagem', 'galeria' => 'Galeria de fotos', 'presentes' => 'Lista de presentes', 'recados' => 'Recados',
];

/** Extras do site (textos, seções ocultas, fotos soltas, legendas) já com valores padrão. */
function site_extras(array $site): array {
    $e = json_decode((string)($site['extras'] ?? ''), true);
    $e = is_array($e) ? $e : [];
    return [
        'textos'   => is_array($e['textos'] ?? null) ? $e['textos'] : [],
        'ocultas'  => is_array($e['ocultas'] ?? null) ? $e['ocultas'] : [],
        'fundo'    => is_array($e['fundo'] ?? null) ? array_values($e['fundo']) : [],
        'faixas'   => is_array($e['faixas'] ?? null) ? array_values($e['faixas']) : [],
        'historia_fotos' => is_array($e['historia_fotos'] ?? null) ? array_values($e['historia_fotos']) : [],
        'historia_lado' => is_array($e['historia_lado'] ?? null) ? array_values($e['historia_lado']) : [],
        'legendas' => is_array($e['legendas'] ?? null) ? $e['legendas'] : [],
        'traje_eles_foto' => (string)($e['traje_eles_foto'] ?? ''),
        'traje_elas_foto' => (string)($e['traje_elas_foto'] ?? ''),
    ];
}

/** Texto personalizado de uma seção, ou o padrão quando o casal deixou em branco. */
function site_texto(array $extras, string $chave, string $padrao = ''): string {
    $v = trim((string)($extras['textos'][$chave] ?? ''));
    return $v !== '' ? $v : $padrao;
}

/** Uma foto única (capa, traje...): remove se marcado, troca se veio arquivo novo. Devolve o nome atual (ou ''). */
function site_foto_unica(?string $atual, string $campo_arquivo, string $campo_remover, int $evento_id, string $prefixo): string {
    $atual = (string)$atual;
    if (!empty($_POST[$campo_remover])) { site_apagar_imagem($atual); $atual = ''; }
    if ($novo = site_salvar_imagem($_FILES[$campo_arquivo] ?? [], $evento_id, $prefixo)) { site_apagar_imagem($atual); $atual = $novo; }
    return $atual;
}

/** Lista de fotos (galeria, fundo, faixas): tira as marcadas e acrescenta as enviadas (limite $max). */
function site_atualizar_lista(array $atual, string $campo_remover, string $campo_novas, int $evento_id, string $prefixo, int $max): array {
    $remover = (array)($_POST[$campo_remover] ?? []);
    $saida = [];
    foreach ($atual as $arq) {
        if (in_array($arq, $remover, true)) site_apagar_imagem($arq); else $saida[] = $arq;
    }
    if (!empty($_FILES[$campo_novas]['name'][0])) {
        foreach ($_FILES[$campo_novas]['name'] as $i => $_) {
            if (count($saida) >= $max) break;
            $arq = site_salvar_imagem([
                'name' => $_FILES[$campo_novas]['name'][$i],
                'tmp_name' => $_FILES[$campo_novas]['tmp_name'][$i],
                'error' => $_FILES[$campo_novas]['error'][$i],
            ], $evento_id, $prefixo);
            if ($arq) $saida[] = $arq;
        }
    }
    return $saida;
}

/**
 * Linhas dinâmicas do formulário que podem ter foto própria (programação, hospedagem).
 * Mantém a foto anterior (campo oculto) quando nada novo foi enviado e apaga as que sumiram.
 */
function site_linhas_com_foto(string $campo, string $campo_foto, array $chaves, string $obrigatoria, ?string $json_antigo, int $evento_id, string $prefixo): array {
    $antigas = array_filter(array_column(site_json_lista($json_antigo), 'foto'));
    $mantidas = [];
    $saida = [];
    foreach (($_POST[$campo] ?? []) as $i => $linha) {
        if (!is_array($linha) || trim((string)($linha[$obrigatoria] ?? '')) === '') continue;
        $item = [];
        foreach ($chaves as $c) $item[$c] = mb_substr(trim((string)($linha[$c] ?? '')), 0, $c === 'descricao' ? 400 : 200);
        // aceita a foto já gravada OU uma enviada na hora (ainda não gravada junto da linha)
        $f = (string)($linha['foto'] ?? '');
        $foto = (in_array($f, $antigas, true) || preg_match('/^site_' . preg_quote($prefixo, '/') . '_' . $evento_id . '_[a-f0-9]+\.(jpg|jpeg|png|webp)$/', $f)) ? $f : '';
        if (isset($_FILES[$campo_foto]['name'][$i]) && ($nova = site_salvar_imagem([
            'name' => $_FILES[$campo_foto]['name'][$i], 'tmp_name' => $_FILES[$campo_foto]['tmp_name'][$i], 'error' => $_FILES[$campo_foto]['error'][$i],
        ], $evento_id, $prefixo))) { $foto = $nova; }
        $item['foto'] = $foto;
        if ($foto !== '') $mantidas[] = $foto;
        $saida[] = $item;
    }
    foreach ($antigas as $f) { if (!in_array($f, $mantidas, true)) site_apagar_imagem($f); }
    return array_slice($saida, 0, 30);
}

/**
 * Envio/remoção de fotos na hora (chamado por site_editar.php via fetch).
 * Campos: capa, historia, traje_eles, traje_elas (foto única); galeria, fundo, faixas,
 * hist_fotos (listas); prog, hosp (foto de uma linha, por posição já gravada).
 * Devolve sempre o estado atual do campo para a tela se redesenhar.
 */
function site_foto_ajax(PDO $pdo, int $evento_id, array $site): array {
    $campo = (string)($_POST['campo'] ?? '');
    $op = (string)($_POST['op'] ?? '');
    $ex = site_extras($site);

    $unicas = [  // campo => [onde guarda, prefixo do arquivo]
        'capa' => ['col:capa_foto', 'capa'], 'historia' => ['col:historia_foto', 'hist'],
        'traje_eles' => ['ex:traje_eles_foto', 'teles'], 'traje_elas' => ['ex:traje_elas_foto', 'telas'],
    ];
    $listas = [  // campo => [onde guarda, prefixo, máximo]
        'galeria' => ['col:galeria', 'gal', 24], 'fundo' => ['ex:fundo', 'fundo', 6],
        'faixas' => ['ex:faixas', 'faixa', 6], 'hist_fotos' => ['ex:historia_fotos', 'hfundo', 8],
        'historia_lado' => ['ex:historia_lado', 'hlado', 10],
    ];
    $salvar = function (string $onde, $valor) use ($pdo, $evento_id, &$ex): void {
        [$tipo, $nome] = explode(':', $onde);
        if ($tipo === 'col') {
            $pdo->prepare("UPDATE site_convite SET $nome = ? WHERE evento_id = ?")
                ->execute([is_array($valor) ? json_encode(array_values($valor), JSON_UNESCAPED_UNICODE) : ($valor !== '' ? $valor : null), $evento_id]);
        } else {
            $ex[$nome] = $valor;
            $pdo->prepare("UPDATE site_convite SET extras = ? WHERE evento_id = ?")->execute([json_encode($ex, JSON_UNESCAPED_UNICODE), $evento_id]);
        }
    };
    $ler = function (string $onde) use ($site, $ex) {
        [$tipo, $nome] = explode(':', $onde);
        if ($nome === 'historia_lado') return site_historia_lado($site, $ex);   // inclui a antiga foto única, se houver
        return $tipo === 'col' ? ($nome === 'galeria' ? site_json_lista($site['galeria']) : (string)$site[$nome]) : $ex[$nome];
    };
    // arquivos enviados, normalizados (aceita um ou vários)
    $arquivos = [];
    if (!empty($_FILES['arq'])) {
        $f = $_FILES['arq'];
        if (is_array($f['name'])) { foreach ($f['name'] as $i => $_) $arquivos[] = ['name' => $f['name'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i]]; }
        else $arquivos[] = $f;
    }

    if (isset($unicas[$campo])) {
        [$onde, $pref] = $unicas[$campo];
        $atual = (string)$ler($onde);
        if ($op === 'add') {
            $novo = $arquivos ? site_salvar_imagem($arquivos[0], $evento_id, $pref) : null;
            if (!$novo) return ['ok' => false, 'msg' => 'Envie uma imagem JPG, PNG ou WEBP válida (veja também o limite de tamanho do servidor).'];
            site_apagar_imagem($atual); $atual = $novo;
        } elseif ($op === 'remove') { site_apagar_imagem($atual); $atual = ''; }
        else return ['ok' => false, 'msg' => 'Operação inválida.'];
        $salvar($onde, $atual);
        return ['ok' => true, 'fotos' => $atual !== '' ? [$atual] : []];
    }

    if (isset($listas[$campo])) {
        [$onde, $pref, $max] = $listas[$campo];
        $lista = (array)$ler($onde);
        if ($op === 'add') {
            $enviadas = 0;
            foreach ($arquivos as $a) {
                if (count($lista) >= $max) break;
                if ($novo = site_salvar_imagem($a, $evento_id, $pref)) { $lista[] = $novo; $enviadas++; }
            }
            if (!$enviadas) return ['ok' => false, 'msg' => count($lista) >= $max ? "Limite de $max fotos atingido." : 'Nenhuma imagem válida (JPG, PNG ou WEBP).'];
        } elseif ($op === 'remove') {
            $alvo = (string)($_POST['arquivo'] ?? '');
            if (in_array($alvo, $lista, true)) { site_apagar_imagem($alvo); $lista = array_values(array_diff($lista, [$alvo])); }
            if ($campo === 'galeria') unset($ex['legendas'][$alvo]);
        } else return ['ok' => false, 'msg' => 'Operação inválida.'];
        $salvar($onde, $lista);
        if ($campo === 'historia_lado') $pdo->prepare("UPDATE site_convite SET historia_foto = NULL WHERE evento_id = ?")->execute([$evento_id]);   // a foto única antiga passou para a lista
        if ($campo === 'galeria' && $op === 'remove') $salvar('ex:legendas', $ex['legendas']);
        return ['ok' => true, 'fotos' => array_values($lista)];
    }

    if ($campo === 'prog' || $campo === 'hosp' || $campo === 'presentes') {   // foto de uma linha (programação / hospedagem / presente)
        $coluna = ['prog' => 'programacao', 'hosp' => 'hospedagem', 'presentes' => 'presentes'][$campo];
        $pref = ['prog' => 'loc', 'hosp' => 'hotel', 'presentes' => 'pres'][$campo];
        $idx = (int)($_POST['idx'] ?? -1);
        $linhas = site_json_lista($site[$coluna]);
        $gravada = $idx >= 0 && isset($linhas[$idx]);
        $atual = $gravada ? (string)($linhas[$idx]['foto'] ?? '') : '';
        if ($op === 'add') {
            $novo = $arquivos ? site_salvar_imagem($arquivos[0], $evento_id, $pref) : null;
            if (!$novo) return ['ok' => false, 'msg' => 'Envie uma imagem JPG, PNG ou WEBP válida.'];
            if ($gravada) { site_apagar_imagem($atual); $linhas[$idx]['foto'] = $novo; }
            $atual = $novo;
        } elseif ($op === 'remove') {
            $alvo = (string)($_POST['arquivo'] ?? '');
            if ($gravada) { site_apagar_imagem($atual); $linhas[$idx]['foto'] = ''; }
            else site_apagar_imagem($alvo);
            $atual = '';
        } else return ['ok' => false, 'msg' => 'Operação inválida.'];
        if ($gravada) $pdo->prepare("UPDATE site_convite SET $coluna = ? WHERE evento_id = ?")->execute([json_encode($linhas, JSON_UNESCAPED_UNICODE), $evento_id]);
        return ['ok' => true, 'foto' => $atual, 'gravada' => $gravada];
    }
    return ['ok' => false, 'msg' => 'Campo inválido.'];
}

/**
 * Situação de cada presente da lista: cotas já presenteadas (pagamento conferido),
 * em andamento (convidado informou o Pix, ainda não conferido) e disponíveis.
 * Presentes com valor livre (valor 0) não têm limite de cotas.
 */
function site_presentes_estado(PDO $pdo, int $evento_id, array $presentes): array {
    $stmt = $pdo->prepare("SELECT presente_id, recebido, SUM(cotas) AS qtd, SUM(valor) AS total FROM site_contribuicoes WHERE evento_id = ? GROUP BY presente_id, recebido");
    $stmt->execute([$evento_id]);
    $soma = [];
    foreach ($stmt->fetchAll() as $l) {
        $soma[$l['presente_id']][(int)$l['recebido']] = ['qtd' => (int)$l['qtd'], 'total' => (float)$l['total']];
    }
    $saida = [];
    foreach ($presentes as $p) {
        $livre = (float)($p['valor'] ?? 0) <= 0;
        $total = $livre ? 0 : max(1, (int)($p['cotas'] ?? 1));
        $ok = $soma[$p['id']][1]['qtd'] ?? 0;
        $andamento = $soma[$p['id']][0]['qtd'] ?? 0;
        $saida[$p['id']] = [
            'livre' => $livre, 'total' => $total, 'recebidas' => $ok, 'andamento' => $andamento,
            'disponiveis' => $livre ? PHP_INT_MAX : max(0, $total - $ok - $andamento),
        ];
    }
    return $saida;
}

/** Fotos ao lado do texto da história (registro do casal). Aproveita a antiga foto única enquanto a lista nova estiver vazia. */
function site_historia_lado(array $site, array $ex): array {
    if ($ex['historia_lado']) return $ex['historia_lado'];
    return !empty($site['historia_foto']) ? [$site['historia_foto']] : [];
}

/**
 * Primeiro nome de cada pessoa do casal para a capa. Prioridade: nomes digitados no editor do site;
 * senão o cadastro do casal (nome + nome secundário). Se o cadastro trouxer "Ana & Lucas" num campo só, separa em dois.
 * @return array{0:string,1:string}
 */
function site_nomes_casal(array $textos, string $nome_cadastro, ?string $nome_secundario): array {
    $primeiro = fn(string $n) => trim(explode(' ', trim($n))[0]);
    $n1 = trim((string)($textos['nome1'] ?? ''));
    $n2 = trim((string)($textos['nome2'] ?? ''));
    if ($n1 === '' && $n2 === '') {
        $sec = trim((string)$nome_secundario);
        if ($sec === '' && str_contains($nome_cadastro, '&')) { [$nome_cadastro, $sec] = array_map('trim', explode('&', $nome_cadastro, 2)); }
        $n1 = $primeiro($nome_cadastro); $n2 = $primeiro($sec);
    }
    return [$n1, $n2];
}

/* ============================================================
   ACESSO PAGO: o site só abre depois que a Central libera (após o pagamento)
   ============================================================ */

/** Valor cobrado pelo site. Ajustável em config/central.local.php ('site_preco') ou na variável de ambiente SITE_PRECO. */
function site_preco(): float {
    $local = @include __DIR__ . '/config/central.local.php';
    if (is_array($local) && isset($local['site_preco']) && (float)$local['site_preco'] > 0) return (float)$local['site_preco'];
    $env = getenv('SITE_PRECO');
    return ($env !== false && (float)$env > 0) ? (float)$env : 149.90;
}

/** @return array{status:string, preco:?float, solicitado_em:?string, liberado_em:?string}  status: bloqueado | solicitado | liberado */
function site_acesso(PDO $pdo, int $evento_id): array {
    $stmt = $pdo->prepare("SELECT * FROM site_convite_acesso WHERE evento_id = ?");
    $stmt->execute([$evento_id]);
    $l = $stmt->fetch();
    return $l ?: ['evento_id' => $evento_id, 'status' => 'bloqueado', 'preco' => null, 'solicitado_em' => null, 'liberado_em' => null];
}

function site_acesso_liberado(PDO $pdo, int $evento_id): bool {
    return site_acesso($pdo, $evento_id)['status'] === 'liberado';
}

/* ============================================================
   MODELO BASE: site de exemplo completo (fotos em img/site_modelo/)
   Serve para (1) a página de demonstração /convite/modelo e
   (2) pré-preencher o editor dos noivos quando o acesso é liberado.
   ============================================================ */

/**
 * Conteúdo do modelo. $foto(arquivo_do_modelo, prefixo) devolve o nome a gravar:
 * na demonstração é o próprio arquivo; ao pré-preencher um casal, é uma cópia nova em uploads/.
 */
function site_modelo_conteudo(callable $foto): array {
    $lado = ['lado1', 'lado2', 'lado3', 'lado4'];
    $leg_lado = ['Nosso primeiro encontro', 'A primeira viagem juntos', 'O dia em que dissemos "sim" ao futuro', 'Planejando o nosso grande dia'];
    $lado_arq = []; $legendas = [];
    foreach ($lado as $i => $n) { $f = $foto($n, 'hlado'); $lado_arq[] = $f; $legendas[$f] = $leg_lado[$i]; }
    $gal = []; $leg_gal = ['Nosso primeiro verão juntos', 'Manhãs que valem o dia inteiro', 'Pôr do sol favorito', 'O sapato do grande dia', 'Flores que enfeitaram a casa', 'Um detalhe que amamos'];
    foreach (['gal1', 'gal2', 'gal3', 'gal4', 'gal5', 'gal6'] as $i => $n) { $f = $foto($n, 'gal'); $gal[] = $f; $legendas[$f] = $leg_gal[$i]; }
    $pres = function (string $nome, float $valor, int $cotas, string $desc, string $img, array $demo = [0, 0]) use ($foto) {
        return ['id' => substr(md5($nome), 0, 8), 'nome' => $nome, 'valor' => $valor, 'cotas' => $cotas, 'descricao' => $desc, 'foto' => $foto($img, 'pres'), 'demo' => $demo];
    };
    return [
        'colunas' => [
            'titulo' => 'Nós vamos casar', 'local_resumo' => 'Sua cidade - UF',
            'capa_foto' => $foto('capa', 'capa'), 'historia_foto' => null,
            'historia' => "Nos conhecemos num dia comum, daqueles em que nada parecia especial, e bastou uma conversa para tudo mudar.\n\nEntre cafés, viagens e planos, descobrimos que o melhor lugar do mundo é ao lado um do outro. Cada dia foi nos mostrando que a vida é mais leve quando dividida.\n\nDepois de tantos caminhos compartilhados, chegou a hora de celebrar o nosso sim ao lado de quem a gente mais ama. Este espaço foi feito para contar um pouco da nossa história e reunir tudo o que você precisa saber sobre esse dia tão especial.",
            'cor_destaque' => '#3f503a', 'cor_fundo' => '#f7f6f2', 'fonte_nomes' => 'Great Vibes',
            'frase_intro' => 'Depois de tantos caminhos compartilhados, chegou a hora de celebrar o nosso sim ao lado de quem amamos.',
            'dress_eles' => "Traje social completo: terno e gravata em tons escuros ou neutros.\nSapato social fechado.",
            'dress_elas' => "Vestido longo ou midi em tons suaves e terrosos.\nEvitar vestidos muito curtos na cerimônia.",
            'cores_evitar' => 'Branco, Off-white, Azul Serenity, Cinza',
            'versiculo' => 'O amor é paciente, o amor é bondoso. (1 Coríntios 13:4)',
            'pix_chave' => '', 'pix_titular' => '',
            'programacao' => [
                ['titulo' => 'Cerimônia', 'hora' => '16:30', 'local' => 'Igreja Matriz', 'endereco' => 'Rua Exemplo, 100 - Centro', 'descricao' => 'Pedimos que cheguem com 20 minutos de antecedência. A cerimônia terá cerca de 40 minutos.', 'foto' => $foto('loc1', 'loc')],
                ['titulo' => 'Recepção', 'hora' => '18:30', 'local' => 'Espaço Jardim', 'endereco' => 'Av. das Palmeiras, 850 - Bairro Exemplo', 'descricao' => 'Jantar, música ao vivo e muita festa. Haverá estacionamento com manobrista.', 'foto' => $foto('loc2', 'loc')],
            ],
            'hospedagem' => [
                ['nome' => 'Hotel Exemplo Centro', 'endereco' => 'Av. Principal, 300 - Centro', 'descricao' => 'A 5 minutos da igreja. Café da manhã incluso. Cite o nome dos noivos para o desconto.', 'foto' => $foto('hotel1', 'hotel')],
                ['nome' => 'Pousada Recanto', 'endereco' => 'Rua das Flores, 45 - Centro', 'descricao' => 'Opção econômica, com piscina e a poucos minutos da cerimônia.', 'foto' => $foto('hotel2', 'hotel')],
                ['nome' => 'Grand Hotel Exemplo', 'endereco' => 'Av. Beira Rio, 1200', 'descricao' => 'Hotel com translado para a recepção nos horários do evento.', 'foto' => $foto('hotel3', 'hotel')],
            ],
            'galeria' => $gal,
            'presentes' => [
                $pres('Cota da lua de mel', 150, 4, 'Ajude a nossa viagem dos sonhos', 'pres1', [2, 1]),
                $pres('Jantar romântico', 250, 4, 'Um jantar a dois na lua de mel', 'pres2', [1, 0]),
                $pres('Cafeteira italiana', 120, 3, 'Para o nosso ritual de todas as manhãs', 'pres3'),
                $pres('Noite em hotel', 400, 2, 'Uma noite especial na viagem', 'pres4'),
                $pres('Kit de panelas', 300, 5, 'Para estrear a nossa cozinha nova', 'pres5'),
                $pres('Contribuição livre', 0, 1, 'Escolha o valor que couber no seu carinho', 'pres6'),
            ],
        ],
        'extras' => [
            'textos' => [
                'historia_desc' => 'Como tudo começou', 'traje_desc' => 'Pedimos a gentileza de seguir as sugestões abaixo.',
                'hospedagem_desc' => 'Sugestões de hotéis para quem vem de fora.', 'galeria_desc' => 'Alguns momentos que guardamos com carinho.',
                'presentes_desc' => 'Se quiser nos presentear, escolha uma opção abaixo. Cada gesto fará parte do nosso começo.',
                'recados_desc' => 'Deixe uma mensagem. Ela aparece aqui depois de aprovada pelos noivos.',
            ],
            'ocultas' => [],
            'fundo' => [$foto('fundo1', 'fundo'), $foto('fundo2', 'fundo'), $foto('fundo3', 'fundo')],
            'faixas' => [$foto('faixa1', 'faixa'), $foto('faixa2', 'faixa'), $foto('faixa3', 'faixa')],
            'historia_fotos' => [$foto('hfundo1', 'hfundo'), $foto('hfundo2', 'hfundo'), $foto('hfundo3', 'hfundo'), $foto('hfundo4', 'hfundo')],
            'historia_lado' => $lado_arq,
            'legendas' => $legendas,
            'traje_eles_foto' => $foto('teles', 'teles'), 'traje_elas_foto' => $foto('telas', 'telas'),
        ],
        'recados' => [
            ['nome' => 'Tia Marlene', 'mensagem' => 'Que Deus abençoe essa união! Vocês merecem toda a felicidade do mundo. Já estou ansiosa pela festa!'],
            ['nome' => 'Rafael e Júlia', 'mensagem' => 'Parabéns, casal lindo! Contem com a gente para o que precisarem. Vai ser uma noite inesquecível.'],
            ['nome' => 'Vovô Antônio', 'mensagem' => 'Meus netos queridos, cada passo de vocês me enche de orgulho. Que o amor de vocês dure por toda a vida.'],
        ],
    ];
}

/** Linha de site_convite (+ campos do evento) equivalente ao modelo, usada pela página de demonstração. */
function site_modelo_linha(): array {
    $m = site_modelo_conteudo(fn($arq, $pref) => $arq . '.jpg');
    $c = $m['colunas'];
    foreach (['programacao', 'hospedagem', 'galeria', 'presentes'] as $k) $c[$k] = json_encode($c[$k], JSON_UNESCAPED_UNICODE);
    return $c + [
        'evento_id' => 0, 'slug' => 'modelo', 'ativo' => 1, 'extras' => json_encode($m['extras'], JSON_UNESCAPED_UNICODE),
        'data_evento' => date('Y-m-d', strtotime('+120 days')), 'hora_evento' => '16:30:00', 'cliente_id' => 0, 'assessoria_id' => null,
        'nome1' => 'Ana', 'nome2' => 'Pedro',
    ];
}

/**
 * Cria o site do casal já pré-preenchido com o modelo (cópias próprias das fotos, que ele pode trocar/remover).
 * Pix e contato ficam em branco de propósito: são dados do casal.
 */
function site_aplicar_modelo(PDO $pdo, int $evento_id, string $slug): void {
    $origem = __DIR__ . '/img/site_modelo/';
    $copia = function (string $arq, string $prefixo) use ($origem, $evento_id): string {
        $novo = "site_{$prefixo}_{$evento_id}_" . bin2hex(random_bytes(5)) . '.jpg';
        return @copy($origem . $arq . '.jpg', __DIR__ . '/uploads/' . $novo) ? $novo : '';
    };
    $m = site_modelo_conteudo($copia);
    $c = $m['colunas'];
    // presentes: no site do casal ninguém presenteou ainda
    $c['presentes'] = array_map(function ($p) { unset($p['demo']); return $p; }, $c['presentes']);
    $pdo->prepare("
        INSERT INTO site_convite (evento_id, slug, ativo, titulo, local_resumo, capa_foto, historia, cor_destaque, cor_fundo, fonte_nomes, frase_intro,
            dress_eles, dress_elas, cores_evitar, versiculo, pix_chave, pix_titular, programacao, hospedagem, galeria, presentes, extras)
        VALUES (?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', '', ?, ?, ?, ?, ?)
    ")->execute([
        $evento_id, $slug, $c['titulo'], $c['local_resumo'], $c['capa_foto'], $c['historia'], $c['cor_destaque'], $c['cor_fundo'], $c['fonte_nomes'], $c['frase_intro'],
        $c['dress_eles'], $c['dress_elas'], $c['cores_evitar'], $c['versiculo'],
        json_encode($c['programacao'], JSON_UNESCAPED_UNICODE), json_encode($c['hospedagem'], JSON_UNESCAPED_UNICODE),
        json_encode($c['galeria']), json_encode($c['presentes'], JSON_UNESCAPED_UNICODE), json_encode($m['extras'], JSON_UNESCAPED_UNICODE),
    ]);
}
