<?php
declare(strict_types=1);

final class SourceRegistry
{
    public static function plugins(): array
    {
        return [
            'database_table' => [
                'label' => 'Banco de dados — tabela',
                'description' => 'Conecta em uma tabela MariaDB/MySQL somente leitura e mapeia campos para documentos RAG.',
                'syncable' => true,
                'config_mode' => 'database_table',
                'class' => 'DatabaseTablePlugin',
            ],
            'markdown_file' => [
                'label' => 'Arquivo Markdown/TXT',
                'description' => 'Importa um arquivo já preparado pela organização e mantém somente os artefatos Markdown canônicos.',
                'syncable' => false,
                'config_mode' => 'source_url',
                'class' => null,
            ],
        ];
    }

    public static function executor(PDO $pdo, array $source): object
    {
        $pluginKey = (string) ($source['plugin_key'] ?? '');
        $definition = self::plugins()[$pluginKey] ?? null;
        $class = is_array($definition) ? ($definition['class'] ?? null) : null;
        if (!is_string($class) || $class === '' || !class_exists($class)) {
            throw new InvalidArgumentException('Plugin sem executor disponível: ' . $pluginKey);
        }
        $executor = new $class($pdo, $source);
        if (!method_exists($executor, 'sync')) {
            throw new InvalidArgumentException('Plugin sem operação de sincronização: ' . $pluginKey);
        }
        return $executor;
    }

    public static function all(PDO $pdo, bool $enabledOnly = false): array
    {
        $sql = 'SELECT * FROM knowledge_sources';
        if ($enabledOnly) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY enabled DESC, name ASC, id ASC';
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM knowledge_sources WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function publicConfig(array $source): array
    {
        $config = json_decode((string) ($source['config_json'] ?? '{}'), true);
        return is_array($config) ? $config : [];
    }

    public static function save(PDO $pdo, array $values, ?int $id, ?int $userId): int
    {
        $name = trim((string) ($values['name'] ?? ''));
        $description = trim((string) ($values['description'] ?? ''));
        $pluginKey = trim((string) ($values['plugin_key'] ?? ''));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 255) {
            throw new InvalidArgumentException('Informe um nome de fonte com até 255 caracteres.');
        }
        if (!isset(self::plugins()[$pluginKey])) {
            throw new InvalidArgumentException('Plugin de fonte não disponível.');
        }
        $config = self::normalizeConfig((array) ($values['config'] ?? []), $pluginKey);
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $keyBase = self::slug((string) ($values['source_key'] ?? $name));
        $key = $keyBase !== '' ? $keyBase : 'source';
        if ($id !== null) {
            $existing = self::find($pdo, $id);
            if (!$existing) {
                throw new InvalidArgumentException('Fonte não encontrada.');
            }
            $key = (string) $existing['source_key'];
            $stmt = $pdo->prepare('UPDATE knowledge_sources SET plugin_key = ?, name = ?, description = ?, config_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([$pluginKey, $name, $description !== '' ? $description : null, $json, $id]);
            return $id;
        }
        $stmt = $pdo->prepare('INSERT INTO knowledge_sources(source_key, plugin_key, name, description, enabled, config_json, created_by) VALUES(?, ?, ?, ?, 1, ?, ?)');
        $stmt->execute([$key, $pluginKey, $name, $description !== '' ? $description : null, $json, $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function toggle(PDO $pdo, int $id): bool
    {
        $source = self::find($pdo, $id);
        if (!$source) {
            throw new InvalidArgumentException('Fonte não encontrada.');
        }
        $enabled = (int) $source['enabled'] === 1 ? 0 : 1;
        $pdo->prepare('UPDATE knowledge_sources SET enabled = ? WHERE id = ?')->execute([$enabled, $id]);
        if ($enabled === 0) {
            $pdo->prepare("UPDATE document_source_links SET is_active = 0, withdrawn_at = CURRENT_TIMESTAMP, withdrawal_reason = 'source_disabled' WHERE source_id = ?")->execute([$id]);
            $pdo->prepare("UPDATE documents d JOIN document_source_links l ON l.document_id = d.id SET d.status = 'disabled' WHERE l.source_id = ? AND NOT EXISTS (SELECT 1 FROM document_source_links other WHERE other.document_id = d.id AND other.source_id <> l.source_id AND other.is_active = 1)")->execute([$id]);
        } else {
            // Reative somente itens retirados quando a fonte foi desativada.
            // Itens ausentes na origem continuam desativados até reaparecerem em uma sincronização.
            $pdo->prepare("UPDATE document_source_links SET is_active = 1, withdrawn_at = NULL, withdrawal_reason = NULL WHERE source_id = ? AND is_active = 0 AND withdrawal_reason = 'source_disabled'")->execute([$id]);
            $pdo->prepare("UPDATE documents d JOIN document_source_links l ON l.document_id = d.id SET d.status = 'ready', d.processed_at = COALESCE(d.processed_at, CURRENT_TIMESTAMP) WHERE l.source_id = ? AND l.is_active = 1 AND d.status = 'disabled'")->execute([$id]);
        }
        return $enabled === 1;
    }

    public static function remove(PDO $pdo, int $id): array
    {
        $source = self::find($pdo, $id);
        if (!$source) {
            throw new InvalidArgumentException('Fonte não encontrada.');
        }
        $stmt = $pdo->prepare('SELECT DISTINCT d.id, a.storage_path, EXISTS (SELECT 1 FROM document_source_links other WHERE other.document_id = d.id AND other.source_id <> ?) AS shared_document FROM documents d JOIN document_source_links l ON l.document_id = d.id LEFT JOIN document_artifacts a ON a.document_id = d.id WHERE l.source_id = ?');
        $stmt->execute([$id, $id]);
        $documents = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $documentId = (int) $row['id'];
            if (!isset($documents[$documentId])) {
                $documents[$documentId] = ['paths' => [], 'delete' => (int) $row['shared_document'] !== 1];
            }
            if (!empty($row['storage_path'])) {
                $documents[$documentId]['paths'][] = self::storagePath((string) $row['storage_path']);
            }
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM knowledge_sources WHERE id = ?')->execute([$id]);
            $deleteDocument = $pdo->prepare('DELETE FROM documents WHERE id = ?');
            $deletedFiles = [];
            $deletedDocumentCount = 0;
            foreach ($documents as $documentId => $document) {
                if (!$document['delete']) {
                    continue;
                }
                $deleteDocument->execute([(int) $documentId]);
                if ($deleteDocument->rowCount() > 0) {
                    $deletedDocumentCount++;
                    foreach ($document['paths'] as $path) {
                        $deletedFiles[] = $path;
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        foreach (array_unique($deletedFiles) as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        return ['name' => (string) $source['name'], 'document_count' => count($documents), 'deleted_document_count' => $deletedDocumentCount];
    }

    public static function deleteDocument(PDO $pdo, int $documentId): array
    {
        $stmt = $pdo->prepare('SELECT d.title, a.storage_path FROM documents d LEFT JOIN document_artifacts a ON a.document_id = d.id WHERE d.id = ?');
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$document) {
            throw new InvalidArgumentException('Documento não encontrado.');
        }
        $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$documentId]);
        if (!empty($document['storage_path'])) {
            $file = self::storagePath((string) $document['storage_path']);
            if (is_file($file)) {
                @unlink($file);
            }
        }
        return ['title' => (string) $document['title']];
    }

    private static function normalizeConfig(array $config, string $pluginKey): array
    {
        if ($pluginKey !== 'database_table') {
            return [
                'source_url' => mb_substr(trim((string) ($config['source_url'] ?? '')), 0, 2048, 'UTF-8'),
                'withdraw_missing' => !empty($config['withdraw_missing']),
            ];
        }
        $content = $config['content_columns'] ?? [];
        if (is_string($content)) {
            $content = preg_split('/[,\s]+/', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($content)) {
            $content = [];
        }
        $content = array_values(array_filter(array_map(static fn ($value): string => trim((string) $value), $content), static fn (string $value): bool => $value !== ''));
        if ($content === []) {
            throw new InvalidArgumentException('Informe pelo menos um campo de conteúdo.');
        }
        $identifiers = ['table', 'key_column', 'title_column', 'filter_column', 'status_column', 'published_column', 'modified_column', 'url_column'];
        foreach ($identifiers as $field) {
            $value = trim((string) ($config[$field] ?? ''));
            if ($value !== '' && preg_match('/^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)?$/', $value) !== 1) {
                throw new InvalidArgumentException('O campo ' . $field . ' possui um identificador inválido.');
            }
        }
        $template = trim((string) ($config['public_url_template'] ?? ''));
        if ($template !== '' && (mb_strlen($template, 'UTF-8') > 2048 || preg_match('#^https?://#i', $template) !== 1)) {
            throw new InvalidArgumentException('O modelo de URL deve começar com http:// ou https://.');
        }
        $passwordEnc = trim((string) ($config['password_enc'] ?? ''));
        $newPassword = (string) ($config['password_plain'] ?? '');
        if ($newPassword !== '') {
            $passwordEnc = SecretBox::encrypt($newPassword, (string) ($_ENV['APP_SECRET'] ?? getenv('APP_SECRET') ?: ''));
        }
        return [
            'host' => mb_substr(trim((string) ($config['host'] ?? '')), 0, 255, 'UTF-8'),
            'port' => max(1, min(65535, (int) ($config['port'] ?? 3306))),
            'database' => mb_substr(trim((string) ($config['database'] ?? '')), 0, 190, 'UTF-8'),
            'user' => mb_substr(trim((string) ($config['user'] ?? '')), 0, 190, 'UTF-8'),
            'password_enc' => $passwordEnc,
            'table' => trim((string) ($config['table'] ?? '')),
            'key_column' => trim((string) ($config['key_column'] ?? 'id')),
            'title_column' => trim((string) ($config['title_column'] ?? 'title')),
            'content_columns' => $content,
            'filter_column' => trim((string) ($config['filter_column'] ?? '')),
            'filter_value' => (string) ($config['filter_value'] ?? ''),
            'status_column' => trim((string) ($config['status_column'] ?? '')),
            'status_value' => (string) ($config['status_value'] ?? ''),
            'published_column' => trim((string) ($config['published_column'] ?? '')),
            'modified_column' => trim((string) ($config['modified_column'] ?? '')),
            'url_column' => trim((string) ($config['url_column'] ?? '')),
            'public_url_template' => $template,
            'withdraw_missing' => !empty($config['withdraw_missing']),
        ];
    }

    private static function slug(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^\pL\pN]+/u', '-', $value) ?? $value;
        $value = trim($value, '-');
        return substr($value, 0, 100);
    }

    private static function storagePath(string $relative): string
    {
        $base = rtrim((string) ($_ENV['RAG_UPLOAD_DIR'] ?? getenv('RAG_UPLOAD_DIR') ?: dirname(__DIR__) . '/storage/uploads'), '/');
        if (str_starts_with($relative, 'storage/uploads/')) {
            return dirname($base) . '/' . substr($relative, strlen('storage/uploads/'));
        }
        return $relative;
    }
}
