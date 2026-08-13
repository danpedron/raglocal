-- Adiciona a categoria para certificados e documentos de manutenção técnica.
-- Idempotente e restrita ao banco da aplicação.
ALTER TABLE documents
  MODIFY kind ENUM('regimento','ata','memoria','manutencao') NOT NULL;

-- A classificação de documentos existentes deve ser feita pelo administrador de cada instalação.
