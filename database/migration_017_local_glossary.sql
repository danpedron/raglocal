-- Glossário local baseado em termos e coocorrências de perguntas.
-- Não armazena fatos nem substitui diretrizes administrativas.

CREATE TABLE IF NOT EXISTS glossary_terms (
  term VARCHAR(120) NOT NULL PRIMARY KEY,
  occurrence_count INT UNSIGNED NOT NULL DEFAULT 0,
  first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_glossary_terms_usage (occurrence_count, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS glossary_relations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  term_a VARCHAR(120) NOT NULL,
  term_b VARCHAR(120) NOT NULL,
  relation_type ENUM('cooccurrence') NOT NULL DEFAULT 'cooccurrence',
  question_count INT UNSIGNED NOT NULL DEFAULT 0,
  confidence DECIMAL(4,2) NOT NULL DEFAULT 0.25,
  first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_glossary_relation (term_a, term_b, relation_type),
  KEY idx_glossary_relations_a (term_a, relation_type, question_count),
  KEY idx_glossary_relations_b (term_b, relation_type, question_count),
  KEY idx_glossary_relations_rank (relation_type, question_count, confidence, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
