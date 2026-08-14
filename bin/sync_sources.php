<?php
declare(strict_types=1);

$root = dirname(__DIR__);

function load_env_file(string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException('Arquivo .env não encontrado.');
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($value !== '' && (($value[0] ?? '') === '"' || ($value[0] ?? '') === "'")) {
            $value = trim($value, "\"'");
        }
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}

function env_value(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : (string) $value;
}

function app_db(): PDO
{
    $host = env_value('DB_HOST', '127.0.0.1');
    $port = env_value('DB_PORT', '3306');
    $name = env_value('DB_NAME');
    $user = env_value('DB_USER');
    $pass = env_value('DB_PASS');
    if ($name === '' || $user === '') {
        throw new RuntimeException('DB_NAME e DB_USER são obrigatórios.');
    }
    return new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4', $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

load_env_file($root . '/config/.env');
require_once $root . '/src/SecretBox.php';
require_once $root . '/src/SourceRegistry.php';
require_once $root . '/src/DatabaseTablePlugin.php';

$lockPath = env_value('RAG_SOURCE_SYNC_LOCK', $root . '/storage/source-sync.lock');
$lockDir = dirname($lockPath);
if (!is_dir($lockDir) && !mkdir($lockDir, 0770, true) && !is_dir($lockDir)) {
    throw new RuntimeException('Não foi possível criar o diretório do bloqueio.');
}
$lock = fopen($lockPath, 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Outra sincronização de fontes já está em execução.\n");
    exit(0);
}

try {
    $pdo = app_db();
    $sources = SourceRegistry::all($pdo, true);
    $totals = ['sources' => 0, 'completed' => 0, 'errors' => 0, 'imported' => 0, 'updated' => 0, 'unchanged' => 0, 'withdrawn' => 0];
    foreach ($sources as $source) {
        $totals['sources']++;
        $sourceId = (int) $source['id'];
        try {
            $definition = SourceRegistry::plugins()[(string) $source['plugin_key']] ?? null;
            if (!is_array($definition) || empty($definition['syncable'])) {
                throw new RuntimeException('Plugin sem sincronização agendada: ' . (string) $source['plugin_key']);
            }
            $summary = SourceRegistry::executor($pdo, $source)->sync('cron');
            $totals['completed']++;
            $totals['imported'] += (int) $summary['imported_count'];
            $totals['updated'] += (int) $summary['updated_count'];
            $totals['unchanged'] += (int) $summary['unchanged_count'];
            $totals['withdrawn'] += (int) $summary['withdrawn_count'];
            fwrite(STDOUT, sprintf("source_id=%d status=%s read=%d imported=%d updated=%d unchanged=%d withdrawn=%d errors=%d\n", $sourceId, $summary['status'], $summary['read_count'], $summary['imported_count'], $summary['updated_count'], $summary['unchanged_count'], $summary['withdrawn_count'], $summary['error_count']));
        } catch (Throwable $error) {
            $totals['errors']++;
            fwrite(STDERR, 'source_id=' . $sourceId . ' status=error message=' . preg_replace('/[\r\n]+/', ' ', mb_substr($error->getMessage(), 0, 300, 'UTF-8')) . "\n");
        }
    }
    fwrite(STDOUT, 'summary=' . json_encode($totals, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    exit($totals['errors'] > 0 ? 1 : 0);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
