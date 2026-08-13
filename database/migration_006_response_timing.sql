-- Duração medida entre o início da recuperação/geração e a resposta pronta.
-- Valores históricos permanecem NULL porque não podem ser reconstruídos com segurança.
ALTER TABLE audit_logs
  ADD COLUMN IF NOT EXISTS response_time_ms INT UNSIGNED NULL AFTER citations;

CREATE INDEX IF NOT EXISTS idx_audit_response_time ON audit_logs(response_time_ms);
