-- Primeiro schema.sql deste projeto. Escopo: só a integração com a Central
-- (sistema-mãe) — este projeto não usa migrations, cada página cria suas
-- próprias tabelas inline (ver conexao.php / gerenciar_equipe.php etc). Esta
-- tabela é aplicada manualmente (local + produção), sem tooling de migration.

CREATE TABLE IF NOT EXISTS `central_outbox_events` (
  `id` int NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) NOT NULL,
  `type` varchar(100) NOT NULL,
  `payload` longtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_central_outbox_events_uuid` (`uuid`),
  KEY `idx_central_outbox_events_sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Avisos da Central sincronizados por scripts/central_sync_announcements.php
-- e exibidos em painel_admin.php (popup, faixa e sino).
CREATE TABLE IF NOT EXISTS `central_avisos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `central_uuid` varchar(36) NOT NULL,
  `titulo` varchar(255) NOT NULL,
  `mensagem` text DEFAULT NULL,
  `modo` enum('popup','banner','bell') NOT NULL DEFAULT 'popup',
  `cor` varchar(20) DEFAULT NULL,
  `alvo_admin_ids` varchar(255) DEFAULT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_central_avisos_uuid` (`central_uuid`),
  KEY `idx_central_avisos_modo_ativo` (`modo`, `ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Quais admins já marcaram cada aviso como lido (central_avisos_marcar_lido.php).
CREATE TABLE IF NOT EXISTS `central_avisos_lidos` (
  `aviso_id` int NOT NULL,
  `usuario_id` int NOT NULL,
  `lido_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`aviso_id`, `usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
