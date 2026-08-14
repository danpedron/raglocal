<?php

declare(strict_types=1);

function import_public_function(string $name): void
{
    $source = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
    $pattern = '/function\s+' . preg_quote($name, '/') . '\s*\([^\n]*\)\s*(?::\s*[^\n{]+)?\s*\{.*?\n\}\n\n(?=function\s)/s';
    if (preg_match($pattern, $source, $match) !== 1) {
        throw new RuntimeException('Não foi possível carregar a função ' . $name . ' para o teste.');
    }
    eval($match[0]);
}

foreach (['normalize_document_text', 'rag_heading', 'rag_sections', 'split_rag_body'] as $function) {
    import_public_function($function);
}

$fixture = dirname(__DIR__) . '/tests/fixtures/contexto-servicos-separadores.md';
$text = (string) file_get_contents($fixture);
$sections = rag_sections(normalize_document_text($text));

$byHeading = [];
foreach ($sections as $section) {
    $byHeading[(string) $section['heading']] = (string) $section['body'];
}

if (!isset($byHeading['SERVIÇO: Unidades Básicas de Saúde'])) {
    throw new RuntimeException('A seção de Unidades Básicas de Saúde não foi separada.');
}
if (!str_contains($byHeading['SERVIÇO: Unidades Básicas de Saúde'], 'vacinas')) {
    throw new RuntimeException('O conteúdo de vacinação não permaneceu na seção de UBS.');
}
if (isset($byHeading['SERVIÇO: Autorização para Corte Eventual de Árvores Nativas']) === false) {
    throw new RuntimeException('A seção de autorização de corte de árvores não foi separada.');
}
if (str_contains($byHeading['SERVIÇO: Unidades Básicas de Saúde'], 'Autorização para Corte Eventual')) {
    throw new RuntimeException('Serviços distintos foram agrupados no mesmo chunk.');
}

$chunks = split_rag_body($byHeading['SERVIÇO: Unidades Básicas de Saúde']);
if (!$chunks || !str_contains(implode("\n", $chunks), 'vacinas')) {
    throw new RuntimeException('A fragmentação removeu a evidência de vacinação.');
}

printf("ok sections=%d ubs_chunks=%d\n", count($sections), count($chunks));
