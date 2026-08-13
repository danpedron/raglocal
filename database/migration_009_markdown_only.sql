-- Reter somente o artefato Markdown canônico usado pelo RAG.
-- A remoção física dos arquivos originais é executada pelo procedimento de deploy,
-- pois o banco não deve manipular o filesystem diretamente.
DELETE FROM document_artifacts WHERE artifact_type = 'original';

ALTER TABLE document_artifacts
  MODIFY artifact_type ENUM('markdown') NOT NULL;

-- O nome do arquivo de origem continua em documents.source_filename apenas como
-- metadado de auditoria; seu conteúdo não é persistido.
