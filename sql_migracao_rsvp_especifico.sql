-- ============================================================================
-- Migração: link de confirmação específico por convidado (RSVP por pessoa)
-- Gerado em: 2026-08-25
--
-- ATENÇÃO — LEIA ANTES DE RODAR NO BANCO ONLINE:
-- O banco online tem dados reais de clientes/convidados. Este script SÓ
-- adiciona colunas novas (ADD COLUMN) — nenhuma tabela ou coluna é apagada,
-- renomeada ou truncada. Ainda assim, antes de rodar:
--   1. Faça um backup completo do banco (mysqldump) por segurança.
--   2. Confira o resultado de cada comando abaixo linha por linha.
--   3. Rode em um horário de baixo uso, se possível.
-- Se qualquer coluna abaixo já existir no banco online, o comando
-- correspondente é ignorado (IF NOT EXISTS) e não faz nada.
-- ============================================================================

-- Guarda o token do link específico de cada convidado (titular da família).
ALTER TABLE convidados
  ADD COLUMN IF NOT EXISTS token_convite VARCHAR(64) NULL;

-- Liga um acompanhante ao id do seu titular (linha própria na tabela
-- convidados, com confirmado/resposta_rsvp independentes do titular).
ALTER TABLE convidados
  ADD COLUMN IF NOT EXISTS convidado_principal_id INT NULL;

-- Legado do fluxo de link geral/específico; mantido por compatibilidade
-- com o código atual, mesmo não havendo mais escolha de modo na interface.
ALTER TABLE eventos
  ADD COLUMN IF NOT EXISTS modo_confirmacao VARCHAR(20) NOT NULL DEFAULT 'geral';

-- ============================================================================
-- Fim da migração. Nenhum DROP TABLE ou DROP COLUMN é executado por este
-- script. As colunas antigas de acompanhantes (acompanhantes, filhos,
-- nomes_acompanhantes, idades_filhos) continuam intactas na tabela
-- convidados — só deixaram de ser usadas pelo código novo.
-- ============================================================================
