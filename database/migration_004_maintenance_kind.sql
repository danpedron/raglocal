-- Jaraguá Tower IA: categoria para certificados e documentos de manutenção técnica.
-- Idempotente e restrita ao banco da aplicação.
ALTER TABLE documents
  MODIFY kind ENUM('estatuto','ata','memoria','manutencao') NOT NULL;

UPDATE documents
SET kind = 'manutencao'
WHERE source_filename = 'JARAGUA-TOWER_certificado_caixa-dagua_26.06.2026.pdf'
  AND title = 'Manutenção: Certificado da caixa d''água';

-- O certificado é fonte de manutenção, não estatuto.
