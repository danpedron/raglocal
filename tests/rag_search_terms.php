<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/RagSearchTerms.php';

function assert_same_value(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nEsperado: " . $expected . "\nRecebido: " . $actual);
    }
}

assert_same_value('vacina*', RagSearchTerms::booleanPrefixQuery('Como faço para tomar vacina?'), 'A busca deve preservar o radical de vacina.');
assert_same_value('laboratório* exames* diagnóstico* monitoramento* doenças*', RagSearchTerms::booleanPrefixQuery('Laboratório exames diagnóstico monitoramento doenças'), 'A busca deve manter os termos informativos.');
assert_same_value('', RagSearchTerms::booleanPrefixQuery('Como eu faço para isso?'), 'Perguntas sem termos informativos não devem gerar consulta booleana ampla.');

fwrite(STDOUT, "OK: termos de busca por prefixo\n");
