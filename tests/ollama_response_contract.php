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
assert_same('direct', $valid['answer_mode'], 'A resposta fundamentada deve ser classificada como direta.');

$partial = OllamaResponse::parse([
    'response' => '{"grounded":false,"answer_mode":"partial","confidence":0.60,"answer":"A base confirma salas de vacinação, mas não confirma a disponibilidade da vacina contra dengue; é necessária confirmação humana.","source_numbers":[1,2]}',
], 2);
assert_same(true, $partial['valid'], 'Uma resposta parcialmente fundamentada deve respeitar o contrato.');
assert_same(false, $partial['grounded'], 'A resposta parcial não pode ser aprovada como diretamente fundamentada.');
assert_same('partial', $partial['answer_mode'], 'A modalidade parcial deve ser preservada.');
assert_same(0.60, $partial['confidence'], 'A confiança parcial deve ser preservada.');
assert_same([1, 2], $partial['source_numbers'], 'As fontes parciais devem ser preservadas.');

$inconsistent = OllamaResponse::parse([
    'response' => '{"grounded":true,"answer_mode":"partial","confidence":0.60,"answer":"Resposta incoerente","source_numbers":[1]}',
], 1);
assert_same(false, $inconsistent['valid'], 'Grounded=true com modalidade parcial deve ser rejeitado.');
assert_same('inconsistent_answer_mode', $inconsistent['error'], 'A inconsistência da modalidade deve ser identificada.');

$stringBoolean = OllamaResponse::parse([
    'response' => '{"grounded":"true","confidence":"95","answer":"Laboratório Municipal.","source_numbers":[1]}',
], 1);
assert_same(true, $stringBoolean['valid'], 'Representações escalares equivalentes devem ser aceitas.');
assert_same(true, $stringBoolean['grounded'], 'O booleano textual deve ser normalizado com segurança.');
assert_same(0.95, $stringBoolean['confidence'], 'A confiança percentual textual deve ser normalizada.');

$wrappedJson = OllamaResponse::parse([
    'response' => "Aqui está a resposta solicitada:\n```json\n{\"grounded\":false,\"answer_mode\":\"partial\",\"confidence\":0.55,\"answer\":\"A base confirma vacinação geral, mas não confirma a vacina específica.\",\"source_numbers\":[1]}\n```",
], 1);
assert_same(true, $wrappedJson['valid'], 'Um objeto JSON válido envolvido por texto curto deve ser recuperado.');
assert_same('partial', $wrappedJson['answer_mode'], 'A modalidade do JSON envolvido deve ser preservada.');

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
