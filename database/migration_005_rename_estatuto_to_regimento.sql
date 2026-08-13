-- Renomeia a categoria interna legada para regimento.
-- Execute somente no banco exclusivo da aplicação.
-- A primeira alteração mantém os dois valores durante a conversão dos registros existentes.
ALTER TABLE documents
  MODIFY kind ENUM('estatuto','regimento','ata','memoria','manutencao') NOT NULL;

UPDATE documents
SET kind = 'regimento'
WHERE kind = 'estatuto';

ALTER TABLE documents
  MODIFY kind ENUM('regimento','ata','memoria','manutencao') NOT NULL;

-- A operação é segura para reexecução: depois da primeira execução, não há registros legados.
