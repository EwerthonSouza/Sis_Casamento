-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Tempo de geração: 05/08/2026 às 01:55
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
CREATE DATABASE IF NOT EXISTS `sistema_eventos` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `sistema_eventos`;

-- --------------------------------------------------------

--
-- Estrutura para tabela `calendario_anotacoes`
--

CREATE TABLE `calendario_anotacoes` (
  `id` int(11) NOT NULL,
  `data_nota` date NOT NULL,
  `hora_nota` time DEFAULT NULL,
  `anotacao` text NOT NULL,
  `data_cadastro` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `calendario_anotacoes`
--

INSERT INTO `calendario_anotacoes` (`id`, `data_nota`, `hora_nota`, `anotacao`, `data_cadastro`) VALUES
(10, '2026-06-04', NULL, 'ashdgahsdas\r\ndkajsdkasj\r\nasjdbas', '2026-06-09 15:52:44'),
(18, '2026-06-25', '12:00:00', 'teste', '2026-06-24 04:31:03'),
(19, '2026-06-25', '14:00:00', 'casamento, orçamento e contrato apra fazer dlfsdjgbsg\r\n\r\n\r\n\r\n\r\n\r\nsdlfsdlf\r\nsdfsçdlfm\r\n\r\n\r\n\r\n\r\n\r\n\r\nsdflksdnglsg', '2026-06-24 04:31:38');

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
  `status` varchar(20) DEFAULT 'pendente',
  `data_prazo` date DEFAULT NULL,
  `concluido_em` datetime DEFAULT NULL,
  `concluido_por` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `checklist`
--

INSERT INTO `checklist` (`id`, `evento_id`, `etapa`, `tarefa`, `descricao`, `origem`, `checado`, `observacoes`, `fornecedores_sugeridos`, `status`, `data_prazo`, `concluido_em`, `concluido_por`) VALUES
(310, 5, 'julho/dezembro 2026', 'Faça sua lista de convidados', 'sdsd', 'Assessoria', 0, NULL, NULL, 'pendente', NULL, NULL, NULL),
(311, 5, 'julho/dezembro 2026', 'Faça sua lista de convidados', 'Lista de convidados (texto completo omitido no reimport para brevidade).', 'Assessoria', 1, NULL, NULL, 'concluido', NULL, NULL, NULL),
(312, 5, 'julho/dezembro 2026', 'Crie suas pastas de inspirações para que possamos montar um projetinho personalizado para solicitarmos orçamento da sua decoração', 'Texto completo omitido no reimport para brevidade.', 'Assessoria', 1, NULL, NULL, 'concluido', NULL, NULL, NULL),
(313, 5, 'dezembro/janeiro 2027', 'Orçando / Contratando o fotógrafo (Atenção ao checklist)', 'Texto completo omitido no reimport para brevidade.', 'Assessoria', 0, NULL, NULL, 'pendente', NULL, NULL, NULL),
(314, 5, 'fevereiro/junho 2027', 'fotos', 'oi', 'Assessoria', 0, NULL, NULL, 'pendente', NULL, NULL, NULL),
(315, 5, 'julho/dezembro 2026', 'COMPRA DO VESTIDO', 'tteste', 'Assessoria', 1, NULL, NULL, 'concluido', NULL, NULL, NULL),
(316, 6, '1', 'Faça sua lista de convidados', 'Texto completo omitido no reimport para brevidade.', 'Assessoria', 0, NULL, NULL, 'pendente', NULL, NULL, NULL),
(317, 6, '1', 'Crie suas pastas de inspirações para que possamos montar um projetinho personalizado para solicitarmos orçamento da sua decoração', 'Texto completo omitido no reimport para brevidade.', 'Assessoria', 0, NULL, NULL, 'pendente', NULL, NULL, NULL),
(318, 6, '2', 'Orçando / Contratando o fotógrafo (Atenção ao checklist)', 'Texto completo omitido no reimport para brevidade.', 'Assessoria', 0, NULL, NULL, 'pendente', NULL, NULL, NULL),
(319, 6, '3', 'fotos', 'oi', 'Assessoria', 0, NULL, NULL, 'pendente', NULL, NULL, NULL),
(320, 6, '1', 'COMPRA DO VESTIDO', 'tteste', 'Assessoria', 0, NULL, NULL, 'pendente', NULL, NULL, NULL),
(321, 6, '1', 'Faça sua lista de convidados', 'Texto completo omitido no reimport para brevidade.', 'Assessoria', 0, NULL, NULL, 'pendente', NULL, NULL, NULL),
(322, 6, '1', 'Crie suas pastas de inspirações para que possamos montar um projetinho personalizado para solicitarmos orçamento da sua decoração', 'Texto completo omitido no reimport para brevidade.', 'Assessoria', 0, NULL, NULL, 'pendente', NULL, NULL, NULL),
(323, 6, '2', 'Orçando / Contratando o fotógrafo (Atenção ao checklist)', 'Texto completo omitido no reimport para brevidade.', 'Assessoria', 0, NULL, NULL, 'pendente', NULL, NULL, NULL),
(324, 6, '3', 'fotos', 'oi', 'Assessoria', 0, NULL, NULL, 'pendente', NULL, NULL, NULL),
(325, 6, '1', 'COMPRA DO VESTIDO', 'tteste', 'Assessoria', 0, NULL, NULL, 'pendente', NULL, NULL, NULL),
(326, 6, '1', 'Faça sua lista de convidados', 'sdsd', 'Assessoria', 0, NULL, NULL, 'pendente', NULL, NULL, NULL);

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
  `data_criacao` datetime DEFAULT current_timestamp(),
  `evento_id` int(11) DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `checklist_comentarios`
--

INSERT INTO `checklist_comentarios` (`id`, `checklist_id`, `autor`, `comentario`, `data_cadastro`, `etapa_nome`, `data_criacao`, `evento_id`, `criado_em`) VALUES
(11, NULL, 'Noivos', 'feito', '2026-06-01 19:14:43', '1', '2026-06-01 15:14:43', NULL, '2026-06-24 03:26:57'),
(13, NULL, 'Assessoria', 'otimo', '2026-06-01 19:38:59', '1', '2026-06-01 15:38:59', NULL, '2026-06-24 03:26:57'),
(15, NULL, 'Assessoria', 'etapa 1,2 e 3 para os meses de junho e julho', '2026-06-06 04:10:26', '1', '2026-06-06 00:10:26', NULL, '2026-06-24 03:26:57'),
(16, NULL, 'Assessoria', 'TESTE', '2026-06-07 23:35:29', '1', '2026-06-07 19:35:29', NULL, '2026-06-24 03:26:57'),
(17, NULL, '', 'TESTE', '2026-06-07 23:39:14', '1', '2026-06-07 19:39:14', NULL, '2026-06-24 03:26:57'),
(18, NULL, '', 'feito', '2026-06-08 15:36:02', '1', '2026-06-08 11:36:02', NULL, '2026-06-24 03:26:57'),
(19, 310, 'Noivos', 'quase completo', '2026-06-17 16:02:08', NULL, '2026-06-17 12:02:08', NULL, '2026-06-24 03:26:57'),
(20, 310, 'Noivos', 'tem mais convidados para fazer', '2026-06-17 16:02:26', NULL, '2026-06-17 12:02:26', NULL, '2026-06-24 03:26:57'),
(21, 310, '', 'otimo!', '2026-06-24 04:53:17', NULL, '2026-06-24 00:53:17', NULL, '2026-06-24 04:53:17'),
(22, 310, '', 'feito', '2026-06-24 13:42:32', NULL, '2026-06-24 09:42:32', NULL, '2026-06-24 13:42:32'),
(23, NULL, '', 'feito', '2026-06-24 13:42:38', 'julho/dezembro 2026', '2026-06-24 09:42:38', 5, '2026-06-24 13:42:38'),
(24, NULL, 'Noivos', 'feito thaina', '2026-07-29 15:25:08', '1', '2026-07-29 11:25:08', 6, '2026-07-29 15:25:08');

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

--
-- Despejando dados para a tabela `checklist_modelos`
--

INSERT INTO `checklist_modelos` (`id`, `tipo_padrao`, `etapa`, `tarefa`, `descricao`, `data_cadastro`) VALUES
(1, 'com_recepcao', 1, 'Faça sua lista de convidados', 'Texto completo omitido no reimport para brevidade.', '2026-05-28 17:22:26'),
(2, 'sem_recepcao', 1, 'Faça sua lista de convidados', 'sdsd', '2026-05-28 17:22:31'),
(7, 'com_recepcao', 1, 'Crie suas pastas de inspirações para que possamos montar um projetinho personalizado para solicitarmos orçamento da sua decoração', 'Texto completo omitido no reimport para brevidade.', '2026-05-28 17:56:04'),
(8, 'com_recepcao', 2, 'Orçando / Contratando o fotógrafo (Atenção ao checklist)', 'Texto completo omitido no reimport para brevidade.', '2026-05-28 17:56:48'),
(9, 'com_recepcao', 3, 'fotos', 'oi', '2026-06-01 19:03:28'),
(10, 'com_recepcao', 1, 'COMPRA DO VESTIDO', 'tteste', '2026-06-17 15:53:19');

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

--
-- Despejando dados para a tabela `clientes`
--

INSERT INTO `clientes` (`id`, `nome`, `cpf`, `rg`, `contato`, `email`, `telefone`, `endereco`, `data_cadastro`, `senha`, `ultimo_login`) VALUES
(18, 'EWERTHON WILLAMIS SOUZA DE OLIVEIRA & EWERTHON WILLAMIS SOUZA DE OLIVEIRA', '12312312312', NULL, NULL, 'ewerthon2710@gmail.com', '(95) 98127-4864', NULL, '2026-06-10 13:20:47', '$2y$10$y.SnoTUSZzUUzB60qD3rq.dzNKVbz5GWaFW/hx3scC2rLjdsYFIFe', '2026-06-10 09:20:47'),
(19, 'teste1 & teste2', '', NULL, NULL, 'teste1@gmail.com', '', NULL, '2026-06-10 13:56:45', '$2y$10$ALQKRjvgPn4A6BGGsnMXQOeGOLiZyZO/GBQM/zWVjK9CLVRZ5dgmW', '2026-06-10 09:56:45');

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
  `faixa_etaria` varchar(50) NOT NULL DEFAULT 'Adulto (11+ anos)',
  `telefone` varchar(20) DEFAULT NULL,
  `confirmado` tinyint(1) DEFAULT 0,
  `data_confirmacao` datetime DEFAULT NULL,
  `mesa_id` int(11) DEFAULT NULL,
  `nomes_acompanhantes` varchar(255) DEFAULT NULL,
  `idades_filhos` varchar(255) DEFAULT NULL,
  `resposta_rsvp` varchar(20) DEFAULT NULL,
  `mensagem_rsvp` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `convidados`
--

INSERT INTO `convidados` (`id`, `evento_id`, `nome`, `categoria`, `acompanhantes`, `filhos`, `faixa_etaria`, `telefone`, `confirmado`, `data_confirmacao`, `mesa_id`, `nomes_acompanhantes`, `idades_filhos`, `resposta_rsvp`, `mensagem_rsvp`) VALUES
(31, 6, 'ewerthon', 'Outros', NULL, NULL, 'Adulto (11+ anos)', '', 1, '2026-07-30 09:17:52', NULL, NULL, NULL, 'confirmado', NULL);

-- --------------------------------------------------------

--
-- Estrutura para tabela `eventos`
--

CREATE TABLE `eventos` (
  `id` int(11) NOT NULL,
  `cliente_id` int(11) NOT NULL,
  `data_evento` date NOT NULL,
  `hora_evento` time DEFAULT NULL,
  `tipo_ceremonia` enum('Igreja','Salão') NOT NULL,
  `local_ceremonia` varchar(255) NOT NULL,
  `tem_recepcao` tinyint(1) DEFAULT 0,
  `local_recepcao` varchar(255) DEFAULT NULL,
  `tipo_assessoria` enum('Básica','Completa') NOT NULL,
  `local_cerimonia` varchar(255) DEFAULT NULL,
  `tem_festa` tinyint(1) DEFAULT 0,
  `local_festa` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Despejando dados para a tabela `eventos`
--

INSERT INTO `eventos` (`id`, `cliente_id`, `data_evento`, `hora_evento`, `tipo_ceremonia`, `local_ceremonia`, `tem_recepcao`, `local_recepcao`, `tipo_assessoria`, `local_cerimonia`, `tem_festa`, `local_festa`) VALUES
(5, 18, '2026-06-25', '20:00:00', 'Igreja', '', 0, NULL, 'Básica', 'Igreja Matriz', 1, 'espaço eventos'),
(6, 19, '2026-12-24', NULL, 'Igreja', '', 0, NULL, 'Básica', NULL, 0, NULL);

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

--
-- Despejando dados para a tabela `fornecedores`
--

INSERT INTO `fornecedores` (`id`, `nome`, `servico`, `servico_tipo`, `contato`, `telefone`) VALUES
(1, 'Marcos Fotografia', NULL, 'Fotografia', 'Marcos', '(11) 99999-1111'),
(2, 'Buffet Requinte', NULL, 'Buffet', 'Cláudia', '(11) 99999-2222'),
(3, 'DJ Som & Luz', NULL, 'DJ / Iluminação', 'Rodrigo', '(11) 99999-3333'),
(4, 'fotografos', NULL, 'fotos', 'Sugerido pelos Noivos', NULL);

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

--
-- Despejando dados para a tabela `fornecedores_evento`
--

INSERT INTO `fornecedores_evento` (`id`, `evento_id`, `nome`, `servico`, `contato`, `status`, `data_cadastro`, `valor`, `valor_pago`) VALUES
(3, 5, 'PICANHAS', 'BUFFET', '', 'Contratado', '2026-06-17 11:57:20', 2000.00, 0.00),
(4, 5, 'PICANHAS', 'BUFFET', '', 'Orçamento', '2026-06-17 11:57:38', 2000.00, 0.00),
(5, 5, 'PICANHAS', 'BUFFET', '', 'Contratado', '2026-06-23 23:55:34', 2000.00, 1200.00),
(10, 6, 'PICANHAS', 'BUFFET', '', 'Contratado', '2026-07-30 11:21:07', 2000.00, 0.00),
(11, 6, 'fotografos', 'FOTOGRAFO', '', 'Contratado', '2026-07-31 02:15:42', 10000.00, 1500.00);

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

--
-- Despejando dados para a tabela `mesas`
--

INSERT INTO `mesas` (`id`, `evento_id`, `nome`, `capacidade`, `ordem`) VALUES
(2, 1, 'mesa 2', 4, 1),
(3, 1, 'mesa 3', 5, 2),
(4, 1, 'Mesa 01', 4, 3),
(6, 1, 'Mesa 03', 4, 5),
(7, 1, 'Mesa 04', 4, 6),
(8, 1, 'Mesa 05', 4, 7),
(9, 1, 'Mesa 06', 4, 8),
(10, 1, 'Mesa 07', 4, 9),
(11, 1, 'Mesa 08', 4, 10),
(12, 1, 'Mesa 09', 4, 11),
(13, 6, 'mesa 1', 8, 1);

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
  `status` varchar(20) NOT NULL DEFAULT 'sugestao',
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `artista` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `musicas_evento`
--

INSERT INTO `musicas_evento` (`id`, `evento_id`, `momento`, `titulo`, `link`, `status`, `criado_em`, `artista`) VALUES
(1, 1, 'Entrada da Noiva', 'A THOUSAND YEARS', 'https://www.youtube.com/watch?v=J_6Vd17ycEU&list=RDCl-IcfdBRRw&index=3', 'sugestao', '2026-06-07 23:55:50', NULL),
(2, 5, 'Entrada do Noivo', 'A THOUSAND YEARS', 'https://www.youtube.com/watch?v=J_6Vd17ycEU&list=RDCl-IcfdBRRw&index=3', 'sugestao', '2026-06-17 16:02:43', NULL),
(3, 5, 'Cerimônia · Entrada dos Padrinhos', 'Radiohead', 'https://www.youtube.com/watch?v=XFkzRNyygfk&list=RDqvwKAyMapy8&index=4', 'sugestao', '2026-06-24 03:53:46', 'creep'),
(4, 6, 'Cerimônia · Entrada dos Padrinhos', 'A THOUSAND YEARS', 'https://www.youtube.com/watch?v=J_6Vd17ycEU&list=RDCl-IcfdBRRw&index=3', 'confirmada', '2026-07-29 14:03:04', 'creep'),
(5, 6, 'Entrada da Noiva', 'Radiohead', 'https://www.youtube.com/watch?v=XFkzRNyygfk&list=RDqvwKAyMapy8&index=4', 'confirmada', '2026-07-29 15:23:24', NULL);

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
-- Estrutura para tabela `notificacoes_lidas`
--

CREATE TABLE `notificacoes_lidas` (
  `usuario_tipo` varchar(20) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `ultima_visualizacao` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `notificacoes_lidas`
--

INSERT INTO `notificacoes_lidas` (`usuario_tipo`, `usuario_id`, `ultima_visualizacao`) VALUES
('admin', 1, '2026-07-30 10:17:33'),
('assistente', 1, '2026-07-31 03:05:43');

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
-- Estrutura para tabela `referencias_fornecedores`
--

CREATE TABLE `referencias_fornecedores` (
  `id` int(11) NOT NULL,
  `categoria` varchar(50) NOT NULL,
  `nome` varchar(150) NOT NULL,
  `contato` varchar(100) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `redes_sociais` varchar(255) DEFAULT NULL,
  `endereco` varchar(255) DEFAULT NULL,
  `observacoes` text DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `referencias_fornecedores`
--

INSERT INTO `referencias_fornecedores` (`id`, `categoria`, `nome`, `contato`, `email`, `redes_sociais`, `endereco`, `observacoes`, `criado_em`) VALUES
(5, 'Local de Recepção', 'LEONARDO FERREIRA', '(47) 991152707', '', 'https://www.instagram.com/ecohotel?utm_source=ig_web_button_share_sheet&igsh=ZDNlZDc0MzIxNw==', '', '', '2026-07-30 13:20:07');

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
-- Despejando dados para a tabela `usuarios`
--

INSERT INTO `usuarios` (`id`, `nome`, `email`, `senha`, `tipo`, `criado_em`) VALUES
(1, 'RICK SANCHES', 'teste@gmail.com', '$2y$10$p5fBJhfrhmPn2WdJVZAikuUENMj.KV2a3VpQK2xQi4H8sD.p0H6ym', 'assistente', '2026-06-07 21:47:03'),
(2, 'Administrador', 'admin@meueventopro.com', '$2y$10$5dz4kT44c9KqP10FYyaRqO1GOEuwGle2ptFERlFZg2IrmIdilKFp6', 'admin', '2026-06-07 21:47:03');

--
-- Índices para tabelas despejadas
--

--
-- Índices de tabela `calendario_anotacoes`
--
ALTER TABLE `calendario_anotacoes`
  ADD PRIMARY KEY (`id`);

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
-- Índices de tabela `notificacoes_lidas`
--
ALTER TABLE `notificacoes_lidas`
  ADD PRIMARY KEY (`usuario_tipo`,`usuario_id`);

--
-- Índices de tabela `playlist_evento`
--
ALTER TABLE `playlist_evento`
  ADD PRIMARY KEY (`id`),
  ADD KEY `evento_id` (`evento_id`);

--
-- Índices de tabela `referencias_fornecedores`
--
ALTER TABLE `referencias_fornecedores`
  ADD PRIMARY KEY (`id`);

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
-- AUTO_INCREMENT de tabela `calendario_anotacoes`
--
ALTER TABLE `calendario_anotacoes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT de tabela `checklist`
--
ALTER TABLE `checklist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=328;

--
-- AUTO_INCREMENT de tabela `checklist_comentarios`
--
ALTER TABLE `checklist_comentarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT de tabela `checklist_modelos`
--
ALTER TABLE `checklist_modelos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT de tabela `clientes`
--
ALTER TABLE `clientes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT de tabela `convidados`
--
ALTER TABLE `convidados`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT de tabela `eventos`
--
ALTER TABLE `eventos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT de tabela `fornecedores`
--
ALTER TABLE `fornecedores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de tabela `fornecedores_evento`
--
ALTER TABLE `fornecedores_evento`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT de tabela `inspiracoes_fotos`
--
ALTER TABLE `inspiracoes_fotos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `mesas`
--
ALTER TABLE `mesas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT de tabela `musicas_evento`
--
ALTER TABLE `musicas_evento`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de tabela `notas_evento`
--
ALTER TABLE `notas_evento`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT de tabela `playlist_evento`
--
ALTER TABLE `playlist_evento`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de tabela `referencias_fornecedores`
--
ALTER TABLE `referencias_fornecedores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de tabela `servicos_assessoria`
--
ALTER TABLE `servicos_assessoria`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de tabela `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

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
