<?php
/**
 * Camada central de acesso ao "tenant" (assessoria) atual.
 *
 * Ver PLANO-MULTI-TENANT.md (C:\docker\sis-casamento\PLANO-MULTI-TENANT.md)
 * e Cerebro_MAX/SisCasamento/08-DECISOES-TECNICAS/decisoes.md (2026-09-17/21)
 * para o desenho completo e o porquê da Opção B (banco único, coluna
 * assessoria_id) em vez de banco separado por assessoria.
 *
 * Este arquivo existe pra centralizar "qual é a assessoria da sessão atual"
 * num único lugar, em vez de cada página redescobrir isso sozinha — é o
 * primeiro tijolo da camada de acesso mencionada no plano (seção 4.3), que
 * no futuro também vira a base da API própria (Central/app mobile).
 *
 * IMPORTANTE — estado atual (2026-09-28): as colunas assessoria_id já
 * existem em todas as tabelas de dado operacional (ver conexao.php,
 * garantir_coluna_assessoria_id()), e todo dado existente já foi migrado pra
 * assessoria padrão. Mas NENHUMA página ainda foi alterada pra realmente
 * FILTRAR as consultas por assessoria_id — isso é a próxima etapa, ainda não
 * feita. Ou seja: hoje, mesmo com múltiplas assessorias cadastradas, o
 * sistema continuaria mostrando o dado de todo mundo pra todo mundo. Não
 * confiar neste arquivo como proteção de isolamento até essa etapa ser
 * concluída — ver seção "Próximos passos" no fim deste arquivo.
 */

/**
 * Id da assessoria da sessão atual, ou null se não houver uma definida
 * (ex.: sessão de 'desenvolvedor', que enxerga todas; ou sessão ainda não
 * migrada pro novo login que grava isso).
 */
function assessoria_atual(): ?int {
    $id = $_SESSION['assessoria_id'] ?? null;
    return $id !== null ? (int)$id : null;
}

/**
 * Verdadeiro só quando a sessão é de um 'desenvolvedor' (papel introduzido
 * pelo hub multi-módulo) — a única exceção que enxerga todas as assessorias
 * de propósito, nunca uma equipe/casal comum.
 */
function eh_sessao_cross_tenant(): bool {
    return ($_SESSION['usuario_tipo'] ?? '') === 'desenvolvedor';
}

/**
 * Busca o assessoria_id de um usuário de equipe (admin/assistente) direto no
 * banco — usado no login, antes de qualquer coisa ir pra sessão. Retorna null
 * pra tipo='desenvolvedor' (cross-tenant, de propósito) ou se não encontrado.
 */
function assessoria_id_do_usuario(PDO $pdo, int $usuario_id): ?int {
    $stmt = $pdo->prepare("SELECT assessoria_id, tipo FROM usuarios WHERE id = ?");
    $stmt->execute([$usuario_id]);
    $row = $stmt->fetch();
    if (!$row || $row['tipo'] === 'desenvolvedor') return null;
    return $row['assessoria_id'] !== null ? (int)$row['assessoria_id'] : null;
}

/**
 * Busca o assessoria_id de um cliente (casal) direto no banco — mesma lógica
 * de assessoria_id_do_usuario(), só que pra clientes.
 */
function assessoria_id_do_cliente(PDO $pdo, int $cliente_id): ?int {
    $stmt = $pdo->prepare("SELECT assessoria_id FROM clientes WHERE id = ?");
    $stmt->execute([$cliente_id]);
    $v = $stmt->fetchColumn();
    return $v !== false && $v !== null ? (int)$v : null;
}

/**
 * PRÓXIMOS PASSOS:
 *
 * 1. [FEITO 2026-09-28] index.php grava $_SESSION['assessoria_id'] no login,
 *    tanto pra equipe (admin/assistente, null pra 'desenvolvedor') quanto
 *    pro casal — direto da linha já buscada em `usuarios`/`clientes`.
 * 2. [PENDENTE — trabalho grande, ainda não iniciado] Cada página que
 *    lista/consulta dado operacional (eventos, checklist, convidados, notas,
 *    fornecedores...) precisa passar a incluir "AND assessoria_id = ?"
 *    (usando assessoria_atual()) em toda consulta — dezenas de arquivos,
 *    centenas de consultas escritas à mão, sem ORM. Fazer uma página de cada
 *    vez, testada isoladamente, nunca tudo de uma vez.
 * 3. [PENDENTE] Testar isolamento de verdade: criar uma segunda assessoria
 *    "QA Teste" com eventos/convidados próprios e confirmar, pelo sistema de
 *    verdade, que uma assessoria nunca vê nada da outra (Fase 3 do
 *    PLANO-MULTI-TENANT.md) — só depois do passo 2 acima estar pronto em
 *    cada página.
 */
