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
        $contextParts[] = '[' . $number . '] Documento: ' . $source['title'] . ' | Tipo: ' . document_kind_label((string) $source['kind']) . "\n" . $source['content'];
    }
    $context = implode("\n\n", $contextParts);
    $model = setting('ollama_chat_model', envv('OLLAMA_CHAT_MODEL', 'qwen3:4b'));
    $prompt = "Você é o assistente oficial de " . brand_name() . ".\n\n" .
        "Use exclusivamente as fontes numeradas no CONTEXTO. Primeiro compare a pergunta com as fontes. Memórias marcadas como [RAG_MEMORIA_VALIDADA] são respostas humanas aprovadas e podem ser usadas como evidência quando a pergunta atual tiver a mesma intenção, mesmo que esteja formulada com outras palavras. Nesse caso, adapte a redação apenas o necessário e preserve o conteúdo da resposta validada; não acrescente conhecimento geral. " .
        "Se alguma fonte sustentar diretamente a resposta, responda em 1 a 3 frases completas, copiando ou resumindo somente os fatos dessa fonte; inclua o fato principal e a informação complementar mais relevante que esteja na mesma fonte, como serviço realizado, data ou validade. Se a pergunta pedir quando, informe a data e explique a que serviço ou evento ela se refere. Nesse caso, grounded deve ser true, confidence deve ser um número entre 0.75 e 1.00 e source_numbers deve conter todas as fontes usadas. " .
        "Se nenhuma fonte sustentar diretamente a resposta, grounded deve ser false, confidence deve ser 0, source_numbers deve ser [] e answer deve dizer que não encontrou base suficiente. " .
        "Nunca use conhecimento geral, não complete lacunas, não faça suposições e não invente horários, multas, artigos, datas, decisões ou interpretações. " .
        "Retorne SOMENTE um JSON válido, sem markdown, exatamente com estas chaves: grounded (boolean), confidence (number), answer (string em português brasileiro), source_numbers (array de números).\n\n" .
        "CONTEXTO:\n" . $context . "\n\nPERGUNTA:\n" . $question;

    $payload = [
        'model' => $model,
        'prompt' => $prompt,
        'stream' => false,
        'format' => 'json',
        'think' => false,
        'keep_alive' => '5m',
        'options' => [
            'temperature' => 0.0,
            'num_predict' => 260,
        ],
    ];

    $endpoint = ollama_endpoint();
    if ($endpoint === '') {
        return ['approved' => false, 'answer' => '', 'confidence' => 0.0, 'source_numbers' => [], 'model' => $model, 'error' => 'ollama_endpoint_not_allowed'];
    }
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
        return ['approved' => false, 'answer' => '', 'confidence' => 0.0, 'source_numbers' => [], 'model' => $model, 'error' => 'ollama_unreachable'];
    }

    $response = json_decode($raw, true);
    $text = trim((string) ($response['response'] ?? ''));
    if ($text === '' && !empty($response['thinking'])) {
        $text = trim((string) $response['thinking']);
    }
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $text ?? '');
    $data = json_decode((string) $text, true);
    if (!is_array($data)) {
        return ['approved' => false, 'answer' => trim((string) $text), 'confidence' => 0.0, 'source_numbers' => [], 'model' => $model, 'error' => 'invalid_json'];
    }

    $rawConfidence = $data['confidence'] ?? 0;
    if (is_string($rawConfidence)) {
        $confidence = match (strtolower(trim($rawConfidence))) {
            'high', 'alta', 'alto' => 0.90,
            'medium', 'média', 'medio', 'médio' => 0.75,
            'low', 'baixa', 'baixo' => 0.25,
            default => (float) $rawConfidence,
        };
    } else {
        $confidence = (float) $rawConfidence;
    }
    if ($confidence > 1 && $confidence <= 100) {
        $confidence /= 100;
    }
    $confidence = max(0.0, min(1.0, $confidence));
    $sourceNumbers = array_values(array_filter(array_map('intval', (array) ($data['source_numbers'] ?? [])), static fn (int $number): bool => $number >= 1 && $number <= count($sources)));
    $answer = trim((string) ($data['answer'] ?? ''));
    $grounded = filter_var($data['grounded'] ?? false, FILTER_VALIDATE_BOOLEAN);
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

    $stmt = db()->prepare("SELECT c.id, c.content, d.title, d.kind, MATCH(c.content) AGAINST(:q IN NATURAL LANGUAGE MODE) AS score
        FROM chunks c JOIN documents d ON d.id = c.document_id
        WHERE d.status = 'ready' AND (MATCH(c.content) AGAINST(:q2 IN NATURAL LANGUAGE MODE) > 0 OR c.content LIKE :like)
        ORDER BY score DESC, c.id DESC LIMIT 6");
    $stmt->execute([
        'q' => $question,
        'q2' => $question,
        'like' => '%' . mb_substr($question, 0, 100) . '%',
    ]);
    return $stmt->fetchAll();
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
    $parserVersion = 'rag-v1';
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
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . ' — ' . h($brandName) . '</title><style>body{font-family:system-ui,sans-serif;background:#f4f6f8;color:#17202a;margin:0}main{max-width:960px;margin:32px auto;padding:0 16px}.card{background:white;border-radius:12px;padding:22px;margin:16px 0;box-shadow:0 2px 12px #0001}textarea,input,select{width:100%;box-sizing:border-box;padding:11px;border:1px solid #ccd3da;border-radius:7px;margin:6px 0 12px}button{background:#155eef;color:#fff;border:0;padding:11px 16px;border-radius:7px;cursor:pointer}.muted{color:#667085;font-size:.92em}.answer{white-space:pre-wrap;line-height:1.55}.cite{border-left:3px solid #b7c8ff;padding:8px 12px;margin:8px 0;background:#f7f9ff}.reference{border-left:3px solid #f59e0b;padding:10px 12px;margin:10px 0;background:#fffbeb;white-space:pre-wrap}.response-source,.response-meta{color:#667085;font-size:.78em}.response-source{border-left:2px solid #b7c8ff;padding:5px 9px;margin:6px 0;background:#f7f9ff}.response-meta{margin:10px 0 0}.top{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}.brand{display:flex;gap:12px;align-items:center}.brand-logo{width:52px;height:52px;object-fit:contain;border-radius:8px;background:#fff}.brand-subtitle{color:#667085;font-size:.85em;margin-top:2px}a{color:#155eef}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}.badge{display:inline-block;padding:3px 8px;border-radius:999px;background:#eef2ff;color:#3446a8;font-size:.85em}.admin-shell{margin-top:16px}.admin-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}.admin-head h2{margin:4px 0}.admin-head p{margin:0}.admin-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.eyebrow{color:#667085;font-size:.75em;font-weight:700;letter-spacing:.08em}.admin-menu{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0 14px;padding:8px;background:#eef2f6;border-radius:10px}.admin-menu a{display:inline-flex;align-items:center;gap:7px;padding:10px 13px;border-radius:8px;text-decoration:none;color:#344054;font-weight:600}.admin-menu a:hover{background:#fff}.admin-menu a.active{background:#155eef;color:#fff}.nav-count{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;padding:0 6px;border-radius:999px;background:#d92d20;color:#fff;font-size:.78em}.admin-menu a.active .nav-count{background:#fff;color:#d92d20}.admin-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:14px 0}.admin-stat{background:#fff;border:1px solid #e4e7ec;border-radius:10px;padding:13px 15px}.admin-stat strong{display:block;font-size:1.45em;color:#101828}.urgent-alert{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;border:2px solid #d92d20;background:#fff1f0;color:#7a271a;border-radius:10px;padding:14px 16px;margin:14px 0}.urgent-alert strong{font-size:1.05em}.success-alert{border:1px solid #abefc6;background:#ecfdf3;color:#05603a;border-radius:10px;padding:12px 14px;margin:14px 0}.pending-card{border:2px solid #f04438;background:#fffafa;border-radius:10px;padding:15px;margin:14px 0}.pending-card .pending-meta{color:#7a271a;font-size:.9em;margin-bottom:8px}.pending-card textarea{min-height:100px}.section-intro{margin-top:-4px}.empty-state{padding:22px;text-align:center;border:1px dashed #cfd4dc;border-radius:10px;color:#667085}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:11px 9px;border-bottom:1px solid #eaecf0;vertical-align:top}th{font-size:.8em;color:#667085;text-transform:uppercase;letter-spacing:.04em}@media(max-width:640px){th:nth-child(3),td:nth-child(3){display:none}.admin-menu a{flex:1 1 45%}}</style></head><body><main><div class="top"><div class="brand">' . $logoHtml . '<div><h1>' . h($brandName) . '</h1>' . $subtitleHtml . '</div></div>' . $adminLink . '</div>' . $body . '</main></body></html>';
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
        header('Location: ?route=admin');
        exit;
    }
    audit_event('login_failure', 'admin', ['metadata' => ['email_hash' => hash('sha256', strtolower(trim((string) ($_POST['email'] ?? ''))))]]);
    $error = 'Credenciais inválidas.';
}

if ($route === 'login') {
    layout('Login', '<div class="card"><h2>Acesso administrativo</h2>' . (!empty($error) ? '<p>' . h($error) . '</p>' : '') . '<form method="post"><input type="hidden" name="csrf" value="' . csrf() . '">E-mail<input name="email" type="email" required>Senha<input name="password" type="password" required><button>Entrar</button></form></div>');
}

if ($route === 'admin' && !admin()) {
    header('Location: ?route=login');
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
    $validSections = ['overview', 'pending', 'knowledge', 'branding', 'settings'];
    if (!in_array($section, $validSections, true)) {
        $section = 'overview';
    }
    $documents = $pdo->query('SELECT title, kind, status, created_at FROM documents ORDER BY id DESC LIMIT 30')->fetchAll();
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
    $flashMessage = take_flash();
    $menuLink = static function (string $key, string $label, string $count = '') use ($section): string {
        $active = $section === $key ? ' active' : '';
        $badge = $count !== '' ? '<span class="nav-count">' . h($count) . '</span>' : '';
        return '<a class="' . $active . '" href="?route=admin&amp;section=' . h($key) . '">' . h($label) . $badge . '</a>';
    };
    $body = '<div class="admin-shell"><div class="admin-head"><div><div class="eyebrow">PAINEL ADMINISTRATIVO</div><h2>Centro de operação</h2><p class="muted">Gerencie a base RAG, a identidade pública e os atendimentos que precisam de decisão humana.</p></div><div class="admin-actions"><a href="?">Atendimento público</a><a href="?route=logout">Sair</a></div></div><nav class="admin-menu" aria-label="Menu administrativo">' . $menuLink('overview', 'Visão geral') . $menuLink('pending', 'Intervenção humana', $pendingCount > 0 ? (string) $pendingCount : '') . $menuLink('knowledge', 'Base de conhecimento') . $menuLink('branding', 'Identidade da empresa') . $menuLink('settings', 'Confiabilidade e Ollama') . '</nav>';
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
        $body .= '<div class="grid"><div class="card"><h3>Base de conhecimento</h3><p class="muted">Envie regimentos, atas, certificados e memórias validadas. Os arquivos são convertidos para Markdown RAG.</p><a href="?route=admin&amp;section=knowledge">Gerenciar documentos</a></div><div class="card"><h3>Identidade da empresa</h3><p class="muted">Personalize nome, descrição e logotipo apresentados aos moradores.</p><a href="?route=admin&amp;section=branding">Editar identidade</a></div><div class="card"><h3>Confiabilidade e Ollama</h3><p class="muted">Ajuste modelo, limiar de confiança, fontes mínimas e tempo limite.</p><a href="?route=admin&amp;section=settings">Revisar configurações</a></div></div>';
    } elseif ($section === 'pending') {
        $body .= '<div class="card"><div class="eyebrow">AÇÃO PRIORITÁRIA</div><h3>Intervenção humana necessária</h3><p class="section-intro muted">Responda cada pergunta abaixo para concluir o atendimento. A resposta aprovada será incorporada à memória validada do RAGLocal.</p>';
        if ($pendingCount > 0) {
            $body .= '<div class="urgent-alert"><strong>' . $pendingCount . ' ' . ($pendingCount === 1 ? 'atendimento pendente' : 'atendimentos pendentes') . '</strong><span>Ordenados do mais recente para o mais antigo.</span></div>';
            foreach ($pending as $item) {
                $confidence = $item['ai_confidence'] === null ? 'não calculada' : number_format((float) $item['ai_confidence'] * 100, 0, ',', '.') . '%';
                $questionTime = format_datetime_br((string) $item['question_created_at']);
                $waiting = waiting_time((string) $item['question_created_at']);
                $body .= '<form action="?route=answer" method="post" class="pending-card"><input type="hidden" name="csrf" value="' . csrf() . '"><input type="hidden" name="conversation_id" value="' . (int) $item['id'] . '"><div class="pending-meta"><b>PENDENTE</b> · Recebida em ' . h($questionTime) . ' · Esperando ' . h($waiting) . '</div><p><b>Pergunta do morador</b><br>' . h((string) $item['body']) . '</p><div class="reference"><b>Rascunho da IA para referência</b> <span class="badge">' . h($confidence) . ' · ' . h((string) ($item['ai_model'] ?: 'modelo desconhecido')) . '</span><br>' . h((string) ($item['ai_draft'] ?: 'O modelo não produziu uma resposta estruturada.')) . '</div><label>Resposta do atendente<textarea name="answer" placeholder="Escreva a resposta validada para este morador e para a memória do RAG..." required></textarea></label><button>Salvar resposta e ensinar a IA</button></form>';
            }
        } else {
            $body .= '<div class="empty-state">A fila está vazia. Quando uma pergunta não tiver evidência suficiente, ela aparecerá aqui com destaque.</div>';
        }
        $body .= '</div>';
    } elseif ($section === 'knowledge') {
        $body .= '<div class="card"><h3>Adicionar à base de conhecimento</h3><p class="muted">Envie PDF, TXT ou MD. O arquivo é convertido para Markdown canônico e indexado para recuperação.</p><form action="?route=upload" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="' . csrf() . '"><label>Título<input name="title" required></label><label>Tipo<select name="kind"><option value="regimento">Regimento interno</option><option value="ata">Ata</option><option value="memoria">Memória validada</option><option value="manutencao">Manutenção / certificado técnico</option></select></label><label>Arquivo PDF, TXT ou MD<input type="file" name="document" accept=".pdf,.txt,.md" required></label><button>Enviar e indexar</button></form></div><div class="card"><h3>Documentos indexados</h3><p class="muted">Os documentos abaixo estão disponíveis como evidência para o RAG.</p><table><thead><tr><th>Documento</th><th>Tipo</th><th>Status</th><th>Adicionado em</th></tr></thead><tbody>';
        foreach ($documents as $document) {
            $body .= '<tr><td>' . h((string) $document['title']) . '</td><td>' . h(document_kind_label((string) $document['kind'])) . '</td><td><span class="badge">' . h((string) $document['status']) . '</span></td><td>' . h(format_datetime_br((string) $document['created_at'])) . '</td></tr>';
        }
        if (!$documents) {
            $body .= '<tr><td colspan="4" class="muted">Nenhum documento indexado.</td></tr>';
        }
        $body .= '</tbody></table></div>';
    } elseif ($section === 'branding') {
        $body .= '<div class="card"><h3>Identidade da empresa</h3><p class="muted">Esses dados aparecem no cabeçalho da página pública e do painel.</p><form action="?route=settings" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="' . csrf() . '"><input type="hidden" name="chat_model" value="' . h($selectedModel) . '"><input type="hidden" name="min_confidence" value="' . h(number_format($minConfidence, 2, '.', '')) . '"><input type="hidden" name="min_sources" value="' . h((string) $minSources) . '"><input type="hidden" name="timeout" value="' . h((string) $timeout) . '"><label>Nome exibido<input name="brand_name" maxlength="120" value="' . h($currentBrandName) . '" required></label><label>Descrição curta<input name="brand_subtitle" maxlength="240" value="' . h($currentBrandSubtitle) . '"></label><label>Logotipo<input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif"></label><span class="muted">PNG, JPG, WEBP ou GIF, até 2 MB. ' . ($currentLogo !== '' ? 'Logotipo atual: ' . h($currentLogo) . '.' : 'Nenhum logotipo configurado.') . '</span><label><input type="checkbox" name="remove_logo" value="1"> Remover logotipo atual</label><br><button>Salvar identidade</button></form></div>';
    } elseif ($section === 'settings') {
        $body .= '<div class="card"><h3>Confiabilidade e Ollama</h3><p class="muted">Endpoint: ' . h(envv('OLLAMA_URL', 'não configurado')) . '. A resposta só é publicada quando há evidência suficiente, fontes válidas e confiança acima do limiar.</p><form action="?route=settings" method="post"><input type="hidden" name="csrf" value="' . csrf() . '"><label>Modelo de chat<select name="chat_model">';
        foreach ($models as $model => $description) {
            $body .= '<option value="' . h($model) . '"' . ($model === $selectedModel ? ' selected' : '') . '>' . h($description) . '</option>';
        }
        $body .= '</select></label><label>Limiar mínimo de confiança (0,50 a 0,99)<input name="min_confidence" type="number" min="0.50" max="0.99" step="0.01" value="' . h(number_format($minConfidence, 2, '.', '')) . '"></label><label>Fontes mínimas citadas<input name="min_sources" type="number" min="1" max="3" step="1" value="' . h((string) $minSources) . '"></label><label>Tempo máximo de consulta (segundos)<input name="timeout" type="number" min="20" max="180" step="5" value="' . h((string) $timeout) . '"></label><button>Salvar configurações</button></form><p class="muted">Para hardware limitado, comece com <b>qwen3:4b</b>, já instalado, e limiar 0,75. Modelos de 1B são alternativas mais leves, mas precisam ser instalados no servidor Ollama antes do uso.</p></div>';
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
        $publicAnswer = 'Não encontrei base suficiente no regimento interno, nas atas ou nas respostas validadas. Sua dúvida foi encaminhada a um atendente humano.';
        $status = 'human_pending';
    }
    $pdo->prepare('UPDATE conversations SET status = ?, ai_draft = ?, ai_confidence = ?, ai_model = ? WHERE id = ?')->execute([$status, $reference !== '' ? $reference : null, $result['confidence'], $result['model'], $conversationId]);
    $citations = [];
    foreach ($result['source_numbers'] as $number) {
        $source = $sources[$number - 1] ?? null;
        if ($source) {
            $citations[] = ['title' => $source['title'], 'kind' => $source['kind']];
        }
    }
    $pdo->prepare('INSERT INTO messages(conversation_id, sender, body, citations) VALUES(?, \'ai\', ?, ?)')->execute([$conversationId, $publicAnswer, json_encode($citations, JSON_UNESCAPED_UNICODE)]);
    $aiMessageId = (int) $pdo->lastInsertId();
    audit_event('question', 'resident', ['conversation_id' => $conversationId, 'message_id' => $residentMessageId, 'question' => $question, 'metadata' => ['source_count' => count($sources)]]);
    audit_event('ai_answer', 'ai', ['conversation_id' => $conversationId, 'message_id' => $aiMessageId, 'question' => $question, 'answer' => $publicAnswer, 'ai_draft' => $reference !== '' ? $reference : null, 'ai_confidence' => $result['confidence'], 'ai_model' => $result['model'], 'citations' => $citations, 'response_time_ms' => $responseTimeMs, 'metadata' => ['approved' => (bool) $result['approved'], 'status' => $status, 'ollama_error' => $result['error'], 'source_count' => count($sources), 'response_time_ms' => $responseTimeMs]]);
    $body = '<div class="card"><h2>Resposta</h2><div class="answer">' . h($publicAnswer) . '</div>';
    foreach ($citations as $citation) {
        $body .= '<div class="response-source"><b>Fonte:</b> ' . h((string) $citation['title']) . '</div>';
    }
    $body .= '<div class="response-meta">Tempo para localizar e processar a resposta: ' . h(format_response_time($responseTimeMs)) . '</div>';
    if ($status === 'human_pending') {
        $body .= '<p class="muted">A pergunta e a resposta calculada pelo modelo foram registradas para análise administrativa.</p>';
    }
    $body .= '</div><div class="card"><a href="?">Fazer outra pergunta</a></div>';
    layout('Resposta', $body);
}

layout('Atendimento', '<div class="card"><p>Consulte o regimento interno e as atas do condomínio. A IA responde somente quando encontra evidência suficiente na base; caso contrário, encaminha a pergunta para atendimento humano.</p><form method="post"><input type="hidden" name="csrf" value="' . csrf() . '">Pergunta<textarea name="question" rows="5" placeholder="Digite sua dúvida..." required></textarea><button>Consultar</button></form></div><p class="muted"><a href="?route=login">Acesso administrativo</a></p>');
