-- Primeiro schema.sql deste projeto. Escopo: só a integração com a Central
-- (sistema-mãe) — este projeto não usa migrations, cada página cria suas
-- próprias tabelas inline (ver conexao.php / gerenciar_equipe.php etc). Esta
-- tabela é aplicada manualmente (local + produção), sem tooling de migration.

CREATE TABLE `central_outbox_events` (
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
