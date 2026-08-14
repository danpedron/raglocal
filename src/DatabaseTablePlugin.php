<?php
declare(strict_types=1);

/**
 * Plugin genérico para importar uma tabela de banco de dados somente leitura.
 * A configuração define os campos de chave, título, conteúdo, filtros, datas e URL.
 */
final class DatabaseTablePlugin
{
    private const PARSER_VERSION = 'database-table-rag-v1';

    private PDO $app;
    private array $source;
    private array $config;
    private ?PDO $external = null;

    public function __construct(PDO $app, array $source)
    {
        $this->app = $app;
        $this->source = $source;
        $decoded = json_decode((string) ($source['config_json'] ?? '{}'), true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        $this->config = $this->normalizeConfig($decoded);
    }

    public function config(): array
    {
        return $this->config;
    }

    public function isConfigured(): bool
    {
        return $this->config['host'] !== ''
            && $this->config['database'] !== ''
            && $this->config['user'] !== ''
            && $this->validIdentifier($this->config['table'])
            && $this->validIdentifier($this->config['key_column'])
            && $this->validIdentifier($this->config['title_column'])
            && count($this->config['content_columns']) > 0;
    }

    public function sync(string $trigger = 'manual'): array
    {
        if (!(int) ($this->source['enabled'] ?? 0)) {
            throw new RuntimeException('Esta fonte está desativada. Ative-a antes de sincronizar.');
        }
        if (!$this->isConfigured()) {
            throw new RuntimeException('Configure servidor, banco, usuário, tabela, chave, título e pelo menos um campo de conteúdo.');
        }

        $started = microtime(true);
        $runId = 0;
        $lockAcquired = false;
        $summary = [
            'trigger' => $trigger,
            'status' => 'running',
            'read_count' => 0,
            'imported_count' => 0,
            'updated_count' => 0,
            'unchanged_count' => 0,
            'withdrawn_count' => 0,
            'error_count' => 0,
            'error_message' => null,
        ];

        try {
            $insertRun = $this->app->prepare("INSERT INTO source_sync_runs(source_id, trigger_type, status) VALUES(?, ?, 'running')");
            $insertRun->execute([(int) $this->source['id'], $trigger]);
            $runId = (int) $this->app->lastInsertId();

            $lock = $this->app->prepare('SELECT GET_LOCK(?, 5)');
            $lock->execute(['raglocal_source_' . (int) $this->source['id']]);
            $lockAcquired = (int) $lock->fetchColumn() === 1;
            if (!$lockAcquired) {
                throw new RuntimeException('Já existe outra sincronização desta fonte em andamento.');
            }

            $rows = $this->fetchRows();
            $summary['read_count'] = count($rows);
            $seen = [];
            foreach ($rows as $row) {
                $itemKey = (string) $row[$this->config['key_column']];
                $seen[$itemKey] = true;
                try {
                    $result = $this->importRow($row, $itemKey);
                    $summary[$result . '_count']++;
                } catch (Throwable $error) {
                    $summary['error_count']++;
                    error_log('RAGLocal source import error for source ' . (int) $this->source['id'] . ' item ' . $itemKey . ': ' . $error->getMessage());
                }
            }
            if ($this->config['withdraw_missing']) {
                $summary['withdrawn_count'] = $this->withdrawMissing($seen);
            }
            $summary['status'] = $summary['error_count'] > 0 ? 'completed_with_errors' : 'completed';
            $this->finishRun($runId, $summary, $started);
            $this->app->prepare('UPDATE knowledge_sources SET last_sync_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int) $this->source['id']]);
            return $summary;
        } catch (Throwable $error) {
            $summary['status'] = 'error';
            $summary['error_message'] = mb_substr($error->getMessage(), 0, 1000, 'UTF-8');
            if ($runId > 0) {
                $this->finishRun($runId, $summary, $started);
            }
            throw $error;
        } finally {
            if ($lockAcquired) {
                try {
                    $this->app->query("SELECT RELEASE_LOCK('raglocal_source_" . (int) $this->source['id'] . "')");
                } catch (Throwable $ignored) {
                    error_log('RAGLocal source unlock failure: ' . $ignored->getMessage());
                }
            }
        }
    }

    private function normalizeConfig(array $config): array
    {
        $content = $config['content_columns'] ?? [];
        if (is_string($content)) {
            $content = preg_split('/[,\s]+/', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($content)) {
            $content = [];
        }
        $content = array_values(array_filter(array_map(static fn ($value): string => trim((string) $value), $content), fn (string $value): bool => $value !== ''));
        return [
            'host' => trim((string) ($config['host'] ?? '')),
            'port' => max(1, min(65535, (int) ($config['port'] ?? 3306))),
            'database' => trim((string) ($config['database'] ?? '')),
            'user' => trim((string) ($config['user'] ?? '')),
            'password_enc' => (string) ($config['password_enc'] ?? ''),
            'table' => trim((string) ($config['table'] ?? '')),
            'key_column' => trim((string) ($config['key_column'] ?? 'id')),
            'title_column' => trim((string) ($config['title_column'] ?? 'title')),
            'content_columns' => $content,
            'filter_column' => trim((string) ($config['filter_column'] ?? '')),
            'filter_value' => (string) ($config['filter_value'] ?? ''),
            'status_column' => trim((string) ($config['status_column'] ?? '')),
            'status_value' => (string) ($config['status_value'] ?? 'publish'),
            'published_column' => trim((string) ($config['published_column'] ?? '')),
            'modified_column' => trim((string) ($config['modified_column'] ?? '')),
            'url_column' => trim((string) ($config['url_column'] ?? '')),
            'public_url_template' => trim((string) ($config['public_url_template'] ?? '')),
            'withdraw_missing' => !empty($config['withdraw_missing']),
        ];
    }

    private function externalPdo(): PDO
    {
        if ($this->external instanceof PDO) {
            return $this->external;
        }
        $password = SecretBox::decrypt($this->config['password_enc'], (string) ($_ENV['APP_SECRET'] ?? getenv('APP_SECRET') ?: ''));
        $dsn = 'mysql:host=' . $this->config['host'] . ';port=' . $this->config['port'] . ';dbname=' . $this->config['database'] . ';charset=utf8mb4';
        $this->external = new PDO($dsn, $this->config['user'], $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 15,
        ]);
        return $this->external;
    }

    private function fetchRows(): array
    {
        $columns = [$this->config['key_column'], $this->config['title_column']];
        foreach ($this->config['content_columns'] as $column) {
            $columns[] = $column;
        }
        foreach (['filter_column', 'status_column', 'published_column', 'modified_column', 'url_column'] as $optional) {
            if ($this->config[$optional] !== '') {
                $columns[] = $this->config[$optional];
            }
        }
        $columns = array_values(array_unique($columns));
        foreach ($columns as $column) {
            if (!$this->validIdentifier($column)) {
                throw new RuntimeException('Campo inválido na configuração: ' . $column);
            }
        }
        $quotedColumns = implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
        $sql = 'SELECT ' . $quotedColumns . ' FROM ' . $this->quoteIdentifier($this->config['table']);
        $where = [];
        $params = [];
        if ($this->config['filter_column'] !== '' && $this->config['filter_value'] !== '') {
            $where[] = $this->quoteIdentifier($this->config['filter_column']) . ' = :filter_value';
            $params['filter_value'] = $this->config['filter_value'];
        }
        if ($this->config['status_column'] !== '' && $this->config['status_value'] !== '') {
            $where[] = $this->quoteIdentifier($this->config['status_column']) . ' = :status_value';
            $params['status_value'] = $this->config['status_value'];
        }
        if ($this->config['published_column'] !== '') {
            $where[] = '(' . $this->quoteIdentifier($this->config['published_column']) . ' IS NULL OR ' . $this->quoteIdentifier($this->config['published_column']) . ' = \'0000-00-00 00:00:00\' OR ' . $this->quoteIdentifier($this->config['published_column']) . ' <= NOW())';
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY ' . $this->quoteIdentifier($this->config['key_column']) . ' ASC';
        $stmt = $this->externalPdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importRow(array $row, string $itemKey): string
    {
        $title = $this->cleanText((string) ($row[$this->config['title_column']] ?? ''));
        $title = $title !== '' ? $title : 'Item ' . $itemKey;
        $publicUrl = $this->publicUrl($row, $itemKey);
        $publishedAt = $this->dateValue($row[$this->config['published_column']] ?? null);
        $modifiedAt = $this->dateValue($row[$this->config['modified_column']] ?? null);
        $parts = [];
        foreach ($this->config['content_columns'] as $column) {
            $value = $this->cleanText((string) ($row[$column] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        $body = implode("\n\n", $parts);
        if ($body === '') {
            throw new RuntimeException('O item não possui conteúdo textual.');
        }
        $markdown = $this->buildMarkdown($title, $body, $itemKey, $publishedAt, $modifiedAt, $publicUrl);
        $sourceHash = hash('sha256', $body . '|' . $title . '|' . $publicUrl . '|' . ($publishedAt ?? '') . '|' . ($modifiedAt ?? ''));
        $existingStmt = $this->app->prepare('SELECT document_id, source_content_sha256, is_active FROM document_source_links WHERE source_id = ? AND source_item_key = ? LIMIT 1');
        $existingStmt->execute([(int) $this->source['id'], $itemKey]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existing && (string) $existing['source_content_sha256'] === $sourceHash && (int) $existing['is_active'] === 1) {
            return 'unchanged';
        }

        $documentId = 0;
        $storedPath = null;
        $this->app->beginTransaction();
        try {
            if ($existing) {
                $documentId = (int) $existing['document_id'];
                $this->app->prepare("UPDATE documents SET title = ?, kind = 'externa', source_filename = ?, status = 'processing', parser_version = ?, canonical_sha256 = ?, processed_at = NULL WHERE id = ?")
                    ->execute([$title, 'source://' . (int) $this->source['id'] . '/' . $itemKey, self::PARSER_VERSION, $markdown['canonical_sha256'], $documentId]);
                $this->app->prepare('DELETE FROM chunks WHERE document_id = ?')->execute([$documentId]);
                $this->app->prepare('DELETE FROM document_artifacts WHERE document_id = ?')->execute([$documentId]);
                $result = 'updated';
            } else {
                $this->app->prepare("INSERT INTO documents(title, kind, source_filename, status, parser_version, canonical_sha256, created_by) VALUES(?, 'externa', ?, 'processing', ?, ?, NULL)")
                    ->execute([$title, 'source://' . (int) $this->source['id'] . '/' . $itemKey, self::PARSER_VERSION, $markdown['canonical_sha256']]);
                $documentId = (int) $this->app->lastInsertId();
                $result = 'imported';
            }
            $sourceDir = rtrim($this->storageDir(), '/') . '/sources/' . (int) $this->source['id'];
            if (!is_dir($sourceDir) && !mkdir($sourceDir, 0770, true) && !is_dir($sourceDir)) {
                throw new RuntimeException('Não foi possível criar o armazenamento desta fonte.');
            }
            $filename = 'item-' . hash('sha256', $itemKey) . '.rag.md';
            $storedPath = $sourceDir . '/' . $filename;
            $bytes = file_put_contents($storedPath, $markdown['content'], LOCK_EX);
            if ($bytes === false || $bytes !== strlen($markdown['content'])) {
                throw new RuntimeException('Não foi possível armazenar o Markdown da fonte.');
            }
            @chmod($storedPath, 0660);
            $this->app->prepare('INSERT INTO document_artifacts(document_id, artifact_type, filename, storage_path, mime_type, byte_size, sha256, content) VALUES(?, \'markdown\', ?, ?, ?, ?, ?, ?)')
                ->execute([$documentId, $filename, 'storage/uploads/sources/' . (int) $this->source['id'] . '/' . $filename, 'text/markdown; charset=UTF-8', $bytes, $markdown['canonical_sha256'], $markdown['content']]);
            $insertChunk = $this->app->prepare('INSERT INTO chunks(document_id, chunk_no, content, section_heading, tags, token_count) VALUES(?, ?, ?, ?, ?, ?)');
            foreach ($markdown['chunks'] as $number => $chunk) {
                $insertChunk->execute([$documentId, $number + 1, $chunk['content'], $chunk['heading'], $chunk['tags'], $chunk['token_count']]);
            }
            $this->app->prepare('INSERT INTO document_source_links(source_id, document_id, source_item_key, public_url, source_title, source_content_sha256, is_active, last_sync_at, withdrawn_at, withdrawal_reason) VALUES(?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP, NULL, NULL) ON DUPLICATE KEY UPDATE document_id = VALUES(document_id), public_url = VALUES(public_url), source_title = VALUES(source_title), source_content_sha256 = VALUES(source_content_sha256), is_active = 1, last_sync_at = CURRENT_TIMESTAMP, withdrawn_at = NULL, withdrawal_reason = NULL')
                ->execute([(int) $this->source['id'], $documentId, $itemKey, $publicUrl !== '' ? $publicUrl : null, $title, $sourceHash]);
            $this->app->prepare("UPDATE documents SET status = 'ready', processed_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$documentId]);
            $this->app->commit();
        } catch (Throwable $error) {
            if ($this->app->inTransaction()) {
                $this->app->rollBack();
            }
            if ($storedPath !== null) {
                @unlink($storedPath);
            }
            throw $error;
        }
        return $result;
    }

    private function withdrawMissing(array $seen): int
    {
        $stmt = $this->app->prepare('SELECT document_id, source_item_key FROM document_source_links WHERE source_id = ? AND is_active = 1');
        $stmt->execute([(int) $this->source['id']]);
        $count = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($seen[(string) $row['source_item_key']])) {
                continue;
            }
            $this->app->beginTransaction();
            try {
                $this->app->prepare("UPDATE document_source_links SET is_active = 0, withdrawn_at = CURRENT_TIMESTAMP, withdrawal_reason = 'not_present_in_source' WHERE source_id = ? AND source_item_key = ?")
                    ->execute([(int) $this->source['id'], (string) $row['source_item_key']]);
                $this->app->prepare("UPDATE documents d SET d.status = CASE WHEN EXISTS (SELECT 1 FROM document_source_links other WHERE other.document_id = d.id AND other.source_id <> ? AND other.is_active = 1) THEN 'ready' ELSE 'disabled' END, d.processed_at = CURRENT_TIMESTAMP WHERE d.id = ?")->execute([(int) $this->source['id'], (int) $row['document_id']]);
                $this->app->commit();
                $count++;
            } catch (Throwable $error) {
                if ($this->app->inTransaction()) {
                    $this->app->rollBack();
                }
                throw $error;
            }
        }
        return $count;
    }

    private function buildMarkdown(string $title, string $body, string $itemKey, ?string $publishedAt, ?string $modifiedAt, string $publicUrl): array
    {
        $lines = [
            '---',
            'rag_format: rag-v1',
            'language: pt-BR',
            'title: ' . json_encode($title, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'kind: externa',
            'source_plugin: database_table',
            'source_id: ' . (int) $this->source['id'],
            'source_item_key: ' . json_encode($itemKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'public_url: ' . json_encode($publicUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'published_at: ' . json_encode($publishedAt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'modified_at: ' . json_encode($modifiedAt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'canonical_sha256: PENDING',
            '---',
            '',
            '# [FONTE EXTERNA] ' . $title,
            '',
            '[TAGS] fonte externa ' . $this->slugWords((string) ($this->source['name'] ?? '')),
            '',
        ];
        if ($publishedAt !== null) {
            $lines[] = '**Publicado em:** ' . $publishedAt;
        }
        if ($modifiedAt !== null) {
            $lines[] = '**Atualizado em:** ' . $modifiedAt;
        }
        if ($publicUrl !== '') {
            $lines[] = '**Fonte pública:** ' . $publicUrl;
        }
        $lines[] = '';
        $lines[] = '## Conteúdo';
        $lines[] = '';
        $lines[] = $body;
        $withoutHash = implode("\n", $lines) . "\n";
        $canonical = hash('sha256', $withoutHash);
        $lines[11] = 'canonical_sha256: ' . $canonical;
        $content = implode("\n", $lines) . "\n";
        $chunks = $this->chunks($title, $body, $publicUrl);
        return ['content' => $content, 'canonical_sha256' => $canonical, 'chunks' => $chunks];
    }

    private function chunks(string $title, string $body, string $publicUrl): array
    {
        $paragraphs = preg_split('/\n\s*\n/u', trim($body)) ?: [];
        $chunks = [];
        $current = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            if ($current !== '' && mb_strlen($current . "\n\n" . $paragraph, 'UTF-8') > 1800) {
                $chunks[] = $this->chunk($title, $current, $publicUrl);
                $current = '';
            }
            $current .= ($current === '' ? '' : "\n\n") . $paragraph;
        }
        if ($current !== '') {
            $chunks[] = $this->chunk($title, $current, $publicUrl);
        }
        return $chunks;
    }

    private function chunk(string $title, string $content, string $publicUrl): array
    {
        $prefix = '[FONTE EXTERNA] ' . $title;
        if ($publicUrl !== '') {
            $prefix .= "\n[URL] " . $publicUrl;
        }
        $text = $prefix . "\n" . $content;
        return [
            'content' => $text,
            'heading' => $title,
            'tags' => 'fonte externa database ' . $this->slugWords((string) ($this->source['name'] ?? '')),
            'token_count' => count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []),
        ];
    }

    private function publicUrl(array $row, string $itemKey): string
    {
        $template = $this->config['public_url_template'];
        $values = ['id' => $itemKey];
        if ($this->config['url_column'] !== '') {
            $values['url'] = trim((string) ($row[$this->config['url_column']] ?? ''));
        }
        foreach ($row as $key => $value) {
            $values[(string) $key] = trim((string) $value);
        }
        if ($template !== '') {
            $url = preg_replace_callback('/\{([A-Za-z0-9_]+)\}/', static fn (array $match): string => rawurlencode((string) ($values[$match[1]] ?? '')), $template) ?? '';
            return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
        }
        $candidate = $this->config['url_column'] !== '' ? trim((string) ($row[$this->config['url_column']] ?? '')) : '';
        return filter_var($candidate, FILTER_VALIDATE_URL) ? $candidate : '';
    }

    private function validIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)?$/', $identifier) === 1;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return implode('.', array_map(static fn (string $part): string => '`' . str_replace('`', '', $part) . '`', explode('.', $identifier)));
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/<\s*br\s*\/?>/iu', "\n", $value) ?? $value;
        $value = preg_replace('/<\/(p|div|li|h[1-6]|tr)>/iu', "\n\n", $value) ?? $value;
        $value = strip_tags($value);
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\n{3,}/u', "\n\n", $value) ?? $value;
        return trim($value);
    }

    private function dateValue($value): ?string
    {
        $value = trim((string) $value);
        return $value === '' || $value === '0000-00-00 00:00:00' ? null : $value;
    }

    private function slugWords(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private function storageDir(): string
    {
        $configured = trim((string) ($_ENV['RAG_UPLOAD_DIR'] ?? getenv('RAG_UPLOAD_DIR') ?: ''));
        return rtrim($configured !== '' ? $configured : dirname(__DIR__) . '/storage/uploads', '/');
    }

    private function finishRun(int $runId, array $summary, float $started): void
    {
        $duration = (int) max(0, round((microtime(true) - $started) * 1000));
        $stmt = $this->app->prepare('UPDATE source_sync_runs SET status = ?, read_count = ?, imported_count = ?, updated_count = ?, unchanged_count = ?, withdrawn_count = ?, error_count = ?, error_message = ?, finished_at = CURRENT_TIMESTAMP, duration_ms = ? WHERE id = ?');
        $stmt->execute([$summary['status'], $summary['read_count'], $summary['imported_count'], $summary['updated_count'], $summary['unchanged_count'], $summary['withdrawn_count'], $summary['error_count'], $summary['error_message'], $duration, $runId]);
    }
}
