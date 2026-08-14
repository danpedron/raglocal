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

$vaccineSource = [['content' => 'A unidade de saúde realiza vacinas para a população.']];
if (!RagSearchTerms::hasDirectEvidenceOverlap('Como faço para tomar vacina?', $vaccineSource)) {
    throw new RuntimeException('O retry focado deve reconhecer vacina como evidência direta de vacinas.');
}

$treeSource = [['content' => 'Autorização Ambiental para corte eventual de árvores nativas. Requerimentos passam por análise da documentação.']];
if (!RagSearchTerms::hasDirectEvidenceOverlap('Como faço para solicitar corte de árvores?', $treeSource)) {
    throw new RuntimeException('O retry focado deve reconhecer corte e árvores como evidência direta.');
}

if (RagSearchTerms::hasDirectEvidenceOverlap('Como eu faço para isso?', [['content' => 'Conteúdo administrativo genérico']])) {
    throw new RuntimeException('Perguntas sem termos informativos não podem acionar retry por sobreposição.');
}

fwrite(STDOUT, "OK: termos de busca por prefixo e sobreposição direta\n");
