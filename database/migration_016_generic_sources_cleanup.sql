-- RAGLocal migration 016
-- Remove somente fontes de compatibilidade legadas sem documentos vinculados.
-- Fontes que já possuem dados são preservadas para migração/remoção explícita pelo administrador.

UPDATE knowledge_sources
SET enabled = 0,
    updated_at = CURRENT_TIMESTAMP
WHERE source_key = 'legacy-services-file';

DELETE ks
FROM knowledge_sources ks
WHERE ks.source_key IN ('legacy-wordpress-news', 'legacy-services-file')
  AND NOT EXISTS (
      SELECT 1
      FROM document_source_links dsl
      WHERE dsl.source_id = ks.id
  );

INSERT INTO audit_logs (event_type, actor, metadata)
SELECT 'source_updated', 'system', JSON_OBJECT(
    'migration', '016_generic_sources_cleanup',
    'message', 'Fontes legadas vazias foram removidas; fontes com documentos foram preservadas e o importador legado de arquivo foi desativado.'
)
WHERE NOT EXISTS (
    SELECT 1
    FROM audit_logs
    WHERE event_type = 'source_updated'
      AND metadata LIKE '%016_generic_sources_cleanup%'
);
