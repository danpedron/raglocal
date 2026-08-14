<?php

declare(strict_types=1);

final class OllamaResponse
{
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'grounded' => ['type' => 'boolean'],
                'confidence' => ['type' => 'number'],
                'answer' => ['type' => 'string'],
                'answer_mode' => [
                    'type' => 'string',
                    'enum' => ['direct', 'partial', 'insufficient'],
                ],
                'source_numbers' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                ],
            ],
            'required' => ['grounded', 'confidence', 'answer', 'answer_mode', 'source_numbers'],
            'additionalProperties' => false,
        ];
    }

    public static function parse(array $response, int $sourceCount): array
    {
        $text = trim((string) ($response['response'] ?? ''));
        if ($text === '') {
            return self::invalid('empty_response');
        }

        $text = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $text ?? '');
        $data = json_decode((string) $text, true);
        if (!is_array($data)) {
            $data = self::extractContractObject((string) $text);
        }
        if (!is_array($data)) {
            return self::invalid('invalid_json');
        }

        foreach (['grounded', 'confidence', 'answer', 'source_numbers'] as $key) {
            if (!array_key_exists($key, $data)) {
                return self::invalid('invalid_contract');
            }
        }
        $grounded = $data['grounded'];
        if (is_string($grounded)) {
            $normalizedGrounded = strtolower(trim($grounded));
            if ($normalizedGrounded === 'true' || $normalizedGrounded === '1') {
                $grounded = true;
            } elseif ($normalizedGrounded === 'false' || $normalizedGrounded === '0') {
                $grounded = false;
            }
        }
        if (!is_bool($grounded) || !is_numeric($data['confidence']) || !is_string($data['answer']) || !is_array($data['source_numbers'])) {
            return self::invalid('invalid_contract');
        }

        $confidence = (float) $data['confidence'];
        if ($confidence > 1 && $confidence <= 100) {
            $confidence /= 100;
        }
        $confidence = max(0.0, min(1.0, $confidence));
        $answerMode = $data['answer_mode'] ?? null;
        if ($answerMode === null) {
            // Compatibility with older Ollama outputs: a non-grounded answer
            // that cites evidence is treated as partial, never as approved.
            $hasCitedEvidence = is_array($data['source_numbers']) && count($data['source_numbers']) > 0;
            $hasAnswer = is_string($data['answer']) && trim($data['answer']) !== '';
            $answerMode = (!$grounded && $hasAnswer && $hasCitedEvidence) ? 'partial' : ($grounded ? 'direct' : 'insufficient');
        }
        if (!is_string($answerMode) || !in_array($answerMode, ['direct', 'partial', 'insufficient'], true)) {
            return self::invalid('invalid_contract');
        }
        if (($answerMode === 'direct' && !$grounded) || ($answerMode !== 'direct' && $grounded)) {
            return self::invalid('inconsistent_answer_mode');
        }
        $sourceNumbers = [];
        foreach ($data['source_numbers'] as $number) {
            if (!is_int($number) && !(is_string($number) && ctype_digit($number))) {
                return self::invalid('invalid_contract');
            }
            $number = (int) $number;
            if ($number >= 1 && $number <= $sourceCount && !in_array($number, $sourceNumbers, true)) {
                $sourceNumbers[] = $number;
            }
        }

        return [
            'valid' => true,
            'grounded' => $grounded,
            'answer_mode' => $answerMode,
            'confidence' => $confidence,
            'answer' => trim($data['answer']),
            'source_numbers' => $sourceNumbers,
            'error' => '',
        ];
    }

    /**
     * Accept a valid contract object surrounded by a short model preamble or
     * markdown fence, but never repair malformed JSON or accept arbitrary text.
     * @return array<string, mixed>|null
     */
    private static function extractContractObject(string $text): ?array
    {
        $candidates = [];
        $length = strlen($text);
        $start = null;
        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($index = 0; $index < $length; $index++) {
            $character = $text[$index];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($character === '"') {
                $inString = true;
                continue;
            }
            if ($character === '{') {
                if ($depth === 0) {
                    $start = $index;
                }
                $depth++;
            } elseif ($character === '}' && $depth > 0) {
                $depth--;
                if ($depth === 0 && $start !== null) {
                    $candidate = json_decode(substr($text, $start, $index - $start + 1), true);
                    if (is_array($candidate)) {
                        $candidates[] = $candidate;
                    }
                    $start = null;
                }
            }
        }

        $fallback = null;
        foreach ($candidates as $candidate) {
            $hasContractKey = isset($candidate['grounded']) || isset($candidate['answer']) || isset($candidate['source_numbers']) || isset($candidate['confidence']);
            if ($hasContractKey && $fallback === null) {
                $fallback = $candidate;
            }
            if (isset($candidate['grounded'], $candidate['confidence'], $candidate['answer'], $candidate['source_numbers'])) {
                return $candidate;
            }
        }

        return $fallback;
    }

    private static function invalid(string $error): array
    {
        return [
            'valid' => false,
            'grounded' => false,
            'answer_mode' => 'insufficient',
            'confidence' => 0.0,
            'answer' => '',
            'source_numbers' => [],
            'error' => $error,
        ];
    }
}
