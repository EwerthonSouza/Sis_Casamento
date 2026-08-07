<?php
session_start();
require_once 'sessao_timeout.inc.php';
verificar_sessao_ativa();
require_once 'conexao.php';

// ============================================================
// TRAVA DE SEGURANÇA: Apenas administradores
// ============================================================
if (!isset($_SESSION['usuario_tipo']) || $_SESSION['usuario_tipo'] !== 'admin') {
    header("Location: index.php?sessao_expirada=1");
    exit;
}

// ============================================================
// CSRF
// ============================================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

function verificar_csrf(): void {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die("Requisição inválida. Token CSRF ausente ou incorreto.");
    }
}

// ============================================================
// AUTO-CONFIGURAÇÃO DO BANCO DE DADOS
// ============================================================
try {
    $pdo->query("SELECT 1 FROM referencias_fornecedores LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("
        CREATE TABLE referencias_fornecedores (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            categoria     VARCHAR(50)  NOT NULL,
            nome          VARCHAR(150) NOT NULL,
            contato       VARCHAR(100) NULL,
            email         VARCHAR(150) NULL,
            redes_sociais VARCHAR(255) NULL,
            endereco      VARCHAR(255) NULL,
            observacoes   TEXT         NULL,
            criado_em     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}

$categorias_sugeridas = ['Local de Cerimônia', 'Local de Recepção', 'Buffet', 'Música/DJ', 'Fotografia', 'Decoração', 'Outros'];

// ============================================================
// POST HANDLERS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verificar_csrf();

    if (isset($_POST['adicionar_referencia'])) {
        $categoria = trim($_POST['categoria'] ?? '');
        $nome      = trim($_POST['nome'] ?? '');
        $contato   = trim($_POST['contato'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $redes     = trim($_POST['redes_sociais'] ?? '');
        $endereco  = trim($_POST['endereco'] ?? '');
        $obs       = trim($_POST['observacoes'] ?? '');

        if ($categoria !== '' && $nome !== '') {
            $pdo->prepare("INSERT INTO referencias_fornecedores (categoria, nome, contato, email, redes_sociais, endereco, observacoes) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$categoria, $nome, $contato, $email, $redes, $endereco, $obs]);
            $_SESSION['msg_sucesso'] = "Referência adicionada com sucesso!";
        } else {
            $_SESSION['msg_erro'] = "Preencha ao menos a categoria e o nome.";
        }
        header("Location: referencias.php"); exit;
    }

    if (isset($_POST['editar_referencia'])) {
        $id        = (int)($_POST['id_referencia'] ?? 0);
        $categoria = trim($_POST['categoria_edit'] ?? '');
        $nome      = trim($_POST['nome_edit'] ?? '');
        $contato   = trim($_POST['contato_edit'] ?? '');
        $email     = trim($_POST['email_edit'] ?? '');
        $redes     = trim($_POST['redes_sociais_edit'] ?? '');
        $endereco  = trim($_POST['endereco_edit'] ?? '');
        $obs       = trim($_POST['observacoes_edit'] ?? '');

        if ($id > 0 && $categoria !== '' && $nome !== '') {
            $pdo->prepare("UPDATE referencias_fornecedores SET categoria = ?, nome = ?, contato = ?, email = ?, redes_sociais = ?, endereco = ?, observacoes = ? WHERE id = ?")
                ->execute([$categoria, $nome, $contato, $email, $redes, $endereco, $obs, $id]);
            $_SESSION['msg_sucesso'] = "Referência atualizada com sucesso!";
        } else {
            $_SESSION['msg_erro'] = "Preencha ao menos a categoria e o nome.";
        }
        header("Location: referencias.php"); exit;
    }

    if (isset($_POST['excluir_referencia'])) {
        $id = (int)($_POST['id_referencia'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM referencias_fornecedores WHERE id = ?")->execute([$id]);
            $_SESSION['msg_sucesso'] = "Referência removida.";
        }
        header("Location: referencias.php"); exit;
    }
}

// ============================================================
// CARREGAR DADOS
// ============================================================
$lista = $pdo->query("SELECT * FROM referencias_fornecedores ORDER BY categoria ASC, nome ASC")->fetchAll();

$grupos = [];
foreach ($lista as $r) {
    $grupos[$r['categoria']][] = $r;
}
ksort($grupos);

$msg_sucesso = $_SESSION['msg_sucesso'] ?? '';
$msg_erro    = $_SESSION['msg_erro']    ?? '';
unset($_SESSION['msg_sucesso'], $_SESSION['msg_erro']);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Referências de Fornecedores - Meu Evento PRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/estilo.css?v=8">
    <style>
        .card-ref { transition: box-shadow .2s, transform .2s; }
        .card-ref:hover { box-shadow: 0 8px 20px rgba(0,0,0,.08); transform: translateY(-2px); }
        .categoria-titulo { font-size: .78rem; text-transform: uppercase; letter-spacing: .05em; font-weight: 800; color: var(--color-primary-dark); border-bottom: 2px solid var(--color-primary-dark); padding-bottom: 6px; margin: 24px 0 14px; }
        .categoria-titulo:first-of-type { margin-top: 0; }
        .link-social { font-size: .78rem; word-break: break-all; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark shadow-sm">
    <div class="container">
        <span class="navbar-brand mb-0">
            <img src="img/LOGO MEP NAV.svg" alt="Meu Evento PRO" style="height:40px;">
        </span>
        <div class="d-flex align-items-center gap-2">
            <a href="painel_admin.php" class="btn btn-sm btn-outline-light rounded-3">
                <i class="bi bi-arrow-left me-1"></i> Voltar ao Painel
            </a>
        </div>
    </div>
</nav>

<div class="container my-5">

    <h4 class="fw-bold mb-4"><i class="bi bi-geo-alt-fill text-primary me-2"></i>Referências de Fornecedores</h4>

    <?php if ($msg_sucesso): ?>
        <div class="alert alert-success shadow-sm alert-dismissible fade show"><button class="btn-close" data-bs-dismiss="alert"></button><i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($msg_sucesso) ?></div>
    <?php endif; ?>
    <?php if ($msg_erro): ?>
        <div class="alert alert-danger shadow-sm alert-dismissible fade show"><button class="btn-close" data-bs-dismiss="alert"></button><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($msg_erro) ?></div>
    <?php endif; ?>

    <div class="bg-white p-4 rounded shadow-sm mb-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h2 class="mb-1"><i class="bi bi-collection-fill text-primary"></i> Catálogo de Referências</h2>
            <p class="text-muted mb-0">Locais, músicos, buffets e outros fornecedores recomendados pela assessoria.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <div class="input-group input-group-sm" style="max-width: 240px;">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" id="campoBusca" class="form-control" placeholder="Buscar referência...">
            </div>
            <button class="btn btn-primary fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalAdicionar">
                <i class="bi bi-plus-lg"></i> Nova Referência
            </button>
        </div>
    </div>

    <div id="lista-referencias">
        <?php if (empty($grupos)): ?>
            <div class="card border-0 shadow-sm text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 mb-2"></i>
                <p class="mb-0">Nenhuma referência cadastrada ainda. Clique em "Nova Referência" para começar.</p>
            </div>
        <?php else: foreach ($grupos as $categoria => $itens): ?>
            <div class="grupo-categoria" data-categoria="<?= strtolower(htmlspecialchars($categoria, ENT_QUOTES, 'UTF-8')) ?>">
                <div class="categoria-titulo"><i class="bi bi-tag-fill me-1"></i> <?= htmlspecialchars($categoria, ENT_QUOTES, 'UTF-8') ?> (<?= count($itens) ?>)</div>
                <div class="row g-3 mb-2">
                    <?php foreach ($itens as $r): ?>
                    <div class="col-md-6 col-lg-4 item-referencia" data-nome="<?= strtolower(htmlspecialchars($r['nome'], ENT_QUOTES, 'UTF-8')) ?>">
                        <div class="card border-0 shadow-sm card-ref h-100">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="fw-bold mb-0"><?= htmlspecialchars($r['nome'], ENT_QUOTES, 'UTF-8') ?></h6>
                                    <div class="d-flex gap-1 flex-shrink-0">
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" data-bs-toggle="modal" data-bs-target="#modalEditar<?= $r['id'] ?>" title="Editar">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" data-bs-toggle="modal" data-bs-target="#modalExcluir<?= $r['id'] ?>" title="Excluir">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                <?php if (!empty($r['contato'])): ?>
                                    <div class="small text-muted mb-1"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($r['contato'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                                <?php if (!empty($r['email'])): ?>
                                    <div class="small text-muted mb-1"><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($r['email'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                                <?php if (!empty($r['endereco'])): ?>
                                    <div class="small text-muted mb-1"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($r['endereco'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                                <?php if (!empty($r['redes_sociais'])): ?>
                                    <?php $red = $r['redes_sociais']; $ehLink = preg_match('#^https?://#i', $red); ?>
                                    <div class="mb-1">
                                        <?php if ($ehLink): ?>
                                            <a href="<?= htmlspecialchars($red, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="link-social"><i class="bi bi-link-45deg me-1"></i><?= htmlspecialchars($red, ENT_QUOTES, 'UTF-8') ?></a>
                                        <?php else: ?>
                                            <span class="link-social text-muted"><i class="bi bi-at me-1"></i><?= htmlspecialchars($red, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($r['observacoes'])): ?>
                                    <p class="small text-muted mt-2 mb-0 border-top pt-2"><?= nl2br(htmlspecialchars($r['observacoes'], ENT_QUOTES, 'UTF-8')) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Modal Editar -->
                    <div class="modal fade" id="modalEditar<?= $r['id'] ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content border-0 shadow-lg rounded-4">
                                <div class="modal-header bg-light">
                                    <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Editar Referência</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                    <input type="hidden" name="editar_referencia" value="1">
                                    <input type="hidden" name="id_referencia" value="<?= $r['id'] ?>">
                                    <div class="modal-body">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold">Categoria *</label>
                                                <input type="text" name="categoria_edit" class="form-control" list="lista-categorias" value="<?= htmlspecialchars($r['categoria'], ENT_QUOTES, 'UTF-8') ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold">Nome *</label>
                                                <input type="text" name="nome_edit" class="form-control" value="<?= htmlspecialchars($r['nome'], ENT_QUOTES, 'UTF-8') ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold">Contato</label>
                                                <input type="text" name="contato_edit" class="form-control" value="<?= htmlspecialchars($r['contato'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label small fw-bold">E-mail</label>
                                                <input type="email" name="email_edit" class="form-control" value="<?= htmlspecialchars($r['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                            </div>
                                            <div class="col-md-12">
                                                <label class="form-label small fw-bold">Redes sociais / Site</label>
                                                <input type="text" name="redes_sociais_edit" class="form-control" placeholder="https://instagram.com/..." value="<?= htmlspecialchars($r['redes_sociais'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                            </div>
                                            <div class="col-md-12">
                                                <label class="form-label small fw-bold">Endereço</label>
                                                <input type="text" name="endereco_edit" class="form-control" value="<?= htmlspecialchars($r['endereco'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                            </div>
                                            <div class="col-md-12">
                                                <label class="form-label small fw-bold">Observações</label>
                                                <textarea name="observacoes_edit" class="form-control" rows="3"><?= htmlspecialchars($r['observacoes'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                            </div>
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

                    <!-- Modal Excluir -->
                    <div class="modal fade" id="modalExcluir<?= $r['id'] ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered modal-sm">
                            <div class="modal-content border-0 shadow-lg rounded-4">
                                <div class="modal-header bg-danger text-white border-0">
                                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle"></i> Confirmar Exclusão</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body text-center py-3">
                                    <p class="mb-0">Excluir <strong><?= htmlspecialchars($r['nome'], ENT_QUOTES, 'UTF-8') ?></strong> do catálogo?</p>
                                </div>
                                <div class="modal-footer border-0 justify-content-center">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="id_referencia" value="<?= $r['id'] ?>">
                                        <button type="submit" name="excluir_referencia" class="btn btn-danger">Excluir</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
        <div id="msg-busca-vazia" class="text-center text-muted py-5" style="display:none;">
            <i class="bi bi-search fs-1 d-block mb-2"></i> Nenhuma referência encontrada.
        </div>
    </div>
</div>

<datalist id="lista-categorias">
    <?php foreach ($categorias_sugeridas as $c): ?>
        <option value="<?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?>">
    <?php endforeach; ?>
</datalist>

<div class="modal fade" id="modalAdicionar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Nova Referência</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="adicionar_referencia" value="1">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Categoria *</label>
                            <input type="text" name="categoria" class="form-control" list="lista-categorias" placeholder="Ex: Buffet" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Nome *</label>
                            <input type="text" name="nome" class="form-control" placeholder="Nome do local/profissional/empresa" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Contato</label>
                            <input type="text" name="contato" class="form-control" placeholder="(00) 00000-0000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">E-mail</label>
                            <input type="email" name="email" class="form-control" placeholder="contato@exemplo.com">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-bold">Redes sociais / Site</label>
                            <input type="text" name="redes_sociais" class="form-control" placeholder="https://instagram.com/...">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-bold">Endereço</label>
                            <input type="text" name="endereco" class="form-control" placeholder="Rua, número, bairro, cidade">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label small fw-bold">Observações</label>
                            <textarea name="observacoes" class="form-control" rows="3" placeholder="Detalhes, faixa de preço, experiências anteriores..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Cadastrar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const campoBusca = document.getElementById('campoBusca');
campoBusca.addEventListener('input', function () {
    const termo = this.value.trim().toLowerCase();
    let algumVisivel = false;

    document.querySelectorAll('.grupo-categoria').forEach(grupo => {
        let grupoTemVisivel = false;
        grupo.querySelectorAll('.item-referencia').forEach(item => {
            const nome = item.dataset.nome || '';
            const categoria = grupo.dataset.categoria || '';
            const visivel = !termo || nome.includes(termo) || categoria.includes(termo);
            item.style.display = visivel ? '' : 'none';
            if (visivel) { grupoTemVisivel = true; algumVisivel = true; }
        });
        grupo.style.display = grupoTemVisivel ? '' : 'none';
    });

    document.getElementById('msg-busca-vazia').style.display = (termo && !algumVisivel) ? 'block' : 'none';
});
</script>
</body>
</html>
