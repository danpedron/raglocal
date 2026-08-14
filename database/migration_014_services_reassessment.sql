-- Carta de Serviços e reavaliação de perguntas após atualização da base.

ALTER TABLE documents
  MODIFY kind ENUM('regimento','ata','memoria','manutencao','noticia','diretriz','servico') NOT NULL;

ALTER TABLE audit_logs
  MODIFY event_type ENUM('question','ai_answer','human_answer','login_success','login_failure','document_upload','question_ignored','password_changed','news_sync','guidance_updated','services_sync','question_reassessed') NOT NULL;

INSERT INTO settings(name, value) VALUES
  ('services_source_url', 'https://example.invalid/servicos'),
  ('services_deactivate_missing', '0')
ON DUPLICATE KEY UPDATE name = VALUES(name);

CREATE TABLE IF NOT EXISTS document_services (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id BIGINT UNSIGNED NOT NULL,
  source_name VARCHAR(100) NOT NULL,
  source_key VARCHAR(190) NOT NULL,
  service_title VARCHAR(255) NOT NULL,
  department VARCHAR(255) NULL,
  public_url VARCHAR(2048) NULL,
  source_page_url VARCHAR(2048) NULL,
  source_content_sha256 CHAR(64) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_import_at TIMESTAMP NULL,
  withdrawn_at TIMESTAMP NULL,
  withdrawal_reason VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_document_services_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  UNIQUE KEY uq_document_services_source (source_name, source_key),
  UNIQUE KEY uq_document_services_document (document_id),
  KEY idx_document_services_active (is_active),
  KEY idx_document_services_department (department(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_sync_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trigger_type ENUM('manual','cron','upload') NOT NULL,
  status ENUM('running','completed','completed_with_errors','error') NOT NULL,
  read_count INT UNSIGNED NOT NULL DEFAULT 0,
  imported_count INT UNSIGNED NOT NULL DEFAULT 0,
  updated_count INT UNSIGNED NOT NULL DEFAULT 0,
  unchanged_count INT UNSIGNED NOT NULL DEFAULT 0,
  withdrawn_count INT UNSIGNED NOT NULL DEFAULT 0,
  error_count INT UNSIGNED NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  source_url VARCHAR(2048) NULL,
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at TIMESTAMP NULL,
  duration_ms INT UNSIGNED NULL,
  KEY idx_service_sync_runs_started (started_at),
  KEY idx_service_sync_runs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_reassessments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT UNSIGNED NOT NULL,
  question_message_id BIGINT UNSIGNED NULL,
  previous_ai_message_id BIGINT UNSIGNED NULL,
  new_ai_message_id BIGINT UNSIGNED NULL,
  status ENUM('answered','human_pending','error') NOT NULL,
  ai_draft MEDIUMTEXT NULL,
  public_answer MEDIUMTEXT NULL,
  ai_confidence DECIMAL(5,4) NULL,
  ai_model VARCHAR(120) NULL,
  citations JSON NULL,
  source_count INT UNSIGNED NOT NULL DEFAULT 0,
  response_time_ms INT UNSIGNED NULL,
  triggered_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (question_message_id) REFERENCES messages(id) ON DELETE SET NULL,
  FOREIGN KEY (previous_ai_message_id) REFERENCES messages(id) ON DELETE SET NULL,
  FOREIGN KEY (new_ai_message_id) REFERENCES messages(id) ON DELETE SET NULL,
  FOREIGN KEY (triggered_by) REFERENCES users(id) ON DELETE SET NULL,
  KEY idx_ai_reassessments_conversation (conversation_id),
  KEY idx_ai_reassessments_created (created_at),
  KEY idx_ai_reassessments_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_import_sources (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_name VARCHAR(100) NOT NULL UNIQUE,
  source_url VARCHAR(2048) NULL,
  source_content_sha256 CHAR(64) NULL,
  service_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_import_at TIMESTAMP NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
