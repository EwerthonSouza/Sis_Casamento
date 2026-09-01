<?php
require_once 'conexao.php';

if (!isset($_GET['evento']) || empty($_GET['evento'])) {
    die("<div class='container mt-5 alert alert-danger'>Link inválido. Por favor, solicite o link correto com os noivos ou assessoria.</div>");
}
$evento_id = (int)$_GET['evento'];

// Migração: colunas usadas pelo formulário público de RSVP.
// Essa página é aberta por CADA convidado que clica no link (sem login/sessão),
// então rodar essas verificações em toda visita pesa muito no banco à toa —
// uma vez confirmado que o schema está OK, marca em disco e nunca mais checa.
if (!schema_ja_verificado('confirmar_v2')) {
    try { $pdo->query("SELECT resposta_rsvp FROM convidados LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE convidados ADD COLUMN resposta_rsvp VARCHAR(20) NULL"); }
    try { $pdo->query("SELECT mensagem_rsvp FROM convidados LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE convidados ADD COLUMN mensagem_rsvp TEXT NULL"); }
    // Amplia faixa_etaria de ENUM fixo para VARCHAR, para comportar os novos rótulos de faixa etária
    $tipoColuna = $pdo->query("
        SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'convidados' AND COLUMN_NAME = 'faixa_etaria'
    ")->fetchColumn();
    if ($tipoColuna !== 'varchar') {
        $pdo->exec("ALTER TABLE convidados MODIFY COLUMN faixa_etaria VARCHAR(50) NOT NULL DEFAULT 'Adulto (11+ anos)'");
    }
    try { $pdo->query("SELECT foto_casal FROM eventos LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE eventos ADD COLUMN foto_casal VARCHAR(255) NULL"); }
    try { $pdo->query("SELECT foto_casal_ativa FROM eventos LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE eventos ADD COLUMN foto_casal_ativa TINYINT(1) NOT NULL DEFAULT 0"); }
    try { $pdo->query("SELECT cor_convite FROM eventos LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE eventos ADD COLUMN cor_convite VARCHAR(7) NULL"); }
    try { $pdo->query("SELECT mensagem_convite FROM eventos LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE eventos ADD COLUMN mensagem_convite VARCHAR(500) NULL"); }
    try { $pdo->query("SELECT cor_btn_sim FROM eventos LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE eventos ADD COLUMN cor_btn_sim VARCHAR(7) NULL"); }
    try { $pdo->query("SELECT cor_btn_nao FROM eventos LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE eventos ADD COLUMN cor_btn_nao VARCHAR(7) NULL"); }
    try { $pdo->query("SELECT token_convite FROM convidados LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE convidados ADD COLUMN token_convite VARCHAR(64) NULL"); }
    try { $pdo->query("SELECT convidado_principal_id FROM convidados LIMIT 1"); }
    catch (Exception $e) { $pdo->exec("ALTER TABLE convidados ADD COLUMN convidado_principal_id INT NULL"); }
    marcar_schema_verificado('confirmar_v2');
}

/** Clareia (percent > 0) ou escurece (percent < 0) uma cor hex, mantendo o mesmo tom */
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
    return sprintf('#%02x%02x%02x', max(0, min(255, round($r))), max(0, min(255, round($g))), max(0, min(255, round($b))));
}

const FAIXAS_ETARIAS = [
    'Criança de Colo (0-5 anos)',
    'Criança (6-10 anos)',
    'Adulto (11+ anos)',
];


$stmt = $pdo->prepare("SELECT e.*, c.nome AS nome_cliente FROM eventos e INNER JOIN clientes c ON e.cliente_id = c.id WHERE e.id = ?");
$stmt->execute([$evento_id]);
$evento = $stmt->fetch();

if (!$evento) {
    die("<div class='container mt-5 alert alert-danger'>Evento não encontrado.</div>");
}

$cor_convite_base = (!empty($evento['cor_convite']) && preg_match('/^#[0-9a-fA-F]{6}$/', $evento['cor_convite']))
    ? $evento['cor_convite'] : '#8b5e3c';
$cor_convite_1 = ajustar_cor($cor_convite_base, -0.22);
$cor_convite_2 = $cor_convite_base;
$cor_convite_3 = ajustar_cor($cor_convite_base, 0.22);

$cor_btn_sim = (!empty($evento['cor_btn_sim']) && preg_match('/^#[0-9a-fA-F]{6}$/', $evento['cor_btn_sim']))
    ? $evento['cor_btn_sim'] : '#16a34a';
$cor_btn_sim_1 = ajustar_cor($cor_btn_sim, -0.18);
$cor_btn_sim_2 = ajustar_cor($cor_btn_sim, 0.18);
$cor_btn_nao = (!empty($evento['cor_btn_nao']) && preg_match('/^#[0-9a-fA-F]{6}$/', $evento['cor_btn_nao']))
    ? $evento['cor_btn_nao'] : '#64748b';
$cor_btn_nao_borda = ajustar_cor($cor_btn_nao, 0.75);

/** Gera (se preciso) e devolve o token_convite de um convidado — mesmo
 *  padrão usado em convidados.php pro botão de WhatsApp/copiar link. */
function garantirTokenConvite(PDO $pdo, int $convidadoId): string {
    $stmt = $pdo->prepare("SELECT token_convite FROM convidados WHERE id = ?");
    $stmt->execute([$convidadoId]);
    $token = $stmt->fetchColumn();
    if (!empty($token)) return $token;

    do {
        $token = bin2hex(random_bytes(16));
        $chk = $pdo->prepare("SELECT id FROM convidados WHERE token_convite = ?");
        $chk->execute([$token]);
    } while ($chk->fetch());
    $pdo->prepare("UPDATE convidados SET token_convite = ? WHERE id = ?")->execute([$token, $convidadoId]);
    return $token;
}

/* ============================================================
   LINK ESPECÍFICO (&token=) — trava a identidade (nome + WhatsApp) no
   convidado dono do link, pra impedir que o link de uma pessoa seja
   usado pra confirmar em nome de outra.

   LINK GERAL (sem &token=) — um único link pra todo mundo: o convidado
   digita o próprio nome, o sistema procura na lista (só entre titulares,
   nunca acompanhantes — cada família responde a partir do titular) e,
   achando exatamente um, gera (ou reaproveita) o token dele e redireciona
   pro link específico — a partir daí é o MESMO fluxo de sempre.

   Exceção: a prévia "somente visual" do modal Personalizar Convite
   (convidados.php) carrega essa página com &_preview=1 e sem token,
   só pra mostrar cor/foto/mensagem — não é uma resposta de verdade
   (o iframe já bloqueia clique via pointer-events:none). Nesse caso
   usa um convidado placeholder só pra o template ter o que exibir.
   ============================================================ */
$token_convite = trim($_GET['token'] ?? '');
$preview_admin = isset($_GET['_preview']);

if ($token_convite === '') {
    if ($preview_admin) {
        $convidado_travado      = ['id' => 0, 'nome' => 'Convidado', 'telefone' => ''];
        $acompanhantes_travados = [];
    } else {
        $busca_nome = trim($_GET['buscar'] ?? '');
        $busca_erro = null;
        $busca_candidatos = [];

        if ($busca_nome !== '') {
            $stmt = $pdo->prepare("SELECT id, nome FROM convidados WHERE evento_id = ? AND convidado_principal_id IS NULL AND LOWER(TRIM(nome)) = LOWER(TRIM(?)) ORDER BY id ASC");
            $stmt->execute([$evento_id, $busca_nome]);
            $candidatos = $stmt->fetchAll();

            $idEscolhido = null;
            $escolherId  = (int)($_GET['escolher'] ?? 0);
            if ($escolherId > 0) {
                foreach ($candidatos as $c) {
                    if ((int)$c['id'] === $escolherId) { $idEscolhido = $escolherId; break; }
                }
            } elseif (count($candidatos) === 1) {
                $idEscolhido = (int)$candidatos[0]['id'];
            }

            if ($idEscolhido !== null) {
                $token = garantirTokenConvite($pdo, $idEscolhido);
                header('Location: confirmar.php?evento=' . $evento_id . '&token=' . $token);
                exit;
            }

            if (count($candidatos) > 1) {
                $busca_candidatos = $candidatos;
            } else {
                $busca_erro = 'Não encontramos esse nome na lista de convidados. Confira se digitou igual ao convite, ou fale com os noivos/assessoria.';
            }
        }

        require 'confirmar_busca_nome.inc.php';
        exit;
    }
} else {
    $stmt = $pdo->prepare("SELECT * FROM convidados WHERE evento_id = ? AND token_convite = ?");
    $stmt->execute([$evento_id, $token_convite]);
    $convidado_travado = $stmt->fetch() ?: null;
    if (!$convidado_travado) {
        die("<div class='container mt-5 alert alert-danger'>Link inválido ou expirado. Solicite um novo link aos noivos ou à assessoria.</div>");
    }
    // Acompanhantes já cadastrados pelos noivos/assessoria junto com este link específico
    $stmt = $pdo->prepare("SELECT * FROM convidados WHERE convidado_principal_id = ? ORDER BY id ASC");
    $stmt->execute([$convidado_travado['id']]);
    $acompanhantes_travados = $stmt->fetchAll();
}

$dias = null;
if (!empty($evento['data_evento'])) {
    $hoje = (new DateTime())->setTime(0, 0, 0);
    $dev  = (new DateTime($evento['data_evento']))->setTime(0, 0, 0);
    $diff = $hoje->diff($dev);
    $dias = $diff->invert ? -$diff->days : $diff->days;
}

$sucesso        = false;
$resposta_tipo  = null;   // 'confirmado' | 'recusado'
$resposta_dados = [];
$erro           = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    try {
        $pdo->beginTransaction();

        if ($acao !== 'resposta_especifico') {
            throw new Exception('validacao:Ação inválida.');
        }

        // A resposta do titular e de cada acompanhante é independente — cada
        // um recebe seu próprio Sim/Não, sem ligação automática entre eles
        // (o titular pode ir mesmo que o acompanhante não vá, e vice-versa).
        $idsPermitidos = array_map('intval', array_merge(
            [$convidado_travado['id']],
            array_column($acompanhantes_travados, 'id')
        ));
        $ids_sim = array_values(array_intersect(array_filter(array_map('intval', $_POST['ids_sim'] ?? [])), $idsPermitidos));
        $ids_nao = array_values(array_intersect(array_filter(array_map('intval', $_POST['ids_nao'] ?? [])), $idsPermitidos));

        if (empty($ids_sim) && empty($ids_nao)) {
            throw new Exception('validacao:Nenhuma resposta recebida.');
        }

        $mapaNomes = [(int)$convidado_travado['id'] => $convidado_travado['nome']];
        foreach ($acompanhantes_travados as $a) { $mapaNomes[(int)$a['id']] = $a['nome']; }

        foreach ($ids_sim as $id) {
            $pdo->prepare("UPDATE convidados SET confirmado = 1, resposta_rsvp = 'confirmado', mensagem_rsvp = NULL, data_confirmacao = NOW() WHERE id = ? AND evento_id = ?")
                ->execute([$id, $evento_id]);
        }
        foreach ($ids_nao as $id) {
            $pdo->prepare("UPDATE convidados SET confirmado = 0, resposta_rsvp = 'recusado', mensagem_rsvp = NULL, data_confirmacao = NOW() WHERE id = ? AND evento_id = ?")
                ->execute([$id, $evento_id]);
        }

        $resposta_dados = [
            'sim' => array_map(fn($id) => ['id' => $id, 'nome' => $mapaNomes[$id] ?? ''], $ids_sim),
            'nao' => array_map(fn($id) => ['id' => $id, 'nome' => $mapaNomes[$id] ?? ''], $ids_nao),
        ];
        $resposta_tipo = 'misto';

        $pdo->commit();
        $sucesso = true;

    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = $e->getMessage();
        if (strpos($msg, 'validacao:') === 0) {
            $erro = substr($msg, 10);
        } else {
            error_log("confirmar.php: erro ao salvar RSVP (evento {$evento_id}): " . $msg);
            $erro = "Erro ao processar sua resposta. Por favor, tente novamente.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-view="<?= $sucesso ? 'sucesso' : (isset($_GET['_preview']) ? 'escolha' : 'auto') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmação de Presença<?= !empty($evento['nome_cliente']) ? ' — ' . htmlspecialchars($evento['nome_cliente'], ENT_QUOTES, 'UTF-8') : '' ?> - Meu Evento PRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="css/estilo.css?v=13">
    <script>
        // Decide qual "view" mostrar antes da página pintar, evitando flash de conteúdo errado.
        (function () {
            var chave = 'rsvp_evento_<?= $evento_id ?>_t<?= (int)$convidado_travado['id'] ?>';
            var salvo = null;
            try { salvo = JSON.parse(localStorage.getItem(chave)); } catch (e) {}
            var modo = document.documentElement.getAttribute('data-view');
            if (modo === 'auto') { modo = salvo ? 'resumo' : 'escolha'; }
            document.documentElement.setAttribute('data-view', modo);
        })();
    </script>
    <style>
        :root {
            --vinho-1: <?= $cor_convite_1 ?>;
            --vinho-2: <?= $cor_convite_2 ?>;
            --vinho-3: <?= $cor_convite_3 ?>;
            --btn-sim-1: <?= $cor_btn_sim_1 ?>;
            --btn-sim-2: <?= $cor_btn_sim_2 ?>;
            --btn-nao: <?= $cor_btn_nao ?>;
            --btn-nao-borda: <?= $cor_btn_nao_borda ?>;
        }
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, var(--vinho-1) 0%, var(--vinho-2) 50%, var(--vinho-3) 100%);
            font-family: 'Inter', system-ui, sans-serif;
        }
        body > .container { padding: 2.5rem 0; }
        .rsvp-card {
            max-width: 560px;
            margin: 0 auto;
            border: none;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 50px rgba(0, 0, 0, .3);
            animation: fadeIn .5s ease both;
        }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .rsvp-topo {
            background: rgba(255, 255, 255, .08);
            color: #fff;
            text-align: center;
            padding: 2.2rem 1.5rem 1.6rem;
        }
        .rsvp-topo .anel { font-size: 2.4rem; opacity: .9; margin-bottom: .4rem; }
        .rsvp-topo .foto-casal {
            width: 110px; height: 110px; border-radius: 50%; object-fit: cover;
            border: 3px solid rgba(255, 255, 255, .6); box-shadow: 0 8px 24px rgba(0, 0, 0, .35);
            margin-bottom: .8rem;
        }
        .rsvp-topo h2 { font-weight: 800; margin-bottom: .2rem; letter-spacing: -.5px; }
        .rsvp-topo .data-evento { opacity: .85; font-size: .92rem; }
        .rsvp-topo .contagem {
            display: inline-block; margin-top: .9rem;
            background: rgba(255, 255, 255, .15); border: 1px solid rgba(255, 255, 255, .3);
            border-radius: 999px; padding: .35rem 1rem; font-size: .8rem; font-weight: 700;
        }
        .rsvp-corpo { background: #fff; padding: 2rem 1.75rem; }

        .view { display: none; }
        html[data-view="escolha"]        #view-escolha        { display: block; }
        html[data-view="resumo"]         #view-resumo          { display: block; }
        html[data-view="sucesso"]        #view-sucesso         { display: block; }
        html[data-view="especifico-acompanhantes"] #view-especifico-acompanhantes { display: block; }

        .btn-escolha {
            display: block; width: 100%; border: none; border-radius: 16px;
            padding: 1.3rem 1rem; font-weight: 800; font-size: 1.05rem;
            transition: transform .15s, box-shadow .15s; text-align: center;
        }
        .btn-escolha:hover { transform: translateY(-2px); }
        .btn-escolha small { display: block; font-weight: 500; opacity: .85; font-size: .78rem; margin-top: .15rem; }
        .btn-sim { background: linear-gradient(135deg, var(--btn-sim-1), var(--btn-sim-2)); color: #fff; }
        .btn-nao { background: #f8fafc; color: var(--btn-nao); border: 1.5px solid var(--btn-nao-borda) !important; }

        .texto-convite-rsvp {
            font-size: clamp(.85rem, 4vw, .95rem);
            line-height: 1.4;
            max-width: 320px;
            margin-left: auto;
            margin-right: auto;
            text-wrap: balance;
        }

        .familiar-linha { background: #f8fafc; border-radius: 14px; padding: 1rem; border: 1px solid #eef1f5; }
        .link-trocar { font-size: .82rem; color: #64748b; text-decoration: none; }
        .link-trocar:hover { color: var(--vinho-2); text-decoration: underline; }
        .resumo-nome-chip {
            display: inline-flex; align-items: center; gap: .3rem;
            background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0;
            border-radius: 999px; padding: .3rem .8rem; font-size: .82rem; font-weight: 600; margin: .2rem;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-dark bg-dark shadow-sm">
  <div class="container">
    <span class="navbar-brand mb-0">
      <img src="img/LOGO MEP NAV.svg" alt="Meu Evento PRO" style="height:40px;">
    </span>
  </div>
</nav>

<div class="container">
    <div class="rsvp-card">
        <div class="rsvp-topo">
            <?php if (!empty($evento['foto_casal_ativa']) && !empty($evento['foto_casal'])): ?>
                <img src="uploads/<?= htmlspecialchars($evento['foto_casal'], ENT_QUOTES, 'UTF-8') ?>" alt="Foto do casal" class="foto-casal">
            <?php else: ?>
                <div class="anel"><i class="bi bi-rings"></i></div>
            <?php endif; ?>
            <h2>Confirmação de Presença</h2>
            <div class="data-evento">
                Casamento de <strong><?= htmlspecialchars($evento['nome_cliente'], ENT_QUOTES, 'UTF-8') ?></strong>
                <?php if (!empty($evento['data_evento'])): ?>
                    · <?= date('d/m/Y', strtotime($evento['data_evento'])) ?>
                <?php endif; ?>
            </div>
            <?php if ($dias !== null && $dias >= 0): ?>
                <span class="contagem"><i class="bi bi-hourglass-split me-1"></i> <?= $dias === 0 ? 'É hoje!' : "Faltam {$dias} dias" ?></span>
            <?php endif; ?>
        </div>

        <div class="rsvp-corpo">

            <?php if ($erro): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
            <?php endif; ?>

            <!-- ESCOLHA INICIAL -->
            <div class="view" id="view-escolha">
                <p class="text-center text-muted mb-4 texto-convite-rsvp">
                    <?php if (!empty($evento['mensagem_convite'])): ?>
                        <?= nl2br(htmlspecialchars($evento['mensagem_convite'], ENT_QUOTES, 'UTF-8')) ?>
                    <?php else: $primeiro_nome = trim(explode(' ', trim($convidado_travado['nome']))[0]); ?>
                        Olá, <?= htmlspecialchars($primeiro_nome, ENT_QUOTES, 'UTF-8') ?>! Contamos com a sua presença! Você poderá comparecer ao nosso grande dia?
                    <?php endif; ?>
                </p>
                <div class="d-flex flex-column gap-3">
                    <button type="button" class="btn-escolha btn-sim" id="btn-ir-confirmar">
                        <i class="bi bi-emoji-heart-eyes-fill me-2"></i> Sim, poderei ir! 🎉
                        <small>Confirmar sua presença</small>
                    </button>
                    <button type="button" class="btn-escolha btn-nao" id="btn-ir-recusar">
                        <i class="bi bi-emoji-frown me-2"></i> Não poderei ir
                        <small>Avisar os noivos que não poderá comparecer</small>
                    </button>
                </div>
            </div>

            <!-- Pergunta individual sobre cada acompanhante já cadastrado.
                 Nome/telefone não são pedidos: o link já identifica o convidado direto. -->
            <?php if (!empty($acompanhantes_travados)): ?>
            <div class="view" id="view-especifico-acompanhantes">
                <p class="text-center text-muted mb-4">E os seus acompanhantes, também vão comparecer? Responda por cada um:</p>
                <div id="lista-perguntas-acompanhantes" class="d-flex flex-column gap-2 mb-4">
                    <?php foreach ($acompanhantes_travados as $a): ?>
                    <div class="familiar-linha d-flex justify-content-between align-items-center pergunta-acompanhante"
                         data-id="<?= (int)$a['id'] ?>"
                         data-nome="<?= htmlspecialchars($a['nome'], ENT_QUOTES, 'UTF-8') ?>"
                         data-faixa="<?= htmlspecialchars($a['faixa_etaria'], ENT_QUOTES, 'UTF-8') ?>">
                        <span class="fw-semibold"><?= htmlspecialchars($a['nome'], ENT_QUOTES, 'UTF-8') ?></span>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-success btn-acomp-sim">Sim</button>
                            <button type="button" class="btn btn-outline-secondary btn-acomp-nao">Não</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="d-grid">
                    <button type="button" id="btn-finalizar-especifico" class="btn btn-success btn-lg fw-bold">
                        <i class="bi bi-check-circle-fill me-1"></i> Confirmar Presença
                    </button>
                </div>
                <div class="text-center mt-3">
                    <a href="#" class="link-trocar" id="link-especifico-mudar-para-recusar">Mudar minha resposta</a>
                </div>
            </div>
            <?php endif; ?>

            <form id="form-especifico-final" method="POST" action="" hidden></form>

            <!-- SUCESSO -->
            <div class="view" id="view-sucesso">
                <?php if ($resposta_tipo === 'misto'): ?>
                    <div class="text-center py-3">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                        <h4 class="mt-3 text-success fw-bold">Respostas registradas!</h4>
                        <?php if (!empty($resposta_dados['sim'])): ?>
                            <p class="text-muted mb-1">Vão comparecer:</p>
                            <div class="mb-3">
                                <?php foreach ($resposta_dados['sim'] as $p): ?>
                                    <span class="resumo-nome-chip"><i class="bi bi-person-fill"></i> <?= htmlspecialchars($p['nome'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($resposta_dados['nao'])): ?>
                            <p class="text-muted mb-1">Não vão poder comparecer:</p>
                            <div class="mb-3">
                                <?php foreach ($resposta_dados['nao'] as $p): ?>
                                    <span class="resumo-nome-chip" style="background:#f1f5f9;color:#64748b;border-color:#e2e8f0;"><i class="bi bi-person-fill"></i> <?= htmlspecialchars($p['nome'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <p class="text-muted small">Obrigado por responder! 💍</p>
                    </div>
                <?php endif; ?>
                <div class="text-center mt-2">
                    <a href="#" class="link-trocar" id="link-alterar-do-sucesso">Errei algo, quero alterar minha resposta</a>
                </div>
            </div>

            <!-- RESUMO (preenchido via JS a partir do localStorage) -->
            <div class="view" id="view-resumo">
                <div class="text-center py-3">
                    <i class="bi bi-bookmark-check-fill text-primary" style="font-size: 3rem;"></i>
                    <h4 class="mt-3 fw-bold">Você já respondeu</h4>
                    <div id="resumo-conteudo" class="text-muted mt-2"></div>
                </div>
                <div class="d-grid mt-3">
                    <button type="button" class="btn btn-outline-secondary fw-bold" id="btn-alterar-resposta">
                        <i class="bi bi-pencil-fill me-1"></i> Alterar minha resposta
                    </button>
                </div>
            </div>

        </div>
    </div>

    <div class="text-center mt-3 text-white-50 small">
        Meu Evento PRO · Sistema de Gestão de Eventos
    </div>
</div>

<?php if ($sucesso): ?>
<script>
const RESPOSTA_SERVIDOR = <?= json_encode(['tipo' => $resposta_tipo, 'dados' => $resposta_dados], JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php endif; ?>

<script>
const CHAVE_LS  = 'rsvp_evento_<?= $evento_id ?>_t<?= (int)$convidado_travado['id'] ?>';
const CONVIDADO_TRAVADO = <?= json_encode([
    'id'       => (int)$convidado_travado['id'],
    'nome'     => $convidado_travado['nome'],
    'telefone' => $convidado_travado['telefone'],
], JSON_UNESCAPED_UNICODE) ?>;

function carregarLocal() {
    try { return JSON.parse(localStorage.getItem(CHAVE_LS)); } catch (e) { return null; }
}

function salvarLocal(obj) {
    try { localStorage.setItem(CHAVE_LS, JSON.stringify(obj)); } catch (e) {}
}

function mudarView(nome) {
    document.documentElement.setAttribute('data-view', nome);
}

function montarResumo(salvo) {
    const el = document.getElementById('resumo-conteudo');
    if (!salvo || salvo.tipo !== 'misto') { el.innerHTML = ''; return; }
    let html = '';
    const sim = salvo.dados.sim || [];
    const nao = salvo.dados.nao || [];
    if (sim.length) {
        const chips = sim.map(p => `<span class="resumo-nome-chip"><i class="bi bi-person-fill"></i> ${escapeHtml(p.nome)}</span>`).join('');
        html += `<p>Vão comparecer:</p><div>${chips}</div>`;
    }
    if (nao.length) {
        const chips2 = nao.map(p => `<span class="resumo-nome-chip" style="background:#f1f5f9;color:#64748b;border-color:#e2e8f0;"><i class="bi bi-person-fill"></i> ${escapeHtml(p.nome)}</span>`).join('');
        html += `<p class="mt-2">Não vão comparecer:</p><div>${chips2}</div>`;
    }
    el.innerHTML = html;
}

function escapeHtml(str) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(str || ''));
    return d.innerHTML;
}

/* ---- Link específico: a resposta do titular e de cada acompanhante é
   independente. O titular responde Sim/Não na tela inicial; se houver
   acompanhantes, cada um recebe seu próprio Sim/Não antes de enviar. ---- */
let respostaPrimarioTravado = null; // 'sim' | 'nao'

function registrarRespostaPrimario(resposta) {
    respostaPrimarioTravado = resposta;
    if (document.querySelectorAll('.pergunta-acompanhante').length > 0) {
        mudarView('especifico-acompanhantes');
    } else {
        enviarRespostaEspecifico();
    }
}

document.querySelectorAll('.pergunta-acompanhante').forEach(linha => {
    const btnSim = linha.querySelector('.btn-acomp-sim');
    const btnNao = linha.querySelector('.btn-acomp-nao');
    btnSim.addEventListener('click', () => {
        linha.dataset.resposta = 'sim';
        btnSim.classList.remove('btn-outline-success');
        btnSim.classList.add('btn-success');
        btnNao.classList.remove('btn-secondary');
        btnNao.classList.add('btn-outline-secondary');
    });
    btnNao.addEventListener('click', () => {
        linha.dataset.resposta = 'nao';
        btnNao.classList.remove('btn-outline-secondary');
        btnNao.classList.add('btn-secondary');
        btnSim.classList.remove('btn-success');
        btnSim.classList.add('btn-outline-success');
    });
});

document.getElementById('btn-finalizar-especifico')?.addEventListener('click', function () {
    const linhas = document.querySelectorAll('.pergunta-acompanhante');
    const pendente = Array.from(linhas).find(l => !l.dataset.resposta);
    if (pendente) {
        alert('Responda se ' + pendente.dataset.nome + ' também vai comparecer.');
        return;
    }
    enviarRespostaEspecifico();
});

function addHiddenInput(form, name, value) {
    const input = document.createElement('input');
    input.type  = 'hidden';
    input.name  = name;
    input.value = value;
    form.appendChild(input);
}

function enviarRespostaEspecifico() {
    const idsSim = [];
    const idsNao = [];

    if (respostaPrimarioTravado === 'nao') idsNao.push(CONVIDADO_TRAVADO.id);
    else idsSim.push(CONVIDADO_TRAVADO.id);

    document.querySelectorAll('.pergunta-acompanhante').forEach(l => {
        if (l.dataset.resposta === 'sim') idsSim.push(l.dataset.id);
        else if (l.dataset.resposta === 'nao') idsNao.push(l.dataset.id);
    });

    const form = document.getElementById('form-especifico-final');
    form.innerHTML = '';
    addHiddenInput(form, 'acao', 'resposta_especifico');
    idsSim.forEach(id => addHiddenInput(form, 'ids_sim[]', id));
    idsNao.forEach(id => addHiddenInput(form, 'ids_nao[]', id));
    form.submit();
}

/* ---- Navegação entre telas ---- */
document.getElementById('btn-ir-confirmar').addEventListener('click', () => registrarRespostaPrimario('sim'));
document.getElementById('btn-ir-recusar').addEventListener('click', () => registrarRespostaPrimario('nao'));

document.getElementById('link-especifico-mudar-para-recusar')?.addEventListener('click', e => { e.preventDefault(); mudarView('escolha'); });
document.getElementById('link-alterar-do-sucesso')?.addEventListener('click', e => {
    e.preventDefault();
    aplicarEdicao(carregarLocal());
});

document.getElementById('btn-alterar-resposta').addEventListener('click', () => {
    aplicarEdicao(carregarLocal());
});

function aplicarEdicao(salvo) {
    const idsSimAntes = (salvo && salvo.tipo === 'misto' ? (salvo.dados.sim || []) : []).map(p => String(p.id));
    const idsNaoAntes = (salvo && salvo.tipo === 'misto' ? (salvo.dados.nao || []) : []).map(p => String(p.id));
    const temAcompanhantes = document.querySelectorAll('.pergunta-acompanhante').length > 0;

    respostaPrimarioTravado = idsNaoAntes.includes(String(CONVIDADO_TRAVADO.id)) ? 'nao' : 'sim';

    document.querySelectorAll('.pergunta-acompanhante').forEach(l => {
        delete l.dataset.resposta;
        l.querySelector('.btn-acomp-sim').classList.remove('btn-success');
        l.querySelector('.btn-acomp-sim').classList.add('btn-outline-success');
        l.querySelector('.btn-acomp-nao').classList.remove('btn-secondary');
        l.querySelector('.btn-acomp-nao').classList.add('btn-outline-secondary');
        if (idsSimAntes.includes(l.dataset.id)) l.querySelector('.btn-acomp-sim').click();
        else if (idsNaoAntes.includes(l.dataset.id)) l.querySelector('.btn-acomp-nao').click();
    });

    // Sem acompanhantes não há nada pra reeditar além do próprio Sim/Não.
    mudarView(temAcompanhantes ? 'especifico-acompanhantes' : 'escolha');
}

/* ---- Estado inicial da página ---- */
(function () {
    const salvoAntes = carregarLocal();

    <?php if ($sucesso): ?>
        salvarLocal(RESPOSTA_SERVIDOR);
    <?php else: ?>
        if (salvoAntes) {
            montarResumo(salvoAntes);
        }
    <?php endif; ?>
})();
</script>

</body>
</html>
