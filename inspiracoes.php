<?php
session_start();

/** Converte um valor de ini (ex: "8M", "1G", "512K") pra bytes. */
function ini_para_bytes(string $val): int {
    $val = trim($val);
    if ($val === '') return 0;
    $unidade = strtolower(substr($val, -1));
    $numero  = (int)$val;
    return match ($unidade) {
        'g' => $numero * 1024 * 1024 * 1024,
        'm' => $numero * 1024 * 1024,
        'k' => $numero * 1024,
        default => (int)$val,
    };
}

// Com envio de várias fotos de uma vez, os dois limites do PHP passam a
// significar coisas diferentes: upload_max_filesize trava CADA arquivo,
// post_max_size trava a SOMA de todos no mesmo envio. Guardamos os dois
// separados pra validar direito no navegador antes de tentar enviar.
$limite_arquivo_mb = max(1, (int)floor(ini_para_bytes(ini_get('upload_max_filesize') ?: '8M') / 1024 / 1024));
$limite_total_mb   = max(1, (int)floor(ini_para_bytes(ini_get('post_max_size')        ?: '8M') / 1024 / 1024));
$limite_upload_mb  = min($limite_arquivo_mb, $limite_total_mb);

// Se o envio veio maior que o limite do servidor, o PHP descarta o corpo da
// requisição inteiro ($_POST/$_FILES ficam vazios) mas ainda registra o
// Content-Length original — é assim que dá pra detectar isso aqui e devolver
// uma mensagem amigável, em vez de deixar cair nas verificações abaixo (que
// esperam $_POST/$_FILES populados) e gerar avisos feios na tela.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // Mensagem mostra o limite REAL configurado no servidor (não um número
    // fixo no código) — pega direto do php.ini efetivo (uploads.ini na
    // imagem Docker), então nunca desalinha do que a hospedagem realmente aceita.
    $limite_ini = ini_get('post_max_size') ?: '?';
    $recebido_mb = round((int)$_SERVER['CONTENT_LENGTH'] / 1024 / 1024, 2);
    $_SESSION['msg_erro'] = "Essa imagem ($recebido_mb MB) passou do limite de envio configurado no servidor (post_max_size = $limite_ini). Peça pra assessoria checar a configuração de upload da hospedagem.";
    $id_redirect = (int)($_GET['id'] ?? 0);
    header('Location: inspiracoes.php' . ($id_redirect ? '?id=' . $id_redirect : ''));
    exit;
}

require_once 'sessao_timeout.inc.php';
verificar_sessao_ativa();

// 1. LEÃO DE CHÁCARA INTELIGENTE (Aceita Admin, Assistente e Noivos)
if (!isset($_SESSION['usuario_tipo']) || !in_array($_SESSION['usuario_tipo'], ['admin', 'assistente', 'noivos'], true)) {
    header("Location: index.php?sessao_expirada=1");
    exit;
}

require_once 'conexao.php';

// Apenas admin pode excluir uploads feitos no mural
$is_admin = ($_SESSION['usuario_tipo'] === 'admin');

// Noivos só podem ver o próprio evento (ignora manipulação da URL); admin/assistente usam o ?id= normalmente
if ($_SESSION['usuario_tipo'] === 'noivos') {
    $evento_id = (int)($_SESSION['evento_id'] ?? 0);
    if (!$evento_id) { die("Acesso negado. Evento inválido."); }
} else {
    if (!isset($_GET['id']) || empty($_GET['id'])) {
        die("Acesso negado. Evento inválido.");
    }
    $evento_id = (int)$_GET['id'];
}

// Buscar dados do casamento
$stmt = $pdo->prepare("SELECT e.*, c.nome FROM eventos e INNER JOIN clientes c ON e.cliente_id = c.id WHERE e.id = ?");
$stmt->execute([$evento_id]);
$evento = $stmt->fetch();
if (!$evento) { die("Casamento não encontrado."); }

// --- LÓGICA DE CATEGORIAS DINÂMICAS ---
$stmt_cats = $pdo->prepare("SELECT DISTINCT categoria FROM inspiracoes_fotos WHERE evento_id = ? AND categoria != '' ORDER BY categoria ASC");
$stmt_cats->execute([$evento_id]);
$categorias_banco = $stmt_cats->fetchAll(PDO::FETCH_COLUMN);

$categorias_padrao = ['Decoração', 'Buquê', 'Bolo', 'Outros'];
$todas_categorias = array_unique(array_merge($categorias_padrao, $categorias_banco));
sort($todas_categorias);

/* ============================================================
   CSRF TOKEN
   ============================================================ */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

$msg_erro    = $_SESSION['msg_erro']    ?? '';
$msg_sucesso = $_SESSION['msg_sucesso'] ?? '';
unset($_SESSION['msg_erro'], $_SESSION['msg_sucesso']);

function verificar_csrf(): void {
    $token_post    = $_POST['csrf_token']    ?? '';
    $token_header  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $token_enviado = $token_post !== '' ? $token_post : $token_header;
    if (!hash_equals($_SESSION['csrf_token'], $token_enviado)) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'Token CSRF inválido.']);
        exit;
    }
}

// 1. PROCESSAR SELEÇÃO DE REFERÊNCIA OFICIAL (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['favoritar_foto'])) {
    verificar_csrf();
    $foto_id = (int)$_POST['foto_id'];
    $status_atual = (int)$_POST['status_atual'];
    $novo_status = $status_atual === 1 ? 0 : 1;

    $stmt = $pdo->prepare("UPDATE inspiracoes_fotos SET selecionada = ? WHERE id = ? AND evento_id = ?");
    $stmt->execute([$novo_status, $foto_id, $evento_id]);

    header("Location: inspiracoes.php?id=" . $evento_id . "&cat=" . ($_GET['cat'] ?? 'Todos'));
    exit;
}

// 1b. EXCLUIR UPLOAD (apenas admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['excluir_foto'])) {
    verificar_csrf();

    if ($is_admin) {
        $foto_id = (int)$_POST['foto_id'];

        $stmt = $pdo->prepare("SELECT nome_imagem FROM inspiracoes_fotos WHERE id = ? AND evento_id = ?");
        $stmt->execute([$foto_id, $evento_id]);
        $foto = $stmt->fetch();

        if ($foto) {
            $caminho_arquivo = './uploads/' . $foto['nome_imagem'];
            if (is_file($caminho_arquivo)) {
                @unlink($caminho_arquivo);
            }
            $pdo->prepare("DELETE FROM inspiracoes_fotos WHERE id = ? AND evento_id = ?")->execute([$foto_id, $evento_id]);
        }
    }

    header("Location: inspiracoes.php?id=" . $evento_id . "&cat=" . ($_GET['cat'] ?? 'Todos'));
    exit;
}

// 1c. EDITAR TÍTULO/CATEGORIA de uma foto já enviada
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_foto'])) {
    verificar_csrf();

    $foto_id   = (int)($_POST['foto_id'] ?? 0);
    $titulo    = trim($_POST['titulo_edit'] ?? '');
    $categoria = !empty($_POST['nova_categoria_edit']) ? trim($_POST['nova_categoria_edit']) : trim($_POST['categoria_edit'] ?? '');

    if ($foto_id > 0 && $titulo !== '' && $categoria !== '') {
        $pdo->prepare("UPDATE inspiracoes_fotos SET titulo = ?, categoria = ? WHERE id = ? AND evento_id = ?")
            ->execute([$titulo, $categoria, $foto_id, $evento_id]);
        $_SESSION['msg_sucesso'] = 'Referência atualizada!';
    } else {
        $_SESSION['msg_erro'] = 'Preencha o título e a categoria.';
    }

    header("Location: inspiracoes.php?id=" . $evento_id . "&cat=" . ($_GET['cat'] ?? 'Todos'));
    exit;
}

// 2. PROCESSAR UPLOAD DE FOTOS — uma ou várias de uma vez (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_foto'])) {
    verificar_csrf();
    $titulo_base = trim($_POST['titulo']);
    $categoria = !empty($_POST['nova_categoria']) ? trim($_POST['nova_categoria']) : ($_POST['categoria'] ?? '');

    // Com name="foto_arquivo[]", o PHP entrega cada campo do $_FILES como
    // array paralelo (name[0], name[1]...), não como lista de arquivos.
    $arquivos = $_FILES['foto_arquivo'] ?? ['name' => []];
    $total_arquivos = is_array($arquivos['name'] ?? null) ? count($arquivos['name']) : 0;

    // jpg/png/webp/gif/bmp são validados de verdade (getimagesize confirma
    // que o conteúdo é mesmo uma imagem). heic/heif (padrão da câmera do
    // iPhone) o GD do PHP não sabe decodificar — o navegador já converte
    // pra JPEG antes de enviar quando consegue (ver JS); se ainda assim
    // chegar em heic/heif puro (JS desligado, navegador antigo), aceita
    // sem essa checagem extra em vez de rejeitar sem explicação.
    $extensoes_validadas = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'];
    $extensoes_sem_check = ['heic', 'heif'];

    $sucesso = 0;
    $erros = [];

    for ($i = 0; $i < $total_arquivos; $i++) {
        $erro_arquivo = $arquivos['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($erro_arquivo === UPLOAD_ERR_NO_FILE) continue;

        $fileName = $arquivos['name'][$i];

        if ($erro_arquivo === UPLOAD_ERR_INI_SIZE || $erro_arquivo === UPLOAD_ERR_FORM_SIZE) {
            $erros[] = "$fileName: imagem grande demais (máx. {$limite_arquivo_mb}MB por foto).";
            continue;
        }
        if ($erro_arquivo !== UPLOAD_ERR_OK) {
            $erros[] = "$fileName: não foi possível enviar.";
            continue;
        }

        $fileTmpPath = $arquivos['tmp_name'][$i];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $valido = in_array($fileExtension, $extensoes_sem_check, true)
            || (in_array($fileExtension, $extensoes_validadas, true) && @getimagesize($fileTmpPath) !== false);

        if (!$valido) {
            $erros[] = "$fileName: formato não suportado.";
            continue;
        }

        // Mais de uma foto no mesmo envio: numera o título pra não duplicar
        // o mesmo nome em todas as fotos do lote.
        $titulo = $total_arquivos > 1 ? ($titulo_base . ' (' . ($sucesso + 1) . ')') : $titulo_base;
        $novo_nome_imagem = "insp_" . $evento_id . "_" . time() . "_" . $i . "." . $fileExtension;

        if (move_uploaded_file($fileTmpPath, './uploads/' . $novo_nome_imagem)) {
            $stmt = $pdo->prepare("INSERT INTO inspiracoes_fotos (evento_id, categoria, titulo, nome_imagem) VALUES (?, ?, ?, ?)");
            $stmt->execute([$evento_id, $categoria, $titulo, $novo_nome_imagem]);
            $sucesso++;
        } else {
            $erros[] = "$fileName: não foi possível salvar no servidor.";
        }
    }

    if ($sucesso === 0 && empty($erros)) {
        $_SESSION['msg_erro'] = 'Selecione ao menos uma foto para enviar.';
    } elseif ($sucesso > 0 && empty($erros)) {
        $_SESSION['msg_sucesso'] = $sucesso === 1 ? 'Foto adicionada ao mural!' : "$sucesso fotos adicionadas ao mural!";
    } elseif ($sucesso > 0) {
        $_SESSION['msg_sucesso'] = "$sucesso foto(s) adicionada(s) ao mural.";
        $_SESSION['msg_erro'] = implode(' | ', $erros);
    } else {
        $_SESSION['msg_erro'] = implode(' | ', $erros);
    }

    header("Location: inspiracoes.php?id=" . $evento_id);
    exit;
}

// 3. FILTRO DE CATEGORIAS
$categoria_filtrada = $_GET['cat'] ?? 'Todos';

if ($categoria_filtrada === 'Escolhidos') {
    $stmt_fotos = $pdo->prepare("SELECT * FROM inspiracoes_fotos WHERE evento_id = ? AND selecionada = 1 ORDER BY data_upload DESC");
    $stmt_fotos->execute([$evento_id]);
} elseif ($categoria_filtrada !== 'Todos') {
    $stmt_fotos = $pdo->prepare("SELECT * FROM inspiracoes_fotos WHERE evento_id = ? AND categoria = ? ORDER BY selecionada DESC, data_upload DESC");
    $stmt_fotos->execute([$evento_id, $categoria_filtrada]);
} else {
    $stmt_fotos = $pdo->prepare("SELECT * FROM inspiracoes_fotos WHERE evento_id = ? ORDER BY selecionada DESC, data_upload DESC");
    $stmt_fotos->execute([$evento_id]);
}
$fotos = $stmt_fotos->fetchAll();
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mural de Inspirações - <?= htmlspecialchars($evento['nome']) ?> - Meu Evento PRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/estilo.css?v=13">
    <style>
        /* Estilização Premium para os Cards de Foto */
        .foto-card {
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            border: 1px solid rgba(0,0,0,0.05) !important;
        }
        .foto-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px -10px rgba(0,0,0,0.15) !important;
        }
        .foto-img-container {
            position: relative;
            height: 220px;
            overflow: hidden;
            background-color: #f8fafc;
        }
        .foto-img-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        .foto-card:hover .foto-img-container img {
            transform: scale(1.05);
        }
        /* Botão de favoritar limpo */
        .btn-fav {
            transition: transform 0.2s;
        }
        .btn-fav:hover {
            transform: scale(1.15);
        }
    </style>
</head>
<body class="bg-light">

<?php $link_voltar = ($_SESSION['usuario_tipo'] === 'noivos') ? "noivos.php" : "gerenciar.php?id=" . $evento_id; ?>
<nav class="navbar navbar-dark bg-dark shadow-sm">
  <div class="container">
    <span class="navbar-brand mb-0">
      <img src="img/LOGO MEP NAV.svg" alt="Meu Evento PRO" style="height:40px;">
    </span>
    <div class="d-flex align-items-center gap-2">
      <a href="<?= $link_voltar ?>" class="btn btn-sm btn-outline-light rounded-3">
        <i class="bi bi-arrow-left me-1"></i> Voltar
      </a>
    </div>
  </div>
</nav>

<div class="container mt-4">
  <?php if ($msg_erro): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
      <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= htmlspecialchars($msg_erro, ENT_QUOTES, 'UTF-8') ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
    </div>
  <?php endif; ?>
  <?php if ($msg_sucesso): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
      <i class="bi bi-check-circle-fill me-1"></i> <?= htmlspecialchars($msg_sucesso, ENT_QUOTES, 'UTF-8') ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
    </div>
  <?php endif; ?>
</div>

<div class="container my-5">

    <div class="row g-4 align-items-start">
        
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 rounded-4 position-sticky" style="top: 20px;">
                <div class="card-header bg-dark text-white p-3 rounded-top-4 text-center" style="cursor:pointer;"
                     data-bs-toggle="collapse" data-bs-target="#collapseSugerirRef" role="button"
                     aria-expanded="false" aria-controls="collapseSugerirRef">
                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-cloud-arrow-up-fill text-primary"></i> Sugerir Referência
                        <i class="bi bi-chevron-down d-md-none ms-1 small"></i>
                    </h5>
                </div>
                <div class="collapse d-md-block" id="collapseSugerirRef">
                <div class="card-body p-4">
                    <form method="POST" enctype="multipart/form-data" id="form-upload-inspiracao">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="upload_foto" value="1">
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary">Título da Referência</label>
                            <input type="text" name="titulo" class="form-control border-light-subtle bg-light" placeholder="Ex: Buquê de Orquídeas" required>
                            <div class="form-text small text-muted">Se enviar várias fotos de uma vez, cada uma leva esse título numerado (1), (2)...</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary">Categoria</label>
                            <select name="categoria" class="form-select border-light-subtle bg-light" id="select-categoria">
                                <?php foreach($todas_categorias as $c): ?>
                                    <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="nova_categoria" id="input-nova-categoria" class="form-control mt-2 d-none" placeholder="Digite a categoria...">
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-bold text-secondary">Selecionar Foto(s)</label>
                            <input type="file" name="foto_arquivo[]" id="input-foto-arquivo" class="form-control border-light-subtle bg-light" accept="image/*,.heic,.heif" multiple required>
                            <div class="form-text small" id="msg-foto-arquivo"></div>
                        </div>

                        <button type="submit" class="btn btn-success w-100 fw-bold shadow-sm py-2" id="btn-upload-inspiracao" style="border-radius: 8px;">
                            <i class="bi bi-plus-circle me-1"></i> <span id="txt-btn-upload">Adicionar ao Catálogo</span>
                        </button>
                    </form>
                </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4 p-4 mb-4">

                <div class="d-flex flex-wrap gap-2 mb-4 justify-content-center border-bottom pb-4">
                    <a href="inspiracoes.php?id=<?= $evento_id ?>&cat=Todos" class="btn btn-sm <?= $categoria_filtrada == 'Todos' ? 'btn-primary shadow-sm fw-bold' : 'btn-light border text-muted' ?> px-4 rounded-pill transition-all">
                        <i class="bi bi-grid-fill me-1"></i> Tudo
                    </a>
                    
                    <?php foreach($todas_categorias as $c): ?>
                        <a href="inspiracoes.php?id=<?= $evento_id ?>&cat=<?= urlencode($c) ?>" class="btn btn-sm <?= $categoria_filtrada == $c ? 'btn-primary shadow-sm fw-bold' : 'btn-light border text-muted' ?> px-4 rounded-pill transition-all">
                            <?= htmlspecialchars($c) ?>
                        </a>
                    <?php endforeach; ?>
                    
                    <a href="inspiracoes.php?id=<?= $evento_id ?>&cat=Escolhidos" class="btn btn-sm <?= $categoria_filtrada == 'Escolhidos' ? 'btn-danger shadow-sm fw-bold text-white' : 'btn-outline-danger' ?> px-4 rounded-pill ms-lg-auto mt-2 mt-sm-0 transition-all">
                        <i class="bi bi-heart-fill me-1"></i> Escolhidos
                    </a>
                </div>

                <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-4">
                    <?php if(empty($fotos)): ?>
                        <div class="col-12 text-center text-muted py-5 w-100">
                            <i class="bi bi-image text-light" style="font-size: 4rem;"></i>
                            <h5 class="mt-3 fw-bold text-secondary">Nenhuma inspiração ainda</h5>
                            <p class="small">Selecione uma categoria ou envie a primeira foto ao lado.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach($fotos as $f): ?>
                            <div class="col">
                                <div class="card h-100 bg-white rounded-4 overflow-hidden foto-card <?= $f['selecionada'] ? 'border border-2 border-danger' : '' ?>">
                                    
                                    <?php if($f['selecionada']): ?>
                                        <span class="badge bg-danger position-absolute top-0 start-0 m-3 shadow px-3 py-2 rounded-pill" style="z-index: 5; font-size: 0.7rem;">
                                            <i class="bi bi-pin-angle-fill"></i> Oficial
                                        </span>
                                    <?php endif; ?>

                                    <div class="foto-img-container cursor-pointer" onclick="abrirFotoCompleta('./uploads/<?= $f['nome_imagem'] ?>', '<?= htmlspecialchars($f['titulo']) ?>')">
                                        <img src="./uploads/<?= $f['nome_imagem'] ?>" alt="Inspiração">
                                    </div>
                                    
                                    <div class="p-3 d-flex justify-content-between align-items-center bg-white border-top border-light">
                                        <div class="text-truncate me-2" style="max-width:75%;">
                                            <span class="badge bg-primary bg-opacity-10 text-primary mb-1" style="font-size: 0.6rem; letter-spacing: 0.5px; text-transform: uppercase;"><?= htmlspecialchars($f['categoria']) ?></span>
                                            <h6 class="mb-0 text-dark text-truncate fw-bold" style="font-size: 0.9rem;" title="<?= htmlspecialchars($f['titulo']) ?>"><?= htmlspecialchars($f['titulo']) ?></h6>
                                        </div>
                                        
                                        <div class="d-flex align-items-center gap-2">
                                            <form method="POST" action="" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                <input type="hidden" name="favoritar_foto" value="1">
                                                <input type="hidden" name="foto_id" value="<?= $f['id'] ?>">
                                                <input type="hidden" name="status_atual" value="<?= $f['selecionada'] ?>">

                                                <button type="submit" class="btn p-0 border-0 bg-transparent btn-fav" title="Alternar Referência">
                                                    <?php if($f['selecionada']): ?>
                                                        <i class="bi bi-heart-fill text-danger fs-3 drop-shadow"></i>
                                                    <?php else: ?>
                                                        <i class="bi bi-heart text-secondary opacity-50 fs-3"></i>
                                                    <?php endif; ?>
                                                </button>
                                            </form>

                                            <button type="button" class="btn p-0 border-0 bg-transparent btn-fav" title="Editar título/categoria"
                                                    data-bs-toggle="modal" data-bs-target="#modalEditarFoto<?= $f['id'] ?>">
                                                <i class="bi bi-pencil-fill text-secondary opacity-50 fs-6"></i>
                                            </button>

                                            <?php if ($is_admin): ?>
                                            <form method="POST" action="" class="m-0" onsubmit="return confirm('Excluir esta foto do mural? Esta ação não pode ser desfeita.');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                <input type="hidden" name="excluir_foto" value="1">
                                                <input type="hidden" name="foto_id" value="<?= $f['id'] ?>">

                                                <button type="submit" class="btn p-0 border-0 bg-transparent btn-fav" title="Excluir foto">
                                                    <i class="bi bi-trash-fill text-secondary opacity-50 fs-5"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Modais de Editar título/categoria — ficam FORA do .card do catálogo de propósito:
     esse .card tem backdrop-filter (blur) no estilo.css, e isso cria uma "âncora" de
     posicionamento pra qualquer elemento position:fixed dentro dele — inclusive modais
     do Bootstrap, que dependem de ser fixed relativo à janela inteira. Um modal preso
     lá dentro renderiza torto e trava, sem responder a clique nenhum. -->
<?php foreach ($fotos as $f): ?>
<div class="modal fade" id="modalEditarFoto<?= $f['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-light">
                <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Editar Referência</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="editar_foto" value="1">
                <input type="hidden" name="foto_id" value="<?= $f['id'] ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Título da Referência</label>
                        <input type="text" name="titulo_edit" class="form-control" value="<?= htmlspecialchars($f['titulo'], ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>
                    <?php $categoriaEstaNaLista = in_array($f['categoria'], $todas_categorias, true); ?>
                    <div class="mb-1">
                        <label class="form-label small fw-bold text-secondary">Categoria</label>
                        <select name="categoria_edit" class="form-select categoria-edit-select">
                            <?php foreach ($todas_categorias as $c):
                                $selecionada = $categoriaEstaNaLista ? ($f['categoria'] === $c) : ($c === 'Outros');
                            ?>
                            <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>" <?= $selecionada ? 'selected' : '' ?>><?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="nova_categoria_edit" class="form-control mt-2 <?= $categoriaEstaNaLista ? 'd-none' : '' ?>"
                               placeholder="Digite a categoria..."
                               value="<?= $categoriaEstaNaLista ? '' : htmlspecialchars($f['categoria'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar Alterações</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<div class="modal fade" id="modalFoto" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content bg-transparent border-0">
      <div class="modal-header border-0 pb-0 justify-content-end">
        <button type="button" class="btn-close btn-close-white fs-4 shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center p-0">
        <div class="bg-dark bg-opacity-75 d-inline-block p-2 rounded-4 shadow-lg">
            <img src="" id="modalImagemReal" class="img-fluid rounded-3" style="max-height: 85vh; object-fit: contain;">
        </div>
        <h5 class="modal-title mt-3 text-white fw-bold drop-shadow" id="modalTitulo"></h5>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js"></script>
<script>
/* ---- Upload de uma ou várias fotos: converte HEIC/HEIF (padrão da câmera
   do iPhone) pra JPEG no próprio navegador — a maioria dos navegadores não
   exibe/processa HEIC direto — e barra arquivo(s) grande(s) demais antes de
   tentar enviar. ---- */
(function () {
  // Vêm do upload_max_filesize/post_max_size REAIS do servidor (não números
  // fixos no código): o primeiro trava CADA foto, o segundo trava a SOMA de
  // todas as fotos do mesmo envio.
  const TAMANHO_ARQUIVO_MAX_MB = <?= $limite_arquivo_mb ?>;
  const TAMANHO_TOTAL_MAX_MB   = <?= $limite_total_mb ?>;
  const input   = document.getElementById('input-foto-arquivo');
  const aviso   = document.getElementById('msg-foto-arquivo');
  const form    = document.getElementById('form-upload-inspiracao');
  const btnEnv  = document.getElementById('btn-upload-inspiracao');
  const txtBtn  = document.getElementById('txt-btn-upload');
  if (!input) return;

  function mostrarAviso(texto, ehErro) {
    aviso.textContent = texto || '';
    aviso.classList.toggle('text-danger', !!ehErro);
    aviso.classList.toggle('text-muted', !ehErro);
  }

  function ehHeic(file) {
    const nome = (file.name || '').toLowerCase();
    return nome.endsWith('.heic') || nome.endsWith('.heif')
        || file.type === 'image/heic' || file.type === 'image/heif';
  }

  function substituirArquivosDoInput(arquivos) {
    const dt = new DataTransfer();
    arquivos.forEach(f => dt.items.add(f));
    input.files = dt.files;
  }

  function tamanhoTotalMB(arquivos) {
    return arquivos.reduce((soma, f) => soma + f.size, 0) / 1024 / 1024;
  }

  function atualizarTextoBotao(qtd) {
    if (!txtBtn) return;
    txtBtn.textContent = qtd > 1 ? `Adicionar ${qtd} Fotos ao Catálogo` : 'Adicionar ao Catálogo';
  }

  input.addEventListener('change', async function () {
    let arquivos = Array.from(input.files);
    mostrarAviso('');
    atualizarTextoBotao(arquivos.length);
    if (!arquivos.length) return;

    if (arquivos.some(ehHeic)) {
      mostrarAviso('Convertendo foto(s) do iPhone (HEIC) pra JPEG...');
      if (btnEnv) btnEnv.disabled = true;
      const convertidos = [];
      for (const file of arquivos) {
        if (!ehHeic(file)) { convertidos.push(file); continue; }
        try {
          const convertido = await heic2any({ blob: file, toType: 'image/jpeg', quality: 0.9 });
          const novoNome = file.name.replace(/\.(heic|heif)$/i, '.jpg');
          convertidos.push(new File([convertido], novoNome, { type: 'image/jpeg' }));
        } catch (e) {
          convertidos.push(file); // mantém original — servidor ainda aceita heic/heif puro
        }
      }
      arquivos = convertidos;
      substituirArquivosDoInput(arquivos);
      if (btnEnv) btnEnv.disabled = false;
      mostrarAviso('Foto(s) convertida(s) — pronta(s) pra enviar.');
    }

    const grandeDemais = arquivos.find(f => f.size > TAMANHO_ARQUIVO_MAX_MB * 1024 * 1024);
    if (grandeDemais) {
      mostrarAviso('"' + grandeDemais.name + '" tem ' + (grandeDemais.size / 1024 / 1024).toFixed(1) + 'MB — o máximo por foto é ' + TAMANHO_ARQUIVO_MAX_MB + 'MB.', true);
      input.value = '';
      atualizarTextoBotao(0);
      return;
    }

    const totalMB = tamanhoTotalMB(arquivos);
    if (totalMB > TAMANHO_TOTAL_MAX_MB) {
      mostrarAviso('O total selecionado (' + totalMB.toFixed(1) + 'MB) passa do limite de ' + TAMANHO_TOTAL_MAX_MB + 'MB por envio. Selecione menos fotos de uma vez.', true);
      input.value = '';
      atualizarTextoBotao(0);
      return;
    }

    if (arquivos.length > 1) {
      mostrarAviso(arquivos.length + ' fotos selecionadas.');
    }
  });

  form?.addEventListener('submit', function (e) {
    const arquivos = Array.from(input.files);
    if (!arquivos.length) return;
    const grandeDemais = arquivos.find(f => f.size > TAMANHO_ARQUIVO_MAX_MB * 1024 * 1024);
    const totalMB = tamanhoTotalMB(arquivos);
    if (grandeDemais || totalMB > TAMANHO_TOTAL_MAX_MB) {
      e.preventDefault();
      mostrarAviso('Revise o(s) arquivo(s) selecionado(s): algum passa do limite de tamanho.', true);
    }
  });
})();

function abrirFotoCompleta(caminhoImagem, tituloFoto) {
    document.getElementById('modalTitulo').innerText = tituloFoto;
    document.getElementById('modalImagemReal').src = caminhoImagem;
    var meuModal = new bootstrap.Modal(document.getElementById('modalFoto'));
    meuModal.show();
}

// Categoria "Outros" revela um campo de texto livre pra digitar o nome — nas
// demais opções o campo some e o valor do <select> é usado direto.
document.getElementById('select-categoria')?.addEventListener('change', function () {
    var inputNova = document.getElementById('input-nova-categoria');
    var ehOutros = this.value === 'Outros';
    inputNova.classList.toggle('d-none', !ehOutros);
    if (ehOutros) inputNova.focus(); else inputNova.value = '';
});

// Mesma regra nos modais de "Editar Referência" — um select por foto, cada um
// com seu próprio campo de texto logo em seguida (nextElementSibling).
document.querySelectorAll('.categoria-edit-select').forEach(function (sel) {
  sel.addEventListener('change', function () {
    var inputNova = this.nextElementSibling;
    var ehOutros = this.value === 'Outros';
    inputNova.classList.toggle('d-none', !ehOutros);
    if (ehOutros) inputNova.focus(); else inputNova.value = '';
  });
});
</script>
</body>
</html>