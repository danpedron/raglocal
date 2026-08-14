<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/OllamaResponse.php';

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nEsperado: " . var_export($expected, true) . "\nObtido: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$valid = OllamaResponse::parse([
    'response' => '{"grounded":true,"confidence":0.95,"answer":"FUJAMA é a Fundação Jaraguaense de Meio Ambiente.","source_numbers":[1]}',
], 2);
assert_same(true, $valid['valid'], 'A resposta fundamentada deve respeitar o contrato.');
assert_same(true, $valid['grounded'], 'A evidência direta deve permanecer fundamentada.');
assert_same(0.95, $valid['confidence'], 'A confiança deve ser preservada.');
assert_same([1], $valid['source_numbers'], 'A fonte citada deve ser preservada.');

$debugOutput = OllamaResponse::parse([
    'response' => '{"input":"O que significa FUJAMA?","context":[{"content":"Fundação Jaraguaense de Meio Ambiente - Fujama"}]}',
], 1);
assert_same(false, $debugOutput['valid'], 'Uma saída de depuração sem contrato não pode ser aceita.');
assert_same('invalid_contract', $debugOutput['error'], 'A saída de depuração deve indicar contrato inválido.');
assert_same('', $debugOutput['answer'], 'A saída de depuração não pode aparecer como rascunho administrativo.');

$thinkingOnly = OllamaResponse::parse([
    'thinking' => '{"grounded":true,"confidence":0.95,"answer":"Não usar raciocínio interno","source_numbers":[1]}',
], 1);
assert_same(false, $thinkingOnly['valid'], 'O raciocínio interno não pode ser usado como resposta.');
assert_same('empty_response', $thinkingOnly['error'], 'Ausência de resposta final deve ser identificada.');

echo "ollama response contract: ok\n";
