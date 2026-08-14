<?php
declare(strict_types=1);

final class NewsConnector
{
    private const SOURCE_NAME = 'wordpress_wp_posts';
    private const SOURCE_TYPE = 'pmjs_noticia';
    private const PARSER_VERSION = 'news-rag-v1';

    private PDO $app;
    private array $config;
    private ?PDO $source = null;

    public function __construct(PDO $app, array $config)
    {
        $this->app = $app;
        $this->config = [
            'enabled' => (string) ($config['enabled'] ?? '0'),
            'host' => trim((string) ($config['host'] ?? '')),
            'port' => (int) ($config['port'] ?? 3306),
            'database' => trim((string) ($config['database'] ?? '')),
            'user' => trim((string) ($config['user'] ?? '')),
            'password' => (string) ($config['password'] ?? ''),
            'table' => trim((string) ($config['table'] ?? 'wp_posts')),
            'post_type' => trim((string) ($config['post_type'] ?? self::SOURCE_TYPE)),
            'public_url_template' => trim((string) ($config['public_url_template'] ?? '')),
        ];
    }

    public static function configFromSettings(PDO $app): array
    {
        $config = [];
        $stmt = $app->query("SELECT name, value FROM settings WHERE name LIKE 'news_%'");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $config[(string) $row['name']] = (string) $row['value'];
        }

        return [
            'enabled' => $config['news_enabled'] ?? '0',
            'host' => $config['news_db_host'] ?? '',
            'port' => $config['news_db_port'] ?? '3306',
            'database' => $config['news_db_name'] ?? '',
            'user' => $config['news_db_user'] ?? '',
            'password' => $config['news_db_password'] ?? '',
            'table' => $config['news_db_table'] ?? 'wp_posts',
            'post_type' => $config['news_post_type'] ?? self::SOURCE_TYPE,
            'public_url_template' => $config['news_public_url_template'] ?? '',
        ];
    }

    public static function settingValues(PDO $app): array
    {
        return self::configFromSettings($app);
    }

    public function config(): array
    {
        return $this->config;
    }

    public function isConfigured(): bool
    {
        return $this->config['host'] !== ''
            && $this->config['port'] >= 1
            && $this->config['port'] <= 65535
            && $this->config['database'] !== ''
            && $this->config['user'] !== ''
            && preg_match('/^[A-Za-z0-9_]+$/', $this->config['table']) === 1
            && preg_match('/^[A-Za-z0-9_.-]+$/', $this->config['post_type']) === 1;
    }

    public function status(): array
    {
        $lastRun = $this->app->query('SELECT * FROM news_sync_runs ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
        $active = (int) $this->app->query("SELECT COUNT(*) FROM document_news WHERE is_active = 1")->fetchColumn();
        $total = (int) $this->app->query("SELECT COUNT(*) FROM document_news")->fetchColumn();
        return ['last_run' => $lastRun, 'active' => $active, 'total' => $total];
    }

    public function sync(string $trigger = 'manual'): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Configure o servidor, porta, banco, usuário, tabela e tipo de publicação do conector.');
        }
        if ((string) $this->config['enabled'] !== '1') {
            throw new RuntimeException('O conector de notícias está desativado. Ative-o antes de sincronizar.');
        }

        $runId = 0;
        $lockAcquired = false;
        $started = microtime(true);
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
            $stmt = $this->app->prepare('INSERT INTO news_sync_runs(trigger_type, status, started_at) VALUES(?, ?, CURRENT_TIMESTAMP)');
            $stmt->execute([$trigger, 'running']);
            $runId = (int) $this->app->lastInsertId();

            $lockStmt = $this->app->prepare('SELECT GET_LOCK(?, 5)');
            $lockStmt->execute(['raglocal_news_sync']);
            $lockAcquired = (int) $lockStmt->fetchColumn() === 1;
            if (!$lockAcquired) {
                throw new RuntimeException('Já existe outra sincronização de notícias em andamento.');
            }

            $rows = $this->fetchPublishedPosts();
            $summary['read_count'] = count($rows);
            $seen = [];
            foreach ($rows as $row) {
                $sourceId = (int) $row['ID'];
                $seen[$sourceId] = true;
                try {
                    $result = $this->importPost($row);
                    $summary[$result . '_count']++;
                } catch (Throwable $error) {
                    $summary['error_count']++;
                    error_log('RAGLocal news import error for post ' . $sourceId . ': ' . $error->getMessage());
                }
            }

            $summary['withdrawn_count'] = $this->withdrawMissing($seen);
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
                    $this->app->query("SELECT RELEASE_LOCK('raglocal_news_sync')");
                } catch (Throwable $ignored) {
                    error_log('RAGLocal news unlock failure: ' . $ignored->getMessage());
                }
            }
        }
    }

    private function sourcePdo(): PDO
    {
        if ($this->source instanceof PDO) {
            return $this->source;
        }
        $dsn = 'mysql:host=' . $this->config['host'] . ';port=' . $this->config['port'] . ';dbname=' . $this->config['database'] . ';charset=utf8mb4';
        $this->source = new PDO($dsn, $this->config['user'], $this->config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 15,
        ]);
        return $this->source;
    }

    private function fetchPublishedPosts(): array
    {
        $table = '`' . str_replace('`', '', $this->config['table']) . '`';
        $sql = "SELECT ID, post_date, post_content, post_title, post_excerpt, post_status, post_name, guid, post_modified, post_type
                FROM {$table}
                WHERE post_type = :post_type
                  AND post_status = 'publish'
                  AND (post_date = '0000-00-00 00:00:00' OR post_date <= NOW())
                ORDER BY ID ASC";
        $stmt = $this->sourcePdo()->prepare($sql);
        $stmt->execute(['post_type' => $this->config['post_type']]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function importPost(array $post): string
    {
        $sourceId = (int) $post['ID'];
        $title = $this->cleanText((string) $post['post_title']);
        $title = $title !== '' ? $title : 'Notícia ' . $sourceId;
        $publicUrl = $this->publicUrl($post);
        $publishedAt = $this->validDate((string) $post['post_date']);
        $modifiedAt = $this->validDate((string) $post['post_modified']);
        $body = $this->newsBody($title, (string) $post['post_excerpt'], (string) $post['post_content'], $publishedAt, $modifiedAt, $publicUrl);
        $sourceHash = hash('sha256', $body);
        $rag = $this->buildRag($title, $body, $sourceHash, $sourceId, $publishedAt, $modifiedAt, $publicUrl);

        $existingStmt = $this->app->prepare('SELECT dn.document_id, dn.source_content_sha256, dn.is_active FROM document_news dn WHERE dn.source_name = ? AND dn.source_id = ? LIMIT 1');
        $existingStmt->execute([self::SOURCE_NAME, $sourceId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existing && (string) $existing['source_content_sha256'] === $sourceHash && (int) $existing['is_active'] === 1) {
            return 'unchanged';
        }

        $pdo = $this->app;
        $storedPath = null;
        $pdo->beginTransaction();
        try {
            if ($existing) {
                $documentId = (int) $existing['document_id'];
                $pdo->prepare("UPDATE documents SET title = ?, kind = 'noticia', source_filename = ?, status = 'processing', parser_version = ?, canonical_sha256 = ?, processed_at = NULL WHERE id = ?")
                    ->execute([$title, 'wordpress://' . self::SOURCE_NAME . '/' . $sourceId, self::PARSER_VERSION, $rag['canonical_sha256'], $documentId]);
                $pdo->prepare('DELETE FROM chunks WHERE document_id = ?')->execute([$documentId]);
                $pdo->prepare('DELETE FROM document_artifacts WHERE document_id = ?')->execute([$documentId]);
                $result = 'updated';
            } else {
                $pdo->prepare("INSERT INTO documents(title, kind, source_filename, status, parser_version, canonical_sha256, created_by) VALUES(?, 'noticia', ?, 'processing', ?, ?, NULL)")
                    ->execute([$title, 'wordpress://' . self::SOURCE_NAME . '/' . $sourceId, self::PARSER_VERSION, $rag['canonical_sha256']]);
                $documentId = (int) $pdo->lastInsertId();
                $result = 'imported';
            }

            $storageDir = $this->storageDir() . '/noticias';
            if (!is_dir($storageDir) && !mkdir($storageDir, 0770, true) && !is_dir($storageDir)) {
                throw new RuntimeException('Não foi possível criar o armazenamento das notícias.');
            }
            $filename = 'noticia-' . $sourceId . '.rag.md';
            $storedPath = $storageDir . '/' . $filename;
            $bytes = file_put_contents($storedPath, $rag['markdown'], LOCK_EX);
            if ($bytes === false || $bytes !== strlen($rag['markdown'])) {
                throw new RuntimeException('Não foi possível armazenar o Markdown da notícia.');
            }
            @chmod($storedPath, 0660);

            $pdo->prepare('INSERT INTO document_artifacts(document_id, artifact_type, filename, storage_path, mime_type, byte_size, sha256, content) VALUES(?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$documentId, 'markdown', $filename, 'storage/uploads/noticias/' . $filename, 'text/markdown; charset=UTF-8', $bytes, $rag['canonical_sha256'], $rag['markdown']]);
            $insertChunk = $pdo->prepare('INSERT INTO chunks(document_id, chunk_no, content, section_heading, tags, page_start, page_end, token_count) VALUES(?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($rag['chunks'] as $number => $chunk) {
                $insertChunk->execute([$documentId, $number + 1, $chunk['content'], $chunk['heading'], $chunk['tags'], null, null, $chunk['token_count']]);
            }
            if (!$rag['chunks']) {
                throw new RuntimeException('A notícia não gerou trechos recuperáveis.');
            }
            $pdo->prepare("INSERT INTO document_news(document_id, source_name, source_id, source_table, source_type, public_url, published_at, modified_at, source_content_sha256, is_active, last_sync_at) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE document_id = VALUES(document_id), source_table = VALUES(source_table), source_type = VALUES(source_type), public_url = VALUES(public_url), published_at = VALUES(published_at), modified_at = VALUES(modified_at), source_content_sha256 = VALUES(source_content_sha256), is_active = 1, last_sync_at = CURRENT_TIMESTAMP, withdrawn_at = NULL, withdrawal_reason = NULL")
                ->execute([$documentId, self::SOURCE_NAME, $sourceId, $this->config['table'], $this->config['post_type'], $publicUrl !== '' ? $publicUrl : null, $publishedAt, $modifiedAt, $sourceHash]);
            $pdo->prepare("UPDATE documents SET status = 'ready', processed_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$documentId]);
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            if ($storedPath !== null) {
                @unlink($storedPath);
            }
            throw $error;
        }
        return $result;
    }

    private function withdrawMissing(array $seen): int
    {
        $rows = $this->app->query("SELECT document_id, source_id FROM document_news WHERE source_name = '" . self::SOURCE_NAME . "' AND is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;
        foreach ($rows as $row) {
            $sourceId = (int) $row['source_id'];
            if (isset($seen[$sourceId])) {
                continue;
            }
            $this->app->beginTransaction();
            try {
                $this->app->prepare("UPDATE document_news SET is_active = 0, withdrawn_at = CURRENT_TIMESTAMP, withdrawal_reason = 'not_published' WHERE source_name = ? AND source_id = ?")
                    ->execute([self::SOURCE_NAME, $sourceId]);
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
        $stmt = $this->app->prepare('UPDATE news_sync_runs SET status = ?, read_count = ?, imported_count = ?, updated_count = ?, unchanged_count = ?, withdrawn_count = ?, error_count = ?, error_message = ?, finished_at = CURRENT_TIMESTAMP, duration_ms = ? WHERE id = ?');
        $stmt->execute([
            $summary['status'],
            $summary['read_count'],
            $summary['imported_count'],
            $summary['updated_count'],
            $summary['unchanged_count'],
            $summary['withdrawn_count'],
            $summary['error_count'],
            $summary['error_message'],
            $duration,
            $runId,
        ]);
    }

    private function storageDir(): string
    {
        $configured = trim((string) ($_ENV['RAG_UPLOAD_DIR'] ?? getenv('RAG_UPLOAD_DIR') ?: ''));
        return rtrim($configured !== '' ? $configured : dirname(__DIR__) . '/storage/uploads', '/');
    }

    private function publicUrl(array $post): string
    {
        $template = $this->config['public_url_template'];
        $guid = trim((string) ($post['guid'] ?? ''));
        $fallback = preg_match('#^https?://#i', $guid) === 1 ? $guid : '';
        if ($template === '') {
            return $fallback;
        }
        $url = strtr($template, [
            '{id}' => rawurlencode((string) ((int) $post['ID'])),
            '{slug}' => rawurlencode(trim((string) ($post['post_name'] ?? ''))),
            '{guid}' => $guid,
        ]);
        return preg_match('#^https?://#i', $url) === 1 ? $url : $fallback;
    }

    private function newsBody(string $title, string $excerpt, string $content, ?string $publishedAt, ?string $modifiedAt, string $publicUrl): string
    {
        $parts = [
            'Título: ' . $title,
            'Data de publicação: ' . ($publishedAt ?: 'não informada'),
            'Última atualização: ' . ($modifiedAt ?: 'não informada'),
        ];
        if ($publicUrl !== '') {
            $parts[] = 'Link público: ' . $publicUrl;
        }
        $summary = $this->cleanText($excerpt);
        if ($summary !== '') {
            $parts[] = "Resumo:\n" . $summary;
        }
        $article = $this->cleanText($content);
        if ($article !== '') {
            $parts[] = "Conteúdo publicado:\n" . $article;
        }
        return implode("\n\n", $parts);
    }

    private function buildRag(string $title, string $body, string $sourceHash, int $sourceId, ?string $publishedAt, ?string $modifiedAt, string $publicUrl): array
    {
        $tags = $this->tags($title . ' notícias informação publicada');
        $markdown = [
            '---',
            'rag_format: ' . self::PARSER_VERSION,
            'language: pt-BR',
            'title: ' . json_encode($title, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'kind: noticia',
            'source: WordPress',
            'source_table: ' . $this->config['table'],
            'source_id: ' . $sourceId,
            'post_type: ' . self::SOURCE_TYPE,
            'published_at: ' . ($publishedAt ?: 'null'),
            'modified_at: ' . ($modifiedAt ?: 'null'),
            'public_url: ' . ($publicUrl !== '' ? json_encode($publicUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'null'),
            'source_sha256: ' . $sourceHash,
            'canonical_sha256: PENDING',
            '---',
            '',
            '# [DOCUMENTO] ' . $title,
            '',
            '[TAGS] ' . $tags,
            '',
            '## [SEÇÃO] Notícia publicada',
            '[TAGS] ' . $tags,
            '',
            $body,
            '',
        ];
        $withoutHash = implode("\n", $markdown) . "\n";
        $canonicalHash = hash('sha256', $withoutHash);
        $markdown[13] = 'canonical_sha256: ' . $canonicalHash;
        $chunkBody = '[RAG_DOCUMENTO] [FONTE] ' . $title . ' [TIPO] Notícias [TAGS] ' . $tags . "\n" . $body;
        $chunks = [];
        foreach ($this->split($chunkBody) as $chunk) {
            $chunks[] = [
                'content' => $chunk,
                'heading' => 'Notícia publicada',
                'tags' => $tags,
                'token_count' => count(preg_split('/\s+/u', $chunk, -1, PREG_SPLIT_NO_EMPTY) ?: []),
            ];
        }
        return [
            'markdown' => implode("\n", $markdown) . "\n",
            'canonical_sha256' => $canonicalHash,
            'chunks' => $chunks,
        ];
    }

    private function split(string $body, int $size = 1800): array
    {
        $body = trim($body);
        if ($body === '') {
            return [];
        }
        $paragraphs = preg_split('/\n{2,}/u', $body) ?: [];
        $chunks = [];
        $current = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim(preg_replace('/\s+/u', ' ', $paragraph) ?? '');
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
        return array_values(array_filter($chunks, static fn (string $value): bool => trim($value) !== ''));
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('#<(script|style|noscript|iframe)\b[^>]*>.*?</\1>#is', ' ', $value) ?? $value;
        $value = preg_replace('/\[(?:\/?)(?:gallery|caption|embed|audio|video|playlist|contact-form|vc_[^\]\s]+)[^\]]*\]/iu', ' ', $value) ?? $value;
        $value = preg_replace('#<\s*(br|p|div|h[1-6]|li|blockquote|pre|figure|figcaption)\b[^>]*>#i', "\n\n", $value) ?? $value;
        $value = preg_replace('#</\s*(p|div|h[1-6]|li|blockquote|pre|figure|figcaption)\s*>#i', "\n\n", $value) ?? $value;
        $value = strip_tags($value);
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0E-\x1F\x7F]/u', ' ', $value) ?? $value;
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\n{3,}/u', "\n\n", $value) ?? $value;
        return trim($value);
    }

    private function validDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (Throwable $error) {
            return null;
        }
    }

    private function tags(string $value): string
    {
        $tags = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value, 'UTF-8')) ?: [] as $word) {
            if (mb_strlen($word, 'UTF-8') >= 3) {
                $tags[$word] = true;
            }
        }
        return implode(' ', array_keys($tags));
    }
}
