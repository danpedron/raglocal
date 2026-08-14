-- RAGLocal: fontes externas por plugins ativáveis
-- Compatível com instalações que já aplicaram as migrações 001-014.

ALTER TABLE documents
  MODIFY kind ENUM('regimento','ata','memoria','manutencao','noticia','diretriz','servico','externa') NOT NULL,
  MODIFY status ENUM('processing','ready','error','disabled') NOT NULL DEFAULT 'processing';

CREATE TABLE IF NOT EXISTS knowledge_sources (
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

CREATE TABLE IF NOT EXISTS document_source_links (
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

CREATE TABLE IF NOT EXISTS source_sync_runs (
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

ALTER TABLE audit_logs
  MODIFY event_type ENUM('question','ai_answer','human_answer','login_success','login_failure','document_upload','document_enabled','document_disabled','document_removed','question_ignored','password_changed','news_sync','guidance_updated','services_sync','question_reassessed','source_created','source_updated','source_enabled','source_disabled','source_removed','source_sync') NOT NULL;

-- Converte os dois conectores históricos em fontes administráveis, quando existirem.
INSERT INTO knowledge_sources(source_key, plugin_key, name, description, enabled, config_json)
SELECT
  'legacy-wordpress-news',
  'database_table',
  'WordPress — conector histórico de notícias',
  'Compatibilidade com a configuração anterior de notícias. Novas integrações devem usar o plugin Banco de dados — tabela.',
  CASE WHEN COALESCE((SELECT value FROM settings WHERE name = 'news_enabled' LIMIT 1), '0') = '1' THEN 1 ELSE 0 END,
  JSON_OBJECT(
    'host', COALESCE((SELECT value FROM settings WHERE name = 'news_db_host' LIMIT 1), ''),
    'port', COALESCE((SELECT value FROM settings WHERE name = 'news_db_port' LIMIT 1), '3306'),
    'database', COALESCE((SELECT value FROM settings WHERE name = 'news_db_name' LIMIT 1), ''),
    'user', COALESCE((SELECT value FROM settings WHERE name = 'news_db_user' LIMIT 1), ''),
    'password_enc', COALESCE((SELECT value FROM settings WHERE name = 'news_db_password' LIMIT 1), ''),
    'table', COALESCE((SELECT value FROM settings WHERE name = 'news_db_table' LIMIT 1), 'wp_posts'),
    'key_column', 'ID',
    'title_column', 'post_title',
    'content_columns', JSON_ARRAY('post_excerpt', 'post_content'),
    'filter_column', 'post_type',
    'filter_value', COALESCE((SELECT value FROM settings WHERE name = 'news_post_type' LIMIT 1), 'pmjs_noticia'),
    'status_column', 'post_status',
    'status_value', 'publish',
    'published_column', 'post_date',
    'modified_column', 'post_modified',
    'url_column', 'guid',
    'public_url_template', COALESCE((SELECT value FROM settings WHERE name = 'news_public_url_template' LIMIT 1), ''),
    'withdraw_missing', 1
  )
WHERE NOT EXISTS (SELECT 1 FROM knowledge_sources WHERE source_key = 'legacy-wordpress-news');

INSERT INTO knowledge_sources(source_key, plugin_key, name, description, enabled, config_json)
SELECT
  'legacy-services-file',
  'markdown_file',
  'Carta de Serviços — importador histórico',
  'Compatibilidade com a importação anterior da Carta de Serviços. O plugin genérico de arquivo poderá substituir este fluxo.',
  1,
  JSON_OBJECT(
    'source_url', COALESCE((SELECT value FROM settings WHERE name = 'services_source_url' LIMIT 1), ''),
    'withdraw_missing', COALESCE((SELECT value FROM settings WHERE name = 'services_deactivate_missing' LIMIT 1), '0')
  )
WHERE NOT EXISTS (SELECT 1 FROM knowledge_sources WHERE source_key = 'legacy-services-file');

INSERT IGNORE INTO document_source_links(source_id, document_id, source_item_key, public_url, source_title, source_content_sha256, is_active, last_sync_at, withdrawn_at, withdrawal_reason)
SELECT ks.id, dn.document_id, CONCAT('legacy:', dn.source_name, ':', dn.source_id), dn.public_url, d.title, dn.source_content_sha256, dn.is_active, dn.last_sync_at, dn.withdrawn_at, dn.withdrawal_reason
FROM document_news dn
JOIN documents d ON d.id = dn.document_id
JOIN knowledge_sources ks ON ks.source_key = 'legacy-wordpress-news';

INSERT IGNORE INTO document_source_links(source_id, document_id, source_item_key, public_url, source_title, source_content_sha256, is_active, last_sync_at, withdrawn_at, withdrawal_reason)
SELECT ks.id, ds.document_id, CONCAT('legacy:', ds.source_name, ':', ds.source_key), COALESCE(ds.public_url, ds.source_page_url), d.title, ds.source_content_sha256, ds.is_active, ds.last_import_at, ds.withdrawn_at, ds.withdrawal_reason
FROM document_services ds
JOIN documents d ON d.id = ds.document_id
JOIN knowledge_sources ks ON ks.source_key = 'legacy-services-file';
