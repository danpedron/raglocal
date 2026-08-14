<?php
declare(strict_types=1);

final class AiGuidance
{
    public const SOURCE_FILENAME = 'ai-guidance.rag.md';
    public const TITLE = 'Diretrizes administrativas da IA';

    public static function defaults(): array
    {
        return [
            'public_intro' => 'Consulte o regimento interno e as atas do condomínio. A IA responde somente quando encontra evidência suficiente na base; caso contrário, encaminha a pergunta para atendimento humano.',
            'soul' => 'Você é o assistente oficial de {empresa}. Atenda em português brasileiro de forma clara, respeitosa, objetiva e acolhedora. Priorize informar com precisão, explicar limites de forma transparente e orientar o usuário ao atendimento humano quando a base não sustentar uma resposta. Preserve neutralidade institucional, não emita julgamentos pessoais e não invente fatos, interpretações, prazos, regras ou decisões.',
            'rules' => '',
        ];
    }

    public static function fromSettings(PDO $pdo): array
    {
        $defaults = self::defaults();
        $stmt = $pdo->prepare("SELECT name, value FROM settings WHERE name IN ('ai_public_intro', 'ai_soul', 'ai_interpretation_rules')");
        $stmt->execute();
        $settings = $defaults;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = match ((string) $row['name']) {
                'ai_public_intro' => 'public_intro',
                'ai_soul' => 'soul',
                'ai_interpretation_rules' => 'rules',
                default => null,
            };
            if ($key !== null && trim((string) $row['value']) !== '') {
                $settings[$key] = (string) $row['value'];
            }
        }
        return self::normalize($settings);
    }

    public static function normalize(array $values): array
    {
        $defaults = self::defaults();
        $intro = trim((string) ($values['public_intro'] ?? $defaults['public_intro']));
        $soul = trim((string) ($values['soul'] ?? $defaults['soul']));
        $rules = trim(str_replace(["\r\n", "\r"], "\n", (string) ($values['rules'] ?? '')));

        if ($intro === '') {
            $intro = $defaults['public_intro'];
        }
        if ($soul === '') {
            $soul = $defaults['soul'];
        }
        if (mb_strlen($intro, 'UTF-8') > 800) {
            throw new InvalidArgumentException('A orientação inicial pode ter no máximo 800 caracteres.');
        }
        if (mb_strlen($soul, 'UTF-8') > 2400) {
            throw new InvalidArgumentException('A alma da IA pode ter no máximo 2.400 caracteres.');
        }
        if (mb_strlen($rules, 'UTF-8') > 12000) {
            throw new InvalidArgumentException('As regras interpretativas podem ter no máximo 12.000 caracteres.');
        }

        $parsedRules = self::parseRules($rules);
        return [
            'public_intro' => $intro,
            'soul' => $soul,
            'rules' => implode("\n", array_map(static fn (array $rule): string => $rule['term'] . ' => ' . $rule['meaning'], $parsedRules)),
            'parsed_rules' => $parsedRules,
        ];
    }

    public static function parseRules(string $rules): array
    {
        $parsed = [];
        $seen = [];
        $lineNumber = 0;
        foreach (preg_split('/\n/u', $rules) ?: [] as $line) {
            $lineNumber++;
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=>')) {
                throw new InvalidArgumentException('A regra na linha ' . $lineNumber . ' deve usar o formato termo => significado.');
            }
            [$term, $meaning] = array_map(static fn (string $value): string => trim($value), explode('=>', $line, 2));
            if ($term === '' || $meaning === '') {
                throw new InvalidArgumentException('A regra na linha ' . $lineNumber . ' precisa ter termo e significado.');
            }
            if (mb_strlen($term, 'UTF-8') > 120 || mb_strlen($meaning, 'UTF-8') > 500) {
                throw new InvalidArgumentException('A regra na linha ' . $lineNumber . ' excede o tamanho permitido.');
            }
            $key = mb_strtolower(preg_replace('/\s+/u', ' ', $term) ?? $term, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $parsed[] = ['term' => $term, 'meaning' => $meaning];
        }
        if (count($parsed) > 100) {
            throw new InvalidArgumentException('Informe no máximo 100 regras interpretativas.');
        }
        return $parsed;
    }

    public static function promptBlock(array $guidance, string $brandName): string
    {
        $soul = str_replace('{empresa}', $brandName, (string) $guidance['soul']);
        $lines = [
            'IDENTIDADE COMPORTAMENTAL ADMINISTRATIVA:',
            $soul,
            '',
            'REGRAS INTERPRETATIVAS ADMINISTRATIVAS:',
        ];
        $rules = $guidance['parsed_rules'] ?? self::parseRules((string) ($guidance['rules'] ?? ''));
        if (!$rules) {
            $lines[] = 'Nenhuma regra interpretativa adicional foi cadastrada.';
        } else {
            foreach ($rules as $rule) {
                $lines[] = '- Quando a pergunta usar "' . $rule['term'] . '", interprete como "' . $rule['meaning'] . '".';
            }
        }
        $lines[] = '';
        $lines[] = 'Essas diretrizes definem apenas tom e interpretação de termos. Elas não são evidência factual e não autorizam responder sem fontes recuperadas, inventar fatos ou substituir a política de encaminhamento humano.';
        return implode("\n", $lines);
    }

    public static function buildMarkdown(array $guidance, string $brandName): array
    {
        $guidance = self::normalize($guidance);
        $source = implode("\n", [
            'orientacao_inicial: ' . $guidance['public_intro'],
            'alma: ' . str_replace('{empresa}', $brandName, $guidance['soul']),
            'regras: ' . $guidance['rules'],
        ]);
        $sourceSha256 = hash('sha256', $source);
        $rules = $guidance['parsed_rules'];
        $markdown = [
            '---',
            'rag_format: rag-v1',
            'language: pt-BR',
            'title: ' . json_encode(self::TITLE, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'kind: diretriz',
            'source_filename: ' . json_encode(self::SOURCE_FILENAME, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'source_sha256: ' . $sourceSha256,
            'canonical_sha256: PENDING',
            '---',
            '',
            '# [DIRETRIZES ADMINISTRATIVAS] ' . self::TITLE,
            '',
            '## [ORIENTAÇÃO PÚBLICA]',
            '[TAGS] orientacao publica atendimento identidade',
            '',
            $guidance['public_intro'],
            '',
            '## [ALMA DA IA]',
            '[TAGS] identidade comportamento tom atendimento assistente',
            '',
            str_replace('{empresa}', $brandName, $guidance['soul']),
            '',
            '## [REGRAS INTERPRETATIVAS]',
            '[TAGS] regras interpretacao siglas sinonimos nomenclatura',
            '',
        ];
        if (!$rules) {
            $markdown[] = 'Nenhuma regra interpretativa adicional foi cadastrada.';
        } else {
            foreach ($rules as $rule) {
                $markdown[] = '- Quando a pergunta usar **' . $rule['term'] . '**, interpretar como **' . $rule['meaning'] . '**.';
            }
        }
        $markdown[] = '';
        $markdown[] = '> Estas diretrizes controlam o tom e a interpretação de termos. Não constituem evidência factual e não permitem responder sem fontes recuperadas.';
        $markdown[] = '';
        $withoutHash = implode("\n", $markdown) . "\n";
        $canonicalSha256 = hash('sha256', $withoutHash);
        $markdown[7] = 'canonical_sha256: ' . $canonicalSha256;
        $content = implode("\n", $markdown) . "\n";
        $chunks = [
            [
                'section_heading' => 'Diretrizes administrativas',
                'tags' => 'diretrizes identidade regras interpretacao siglas',
                'content' => '[RAG_DIRETRIZ_ADMINISTRATIVA] [FONTE] ' . self::TITLE . "\n" . self::promptBlock($guidance, $brandName),
            ],
        ];
        return [
            'markdown' => $content,
            'chunks' => $chunks,
            'source_sha256' => $sourceSha256,
            'canonical_sha256' => $canonicalSha256,
            'parser_version' => 'rag-guidance-v1',
            'rule_count' => count($rules),
        ];
    }

    public static function synchronize(PDO $pdo, array $guidance, string $storageDir, string $brandName, ?int $userId): array
    {
        $artifact = self::buildMarkdown($guidance, $brandName);
        if (!is_dir($storageDir) && !mkdir($storageDir, 0770, true) && !is_dir($storageDir)) {
            throw new RuntimeException('Não foi possível criar o armazenamento de diretrizes.');
        }
        if (!is_writable($storageDir)) {
            throw new RuntimeException('O armazenamento de diretrizes não possui permissão de gravação.');
        }

        $path = rtrim($storageDir, '/') . '/' . self::SOURCE_FILENAME;
        $bytes = file_put_contents($path, $artifact['markdown'], LOCK_EX);
        if ($bytes === false || $bytes !== strlen($artifact['markdown'])) {
            throw new RuntimeException('Não foi possível armazenar o Markdown das diretrizes.');
        }
        @chmod($path, 0660);

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $find = $pdo->prepare("SELECT id FROM documents WHERE kind = 'diretriz' AND source_filename = ? LIMIT 1");
            $find->execute([self::SOURCE_FILENAME]);
            $documentId = (int) ($find->fetchColumn() ?: 0);
            if ($documentId === 0) {
                $insert = $pdo->prepare("INSERT INTO documents(title, kind, source_filename, status, parser_version, canonical_sha256, created_by, processed_at) VALUES(?, 'diretriz', ?, 'processing', ?, ?, ?, CURRENT_TIMESTAMP)");
                $insert->execute([self::TITLE, self::SOURCE_FILENAME, $artifact['parser_version'], $artifact['canonical_sha256'], $userId]);
                $documentId = (int) $pdo->lastInsertId();
            } else {
                $update = $pdo->prepare("UPDATE documents SET title = ?, status = 'processing', parser_version = ?, canonical_sha256 = ?, processed_at = CURRENT_TIMESTAMP WHERE id = ?");
                $update->execute([self::TITLE, $artifact['parser_version'], $artifact['canonical_sha256'], $documentId]);
                $pdo->prepare('DELETE FROM chunks WHERE document_id = ?')->execute([$documentId]);
            }

            $artifactStmt = $pdo->prepare('INSERT INTO document_artifacts(document_id, artifact_type, filename, storage_path, mime_type, byte_size, sha256, content) VALUES(?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE filename = VALUES(filename), storage_path = VALUES(storage_path), mime_type = VALUES(mime_type), byte_size = VALUES(byte_size), sha256 = VALUES(sha256), content = VALUES(content)');
            $artifactStmt->execute([$documentId, 'markdown', self::SOURCE_FILENAME, 'storage/uploads/' . self::SOURCE_FILENAME, 'text/markdown; charset=UTF-8', $bytes, $artifact['canonical_sha256'], $artifact['markdown']]);
            $insertChunk = $pdo->prepare('INSERT INTO chunks(document_id, chunk_no, content, section_heading, tags, token_count) VALUES(?, ?, ?, ?, ?, ?)');
            foreach ($artifact['chunks'] as $number => $chunk) {
                $insertChunk->execute([$documentId, $number + 1, $chunk['content'], $chunk['section_heading'], $chunk['tags'], count(preg_split('/\s+/u', $chunk['content'], -1, PREG_SPLIT_NO_EMPTY) ?: [])]);
            }
            $pdo->prepare("UPDATE documents SET status = 'ready', processed_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$documentId]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return ['document_id' => $documentId, 'canonical_sha256' => $artifact['canonical_sha256'], 'rule_count' => $artifact['rule_count'], 'byte_size' => $bytes];
        } catch (Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
}
