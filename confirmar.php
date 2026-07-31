<?php
require_once 'conexao.php';

if (!isset($_GET['evento']) || empty($_GET['evento'])) {
    die("<div class='container mt-5 alert alert-danger'>Link inválido. Por favor, solicite o link correto com os noivos ou assessoria.</div>");
}
$evento_id = (int)$_GET['evento'];

// Migração: colunas usadas pelo formulário público de RSVP
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

const FAIXAS_ETARIAS = [
    'Criança de Colo (0-5 anos)',
    'Criança (6-10 anos)',
    'Adulto (11+ anos)',
];

/**
 * Tenta achar, neste evento, uma "vaga" ainda sem resposta (resposta_rsvp IS NULL) que
 * corresponda a uma resposta sem id do navegador (link aberto de novo em outro aparelho,
 * cache limpo, ou nome já cadastrado manualmente pela assessoria). Só considera convidados
 * que ainda NÃO responderam, pra nunca sobrescrever quem já confirmou/recusou por engano —
 * se dois convidados diferentes tiverem o mesmo nome, casar por nome poderia juntar as duas
 * pessoas; restringindo às vagas pendentes, o pior caso vira um registro duplicado (visível
 * e fácil de mesclar pela assessoria), nunca a perda silenciosa da resposta de outra pessoa.
 * Se houver mais de uma vaga pendente com o mesmo nome, exige telefone batendo com
 * exatamente uma delas pra desambiguar; sem isso, retorna null (cria um registro novo).
 */
function buscarConvidadoPorNome(PDO $pdo, int $evento_id, string $nome, string $telefone, array $idsExcluir): ?int {
    if (empty($idsExcluir)) {
        $stmt = $pdo->prepare("SELECT id, telefone FROM convidados WHERE evento_id = ? AND resposta_rsvp IS NULL AND LOWER(TRIM(nome)) = LOWER(TRIM(?))");
        $stmt->execute([$evento_id, $nome]);
    } else {
        $ph = implode(',', array_fill(0, count($idsExcluir), '?'));
        $stmt = $pdo->prepare("SELECT id, telefone FROM convidados WHERE evento_id = ? AND resposta_rsvp IS NULL AND LOWER(TRIM(nome)) = LOWER(TRIM(?)) AND id NOT IN ($ph)");
        $stmt->execute(array_merge([$evento_id, $nome], $idsExcluir));
    }
    $candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($candidatos) === 1) {
        return (int)$candidatos[0]['id'];
    }

    if (count($candidatos) > 1 && $telefone !== '') {
        $comTelefoneIgual = array_values(array_filter($candidatos, function ($c) use ($telefone) {
            return trim((string)$c['telefone']) !== '' && trim((string)$c['telefone']) === $telefone;
        }));
        if (count($comTelefoneIgual) === 1) {
            return (int)$comTelefoneIgual[0]['id'];
        }
    }

    return null;
}

$stmt = $pdo->prepare("SELECT e.*, c.nome AS nome_cliente FROM eventos e INNER JOIN clientes c ON e.cliente_id = c.id WHERE e.id = ?");
$stmt->execute([$evento_id]);
$evento = $stmt->fetch();

if (!$evento) {
    die("<div class='container mt-5 alert alert-danger'>Evento não encontrado.</div>");
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
    $acao        = $_POST['acao'] ?? '';
    $ids_antigos = array_filter(array_map('intval', explode(',', $_POST['ids_todos_anteriores'] ?? '')));

    try {
        $pdo->beginTransaction();
        $ids_mantidos = [];

        if ($acao === 'confirmar') {
            $nomes      = $_POST['nome_convidado']     ?? [];
            $faixas     = $_POST['faixa_etaria']       ?? [];
            $telefones  = $_POST['telefone_convidado'] ?? [];
            $id_ant_arr = $_POST['id_anterior']        ?? [];

            for ($i = 0; $i < count($nomes); $i++) {
                $nome = trim($nomes[$i]);
                if ($nome === '') continue;

                $faixa = in_array($faixas[$i] ?? '', FAIXAS_ETARIAS, true) ? $faixas[$i] : FAIXAS_ETARIAS[2];
                $tel   = trim($telefones[$i] ?? '');
                $idAnt = (int)($id_ant_arr[$i] ?? 0);
                $atualizou = false;

                if ($idAnt > 0) {
                    $chk = $pdo->prepare("SELECT id FROM convidados WHERE id = ? AND evento_id = ?");
                    $chk->execute([$idAnt, $evento_id]);
                    if ($chk->fetch()) {
                        $pdo->prepare("UPDATE convidados SET nome = ?, faixa_etaria = ?, telefone = ?, confirmado = 1, resposta_rsvp = 'confirmado', mensagem_rsvp = NULL, data_confirmacao = NOW() WHERE id = ? AND evento_id = ?")
                            ->execute([$nome, $faixa, $tel, $idAnt, $evento_id]);
                        $ids_mantidos[]    = $idAnt;
                        $resposta_dados[]  = ['id' => $idAnt, 'nome' => $nome, 'faixa_etaria' => $faixa, 'telefone' => $tel];
                        $atualizou = true;
                    }
                }

                // Sem id do navegador: tenta casar por nome (só quando único no evento, ou
                // desambiguado por telefone) antes de criar um registro novo.
                if (!$atualizou) {
                    $idExistente = buscarConvidadoPorNome($pdo, $evento_id, $nome, $tel, $ids_mantidos);
                    if ($idExistente !== null) {
                        $pdo->prepare("UPDATE convidados SET nome = ?, faixa_etaria = ?, telefone = ?, confirmado = 1, resposta_rsvp = 'confirmado', mensagem_rsvp = NULL, data_confirmacao = NOW() WHERE id = ? AND evento_id = ?")
                            ->execute([$nome, $faixa, $tel, $idExistente, $evento_id]);
                        $ids_mantidos[]   = $idExistente;
                        $resposta_dados[] = ['id' => $idExistente, 'nome' => $nome, 'faixa_etaria' => $faixa, 'telefone' => $tel];
                        $atualizou = true;
                    }
                }

                if (!$atualizou) {
                    $pdo->prepare("INSERT INTO convidados (evento_id, nome, faixa_etaria, telefone, confirmado, resposta_rsvp, data_confirmacao) VALUES (?, ?, ?, ?, 1, 'confirmado', NOW())")
                        ->execute([$evento_id, $nome, $faixa, $tel]);
                    $novoId = (int)$pdo->lastInsertId();
                    $ids_mantidos[]   = $novoId;
                    $resposta_dados[] = ['id' => $novoId, 'nome' => $nome, 'faixa_etaria' => $faixa, 'telefone' => $tel];
                }
            }

            if (empty($resposta_dados)) {
                throw new Exception('validacao:Informe ao menos um nome.');
            }
            $resposta_tipo = 'confirmado';

        } elseif ($acao === 'recusar') {
            $nome  = trim($_POST['nome_responsavel'] ?? '');
            $msg   = trim($_POST['mensagem_recusa']   ?? '');
            $idAnt = (int)($_POST['id_anterior_recusa'] ?? 0);

            if ($nome === '') {
                throw new Exception('validacao:Informe o nome do responsável.');
            }

            if ($idAnt > 0) {
                $chk = $pdo->prepare("SELECT id FROM convidados WHERE id = ? AND evento_id = ?");
                $chk->execute([$idAnt, $evento_id]);
                if ($chk->fetch()) {
                    $pdo->prepare("UPDATE convidados SET nome = ?, confirmado = 0, resposta_rsvp = 'recusado', mensagem_rsvp = ?, data_confirmacao = NOW() WHERE id = ? AND evento_id = ?")
                        ->execute([$nome, $msg !== '' ? $msg : null, $idAnt, $evento_id]);
                    $ids_mantidos[] = $idAnt;
                }
            }

            // Mesmo reforço do fluxo de confirmação: sem id do navegador, tenta casar
            // por nome (só quando único no evento) antes de criar um registro novo.
            if (empty($ids_mantidos)) {
                $idExistente = buscarConvidadoPorNome($pdo, $evento_id, $nome, '', []);
                if ($idExistente !== null) {
                    $pdo->prepare("UPDATE convidados SET nome = ?, confirmado = 0, resposta_rsvp = 'recusado', mensagem_rsvp = ?, data_confirmacao = NOW() WHERE id = ? AND evento_id = ?")
                        ->execute([$nome, $msg !== '' ? $msg : null, $idExistente, $evento_id]);
                    $ids_mantidos[] = $idExistente;
                }
            }

            if (empty($ids_mantidos)) {
                $pdo->prepare("INSERT INTO convidados (evento_id, nome, faixa_etaria, confirmado, resposta_rsvp, mensagem_rsvp, data_confirmacao) VALUES (?, ?, ?, 0, 'recusado', ?, NOW())")
                    ->execute([$evento_id, $nome, FAIXAS_ETARIAS[2], $msg !== '' ? $msg : null]);
                $ids_mantidos[] = (int)$pdo->lastInsertId();
            }

            $resposta_dados = ['nome' => $nome, 'mensagem' => $msg];
            $resposta_tipo  = 'recusado';

        } else {
            throw new Exception('validacao:Ação inválida.');
        }

        // Remove registros antigos desta mesma "resposta" que não foram reaproveitados
        $ids_remover = array_diff($ids_antigos, $ids_mantidos);
        if (!empty($ids_remover)) {
            $ph     = implode(',', array_fill(0, count($ids_remover), '?'));
            $params = array_merge(array_values($ids_remover), [$evento_id]);
            $pdo->prepare("DELETE FROM convidados WHERE id IN ($ph) AND evento_id = ?")->execute($params);
        }

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
<html lang="pt-br" data-view="<?= $sucesso ? 'sucesso' : 'auto' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmação de Presença<?= !empty($evento['nome_cliente']) ? ' — ' . htmlspecialchars($evento['nome_cliente'], ENT_QUOTES, 'UTF-8') : '' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="css/estilo.css?v=3">
    <script>
        // Decide qual "view" mostrar antes da página pintar, evitando flash de conteúdo errado.
        (function () {
            var chave = 'rsvp_evento_<?= $evento_id ?>';
            var salvo = null;
            try { salvo = JSON.parse(localStorage.getItem(chave)); } catch (e) {}
            var modo = document.documentElement.getAttribute('data-view');
            if (modo === 'auto') { modo = salvo ? 'resumo' : 'escolha'; }
            document.documentElement.setAttribute('data-view', modo);
        })();
    </script>
    <style>
        :root {
            --vinho-1: #6f4a2f;
            --vinho-2: #8b5e3c;
            --vinho-3: #a9744f;
        }
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, var(--vinho-1) 0%, var(--vinho-2) 50%, var(--vinho-3) 100%);
            font-family: 'Inter', system-ui, sans-serif;
            padding: 2.5rem 0;
        }
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
        html[data-view="form-confirmar"] #view-form-confirmar  { display: block; }
        html[data-view="form-recusar"]   #view-form-recusar    { display: block; }
        html[data-view="sucesso"]        #view-sucesso         { display: block; }

        .btn-escolha {
            display: block; width: 100%; border: none; border-radius: 16px;
            padding: 1.3rem 1rem; font-weight: 800; font-size: 1.05rem;
            transition: transform .15s, box-shadow .15s; text-align: left;
        }
        .btn-escolha:hover { transform: translateY(-2px); }
        .btn-escolha small { display: block; font-weight: 500; opacity: .85; font-size: .78rem; margin-top: .15rem; }
        .btn-sim { background: linear-gradient(135deg, #16a34a, #22c55e); color: #fff; }
        .btn-nao { background: #f8fafc; color: #64748b; border: 1.5px solid #e2e8f0 !important; }

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

<div class="container">
    <div class="rsvp-card">
        <div class="rsvp-topo">
            <div class="anel"><i class="bi bi-rings"></i></div>
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
                <p class="text-center text-muted mb-4">Contamos com a sua presença! Você e sua família poderão comparecer ao nosso grande dia?</p>
                <div class="d-flex flex-column gap-3">
                    <button type="button" class="btn-escolha btn-sim" id="btn-ir-confirmar">
                        <i class="bi bi-emoji-heart-eyes-fill me-2"></i> Sim, poderemos ir! 🎉
                        <small>Confirmar presença da família</small>
                    </button>
                    <button type="button" class="btn-escolha btn-nao" id="btn-ir-recusar">
                        <i class="bi bi-emoji-frown me-2"></i> Não poderemos ir
                        <small>Avisar os noivos que não poderá comparecer</small>
                    </button>
                </div>
            </div>

            <!-- FORMULÁRIO: CONFIRMAR -->
            <div class="view" id="view-form-confirmar">
                <p class="text-center text-muted mb-4">Que alegria! Preencha o nome de todos os membros da família que irão ao evento.</p>
                <form method="POST" action="" id="form-confirmar">
                    <input type="hidden" name="acao" value="confirmar">
                    <input type="hidden" name="ids_todos_anteriores" id="ids-todos-anteriores-conf" value="">

                    <div id="container-familia" class="d-flex flex-column gap-2 mb-3">
                        <div class="familiar-linha row g-2 align-items-end">
                            <input type="hidden" name="id_anterior[]" class="campo-id-anterior" value="">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Nome Completo</label>
                                <input type="text" name="nome_convidado[]" class="form-control campo-nome" placeholder="Ex: João Silva" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold">Faixa Etária</label>
                                <select name="faixa_etaria[]" class="form-select campo-faixa">
                                    <option value="Criança de Colo (0-5 anos)">Criança de Colo (0-5 anos)</option>
                                    <option value="Criança (6-10 anos)">Criança (6-10 anos)</option>
                                    <option value="Adulto (11+ anos)" selected>Adulto (11+ anos)</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold">Telefone</label>
                                <input type="text" name="telefone_convidado[]" class="form-control campo-telefone" placeholder="(00) 00000-0000">
                            </div>
                        </div>
                    </div>

                    <button type="button" class="btn btn-outline-secondary btn-sm mb-4" id="btn-add-familiar">
                        <i class="bi bi-person-plus-fill"></i> Adicionar Acompanhante / Familiar
                    </button>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-success btn-lg fw-bold">
                            <i class="bi bi-check-circle-fill me-1"></i> Confirmar Nossa Presença
                        </button>
                    </div>
                    <div class="text-center mt-3">
                        <a href="#" class="link-trocar" id="link-mudar-para-recusar">Na verdade, não poderemos comparecer</a>
                    </div>
                </form>
            </div>

            <!-- FORMULÁRIO: RECUSAR -->
            <div class="view" id="view-form-recusar">
                <p class="text-center text-muted mb-4">Sentiremos sua falta! Se puder, deixe uma mensagem carinhosa para os noivos.</p>
                <form method="POST" action="" id="form-recusar">
                    <input type="hidden" name="acao" value="recusar">
                    <input type="hidden" name="ids_todos_anteriores" id="ids-todos-anteriores-rec" value="">
                    <input type="hidden" name="id_anterior_recusa" id="id-anterior-recusa" value="">

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Seu nome</label>
                        <input type="text" name="nome_responsavel" id="campo-nome-responsavel" class="form-control" placeholder="Ex: João Silva" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label small fw-bold">Mensagem para os noivos (opcional)</label>
                        <textarea name="mensagem_recusa" id="campo-mensagem-recusa" class="form-control" rows="3" placeholder="Deixe um recadinho carinhoso..."></textarea>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-dark btn-lg fw-bold">
                            <i class="bi bi-send-fill me-1"></i> Enviar Resposta
                        </button>
                    </div>
                    <div class="text-center mt-3">
                        <a href="#" class="link-trocar" id="link-mudar-para-confirmar">Na verdade, poderemos comparecer!</a>
                    </div>
                </form>
            </div>

            <!-- SUCESSO -->
            <div class="view" id="view-sucesso">
                <?php if ($resposta_tipo === 'confirmado'): ?>
                    <div class="text-center py-3">
                        <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                        <h4 class="mt-3 text-success fw-bold">Presença Confirmada!</h4>
                        <p class="text-muted">Registramos a presença de:</p>
                        <div class="mb-3">
                            <?php foreach ($resposta_dados as $p): ?>
                                <span class="resumo-nome-chip"><i class="bi bi-person-fill"></i> <?= htmlspecialchars($p['nome'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-muted small">Mal podemos esperar para celebrar com vocês! 💍</p>
                    </div>
                <?php elseif ($resposta_tipo === 'recusado'): ?>
                    <div class="text-center py-3">
                        <i class="bi bi-heart text-danger" style="font-size: 4rem;"></i>
                        <h4 class="mt-3 fw-bold">Resposta registrada</h4>
                        <p class="text-muted">Obrigado por avisar, <?= htmlspecialchars($resposta_dados['nome'] ?? '', ENT_QUOTES, 'UTF-8') ?>. Sentiremos sua falta!</p>
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
        Sistema de Gestão de Eventos
    </div>
</div>

<?php if ($sucesso): ?>
<script>
const RESPOSTA_SERVIDOR = <?= json_encode(['tipo' => $resposta_tipo, 'dados' => $resposta_dados], JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php endif; ?>

<script>
const CHAVE_LS  = 'rsvp_evento_<?= $evento_id ?>';
let idsAnterioresGlobal = [];

function carregarLocal() {
    try { return JSON.parse(localStorage.getItem(CHAVE_LS)); } catch (e) { return null; }
}

function salvarLocal(obj) {
    try { localStorage.setItem(CHAVE_LS, JSON.stringify(obj)); } catch (e) {}
}

function mudarView(nome) {
    document.documentElement.setAttribute('data-view', nome);
}

function idsDoSalvo(salvo) {
    if (!salvo) return [];
    if (salvo.tipo === 'confirmado') return (salvo.dados || []).map(p => p.id).filter(Boolean);
    if (salvo.tipo === 'recusado' && salvo.dados && salvo.dados.id) return [salvo.dados.id];
    return [];
}

function montarResumo(salvo) {
    const el = document.getElementById('resumo-conteudo');
    if (!salvo) { el.innerHTML = ''; return; }
    if (salvo.tipo === 'confirmado') {
        const chips = (salvo.dados || []).map(p =>
            `<span class="resumo-nome-chip"><i class="bi bi-person-fill"></i> ${escapeHtml(p.nome)}</span>`
        ).join('');
        el.innerHTML = `<p>Você confirmou presença de:</p><div>${chips}</div>`;
    } else if (salvo.tipo === 'recusado') {
        el.innerHTML = `<p>Você avisou que <strong>${escapeHtml(salvo.dados.nome || '')}</strong> não poderá comparecer.</p>`;
    }
}

function escapeHtml(str) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(str || ''));
    return d.innerHTML;
}

/* ---- Repetidor de familiares (confirmar) ---- */
function novaLinhaFamiliar(dados) {
    dados = dados || {};
    const div = document.createElement('div');
    div.className = 'familiar-linha row g-2 align-items-end';
    div.innerHTML = `
        <input type="hidden" name="id_anterior[]" class="campo-id-anterior" value="${dados.id || ''}">
        <div class="col-md-5">
            <label class="form-label small fw-bold text-secondary">Nome do Acompanhante</label>
            <input type="text" name="nome_convidado[]" class="form-control campo-nome" placeholder="Ex: Maria Silva" required>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold text-secondary">Faixa Etária</label>
            <select name="faixa_etaria[]" class="form-select campo-faixa">
                <option value="Criança de Colo (0-5 anos)">Criança de Colo (0-5 anos)</option>
                <option value="Criança (6-10 anos)">Criança (6-10 anos)</option>
                <option value="Adulto (11+ anos)" selected>Adulto (11+ anos)</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold text-secondary">Telefone (Opcional)</label>
            <input type="text" name="telefone_convidado[]" class="form-control campo-telefone" placeholder="(00) 00000-0000">
        </div>
        <div class="col-md-1 text-end">
            <button type="button" class="btn btn-outline-danger btn-sm w-100 btn-remover-familiar">
                <i class="bi bi-trash-fill"></i>
            </button>
        </div>
    `;
    div.querySelector('.campo-nome').value     = dados.nome || '';
    div.querySelector('.campo-faixa').value    = dados.faixa_etaria || 'Adulto (11+ anos)';
    div.querySelector('.campo-telefone').value = dados.telefone || '';
    div.querySelector('.btn-remover-familiar').addEventListener('click', () => div.remove());
    return div;
}

document.getElementById('btn-add-familiar').addEventListener('click', () => {
    document.getElementById('container-familia').appendChild(novaLinhaFamiliar());
});

function preencherFormConfirmar(lista) {
    const container = document.getElementById('container-familia');
    container.innerHTML = '';
    (lista && lista.length ? lista : [{}]).forEach(p => container.appendChild(novaLinhaFamiliar(p)));
}

/* ---- Navegação entre telas ---- */
document.getElementById('btn-ir-confirmar').addEventListener('click', () => mudarView('form-confirmar'));
document.getElementById('btn-ir-recusar').addEventListener('click', () => mudarView('form-recusar'));

document.getElementById('link-mudar-para-recusar').addEventListener('click', e => { e.preventDefault(); mudarView('form-recusar'); });
document.getElementById('link-mudar-para-confirmar').addEventListener('click', e => { e.preventDefault(); mudarView('form-confirmar'); });
document.getElementById('link-alterar-do-sucesso')?.addEventListener('click', e => {
    e.preventDefault();
    const salvo = carregarLocal();
    aplicarEdicao(salvo);
});

document.getElementById('btn-alterar-resposta').addEventListener('click', () => {
    aplicarEdicao(carregarLocal());
});

function aplicarEdicao(salvo) {
    idsAnterioresGlobal = idsDoSalvo(salvo);
    const idsStr = idsAnterioresGlobal.join(',');
    document.getElementById('ids-todos-anteriores-conf').value = idsStr;
    document.getElementById('ids-todos-anteriores-rec').value  = idsStr;

    if (salvo && salvo.tipo === 'recusado') {
        document.getElementById('id-anterior-recusa').value = salvo.dados.id || '';
        document.getElementById('campo-nome-responsavel').value = salvo.dados.nome || '';
        document.getElementById('campo-mensagem-recusa').value  = salvo.dados.mensagem || '';
        mudarView('form-recusar');
    } else {
        preencherFormConfirmar(salvo && salvo.tipo === 'confirmado' ? salvo.dados : []);
        mudarView('form-confirmar');
    }
}

/* ---- Ao enviar, garante que os ids antigos vão junto (mesmo sem passar por "alterar") ---- */
document.getElementById('form-confirmar').addEventListener('submit', () => {
    document.getElementById('ids-todos-anteriores-conf').value = idsAnterioresGlobal.join(',');
});
document.getElementById('form-recusar').addEventListener('submit', () => {
    document.getElementById('ids-todos-anteriores-rec').value = idsAnterioresGlobal.join(',');
});

/* ---- Estado inicial da página ---- */
(function () {
    const salvoAntes = carregarLocal();

    <?php if ($sucesso): ?>
        salvarLocal(RESPOSTA_SERVIDOR);
    <?php else: ?>
        if (salvoAntes) {
            idsAnterioresGlobal = idsDoSalvo(salvoAntes);
            montarResumo(salvoAntes);
        }
    <?php endif; ?>
})();
</script>

</body>
</html>
