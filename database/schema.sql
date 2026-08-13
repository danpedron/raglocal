CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','attendant') NOT NULL DEFAULT 'attendant',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  kind ENUM('regimento','ata','memoria','manutencao') NOT NULL,
  source_filename VARCHAR(255) NULL,
  status ENUM('processing','ready','error') NOT NULL DEFAULT 'processing',
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE chunks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id BIGINT UNSIGNED NOT NULL,
  chunk_no INT UNSIGNED NOT NULL,
  content MEDIUMTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FULLTEXT KEY ft_chunks_content (content),
  FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  UNIQUE KEY uq_document_chunk (document_id, chunk_no)
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

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_type ENUM('question','ai_answer','human_answer','login_success','login_failure','document_upload') NOT NULL,
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
