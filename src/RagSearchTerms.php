<?php

declare(strict_types=1);

final class RagSearchTerms
{
    /** @var array<string, bool> */
    private const STOPWORDS = [
        'a' => true, 'ao' => true, 'aos' => true, 'as' => true, 'com' => true,
        'como' => true,         'da' => true, 'das' => true, 'de' => true, 'do' => true,
        'faço' => true,

        'dos' => true, 'e' => true, 'em' => true, 'eu' => true, 'faca' => true,
        'faco' => true, 'fazer' => true, 'isso' => true, 'me' => true, 'na' => true, 'nas' => true,
        'no' => true, 'nos' => true, 'o' => true, 'onde' => true, 'os' => true,
        'para' => true, 'por' => true, 'posso' => true, 'preciso' => true,
        'quais' => true, 'qual' => true, 'que' => true, 'se' => true, 'sobre' => true,
        'tomar' => true, 'uma' => true, 'um' => true, 'você' => true, 'voce' => true,
    ];

    public static function booleanPrefixQuery(string $question): string
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
        return implode(' ', array_map(static fn (string $term): string => $term . '*', array_keys($terms)));
    }
}
