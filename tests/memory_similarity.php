<?php

declare(strict_types=1);

function normalize_memory_question(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

function memory_question_terms(string $value): array
{
    $normalized = normalize_memory_question($value);
    $ignored = [
        'a', 'ao', 'aos', 'as', 'com', 'da', 'das', 'de', 'do', 'dos', 'e', 'em', 'entre',
        'na', 'nas', 'no', 'nos', 'o', 'os', 'para', 'por', 'que', 'se', 'sem', 'sobre',
        'um', 'uma', 'uns', 'umas', 'como', 'qual', 'quais', 'quando', 'onde', 'quem',
        'porque', 'porquê', 'eu', 'me', 'meu', 'minha', 'meus', 'minhas', 'você', 'voce',
        'te', 'seu', 'sua', 'pode', 'poderia', 'gostaria', 'quero', 'queria', 'saber',
        'dizer', 'ensina', 'ensine', 'ensinar', 'fazer', 'faço', 'faca', 'faz', 'preparar',
        'preparo', 'prepara', 'receita', 'modo', 'maneira', 'forma', 'elaborar', 'elabore',
        'produzir', 'produza', 'obter', 'obtenho', 'conseguir', 'devo', 'deve', 'deveria',
    ];
    $terms = [];
    foreach (explode(' ', $normalized) as $term) {
        if (mb_strlen($term, 'UTF-8') >= 2 && !in_array($term, $ignored, true)) {
            $terms[$term] = true;
        }
    }
    return array_keys($terms);
}

function memory_question_similarity(string $left, string $right): float
{
    $leftTerms = memory_question_terms($left);
    $rightTerms = memory_question_terms($right);
    if (count($leftTerms) < 2 || count($rightTerms) < 2) {
        return 0.0;
    }
    $intersection = count(array_intersect($leftTerms, $rightTerms));
    if ($intersection < 2) {
        return 0.0;
    }
    $union = count(array_unique(array_merge($leftTerms, $rightTerms)));
    $jaccard = $intersection / max(1, $union);
    $leftCoverage = $intersection / count($leftTerms);
    $rightCoverage = $intersection / count($rightTerms);
    if ($jaccard < 0.65 || $leftCoverage < 0.80 || $rightCoverage < 0.80) {
        return 0.0;
    }
    return (0.50 * $jaccard) + (0.25 * $leftCoverage) + (0.25 * $rightCoverage);
}

$cases = [
    ['Você me ensina a fazer um bolo de fubá?', 'Como faço um bolo de fubá?', true],
    ['Como faço um bolo de chocolate?', 'Como faço um bolo de fubá?', false],
    ['Qual o modelo do elevador?', 'Como faço um bolo de fubá?', false],
];
foreach ($cases as [$left, $right, $expected]) {
    $actual = memory_question_similarity($left, $right) >= 0.90;
    if ($actual !== $expected) {
        fwrite(STDERR, "Falha: {$left} <> {$right}\n");
        exit(1);
    }
    echo ($actual ? 'match' : 'no-match') . " | {$left}\n";
}
