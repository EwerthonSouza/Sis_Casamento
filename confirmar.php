<?php
require_once 'conexao.php';

// Verifica se o ID do evento foi passado na URL
if (!isset($_GET['evento']) || empty($_GET['evento'])) {
    die("<div class='container mt-5 alert alert-danger'>Link inválido. Por favor, solicite o link correto com os noivos ou assessoria.</div>");
}

$evento_id = (int)$_GET['evento'];

// Busca os dados do evento para mostrar o nome no topo da página
$stmt = $pdo->prepare("SELECT e.*, c.nome AS nome_cliente FROM eventos e INNER JOIN clientes c ON e.cliente_id = c.id WHERE e.id = ?");
$stmt->execute([$evento_id]);
$evento = $stmt->fetch();

if (!$evento) {
    die("<div class='container mt-5 alert alert-danger'>Evento não encontrado.</div>");
}

$sucesso = false;

// Processa o formulário enviado pela família
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nomes = $_POST['nome_convidado'] ?? [];
    $faixas = $_POST['faixa_etaria'] ?? [];
    $telefones = $_POST['telefone_convidado'] ?? [];

    if (!empty($nomes)) {
        try {
            $pdo->beginTransaction();

            // Loop para salvar cada membro da família enviado
            for ($i = 0; $i < count($nomes); $i++) {
                if (trim($nomes[$i]) !== "") { // Ignora linhas vazias
                    $stmt = $pdo->prepare("INSERT INTO convidados (evento_id, nome, faixa_etaria, telefone, confirmado) VALUES (?, ?, ?, ?, 1)");
                    $stmt->execute([
                        $evento_id,
                        $nomes[$i],
                        $faixas[$i],
                        $telefones[$i]
                    ]);
                }
            }

            $pdo->commit();
            $sucesso = true;
        } catch (Exception $e) {
            $pdo->rollBack();
            $erro = "Erro ao processar sua confirmação: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmação de Presença</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body class="bg-light">

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            
            <div class="card shadow border-0 rounded-4 overflow-hidden">
                <div class="bg-primary p-4 text-white text-center">
                    <i class="bi bi-heart-fill fs-1 mb-2"></i>
                    <h3 class="mb-1">Confirmação de Presença</h3>
                    <p class="mb-0 opacity-75">Evento de: <strong><?= htmlspecialchars($evento['nome_cliente']) ?></strong></p>
                </div>

                <div class="card-body p-4">
                    <?php if ($sucesso): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                            <h4 class="mt-3 text-success">Presença Confirmada!</h4>
                            <p class="text-muted">Sua presença e de seus familiares foi registrada com sucesso no nosso sistema. Obrigado!</p>
                        </div>
                    <?php else: ?>
                        
                        <?php if (isset($erro)): ?>
                            <div class="alert alert-danger"><?= $erro ?></div>
                        <?php endif; ?>

                        <p class="text-center text-muted mb-4">Por favor, preencha abaixo o seu nome e de todos os membros da sua família que irão ao evento.</p>

                        <form method="POST" action="">
                            <div id="container-familia">
                                
                                <div class="row g-2 mb-3 align-items-end familiar-linha">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Nome Completo</label>
                                        <input type="text" name="nome_convidado[]" class="form-control" placeholder="Ex: João Silva" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small fw-bold">Faixa Etária</label>
                                        <select name="faixa_etaria[]" class="form-select">
                                            <option value="Adulto">Adulto</option>
                                            <option value="Criança">Criança (Buffet)</option>
                                            <option value="Bebê">Bebê (Colo)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small fw-bold">Telefone</label>
                                        <input type="text" name="telefone_convidado[]" class="form-control" placeholder="(00) 00000-0000">
                                    </div>
                                </div>

                            </div>

                            <div class="mb-4">
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="adicionarFamiliar()">
                                    <i class="bi bi-person-plus-fill"></i> Adicionar Acompanhante / Familiar
                                </button>
                            </div>

                            <hr>

                            <div class="d-grid mt-4">
                                <button type="submit" class="btn btn-success btn-lg">Confirmar Nossa Presença</button>
                            </div>
                        </form>

                    <?php endif; ?>
                </div>
            </div>

            <div class="text-center mt-3 text-muted small">
                Sistema de Gestão de Eventos desenvolvido em PHP.
            </div>

        </div>
    </div>
</div>

<script>
// Função em JavaScript para clonar a linha de cadastro e permitir múltiplos familiares
function adicionarFamiliar() {
    const container = document.getElementById('container-familia');
    
    // Cria um novo bloco de inputs para o familiar
    const novaLinha = document.createElement('div');
    novaLinha.className = 'row g-2 mb-3 align-items-end familiar-linha border-top pt-3 mt-3';
    
    novaLinha.innerHTML = `
        <div class="col-md-5">
            <label class="form-label small fw-bold text-secondary">Nome do Acompanhante</label>
            <input type="text" name="nome_convidado[]" class="form-control" placeholder="Ex: Maria Silva" required>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold text-secondary">Faixa Etária</label>
            <select name="faixa_etaria[]" class="form-select">
                <option value="Adulto">Adulto</option>
                <option value="Criança">Criança (Buffet)</option>
                <option value="Bebê">Bebê (Colo)</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold text-secondary">Telefone (Opcional)</label>
            <input type="text" name="telefone_convidado[]" class="form-control" placeholder="(00) 00000-0000">
        </div>
        <div class="col-md-1 text-end">
            <button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="removerFamiliar(this)">
                <i class="bi bi-trash-fill"></i>
            </button>
        </div>
    `;
    
    container.appendChild(novaLinha);
}

// Função para remover a linha criada caso o usuário desista
function removerFamiliar(botao) {
    const linha = botao.closest('.familiar-linha');
    linha.remove();
}
</script>

</body>
</html>