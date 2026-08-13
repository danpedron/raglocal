ALTER TABLE conversations
  ADD COLUMN ai_draft MEDIUMTEXT NULL AFTER status,
  ADD COLUMN ai_confidence DECIMAL(5,4) NULL AFTER ai_draft,
  ADD COLUMN ai_model VARCHAR(120) NULL AFTER ai_confidence;

CREATE TABLE IF NOT EXISTS settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  value TEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings(name, value) VALUES
  ('ollama_chat_model', 'qwen3:4b'),
  ('rag_min_confidence', '0.75'),
  ('rag_min_sources', '1'),
  ('ollama_timeout', '120')
ON DUPLICATE KEY UPDATE name = VALUES(name);
