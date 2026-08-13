INSERT INTO audit_logs(event_type, actor, conversation_id, message_id, question, answer, ai_draft, ai_confidence, ai_model, citations, metadata, created_at)
SELECT 'question', 'resident', m.conversation_id, m.id, m.body, NULL, c.ai_draft, c.ai_confidence, c.ai_model, NULL,
       JSON_OBJECT('backfilled', TRUE, 'source', 'messages', 'origin_metadata_unavailable', TRUE), m.created_at
FROM messages m
JOIN conversations c ON c.id = m.conversation_id
WHERE m.sender = 'resident'
  AND NOT EXISTS (
    SELECT 1 FROM audit_logs a
    WHERE a.event_type = 'question' AND a.message_id = m.id
  );

INSERT INTO audit_logs(event_type, actor, conversation_id, message_id, question, answer, ai_draft, ai_confidence, ai_model, citations, metadata, created_at)
SELECT 'ai_answer', 'ai', m.conversation_id, m.id, NULL, m.body, c.ai_draft, c.ai_confidence, c.ai_model, m.citations,
       JSON_OBJECT('backfilled', TRUE, 'source', 'messages', 'origin_metadata_unavailable', TRUE), m.created_at
FROM messages m
JOIN conversations c ON c.id = m.conversation_id
WHERE m.sender = 'ai'
  AND NOT EXISTS (
    SELECT 1 FROM audit_logs a
    WHERE a.event_type = 'ai_answer' AND a.message_id = m.id
  );

INSERT INTO audit_logs(event_type, actor, conversation_id, message_id, question, answer, metadata, created_at)
SELECT 'human_answer', 'human', m.conversation_id, m.id,
       (SELECT ha.question FROM human_answers ha WHERE ha.conversation_id = m.conversation_id AND ha.answer = m.body ORDER BY ha.id DESC LIMIT 1),
       m.body,
       JSON_OBJECT('backfilled', TRUE, 'source', 'messages', 'origin_metadata_unavailable', TRUE), m.created_at
FROM messages m
WHERE m.sender = 'human'
  AND NOT EXISTS (
    SELECT 1 FROM audit_logs a
    WHERE a.event_type = 'human_answer' AND a.message_id = m.id
  );
