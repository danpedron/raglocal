<?php

declare(strict_types=1);

final class RagSearchTerms
{
    /** @var array<string, bool> */
    private const STOPWORDS = [
        'a' => true, 'ao' => true, 'aos' => true, 'as' => true, 'com' => true,
        'como' => true, 'da' => true, 'das' => true, 'de' => true, 'do' => true,
        'dos' => true, 'e' => true, 'em' => true, 'eu' => true, 'faca' => true,
        'faço' => true, 'faco' => true, 'fazer' => true, 'isso' => true, 'me' => true,
        'na' => true, 'nas' => true, 'no' => true, 'nos' => true, 'o' => true,
        'onde' => true, 'os' => true, 'para' => true, 'por' => true, 'posso' => true,
        'preciso' => true, 'quais' => true, 'qual' => true, 'que' => true, 'se' => true,
        'sobre' => true, 'tomar' => true, 'uma' => true, 'um' => true, 'você' => true,
        'voce' => true,
    ];

    /** @return list<string> */
    public static function terms(string $question): array
    {
        $normalized = mb_strtolower(trim($question), 'UTF-8');
        $words = preg_split('/[^\p{L}\p{N}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = [];
        foreach ($words as $word) {
            if (mb_strlen($word, 'UTF-8') < 3 || isset(self::STOPWORDS[$word])) {
                continue;
            }
            $terms[$word] = true;
            if (count($terms) >= 8) {
                break;
            }
        }
        return array_keys($terms);
    }

    public static function booleanPrefixQuery(string $question): string
    {
        return implode(' ', array_map(static fn (string $term): string => $term . '*', self::terms($question)));
    }

    /** @param list<array<string, mixed>> $sources */
    public static function hasDirectEvidenceOverlap(string $question, array $sources): bool
    {
        $terms = self::terms($question);
        if (!$terms || !$sources) {
            return false;
        }

        $requiredMatches = count($terms) === 1 ? 1 : 2;
        foreach (array_slice($sources, 0, 2) as $source) {
            $content = mb_strtolower((string) ($source['content'] ?? ''), 'UTF-8');
            if ($content === '') {
                continue;
            }

            $matches = 0;
            foreach ($terms as $term) {
                if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '[\p{L}\p{N}]*/u', $content) === 1) {
                    $matches++;
                }
                if ($matches >= $requiredMatches) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Detecta material relacionado que pode sustentar apenas uma resposta
     * parcial. A correspondência pode estar distribuída entre as fontes:
     * por exemplo, uma fonte pode mencionar vacinação e outra dengue, sem
     * que nenhuma delas confirme a vacina contra dengue.
     *
     * @param list<array<string, mixed>> $sources
     */
    public static function hasPartialEvidenceOverlap(string $question, array $sources): bool
    {
        $terms = self::terms($question);
        if (!$terms || !$sources) {
            return false;
        }

        foreach (array_slice($sources, 0, 6) as $source) {
            $content = mb_strtolower((string) ($source['content'] ?? ''), 'UTF-8');
            if ($content === '') {
                continue;
            }
            foreach ($terms as $term) {
                if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '[\p{L}\p{N}]*/u', $content) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
