-- Artefato privado da ingestão: Markdown canônico para RAG.
CREATE TABLE IF NOT EXISTS document_artifacts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id BIGINT UNSIGNED NOT NULL,
  artifact_type ENUM('markdown') NOT NULL,
  filename VARCHAR(255) NOT NULL,
  storage_path VARCHAR(500) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  byte_size INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  content LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  UNIQUE KEY uq_document_artifact_type (document_id, artifact_type),
  KEY idx_artifacts_sha256 (sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE chunks
  ADD COLUMN IF NOT EXISTS section_heading VARCHAR(255) NULL AFTER chunk_no,
  ADD COLUMN IF NOT EXISTS tags VARCHAR(1000) NULL AFTER section_heading,
  ADD COLUMN IF NOT EXISTS page_start SMALLINT UNSIGNED NULL AFTER tags,
  ADD COLUMN IF NOT EXISTS page_end SMALLINT UNSIGNED NULL AFTER page_start,
  ADD COLUMN IF NOT EXISTS token_count INT UNSIGNED NULL AFTER page_end;

ALTER TABLE documents
  ADD COLUMN IF NOT EXISTS parser_version VARCHAR(40) NULL AFTER status,
  ADD COLUMN IF NOT EXISTS canonical_sha256 CHAR(64) NULL AFTER parser_version,
  ADD COLUMN IF NOT EXISTS processed_at TIMESTAMP NULL AFTER canonical_sha256;
