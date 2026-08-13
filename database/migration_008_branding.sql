-- RAG genérico: identidade visual configurável por empresa.
-- Idempotente e restrita ao banco da aplicação.
CREATE TABLE IF NOT EXISTS settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  value TEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings(name, value) VALUES
  ('brand_name', 'RAGLocal'),
  ('brand_subtitle', 'Atendimento inteligente baseado na sua base de conhecimento'),
  ('brand_logo_filename', ''),
  ('brand_logo_mime', '')
ON DUPLICATE KEY UPDATE name = VALUES(name);
