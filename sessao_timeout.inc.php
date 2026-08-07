<?php
/* ============================================================
   CONTROLE DE INATIVIDADE
   Encerra a sessão automaticamente após um período sem uso e
   redireciona ao login com aviso. Chamar logo após session_start(),
   antes de qualquer checagem de $_SESSION['usuario_tipo'].
   ============================================================ */

define('TEMPO_LIMITE_SESSAO', 30 * 60); // 30 minutos

function verificar_sessao_ativa(): void {
    if (!isset($_SESSION['usuario_tipo'])) {
        return;
    }

    if (isset($_SESSION['ultima_atividade']) && (time() - $_SESSION['ultima_atividade']) > TEMPO_LIMITE_SESSAO) {
        $_SESSION = [];
        session_destroy();
        header("Location: index.php?sessao_expirada=1");
        exit;
    }

    $_SESSION['ultima_atividade'] = time();
}
