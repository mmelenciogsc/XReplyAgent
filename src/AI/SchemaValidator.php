<?php

declare(strict_types=1);

namespace XReplyAgent\AI;

final class SchemaValidator
{
    /**
     * @param array<string, mixed> $schema
     * @param mixed $value
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validate(array $schema, mixed $value): array
    {
        $errors = [];
        $this->validateNode($schema, $value, '$', $errors);

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $schema
     * @param mixed $value
     * @param array<int, string> $errors
     */
    private function validateNode(array $schema, mixed $value, string $path, array &$errors): void
    {
        $type = (string) ($schema['type'] ?? '');
        if ($type !== '' && !$this->matchesType($type, $value)) {
            $errors[] = sprintf('%s must be of type %s', $path, $type);
            return;
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[] = sprintf('%s must be one of the allowed values', $path);
            return;
        }

        if ($type === 'object' && is_array($value)) {
            $required = array_values(array_filter((array) ($schema['required'] ?? []), 'is_string'));
            foreach ($required as $field) {
                if (!array_key_exists($field, $value)) {
                    $errors[] = sprintf('%s.%s is required', $path, $field);
                }
            }

            $properties = (array) ($schema['properties'] ?? []);
            foreach ($properties as $field => $definition) {
                if (!array_key_exists($field, $value) || !is_array($definition)) {
                    continue;
                }
                $this->validateNode($definition, $value[$field], $path . '.' . $field, $errors);
            }

            if (($schema['additionalProperties'] ?? true) === false) {
                foreach (array_keys($value) as $field) {
                    if (!array_key_exists($field, $properties)) {
                        $errors[] = sprintf('%s.%s is not allowed', $path, (string) $field);
                    }
                }
            }
        }

        if ($type === 'array' && is_array($value)) {
            if (isset($schema['minItems']) && is_int($schema['minItems']) && count($value) < $schema['minItems']) {
                $errors[] = sprintf('%s must contain at least %d items', $path, $schema['minItems']);
            }

            if (isset($schema['maxItems']) && is_int($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = sprintf('%s must contain at most %d items', $path, $schema['maxItems']);
            }

            $items = $schema['items'] ?? null;
            if (is_array($items)) {
                foreach (array_values($value) as $index => $item) {
                    $this->validateNode($items, $item, $path . '[' . $index . ']', $errors);
                }
            }
        }
    }

    private function matchesType(string $type, mixed $value): bool
    {
        return match ($type) {
            'object' => is_array($value) && array_is_list($value) === false,
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'number' => is_int($value) || is_float($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }
}
