<div class="modal fade" id="modalEditarModelo_<?= $modelo['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="salvar_edicao_modelo.php" class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Editar Meta do Modelo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id" value="<?= $modelo['id'] ?>">
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Tarefa</label>
                    <input type="text" name="tarefa" class="form-control" value="<?= htmlspecialchars($modelo['tarefa']) ?>" required>
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Descrição</label>
                    <textarea name="descricao" class="form-control" rows="4"><?= htmlspecialchars($modelo['descricao']) ?></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                <button type="submit" class="btn btn-primary">Salvar Alterações</button>
            </div>
        </form>
    </div>
</div>