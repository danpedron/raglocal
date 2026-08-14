<?php
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'secure' => true,
    'samesite' => 'Lax',
]);
session_start();

function load_env(string $file): void
{
    if (!is_file($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value, " \t\"");
    }
}

load_env(dirname(__DIR__) . '/config/.env');
require_once dirname(__DIR__) . '/src/SecretBox.php';
require_once dirname(__DIR__) . '/src/OllamaResponse.php';
require_once dirname(__DIR__) . '/src/RagSearchTerms.php';
require_once dirname(__DIR__) . '/src/AiGuidance.php';
require_once dirname(__DIR__) . '/src/SourceRegistry.php';
require_once dirname(__DIR__) . '/src/DatabaseTablePlugin.php';

date_default_timezone_set((string) ($_ENV['APP_TIMEZONE'] ?? 'America/Sao_Paulo'));

function envv(string $key, string $default = ''): string
{
    return (string) ($_ENV[$key] ?? getenv($key) ?? $default);
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . envv('DB_HOST', 'localhost') . ';port=' . envv('DB_PORT', '3306') . ';dbname=' . envv('DB_NAME') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, envv('DB_USER'), envv('DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function request_metadata(): array
{
    $sourceIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!filter_var($sourceIp, FILTER_VALIDATE_IP)) {
        $sourceIp = '';
    }
    $sourcePort = (int) ($_SERVER['REMOTE_PORT'] ?? 0);
    if ($sourcePort < 1 || $sourcePort > 65535) {
        $sourcePort = 0;
    }
    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
    $referer = substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 2048);
    $forwardedFor = substr((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''), 0, 512);

    return [
        'source_ip' => $sourceIp !== '' ? $sourceIp : null,
        'source_port' => $sourcePort > 0 ? $sourcePort : null,
        'user_agent' => $userAgent !== '' ? $userAgent : null,
        'request_method' => substr((string) ($_SERVER['REQUEST_METHOD'] ?? ''), 0, 16) ?: null,
        'request_uri' => substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 2048) ?: null,
        'referer' => $referer !== '' ? $referer : null,
        'forwarded_for' => $forwardedFor !== '' ? $forwardedFor : null,
        'host' => substr((string) ($_SERVER['HTTP_HOST'] ?? ''), 0, 255) ?: null,
        'session_hash' => hash('sha256', session_id()),
    ];
}

function audit_event(string $eventType, string $actor, array $event = []): void
{
    try {
        $metadata = request_metadata();
        $metadata['app_version'] = envv('APP_VERSION', 'unversioned');
        $metadata['route'] = (string) ($_GET['route'] ?? 'chat');
        $metadata['request_id'] = bin2hex(random_bytes(16));
        $metadata['extra'] = $event['metadata'] ?? [];
        $stmt = db()->prepare('INSERT INTO audit_logs(event_type, actor, conversation_id, message_id, question, answer, ai_draft, ai_confidence, ai_model, citations, response_time_ms, source_ip, source_port, user_agent, request_method, request_uri, referer, forwarded_for, host, session_hash, metadata) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $eventType,
            $actor,
            $event['conversation_id'] ?? null,
            $event['message_id'] ?? null,
            $event['question'] ?? null,
            $event['answer'] ?? null,
            $event['ai_draft'] ?? null,
            $event['ai_confidence'] ?? null,
            $event['ai_model'] ?? null,
            isset($event['citations']) ? json_encode($event['citations'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            isset($event['response_time_ms']) ? max(0, (int) $event['response_time_ms']) : null,
            $metadata['source_ip'],
            $metadata['source_port'],
            $metadata['user_agent'],
            $metadata['request_method'],
            $metadata['request_uri'],
            $metadata['referer'],
            $metadata['forwarded_for'],
            $metadata['host'],
            $metadata['session_hash'],
            json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $error) {
        error_log('RAG application audit failure: ' . $error->getMessage());
    }
}

function ollama_endpoint(): string
{
    $url = rtrim(envv('OLLAMA_URL', ''), '/');
    $host = (string) parse_url($url, PHP_URL_HOST);
    $allowedHost = trim(envv('OLLAMA_ALLOWED_HOST', ''));
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if ($url === '' || !in_array($scheme, ['http', 'https'], true) || $host === '' || ($allowedHost !== '' && !hash_equals($allowedHost, $host))) {
        return '';
    }
    return $url;
}

function ollama_source_ip(): string
{
    $ip = trim(envv('OLLAMA_SOURCE_IP', ''));
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

function app_timezone(): DateTimeZone
{
    static $timezone;
    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }
    try {
        $timezone = new DateTimeZone(envv('APP_TIMEZONE', 'America/Sao_Paulo'));
    } catch (Throwable $error) {
        $timezone = new DateTimeZone('America/Sao_Paulo');
    }
    return $timezone;
}

function parse_app_datetime(?string $value): ?DateTimeImmutable
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($value, app_timezone()))->setTimezone(app_timezone());
    } catch (Throwable $error) {
        return null;
    }
}

function format_datetime_br(?string $value): string
{
    $date = parse_app_datetime($value);
    return $date ? $date->format('d/m/Y H:i:s') : 'data/hora não disponível';
}

function format_response_time(int $milliseconds): string
{
    $milliseconds = max(0, $milliseconds);
    if ($milliseconds < 1000) {
        return number_format($milliseconds, 0, ',', '.') . ' ms';
    }
    return number_format($milliseconds / 1000, 2, ',', '.') . ' s';
}

function waiting_time(?string $value): string
{
    $date = parse_app_datetime($value);
    if (!$date) {
        return 'tempo de espera indisponível';
    }
    $now = new DateTimeImmutable('now', app_timezone());
    $seconds = max(0, $now->getTimestamp() - $date->getTimestamp());
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $parts = [];
    if ($days > 0) {
        $parts[] = $days . ($days === 1 ? ' dia' : ' dias');
    }
    if ($hours > 0) {
        $parts[] = $hours . ($hours === 1 ? ' hora' : ' horas');
    }
    if ($minutes > 0 || !$parts) {
        $parts[] = $minutes . ($minutes === 1 ? ' minuto' : ' minutos');
    }
    return 'aguardando há ' . implode(' e ', $parts);
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function check_csrf(): void
{
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('Token inválido.');
    }
}

function admin(): bool
{
    return !empty($_SESSION['user']) && in_array($_SESSION['user']['role'] ?? '', ['admin', 'attendant'], true);
}

function flash(string $message): void
{
    $_SESSION['flash'] = $message;
}

function take_flash(): string
{
    $message = (string) ($_SESSION['flash'] ?? '');
    unset($_SESSION['flash']);
    return $message;
}

function setting(string $key, string $default = ''): string
{
    try {
        $stmt = db()->prepare('SELECT value FROM settings WHERE name = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    } catch (Throwable $error) {
        return $default;
    }
}

function save_setting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings(name, value) VALUES(?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
    $stmt->execute([$key, $value]);
}

function source_config(PDO $pdo, int $sourceId): array
{
    $source = SourceRegistry::find($pdo, $sourceId);
    if (!$source) {
        throw new InvalidArgumentException('Fonte não encontrada.');
    }
    return SourceRegistry::publicConfig($source);
}

function source_plugin(PDO $pdo, int $sourceId): array
{
    $source = SourceRegistry::find($pdo, $sourceId);
    if (!$source) {
        throw new InvalidArgumentException('Fonte não encontrada.');
    }
    return [$source, (string) $source['plugin_key']];
}

function source_sync(PDO $pdo, int $sourceId, string $trigger = 'manual'): array
{
    [$source, $pluginKey] = source_plugin($pdo, $sourceId);
    $definition = SourceRegistry::plugins()[$pluginKey] ?? null;
    if (!is_array($definition) || empty($definition['syncable'])) {
        throw new InvalidArgumentException('Este plugin não possui sincronização disponível.');
    }
    return SourceRegistry::executor($pdo, $source)->sync($trigger);
}

function source_last_run(PDO $pdo, int $sourceId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM source_sync_runs WHERE source_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$sourceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function source_document_count(PDO $pdo, int $sourceId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM document_source_links WHERE source_id = ? AND is_active = 1');
    $stmt->execute([$sourceId]);
    return (int) $stmt->fetchColumn();
}

function source_form_html(array $source, array $config, string $csrfToken): string
{
    $sourceId = (int) ($source['id'] ?? 0);
    $sourceKey = (string) ($source['source_key'] ?? '');
    $pluginKey = (string) ($source['plugin_key'] ?? 'database_table');
    $name = (string) ($source['name'] ?? '');
    $description = (string) ($source['description'] ?? '');
    $contentColumns = is_array($config['content_columns'] ?? null) ? implode(', ', $config['content_columns']) : (string) ($config['content_columns'] ?? '');
    $checked = !empty($config['withdraw_missing']) ? ' checked' : '';
    $passwordStatus = !empty($config['password_enc']) ? 'Senha protegida já cadastrada; deixe em branco para mantê-la.' : 'Nenhuma senha cadastrada.';
    $pluginDefinitions = SourceRegistry::plugins();
    $pluginOptions = '';
    foreach ($pluginDefinitions as $availableKey => $availablePlugin) {
        if (empty($availablePlugin['syncable']) && $availableKey !== $pluginKey) {
            continue;
        }
        $pluginOptions .= '<option value="' . h((string) $availableKey) . '"' . ($pluginKey === (string) $availableKey ? ' selected' : '') . '>' . h((string) ($availablePlugin['label'] ?? $availableKey)) . '</option>';
    }
    $selectedPluginDescription = is_array($pluginDefinitions[$pluginKey] ?? null) ? (string) ($pluginDefinitions[$pluginKey]['description'] ?? '') : '';
    return '<div class="card"><div class="eyebrow">PLUGIN ATIVÁVEL</div><h3>' . ($sourceId > 0 ? 'Editar fonte' : 'Adicionar fonte') . '</h3><p class="muted">Uma fonte é uma instância configurada de um plugin. O plugin atual lê uma tabela MariaDB externa e transforma os registros em documentos RAG. O núcleo não precisa saber se os registros são notícias, pesquisas, produtos, estoque ou outro domínio.</p><form action="?route=source-save" method="post"><input type="hidden" name="csrf" value="' . h($csrfToken) . '"><input type="hidden" name="source_id" value="' . $sourceId . '"><label>Chave interna<input name="source_key" maxlength="120" pattern="[A-Za-z0-9._-]+" value="' . h($sourceKey) . '" placeholder="ex.: catalogo-produtos" required></label><label>Plugin<select name="plugin_key">' . $pluginOptions . '</select></label><span class="muted">' . h($selectedPluginDescription) . '</span><label>Nome da fonte<input name="source_name" maxlength="255" value="' . h($name) . '" placeholder="ex.: Catálogo de produtos" required></label><label>Descrição curta<textarea name="source_description" maxlength="2000" rows="2">' . h($description) . '</textarea></label><div class="grid"><div><label>Servidor<input name="source_host" maxlength="255" value="' . h((string) ($config['host'] ?? '')) . '" required></label></div><div><label>Porta<input name="source_port" type="number" min="1" max="65535" value="' . h((string) ($config['port'] ?? 3306)) . '" required></label></div></div><div class="grid"><div><label>Banco de dados<input name="source_database" maxlength="190" value="' . h((string) ($config['database'] ?? '')) . '" required></label></div><div><label>Usuário somente leitura<input name="source_user" maxlength="190" value="' . h((string) ($config['user'] ?? '')) . '" required></label></div></div><label>Senha do banco externo<input name="source_password" type="password" autocomplete="new-password"><span class="muted">' . h($passwordStatus) . '</span></label>' . ($sourceId > 0 && !empty($config['password_enc']) ? '<label><input type="checkbox" name="source_remove_password" value="1"> Remover a senha armazenada</label>' : '') . '<div class="grid"><div><label>Tabela<input name="source_table" maxlength="120" value="' . h((string) ($config['table'] ?? '')) . '" placeholder="ex.: wp_posts" required></label></div><div><label>Coluna-chave<input name="source_key_column" maxlength="120" value="' . h((string) ($config['key_column'] ?? 'id')) . '" required></label></div></div><label>Coluna do título<input name="source_title_column" maxlength="120" value="' . h((string) ($config['title_column'] ?? 'title')) . '" required></label><label>Colunas de conteúdo<input name="source_content_columns" maxlength="1000" value="' . h($contentColumns) . '" placeholder="titulo, resumo, conteudo" required></label><span class="muted">Separe várias colunas por vírgula. O conteúdo será combinado em um Markdown canônico.</span><div class="grid"><div><label>Coluna de filtro opcional<input name="source_filter_column" maxlength="120" value="' . h((string) ($config['filter_column'] ?? '')) . '" placeholder="ex.: post_type"></label></div><div><label>Valor do filtro<input name="source_filter_value" maxlength="255" value="' . h((string) ($config['filter_value'] ?? '')) . '" placeholder="ex.: produto"></label></div></div><div class="grid"><div><label>Coluna de status opcional<input name="source_status_column" maxlength="120" value="' . h((string) ($config['status_column'] ?? '')) . '" placeholder="ex.: status"></label></div><div><label>Status publicado/ativo<input name="source_status_value" maxlength="255" value="' . h((string) ($config['status_value'] ?? '')) . '" placeholder="ex.: ativo"></label></div></div><div class="grid"><div><label>Coluna de publicação/data<input name="source_published_column" maxlength="120" value="' . h((string) ($config['published_column'] ?? '')) . '" placeholder="ex.: published_at"></label></div><div><label>Coluna de alteração<input name="source_modified_column" maxlength="120" value="' . h((string) ($config['modified_column'] ?? '')) . '" placeholder="ex.: updated_at"></label></div></div><div class="grid"><div><label>Coluna de URL pública<input name="source_url_column" maxlength="120" value="' . h((string) ($config['url_column'] ?? '')) . '" placeholder="ex.: url"></label></div><div><label>Modelo de URL pública<input name="source_public_url_template" maxlength="2048" value="' . h((string) ($config['public_url_template'] ?? '')) . '" placeholder="https://site.exemplo/item/{id}"></label></div></div><span class="muted">A URL pode vir de uma coluna ou de um modelo com <code>{id}</code>. A senha nunca é exibida nem gravada em texto puro.</span><label><input type="checkbox" name="source_withdraw_missing" value="1"' . $checked . '> Retirar itens ausentes na próxima sincronização</label><br><button>Salvar fonte</button></form></div>';
}

function ai_guidance(): array
{
    static $guidance;
    if (!is_array($guidance)) {
        $guidance = AiGuidance::fromSettings(db());
    }
    return $guidance;
}

function ai_public_intro(): string
{
    return (string) ai_guidance()['public_intro'];
}

function brand_name(): string
{
    $value = trim(setting('brand_name', envv('APP_BRAND_NAME', 'RAGLocal')));
    return $value !== '' ? mb_substr($value, 0, 120, 'UTF-8') : 'RAGLocal';
}

function brand_subtitle(): string
{
    $value = trim(setting('brand_subtitle', envv('APP_BRAND_SUBTITLE', 'Atendimento inteligente baseado na sua base de conhecimento')));
    return mb_substr($value, 0, 240, 'UTF-8');
}

function default_scope_template(): string
{
    $default = 'As perguntas devem ser referentes a {empresa}. Sua pergunta não está no contexto deste agente.';
    $value = trim(setting('default_scope_response', $default));
    if ($value === '') {
        $value = $default;
    }
    return mb_substr($value, 0, 500, 'UTF-8');
}

function default_scope_response(): string
{
    return str_replace(['{empresa}', '<nome da empresa>'], brand_name(), default_scope_template());
}

function brand_logo_filename(): string
{
    $filename = basename(trim(setting('brand_logo_filename', '')));
    return preg_match('/^brand-logo\\.(png|jpe?g|webp|gif)$/i', $filename) ? $filename : '';
}

function brand_logo_path(): string
{
    $filename = brand_logo_filename();
    return $filename === '' ? '' : rag_storage_dir() . '/' . $filename;
}

function brand_logo_mime(): string
{
    return match (strtolower(pathinfo(brand_logo_filename(), PATHINFO_EXTENSION))) {
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        default => '',
    };
}

function ollama_models(): array
{
    return [
        'qwen3:4b' => 'Qwen3 4B — recomendado; já instalado, português e raciocínio',
        'linuxadmin_agent:latest' => 'LinuxAdmin Agent — instalado; evitar para respostas condominiais gerais',
        'gemma3:4b' => 'Gemma3 4B — instalado; suporta imagem, porém mais pesado',
        'gemma4:e4b' => 'Gemma4 E4B — instalado; mais pesado para o hardware atual',
        'qwen3:1.7b' => 'Qwen3 1.7B — sugestão leve; instalar no Ollama antes de selecionar',
        'gemma3:1b' => 'Gemma3 1B — sugestão leve; instalar no Ollama antes de selecionar',
    ];
}

function document_kind_label(string $kind): string
{
    return match ($kind) {
        'regimento' => 'Regimento interno',
        'ata' => 'Ata',
        'memoria' => 'Memória validada',
        'manutencao' => 'Manutenção',
        'noticia' => 'Notícias',
        'servico' => 'Carta de Serviços',
        'diretriz' => 'Diretrizes da IA',
        default => $kind,
    };
}

function rag_min_confidence(): float
{
    $value = (float) setting('rag_min_confidence', envv('RAG_MIN_CONFIDENCE', '0.75'));
    return max(0.50, min(0.99, $value));
}

function rag_min_sources(): int
{
    $value = (int) setting('rag_min_sources', envv('RAG_MIN_SOURCES', '1'));
    return max(1, min(3, $value));
}

function ollama_timeout(): int
{
    $value = (int) setting('ollama_timeout', envv('OLLAMA_TIMEOUT', '120'));
    return max(20, min(180, $value));
}

function ollama_generate(string $model, string $prompt, int $maxTokens): array
{
    $endpoint = ollama_endpoint();
    if ($endpoint === '') {
        return ['response' => [], 'error' => 'ollama_endpoint_not_allowed'];
    }

    $payload = [
        'model' => $model,
        'prompt' => $prompt,
        'stream' => false,
        'format' => OllamaResponse::schema(),
        'think' => false,
        'keep_alive' => '5m',
        'options' => [
            'temperature' => 0.0,
            'num_predict' => $maxTokens,
        ],
    ];
    $ch = curl_init($endpoint . '/api/generate');
    $curlOptions = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => ollama_timeout(),
    ];
    $sourceIp = ollama_source_ip();
    if ($sourceIp !== '') {
        $curlOptions[CURLOPT_INTERFACE] = $sourceIp;
    }
    curl_setopt_array($ch, $curlOptions);
    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $curlError !== '') {
        return ['response' => [], 'error' => 'ollama_unreachable'];
    }

    $response = json_decode($raw, true);
    if (!is_array($response)) {
        return ['response' => [], 'error' => 'invalid_ollama_response'];
    }
    if (trim((string) ($response['error'] ?? '')) !== '') {
        return ['response' => [], 'error' => 'ollama_model_error'];
    }
    return ['response' => $response, 'error' => ''];
}

function ollama_retry_prompt(string $question, array $sources): string
{
    $evidence = [];
    foreach (array_slice($sources, 0, 2) as $index => $source) {
        $content = trim((string) ($source['content'] ?? ''));
        if (mb_strlen($content, 'UTF-8') > 2400) {
            $content = rtrim(mb_substr($content, 0, 2400, 'UTF-8')) . '…';
        }
        $evidence[] = '[' . ($index + 1) . '] Documento: ' . (string) ($source['title'] ?? 'Fonte sem título') . "\n" . $content;
    }

    return "Responda à PERGUNTA usando exclusivamente as EVIDÊNCIAS numeradas abaixo. Não explique o processo e não use conhecimento externo. Se uma evidência responder diretamente, grounded deve ser true, confidence deve ficar entre 0.75 e 1.00, answer deve ter uma ou duas frases em português brasileiro e source_numbers deve listar apenas as evidências usadas. Se não houver resposta direta, grounded deve ser false, confidence deve ser 0, answer deve informar que não há base suficiente e source_numbers deve ser []. Retorne somente um objeto JSON com grounded, confidence, answer e source_numbers.\n\nEVIDÊNCIAS:\n" . implode("\n\n", $evidence) . "\n\nPERGUNTA:\n" . $question;
}

function ollama_call(string $question, array $sources): array
{
    if (!empty($sources[0]['memory_exact'])) {
        $memory = parse_validated_memory((string) $sources[0]['content']);
        $validatedAnswer = trim((string) ($memory['answer'] ?? ''));
        if ($validatedAnswer !== '') {
            return [
                'approved' => true,
                'answer' => $validatedAnswer,
                'confidence' => 1.0,
                'source_numbers' => [1],
                'model' => 'memoria-validada',
                'error' => '',
            ];
        }
    }

    if (!$sources) {
        return [
            'approved' => false,
            'answer' => '',
            'confidence' => 0.0,
            'source_numbers' => [],
            'model' => setting('ollama_chat_model', envv('OLLAMA_CHAT_MODEL', 'qwen3:4b')),
            'error' => 'no_context',
        ];
    }

    $contextParts = [];
    foreach ($sources as $index => $source) {
        $number = $index + 1;
        $publicUrl = trim((string) ($source['public_url'] ?? ''));
        $urlContext = $publicUrl !== '' ? ' | URL pública: ' . $publicUrl : '';
        $contextParts[] = '[' . $number . '] Documento: ' . $source['title'] . ' | Tipo: ' . document_kind_label((string) $source['kind']) . $urlContext . "\n" . $source['content'];
    }
    $context = implode("\n\n", $contextParts);
    $model = setting('ollama_chat_model', envv('OLLAMA_CHAT_MODEL', 'qwen3:4b'));
    $guidance = AiGuidance::promptBlock(ai_guidance(), brand_name());
    $prompt = "Você é o assistente oficial de " . brand_name() . ".\n\n" .
        $guidance . "\n\n" .
        "Use exclusivamente as fontes numeradas no CONTEXTO. Primeiro compare a pergunta com as fontes. Memórias marcadas como [RAG_MEMORIA_VALIDADA] são respostas humanas aprovadas e podem ser usadas como evidência quando a pergunta atual tiver a mesma intenção, mesmo que esteja formulada com outras palavras. Nesse caso, adapte a redação apenas o necessário e preserve o conteúdo da resposta validada; não acrescente conhecimento geral. " .
        "Se alguma fonte sustentar diretamente a resposta, responda em 1 a 3 frases completas, copiando ou resumindo somente os fatos dessa fonte; inclua o fato principal e a informação complementar mais relevante que esteja na mesma fonte, como serviço realizado, data ou validade. Se a pergunta pedir quando, informe a data e explique a que serviço ou evento ela se refere. Nesse caso, grounded deve ser true, confidence deve ser um número entre 0.75 e 1.00 e source_numbers deve conter todas as fontes usadas. " .
        "Se nenhuma fonte sustentar diretamente a resposta, grounded deve ser false, confidence deve ser 0, source_numbers deve ser [] e answer deve dizer que não encontrou base suficiente. " .
        "Nunca use conhecimento geral, não complete lacunas, não faça suposições e não invente horários, multas, artigos, datas, decisões ou interpretações. " .
        "Retorne SOMENTE um JSON válido, sem markdown, exatamente com estas chaves: grounded (boolean), confidence (number), answer (string em português brasileiro), source_numbers (array de números).\n\n" .
        "CONTEXTO:\n" . $context . "\n\nPERGUNTA:\n" . $question;

    $generated = ollama_generate($model, $prompt, 260);
    $initialError = (string) $generated['error'];
    $parsed = $initialError === '' ? OllamaResponse::parse($generated['response'], count($sources)) : null;
    $retryableErrors = ['ollama_model_error', 'invalid_ollama_response'];
    $hasDirectEvidenceOverlap = RagSearchTerms::hasDirectEvidenceOverlap($question, $sources);
    $shouldRetry = $initialError === ''
        ? (empty($parsed['valid']) || (!$parsed['grounded'] && $hasDirectEvidenceOverlap))
        : in_array($initialError, $retryableErrors, true);
    if ($shouldRetry) {
        $retry = ollama_generate($model, ollama_retry_prompt($question, $sources), 180);
        if ($retry['error'] === '') {
            $parsed = OllamaResponse::parse($retry['response'], count($sources));
        }
        if (empty($parsed['valid'])) {
            $error = $retry['error'] !== '' ? (string) $retry['error'] : 'retry_' . (string) ($parsed['error'] ?? $initialError);
            return ['approved' => false, 'answer' => '', 'confidence' => 0.0, 'source_numbers' => [], 'model' => $model, 'error' => $error];
        }
    } elseif ($initialError !== '') {
        return ['approved' => false, 'answer' => '', 'confidence' => 0.0, 'source_numbers' => [], 'model' => $model, 'error' => $initialError];
    }

    $confidence = (float) $parsed['confidence'];
    $sourceNumbers = $parsed['source_numbers'];
    $answer = (string) $parsed['answer'];
    $grounded = (bool) $parsed['grounded'];
    $approved = $grounded && $answer !== '' && $confidence >= rag_min_confidence() && count($sourceNumbers) >= rag_min_sources();

    return [
        'approved' => $approved,
        'answer' => $answer,
        'confidence' => $confidence,
        'source_numbers' => $sourceNumbers,
        'model' => $model,
        'error' => '',
    ];
}

function normalize_memory_question(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = preg_replace('/[^\\p{L}\\p{N}]+/u', ' ', $value) ?? '';
    return trim(preg_replace('/\\s+/u', ' ', $value) ?? '');
}

function parse_validated_memory(string $content): ?array
{
    if (!preg_match('/Pergunta:\\s*(.*?)\\s+Resposta validada:\\s*(.*)$/us', $content, $match)) {
        return null;
    }
    $question = trim((string) $match[1]);
    $answer = trim((string) $match[2]);
    return $question !== '' && $answer !== '' ? ['question' => $question, 'answer' => $answer] : null;
}

function memory_question_terms(string $value): array
{
    $normalized = normalize_memory_question($value);
    if ($normalized === '') {
        return [];
    }

    $ignored = [
        'a', 'ao', 'aos', 'as', 'com', 'da', 'das', 'de', 'do', 'dos', 'e', 'em', 'entre',
        'na', 'nas', 'no', 'nos', 'o', 'os', 'para', 'por', 'que', 'se', 'sem', 'sobre',
        'um', 'uma', 'uns', 'umas', 'como', 'qual', 'quais', 'quando', 'onde', 'quem',
        'porque', 'porquê', 'eu', 'me', 'meu', 'minha', 'meus', 'minhas', 'você', 'voce',
        'te', 'seu', 'sua', 'pode', 'poderia', 'gostaria', 'quero', 'queria', 'saber',
        'dizer', 'ensina', 'ensine', 'ensinar', 'fazer', 'faço', 'faca', 'faz', 'preparar',
        'preparo', 'prepara', 'receita', 'modo', 'maneira', 'forma', 'elaborar', 'elabore',
        'produzir', 'produza', 'obter', 'obtenho', 'conseguir', 'devo', 'deve', 'deveria',
    ];

    $terms = [];
    foreach (explode(' ', $normalized) as $term) {
        if (mb_strlen($term, 'UTF-8') < 2 || in_array($term, $ignored, true)) {
            continue;
        }
        $terms[$term] = true;
    }
    return array_keys($terms);
}

function memory_question_similarity(string $left, string $right): float
{
    $leftTerms = memory_question_terms($left);
    $rightTerms = memory_question_terms($right);
    if (count($leftTerms) < 2 || count($rightTerms) < 2) {
        return 0.0;
    }

    $intersection = count(array_intersect($leftTerms, $rightTerms));
    if ($intersection < 2) {
        return 0.0;
    }
    $union = count(array_unique(array_merge($leftTerms, $rightTerms)));
    $jaccard = $intersection / max(1, $union);
    $leftCoverage = $intersection / count($leftTerms);
    $rightCoverage = $intersection / count($rightTerms);

    if ($jaccard < 0.65 || $leftCoverage < 0.80 || $rightCoverage < 0.80) {
        return 0.0;
    }
    return (0.50 * $jaccard) + (0.25 * $leftCoverage) + (0.25 * $rightCoverage);
}

function validated_memory_context(string $question): array
{
    $normalizedQuestion = normalize_memory_question($question);
    if ($normalizedQuestion === '') {
        return [];
    }

    $stmt = db()->query("SELECT c.id, c.content, d.title, d.kind
        FROM chunks c JOIN documents d ON d.id = c.document_id
        WHERE d.status = 'ready' AND d.kind = 'memoria'
        ORDER BY c.id DESC LIMIT 500");
    $similar = [];

    foreach ($stmt->fetchAll() as $row) {
        $memory = parse_validated_memory((string) $row['content']);
        if (!$memory) {
            continue;
        }
        $storedQuestion = (string) $memory['question'];
        $row['content'] = '[RAG_MEMORIA_VALIDADA] [FONTE] Respostas validadas por atendentes [TAGS] memoria validada atendente\n' . trim((string) $row['content']);
        if (normalize_memory_question($storedQuestion) === $normalizedQuestion) {
            $row['score'] = 100000.0;
            $row['memory_exact'] = true;
            return [$row];
        }

        $score = memory_question_similarity($question, $storedQuestion);
        if ($score >= 0.90) {
            $row['score'] = $score;
            $row['memory_similarity'] = $score;
            $row['memory_similar'] = true;
            $similar[] = $row;
        }
    }

    if (!$similar) {
        return [];
    }
    usort($similar, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
    $best = $similar[0];
    $second = $similar[1] ?? null;
    if ($second && ((float) $best['score'] - (float) $second['score']) < 0.05) {
        $bestAnswer = parse_validated_memory((string) $best['content']);
        $secondAnswer = parse_validated_memory((string) $second['content']);
        if (normalize_memory_question((string) ($bestAnswer['answer'] ?? '')) !== normalize_memory_question((string) ($secondAnswer['answer'] ?? ''))) {
            return [];
        }
    }
    return [$best];
}

function context(string $question): array
{
    $validatedMemory = validated_memory_context($question);
    if ($validatedMemory) {
        return $validatedMemory;
    }

    $prefixQuery = RagSearchTerms::booleanPrefixQuery($question);
    $stmt = db()->prepare("SELECT c.id, c.content, d.title, d.kind,
            dsl.public_url,
            NULL AS published_at,
            NULL AS service_department,
            MATCH(c.content) AGAINST(:q IN NATURAL LANGUAGE MODE) AS score,
            MATCH(c.content) AGAINST(:prefix_score IN BOOLEAN MODE) AS prefix_score
        FROM chunks c
        JOIN documents d ON d.id = c.document_id
        LEFT JOIN document_source_links dsl ON dsl.document_id = d.id AND dsl.is_active = 1
        LEFT JOIN knowledge_sources ks ON ks.id = dsl.source_id AND ks.enabled = 1
        WHERE d.status = 'ready' AND d.kind <> 'diretriz'
          AND (dsl.id IS NULL OR ks.id IS NOT NULL)
          AND (
              MATCH(c.content) AGAINST(:q2 IN NATURAL LANGUAGE MODE) > 0
              OR MATCH(c.content) AGAINST(:prefix_filter IN BOOLEAN MODE) > 0
              OR c.content LIKE :like
          )
        ORDER BY GREATEST(score, prefix_score) DESC, c.id DESC LIMIT 6");
    $stmt->execute([
        'q' => $question,
        'prefix_score' => $prefixQuery,
        'q2' => $question,
        'prefix_filter' => $prefixQuery,
        'like' => '%' . mb_substr($question, 0, 100) . '%',
    ]);
    return $stmt->fetchAll();
}

function citations_from_result(array $result, array $sources): array
{
    $citations = [];
    foreach ((array) ($result['source_numbers'] ?? []) as $number) {
        $source = $sources[(int) $number - 1] ?? null;
        if ($source) {
            $citations[] = [
                'title' => (string) ($source['title'] ?? ''),
                'kind' => (string) ($source['kind'] ?? ''),
                'public_url' => (string) ($source['public_url'] ?? ''),
                'published_at' => (string) ($source['published_at'] ?? ''),
            ];
        }
    }
    return $citations;
}

function citation_link_label(string $kind): string
{
    return match ($kind) {
        'noticia' => 'Abrir notícia',
        'servico' => 'Abrir serviço',
        default => 'Abrir fonte',
    };
}

function reassess_conversation(PDO $pdo, int $conversationId): array
{
    $stmt = $pdo->prepare("SELECT c.id, c.status,
            q.id AS question_message_id, q.body AS question,
            a.id AS previous_ai_message_id
        FROM conversations c
        JOIN messages q ON q.conversation_id = c.id AND q.sender = 'resident'
        LEFT JOIN messages a ON a.id = (
            SELECT MAX(a2.id) FROM messages a2 WHERE a2.conversation_id = c.id AND a2.sender = 'ai'
        )
        WHERE c.id = ? AND q.id = (
            SELECT MAX(q2.id) FROM messages q2 WHERE q2.conversation_id = c.id AND q2.sender = 'resident'
        )
        LIMIT 1");
    $stmt->execute([$conversationId]);
    $conversation = $stmt->fetch();
    if (!$conversation || (string) $conversation['status'] !== 'human_pending') {
        throw new RuntimeException('A pergunta não está mais aguardando intervenção humana.');
    }

    $startedAt = microtime(true);
    $sources = context((string) $conversation['question']);
    $result = ollama_call((string) $conversation['question'], $sources);
    $responseTimeMs = (int) max(0, round((microtime(true) - $startedAt) * 1000));
    $draft = trim((string) ($result['answer'] ?? ''));
    $approved = !empty($result['approved']);
    $publicAnswer = $approved ? $draft : default_scope_response();
    $status = $approved ? 'answered' : 'human_pending';
    $citations = citations_from_result($result, $sources);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO messages(conversation_id, sender, body, citations) VALUES(?, \'ai\', ?, ?)')
            ->execute([$conversationId, $publicAnswer, json_encode($citations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        $newAiMessageId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE conversations SET status = ?, ai_draft = ?, ai_confidence = ?, ai_model = ? WHERE id = ?')
            ->execute([$status, $draft !== '' ? $draft : null, (float) ($result['confidence'] ?? 0), (string) ($result['model'] ?? ''), $conversationId]);
        $pdo->prepare('INSERT INTO ai_reassessments(conversation_id, question_message_id, previous_ai_message_id, new_ai_message_id, status, ai_draft, public_answer, ai_confidence, ai_model, citations, source_count, response_time_ms, triggered_by) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$conversationId, (int) $conversation['question_message_id'], $conversation['previous_ai_message_id'] !== null ? (int) $conversation['previous_ai_message_id'] : null, $newAiMessageId, $status, $draft !== '' ? $draft : null, $publicAnswer, (float) ($result['confidence'] ?? 0), (string) ($result['model'] ?? ''), json_encode($citations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), count($sources), $responseTimeMs, (int) ($_SESSION['user']['id'] ?? 0)]);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }

    return [
        'conversation_id' => $conversationId,
        'question' => (string) $conversation['question'],
        'question_message_id' => (int) $conversation['question_message_id'],
        'new_ai_message_id' => $newAiMessageId,
        'status' => $status,
        'public_answer' => $publicAnswer,
        'draft' => $draft,
        'confidence' => (float) ($result['confidence'] ?? 0),
        'model' => (string) ($result['model'] ?? ''),
        'citations' => $citations,
        'source_count' => count($sources),
        'response_time_ms' => $responseTimeMs,
        'ollama_error' => (string) ($result['error'] ?? ''),
    ];
}

function run_process(string $command): string
{
    $output = shell_exec($command . ' 2>/dev/null');
    return is_string($output) ? $output : '';
}

function extract_pdf_text(string $file): string
{
    $temporary = tempnam(sys_get_temp_dir(), 'jt_pdf_');
    if ($temporary === false || !copy($file, $temporary)) {
        if ($temporary !== false) {
            @unlink($temporary);
        }
        return '';
    }

    $pdfToText = envv('PDFTOTEXT_BIN', '/usr/bin/pdftotext');
    $text = run_process(escapeshellarg($pdfToText) . ' -enc UTF-8 -layout ' . escapeshellarg($temporary) . ' -');
    if (trim($text) !== '') {
        @unlink($temporary);
        return trim($text);
    }

    if (envv('OCR_ENABLED', '1') !== '1') {
        @unlink($temporary);
        return '';
    }

    $pdfToPpm = envv('PDFTOPPM_BIN', '/usr/bin/pdftoppm');
    $tesseract = envv('TESSERACT_BIN', '/usr/bin/tesseract');
    $ocrLanguage = preg_replace('/[^a-zA-Z0-9+_-]/', '', envv('OCR_LANG', 'por+eng')) ?: 'por+eng';
    $dpi = max(120, min(300, (int) envv('OCR_DPI', '200')));
    $prefix = tempnam(sys_get_temp_dir(), 'jt_ocr_');
    if ($prefix === false) {
        @unlink($temporary);
        return '';
    }
    @unlink($prefix);
    run_process(escapeshellarg($pdfToPpm) . ' -r ' . $dpi . ' -png -f 1 -l 60 ' . escapeshellarg($temporary) . ' ' . escapeshellarg($prefix));
    $pages = glob($prefix . '-*.png') ?: [];
    natsort($pages);
    $ocrText = '';
    foreach ($pages as $page) {
        $ocrText .= "\n" . run_process(escapeshellarg($tesseract) . ' ' . escapeshellarg($page) . ' stdout -l ' . escapeshellarg($ocrLanguage) . ' --psm 6');
        @unlink($page);
    }
    @unlink($temporary);
    return trim($ocrText);
}

function extract_text(string $file, string $name): string
{
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($extension === 'pdf') {
        return extract_pdf_text($file);
    }
    $text = file_get_contents($file);
    return is_string($text) ? $text : '';
}

function normalize_document_text(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = str_replace("\f", "\n\n[[PAGE_BREAK]]\n\n", $text);
    $text = preg_replace('/[\\x00-\\x08\\x0B\\x0E-\\x1F\\x7F]/u', ' ', $text) ?? $text;

    // Listas de serviços exportadas em Markdown usam --- imediatamente antes do
    // próximo título. Sem uma quebra de parágrafo, o processador anterior mantinha
    // centenas de serviços na mesma seção e prejudicava a recuperação semântica.
    $text = preg_replace('/\\n[ \\t]*---[ \\t]*\\n(?=[ \\t]*#{1,6}\\s+)/u', "\n\n", $text) ?? $text;

    $text = preg_replace('/[ \\t]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\\n{3,}/u', "\n\n", $text) ?? $text;
    return trim($text);
}

function rag_heading(string $line): ?string
{
    $line = trim($line);
    if ($line === '' || mb_strlen($line, 'UTF-8') > 180) {
        return null;
    }
    if (preg_match('/^#{1,6}\\s+(.+)$/u', $line, $match)) {
        return trim((string) $match[1]);
    }
    if (preg_match('/^(?:CAP[IÍ]TULO|SE[CÇÃ]O|ART(?:IGO)?\\.?|ANEXO)\\b.*$/iu', $line)) {
        return $line;
    }
    $letters = preg_replace('/[^\\p{L}]+/u', '', $line) ?? '';
    if ($letters !== '' && mb_strtoupper($letters, 'UTF-8') === $letters && mb_strlen($letters, 'UTF-8') >= 5) {
        return $line;
    }
    return null;
}

function rag_tags(string $title, string $kind, string $heading): string
{
    $parts = [$title, document_kind_label($kind), 'base de conhecimento', brand_name(), $heading];
    $tags = [];
    foreach ($parts as $part) {
        $words = preg_split('/[^\\p{L}\\p{N}]+/u', mb_strtolower($part, 'UTF-8')) ?: [];
        foreach ($words as $word) {
            if (mb_strlen($word, 'UTF-8') >= 3) {
                $tags[$word] = true;
            }
        }
    }
    return implode(' ', array_keys($tags));
}

function rag_sections(string $text): array
{
    $sections = [];
    $heading = 'Conteúdo principal';
    $page = 1;
    $body = [];
    $flush = static function () use (&$sections, &$body, &$heading, &$page): void {
        $content = trim(implode("\n", $body));
        if ($content !== '') {
            $sections[] = ['heading' => $heading, 'body' => $content, 'page_start' => $page, 'page_end' => $page];
        }
        $body = [];
    };

    foreach (preg_split('/\\n{2,}/u', $text) ?: [] as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') {
            continue;
        }
        if ($paragraph === '[[PAGE_BREAK]]') {
            $flush();
            $page++;
            continue;
        }
        $lines = preg_split('/\\n/u', $paragraph) ?: [];
        $first = trim((string) ($lines[0] ?? ''));
        $detectedHeading = rag_heading($first);
        if ($detectedHeading !== null) {
            $flush();
            $heading = $detectedHeading;
            $remaining = trim(implode("\n", array_slice($lines, 1)));
            if ($remaining !== '') {
                $body[] = $remaining;
            }
        } else {
            $body[] = $paragraph;
        }
    }
    $flush();
    return $sections ?: [['heading' => 'Conteúdo principal', 'body' => $text, 'page_start' => 1, 'page_end' => $page]];
}

function split_rag_body(string $body, int $size = 1800): array
{
    $paragraphs = preg_split('/\\n{2,}/u', trim($body)) ?: [];
    $chunks = [];
    $current = '';
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim(preg_replace('/\\s+/u', ' ', $paragraph) ?? '');
        if ($paragraph === '') {
            continue;
        }
        while (mb_strlen($paragraph, 'UTF-8') > $size) {
            if ($current !== '') {
                $chunks[] = $current;
                $current = '';
            }
            $piece = mb_substr($paragraph, 0, $size, 'UTF-8');
            $position = mb_strrpos($piece, ' ', 0, 'UTF-8');
            if ($position !== false && $position > 600) {
                $piece = mb_substr($piece, 0, $position, 'UTF-8');
            }
            $chunks[] = trim($piece);
            $paragraph = trim(mb_substr($paragraph, mb_strlen($piece, 'UTF-8'), null, 'UTF-8'));
        }
        if ($current !== '' && mb_strlen($current . ' ' . $paragraph, 'UTF-8') > $size) {
            $chunks[] = $current;
            $current = '';
        }
        $current = $current === '' ? $paragraph : $current . "\n\n" . $paragraph;
    }
    if ($current !== '') {
        $chunks[] = $current;
    }
    return array_values(array_filter($chunks, static fn (string $chunk): bool => trim($chunk) !== ''));
}

function build_rag_document(string $title, string $kind, string $sourceFilename, string $text): array
{
    $normalized = normalize_document_text($text);
    $sourceSha256 = hash('sha256', $normalized);
    $sections = rag_sections($normalized);
    $parserVersion = 'rag-v2';
    $markdown = [
        '---',
        'rag_format: ' . $parserVersion,
        'language: pt-BR',
        'title: ' . json_encode($title, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'kind: ' . $kind,
        'source_filename: ' . json_encode($sourceFilename, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'source_sha256: ' . $sourceSha256,
        'canonical_sha256: PENDING',
        '---',
        '',
        '# [DOCUMENTO] ' . $title,
        '',
        '[TAGS] ' . rag_tags($title, $kind, 'documento'),
        '',
    ];
    $chunks = [];
    foreach ($sections as $section) {
        $heading = trim((string) $section['heading']);
        $tags = rag_tags($title, $kind, $heading);
        $markdown[] = '## [SEÇÃO] ' . $heading;
        $markdown[] = '[TAGS] ' . $tags;
        $markdown[] = '';
        $markdown[] = trim((string) $section['body']);
        $markdown[] = '';
        foreach (split_rag_body((string) $section['body']) as $chunkBody) {
            $chunks[] = [
                'content' => '[RAG_DOCUMENTO] [FONTE] ' . $title . ' [TIPO] ' . document_kind_label($kind) . ' [SEÇÃO] ' . $heading . ' [TAGS] ' . $tags . "\n" . $chunkBody,
                'section_heading' => mb_substr($heading, 0, 255, 'UTF-8'),
                'tags' => mb_substr($tags, 0, 1000, 'UTF-8'),
                'page_start' => (int) $section['page_start'],
                'page_end' => (int) $section['page_end'],
                'token_count' => count(preg_split('/\\s+/u', $chunkBody, -1, PREG_SPLIT_NO_EMPTY) ?: []),
            ];
        }
    }
    $canonicalWithoutHash = implode("\n", $markdown) . "\n";
    $canonicalSha256 = hash('sha256', $canonicalWithoutHash);
    $markdown[7] = 'canonical_sha256: ' . $canonicalSha256;
    return [
        'markdown' => implode("\n", $markdown) . "\n",
        'chunks' => $chunks,
        'source_sha256' => $sourceSha256,
        'canonical_sha256' => $canonicalSha256,
        'parser_version' => $parserVersion,
    ];
}

function rag_storage_dir(): string
{
    $default = dirname(__DIR__) . '/storage/uploads';
    $configured = trim(envv('RAG_UPLOAD_DIR'));
    return rtrim($configured !== '' ? $configured : $default, '/');
}

function ensure_rag_storage(): bool
{
    $directory = rag_storage_dir();
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        return false;
    }
    return is_writable($directory);
}

function artifact_mime(string $path, string $fallback): string
{
    $mime = function_exists('mime_content_type') ? mime_content_type($path) : false;
    return is_string($mime) && $mime !== '' ? $mime : $fallback;
}

function layout(string $title, string $body): never
{
    $logged = admin();
    $brandName = brand_name();
    $subtitle = brand_subtitle();
    $logoPath = brand_logo_path();
    $logoHtml = ($logoPath !== '' && is_file($logoPath)) ? '<img class="brand-logo" src="?route=brand-logo" alt="Logotipo de ' . h($brandName) . '">' : '';
    $subtitleHtml = $subtitle !== '' ? '<div class="brand-subtitle">' . h($subtitle) . '</div>' : '';
    $adminLink = $logged ? '<a href="?route=admin">Administração</a>' : '';
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . ' — ' . h($brandName) . '</title><style>body{font-family:system-ui,sans-serif;background:#f4f6f8;color:#17202a;margin:0}main{max-width:960px;margin:32px auto;padding:0 16px}.card{background:white;border-radius:12px;padding:22px;margin:16px 0;box-shadow:0 2px 12px #0001}textarea,input,select{width:100%;box-sizing:border-box;padding:11px;border:1px solid #ccd3da;border-radius:7px;margin:6px 0 12px}button{background:#155eef;color:#fff;border:0;padding:11px 16px;border-radius:7px;cursor:pointer}.button-danger{background:#b42318}.button-danger:hover{background:#8f1d14}.button-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.button-secondary{background:#667085}.button-secondary:hover{background:#475467}.muted{color:#667085;font-size:.92em}.answer{white-space:pre-wrap;line-height:1.55}.cite{border-left:3px solid #b7c8ff;padding:8px 12px;margin:8px 0;background:#f7f9ff}.reference{border-left:3px solid #f59e0b;padding:10px 12px;margin:10px 0;background:#fffbeb;white-space:pre-wrap}.response-source,.response-meta{color:#667085;font-size:.78em}.response-source{border-left:2px solid #b7c8ff;padding:5px 9px;margin:6px 0;background:#f7f9ff}.response-meta{margin:10px 0 0}.top{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}.brand{display:flex;gap:12px;align-items:center}.brand-logo{width:52px;height:52px;object-fit:contain;border-radius:8px;background:#fff}.brand-subtitle{color:#667085;font-size:.85em;margin-top:2px}a{color:#155eef}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}.badge{display:inline-block;padding:3px 8px;border-radius:999px;background:#eef2ff;color:#3446a8;font-size:.85em}.admin-shell{margin-top:16px}.admin-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}.admin-head h2{margin:4px 0}.admin-head p{margin:0}.admin-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.eyebrow{color:#667085;font-size:.75em;font-weight:700;letter-spacing:.08em}.admin-menu{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0 14px;padding:8px;background:#eef2f6;border-radius:10px}.admin-menu a{display:inline-flex;align-items:center;gap:7px;padding:10px 13px;border-radius:8px;text-decoration:none;color:#344054;font-weight:600}.admin-menu a:hover{background:#fff}.admin-menu a.active{background:#155eef;color:#fff}.nav-count{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;padding:0 6px;border-radius:999px;background:#d92d20;color:#fff;font-size:.78em}.admin-menu a.active .nav-count{background:#fff;color:#d92d20}.admin-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:14px 0}.admin-stat{background:#fff;border:1px solid #e4e7ec;border-radius:10px;padding:13px 15px}.admin-stat strong{display:block;font-size:1.45em;color:#101828}.urgent-alert{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;border:2px solid #d92d20;background:#fff1f0;color:#7a271a;border-radius:10px;padding:14px 16px;margin:14px 0}.urgent-alert strong{font-size:1.05em}.success-alert{border:1px solid #abefc6;background:#ecfdf3;color:#05603a;border-radius:10px;padding:12px 14px;margin:14px 0}.pending-card{border:2px solid #f04438;background:#fffafa;border-radius:10px;padding:15px;margin:14px 0}.pending-card .pending-meta{color:#7a271a;font-size:.9em;margin-bottom:8px}.pending-card textarea{min-height:100px}.section-intro{margin-top:-4px}.empty-state{padding:22px;text-align:center;border:1px dashed #cfd4dc;border-radius:10px;color:#667085}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:11px 9px;border-bottom:1px solid #eaecf0;vertical-align:top}th{font-size:.8em;color:#667085;text-transform:uppercase;letter-spacing:.04em}@media(max-width:640px){th:nth-child(3),td:nth-child(3){display:none}.admin-menu a{flex:1 1 45%}}</style></head><body><main><div class="top"><div class="brand">' . $logoHtml . '<div><h1>' . h($brandName) . '</h1>' . $subtitleHtml . '</div></div>' . $adminLink . '</div>' . $body . '</main></body></html>';
    exit;
}

$route = $_GET['route'] ?? 'chat';

if ($route === 'brand-logo') {
    $logoPath = brand_logo_path();
    $mime = brand_logo_mime();
    if ($logoPath === '' || $mime === '' || !is_file($logoPath)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($logoPath));
    header('Cache-Control: public, max-age=300');
    readfile($logoPath);
    exit;
}

if ($route === 'logout') {
    session_destroy();
    header('Location: ?');
    exit;
}

if ($route === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([trim((string) ($_POST['email'] ?? ''))]);
    $user = $stmt->fetch();
    if ($user && password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        audit_event('login_success', 'admin', ['metadata' => ['user_id' => (int) $user['id'], 'role' => (string) $user['role']]]);
        header('Location: ?route=' . (!empty($user['must_change_password']) ? 'password' : 'admin'));
        exit;
    }
    audit_event('login_failure', 'admin', ['metadata' => ['email_hash' => hash('sha256', strtolower(trim((string) ($_POST['email'] ?? ''))))]]);
    $error = 'Credenciais inválidas.';
}

if ($route === 'login') {
    layout('Login', '<div class="card"><h2>Acesso administrativo</h2>' . (!empty($error) ? '<p>' . h($error) . '</p>' : '') . '<form method="post"><input type="hidden" name="csrf" value="' . csrf() . '">E-mail<input name="email" type="email" required>Senha<input name="password" type="password" required><button>Entrar</button></form></div>');
}

if ($route === 'password' && !admin()) {
    header('Location: ?route=login');
    exit;
}

$passwordError = '';
if ($route === 'password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    if (strlen($newPassword) < 12 || strlen($newPassword) > 255) {
        $passwordError = 'A nova senha deve ter entre 12 e 255 caracteres.';
    } elseif ($newPassword !== $confirmPassword) {
        $passwordError = 'A confirmação da nova senha não coincide.';
    } else {
        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $_SESSION['user']['id']]);
        $account = $stmt->fetch();
        if (!$account || !password_verify($currentPassword, (string) $account['password_hash'])) {
            $passwordError = 'A senha atual está incorreta.';
        } else {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')->execute([$newHash, (int) $_SESSION['user']['id']]);
            $_SESSION['user']['password_hash'] = $newHash;
            $_SESSION['user']['must_change_password'] = 0;
            audit_event('password_changed', 'admin', ['metadata' => ['user_id' => (int) $_SESSION['user']['id']]]);
            flash('Senha alterada com sucesso.');
            header('Location: ?route=admin&section=security');
            exit;
        }
    }
}

if (admin() && !empty($_SESSION['user']['must_change_password']) && $route !== 'password' && $route !== 'logout') {
    header('Location: ?route=password');
    exit;
}

if ($route === 'password') {
    layout('Alterar senha', '<div class="card"><div class="eyebrow">SEGURANÇA</div><h2>Alterar senha administrativa</h2><p class="muted">Por segurança, a senha temporária deve ser substituída antes de acessar o painel.</p>' . ($passwordError !== '' ? '<div class="urgent-alert">' . h($passwordError) . '</div>' : '') . '<form method="post"><input type="hidden" name="csrf" value="' . csrf() . '"><label>Senha atual<input name="current_password" type="password" autocomplete="current-password" required></label><label>Nova senha<input name="new_password" type="password" minlength="12" maxlength="255" autocomplete="new-password" required></label><label>Confirme a nova senha<input name="confirm_password" type="password" minlength="12" maxlength="255" autocomplete="new-password" required></label><button>Alterar senha</button></form><p class="muted">Use pelo menos 12 caracteres e não reutilize a senha temporária.</p></div>');
}

if ($route === 'admin' && !admin()) {
    header('Location: ?route=login');
    exit;
}

if ($route === 'source-save' && admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        $pdo = db();
        $sourceId = (int) ($_POST['source_id'] ?? 0);
        $existingConfig = [];
        if ($sourceId > 0) {
            $existing = SourceRegistry::find($pdo, $sourceId);
            if (!$existing) {
                throw new InvalidArgumentException('Fonte não encontrada.');
            }
            $existingConfig = SourceRegistry::publicConfig($existing);
        }
        $config = [
            'host' => trim((string) ($_POST['source_host'] ?? ($existingConfig['host'] ?? ''))),
            'port' => (int) ($_POST['source_port'] ?? ($existingConfig['port'] ?? 3306)),
            'database' => trim((string) ($_POST['source_database'] ?? ($existingConfig['database'] ?? ''))),
            'user' => trim((string) ($_POST['source_user'] ?? ($existingConfig['user'] ?? ''))),
            'password_enc' => (string) ($existingConfig['password_enc'] ?? ''),
            'password_plain' => (string) ($_POST['source_password'] ?? ''),
            'table' => trim((string) ($_POST['source_table'] ?? ($existingConfig['table'] ?? ''))),
            'key_column' => trim((string) ($_POST['source_key_column'] ?? ($existingConfig['key_column'] ?? 'id'))),
            'title_column' => trim((string) ($_POST['source_title_column'] ?? ($existingConfig['title_column'] ?? 'title'))),
            'content_columns' => trim((string) ($_POST['source_content_columns'] ?? implode(', ', (array) ($existingConfig['content_columns'] ?? [])))),
            'filter_column' => trim((string) ($_POST['source_filter_column'] ?? ($existingConfig['filter_column'] ?? ''))),
            'filter_value' => (string) ($_POST['source_filter_value'] ?? ($existingConfig['filter_value'] ?? '')),
            'status_column' => trim((string) ($_POST['source_status_column'] ?? ($existingConfig['status_column'] ?? ''))),
            'status_value' => (string) ($_POST['source_status_value'] ?? ($existingConfig['status_value'] ?? '')),
            'published_column' => trim((string) ($_POST['source_published_column'] ?? ($existingConfig['published_column'] ?? ''))),
            'modified_column' => trim((string) ($_POST['source_modified_column'] ?? ($existingConfig['modified_column'] ?? ''))),
            'url_column' => trim((string) ($_POST['source_url_column'] ?? ($existingConfig['url_column'] ?? ''))),
            'public_url_template' => trim((string) ($_POST['source_public_url_template'] ?? ($existingConfig['public_url_template'] ?? ''))),
            'withdraw_missing' => !empty($_POST['source_withdraw_missing']),
        ];
        if (!empty($_POST['source_remove_password'])) {
            $config['password_enc'] = '';
        }
        $savedId = SourceRegistry::save($pdo, [
            'source_key' => (string) ($_POST['source_key'] ?? ''),
            'plugin_key' => (string) ($_POST['plugin_key'] ?? 'database_table'),
            'name' => (string) ($_POST['source_name'] ?? ''),
            'description' => (string) ($_POST['source_description'] ?? ''),
            'config' => $config,
        ], $sourceId > 0 ? $sourceId : null, (int) ($_SESSION['user']['id'] ?? 0));
        audit_event($sourceId > 0 ? 'source_updated' : 'source_created', 'admin', ['metadata' => ['source_id' => $savedId, 'plugin_key' => (string) ($_POST['plugin_key'] ?? 'database_table'), 'updated_by' => (int) ($_SESSION['user']['id'] ?? 0)]]);
        flash($sourceId > 0 ? 'Fonte atualizada.' : 'Fonte criada e ativada.');
    } catch (Throwable $error) {
        flash('Não foi possível salvar a fonte: ' . mb_substr($error->getMessage(), 0, 300, 'UTF-8'));
    }
    header('Location: ?route=admin&section=sources');
    exit;
}

if ($route === 'source-toggle' && admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $sourceId = (int) ($_POST['source_id'] ?? 0);
    try {
        $enabled = SourceRegistry::toggle(db(), $sourceId);
        audit_event($enabled ? 'source_enabled' : 'source_disabled', 'admin', ['metadata' => ['source_id' => $sourceId, 'updated_by' => (int) ($_SESSION['user']['id'] ?? 0)]]);
        flash($enabled ? 'Fonte ativada.' : 'Fonte desativada. Os documentos derivados não serão usados na recuperação.');
    } catch (Throwable $error) {
        flash('Não foi possível alterar o estado da fonte: ' . mb_substr($error->getMessage(), 0, 300, 'UTF-8'));
    }
    header('Location: ?route=admin&section=sources');
    exit;
}

if ($route === 'source-remove' && admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $sourceId = (int) ($_POST['source_id'] ?? 0);
    try {
        if (($_POST['confirm_remove'] ?? '') !== 'REMOVE') {
            throw new InvalidArgumentException('Digite REMOVE para confirmar a exclusão da fonte e dos documentos derivados.');
        }
        $removed = SourceRegistry::remove(db(), $sourceId);
        audit_event('source_removed', 'admin', ['metadata' => ['source_id' => $sourceId, 'name' => $removed['name'], 'document_count' => $removed['document_count'], 'removed_by' => (int) ($_SESSION['user']['id'] ?? 0)]]);
        flash('Fonte removida com ' . $removed['document_count'] . ' documento(s) derivado(s).');
    } catch (Throwable $error) {
        flash('Não foi possível remover a fonte: ' . mb_substr($error->getMessage(), 0, 300, 'UTF-8'));
    }
    header('Location: ?route=admin&section=sources');
    exit;
}

if ($route === 'source-sync' && admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $sourceId = (int) ($_POST['source_id'] ?? 0);
    try {
        $summary = source_sync(db(), $sourceId, 'manual');
        audit_event('source_sync', 'admin', ['metadata' => ['source_id' => $sourceId, 'trigger' => 'manual', 'status' => $summary['status'], 'read_count' => $summary['read_count'], 'imported_count' => $summary['imported_count'], 'updated_count' => $summary['updated_count'], 'unchanged_count' => $summary['unchanged_count'], 'withdrawn_count' => $summary['withdrawn_count'], 'error_count' => $summary['error_count']]]);
        flash('Sincronização concluída: ' . $summary['imported_count'] . ' novas, ' . $summary['updated_count'] . ' atualizadas, ' . $summary['unchanged_count'] . ' sem alteração e ' . $summary['withdrawn_count'] . ' retiradas.');
    } catch (Throwable $error) {
        audit_event('source_sync', 'admin', ['metadata' => ['source_id' => $sourceId, 'trigger' => 'manual', 'status' => 'error', 'error' => mb_substr($error->getMessage(), 0, 500, 'UTF-8')]]);
        flash('Não foi possível sincronizar a fonte: ' . mb_substr($error->getMessage(), 0, 300, 'UTF-8'));
    }
    header('Location: ?route=admin&section=sources');
    exit;
}

if ($route === 'reassess' && admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $conversationId = (int) ($_POST['conversation_id'] ?? 0);
    try {
        if ($conversationId < 1) {
            throw new InvalidArgumentException('Atendimento inválido.');
        }
        $result = reassess_conversation(db(), $conversationId);
        audit_event('question_reassessed', 'admin', ['conversation_id' => $conversationId, 'message_id' => $result['new_ai_message_id'], 'question' => $result['question'], 'answer' => $result['public_answer'], 'ai_draft' => $result['draft'] !== '' ? $result['draft'] : null, 'ai_confidence' => $result['confidence'], 'ai_model' => $result['model'], 'citations' => $result['citations'], 'response_time_ms' => $result['response_time_ms'], 'metadata' => ['status' => $result['status'], 'source_count' => $result['source_count'], 'ollama_error' => $result['ollama_error'], 'reassessed_by' => (int) $_SESSION['user']['id']]]);
        flash($result['status'] === 'answered' ? 'A IA encontrou uma resposta fundamentada após a atualização da base. A pergunta saiu da fila humana.' : 'A IA foi reavaliada, mas ainda não encontrou evidência suficiente.');
    } catch (Throwable $error) {
        audit_event('question_reassessed', 'admin', ['conversation_id' => $conversationId > 0 ? $conversationId : null, 'metadata' => ['status' => 'error', 'error' => mb_substr($error->getMessage(), 0, 500, 'UTF-8'), 'reassessed_by' => (int) $_SESSION['user']['id']]]);
        flash('Não foi possível reavaliar a pergunta: ' . mb_substr($error->getMessage(), 0, 300, 'UTF-8'));
    }
    header('Location: ?route=admin&section=pending');
    exit;
}

if ($route === 'guidance-settings' && admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $pdo = db();
    try {
        $guidance = AiGuidance::normalize([
            'public_intro' => (string) ($_POST['ai_public_intro'] ?? ''),
            'soul' => (string) ($_POST['ai_soul'] ?? ''),
            'rules' => (string) ($_POST['ai_interpretation_rules'] ?? ''),
        ]);
        $pdo->beginTransaction();
        $artifact = AiGuidance::synchronize($pdo, $guidance, rag_storage_dir(), brand_name(), (int) ($_SESSION['user']['id'] ?? 0));
        save_setting('ai_public_intro', $guidance['public_intro']);
        save_setting('ai_soul', $guidance['soul']);
        save_setting('ai_interpretation_rules', $guidance['rules']);
        $pdo->commit();
        audit_event('guidance_updated', 'admin', ['metadata' => ['updated_by' => (int) ($_SESSION['user']['id'] ?? 0), 'document_id' => $artifact['document_id'], 'rule_count' => $artifact['rule_count'], 'canonical_sha256' => $artifact['canonical_sha256'], 'byte_size' => $artifact['byte_size']]]);
        flash('Diretrizes da IA salvas e convertidas para memória Markdown controlada.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('Não foi possível salvar as diretrizes: ' . mb_substr($error->getMessage(), 0, 300, 'UTF-8'));
    }
    header('Location: ?route=admin&section=guidance');
    exit;
}

if ($route === 'settings' && admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $allowedModels = array_keys(ollama_models());
    $model = trim((string) ($_POST['chat_model'] ?? 'qwen3:4b'));
    if (!in_array($model, $allowedModels, true)) {
        $model = 'qwen3:4b';
    }
    $confidence = max(0.50, min(0.99, (float) ($_POST['min_confidence'] ?? 0.75)));
    $sources = max(1, min(3, (int) ($_POST['min_sources'] ?? 1)));
    $timeout = max(20, min(180, (int) ($_POST['timeout'] ?? 120)));
    $newBrandName = trim((string) ($_POST['brand_name'] ?? brand_name()));
    $newBrandSubtitle = trim((string) ($_POST['brand_subtitle'] ?? brand_subtitle()));
    $newDefaultScopeResponse = trim((string) ($_POST['default_scope_response'] ?? default_scope_template()));
    if ($newDefaultScopeResponse === '') {
        $newDefaultScopeResponse = 'As perguntas devem ser referentes a {empresa}. Sua pergunta não está no contexto deste agente.';
    }
    $newDefaultScopeResponse = mb_substr($newDefaultScopeResponse, 0, 500, 'UTF-8');
    if ($newBrandName === '') {
        $newBrandName = 'RAGLocal';
    }
    $newBrandName = mb_substr($newBrandName, 0, 120, 'UTF-8');
    $newBrandSubtitle = mb_substr($newBrandSubtitle, 0, 240, 'UTF-8');
    save_setting('ollama_chat_model', $model);
    save_setting('rag_min_confidence', number_format($confidence, 2, '.', ''));
    save_setting('rag_min_sources', (string) $sources);
    save_setting('ollama_timeout', (string) $timeout);
    save_setting('brand_name', $newBrandName);
    save_setting('brand_subtitle', $newBrandSubtitle);
    save_setting('default_scope_response', $newDefaultScopeResponse);
    if (!empty($_POST['remove_logo'])) {
        $oldLogoPath = brand_logo_path();
        if ($oldLogoPath !== '' && is_file($oldLogoPath)) {
            @unlink($oldLogoPath);
        }
        save_setting('brand_logo_filename', '');
        save_setting('brand_logo_mime', '');
    }
    $logoFile = $_FILES['logo'] ?? null;
    if ($logoFile && $logoFile['error'] === UPLOAD_ERR_OK && is_uploaded_file($logoFile['tmp_name'])) {
        $logoMap = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $logoMime = function_exists('mime_content_type') ? (string) mime_content_type($logoFile['tmp_name']) : '';
        $logoBytes = (int) ($logoFile['size'] ?? 0);
        if ($logoBytes < 1 || $logoBytes > 2 * 1024 * 1024 || !isset($logoMap[$logoMime]) || !ensure_rag_storage()) {
            flash('Configurações salvas, mas o logotipo foi rejeitado. Use PNG, JPG, WEBP ou GIF de até 2 MB.');
        } else {
            $logoFilename = 'brand-logo.' . $logoMap[$logoMime];
            $logoPath = rag_storage_dir() . '/' . $logoFilename;
            $oldLogoPath = brand_logo_path();
            if (move_uploaded_file($logoFile['tmp_name'], $logoPath)) {
                @chmod($logoPath, 0660);
                if ($oldLogoPath !== '' && $oldLogoPath !== $logoPath && is_file($oldLogoPath)) {
                    @unlink($oldLogoPath);
                }
                save_setting('brand_logo_filename', $logoFilename);
                save_setting('brand_logo_mime', $logoMime);
                flash('Configurações e logotipo salvos.');
            } else {
                flash('Configurações salvas, mas não foi possível gravar o logotipo.');
            }
        }
    } else {
        flash('Configurações de conexão, confiabilidade e marca salvas.');
    }
    header('Location: ?route=admin');
    exit;
}

if ($route === 'upload' && admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $file = $_FILES['document'] ?? null;
    $maximumBytes = 10 * 1024 * 1024;
    $storedMarkdownPath = null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || (int) $file['size'] > $maximumBytes) {
        flash('Falha no upload. O limite é 10 MB.');
    } else {
        $allowed = ['pdf', 'txt', 'md'];
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $kind = (string) ($_POST['kind'] ?? 'regimento');
        if (!in_array($kind, ['regimento', 'ata', 'memoria', 'manutencao'], true)) {
            $kind = 'regimento';
        }
        $title = trim((string) ($_POST['title'] ?? '')) ?: pathinfo((string) $file['name'], PATHINFO_FILENAME);
        $text = in_array($extension, $allowed, true) ? extract_text($file['tmp_name'], (string) $file['name']) : '';
        if (!in_array($extension, $allowed, true)) {
            flash('Aceitos apenas arquivos PDF, TXT e MD.');
        } elseif (trim($text) === '') {
            flash('Não foi possível extrair texto desse arquivo.');
        } elseif (!ensure_rag_storage()) {
            flash('Não foi possível gravar no armazenamento privado de documentos.');
        } else {
            $rag = build_rag_document($title, $kind, (string) $file['name'], $text);
            $sourceSha256 = hash_file('sha256', $file['tmp_name']) ?: '';
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('INSERT INTO documents(title, kind, source_filename, status, parser_version, canonical_sha256, created_by) VALUES(?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$title, $kind, (string) $file['name'], 'processing', $rag['parser_version'], $rag['canonical_sha256'], $_SESSION['user']['id']]);
                $documentId = (int) $pdo->lastInsertId();
                $storageDir = rag_storage_dir();
                $markdownFilename = 'document-' . $documentId . '.rag.md';
                $storedMarkdownPath = $storageDir . '/' . $markdownFilename;
                $markdownBytes = file_put_contents($storedMarkdownPath, $rag['markdown'], LOCK_EX);
                if ($markdownBytes === false || $markdownBytes !== strlen($rag['markdown'])) {
                    throw new RuntimeException('Falha ao armazenar o Markdown RAG.');
                }
                @chmod($storedMarkdownPath, 0660);
                $artifactStmt = $pdo->prepare('INSERT INTO document_artifacts(document_id, artifact_type, filename, storage_path, mime_type, byte_size, sha256, content) VALUES(?, ?, ?, ?, ?, ?, ?, ?)');
                $artifactStmt->execute([$documentId, 'markdown', $markdownFilename, 'storage/uploads/' . $markdownFilename, 'text/markdown; charset=UTF-8', $markdownBytes, $rag['canonical_sha256'], $rag['markdown']]);
                $insertChunk = $pdo->prepare('INSERT INTO chunks(document_id, chunk_no, content, section_heading, tags, page_start, page_end, token_count) VALUES(?, ?, ?, ?, ?, ?, ?, ?)');
                foreach ($rag['chunks'] as $number => $chunk) {
                    $insertChunk->execute([$documentId, $number + 1, $chunk['content'], $chunk['section_heading'], $chunk['tags'], $chunk['page_start'], $chunk['page_end'], $chunk['token_count']]);
                }
                if (!$rag['chunks']) {
                    throw new RuntimeException('O documento não gerou trechos recuperáveis.');
                }
                $pdo->prepare("UPDATE documents SET status = 'ready', processed_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$documentId]);
                $pdo->commit();
                audit_event('document_upload', 'admin', ['metadata' => ['document_id' => $documentId, 'kind' => $kind, 'extension' => $extension, 'bytes' => (int) $file['size'], 'chunk_count' => count($rag['chunks']), 'parser_version' => $rag['parser_version'], 'source_sha256' => $sourceSha256, 'canonical_sha256' => $rag['canonical_sha256'], 'markdown_bytes' => $markdownBytes]]);
                flash('Documento convertido para Markdown RAG e indexado com sucesso.');
            } catch (Throwable $error) {
                $pdo->rollBack();
                if ($storedMarkdownPath !== null) {
                    @unlink($storedMarkdownPath);
                }
                flash('Não foi possível converter e indexar o documento.');
            }
        }
    }
    header('Location: ?route=admin');
    exit;
}

if ($route === 'document-delete' && admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $documentId = (int) ($_POST['document_id'] ?? 0);
    try {
        $deleted = SourceRegistry::deleteManualDocument(db(), $documentId);
        audit_event('document_removed', 'admin', ['metadata' => [
            'document_id' => $documentId,
            'title' => $deleted['title'],
            'kind' => $deleted['kind'],
            'parser_version' => $deleted['parser_version'],
            'chunk_count' => $deleted['chunk_count'],
            'deleted_by' => (int) $_SESSION['user']['id'],
        ]]);
        flash('Documento e seus trechos indexados foram excluídos. Você já pode enviar uma versão atualizada.');
    } catch (Throwable $error) {
        flash('Não foi possível excluir este documento: ' . $error->getMessage());
    }
    header('Location: ?route=admin&section=knowledge');
    exit;
}

if ($route === 'ignore' && admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $conversationId = (int) ($_POST['conversation_id'] ?? 0);
    $pdo = db();
    $stmt = $pdo->prepare("SELECT c.id, m.body FROM conversations c JOIN messages m ON m.conversation_id = c.id WHERE c.id = ? AND c.status = 'human_pending' AND m.sender = 'resident' ORDER BY m.id DESC LIMIT 1");
    $stmt->execute([$conversationId]);
    $question = $stmt->fetch();
    if ($question) {
        $pdo->prepare("UPDATE conversations SET status = 'closed' WHERE id = ? AND status = 'human_pending'")->execute([$conversationId]);
        audit_event('question_ignored', 'admin', ['conversation_id' => $conversationId, 'question' => (string) $question['body'], 'metadata' => ['ignored_by' => (int) $_SESSION['user']['id'], 'reason' => 'admin_ignored_without_answer']]);
        flash('Pergunta ignorada e removida da fila. O histórico foi preservado na auditoria.');
    } else {
        flash('A pergunta não está mais pendente.');
    }
    header('Location: ?route=admin&section=pending');
    exit;
}

if ($route === 'answer' && admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $pdo = db();
    $conversationId = (int) ($_POST['conversation_id'] ?? 0);
    $answer = trim((string) ($_POST['answer'] ?? ''));
    $stmt = $pdo->prepare("SELECT m.body, c.id FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.id = ? AND m.sender = 'resident' ORDER BY m.id DESC LIMIT 1");
    $stmt->execute([$conversationId]);
    $question = $stmt->fetch();
    if ($question && $answer !== '') {
        $pdo->prepare('INSERT INTO human_answers(conversation_id, question, answer, approved, answered_by) VALUES(?, ?, ?, ?, ?)')->execute([$question['id'], $question['body'], $answer, 1, $_SESSION['user']['id']]);
        $pdo->prepare("INSERT INTO messages(conversation_id, sender, body) VALUES(?, 'human', ?)")->execute([$question['id'], $answer]);
        $humanMessageId = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE conversations SET status = 'answered' WHERE id = ?")->execute([$question['id']]);
        audit_event('human_answer', 'human', ['conversation_id' => $conversationId, 'message_id' => $humanMessageId, 'question' => (string) $question['body'], 'answer' => $answer, 'metadata' => ['answered_by' => (int) $_SESSION['user']['id']]]);
        $docStmt = $pdo->prepare("SELECT id FROM documents WHERE kind = 'memoria' AND title = 'Respostas validadas por atendentes' LIMIT 1");
        $docStmt->execute();
        $document = $docStmt->fetch();
        if (!$document) {
            $pdo->prepare("INSERT INTO documents(title, kind, status, created_by) VALUES('Respostas validadas por atendentes', 'memoria', 'ready', ?)")->execute([$_SESSION['user']['id']]);
            $document = ['id' => $pdo->lastInsertId()];
        }
        $next = (int) $pdo->query('SELECT COALESCE(MAX(chunk_no), 0) + 1 FROM chunks WHERE document_id = ' . (int) $document['id'])->fetchColumn();
        $memoryContent = '[RAG_MEMORIA_VALIDADA] [FONTE] Respostas validadas por atendentes [TAGS] memoria validada atendente\nPergunta: ' . $question['body'] . ' Resposta validada: ' . $answer;
        $pdo->prepare('INSERT INTO chunks(document_id, chunk_no, content, section_heading, tags, token_count) VALUES(?, ?, ?, ?, ?, ?)')->execute([(int) $document['id'], $next, $memoryContent, 'Resposta humana validada', 'memoria validada atendente', count(preg_split('/\\s+/u', $memoryContent, -1, PREG_SPLIT_NO_EMPTY) ?: [])]);
        flash('Resposta registrada e incorporada à memória validada.');
    }
    header('Location: ?route=admin');
    exit;
}

if ($route === 'admin') {
    $pdo = db();
    $section = (string) ($_GET['section'] ?? 'overview');
    $validSections = ['overview', 'pending', 'knowledge', 'branding', 'guidance', 'settings', 'sources', 'security'];
    if (!in_array($section, $validSections, true)) {
        $section = 'overview';
    }
    $documents = $pdo->query("SELECT d.id, d.title, d.kind, d.status, d.source_filename, d.parser_version, d.canonical_sha256, d.created_at, d.processed_at,
            (SELECT COUNT(*) FROM chunks c WHERE c.document_id = d.id) AS chunk_count,
            EXISTS (SELECT 1 FROM document_artifacts a WHERE a.document_id = d.id) AS has_artifact,
            EXISTS (SELECT 1 FROM document_source_links l WHERE l.document_id = d.id) AS source_linked
        FROM documents d
        ORDER BY d.id DESC LIMIT 30")->fetchAll();
    $documentTotal = (int) $pdo->query("SELECT COUNT(*) FROM documents WHERE status = 'ready'")->fetchColumn();
    $pending = $pdo->query("SELECT c.id, c.ai_draft, c.ai_confidence, c.ai_model, m.body, m.created_at AS question_created_at FROM conversations c JOIN messages m ON m.conversation_id = c.id WHERE c.status = 'human_pending' AND m.sender = 'resident' AND m.id = (SELECT MAX(m2.id) FROM messages m2 WHERE m2.conversation_id = c.id AND m2.sender = 'resident') ORDER BY m.created_at DESC, m.id DESC")->fetchAll();
    $pendingCount = count($pending);
    $models = ollama_models();
    $selectedModel = setting('ollama_chat_model', envv('OLLAMA_CHAT_MODEL', 'qwen3:4b'));
    $minConfidence = rag_min_confidence();
    $minSources = rag_min_sources();
    $timeout = ollama_timeout();
    $currentBrandName = brand_name();
    $currentBrandSubtitle = brand_subtitle();
    $currentLogo = brand_logo_filename();
    $defaultScopeResponse = default_scope_template();
    $currentGuidance = AiGuidance::fromSettings($pdo);
    $sourceRows = SourceRegistry::all($pdo);
    $sourceEdit = null;
    $sourceEditConfig = [];
    $sourceEditId = (int) ($_GET['edit_source'] ?? 0);
    if ($sourceEditId > 0) {
        $sourceEdit = SourceRegistry::find($pdo, $sourceEditId);
        if ($sourceEdit) {
            $sourceEditConfig = SourceRegistry::publicConfig($sourceEdit);
        }
    }
    $flashMessage = take_flash();
    $menuLink = static function (string $key, string $label, string $count = '') use ($section): string {
        $active = $section === $key ? ' active' : '';
        $badge = $count !== '' ? '<span class="nav-count">' . h($count) . '</span>' : '';
        return '<a class="' . $active . '" href="?route=admin&amp;section=' . h($key) . '">' . h($label) . $badge . '</a>';
    };
    $body = '<div class="admin-shell"><div class="admin-head"><div><div class="eyebrow">PAINEL ADMINISTRATIVO</div><h2>Centro de operação</h2><p class="muted">Gerencie a base RAG, a identidade pública e os atendimentos que precisam de decisão humana.</p></div><div class="admin-actions"><a href="?">Atendimento público</a><a href="?route=logout">Sair</a></div></div><nav class="admin-menu" aria-label="Menu administrativo">' . $menuLink('overview', 'Visão geral') . $menuLink('pending', 'Intervenção humana', $pendingCount > 0 ? (string) $pendingCount : '') . $menuLink('knowledge', 'Base de conhecimento') . $menuLink('branding', 'Identidade da empresa') . $menuLink('guidance', 'Diretrizes da IA') . $menuLink('settings', 'Confiabilidade e Ollama') . $menuLink('sources', 'Fontes de conhecimento', count($sourceRows) > 0 ? (string) count($sourceRows) : '') . $menuLink('security', 'Segurança') . '</nav>';
    if ($flashMessage !== '') {
        $body .= '<div class="success-alert">' . h($flashMessage) . '</div>';
    }
    if ($section === 'overview') {
        $body .= '<div class="admin-stats"><div class="admin-stat"><span class="muted">Documentos prontos</span><strong>' . $documentTotal . '</strong></div><div class="admin-stat"><span class="muted">Perguntas aguardando atendimento</span><strong>' . $pendingCount . '</strong></div><div class="admin-stat"><span class="muted">Modelo ativo</span><strong style="font-size:1em">' . h($selectedModel) . '</strong></div></div>';
        if ($pendingCount > 0) {
            $body .= '<div class="urgent-alert"><div><strong>Há ' . $pendingCount . ' ' . ($pendingCount === 1 ? 'pergunta aguardando' : 'perguntas aguardando') . ' intervenção humana.</strong><br><span>Esses atendimentos não receberam resposta automática confiável.</span></div><a href="?route=admin&amp;section=pending">Abrir fila prioritária</a></div>';
        } else {
            $body .= '<div class="success-alert">Nenhuma pergunta aguarda intervenção humana no momento.</div>';
        }
        $body .= '<div class="grid"><div class="card"><h3>Base de conhecimento</h3><p class="muted">Envie regimentos, atas, certificados e memórias validadas. Os arquivos são convertidos para Markdown RAG.</p><a href="?route=admin&amp;section=knowledge">Gerenciar documentos</a></div><div class="card"><h3>Identidade da empresa</h3><p class="muted">Personalize nome, descrição e logotipo apresentados aos moradores.</p><a href="?route=admin&amp;section=branding">Editar identidade</a></div><div class="card"><h3>Diretrizes da IA</h3><p class="muted">Defina a mensagem inicial, a identidade do assistente e regras de interpretação de termos.</p><a href="?route=admin&amp;section=guidance">Gerenciar diretrizes</a></div><div class="card"><h3>Confiabilidade e Ollama</h3><p class="muted">Ajuste modelo, limiar de confiança, fontes mínimas e tempo limite.</p><a href="?route=admin&amp;section=settings">Revisar configurações</a></div><div class="card"><h3>Fontes de conhecimento</h3><p class="muted">Ative plugins configuráveis para importar bancos, arquivos e outros repositórios externos. Nenhum conector é obrigatório.</p><a href="?route=admin&amp;section=sources">Gerenciar fontes</a></div></div>';
    } elseif ($section === 'pending') {
        $body .= '<div class="card"><div class="eyebrow">AÇÃO PRIORITÁRIA</div><h3>Intervenção humana necessária</h3><p class="section-intro muted">Responda cada pergunta abaixo para concluir o atendimento. A resposta aprovada será incorporada à memória validada do RAGLocal.</p>';
        if ($pendingCount > 0) {
            $body .= '<div class="urgent-alert"><strong>' . $pendingCount . ' ' . ($pendingCount === 1 ? 'atendimento pendente' : 'atendimentos pendentes') . '</strong><span>Ordenados do mais recente para o mais antigo.</span></div>';
            foreach ($pending as $item) {
                $confidence = $item['ai_confidence'] === null ? 'não calculada' : number_format((float) $item['ai_confidence'] * 100, 0, ',', '.') . '%';
                $questionTime = format_datetime_br((string) $item['question_created_at']);
                $waiting = waiting_time((string) $item['question_created_at']);
                $body .= '<form action="?route=answer" method="post" class="pending-card"><input type="hidden" name="csrf" value="' . csrf() . '"><input type="hidden" name="conversation_id" value="' . (int) $item['id'] . '"><div class="pending-meta"><b>PENDENTE</b> · Recebida em ' . h($questionTime) . ' · Tempo de espera: ' . h($waiting) . '</div><p><b>Pergunta do morador</b><br>' . h((string) $item['body']) . '</p><div class="reference"><b>Rascunho da IA para referência</b> <span class="badge">' . h($confidence) . ' · ' . h((string) ($item['ai_model'] ?: 'modelo desconhecido')) . '</span><br>' . h((string) ($item['ai_draft'] ?: 'O modelo não produziu uma resposta estruturada.')) . '</div><label>Resposta do atendente<textarea name="answer" placeholder="Escreva a resposta validada para este morador e para a memória do RAG..." required></textarea></label><div class="button-row"><button>Salvar resposta e ensinar a IA</button><button type="submit" formaction="?route=reassess" formmethod="post" formnovalidate class="button-secondary">Reavaliar com a base atualizada</button><button type="submit" formaction="?route=ignore" formmethod="post" formnovalidate class="button-secondary">Ignorar sem responder</button></div></form>';
            }
        } else {
            $body .= '<div class="empty-state">A fila está vazia. Quando uma pergunta não tiver evidência suficiente, ela aparecerá aqui com destaque.</div>';
        }
        $body .= '</div>';
    } elseif ($section === 'knowledge') {
        $body .= '<div class="card"><h3>Adicionar à base de conhecimento</h3><p class="muted">Envie PDF, TXT ou MD. O arquivo é convertido para Markdown canônico e indexado para recuperação. Para substituir um arquivo, exclua a versão anterior e envie a versão atualizada.</p><form action="?route=upload" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="' . csrf() . '"><label>Título<input name="title" required></label><label>Tipo<select name="kind"><option value="regimento">Regimento interno</option><option value="ata">Ata</option><option value="memoria">Memória validada</option><option value="manutencao">Manutenção / certificado técnico</option></select></label><label>Arquivo PDF, TXT ou MD<input type="file" name="document" accept=".pdf,.txt,.md" required></label><button>Enviar e indexar</button></form></div><div class="card"><h3>Documentos indexados</h3><p class="muted">A versão exibida identifica o processador que gerou o Markdown RAG. A exclusão remove o documento, seus trechos e o arquivo Markdown armazenado; diretrizes, memória automática e fontes externas possuem fluxos próprios.</p><table><thead><tr><th>Documento</th><th>Tipo</th><th>Status</th><th>Processamento</th><th>Ações</th></tr></thead><tbody>';
        foreach ($documents as $document) {
            $version = trim((string) ($document['parser_version'] ?? '')) ?: 'legado';
            $processedAt = !empty($document['processed_at']) ? format_datetime_br((string) $document['processed_at']) : 'ainda não processado';
            $sourceName = trim((string) ($document['source_filename'] ?? ''));
            $hash = trim((string) ($document['canonical_sha256'] ?? ''));
            $details = 'Versão: ' . h($version) . ' · ' . (int) $document['chunk_count'] . ' ' . ((int) $document['chunk_count'] === 1 ? 'trecho' : 'trechos') . '<br><span class="muted">Processado: ' . h($processedAt) . ($hash !== '' ? ' · SHA-256: ' . h(substr($hash, 0, 12)) . '…' : '') . ($sourceName !== '' ? ' · Arquivo: ' . h($sourceName) : '') . '</span>';
            $canDelete = (string) $document['kind'] !== 'diretriz' && (int) $document['source_linked'] !== 1 && (int) $document['has_artifact'] === 1 && $sourceName !== '' && !str_starts_with($sourceName, 'source://');
            if ($canDelete) {
                $action = '<form action="?route=document-delete" method="post" onsubmit="return confirm(\'Excluir este documento e todos os seus trechos indexados? Esta ação não pode ser desfeita.\')"><input type="hidden" name="csrf" value="' . csrf() . '"><input type="hidden" name="document_id" value="' . (int) $document['id'] . '"><button class="button-danger">Excluir arquivo</button></form>';
            } elseif ((int) $document['source_linked'] === 1 || str_starts_with($sourceName, 'source://')) {
                $action = '<span class="muted">Gerenciado por fonte externa</span>';
            } else {
                $action = '<span class="muted">Gerado pelo sistema</span>';
            }
            $body .= '<tr><td><b>' . h((string) $document['title']) . '</b></td><td>' . h(document_kind_label((string) $document['kind'])) . '</td><td><span class="badge">' . h((string) $document['status']) . '</span><br><span class="muted">Adicionado: ' . h(format_datetime_br((string) $document['created_at'])) . '</span></td><td>' . $details . '</td><td>' . $action . '</td></tr>';
        }
        if (!$documents) {
            $body .= '<tr><td colspan="5" class="muted">Nenhum documento indexado.</td></tr>';
        }
        $body .= '</tbody></table></div>';
    } elseif ($section === 'branding') {
        $body .= '<div class="card"><h3>Identidade da empresa</h3><p class="muted">Esses dados aparecem no cabeçalho da página pública e do painel.</p><form action="?route=settings" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="' . csrf() . '"><input type="hidden" name="chat_model" value="' . h($selectedModel) . '"><input type="hidden" name="min_confidence" value="' . h(number_format($minConfidence, 2, '.', '')) . '"><input type="hidden" name="min_sources" value="' . h((string) $minSources) . '"><input type="hidden" name="timeout" value="' . h((string) $timeout) . '"><label>Nome exibido<input name="brand_name" maxlength="120" value="' . h($currentBrandName) . '" required></label><label>Descrição curta<input name="brand_subtitle" maxlength="240" value="' . h($currentBrandSubtitle) . '"></label><label>Logotipo<input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif"></label><span class="muted">PNG, JPG, WEBP ou GIF, até 2 MB. ' . ($currentLogo !== '' ? 'Logotipo atual: ' . h($currentLogo) . '.' : 'Nenhum logotipo configurado.') . '</span><label><input type="checkbox" name="remove_logo" value="1"> Remover logotipo atual</label><br><button>Salvar identidade</button></form></div>';
    } elseif ($section === 'guidance') {
        $ruleExample = "SIGLA => Nome completo da organização";
        $body .= '<div class="card"><div class="eyebrow">MEMÓRIA CONTROLADA</div><h3>Diretrizes da IA</h3><p class="muted">Defina a orientação mostrada ao público, a identidade comportamental do assistente e regras para interpretar siglas, sinônimos ou termos internos. Ao salvar, o RAGLocal atualiza um Markdown canônico auditável. As regras definem interpretação e tom; elas nunca substituem documentos como evidência factual.</p><form action="?route=guidance-settings" method="post"><input type="hidden" name="csrf" value="' . csrf() . '"><label>Mensagem inicial do atendimento público<textarea name="ai_public_intro" maxlength="800" rows="4" required>' . h((string) $currentGuidance['public_intro']) . '</textarea></label><span class="muted">Este é o texto exibido antes do campo de pergunta na página pública.</span><label>Alma da IA<textarea name="ai_soul" maxlength="2400" rows="8" required>' . h((string) $currentGuidance['soul']) . '</textarea></label><span class="muted">Use <code>{empresa}</code> para inserir o nome configurado da organização.</span><label>Regras interpretativas<textarea name="ai_interpretation_rules" maxlength="12000" rows="10" placeholder="' . h($ruleExample) . '">' . h((string) $currentGuidance['rules']) . '</textarea></label><span class="muted">Uma regra por linha no formato <code>termo =&gt; significado</code>. Exemplo: <code>' . h($ruleExample) . '</code>. Linhas iniciadas com <code>#</code> são ignoradas.</span><br><button>Salvar diretrizes e atualizar memória</button></form></div>';
    } elseif ($section === 'settings') {
        $body .= '<div class="card"><h3>Confiabilidade e Ollama</h3><p class="muted">Endpoint: ' . h(envv('OLLAMA_URL', 'não configurado')) . '. A resposta só é publicada quando há evidência suficiente, fontes válidas e confiança acima do limiar.</p><form action="?route=settings" method="post"><input type="hidden" name="csrf" value="' . csrf() . '"><label>Modelo de chat<select name="chat_model">';
        foreach ($models as $model => $description) {
            $body .= '<option value="' . h($model) . '"' . ($model === $selectedModel ? ' selected' : '') . '>' . h($description) . '</option>';
        }
        $body .= '</select></label><label>Limiar mínimo de confiança (0,50 a 0,99)<input name="min_confidence" type="number" min="0.50" max="0.99" step="0.01" value="' . h(number_format($minConfidence, 2, '.', '')) . '"></label><label>Fontes mínimas citadas<input name="min_sources" type="number" min="1" max="3" step="1" value="' . h((string) $minSources) . '"></label><label>Tempo máximo de consulta (segundos)<input name="timeout" type="number" min="20" max="180" step="5" value="' . h((string) $timeout) . '"></label><label>Resposta padrão para perguntas fora do contexto<textarea name="default_scope_response" maxlength="500" rows="4">' . h($defaultScopeResponse) . '</textarea><span class="muted">Use <code>{empresa}</code> para inserir automaticamente o nome configurado da empresa.</span><button>Salvar configurações</button></form><p class="muted">Para hardware limitado, comece com <b>qwen3:4b</b>, já instalado, e limiar 0,75. Modelos de 1B são alternativas mais leves, mas precisam ser instalados no servidor Ollama antes do uso.</p></div>';
    } elseif ($section === 'sources') {
        $sourceForForm = $sourceEdit ?: ['id' => 0, 'source_key' => '', 'plugin_key' => 'database_table', 'name' => '', 'description' => ''];
        $sourceConfigForForm = $sourceEditConfig ?: ['host' => '', 'port' => 3306, 'database' => '', 'user' => '', 'password_enc' => '', 'table' => '', 'key_column' => 'id', 'title_column' => 'title', 'content_columns' => [], 'filter_column' => '', 'filter_value' => '', 'status_column' => '', 'status_value' => '', 'published_column' => '', 'modified_column' => '', 'url_column' => '', 'public_url_template' => '', 'withdraw_missing' => false];
        $body .= '<div class="card"><div class="eyebrow">ARQUITETURA DE PLUGINS</div><h3>Fontes de conhecimento</h3><p class="muted">Uma fonte conecta um plugin a um repositório externo e produz documentos locais para o RAG. Plugins podem ser ativados, desativados, editados ou removidos por instalação. Notícias, produtos, pesquisas, estoque e outros domínios são apenas exemplos de dados; não são módulos obrigatórios do RAGLocal.</p><p class="muted"><b>Pipeline:</b> o plugin faz a ingestão (Loader), a normalização e fragmentação formam os chunks (Chunker), o MariaDB mantém o índice (Indexer), a busca seleciona evidências (Retriever) e o Ollama gera a resposta fundamentada.</p></div>';
        $body .= source_form_html($sourceForForm, $sourceConfigForForm, csrf());
        $body .= '<div class="card"><h3>Fontes configuradas</h3><p class="muted">Desativar uma fonte impede que seus documentos sejam recuperados. Remover uma fonte exclui também os documentos derivados e seus chunks, mas mantém o registro da operação na auditoria.</p>';
        if (!$sourceRows) {
            $body .= '<div class="empty-state">Nenhuma fonte externa configurada. O RAGLocal continua funcionando apenas com os documentos enviados diretamente à base.</div>';
        } else {
            $body .= '<table><thead><tr><th>Fonte</th><th>Plugin</th><th>Estado</th><th>Itens indexados</th><th>Última sincronização</th><th>Ações</th></tr></thead><tbody>';
            foreach ($sourceRows as $sourceRow) {
                $sourceId = (int) $sourceRow['id'];
                $sourceConfig = SourceRegistry::publicConfig($sourceRow);
                $lastRun = source_last_run($pdo, $sourceId);
                $state = (int) $sourceRow['enabled'] === 1 ? '<span class="badge">Ativa</span>' : '<span class="badge" style="background:#f2f4f7;color:#667085">Desativada</span>';
                $pluginDefinition = SourceRegistry::plugins()[(string) $sourceRow['plugin_key']] ?? null;
                $pluginLabel = is_array($pluginDefinition) ? (string) ($pluginDefinition['label'] ?? $sourceRow['plugin_key']) : (string) $sourceRow['plugin_key'];
                $lastRunLabel = is_array($lastRun) ? h(format_datetime_br((string) $lastRun['started_at'])) . '<br><span class="muted">' . h((string) $lastRun['status']) . '</span>' : '<span class="muted">Nunca</span>';
                $toggleLabel = (int) $sourceRow['enabled'] === 1 ? 'Desativar' : 'Ativar';
                $body .= '<tr><td><b>' . h((string) $sourceRow['name']) . '</b><br><span class="muted">' . h((string) $sourceRow['source_key']) . '</span><br><span class="muted">' . h((string) ($sourceRow['description'] ?? '')) . '</span></td><td>' . h($pluginLabel) . '</td><td>' . $state . '</td><td>' . source_document_count($pdo, $sourceId) . '</td><td>' . $lastRunLabel . '</td><td><div class="button-row"><a href="?route=admin&amp;section=sources&amp;edit_source=' . $sourceId . '">Editar</a><form action="?route=source-sync" method="post"><input type="hidden" name="csrf" value="' . csrf() . '"><input type="hidden" name="source_id" value="' . $sourceId . '"><button type="submit">Sincronizar</button></form><form action="?route=source-toggle" method="post"><input type="hidden" name="csrf" value="' . csrf() . '"><input type="hidden" name="source_id" value="' . $sourceId . '"><button type="submit" class="button-secondary">' . $toggleLabel . '</button></form><form action="?route=source-remove" method="post"><input type="hidden" name="csrf" value="' . csrf() . '"><input type="hidden" name="source_id" value="' . $sourceId . '"><input name="confirm_remove" placeholder="Digite REMOVE" size="12" required><button type="submit" class="button-secondary">Remover</button></form></div></td></tr>';
            }
            $body .= '</tbody></table>';
        }
        $body .= '</div>';
    } elseif ($section === 'security') {
        $body .= '<div class="card"><div class="eyebrow">SEGURANÇA DA CONTA</div><h3>Alterar senha administrativa</h3><p class="muted">Troque sua senha a qualquer momento. A senha atual é exigida e a nova senha deve ter entre 12 e 255 caracteres.</p><form action="?route=password" method="post"><input type="hidden" name="csrf" value="' . csrf() . '"><label>Senha atual<input name="current_password" type="password" autocomplete="current-password" required></label><label>Nova senha<input name="new_password" type="password" minlength="12" maxlength="255" autocomplete="new-password" required></label><label>Confirme a nova senha<input name="confirm_password" type="password" minlength="12" maxlength="255" autocomplete="new-password" required></label><button>Alterar senha</button></form></div>';
    }
    $body .= '</div>';
    layout('Administração', $body);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $question = trim((string) ($_POST['question'] ?? ''));
    if ($question === '') {
        layout('Atendimento', '<div class="card">Informe uma pergunta.</div>');
    }
    $token = $_SESSION['conv'] ?? bin2hex(random_bytes(32));
    $_SESSION['conv'] = $token;
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM conversations WHERE session_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $conversation = $stmt->fetch();
    if (!$conversation) {
        $pdo->prepare('INSERT INTO conversations(session_token) VALUES(?)')->execute([$token]);
        $conversationId = (int) $pdo->lastInsertId();
    } else {
        $conversationId = (int) $conversation['id'];
    }
    $pdo->prepare("INSERT INTO messages(conversation_id, sender, body) VALUES(?, 'resident', ?)")->execute([$conversationId, $question]);
    $residentMessageId = (int) $pdo->lastInsertId();
    $startedAt = microtime(true);
    $sources = context($question);
    $result = ollama_call($question, $sources);
    $responseTimeMs = (int) max(0, round((microtime(true) - $startedAt) * 1000));
    $reference = $result['answer'];
    if ($result['approved']) {
        $publicAnswer = $result['answer'];
        $status = 'answered';
    } else {
        $publicAnswer = default_scope_response();
        $status = 'human_pending';
    }
    $pdo->prepare('UPDATE conversations SET status = ?, ai_draft = ?, ai_confidence = ?, ai_model = ? WHERE id = ?')->execute([$status, $reference !== '' ? $reference : null, $result['confidence'], $result['model'], $conversationId]);
    $citations = [];
    foreach ($result['source_numbers'] as $number) {
        $source = $sources[$number - 1] ?? null;
        if ($source) {
            $citations[] = ['title' => $source['title'], 'kind' => $source['kind'], 'public_url' => (string) ($source['public_url'] ?? ''), 'published_at' => (string) ($source['published_at'] ?? '')];
        }
    }
    $pdo->prepare('INSERT INTO messages(conversation_id, sender, body, citations) VALUES(?, \'ai\', ?, ?)')->execute([$conversationId, $publicAnswer, json_encode($citations, JSON_UNESCAPED_UNICODE)]);
    $aiMessageId = (int) $pdo->lastInsertId();
    audit_event('question', 'resident', ['conversation_id' => $conversationId, 'message_id' => $residentMessageId, 'question' => $question, 'metadata' => ['source_count' => count($sources)]]);
    audit_event('ai_answer', 'ai', ['conversation_id' => $conversationId, 'message_id' => $aiMessageId, 'question' => $question, 'answer' => $publicAnswer, 'ai_draft' => $reference !== '' ? $reference : null, 'ai_confidence' => $result['confidence'], 'ai_model' => $result['model'], 'citations' => $citations, 'response_time_ms' => $responseTimeMs, 'metadata' => ['approved' => (bool) $result['approved'], 'status' => $status, 'ollama_error' => $result['error'], 'source_count' => count($sources), 'response_time_ms' => $responseTimeMs]]);
    $body = '<div class="card"><h2>Resposta</h2><div class="answer">' . h($publicAnswer) . '</div>';
    foreach ($citations as $citation) {
        $citationTitle = h((string) $citation['title']);
        $citationUrl = trim((string) ($citation['public_url'] ?? ''));
        $citationLink = preg_match('#^https?://#i', $citationUrl) === 1 ? ' <a href="' . h($citationUrl) . '" target="_blank" rel="noopener noreferrer">Abrir fonte</a>' : '';
        $body .= '<div class="response-source"><b>Fonte:</b> ' . $citationTitle . $citationLink . '</div>';
    }
    $body .= '<div class="response-meta">Tempo para localizar e processar a resposta: ' . h(format_response_time($responseTimeMs)) . '</div>';
    if ($status === 'human_pending') {
        $body .= '<p class="muted">A pergunta e a resposta calculada pelo modelo foram registradas para análise administrativa.</p>';
    }
    $body .= '</div><div class="card"><a href="?">Fazer outra pergunta</a></div>';
    layout('Resposta', $body);
}

layout('Atendimento', '<div class="card"><p>' . h(ai_public_intro()) . '</p><form method="post"><input type="hidden" name="csrf" value="' . csrf() . '">Pergunta<textarea name="question" rows="5" placeholder="Digite sua dúvida..." required></textarea><button>Consultar</button></form></div><p class="muted"><a href="?route=login">Acesso administrativo</a></p>');
