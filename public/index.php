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
        error_log('Jaraguá Tower IA audit failure: ' . $error->getMessage());
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
    $prompt = "Você é o assistente oficial do Condomínio Jaraguá Tower.\n\n" .
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
    $parts = [$title, document_kind_label($kind), 'condominio', 'jaragua tower', $heading];
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
    $parserVersion = 'jaragua-rag-v1';
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
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . ' — Jaraguá Tower IA</title><style>body{font-family:system-ui,sans-serif;background:#f4f6f8;color:#17202a;margin:0}main{max-width:960px;margin:32px auto;padding:0 16px}.card{background:white;border-radius:12px;padding:22px;margin:16px 0;box-shadow:0 2px 12px #0001}textarea,input,select{width:100%;box-sizing:border-box;padding:11px;border:1px solid #ccd3da;border-radius:7px;margin:6px 0 12px}button{background:#155eef;color:#fff;border:0;padding:11px 16px;border-radius:7px;cursor:pointer}.muted{color:#667085;font-size:.92em}.answer{white-space:pre-wrap;line-height:1.55}.cite{border-left:3px solid #b7c8ff;padding:8px 12px;margin:8px 0;background:#f7f9ff}.reference{border-left:3px solid #f59e0b;padding:10px 12px;margin:10px 0;background:#fffbeb;white-space:pre-wrap}.response-source,.response-meta{color:#667085;font-size:.78em}.response-source{border-left:2px solid #b7c8ff;padding:5px 9px;margin:6px 0;background:#f7f9ff}.response-meta{margin:10px 0 0}.top{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}a{color:#155eef}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}.badge{display:inline-block;padding:3px 8px;border-radius:999px;background:#eef2ff;color:#3446a8;font-size:.85em}</style></head><body><main><div class="top"><h1>Jaraguá Tower IA</h1>' . ($logged ? '<a href="?route=admin">Administração</a>' : '') . '</div>' . $body . '</main></body></html>';
    exit;
}

$route = $_GET['route'] ?? 'chat';

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
    save_setting('ollama_chat_model', $model);
    save_setting('rag_min_confidence', number_format($confidence, 2, '.', ''));
    save_setting('rag_min_sources', (string) $sources);
    save_setting('ollama_timeout', (string) $timeout);
    flash('Configurações salvas.');
    header('Location: ?route=admin');
    exit;
}

if ($route === 'upload' && admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $file = $_FILES['document'] ?? null;
    $maximumBytes = 10 * 1024 * 1024;
    $storedOriginalPath = null;
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
                $originalFilename = 'document-' . $documentId . '.source.' . $extension;
                $markdownFilename = 'document-' . $documentId . '.rag.md';
                $storedOriginalPath = $storageDir . '/' . $originalFilename;
                $storedMarkdownPath = $storageDir . '/' . $markdownFilename;
                if (!move_uploaded_file($file['tmp_name'], $storedOriginalPath)) {
                    throw new RuntimeException('Falha ao armazenar o arquivo original.');
                }
                $markdownBytes = file_put_contents($storedMarkdownPath, $rag['markdown'], LOCK_EX);
                if ($markdownBytes === false || $markdownBytes !== strlen($rag['markdown'])) {
                    throw new RuntimeException('Falha ao armazenar o Markdown RAG.');
                }
                $artifactStmt = $pdo->prepare('INSERT INTO document_artifacts(document_id, artifact_type, filename, storage_path, mime_type, byte_size, sha256, content) VALUES(?, ?, ?, ?, ?, ?, ?, ?)');
                $artifactStmt->execute([$documentId, 'original', (string) $file['name'], 'storage/uploads/' . $originalFilename, artifact_mime($storedOriginalPath, 'application/octet-stream'), (int) filesize($storedOriginalPath), $sourceSha256, null]);
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
                if ($storedOriginalPath !== null) {
                    @unlink($storedOriginalPath);
                }
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
    $documents = $pdo->query('SELECT title, kind, status, created_at FROM documents ORDER BY id DESC LIMIT 30')->fetchAll();
    $pending = $pdo->query("SELECT c.id, c.ai_draft, c.ai_confidence, c.ai_model, m.body, m.created_at AS question_created_at FROM conversations c JOIN messages m ON m.conversation_id = c.id WHERE c.status = 'human_pending' AND m.sender = 'resident' AND m.id = (SELECT MAX(m2.id) FROM messages m2 WHERE m2.conversation_id = c.id AND m2.sender = 'resident') ORDER BY m.created_at DESC, m.id DESC")->fetchAll();
    $models = ollama_models();
    $selectedModel = setting('ollama_chat_model', envv('OLLAMA_CHAT_MODEL', 'qwen3:4b'));
    $minConfidence = rag_min_confidence();
    $minSources = rag_min_sources();
    $timeout = ollama_timeout();
    $body = '<div class="card"><p><a href="?">Voltar ao atendimento</a> · <a href="?route=logout">Sair</a></p><h2>Base de conhecimento</h2>' . (!empty($flash = take_flash()) ? '<p>' . h($flash) . '</p>' : '') . '<form action="?route=upload" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="' . csrf() . '">Título<input name="title" required>Tipo<select name="kind"><option value="regimento">Regimento interno</option><option value="ata">Ata</option><option value="memoria">Memória validada</option><option value="manutencao">Manutenção / certificado técnico</option></select>Arquivo PDF, TXT ou MD<input type="file" name="document" accept=".pdf,.txt,.md" required><button>Enviar e indexar</button></form></div>';
    $body .= '<div class="card"><h2>Confiabilidade e Ollama</h2><p class="muted">Endpoint: ' . h(envv('OLLAMA_URL', 'não configurado')) . '. A resposta só é publicada quando o modelo indica evidência suficiente, cita fontes válidas e supera o limiar abaixo. Caso contrário, o morador vê apenas o encaminhamento humano.</p><form action="?route=settings" method="post"><input type="hidden" name="csrf" value="' . csrf() . '">Modelo de chat<select name="chat_model">';
    foreach ($models as $model => $description) {
        $body .= '<option value="' . h($model) . '"' . ($model === $selectedModel ? ' selected' : '') . '>' . h($description) . '</option>';
    }
    $body .= '</select>Limiar mínimo de confiança (0,50 a 0,99)<input name="min_confidence" type="number" min="0.50" max="0.99" step="0.01" value="' . h(number_format($minConfidence, 2, '.', '')) . '">Fontes mínimas citadas<input name="min_sources" type="number" min="1" max="3" step="1" value="' . h((string) $minSources) . '">Tempo máximo de consulta (segundos)<input name="timeout" type="number" min="20" max="180" step="5" value="' . h((string) $timeout) . '"><button>Salvar configurações</button></form><p class="muted">Para hardware limitado, comece com <b>qwen3:4b</b>, já instalado, e limiar 0,75. Modelos de 1B são alternativas mais leves, mas precisam ser instalados no servidor Ollama antes do uso.</p></div>';
    $body .= '<div class="card"><h2>Atendimentos pendentes</h2>';
    foreach ($pending as $item) {
        $confidence = $item['ai_confidence'] === null ? 'não calculada' : number_format((float) $item['ai_confidence'] * 100, 0, ',', '.') . '%';
        $questionTime = format_datetime_br((string) $item['question_created_at']);
        $waiting = waiting_time((string) $item['question_created_at']);
        $body .= '<form action="?route=answer" method="post" class="cite"><input type="hidden" name="csrf" value="' . csrf() . '"><input type="hidden" name="conversation_id" value="' . (int) $item['id'] . '"><div class="muted"><b>Recebida em:</b> ' . h($questionTime) . ' · <b>Tempo de espera:</b> ' . h($waiting) . '</div><b>Pergunta:</b> ' . h((string) $item['body']) . '<div class="reference"><b>Resposta calculada pelo modelo para referência</b> <span class="badge">' . h($confidence) . ' · ' . h((string) ($item['ai_model'] ?: 'modelo desconhecido')) . '</span>\n' . h((string) ($item['ai_draft'] ?: 'O modelo não produziu uma resposta estruturada.')) . '</div><textarea name="answer" placeholder="Resposta do atendente" required></textarea><button>Salvar resposta e ensinar a IA</button></form>';
    }
    if (!$pending) {
        $body .= '<p class="muted">Nenhuma pergunta pendente.</p>';
    }
    $body .= '</div><div class="card"><h2>Documentos</h2><ul>';
    foreach ($documents as $document) {
        $body .= '<li>' . h((string) $document['title']) . ' — ' . h(document_kind_label((string) $document['kind'])) . ' — ' . h((string) $document['status']) . '</li>';
    }
    $body .= '</ul></div>';
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
