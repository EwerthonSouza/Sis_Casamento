-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Tempo de geração: 09/06/2026 às 04:19
-- Versão do servidor: 10.4.32-MariaDB
-- Versão do PHP: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `sistema_eventos`
--

-- --------------------------------------------------------

--
-- Estrutura para tabela `administradores`
--

CREATE TABLE `administradores` (
  `id` int(11) NOT NULL,
  `usuario` varchar(50) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `ultimo_login` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `calendario_anotacoes`
--

CREATE TABLE `calendario_anotacoes` (
  `id` int(11) NOT NULL,
  `data_nota` date NOT NULL,
  `anotacao` text NOT NULL,
  `data_cadastro` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `checklist`
--

CREATE TABLE `checklist` (
  `id` int(11) NOT NULL,
  `evento_id` int(11) NOT NULL,
  `etapa` varchar(100) DEFAULT 'PASSO 01',
  `tarefa` varchar(255) NOT NULL,
  `descricao` text DEFAULT NULL,
  `origem` varchar(50) DEFAULT 'Assessoria',
  `checado` tinyint(1) DEFAULT 0,
  `observacoes` text DEFAULT NULL,
  `fornecedores_sugeridos` text DEFAULT NULL,
  `status` varchar(20) DEFAULT 'pendente'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `checklist_comentarios`
--

CREATE TABLE `checklist_comentarios` (
  `id` int(11) NOT NULL,
  `checklist_id` int(11) DEFAULT NULL,
  `autor` enum('Assessoria','Noivos') NOT NULL,
  `comentario` text NOT NULL,
  `data_cadastro` timestamp NOT NULL DEFAULT current_timestamp(),
  `etapa_nome` varchar(100) DEFAULT NULL,
  `data_criacao` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `checklist_modelos`
--

CREATE TABLE `checklist_modelos` (
  `id` int(11) NOT NULL,
  `tipo_padrao` varchar(50) NOT NULL,
  `etapa` int(11) NOT NULL,
  `tarefa` varchar(255) NOT NULL,
  `descricao` text DEFAULT NULL,
  `data_cadastro` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `clientes`
--

CREATE TABLE `clientes` (
  `id` int(11) NOT NULL,
  `nome` varchar(150) NOT NULL,
  `cpf` varchar(14) NOT NULL,
  `rg` varchar(20) DEFAULT NULL,
  `contato` varchar(20) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `telefone` varchar(20) DEFAULT NULL,
  `endereco` text DEFAULT NULL,
  `data_cadastro` timestamp NOT NULL DEFAULT current_timestamp(),
  `senha` varchar(255) DEFAULT '123456',
  `ultimo_login` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `convidados`
--

CREATE TABLE `convidados` (
  `id` int(11) NOT NULL,
  `evento_id` int(11) NOT NULL,
  `nome` varchar(150) NOT NULL,
  `categoria` varchar(50) DEFAULT 'Outros',
  `acompanhantes` varchar(255) DEFAULT NULL,
  `filhos` varchar(255) DEFAULT NULL,
  `faixa_etaria` enum('Adulto','Criança','Bebê') NOT NULL DEFAULT 'Adulto',
  `telefone` varchar(20) DEFAULT NULL,
  `confirmado` tinyint(1) DEFAULT 0,
  `data_confirmacao` datetime DEFAULT NULL,
  `mesa_id` int(11) DEFAULT NULL,
  `nomes_acompanhantes` varchar(255) DEFAULT NULL,
  `idades_filhos` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `eventos`
--

CREATE TABLE `eventos` (
  `id` int(11) NOT NULL,
  `cliente_id` int(11) NOT NULL,
  `data_evento` date NOT NULL,
  `hora_evento` time NOT NULL,
  `tipo_ceremonia` enum('Igreja','Salão') NOT NULL,
  `local_ceremonia` varchar(255) NOT NULL,
  `tem_recepcao` tinyint(1) DEFAULT 0,
  `local_recepcao` varchar(255) DEFAULT NULL,
  `tipo_assessoria` enum('Básica','Completa') NOT NULL,
  `local_cerimonia` varchar(255) DEFAULT NULL,
  `tem_festa` tinyint(1) DEFAULT 0,
  `local_festa` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `fornecedores`
--

CREATE TABLE `fornecedores` (
  `id` int(11) NOT NULL,
  `nome` varchar(150) NOT NULL,
  `servico` varchar(255) DEFAULT NULL,
  `servico_tipo` varchar(100) NOT NULL,
  `contato` varchar(100) DEFAULT NULL,
  `telefone` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `fornecedores_evento`
--

CREATE TABLE `fornecedores_evento` (
  `id` int(11) NOT NULL,
  `evento_id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `servico` varchar(100) NOT NULL,
  `contato` varchar(100) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Orçamento',
  `data_cadastro` datetime DEFAULT current_timestamp(),
  `valor` decimal(10,2) DEFAULT 0.00,
  `valor_pago` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `inspiracoes_fotos`
--

CREATE TABLE `inspiracoes_fotos` (
  `id` int(11) NOT NULL,
  `evento_id` int(11) NOT NULL,
  `categoria` enum('Decoração','Buquê','Bolo','Outros') NOT NULL,
  `titulo` varchar(100) NOT NULL,
  `nome_imagem` varchar(255) NOT NULL,
  `data_upload` timestamp NOT NULL DEFAULT current_timestamp(),
  `selecionada` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `mesas`
--

CREATE TABLE `mesas` (
  `id` int(11) NOT NULL,
  `evento_id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `capacidade` int(11) NOT NULL DEFAULT 8,
  `ordem` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `musicas_evento`
--

CREATE TABLE `musicas_evento` (
  `id` int(11) NOT NULL,
  `evento_id` int(11) NOT NULL,
  `momento` varchar(100) NOT NULL,
  `titulo` varchar(255) NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `status` tinyint(1) DEFAULT 0,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `notas_evento`
--

CREATE TABLE `notas_evento` (
  `id` int(11) NOT NULL,
  `evento_id` int(11) NOT NULL,
  `titulo` varchar(255) NOT NULL,
  `conteudo` text DEFAULT NULL,
  `cor` varchar(50) DEFAULT 'amarelo',
  `autor` varchar(100) DEFAULT 'Assessoria',
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `playlist_evento`
--

CREATE TABLE `playlist_evento` (
  `id` int(11) NOT NULL,
  `evento_id` int(11) NOT NULL,
  `momento` varchar(100) NOT NULL,
  `nome_musica` varchar(150) NOT NULL,
  `link_url` varchar(255) DEFAULT NULL,
  `origem` enum('Assessoria','Noivos') DEFAULT 'Assessoria',
  `escolhida` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `servicos_assessoria`
--

CREATE TABLE `servicos_assessoria` (
  `id` int(11) NOT NULL,
  `evento_id` int(11) NOT NULL,
  `nome_servico` varchar(150) NOT NULL,
  `status` enum('Pendente','Em Andamento','Concluído') DEFAULT 'Pendente'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `tipo` varchar(20) DEFAULT 'assistente',
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Índices para tabelas despejadas
--

--
-- Índices de tabela `administradores`
--
ALTER TABLE `administradores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `usuario` (`usuario`);

--
-- Índices de tabela `calendario_anotacoes`
--
ALTER TABLE `calendario_anotacoes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `data_nota` (`data_nota`);

--
-- Índices de tabela `checklist`
--
ALTER TABLE `checklist`
  ADD PRIMARY KEY (`id`),
  ADD KEY `evento_id` (`evento_id`);

--
-- Índices de tabela `checklist_comentarios`
--
ALTER TABLE `checklist_comentarios`
  ADD PRIMARY KEY (`id`),
  ADD KEY `checklist_id` (`checklist_id`);

--
-- Índices de tabela `checklist_modelos`
--
ALTER TABLE `checklist_modelos`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `clientes`
--
ALTER TABLE `clientes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `cpf` (`cpf`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Índices de tabela `convidados`
--
ALTER TABLE `convidados`
  ADD PRIMARY KEY (`id`),
  ADD KEY `evento_id` (`evento_id`);

--
-- Índices de tabela `eventos`
--
ALTER TABLE `eventos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cliente_id` (`cliente_id`);

--
-- Índices de tabela `fornecedores`
--
ALTER TABLE `fornecedores`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `fornecedores_evento`
--
ALTER TABLE `fornecedores_evento`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `inspiracoes_fotos`
--
ALTER TABLE `inspiracoes_fotos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `evento_id` (`evento_id`);

--
-- Índices de tabela `mesas`
--
ALTER TABLE `mesas`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `musicas_evento`
--
ALTER TABLE `musicas_evento`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_musica_evento` (`evento_id`);

--
-- Índices de tabela `notas_evento`
--
ALTER TABLE `notas_evento`
  ADD PRIMARY KEY (`id`);

--
-- Índices de tabela `playlist_evento`
--
ALTER TABLE `playlist_evento`
  ADD PRIMARY KEY (`id`),
  ADD KEY `evento_id` (`evento_id`);

--
-- Índices de tabela `servicos_assessoria`
--
ALTER TABLE `servicos_assessoria`
  ADD PRIMARY KEY (`id`),
  ADD KEY `evento_id` (`evento_id`);

--
-- Índices de tabela `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT para tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `administradores`
--
ALTER TABLE `administradores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `calendario_anotacoes`
--
ALTER TABLE `calendario_anotacoes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `checklist`
--
ALTER TABLE `checklist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `checklist_comentarios`
--
ALTER TABLE `checklist_comentarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `checklist_modelos`
--
ALTER TABLE `checklist_modelos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `clientes`
--
ALTER TABLE `clientes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `convidados`
--
ALTER TABLE `convidados`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `eventos`
--
ALTER TABLE `eventos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `fornecedores`
--
ALTER TABLE `fornecedores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `fornecedores_evento`
--
ALTER TABLE `fornecedores_evento`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `inspiracoes_fotos`
--
ALTER TABLE `inspiracoes_fotos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `mesas`
--
ALTER TABLE `mesas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `musicas_evento`
--
ALTER TABLE `musicas_evento`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `notas_evento`
--
ALTER TABLE `notas_evento`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `playlist_evento`
--
ALTER TABLE `playlist_evento`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `servicos_assessoria`
--
ALTER TABLE `servicos_assessoria`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Restrições para tabelas despejadas
--

--
-- Restrições para tabelas `checklist`
--
ALTER TABLE `checklist`
  ADD CONSTRAINT `checklist_ibfk_1` FOREIGN KEY (`evento_id`) REFERENCES `eventos` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `checklist_comentarios`
--
ALTER TABLE `checklist_comentarios`
  ADD CONSTRAINT `checklist_comentarios_ibfk_1` FOREIGN KEY (`checklist_id`) REFERENCES `checklist` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `convidados`
--
ALTER TABLE `convidados`
  ADD CONSTRAINT `convidados_ibfk_1` FOREIGN KEY (`evento_id`) REFERENCES `eventos` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `eventos`
--
ALTER TABLE `eventos`
  ADD CONSTRAINT `eventos_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `inspiracoes_fotos`
--
ALTER TABLE `inspiracoes_fotos`
  ADD CONSTRAINT `inspiracoes_fotos_ibfk_1` FOREIGN KEY (`evento_id`) REFERENCES `eventos` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `playlist_evento`
--
ALTER TABLE `playlist_evento`
  ADD CONSTRAINT `playlist_evento_ibfk_1` FOREIGN KEY (`evento_id`) REFERENCES `eventos` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `servicos_assessoria`
--
ALTER TABLE `servicos_assessoria`
  ADD CONSTRAINT `servicos_assessoria_ibfk_1` FOREIGN KEY (`evento_id`) REFERENCES `eventos` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
