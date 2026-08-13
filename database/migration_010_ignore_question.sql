-- RAGLocal: permite ignorar perguntas pendentes e registrar a ação na auditoria.
ALTER TABLE audit_logs
  MODIFY event_type ENUM('question','ai_answer','human_answer','login_success','login_failure','document_upload','question_ignored') NOT NULL;

INSERT INTO settings(name, value) VALUES
  ('default_scope_response', 'As perguntas devem ser referentes a {empresa}. Sua pergunta não está no contexto deste agente.')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- O marcador {empresa} é substituído pelo nome configurado da empresa antes da exibição.
-- Perguntas ignoradas continuam nas conversas e na auditoria, mas deixam de aparecer na fila human_pending.

COMMIT;
