<?php
/**
 * Tela do LINK GERAL de confirmação — pede o nome do convidado antes de
 * entrar no fluxo de sempre. Incluído por confirmar.php (via require) quando
 * a página é aberta sem &token=; espera as variáveis já prontas:
 * $evento, $evento_id, $cor_convite_1/2/3, $busca_nome, $busca_erro, $busca_candidatos.
 */
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmação de Presença<?= !empty($evento['nome_cliente']) ? ' — ' . htmlspecialchars($evento['nome_cliente'], ENT_QUOTES, 'UTF-8') : '' ?> - Meu Evento PRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --vinho-1: <?= $cor_convite_1 ?>;
            --vinho-2: <?= $cor_convite_2 ?>;
            --vinho-3: <?= $cor_convite_3 ?>;
        }
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, var(--vinho-1) 0%, var(--vinho-2) 50%, var(--vinho-3) 100%);
            font-family: 'Inter', system-ui, sans-serif;
        }
        body > .container { padding: 2.5rem 0; }
        .rsvp-card {
            max-width: 480px; margin: 0 auto; border: none; border-radius: 24px;
            overflow: hidden; box-shadow: 0 20px 50px rgba(0, 0, 0, .3);
            animation: fadeIn .5s ease both;
        }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .rsvp-topo { background: rgba(255, 255, 255, .08); color: #fff; text-align: center; padding: 2.2rem 1.5rem 1.6rem; }
        .rsvp-topo .anel { font-size: 2.4rem; opacity: .9; margin-bottom: .4rem; }
        .rsvp-topo h2 { font-weight: 800; margin-bottom: .2rem; letter-spacing: -.5px; }
        .rsvp-topo .data-evento { opacity: .85; font-size: .92rem; }
        .rsvp-corpo { background: #fff; padding: 2rem 1.75rem; }
        .candidato-btn { text-align: left; }
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
            <div class="anel"><i class="bi bi-rings"></i></div>
            <h2>Confirmação de Presença</h2>
            <div class="data-evento">
                Casamento de <strong><?= htmlspecialchars($evento['nome_cliente'], ENT_QUOTES, 'UTF-8') ?></strong>
                <?php if (!empty($evento['data_evento'])): ?>
                    · <?= date('d/m/Y', strtotime($evento['data_evento'])) ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="rsvp-corpo">
            <?php if (!empty($busca_candidatos)): ?>
                <p class="text-center text-muted mb-3">Encontramos mais de um convidado com esse nome. Qual é você?</p>
                <div class="d-flex flex-column gap-2 mb-3">
                    <?php foreach ($busca_candidatos as $c): ?>
                        <a class="btn btn-outline-secondary candidato-btn"
                           href="?evento=<?= $evento_id ?>&buscar=<?= urlencode($busca_nome) ?>&escolher=<?= (int)$c['id'] ?>">
                            <i class="bi bi-person-fill me-2"></i><?= htmlspecialchars($c['nome'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="text-center">
                    <a href="?evento=<?= $evento_id ?>" class="small text-muted">Não é nenhum desses, tentar outro nome</a>
                </div>
            <?php else: ?>
                <p class="text-center text-muted mb-4">Pra confirmar sua presença, digite seu nome completo exatamente como está no convite:</p>
                <?php if (!empty($busca_erro)): ?>
                    <div class="alert alert-danger small"><?= htmlspecialchars($busca_erro, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <form method="GET" action="">
                    <input type="hidden" name="evento" value="<?= $evento_id ?>">
                    <div class="mb-3">
                        <input type="text" name="buscar" class="form-control form-control-lg" placeholder="Seu nome completo"
                               value="<?= htmlspecialchars($busca_nome ?? '', ENT_QUOTES, 'UTF-8') ?>" required autofocus>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-success btn-lg fw-bold">
                            <i class="bi bi-search me-1"></i> Buscar meu convite
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="text-center mt-3 text-white-50 small">
        Meu Evento PRO · Sistema de Gestão de Eventos
    </div>
</div>

</body>
</html>
