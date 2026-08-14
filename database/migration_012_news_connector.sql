-- Conector WordPress de notícias para o RAGLocal.
-- A senha do banco editorial deve ser gravada pelo painel usando APP_SECRET.

ALTER TABLE documents
  MODIFY kind ENUM('regimento','ata','memoria','manutencao','noticia') NOT NULL;

ALTER TABLE audit_logs
  MODIFY event_type ENUM('question','ai_answer','human_answer','login_success','login_failure','document_upload','question_ignored','password_changed','news_sync') NOT NULL;

CREATE TABLE IF NOT EXISTS document_news (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id BIGINT UNSIGNED NOT NULL,
  source_name VARCHAR(100) NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  source_table VARCHAR(120) NOT NULL,
  source_type VARCHAR(40) NOT NULL,
  public_url VARCHAR(2048) NULL,
  published_at DATETIME NULL,
  modified_at DATETIME NULL,
  source_content_sha256 CHAR(64) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_sync_at TIMESTAMP NULL,
  withdrawn_at TIMESTAMP NULL,
  withdrawal_reason VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_document_news_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  UNIQUE KEY uq_document_news_source (source_name, source_id),
  UNIQUE KEY uq_document_news_document (document_id),
  KEY idx_document_news_active (is_active),
  KEY idx_document_news_modified (modified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_sync_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trigger_type ENUM('manual','cron') NOT NULL,
  status ENUM('running','completed','completed_with_errors','error') NOT NULL,
  read_count INT UNSIGNED NOT NULL DEFAULT 0,
  imported_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_count INT UNSIGNED NOT NULL DEFAULT 0,
  unchanged_count INT UNSIGNED NOT NULL DEFAULT 0,
  withdrawn_count INT UNSIGNED NOT NULL DEFAULT 0,
  error_count INT UNSIGNED NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at TIMESTAMP NULL,
  duration_ms INT UNSIGNED NULL,
  KEY idx_news_sync_runs_started (started_at),
  KEY idx_news_sync_runs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings(name, value) VALUES
  ('news_enabled', '0'),
  ('news_db_host', ''),
  ('news_db_port', '3306'),
  ('news_db_name', ''),
  ('news_db_user', ''),
  ('news_db_password', ''),
  ('news_db_table', 'wp_posts'),
  ('news_post_type', 'pmjs_noticia'),
  ('news_public_url_template', '')
ON DUPLICATE KEY UPDATE name = VALUES(name);
