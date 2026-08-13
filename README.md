# Jaraguá Tower IA

Aplicação PHP de RAG para responder dúvidas sobre regimento interno, atas, documentos de manutenção técnica e respostas validadas de um condomínio. O sistema usa MariaDB para indexação textual e integra-se a um servidor Ollama configurado externamente.

## Princípios de segurança

A aplicação só publica uma resposta automática quando há evidência recuperada da base, fontes citadas e confiança acima do limiar configurado. Quando a evidência é insuficiente, a pergunta é encaminhada para atendimento humano; o rascunho calculado pelo modelo fica disponível no painel administrativo para referência. Respostas humanas validadas entram novamente no RAG como memória aprovada. Para coincidência exata, a resposta pode ser reutilizada de forma determinística; para formulações equivalentes, a memória é enviada como contexto confiável ao Ollama, que precisa responder à pergunta atual exclusivamente com base nessa evidência. A equivalência exige cobertura lexical alta e, em caso de empate entre memórias com respostas diferentes, não é aplicada. Assim, o sistema aprende novas evidências sem transformar a aplicação em um mecanismo que apenas reproduz respostas humanas ou em um processo de treinamento não auditado.

Durante o upload, PDF, TXT e MD são convertidos para um Markdown canônico compatível com o contexto do Qwen3. O artefato inclui front matter, identificador de formato, tipo documental, hash, seções, marcadores `[RAG_DOCUMENTO]`, `[FONTE]`, `[TIPO]`, `[SEÇÃO]` e `[TAGS]`. Os chunks também guardam título da seção, tags, páginas quando identificáveis e contagem aproximada de tokens. O original e o Markdown ficam em armazenamento privado bloqueado pelo NGINX; o Markdown é mantido no MariaDB como artefato versionável por hash e os chunks são indexados com FULLTEXT.

Todas as perguntas, respostas públicas, rascunhos da IA e respostas humanas são registradas na tabela `audit_logs`, junto com o IP de origem, porta de origem quando fornecida pelo proxy, User-Agent, método, URI, host, referenciador, cabeçalho `X-Forwarded-For` e um hash de sessão. Os dados de auditoria não são exibidos na interface pública. A migração de backfill cobre mensagens anteriores, mas marca que os metadados de origem não estavam disponíveis antes da instrumentação.

A chamada de saída ao Ollama pode ser vinculada a um IP local específico por `OLLAMA_SOURCE_IP`. Em produção, esse valor deve ser o IP público autorizado no firewall do servidor Ollama. A configuração também pode exigir o host esperado por meio de `OLLAMA_ALLOWED_HOST`.

## Estrutura

| Caminho | Função |
|---|---|
| `public/index.php` | Front controller, atendimento, administração e integração com Ollama |
| `database/schema.sql` | Schema completo para instalação nova |
| `database/migration_001_confidence.sql` | Confiança e configurações do RAG |
| `database/migration_002_audit.sql` | Auditoria de perguntas, respostas e metadados |
| `database/migration_003_backfill_audit.sql` | Backfill idempotente de mensagens históricas |
| `database/migration_004_maintenance_kind.sql` | Categoria de manutenção e reclassificação segura do certificado |
| `database/migration_006_response_timing.sql` | Duração das respostas automáticas na auditoria |
| `database/migration_007_rag_artifacts.sql` | Artefatos Markdown privados e metadados estruturados de chunks |
| `config/.env.example` | Exemplo sanitizado de configuração |
| `bin/bootstrap_admin.php` | Bootstrap de administrador por argumentos de linha de comando |
| `bin/backup.sh` | Backup local do banco e configuração privada |

## Configuração

Copie `config/.env.example` para `config/.env` fora da árvore pública e substitua os placeholders. Nunca publique `config/.env`, dumps de banco, documentos do condomínio, logs, chaves SSH ou senhas.

As variáveis essenciais são `DB_*`, `APP_TIMEZONE`, `OLLAMA_URL`, `OLLAMA_ALLOWED_HOST`, `OLLAMA_SOURCE_IP`, `OLLAMA_CHAT_MODEL`, `OLLAMA_TIMEOUT`, `RAG_UPLOAD_DIR`, `RAG_MIN_CONFIDENCE` e `RAG_MIN_SOURCES`. `RAG_UPLOAD_DIR` deve apontar para uma pasta fora da raiz pública e gravável pelo usuário do PHP-FPM. Em um ambiente compartilhado, configure o firewall do servidor Ollama para aceitar somente o IP público do servidor web e, preferencialmente, proteja o transporte com VPN, túnel ou HTTPS.

## Instalação

Use PHP 8.2 ou superior com PDO MySQL, cURL, MariaDB e os utilitários `pdftotext`, `pdftoppm`, `tesseract` e o idioma `por` para ingestão de PDFs. Os documentos podem ser classificados como **Regimento interno**, **Ata**, **Manutenção** (certificados, laudos e comprovantes técnicos) ou **Memória validada**. Na resposta pública, as fontes são exibidas no formato `Fonte: título do documento` e, abaixo delas, aparece em fonte discreta o tempo total para localizar e processar a resposta. Essa duração também é registrada em milissegundos na auditoria; registros históricos permanecem sem duração quando ela não podia ser reconstruída. PDFs com camada de texto usam extração direta; PDFs digitalizados ou sem texto utilizam OCR controlado em `por+eng`. Após a extração, o documento é normalizado e convertido para Markdown RAG, enquanto o original e o artefato gerado são guardados em diretório privado. Os caminhos e parâmetros podem ser ajustados por `PDFTOTEXT_BIN`, `PDFTOPPM_BIN`, `TESSERACT_BIN`, `OCR_ENABLED`, `OCR_LANG`, `OCR_DPI` e `RAG_UPLOAD_DIR`. Crie o banco a partir de `database/schema.sql` ou aplique as migrações em ordem. Valide com `php -l public/index.php` antes de publicar.

## Fila administrativa

As perguntas encaminhadas para atendimento humano são exibidas pela data/hora da mensagem original do morador, em ordem decrescente: a mais recente aparece primeiro. Para cada item, o painel mostra a data e hora de recebimento e calcula o tempo transcorrido até o momento da consulta, usando o fuso definido em `APP_TIMEZONE` (por padrão, `America/Sao_Paulo`). A consulta seleciona somente a pergunta residente mais recente de cada conversa pendente, evitando duplicações.

## Versionamento

Cada alteração deve ter um commit descritivo e ser enviada ao repositório remoto. Antes do commit, execute uma busca por segredos e confirme que somente arquivos sanitizados serão publicados. O repositório público contém código e documentação genérica; dados de produção permanecem exclusivamente na VPS.

## Aviso

Este projeto é um ponto de partida operacional. A qualidade das respostas depende dos documentos indexados, da revisão humana e do modelo local selecionado. Para informações normativas, a fonte oficial e a administração do condomínio prevalecem sobre qualquer resposta automatizada.
