<?php
// Modal "Meu site do casamento" mostrado no primeiro clique (e enquanto o acesso não for liberado pela Central).
// Espera: $site_acesso (site_acesso()), $evento_id, $csrf_token. Incluído nos painéis do casal e da assessoria.
$gate_status = $site_acesso['status'] ?? 'bloqueado';
$gate_preco = number_format(site_preco(), 2, ',', '.');
$gate_url_modelo = site_base_path() . '/convite/modelo';
?>
<div class="modal fade" id="modalSiteGate" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content" style="border:0;border-radius:22px;overflow:hidden">
      <div style="background:linear-gradient(135deg,#a9744f,#7f5535);color:#fff;padding:1.4rem 1.5rem">
        <div style="font-size:.7rem;letter-spacing:.2em;text-transform:uppercase;opacity:.85">Item opcional</div>
        <h5 class="fw-bold mb-1" style="font-size:1.35rem"><i class="bi bi-globe2 me-2"></i>Meu Site do Casamento</h5>
        <div style="opacity:.92;font-size:.92rem">Um convite online exclusivo, com a cara do casal.</div>
      </div>
      <div class="modal-body" style="padding:1.4rem 1.5rem">
        <p class="mb-3" style="font-size:.95rem">Em vez de só um link de confirmação, vocês ganham uma <strong>página completa do casamento</strong>, pronta para compartilhar com os convidados:</p>
        <ul class="list-unstyled mb-3" style="font-size:.9rem">
          <li class="d-flex gap-2 mb-1"><i class="bi bi-check-circle-fill text-success mt-1"></i><span>Capa com os nomes, contagem regressiva e foto do casal</span></li>
          <li class="d-flex gap-2 mb-1"><i class="bi bi-check-circle-fill text-success mt-1"></i><span>Nossa história, programação com mapa/Waze, dress code e hospedagem</span></li>
          <li class="d-flex gap-2 mb-1"><i class="bi bi-check-circle-fill text-success mt-1"></i><span>Galeria de fotos e mural de recados (vocês aprovam antes de aparecer)</span></li>
          <li class="d-flex gap-2 mb-1"><i class="bi bi-check-circle-fill text-success mt-1"></i><span>Lista de presentes com cotas e Pix</span></li>
          <li class="d-flex gap-2 mb-1"><i class="bi bi-check-circle-fill text-success mt-1"></i><span>Botão de confirmação de presença integrado ao sistema</span></li>
        </ul>
        <div class="rounded-4 p-3 mb-3" style="background:#faf5f0;border:1px solid #eadfd5">
          <div class="fw-bold mb-1" style="font-size:.9rem">Como funciona</div>
          <ol class="mb-0 ps-3" style="font-size:.86rem;line-height:1.55">
            <li>Veja o <strong>modelo</strong> pronto para entender como fica.</li>
            <li>Clique em <strong>Quero contratar</strong> para enviar o pedido.</li>
            <li>Depois da <strong>confirmação do pagamento</strong>, o botão "Meu site" é liberado e o editor já vem preenchido com o modelo, é só trocar textos e fotos.</li>
          </ol>
        </div>
        <div class="d-flex justify-content-between align-items-center">
          <div><div class="text-muted" style="font-size:.75rem">Valor único</div><div class="fw-bold" style="font-size:1.7rem;color:#7f5535;line-height:1">R$ <?= $gate_preco ?></div></div>
          <a href="<?= htmlspecialchars($gate_url_modelo, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-outline-dark rounded-pill fw-bold"><i class="bi bi-eye me-1"></i> Ver modelo</a>
        </div>
        <div id="siteGateMsg" class="small mt-3"></div>
      </div>
      <div class="modal-footer border-0 pt-0" style="padding:0 1.5rem 1.4rem">
        <button type="button" class="btn btn-light rounded-pill fw-bold" data-bs-dismiss="modal">Agora não</button>
        <button type="button" id="siteGateContratar" class="btn rounded-pill fw-bold text-white px-4" style="background:linear-gradient(135deg,#2f9e5b,#1e7a44)" <?= $gate_status === 'solicitado' ? 'disabled' : '' ?>>
          <?php if ($gate_status === 'solicitado'): ?><i class="bi bi-hourglass-split me-1"></i> Aguardando pagamento<?php else: ?><i class="bi bi-bag-check me-1"></i> Quero contratar<?php endif; ?>
        </button>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
    var modalEl = document.getElementById('modalSiteGate');
    if (!modalEl || typeof bootstrap === 'undefined') return;
    var modal = new bootstrap.Modal(modalEl), msg = document.getElementById('siteGateMsg'), btn = document.getElementById('siteGateContratar');
    document.querySelectorAll('[data-site-gate]').forEach(function (a) {
        a.addEventListener('click', function (e) { e.preventDefault(); modal.show(); });
    });
    <?php if ($gate_status === 'solicitado'): ?>
    msg.innerHTML = '<span class="text-success fw-bold"><i class="bi bi-check-circle"></i> Pedido enviado.</span> Estamos aguardando a confirmação do pagamento para liberar o seu site.';
    <?php endif; ?>
    btn.addEventListener('click', function () {
        btn.disabled = true; msg.textContent = 'Enviando pedido...'; msg.className = 'small mt-3 text-muted';
        var f = new FormData(); f.append('csrf_token', <?= json_encode($csrf_token) ?>); f.append('evento_id', <?= (int)$evento_id ?>);
        fetch('site_solicitar.php', { method: 'POST', body: f }).then(function (r) { return r.json(); }).catch(function () { return { ok: false, msg: 'Falha de conexão. Tente de novo.' }; })
        .then(function (r) {
            if (!r.ok) { btn.disabled = false; msg.className = 'small mt-3 text-danger fw-bold'; msg.textContent = r.msg; return; }
            msg.className = 'small mt-3 text-success fw-bold'; msg.innerHTML = '<i class="bi bi-check-circle"></i> ' + r.msg;
            btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Aguardando pagamento';
            if (r.status === 'liberado') location.reload();
        });
    });
})();
</script>
