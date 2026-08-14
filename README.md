# RAGLocal — RAG local configurável

Aplicação PHP de **Retrieval-Augmented Generation (RAG)** para responder perguntas usando documentos indexados, como regulamentos internos, atas, certificados técnicos, políticas, manuais e memórias humanas validadas. Cada instalação pode representar uma empresa, condomínio, associação ou outra organização, sem identidade fixa no código.

O sistema usa MariaDB para indexação textual e integra-se a um servidor Ollama configurado externamente. O nome exibido, o subtítulo, o logotipo, o modelo de chat, os limiares de confiança e os documentos são específicos de cada instalação.

## Personalização por empresa

A identidade pública é configurada no painel administrativo. O administrador pode definir o nome exibido, uma descrição curta e um logotipo em PNG, JPG, WEBP ou GIF de até 2 MB. O logotipo é armazenado em diretório privado e servido por uma rota controlada da aplicação; o diretório de uploads não é publicado diretamente pelo NGINX.

Quando uma nova empresa clona o repositório, basta criar um arquivo `config/.env` próprio, configurar o MariaDB, definir o endpoint do Ollama e aplicar o schema. A marca inicial pode ser definida por `APP_BRAND_NAME` e `APP_BRAND_SUBTITLE`, ou alterada depois pelo painel.

## Princípios de segurança e fundamentação

A aplicação só publica uma resposta automática quando há evidência recuperada da base, fontes citadas e confiança acima do limiar configurado. Quando a evidência é insuficiente, a pergunta é encaminhada para atendimento humano; o rascunho calculado pelo modelo fica disponível no painel administrativo para referência.

Respostas humanas validadas entram novamente no RAG como memória aprovada. Para coincidência exata, a resposta pode ser reutilizada de forma determinística; para formulações equivalentes, a memória é enviada como contexto confiável ao Ollama, que precisa responder à pergunta atual exclusivamente com base nessa evidência. A equivalência exige cobertura lexical alta e, em caso de empate entre memórias com respostas diferentes, não é aplicada. Assim, o sistema aprende novas evidências sem se transformar em um mecanismo que apenas reproduz respostas humanas ou em um processo de treinamento não auditado.

Durante o upload, PDF, TXT e MD são convertidos para um Markdown canônico compatível com o contexto do Qwen3. O arquivo enviado é usado apenas durante a conversão e não é persistido. O único artefato armazenado é o Markdown, que inclui front matter, identificador de formato, tipo documental, hash, seções e marcadores `[RAG_DOCUMENTO]`, `[FONTE]`, `[TIPO]`, `[SEÇÃO]` e `[TAGS]`. Os chunks também guardam título da seção, tags, páginas quando identificáveis e contagem aproximada de tokens. O Markdown fica em armazenamento privado e no MariaDB como artefato versionável por hash; os chunks são indexados com FULLTEXT.

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
| `database/migration_009_markdown_only.sql` | Remoção de artefatos originais e retenção exclusiva do Markdown |
| `database/migration_010_ignore_question.sql` | Perguntas ignoradas e resposta padrão fora do contexto |
| `database/migration_011_secure_admin_password.sql` | Troca obrigatória da senha temporária e auditoria de alteração |
| `database/migration_012_news_connector.sql` | Tipo Notícias, metadados WordPress e histórico de sincronização |
| `database/migration_013_ai_guidance.sql` | Diretrizes da IA, orientação pública, identidade e regras interpretativas |
| `src/NewsConnector.php` | Conector somente leitura, importação incremental e Markdown de notícias |
| `src/AiGuidance.php` | Validação, Markdown canônico e contexto controlado das diretrizes administrativas |
| `src/NewsSecrets.php` | Proteção AES-GCM da senha do banco editorial usando `APP_SECRET` |
| `bin/sync_news.php` | Comando para sincronização manual ou diária via cron |
| `config/.env.example` | Exemplo sanitizado de configuração para novos ambientes |
| `bin/bootstrap_admin.php` | Criação ou atualização do administrador por argumentos de linha de comando |
| `bin/backup.sh` | Backup parametrizável do banco e da configuração privada |

## Configuração para uma nova empresa

Copie `config/.env.example` para `config/.env` fora da árvore pública e substitua os placeholders. Nunca publique `config/.env`, dumps de banco, documentos, logs, chaves SSH ou senhas.

As variáveis essenciais são `DB_*`, `APP_TIMEZONE`, `APP_BRAND_NAME`, `APP_BRAND_SUBTITLE`, `OLLAMA_URL`, `OLLAMA_ALLOWED_HOST`, `OLLAMA_SOURCE_IP`, `OLLAMA_CHAT_MODEL`, `OLLAMA_TIMEOUT`, `RAG_UPLOAD_DIR`, `RAG_MIN_CONFIDENCE` e `RAG_MIN_SOURCES`. `RAG_UPLOAD_DIR` deve apontar para uma pasta privada gravável pelo usuário do PHP-FPM. Os valores `RAG_APP_ROOT`, `RAG_BACKUP_ROOT`, `RAG_BACKUP_PREFIX` e `RAG_BACKUP_RETENTION_DAYS` parametrizam o script de backup.

Em um ambiente compartilhado, configure o firewall do servidor Ollama para aceitar somente o IP público do servidor web e, preferencialmente, proteja o transporte com VPN, túnel ou HTTPS. O valor `OLLAMA_SOURCE_IP` deve corresponder ao IP local permitido no firewall remoto.

## Instalação

Use PHP 8.2 ou superior com PDO MySQL, cURL, MariaDB e os utilitários `pdftotext`, `pdftoppm`, `tesseract` e o idioma `por` para ingestão de PDFs. Os documentos podem ser classificados como regulamento interno, ata, manutenção técnica ou memória validada; a aplicação também pode ser adaptada para outras categorias conforme o negócio.

Crie o banco a partir de `database/schema.sql` ou aplique as migrações em ordem, incluindo `database/migration_012_news_connector.sql` e `database/migration_013_ai_guidance.sql` em instalações existentes. Crie o administrador com `bin/bootstrap_admin.php`. Se a senha não for informada, o script gera uma senha temporária aleatória, exibe-a uma única vez no terminal e marca a conta para troca obrigatória no primeiro acesso. A senha opcional fica no quinto argumento e o nome da pessoa ou equipe administradora no sexto argumento. Configure o NGINX para encaminhar a aplicação ao PHP-FPM e bloqueie a árvore privada de armazenamento por regra explícita.

Após o primeiro login, substitua a senha temporária na tela de Segurança; enquanto isso não ocorrer, o painel administrativo permanece bloqueado. A aplicação armazena somente o hash da senha e não registra a senha em auditoria ou logs. Antes de publicar, valide com `php -l public/index.php` e confirme que o PHP-FPM consegue gravar em `RAG_UPLOAD_DIR`. A primeira execução deve ser testada com um documento pequeno, verificando a criação do Markdown RAG e dos chunks no MariaDB; o arquivo enviado existe somente durante a conversão.

## Conector de notícias WordPress

O painel administrativo possui a seção **Notícias**, onde o administrador configura o servidor, a porta, o banco, o usuário somente leitura, a tabela, o `post_type` e o modelo do link público. Para a estrutura WordPress fornecida, os valores iniciais são `wp_posts` e `pmjs_noticia`. A senha do banco editorial é armazenada de forma protegida com `APP_SECRET`; ela nunca deve ser colocada no repositório ou em mensagens de commit.

O conector consulta apenas registros com `post_type = 'pmjs_noticia'`, `post_status = 'publish'` e data de publicação válida. Cada registro é identificado pelo `ID` original e pelo hash do conteúdo normalizado. Notícias sem alteração não são reprocessadas; notícias editadas atualizam o Markdown e os chunks existentes; notícias que deixam de estar publicadas são retiradas do índice ativo. O processo é independente, pode ser iniciado pelo botão **Sincronizar agora** e não consulta o banco editorial durante as perguntas dos usuários.

O modelo de URL pode usar `https://site.exemplo/noticias/{slug}`, `https://site.exemplo/noticia/{id}` ou `{guid}`. Se ficar vazio, o conector usa o campo `guid` quando ele for uma URL HTTP ou HTTPS. O link público é guardado nos metadados do documento e aparece na resposta como fonte clicável.

Para executar diariamente, configure no servidor uma entrada semelhante à seguinte, ajustando o caminho da instalação e o usuário do PHP:

```cron
15 3 * * * www-data /usr/bin/php /var/www/raglocal/bin/sync_news.php >> /var/log/raglocal-news-sync.log 2>&1
```

O usuário do cron precisa ter acesso ao `config/.env`, ao banco local e ao diretório privado `RAG_UPLOAD_DIR`. A conta do banco editorial deve possuir somente `SELECT` na tabela de notícias. O comando registra cada execução em `news_sync_runs` e também na auditoria com o evento `news_sync`.

## Diretrizes administrativas da IA

A seção **Diretrizes da IA** permite personalizar a mensagem inicial exibida no atendimento público, a identidade comportamental do assistente e regras de interpretação. A orientação inicial padrão é: “Consulte o regimento interno e as atas do condomínio. A IA responde somente quando encontra evidência suficiente na base; caso contrário, encaminha a pergunta para atendimento humano.” Ela pode ser alterada pelo administrador sem editar código.

A identidade padrão determina que o assistente se comunique em português brasileiro, de forma clara, respeitosa, objetiva e acolhedora, sem inventar fatos e com encaminhamento humano quando a evidência for insuficiente. O marcador `{empresa}` é substituído automaticamente pelo nome definido na identidade da organização.

Regras interpretativas devem ser cadastradas uma por linha no formato `termo => significado`; por exemplo, `SIGLA => Nome completo da organização`. Elas são aplicadas como instruções de interpretação e não como fonte de fatos. Ao salvar, o RAGLocal gera e atualiza exclusivamente o artefato privado `ai-guidance.rag.md`, seus metadados e um evento `guidance_updated` na auditoria. O documento de diretrizes é excluído da busca de evidências para que não possa, sozinho, autorizar uma resposta automática.

## Fila administrativa e aprendizado controlado

As perguntas encaminhadas para atendimento humano são exibidas pela data e hora da mensagem original, em ordem decrescente. O painel mostra o tempo de espera, o rascunho da IA e permite registrar a resposta validada. Também é possível **ignorar uma pergunta sem responder**; nesse caso, ela sai da fila, a conversa é encerrada e a decisão permanece na auditoria.

Quando a evidência é insuficiente, a resposta pública usa o texto configurado em **Confiabilidade e Ollama**. O marcador `{empresa}` é substituído automaticamente pelo nome definido em Identidade da empresa. O padrão inicial é: “As perguntas devem ser referentes a {empresa}. Sua pergunta não está no contexto deste agente.” Essa resposta pode ser personalizada sem alterar a política de fundamentação. A resposta humana validada é incorporada à memória, mas continua sujeita à recuperação por intenção, à auditoria e às regras de fundamentação; ela não altera os parâmetros do modelo nem treina pesos do Ollama.

## Versionamento e privacidade

Cada alteração deve ter um commit descritivo e ser enviada ao repositório remoto. Antes do commit, execute uma busca por segredos e confirme que somente arquivos sanitizados serão publicados. Dados de produção, documentos privados, caminhos específicos de servidores e credenciais devem permanecer fora do repositório público.

## Aviso

Este projeto é um ponto de partida operacional para RAG fundamentado. A qualidade das respostas depende dos documentos indexados, da revisão humana e do modelo local selecionado. Para informações normativas, a fonte oficial e a administração da organização prevalecem sobre qualquer resposta automatizada.
