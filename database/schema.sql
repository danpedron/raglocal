CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','attendant') NOT NULL DEFAULT 'attendant',
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  value TEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings(name, value) VALUES
  ('brand_name', 'RAGLocal'),
  ('brand_subtitle', 'Atendimento inteligente baseado na sua base de conhecimento'),
  ('brand_logo_filename', ''),
  ('brand_logo_mime', ''),
  ('default_scope_response', 'As perguntas devem ser referentes a {empresa}. Sua pergunta não está no contexto deste agente.'),
  ('news_enabled', '0'),
  ('news_db_host', ''),
  ('news_db_port', '3306'),
  ('news_db_name', ''),
  ('news_db_user', ''),
  ('news_db_password', ''),
  ('news_db_table', 'wp_posts'),
  ('news_post_type', 'pmjs_noticia'),
  ('news_public_url_template', ''),
  ('ai_public_intro', 'Consulte o regimento interno e as atas do condomínio. A IA responde somente quando encontra evidência suficiente na base; caso contrário, encaminha a pergunta para atendimento humano.'),
  ('ai_soul', 'Você é o assistente oficial de {empresa}. Atenda em português brasileiro de forma clara, respeitosa, objetiva e acolhedora. Priorize informar com precisão, explicar limites de forma transparente e orientar o usuário ao atendimento humano quando a base não sustentar uma resposta. Preserve neutralidade institucional, não emita julgamentos pessoais e não invente fatos, interpretações, prazos, regras ou decisões.'),
  ('ai_interpretation_rules', ''),
  ('services_source_url', ''),
  ('services_deactivate_missing', '0')
ON DUPLICATE KEY UPDATE name = VALUES(name);

CREATE TABLE documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  kind ENUM('regimento','ata','memoria','manutencao','noticia','diretriz','servico','externa') NOT NULL,
  source_filename VARCHAR(255) NULL,
  status ENUM('processing','ready','error','disabled') NOT NULL DEFAULT 'processing',
  parser_version VARCHAR(40) NULL,
  canonical_sha256 CHAR(64) NULL,
  processed_at TIMESTAMP NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE chunks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id BIGINT UNSIGNED NOT NULL,
  chunk_no INT UNSIGNED NOT NULL,
  section_heading VARCHAR(255) NULL,
  tags VARCHAR(1000) NULL,
  page_start SMALLINT UNSIGNED NULL,
  page_end SMALLINT UNSIGNED NULL,
  token_count INT UNSIGNED NULL,
  content MEDIUMTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FULLTEXT KEY ft_chunks_content (content),
  FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  UNIQUE KEY uq_document_chunk (document_id, chunk_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_artifacts (
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

CREATE TABLE knowledge_sources (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_key VARCHAR(120) NOT NULL UNIQUE,
  plugin_key VARCHAR(80) NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  config_json LONGTEXT NOT NULL,
  last_sync_at TIMESTAMP NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  KEY idx_knowledge_sources_plugin (plugin_key),
  KEY idx_knowledge_sources_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_source_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_id BIGINT UNSIGNED NOT NULL,
  document_id BIGINT UNSIGNED NOT NULL,
  source_item_key VARCHAR(255) NOT NULL,
  public_url VARCHAR(2048) NULL,
  source_title VARCHAR(255) NULL,
  source_content_sha256 CHAR(64) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_sync_at TIMESTAMP NULL,
  withdrawn_at TIMESTAMP NULL,
  withdrawal_reason VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (source_id) REFERENCES knowledge_sources(id) ON DELETE CASCADE,
  FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  UNIQUE KEY uq_document_source_item (source_id, source_item_key),
  UNIQUE KEY uq_document_source_document (source_id, document_id),
  KEY idx_document_source_active (source_id, is_active),
  KEY idx_document_source_url (public_url(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE source_sync_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_id BIGINT UNSIGNED NOT NULL,
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
  FOREIGN KEY (source_id) REFERENCES knowledge_sources(id) ON DELETE CASCADE,
  KEY idx_source_sync_runs_source_started (source_id, started_at),
  KEY idx_source_sync_runs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE document_news (
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

CREATE TABLE news_sync_runs (
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

CREATE TABLE document_services (
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

CREATE TABLE service_sync_runs (
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

CREATE TABLE conversations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_token CHAR(64) NOT NULL UNIQUE,
  status ENUM('open','answered','human_pending','closed') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT UNSIGNED NOT NULL,
  sender ENUM('resident','ai','human') NOT NULL,
  body MEDIUMTEXT NOT NULL,
  citations JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE human_answers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id BIGINT UNSIGNED NOT NULL,
  question MEDIUMTEXT NOT NULL,
  answer MEDIUMTEXT NOT NULL,
  approved TINYINT(1) NOT NULL DEFAULT 0,
  answered_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (answered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_reassessments (
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

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type ENUM('question','ai_answer','human_answer','login_success','login_failure','document_upload','document_enabled','document_disabled','document_removed','question_ignored','password_changed','news_sync','guidance_updated','services_sync','question_reassessed','source_created','source_updated','source_enabled','source_disabled','source_removed','source_sync') NOT NULL,
  actor ENUM('resident','ai','human','admin','system') NOT NULL,
  conversation_id BIGINT UNSIGNED NULL,
  message_id BIGINT UNSIGNED NULL,
  question MEDIUMTEXT NULL,
  answer MEDIUMTEXT NULL,
  ai_draft MEDIUMTEXT NULL,
  ai_confidence DECIMAL(5,4) NULL,
  ai_model VARCHAR(120) NULL,
  citations JSON NULL,
  response_time_ms INT UNSIGNED NULL,
  source_ip VARCHAR(45) NULL,
  source_port SMALLINT UNSIGNED NULL,
  user_agent VARCHAR(512) NULL,
  request_method VARCHAR(16) NULL,
  request_uri VARCHAR(2048) NULL,
  referer VARCHAR(2048) NULL,
  forwarded_for VARCHAR(512) NULL,
  host VARCHAR(255) NULL,
  session_hash CHAR(64) NULL,
  metadata JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_created_at (created_at),
  KEY idx_audit_conversation (conversation_id),
  KEY idx_audit_event (event_type),
  CONSTRAINT fk_audit_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE SET NULL,
  CONSTRAINT fk_audit_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
