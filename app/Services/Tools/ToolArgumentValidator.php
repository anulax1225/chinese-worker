<?php

namespace App\Services\Tools;

use App\DTOs\ToolCall;

class ToolArgumentValidator
{
    /**
     * Validate tool call arguments against a schema.
     *
     * @param  array<string, mixed>  $schema  The tool schema with parameters definition
     * @return array{valid: bool, errors: array<string>}
     */
    public function validate(ToolCall $toolCall, array $schema): array
    {
        $errors = [];
        $parameters = $schema['parameters'] ?? [];
        $properties = $parameters['properties'] ?? [];
        $required = $parameters['required'] ?? [];
        $arguments = $toolCall->arguments;

        // Check required fields
        foreach ($required as $field) {
            if (! array_key_exists($field, $arguments)) {
                $errors[] = "Missing required argument: {$field}";
            } elseif ($arguments[$field] === null || $arguments[$field] === '') {
                $errors[] = "Required argument '{$field}' cannot be empty";
            }
        }

        // Validate argument types and constraints
        foreach ($arguments as $key => $value) {
            if (! isset($properties[$key])) {
                // Unknown argument - log but don't fail (AI may include extra fields)
                continue;
            }

            $propSchema = $properties[$key];
            $typeErrors = $this->validateType($key, $value, $propSchema);
            $errors = array_merge($errors, $typeErrors);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate a single argument against its type schema.
     *
     * @param  array<string, mixed>  $propSchema
     * @return array<string>
     */
    protected function validateType(string $key, mixed $value, array $propSchema): array
    {
        $errors = [];
        $expectedType = $propSchema['type'] ?? 'string';

        // Type validation
        $isValid = match ($expectedType) {
            'string' => is_string($value) || is_numeric($value),
            'integer' => is_int($value) || (is_string($value) && ctype_digit($value)),
            'number' => is_numeric($value),
            'boolean' => is_bool($value) || in_array($value, ['true', 'false', 0, 1], true),
            'array' => is_array($value),
            'object' => is_array($value) || is_object($value),
            default => true,
        };

        if (! $isValid && $value !== null) {
            $errors[] = "Argument '{$key}' expected type '{$expectedType}', got ".gettype($value);
        }

        // Enum validation
        if (isset($propSchema['enum']) && $value !== null) {
            if (! in_array($value, $propSchema['enum'], true)) {
                $allowed = implode(', ', $propSchema['enum']);
                $errors[] = "Argument '{$key}' must be one of: {$allowed}";
            }
        }

        // String length constraints
        if ($expectedType === 'string' && is_string($value)) {
            if (isset($propSchema['minLength']) && strlen($value) < $propSchema['minLength']) {
                $errors[] = "Argument '{$key}' must be at least {$propSchema['minLength']} characters";
            }
            if (isset($propSchema['maxLength']) && strlen($value) > $propSchema['maxLength']) {
                $errors[] = "Argument '{$key}' must be at most {$propSchema['maxLength']} characters";
            }
        }

        // Number range constraints
        if (in_array($expectedType, ['integer', 'number']) && is_numeric($value)) {
            if (isset($propSchema['minimum']) && $value < $propSchema['minimum']) {
                $errors[] = "Argument '{$key}' must be at least {$propSchema['minimum']}";
            }
            if (isset($propSchema['maximum']) && $value > $propSchema['maximum']) {
                $errors[] = "Argument '{$key}' must be at most {$propSchema['maximum']}";
            }
        }

        return $errors;
    }

    /**
     * Get schema for a tool by name from the available schemas.
     *
     * @param  array<int, array<string, mixed>>  $schemas
     * @return array<string, mixed>|null
     */
    public function findSchema(string $toolName, array $schemas): ?array
    {
        foreach ($schemas as $schema) {
            if (($schema['name'] ?? '') === $toolName) {
                return $schema;
            }
        }

        return null;
    }
}
