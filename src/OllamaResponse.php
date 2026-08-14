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
                'source_numbers' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                ],
            ],
            'required' => ['grounded', 'confidence', 'answer', 'source_numbers'],
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
            'confidence' => $confidence,
            'answer' => trim($data['answer']),
            'source_numbers' => $sourceNumbers,
            'error' => '',
        ];
    }

    private static function invalid(string $error): array
    {
        return [
            'valid' => false,
            'grounded' => false,
            'confidence' => 0.0,
            'answer' => '',
            'source_numbers' => [],
            'error' => $error,
        ];
    }
}
