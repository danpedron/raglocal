# RAGLocal — RAG local configurável

Aplicação PHP de **Retrieval-Augmented Generation (RAG)** para responder perguntas usando documentos indexados, como regulamentos internos, atas, certificados técnicos, políticas, manuais e memórias humanas validadas. Cada instalação pode representar uma empresa, condomínio, associação ou outra organização, sem identidade fixa no código.

O sistema usa MariaDB para indexação textual e integra-se a um servidor Ollama configurado externamente. O nome exibido, o subtítulo, o logotipo, o modelo de chat, os limiares de confiança e os documentos são específicos de cada instalação.

## Personalização por empresa

A identidade pública é configurada no painel administrativo. O administrador pode definir o nome exibido, uma descrição curta e um logotipo em PNG, JPG, WEBP ou GIF de até 2 MB. O logotipo é armazenado em diretório privado e servido por uma rota controlada da aplicação; o diretório de uploads não é publicado diretamente pelo NGINX.

Quando uma nova empresa clona o repositório, basta criar um arquivo `config/.env` próprio, configurar o MariaDB, definir o endpoint do Ollama e aplicar o schema. A marca inicial pode ser definida por `APP_BRAND_NAME` e `APP_BRAND_SUBTITLE`, ou alterada depois pelo painel.

## Princípios de segurança e fundamentação

A aplicação só publica uma resposta automática quando há evidência recuperada da base, fontes citadas e confiança acima do limiar configurado. Quando a evidência é insuficiente, a pergunta é encaminhada para atendimento humano; o rascunho calculado pelo modelo fica disponível no painel administrativo para referência. Se o Ollama estiver indisponível ou exceder o timeout, isso é registrado como falha técnica separada (`ollama_unreachable` ou `ollama_timeout`), a pergunta permanece na fila e a interface informa que o serviço de IA não concluiu o processamento, sem classificar o caso como falta de evidência.

Respostas humanas validadas entram novamente no RAG como memória aprovada. Para coincidência exata, a resposta pode ser reutilizada de forma determinística; para formulações equivalentes, a memória é enviada como contexto confiável ao Ollama, que precisa responder à pergunta atual exclusivamente com base nessa evidência. A equivalência exige cobertura lexical alta e, em caso de empate entre memórias com respostas diferentes, não é aplicada. Assim, o sistema aprende novas evidências sem se transformar em um mecanismo que apenas reproduz respostas humanas ou em um processo de treinamento não auditado.

Durante o upload, PDF, TXT e MD são convertidos para um Markdown canônico compatível com o contexto do Qwen3. O arquivo enviado é usado apenas durante a conversão e não é persistido. O único artefato armazenado é o Markdown, que inclui front matter, identificador de formato, tipo documental, hash, seções e marcadores `[RAG_DOCUMENTO]`, `[FONTE]`, `[TIPO]`, `[SEÇÃO]` e `[TAGS]`. A versão `rag-v2` reconhece regras Markdown (`---`) imediatamente antes de títulos como limites de seção, mantendo cada serviço de catálogos extensos em chunks recuperáveis separados. Os chunks também guardam título da seção, tags, páginas quando identificáveis e contagem aproximada de tokens. O Markdown fica em armazenamento privado e no MariaDB como artefato versionável por hash; os chunks são indexados com FULLTEXT. A recuperação também usa uma consulta booleana por prefixo para alcançar variações morfológicas, como `vacina` e `vacinas`, sem depender de uma chamada adicional ao modelo.

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
| `database/migration_012_news_connector.sql` | Compatibilidade histórica do conector WordPress |
| `database/migration_013_ai_guidance.sql` | Diretrizes da IA, orientação pública, identidade e regras interpretativas |
| `database/migration_014_services_reassessment.sql` | Compatibilidade histórica da Carta de Serviços e reavaliação auditável |
| `database/migration_015_generic_sources.sql` | Registro de fontes/plugins, vínculos de documentos e histórico de sincronização |
| `database/migration_016_generic_sources_cleanup.sql` | Limpeza idempotente de fontes legadas vazias |
| `database/migration_017_local_glossary.sql` | Termos recorrentes e relações locais de coocorrência |
| `src/RagGlossary.php` | Registro local de termos, relações recorrentes e expansão controlada da busca |
| `src/SourceRegistry.php` | Catálogo de plugins, fontes ativáveis, estado e remoção segura |
| `src/RagSearchTerms.php` | Normalização determinística e termos de busca por prefixo |
| `src/DatabaseTablePlugin.php` | Plugin genérico de banco/tabela com Loader, Chunker e atualização incremental |
| `src/AiGuidance.php` | Validação, Markdown canônico e contexto controlado das diretrizes administrativas |
| `src/SecretBox.php` | Proteção AES-GCM de credenciais de fontes usando `APP_SECRET` |
| `bin/sync_sources.php` | Sincronização diária de todas as fontes ativadas |
| `config/.env.example` | Exemplo sanitizado de configuração para novos ambientes |
| `bin/bootstrap_admin.php` | Criação ou atualização do administrador por argumentos de linha de comando |
| `bin/backup.sh` | Backup parametrizável do banco e da configuração privada |

## Configuração para uma nova empresa

Copie `config/.env.example` para `config/.env` fora da árvore pública e substitua os placeholders. Nunca publique `config/.env`, dumps de banco, documentos, logs, chaves SSH ou senhas.

As variáveis essenciais são `DB_*`, `APP_TIMEZONE`, `APP_BRAND_NAME`, `APP_BRAND_SUBTITLE`, `OLLAMA_URL`, `OLLAMA_ALLOWED_HOST`, `OLLAMA_SOURCE_IP`, `OLLAMA_CHAT_MODEL`, `OLLAMA_TIMEOUT`, `RAG_UPLOAD_DIR`, `RAG_MIN_CONFIDENCE` e `RAG_MIN_SOURCES`. `RAG_UPLOAD_DIR` deve apontar para uma pasta privada gravável pelo usuário do PHP-FPM. Os valores `RAG_APP_ROOT`, `RAG_BACKUP_ROOT`, `RAG_BACKUP_PREFIX` e `RAG_BACKUP_RETENTION_DAYS` parametrizam o script de backup.

Em um ambiente compartilhado, configure o firewall do servidor Ollama para aceitar somente o IP público do servidor web e, preferencialmente, proteja o transporte com VPN, túnel ou HTTPS. O valor `OLLAMA_SOURCE_IP` deve corresponder ao IP local permitido no firewall remoto.

## Instalação

Use PHP 8.2 ou superior com PDO MySQL, cURL, MariaDB e os utilitários `pdftotext`, `pdftoppm`, `tesseract` e o idioma `por` para ingestão de PDFs. Os documentos podem ser classificados como regulamento interno, ata, manutenção técnica ou memória validada; a aplicação também pode ser adaptada para outras categorias conforme o negócio.

Crie o banco a partir de `database/schema.sql` ou aplique as migrações em ordem, incluindo `database/migration_012_news_connector.sql`, `database/migration_013_ai_guidance.sql`, `database/migration_014_services_reassessment.sql`, `database/migration_015_generic_sources.sql`, `database/migration_016_generic_sources_cleanup.sql` e `database/migration_017_local_glossary.sql` em instalações existentes. Crie o administrador com `bin/bootstrap_admin.php`. Se a senha não for informada, o script gera uma senha temporária aleatória, exibe-a uma única vez no terminal e marca a conta para troca obrigatória no primeiro acesso. A senha opcional fica no quinto argumento e o nome da pessoa ou equipe administradora no sexto argumento. Configure o NGINX para encaminhar a aplicação ao PHP-FPM e bloqueie a árvore privada de armazenamento por regra explícita.

Após o primeiro login, substitua a senha temporária na tela de Segurança; enquanto isso não ocorrer, o painel administrativo permanece bloqueado. A aplicação armazena somente o hash da senha e não registra a senha em auditoria ou logs. Antes de publicar, valide com `php -l public/index.php` e confirme que o PHP-FPM consegue gravar em `RAG_UPLOAD_DIR`. A primeira execução deve ser testada com um documento pequeno, verificando a criação do Markdown RAG e dos chunks no MariaDB; o arquivo enviado existe somente durante a conversão.

Na seção **Base de conhecimento**, cada documento mostra a versão do processador Markdown/RAG, a data de processamento, o hash e a quantidade de trechos gerados. Arquivos enviados manualmente podem ser excluídos com confirmação; a ação remove o documento, seus chunks e o Markdown privado, registra a operação na auditoria e permite o reenvio de uma versão atualizada. Diretrizes da IA, memórias geradas automaticamente e documentos vinculados a fontes externas não são apagados por essa ação: devem ser mantidos pelos fluxos administrativos correspondentes.

## Plugins e fontes externas

O painel administrativo possui uma única seção **Fontes de conhecimento**. Ela não presume Notícias, Produtos, Pesquisas, Estoque ou qualquer outro domínio. Cada fonte é uma instância independente de um plugin instalado e pode ser criada, editada, sincronizada, ativada, desativada ou removida pelo administrador. Se nenhuma fonte externa for configurada, o RAGLocal continua funcionando somente com os documentos enviados diretamente pela base de conhecimento.

O plugin inicial **Banco de dados — tabela** é genérico: conecta-se a uma tabela MariaDB/MySQL somente leitura, permite mapear chave, título, colunas de conteúdo, filtros, status, datas e URL pública e transforma cada registro em um documento Markdown RAG. A mesma implementação pode representar posts, notícias, pesquisas, produtos, itens de estoque, contratos ou outro conjunto estruturado, sem criar uma aba específica no núcleo. O nome, a descrição e a chave interna são definidos por instalação.

Cada fonte externa pode ser configurada com uma conta de leitura restrita, sem permissões de escrita. A senha é protegida com `APP_SECRET` e nunca aparece no painel, no repositório, na auditoria ou nos logs. Os identificadores de tabela e coluna são validados antes de serem usados em SQL; valores de filtro são enviados como parâmetros. O modelo de URL pública pode usar `{id}` e qualquer coluna mapeada, mas o link só é preservado quando resulta em uma URL HTTP ou HTTPS válida.

A sincronização é incremental e idempotente. Itens sem alteração não são reprocessados; itens alterados atualizam o mesmo documento e seus chunks; itens ausentes podem ser retirados quando a opção correspondente estiver ativada. Desativar uma fonte remove seus documentos da recuperação sem apagar imediatamente os artefatos. Reativá-la restaura apenas os itens retirados pela desativação; itens que realmente não estão mais na origem continuam desativados até reaparecerem. Remover uma fonte exige a confirmação textual `REMOVE`, apaga seus vínculos e exclui apenas documentos que não sejam compartilhados com outra fonte. Toda operação é auditada.

O cron genérico percorre somente fontes ativadas e descobre o executor a partir do registro de plugins. Uma falha em uma fonte não impede o processamento das demais. O bloqueio global e o histórico em `source_sync_runs` evitam execuções concorrentes e permitem observar leituras, inclusões, atualizações, itens inalterados, retiradas, erros e duração.

Exemplo de configuração diária:

```cron
15 3 * * * www-data /usr/bin/php /var/www/raglocal/bin/sync_sources.php >> /var/log/raglocal-source-sync.log 2>&1
```

Para adicionar outro tipo de integração no futuro, instale um plugin que implemente o contrato de fonte, registre seu manifesto no catálogo e disponibilize sua configuração e executor. O núcleo continua consumindo documentos e chunks pelo mesmo pipeline, independentemente do domínio da origem.

## Reavaliação após atualização da base

Na fila de **Intervenção humana**, o administrador pode clicar em **Reavaliar com a base atualizada** depois de importar um novo documento ou sincronizar uma fonte externa. A pergunta é pesquisada novamente no índice atual e uma nova resposta é gravada como mensagem separada, preservando a resposta anterior, o rascunho anterior, as fontes, o modelo, a confiança e o tempo da nova tentativa. A operação é registrada em `ai_reassessments` e na auditoria com o evento `question_reassessed`.

A reavaliação só pode ser executada enquanto a conversa estiver `human_pending`; ela não apaga a pergunta nem a resposta anterior. Se a nova tentativa encontrar evidência suficiente, a conversa sai da fila humana. Caso contrário, permanece pendente com a resposta padrão ou, quando houver apenas evidência relacionada, com uma resposta parcial cuidadosamente limitada e o novo rascunho disponíveis para o atendente.

## Glossário local de uso

A seção **Glossário local** registra, exclusivamente no MariaDB da própria instalação, os termos relevantes de cada pergunta e os pares de termos que passam a aparecer juntos de forma recorrente. O recurso não consulta a internet, não usa um serviço externo em tempo de atendimento e não grava respostas nem interpretações como fatos.

Depois de uma relação aparecer em pelo menos três perguntas, ela pode ampliar a consulta FULLTEXT com peso inferior à correspondência direta e à busca por prefixo. Essa expansão serve somente para recuperar documentos potencialmente relacionados. O Ollama continua recebendo como evidência apenas os trechos recuperados da base corporativa, e uma relação do glossário nunca torna uma resposta automaticamente fundamentada.

Siglas, abreviações e nomenclaturas institucionais continuam sendo cadastradas em **Diretrizes da IA** no formato `termo => significado`. O glossário não substitui, altera ou infere essas regras. Essa separação mantém a interpretação institucional explícita, auditável e sob controle administrativo.

## Diretrizes administrativas da IA

A seção **Diretrizes da IA** permite personalizar a mensagem inicial exibida no atendimento público, a identidade comportamental do assistente e regras de interpretação. A orientação inicial padrão é: “Consulte o regimento interno e as atas do condomínio. A IA responde somente quando encontra evidência suficiente na base; caso contrário, encaminha a pergunta para atendimento humano.” Ela pode ser alterada pelo administrador sem editar código.

A identidade padrão determina que o assistente se comunique em português brasileiro, de forma clara, respeitosa, objetiva e acolhedora, sem inventar fatos e com encaminhamento humano quando a evidência for insuficiente. O marcador `{empresa}` é substituído automaticamente pelo nome definido na identidade da organização.

Regras interpretativas devem ser cadastradas uma por linha no formato `termo => significado`; por exemplo, `SIGLA => Nome completo da organização`. Elas são aplicadas como instruções de interpretação e não como fonte de fatos. Ao salvar, o RAGLocal gera e atualiza exclusivamente o artefato privado `ai-guidance.rag.md`, seus metadados e um evento `guidance_updated` na auditoria. O documento de diretrizes é excluído da busca de evidências para que não possa, sozinho, autorizar uma resposta automática.

## Fila administrativa e aprendizado controlado

As perguntas encaminhadas para atendimento humano são exibidas pela data e hora da mensagem original, em ordem decrescente. O painel mostra o tempo de espera, o rascunho da IA e permite registrar a resposta validada. Também é possível **ignorar uma pergunta sem responder**; nesse caso, ela sai da fila, a conversa é encerrada e a decisão permanece na auditoria.

Quando não há evidência útil, a resposta pública usa o texto configurado em **Confiabilidade e Ollama**. O marcador `{empresa}` é substituído automaticamente pelo nome definido em Identidade da empresa. O padrão inicial é: “As perguntas devem ser referentes a {empresa}. Sua pergunta não está no contexto deste agente.” Essa resposta pode ser personalizada sem alterar a política de fundamentação. Quando a base confirma somente uma parte ou uma categoria mais ampla, o modelo pode retornar `answer_mode=partial` com confiança entre 0,50 e 0,74: a pessoa recebe o trecho confirmado e o limite explícito da base, mas a conversa permanece `human_pending` para confirmar o item específico. Por exemplo, evidência de salas de vacinação não confirma disponibilidade, indicação ou elegibilidade de vacina contra dengue. A resposta humana validada é incorporada à memória, mas continua sujeita à recuperação por intenção, à auditoria e às regras de fundamentação; ela não altera os parâmetros do modelo nem treina pesos do Ollama.

## Versionamento e privacidade

Cada alteração deve ter um commit descritivo e ser enviada ao repositório remoto. Antes do commit, execute uma busca por segredos e confirme que somente arquivos sanitizados serão publicados. Dados de produção, documentos privados, caminhos específicos de servidores e credenciais devem permanecer fora do repositório público.

## Aviso

Este projeto é um ponto de partida operacional para RAG fundamentado. A qualidade das respostas depende dos documentos indexados, da revisão humana e do modelo local selecionado. Para informações normativas, a fonte oficial e a administração da organização prevalecem sobre qualquer resposta automatizada.
