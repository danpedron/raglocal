<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/SecretBox.php';
require_once dirname(__DIR__) . '/src/SourceRegistry.php';
require_once dirname(__DIR__) . '/src/DatabaseTablePlugin.php';

$database = (string) ($argv[1] ?? '');
$dbUser = (string) ($argv[2] ?? '');
$dbPass = (string) ($argv[3] ?? '');
if ($database === '' || $dbUser === '') {
    fwrite(STDERR, "Uso: php tests/generic_source_lifecycle.php <banco> <usuario> [senha]\n");
    exit(2);
}

$pdo = new PDO('mysql:host=127.0.0.1;dbname=' . $database . ';charset=utf8mb4', $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$prefix = 'test-source-' . bin2hex(random_bytes(8));
$sourceIds = [];
$documentIds = [];

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $config = json_encode(['content_columns' => ['content']], JSON_THROW_ON_ERROR);
    $insertSource = $pdo->prepare("INSERT INTO knowledge_sources(source_key, plugin_key, name, enabled, config_json) VALUES(?, 'database_table', ?, 1, ?)");
    foreach (['a', 'b'] as $suffix) {
        $insertSource->execute([$prefix . '-' . $suffix, 'Fonte de teste ' . strtoupper($suffix), $config]);
        $sourceIds[$suffix] = (int) $pdo->lastInsertId();
    }

    $pdo->prepare("INSERT INTO documents(title, kind, status, parser_version, canonical_sha256) VALUES(?, 'externa', 'ready', 'test', ?)")
        ->execute(['Documento compartilhado', hash('sha256', $prefix . '-shared')]);
    $documentIds['shared'] = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO documents(title, kind, status, parser_version, canonical_sha256) VALUES(?, 'externa', 'ready', 'test', ?)")
        ->execute(['Documento exclusivo', hash('sha256', $prefix . '-exclusive')]);
    $documentIds['exclusive'] = (int) $pdo->lastInsertId();

    $insertLink = $pdo->prepare('INSERT INTO document_source_links(source_id, document_id, source_item_key, source_content_sha256, is_active) VALUES(?, ?, ?, ?, 1)');
    $insertLink->execute([$sourceIds['a'], $documentIds['shared'], 'shared-a', hash('sha256', $prefix . '-shared-a')]);
    $insertLink->execute([$sourceIds['b'], $documentIds['shared'], 'shared-b', hash('sha256', $prefix . '-shared-b')]);
    $insertLink->execute([$sourceIds['a'], $documentIds['exclusive'], 'exclusive-a', hash('sha256', $prefix . '-exclusive-a')]);

    $assert(SourceRegistry::toggle($pdo, $sourceIds['a']) === false, 'A desativação deveria retornar false.');
    $status = $pdo->prepare('SELECT status FROM documents WHERE id = ?');
    $status->execute([$documentIds['shared']]);
    $assert($status->fetchColumn() === 'ready', 'Documento compartilhado foi desativado indevidamente.');
    $status->execute([$documentIds['exclusive']]);
    $assert($status->fetchColumn() === 'disabled', 'Documento exclusivo deveria ficar desativado.');

    $assert(SourceRegistry::toggle($pdo, $sourceIds['a']) === true, 'A reativação deveria retornar true.');
    $status->execute([$documentIds['exclusive']]);
    $assert($status->fetchColumn() === 'ready', 'Documento exclusivo não foi reativado.');

    $source = SourceRegistry::find($pdo, $sourceIds['a']);
    $assert(is_array($source), 'Fonte de teste não encontrada.');
    $plugin = new DatabaseTablePlugin($pdo, $source);
    $withdraw = new ReflectionMethod($plugin, 'withdrawMissing');
    $withdraw->setAccessible(true);
    $withdrawn = $withdraw->invoke($plugin, ['exclusive-a' => true]);
    $assert($withdrawn === 1, 'A retirada incremental deveria afetar um item.');
    $status->execute([$documentIds['shared']]);
    $assert($status->fetchColumn() === 'ready', 'A retirada de um vínculo não deveria afetar o documento compartilhado.');

    $removed = SourceRegistry::remove($pdo, $sourceIds['a']);
    $sourceIds['a'] = 0;
    $assert((int) $removed['deleted_document_count'] === 1, 'A remoção deveria apagar somente o documento exclusivo.');
    $exists = $pdo->prepare('SELECT COUNT(*) FROM documents WHERE id = ?');
    $exists->execute([$documentIds['shared']]);
    $assert((int) $exists->fetchColumn() === 1, 'A primeira fonte não deveria apagar o documento compartilhado.');

    $removed = SourceRegistry::remove($pdo, $sourceIds['b']);
    $sourceIds['b'] = 0;
    $assert((int) $removed['deleted_document_count'] === 1, 'A última fonte deveria apagar o documento que ficou órfão.');
    $exists->execute([$documentIds['shared']]);
    $assert((int) $exists->fetchColumn() === 0, 'O documento órfão permaneceu após a remoção da última fonte.');

    echo "generic source lifecycle: ok\n";
} finally {
    foreach ($sourceIds as $sourceId) {
        if ($sourceId > 0) {
            $pdo->prepare('DELETE FROM knowledge_sources WHERE id = ?')->execute([$sourceId]);
        }
    }
    foreach ($documentIds as $documentId) {
        $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$documentId]);
    }
}
