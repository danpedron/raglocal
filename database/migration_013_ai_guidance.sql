-- Diretrizes administrativas da IA: identidade, orientação inicial e regras interpretativas.

ALTER TABLE documents
  MODIFY kind ENUM('regimento','ata','memoria','manutencao','noticia','diretriz') NOT NULL;

ALTER TABLE audit_logs
  MODIFY event_type ENUM('question','ai_answer','human_answer','login_success','login_failure','document_upload','question_ignored','password_changed','news_sync','guidance_updated') NOT NULL;

INSERT INTO settings(name, value) VALUES
  ('ai_public_intro', 'Consulte o regimento interno e as atas do condomínio. A IA responde somente quando encontra evidência suficiente na base; caso contrário, encaminha a pergunta para atendimento humano.'),
  ('ai_soul', 'Você é o assistente oficial de {empresa}. Atenda em português brasileiro de forma clara, respeitosa, objetiva e acolhedora. Priorize informar com precisão, explicar limites de forma transparente e orientar o usuário ao atendimento humano quando a base não sustentar uma resposta. Preserve neutralidade institucional, não emita julgamentos pessoais e não invente fatos, interpretações, prazos, regras ou decisões.'),
  ('ai_interpretation_rules', '')
ON DUPLICATE KEY UPDATE name = VALUES(name);
