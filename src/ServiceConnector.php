<?php
declare(strict_types=1);

final class ServiceConnector
{
    public const SOURCE_NAME = 'carta_servicos';
    public const PARSER_VERSION = 'carta-servicos-v1';

    private PDO $app;
    private string $sourceUrl;

    public function __construct(PDO $app, string $sourceUrl = '')
    {
        $this->app = $app;
        $this->sourceUrl = trim($sourceUrl) !== '' ? trim($sourceUrl) : 'https://example.invalid/servicos';
    }

    public function importText(string $text, string $trigger = 'upload', bool $deactivateMissing = false): array
    {
        $services = $this->parseText($text);
        if (!$services) {
            throw new RuntimeException('Nenhum serviço foi encontrado no arquivo. Use blocos separados por --- e títulos no formato # SERVIÇO: Nome.');
        }

        $started = microtime(true);
        $runId = 0;
        $lockAcquired = false;
        $summary = [
            'trigger' => $trigger,
            'status' => 'running',
            'read_count' => count($services),
            'imported_count' => 0,
            'updated_count' => 0,
            'unchanged_count' => 0,
            'withdrawn_count' => 0,
            'error_count' => 0,
            'error_message' => null,
            'source_url' => $this->sourceUrl,
        ];
        $sourceHash = hash('sha256', $this->normalizeText($text));

        try {
            $stmt = $this->app->prepare('INSERT INTO service_sync_runs(trigger_type, status, source_url, started_at) VALUES(?, ?, ?, CURRENT_TIMESTAMP)');
            $stmt->execute([$trigger, 'running', $this->sourceUrl]);
            $runId = (int) $this->app->lastInsertId();

            $lockStmt = $this->app->prepare('SELECT GET_LOCK(?, 5)');
            $lockStmt->execute(['raglocal_services_import']);
            $lockAcquired = (int) $lockStmt->fetchColumn() === 1;
            if (!$lockAcquired) {
                throw new RuntimeException('Já existe outra importação da Carta de Serviços em andamento.');
            }

            $seen = [];
            foreach ($services as $service) {
                $seen[$service['source_key']] = true;
                try {
                    $result = $this->importService($service);
                    $summary[$result . '_count']++;
                } catch (Throwable $error) {
                    $summary['error_count']++;
                    error_log('RAGLocal service import error for ' . $service['source_key'] . ': ' . $error->getMessage());
                }
            }

            if ($deactivateMissing) {
                $summary['withdrawn_count'] = $this->withdrawMissing($seen);
            }
            $this->app->prepare("INSERT INTO service_import_sources(source_name, source_url, source_content_sha256, service_count, last_import_at) VALUES(?, ?, ?, ?, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE source_url = VALUES(source_url), source_content_sha256 = VALUES(source_content_sha256), service_count = VALUES(service_count), last_import_at = CURRENT_TIMESTAMP")
                ->execute([self::SOURCE_NAME, $this->sourceUrl, $sourceHash, count($services)]);

            $summary['status'] = $summary['error_count'] > 0 ? 'completed_with_errors' : 'completed';
            $this->finishRun($runId, $summary, $started);
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
                    $this->app->query("SELECT RELEASE_LOCK('raglocal_services_import')");
                } catch (Throwable $ignored) {
                    error_log('RAGLocal service unlock failure: ' . $ignored->getMessage());
                }
            }
        }
    }

    public function parseText(string $text): array
    {
        $text = $this->normalizeText($text);
        $parts = preg_split('/(?:^|\n)---+\s*(?:\n|$)/u', $text) ?: [];
        $services = [];
        $usedKeys = [];
        foreach ($parts as $index => $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $title = '';
            if (preg_match('/^#\s*SERVI[CÇ]O\s*:\s*(.+)$/imu', $part, $match)) {
                $title = trim((string) $match[1]);
            }
            $department = '';
            if (preg_match('/^Órgão\s*:\s*(.+)$/imu', $part, $match)) {
                $department = $this->cleanInline((string) $match[1]);
            }
            $publicUrl = '';
            if (preg_match('/^Link\s*:\s*(https?:\/\/\S+)\s*$/imu', $part, $match)) {
                $publicUrl = trim((string) $match[1]);
            }
            if ($title === '') {
                $title = $department !== '' ? 'Serviço da ' . $department . ' ' . ($index + 1) : 'Serviço da Carta de Serviços ' . ($index + 1);
            }
            $body = trim(preg_replace('/^#\s*SERVI[CÇ]O\s*:\s*.+$/imu', '', $part) ?? $part);
            if ($body === '' && $title === '') {
                continue;
            }
            $baseKey = $this->slugify($title);
            $sourceKey = $baseKey;
            $suffix = 2;
            while (isset($usedKeys[$sourceKey])) {
                $sourceKey = $baseKey . '-' . $suffix++;
            }
            $usedKeys[$sourceKey] = true;
            $services[] = [
                'source_key' => mb_substr($sourceKey, 0, 190, 'UTF-8'),
                'title' => mb_substr($this->cleanInline($title), 0, 255, 'UTF-8'),
                'department' => mb_substr($department, 0, 255, 'UTF-8'),
                'public_url' => $this->validUrl($publicUrl) ? $publicUrl : '',
                'body' => $body,
            ];
        }
        return $services;
    }

    private function importService(array $service): string
    {
        $title = (string) $service['title'];
        $body = $this->serviceBody($service);
        $sourceHash = hash('sha256', $body);
        $rag = $this->buildRag($service, $body, $sourceHash);
        $existingStmt = $this->app->prepare('SELECT ds.document_id, ds.source_content_sha256, ds.is_active FROM document_services ds WHERE ds.source_name = ? AND ds.source_key = ? LIMIT 1');
        $existingStmt->execute([self::SOURCE_NAME, $service['source_key']]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existing && (string) $existing['source_content_sha256'] === $sourceHash && (int) $existing['is_active'] === 1) {
            return 'unchanged';
        }

        $storedPath = null;
        $this->app->beginTransaction();
        try {
            if ($existing) {
                $documentId = (int) $existing['document_id'];
                $this->app->prepare("UPDATE documents SET title = ?, kind = 'servico', source_filename = ?, status = 'processing', parser_version = ?, canonical_sha256 = ?, processed_at = NULL WHERE id = ?")
                    ->execute([$title, 'carta-servicos://' . $service['source_key'], self::PARSER_VERSION, $rag['canonical_sha256'], $documentId]);
                $this->app->prepare('DELETE FROM chunks WHERE document_id = ?')->execute([$documentId]);
                $this->app->prepare('DELETE FROM document_artifacts WHERE document_id = ?')->execute([$documentId]);
                $result = 'updated';
            } else {
                $this->app->prepare("INSERT INTO documents(title, kind, source_filename, status, parser_version, canonical_sha256, created_by) VALUES(?, 'servico', ?, 'processing', ?, ?, NULL)")
                    ->execute([$title, 'carta-servicos://' . $service['source_key'], self::PARSER_VERSION, $rag['canonical_sha256']]);
                $documentId = (int) $this->app->lastInsertId();
                $result = 'imported';
            }

            $storageDir = $this->storageDir() . '/servicos';
            if (!is_dir($storageDir) && !mkdir($storageDir, 0770, true) && !is_dir($storageDir)) {
                throw new RuntimeException('Não foi possível criar o armazenamento da Carta de Serviços.');
            }
            $filename = 'servico-' . $service['source_key'] . '.rag.md';
            $storedPath = $storageDir . '/' . $filename;
            $bytes = file_put_contents($storedPath, $rag['markdown'], LOCK_EX);
            if ($bytes === false || $bytes !== strlen($rag['markdown'])) {
                throw new RuntimeException('Não foi possível armazenar o Markdown do serviço.');
            }
            @chmod($storedPath, 0660);

            $this->app->prepare('INSERT INTO document_artifacts(document_id, artifact_type, filename, storage_path, mime_type, byte_size, sha256, content) VALUES(?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$documentId, 'markdown', $filename, 'storage/uploads/servicos/' . $filename, 'text/markdown; charset=UTF-8', $bytes, $rag['canonical_sha256'], $rag['markdown']]);
            $insertChunk = $this->app->prepare('INSERT INTO chunks(document_id, chunk_no, content, section_heading, tags, page_start, page_end, token_count) VALUES(?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($rag['chunks'] as $number => $chunk) {
                $insertChunk->execute([$documentId, $number + 1, $chunk['content'], $chunk['heading'], $chunk['tags'], null, null, $chunk['token_count']]);
            }
            if (!$rag['chunks']) {
                throw new RuntimeException('O serviço não gerou trechos recuperáveis.');
            }
            $this->app->prepare("INSERT INTO document_services(document_id, source_name, source_key, service_title, department, public_url, source_page_url, source_content_sha256, is_active, last_import_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE document_id = VALUES(document_id), service_title = VALUES(service_title), department = VALUES(department), public_url = VALUES(public_url), source_page_url = VALUES(source_page_url), source_content_sha256 = VALUES(source_content_sha256), is_active = 1, last_import_at = CURRENT_TIMESTAMP, withdrawn_at = NULL, withdrawal_reason = NULL")
                ->execute([$documentId, self::SOURCE_NAME, $service['source_key'], $title, $service['department'] !== '' ? $service['department'] : null, $service['public_url'] !== '' ? $service['public_url'] : null, $this->sourceUrl, $sourceHash]);
            $this->app->prepare("UPDATE documents SET status = 'ready', processed_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$documentId]);
            $this->app->commit();
        } catch (Throwable $error) {
            $this->app->rollBack();
            if ($storedPath !== null) {
                @unlink($storedPath);
            }
            throw $error;
        }
        return $result;
    }

    private function withdrawMissing(array $seen): int
    {
        $rows = $this->app->query("SELECT document_id, source_key FROM document_services WHERE source_name = '" . self::SOURCE_NAME . "' AND is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;
        foreach ($rows as $row) {
            if (isset($seen[(string) $row['source_key']])) {
                continue;
            }
            $this->app->beginTransaction();
            try {
                $this->app->prepare("UPDATE document_services SET is_active = 0, withdrawn_at = CURRENT_TIMESTAMP, withdrawal_reason = 'not_in_latest_import' WHERE source_name = ? AND source_key = ?")
                    ->execute([self::SOURCE_NAME, $row['source_key']]);
                $this->app->prepare("UPDATE documents SET status = 'error', processed_at = CURRENT_TIMESTAMP WHERE id = ?")
                    ->execute([(int) $row['document_id']]);
                $this->app->commit();
                $count++;
            } catch (Throwable $error) {
                $this->app->rollBack();
                throw $error;
            }
        }
        return $count;
    }

    private function finishRun(int $runId, array $summary, float $started): void
    {
        $duration = (int) max(0, round((microtime(true) - $started) * 1000));
        $stmt = $this->app->prepare('UPDATE service_sync_runs SET status = ?, read_count = ?, imported_count = ?, updated_count = ?, unchanged_count = ?, withdrawn_count = ?, error_count = ?, error_message = ?, finished_at = CURRENT_TIMESTAMP, duration_ms = ? WHERE id = ?');
        $stmt->execute([$summary['status'], $summary['read_count'], $summary['imported_count'], $summary['updated_count'], $summary['unchanged_count'], $summary['withdrawn_count'], $summary['error_count'], $summary['error_message'], $duration, $runId]);
    }

    private function buildRag(array $service, string $body, string $sourceHash): array
    {
        $tags = $this->tags($service['title'] . ' ' . $service['department'] . ' carta de serviços atendimento público');
        $publicUrl = $service['public_url'] !== '' ? $service['public_url'] : $this->sourceUrl;
        $lines = [
            '---',
            'rag_format: ' . self::PARSER_VERSION,
            'language: pt-BR',
            'title: ' . json_encode($service['title'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'kind: servico',
            'source: Carta de Serviços',
            'source_key: ' . $service['source_key'],
            'department: ' . ($service['department'] !== '' ? json_encode($service['department'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'null'),
            'source_page_url: ' . json_encode($this->sourceUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'public_url: ' . json_encode($publicUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'source_sha256: ' . $sourceHash,
            'canonical_sha256: PENDING',
            '---',
            '',
            '# [DOCUMENTO] ' . $service['title'],
            '',
            '[TAGS] ' . $tags,
            '',
            '## [SEÇÃO] Serviço público',
            '[TAGS] ' . $tags,
            '',
            $body,
            '',
        ];
        $withoutHash = implode("\n", $lines) . "\n";
        $canonicalHash = hash('sha256', $withoutHash);
        foreach ($lines as $index => $line) {
            if ($line === 'canonical_sha256: PENDING') {
                $lines[$index] = 'canonical_sha256: ' . $canonicalHash;
                break;
            }
        }
        $markdown = implode("\n", $lines) . "\n";
        $chunkBody = '[RAG_DOCUMENTO] [FONTE] ' . $service['title'] . ' [TIPO] Carta de Serviços [TAGS] ' . $tags . "\n" . $body;
        $chunks = [];
        foreach ($this->split($chunkBody) as $chunk) {
            $chunks[] = ['content' => $chunk, 'heading' => 'Serviço público', 'tags' => $tags, 'token_count' => count(preg_split('/\s+/u', trim($chunk)) ?: [])];
        }
        return ['markdown' => $markdown, 'canonical_sha256' => $canonicalHash, 'chunks' => $chunks];
    }

    private function serviceBody(array $service): string
    {
        $parts = ['Serviço: ' . $service['title']];
        if ($service['department'] !== '') {
            $parts[] = 'Órgão responsável: ' . $service['department'];
        }
        $parts[] = 'Fonte pública: ' . ($service['public_url'] !== '' ? $service['public_url'] : $this->sourceUrl);
        $parts[] = "Conteúdo da Carta de Serviços:\n" . trim($service['body']);
        return implode("\n\n", $parts);
    }

    private function split(string $text): array
    {
        $paragraphs = preg_split('/\n{2,}/u', trim($text)) ?: [];
        $chunks = [];
        $current = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            if ($current !== '' && mb_strlen($current . "\n\n" . $paragraph, 'UTF-8') > 2800) {
                $chunks[] = $current;
                $current = '';
            }
            if (mb_strlen($paragraph, 'UTF-8') > 3200) {
                foreach (mb_str_split($paragraph, 3000, 'UTF-8') as $part) {
                    if ($current !== '') {
                        $chunks[] = $current;
                        $current = '';
                    }
                    $chunks[] = trim($part);
                }
                continue;
            }
            $current = $current === '' ? $paragraph : $current . "\n\n" . $paragraph;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }
        return $chunks;
    }

    private function normalizeText(string $text): string
    {
        $text = preg_replace('/^\xEF\xBB\xBF/u', '', $text) ?? $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private function cleanInline(string $value): string
    {
        $value = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function slugify(string $value): string
    {
        $value = mb_strtolower($this->cleanInline($value), 'UTF-8');
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-') !== '' ? trim($value, '-') : 'servico';
    }

    private function tags(string $value): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value, 'UTF-8')) ?: [];
        $tags = [];
        foreach ($words as $word) {
            if (mb_strlen($word, 'UTF-8') >= 3) {
                $tags[$word] = true;
            }
        }
        return implode(' ', array_keys($tags));
    }

    private function validUrl(string $url): bool
    {
        return preg_match('#^https?://#i', trim($url)) === 1;
    }

    private function storageDir(): string
    {
        $configured = trim((string) ($_ENV['RAG_UPLOAD_DIR'] ?? getenv('RAG_UPLOAD_DIR') ?: ''));
        return rtrim($configured !== '' ? $configured : dirname(__DIR__) . '/storage/uploads', '/');
    }
}
