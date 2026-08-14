ALTER TABLE users
  ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER role;

ALTER TABLE audit_logs
  MODIFY event_type ENUM('question','ai_answer','human_answer','login_success','login_failure','document_upload','question_ignored','password_changed') NOT NULL;

-- A senha inicial temporária deve ser substituída no primeiro acesso.
-- Nenhuma senha é armazenada nesta migração.

COMMIT;

