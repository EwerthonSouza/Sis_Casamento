<?php
session_start();

// 1. LEÃO DE CHÁCARA INTELIGENTE (Aceita Admin e Noivos)
if (!isset($_SESSION['usuario_tipo']) || ($_SESSION['usuario_tipo'] !== 'admin' && $_SESSION['usuario_tipo'] !== 'noivos')) {
    header("Location: index.php");
    exit;
}

require_once 'conexao.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Acesso negado. Evento inválido.");
}

$evento_id = (int)$_GET['id'];
$usuario_atual = $_GET['usuario'] ?? 'Assessoria'; 

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

// 1. PROCESSAR SELEÇÃO DE REFERÊNCIA OFICIAL (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['favoritar_foto'])) {
    $foto_id = (int)$_POST['foto_id'];
    $status_atual = (int)$_POST['status_atual'];
    $novo_status = $status_atual === 1 ? 0 : 1;

    $stmt = $pdo->prepare("UPDATE inspiracoes_fotos SET selecionada = ? WHERE id = ? AND evento_id = ?");
    $stmt->execute([$novo_status, $foto_id, $evento_id]);
    
    header("Location: inspiracoes.php?id=" . $evento_id . "&usuario=" . $usuario_atual . "&cat=" . ($_GET['cat'] ?? 'Todos'));
    exit;
}

// 2. PROCESSAR UPLOAD DA FOTO COM CATEGORIA NOVA (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_foto'])) {
    $titulo = trim($_POST['titulo']);
    $categoria = !empty($_POST['nova_categoria']) ? trim($_POST['nova_categoria']) : $_POST['categoria'];
    
    if (isset($_FILES['foto_arquivo']) && $_FILES['foto_arquivo']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['foto_arquivo']['tmp_name'];
        $fileName = $_FILES['foto_arquivo']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        $extensoes_permitidas = ['jpg', 'jpeg', 'png', 'webp'];
        if (in_array($fileExtension, $extensoes_permitidas)) {
            $novo_nome_imagem = "insp_" . $evento_id . "_" . time() . "." . $fileExtension;
            if (move_uploaded_file($fileTmpPath, './uploads/' . $novo_nome_imagem)) {
                $stmt = $pdo->prepare("INSERT INTO inspiracoes_fotos (evento_id, categoria, titulo, nome_imagem) VALUES (?, ?, ?, ?)");
                $stmt->execute([$evento_id, $categoria, $titulo, $novo_nome_imagem]);
            }
        }
    }
    header("Location: inspiracoes.php?id=" . $evento_id . "&usuario=" . $usuario_atual);
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
    <title>Mural de Inspirações - <?= htmlspecialchars($evento['nome']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/estilo.css">
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

<div class="container my-5">
    
    <div class="bg-white p-4 rounded-4 shadow-sm mb-4 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 border-top border-4 border-primary">
        <div class="d-flex align-items-center">
            <?php $link_voltar = ($_SESSION['usuario_tipo'] === 'noivos') ? "noivos.php" : "gerenciar.php?id=" . $evento_id; ?>
            <a href="<?= $link_voltar ?>" class="btn btn-outline-secondary btn-sm shadow-sm fw-bold me-3">
                <i class="bi bi-arrow-left"></i> Voltar
            </a>
            <h4 class="mb-0 text-dark fw-bold">Mural: <span class="text-primary"><?= htmlspecialchars($evento['nome']) ?></span></h4>
        </div>
        
        <div class="bg-light p-2 rounded-3 border d-flex align-items-center gap-2">
            <small class="text-muted fw-bold mb-0"><i class="bi bi-eye-fill"></i> Visão:</small>
            <a href="inspiracoes.php?id=<?= $evento_id ?>&usuario=Assessoria" class="btn btn-sm <?= $usuario_atual == 'Assessoria' ? 'btn-dark fw-bold' : 'btn-outline-dark' ?> py-1 px-3" style="font-size:0.8rem;">Assessoria</a>
            <a href="inspiracoes.php?id=<?= $evento_id ?>&usuario=Noivos" class="btn btn-sm <?= $usuario_atual == 'Noivos' ? 'btn-danger fw-bold' : 'btn-outline-danger' ?> py-1 px-3" style="font-size:0.8rem;">Noivos ❤️</a>
        </div>
    </div>

    <div class="row g-4 align-items-start">
        
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 rounded-4 position-sticky" style="top: 20px;">
                <div class="card-header bg-dark text-white p-3 rounded-top-4 text-center">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-cloud-arrow-up-fill text-primary"></i> Sugerir Referência</h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="upload_foto" value="1">
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary">Título da Referência</label>
                            <input type="text" name="titulo" class="form-control border-light-subtle bg-light" placeholder="Ex: Buquê de Orquídeas" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-secondary">Categoria</label>
                            
                            <div class="input-group mb-1" id="box-select-categoria">
                                <select name="categoria" class="form-select border-light-subtle bg-light" id="select-categoria">
                                    <?php foreach($todas_categorias as $c): ?>
                                        <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-primary" type="button" onclick="toggleCategoriaForm()" title="Criar Nova Categoria">
                                    <i class="bi bi-plus-lg"></i>
                                </button>
                            </div>
                            
                            <div class="input-group d-none" id="box-nova-categoria">
                                <input type="text" name="nova_categoria" id="input-nova-categoria" class="form-control border-primary" placeholder="Nova categoria...">
                                <button class="btn btn-danger" type="button" onclick="toggleCategoriaForm()" title="Cancelar">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-bold text-secondary">Selecionar Foto</label>
                            <input type="file" name="foto_arquivo" class="form-control border-light-subtle bg-light" accept="image/*" required>
                        </div>
                        
                        <button type="submit" class="btn btn-success w-100 fw-bold shadow-sm py-2" style="border-radius: 8px;">
                            <i class="bi bi-plus-circle me-1"></i> Adicionar ao Catálogo
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4 p-4 mb-4">
                
                <div class="d-flex flex-wrap gap-2 mb-4 justify-content-center border-bottom pb-4">
                    <a href="inspiracoes.php?id=<?= $evento_id ?>&usuario=<?= $usuario_atual ?>&cat=Todos" class="btn btn-sm <?= $categoria_filtrada == 'Todos' ? 'btn-primary shadow-sm fw-bold' : 'btn-light border text-muted' ?> px-4 rounded-pill transition-all">
                        <i class="bi bi-grid-fill me-1"></i> Tudo
                    </a>
                    
                    <?php foreach($todas_categorias as $c): ?>
                        <a href="inspiracoes.php?id=<?= $evento_id ?>&usuario=<?= $usuario_atual ?>&cat=<?= urlencode($c) ?>" class="btn btn-sm <?= $categoria_filtrada == $c ? 'btn-primary shadow-sm fw-bold' : 'btn-light border text-muted' ?> px-4 rounded-pill transition-all">
                            <?= htmlspecialchars($c) ?>
                        </a>
                    <?php endforeach; ?>
                    
                    <a href="inspiracoes.php?id=<?= $evento_id ?>&usuario=<?= $usuario_atual ?>&cat=Escolhidos" class="btn btn-sm <?= $categoria_filtrada == 'Escolhidos' ? 'btn-danger shadow-sm fw-bold text-white' : 'btn-outline-danger' ?> px-4 rounded-pill ms-lg-auto mt-2 mt-sm-0 transition-all">
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
                                        
                                        <form method="POST" action="" class="m-0">
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
<script>
function abrirFotoCompleta(caminhoImagem, tituloFoto) {
    document.getElementById('modalTitulo').innerText = tituloFoto;
    document.getElementById('modalImagemReal').src = caminhoImagem;
    var meuModal = new bootstrap.Modal(document.getElementById('modalFoto'));
    meuModal.show();
}

function toggleCategoriaForm() {
    var boxSelect = document.getElementById('box-select-categoria');
    var boxNova = document.getElementById('box-nova-categoria');
    var inputNova = document.getElementById('input-nova-categoria');

    if (boxNova.classList.contains('d-none')) {
        boxNova.classList.remove('d-none');
        boxSelect.classList.add('d-none');
        inputNova.focus();
    } else {
        boxNova.classList.add('d-none');
        boxSelect.classList.remove('d-none');
        inputNova.value = ''; 
    }
}
</script>
</body>
</html>