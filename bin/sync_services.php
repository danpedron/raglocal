<?php
declare(strict_types=1);

function load_env_file(string $file): void
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

function env_value(string $key, string $default = ''): string
{
    return (string) ($_ENV[$key] ?? getenv($key) ?? $default);
}

load_env_file(dirname(__DIR__) . '/config/.env');
require_once dirname(__DIR__) . '/src/ServiceConnector.php';

date_default_timezone_set(env_value('APP_TIMEZONE', 'America/Sao_Paulo'));

$dsn = 'mysql:host=' . env_value('DB_HOST', '127.0.0.1') . ';port=' . env_value('DB_PORT', '3306') . ';dbname=' . env_value('DB_NAME') . ';charset=utf8mb4';
$app = new PDO($dsn, env_value('DB_USER'), env_value('DB_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$input = $argv[1] ?? env_value('SERVICES_SOURCE_FILE');
if (!is_string($input) || trim($input) === '' || !is_file($input) || !is_readable($input)) {
    fwrite(STDERR, "services-sync-error: informe um arquivo TXT/MD legível como argumento ou SERVICES_SOURCE_FILE no .env\n");
    exit(2);
}

$sourceUrl = env_value('SERVICES_SOURCE_URL', 'https://example.invalid/servicos');
$deactivateMissing = env_value('SERVICES_DEACTIVATE_MISSING', '0') === '1';
$summary = null;
try {
    $text = file_get_contents($input);
    if (!is_string($text) || trim($text) === '') {
        throw new RuntimeException('O arquivo da Carta de Serviços está vazio.');
    }
    $summary = (new ServiceConnector($app, $sourceUrl))->importText($text, 'cron', $deactivateMissing);
    $audit = $app->prepare("INSERT INTO audit_logs(event_type, actor, metadata) VALUES('services_sync', 'system', ?)");
    $audit->execute([json_encode(['trigger' => 'cron', 'source_file' => basename($input), 'status' => $summary['status'], 'source_url' => $summary['source_url'], 'read_count' => $summary['read_count'], 'imported_count' => $summary['imported_count'], 'updated_count' => $summary['updated_count'], 'unchanged_count' => $summary['unchanged_count'], 'withdrawn_count' => $summary['withdrawn_count'], 'error_count' => $summary['error_count']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($summary['status'] === 'completed' ? 0 : 1);
} catch (Throwable $error) {
    $message = mb_substr($error->getMessage(), 0, 1000, 'UTF-8');
    try {
        $audit = $app->prepare("INSERT INTO audit_logs(event_type, actor, metadata) VALUES('services_sync', 'system', ?)");
        $audit->execute([json_encode(['trigger' => 'cron', 'source_file' => is_string($input) ? basename($input) : null, 'status' => 'error', 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    } catch (Throwable $auditError) {
        error_log('RAGLocal services sync audit failure: ' . $auditError->getMessage());
    }
    fwrite(STDERR, 'services-sync-error: ' . $message . PHP_EOL);
    exit(1);
}

