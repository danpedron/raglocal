# RAG Assistant — base de conhecimento configurável

Aplicação PHP de **Retrieval-Augmented Generation (RAG)** para responder perguntas usando documentos indexados, como regulamentos internos, atas, certificados técnicos, políticas, manuais e memórias humanas validadas. Cada instalação pode representar uma empresa, condomínio, associação ou outra organização, sem identidade fixa no código.

O sistema usa MariaDB para indexação textual e integra-se a um servidor Ollama configurado externamente. O nome exibido, o subtítulo, o logotipo, o modelo de chat, os limiares de confiança e os documentos são específicos de cada instalação.

## Personalização por empresa

A identidade pública é configurada no painel administrativo. O administrador pode definir o nome exibido, uma descrição curta e um logotipo em PNG, JPG, WEBP ou GIF de até 2 MB. O logotipo é armazenado em diretório privado e servido por uma rota controlada da aplicação; o diretório de uploads não é publicado diretamente pelo NGINX.

Quando uma nova empresa clona o repositório, basta criar um arquivo `config/.env` próprio, configurar o MariaDB, definir o endpoint do Ollama e aplicar o schema. A marca inicial pode ser definida por `APP_BRAND_NAME` e `APP_BRAND_SUBTITLE`, ou alterada depois pelo painel.

## Princípios de segurança e fundamentação

A aplicação só publica uma resposta automática quando há evidência recuperada da base, fontes citadas e confiança acima do limiar configurado. Quando a evidência é insuficiente, a pergunta é encaminhada para atendimento humano; o rascunho calculado pelo modelo fica disponível no painel administrativo para referência.

Respostas humanas validadas entram novamente no RAG como memória aprovada. Para coincidência exata, a resposta pode ser reutilizada de forma determinística; para formulações equivalentes, a memória é enviada como contexto confiável ao Ollama, que precisa responder à pergunta atual exclusivamente com base nessa evidência. A equivalência exige cobertura lexical alta e, em caso de empate entre memórias com respostas diferentes, não é aplicada. Assim, o sistema aprende novas evidências sem se transformar em um mecanismo que apenas reproduz respostas humanas ou em um processo de treinamento não auditado.

Durante o upload, PDF, TXT e MD são convertidos para um Markdown canônico compatível com o contexto do Qwen3. O artefato inclui front matter, identificador de formato, tipo documental, hash, seções e marcadores `[RAG_DOCUMENTO]`, `[FONTE]`, `[TIPO]`, `[SEÇÃO]` e `[TAGS]`. Os chunks também guardam título da seção, tags, páginas quando identificáveis e contagem aproximada de tokens. O original e o Markdown ficam em armazenamento privado; o Markdown é mantido no MariaDB como artefato versionável por hash e os chunks são indexados com FULLTEXT.

Todas as perguntas, respostas públicas, rascunhos da IA e respostas humanas são registradas na tabela `audit_logs`, junto com IP de origem, porta quando fornecida pelo proxy, User-Agent, método, URI, host, referenciador, cabeçalho `X-Forwarded-For` e hash de sessão. Os dados de auditoria não são exibidos na interface pública.

## Estrutura

| Caminho | Função |
|---|---|
| `public/index.php` | Front controller, atendimento, administração, marca e integração com Ollama |
| `database/schema.sql` | Schema completo para instalação nova |
| `database/migration_001_confidence.sql` | Confiança e configurações do RAG |
| `database/migration_002_audit.sql` | Auditoria de perguntas, respostas e metadados |
| `database/migration_003_backfill_audit.sql` | Backfill idempotente de mensagens históricas |
| `database/migration_004_maintenance_kind.sql` | Categoria de manutenção técnica |
| `database/migration_006_response_timing.sql` | Duração das respostas automáticas na auditoria |
| `database/migration_007_rag_artifacts.sql` | Artefatos Markdown privados e metadados estruturados de chunks |
| `database/migration_008_branding.sql` | Nome, subtítulo e logotipo configuráveis |
| `config/.env.example` | Exemplo sanitizado de configuração para novos ambientes |
| `bin/bootstrap_admin.php` | Criação ou atualização do administrador por argumentos de linha de comando |
| `bin/backup.sh` | Backup parametrizável do banco e da configuração privada |

## Configuração para uma nova empresa

Copie `config/.env.example` para `config/.env` fora da árvore pública e substitua os placeholders. Nunca publique `config/.env`, dumps de banco, documentos, logs, chaves SSH ou senhas.

As variáveis essenciais são `DB_*`, `APP_TIMEZONE`, `APP_BRAND_NAME`, `APP_BRAND_SUBTITLE`, `OLLAMA_URL`, `OLLAMA_ALLOWED_HOST`, `OLLAMA_SOURCE_IP`, `OLLAMA_CHAT_MODEL`, `OLLAMA_TIMEOUT`, `RAG_UPLOAD_DIR`, `RAG_MIN_CONFIDENCE` e `RAG_MIN_SOURCES`. `RAG_UPLOAD_DIR` deve apontar para uma pasta privada gravável pelo usuário do PHP-FPM. Os valores `RAG_APP_ROOT`, `RAG_BACKUP_ROOT`, `RAG_BACKUP_PREFIX` e `RAG_BACKUP_RETENTION_DAYS` parametrizam o script de backup.

Em um ambiente compartilhado, configure o firewall do servidor Ollama para aceitar somente o IP público do servidor web e, preferencialmente, proteja o transporte com VPN, túnel ou HTTPS. O valor `OLLAMA_SOURCE_IP` deve corresponder ao IP local permitido no firewall remoto.

## Instalação

Use PHP 8.2 ou superior com PDO MySQL, cURL, MariaDB e os utilitários `pdftotext`, `pdftoppm`, `tesseract` e o idioma `por` para ingestão de PDFs. Os documentos podem ser classificados como regulamento interno, ata, manutenção técnica ou memória validada; a aplicação também pode ser adaptada para outras categorias conforme o negócio.

Crie o banco a partir de `database/schema.sql` ou aplique as migrações em ordem. Crie o administrador com `bin/bootstrap_admin.php`, informando opcionalmente o nome da pessoa ou equipe administradora como sexto argumento. Configure o NGINX para encaminhar a aplicação ao PHP-FPM e bloqueie a árvore privada de armazenamento por regra explícita.

Antes de publicar, valide com `php -l public/index.php` e confirme que o PHP-FPM consegue gravar em `RAG_UPLOAD_DIR`. A primeira execução deve ser testada com um documento pequeno, verificando a criação do original privado, do Markdown RAG e dos chunks no MariaDB.

## Fila administrativa e aprendizado controlado

As perguntas encaminhadas para atendimento humano são exibidas pela data e hora da mensagem original, em ordem decrescente. O painel mostra o tempo de espera, o rascunho da IA e permite registrar a resposta validada. Essa resposta é incorporada à memória, mas continua sujeita à recuperação por intenção, à auditoria e às regras de fundamentação; ela não altera os parâmetros do modelo nem treina pesos do Ollama.

## Versionamento e privacidade

Cada alteração deve ter um commit descritivo e ser enviada ao repositório remoto. Antes do commit, execute uma busca por segredos e confirme que somente arquivos sanitizados serão publicados. Dados de produção, documentos privados, caminhos específicos de servidores e credenciais devem permanecer fora do repositório público.

## Aviso

Este projeto é um ponto de partida operacional para RAG fundamentado. A qualidade das respostas depende dos documentos indexados, da revisão humana e do modelo local selecionado. Para informações normativas, a fonte oficial e a administração da organização prevalecem sobre qualquer resposta automatizada.
