<?php

declare(strict_types=1);

final class RagGlossary
{
    private const MIN_RELATION_OCCURRENCES = 3;
    private const MAX_EXPANSIONS = 6;

    /** @return list<string> */
    public static function questionTerms(string $question): array
    {
        return RagSearchTerms::terms($question);
    }

    /** @return list<array{0: string, 1: string}> */
    public static function relationPairs(string $question): array
    {
        $terms = self::questionTerms($question);
        $pairs = [];
        $count = count($terms);
        for ($left = 0; $left < $count; $left++) {
            for ($right = $left + 1; $right < $count; $right++) {
                $termA = $terms[$left];
                $termB = $terms[$right];
                if ($termA === $termB) {
                    continue;
                }
                if (strcmp($termA, $termB) > 0) {
                    [$termA, $termB] = [$termB, $termA];
                }
                $pairs[$termA . "\0" . $termB] = [$termA, $termB];
            }
        }
        return array_values($pairs);
    }

    public static function recordQuestion(PDO $pdo, string $question): void
    {
        $terms = self::questionTerms($question);
        if (!$terms) {
            return;
        }

        $termStatement = $pdo->prepare('INSERT INTO glossary_terms(term, occurrence_count, first_seen_at, last_seen_at) VALUES(?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE occurrence_count = occurrence_count + 1, last_seen_at = CURRENT_TIMESTAMP');
        foreach ($terms as $term) {
            $termStatement->execute([$term]);
        }

        $relationStatement = $pdo->prepare("INSERT INTO glossary_relations(term_a, term_b, relation_type, question_count, confidence, first_seen_at, last_seen_at) VALUES(?, ?, 'cooccurrence', 1, 0.25, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE question_count = question_count + 1, confidence = LEAST(0.60, 0.15 + ((question_count + 1) * 0.10)), last_seen_at = CURRENT_TIMESTAMP");
        foreach (self::relationPairs($question) as [$termA, $termB]) {
            $relationStatement->execute([$termA, $termB]);
        }
    }

    /** @return list<string> */
    public static function expansionTerms(PDO $pdo, string $question): array
    {
        $terms = self::questionTerms($question);
        if (!$terms) {
            return [];
        }

        try {
            $placeholders = implode(', ', array_fill(0, count($terms), '?'));
            $sql = "SELECT CASE WHEN term_a IN ($placeholders) THEN term_b ELSE term_a END AS related_term
                FROM glossary_relations
                WHERE relation_type = 'cooccurrence'
                  AND question_count >= " . self::MIN_RELATION_OCCURRENCES . "
                  AND confidence >= 0.45
                  AND (term_a IN ($placeholders) OR term_b IN ($placeholders))
                ORDER BY question_count DESC, confidence DESC, last_seen_at DESC
                LIMIT " . self::MAX_EXPANSIONS;
            $statement = $pdo->prepare($sql);
            $statement->execute(array_merge($terms, $terms, $terms));
            $known = array_fill_keys($terms, true);
            $expanded = [];
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $term) {
                $term = trim((string) $term);
                if ($term === '' || isset($known[$term])) {
                    continue;
                }
                $known[$term] = true;
                $expanded[] = $term;
            }
            return $expanded;
        } catch (Throwable $error) {
            error_log('RAG glossary expansion unavailable: ' . $error->getMessage());
            return [];
        }
    }

    /** @return array{term_count: int, relation_count: int, learned_question_count: int} */
    public static function stats(PDO $pdo): array
    {
        try {
            return [
                'term_count' => (int) $pdo->query('SELECT COUNT(*) FROM glossary_terms')->fetchColumn(),
                'relation_count' => (int) $pdo->query("SELECT COUNT(*) FROM glossary_relations WHERE relation_type = 'cooccurrence'")->fetchColumn(),
                'learned_question_count' => (int) $pdo->query('SELECT COALESCE(SUM(occurrence_count), 0) FROM glossary_terms')->fetchColumn(),
            ];
        } catch (Throwable $error) {
            return ['term_count' => 0, 'relation_count' => 0, 'learned_question_count' => 0];
        }
    }

    /** @return list<array{term: string, occurrence_count: int, last_seen_at: string}> */
    public static function topTerms(PDO $pdo, int $limit = 20): array
    {
        try {
            $statement = $pdo->prepare('SELECT term, occurrence_count, last_seen_at FROM glossary_terms ORDER BY occurrence_count DESC, last_seen_at DESC, term ASC LIMIT ?');
            $statement->bindValue(1, max(1, min(50, $limit)), PDO::PARAM_INT);
            $statement->execute();
            return $statement->fetchAll();
        } catch (Throwable $error) {
            return [];
        }
    }

    /** @return list<array{term_a: string, term_b: string, question_count: int, confidence: float, last_seen_at: string}> */
    public static function topRelations(PDO $pdo, int $limit = 20): array
    {
        try {
            $statement = $pdo->prepare("SELECT term_a, term_b, question_count, confidence, last_seen_at FROM glossary_relations WHERE relation_type = 'cooccurrence' ORDER BY question_count DESC, confidence DESC, last_seen_at DESC, term_a ASC, term_b ASC LIMIT ?");
            $statement->bindValue(1, max(1, min(50, $limit)), PDO::PARAM_INT);
            $statement->execute();
            return $statement->fetchAll();
        } catch (Throwable $error) {
            return [];
        }
    }
}
