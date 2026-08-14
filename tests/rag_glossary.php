<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/RagSearchTerms.php';
require_once dirname(__DIR__) . '/src/RagGlossary.php';

function expect_glossary(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

$question = 'Quero tomar a vacina da dengue na UBS';
$terms = RagGlossary::questionTerms($question);
expect_glossary($terms === RagSearchTerms::terms($question), 'deve reutilizar exatamente a normalização segura de termos da recuperação');
expect_glossary(in_array('vacina', $terms, true) && in_array('dengue', $terms, true) && in_array('ubs', $terms, true), 'deve preservar os termos relevantes da pergunta');

$relations = RagGlossary::relationPairs($question);
$relationKeys = array_map(static fn (array $pair): string => $pair[0] . '|' . $pair[1], $relations);
expect_glossary(in_array('dengue|vacina', $relationKeys, true) && in_array('dengue|ubs', $relationKeys, true) && in_array('ubs|vacina', $relationKeys, true), 'deve gerar pares canônicos de coocorrência entre termos relevantes');

$b12Terms = RagGlossary::questionTerms('Posso tomar B12 na UBS?');
expect_glossary($b12Terms === ['b12', 'ubs'], 'deve manter termos alfanuméricos locais para que as regras interpretativas tratem a sigla');

expect_glossary(RagGlossary::relationPairs('Como eu faço?') === [], 'não deve criar relações a partir de palavras vazias');

fwrite(STDOUT, "ok glossary terms=" . count($terms) . " relations=" . count($relations) . "\n");
