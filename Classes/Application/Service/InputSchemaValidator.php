<?php

declare(strict_types=1);

namespace Koh\WebMcp\Application\Service;

/**
 * Checks a decoded request body against a tool's advertised input schema.
 *
 * A tool publishes its `inputSchema` to the browser and to the discovery
 * endpoints; until now nothing enforced it, so the schema was documentation
 * and every handler validated on its own, or did not. This validator covers
 * the subset of JSON Schema the tools in this estate use — types, required
 * and additional properties, enum, string lengths, numeric bounds, array
 * sizes and nested objects — and ignores keywords it does not know, so an
 * unusual schema loosens the check rather than breaking the tool.
 *
 * Violations name the path and the rule, never the value: they go back to
 * the caller in a 400 response, and a response must not echo input.
 */
final readonly class InputSchemaValidator
{
    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string> violations as "path: rule"; empty when the input conforms
     */
    public function validate(array $schema, mixed $input, string $path = 'input'): array
    {
        $violations = [];

        $type = $schema['type'] ?? null;
        if (null !== $type && !$this->matchesType($type, $input)) {
            $expected = is_array($type) ? implode(' or ', array_map('strval', $type)) : (string) $type;
            $violations[] = sprintf('%s: must be of type %s', $path, $expected);

            // The remaining keywords assume the type; reporting them on a
            // value of the wrong type would only add noise.
            return $violations;
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($input, $schema['enum'], true)) {
            $violations[] = sprintf('%s: must be one of the allowed values', $path);
        }

        if (is_string($input)) {
            $violations = [...$violations, ...$this->validateString($schema, $input, $path)];
        }

        if (is_int($input) || is_float($input)) {
            $violations = [...$violations, ...$this->validateNumber($schema, $input, $path)];
        }

        if (is_array($input)) {
            $violations = array_is_list($input) && [] !== $input
                ? [...$violations, ...$this->validateArray($schema, $input, $path)]
                : [...$violations, ...$this->validateObject($schema, $input, $path)];
        }

        return $violations;
    }

    private function matchesType(mixed $type, mixed $value): bool
    {
        if (is_array($type)) {
            foreach ($type as $candidate) {
                if ($this->matchesType($candidate, $value)) {
                    return true;
                }
            }

            return false;
        }

        return match ($type) {
            'object' => is_array($value) && ([] === $value || !array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            // JSON has no integer type of its own: 10.0 decodes to a float
            // and is an integer in the schema's sense.
            'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => null === $value,
            default => true,
        };
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private function validateString(array $schema, string $value, string $path): array
    {
        $violations = [];
        $length = mb_strlen($value);

        if (isset($schema['minLength']) && $length < (int) $schema['minLength']) {
            $violations[] = sprintf('%s: must be at least %d characters', $path, (int) $schema['minLength']);
        }
        if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
            $violations[] = sprintf('%s: must be at most %d characters', $path, (int) $schema['maxLength']);
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private function validateNumber(array $schema, int|float $value, string $path): array
    {
        $violations = [];

        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            $violations[] = sprintf('%s: must be at least %s', $path, (string) $schema['minimum']);
        }
        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            $violations[] = sprintf('%s: must be at most %s', $path, (string) $schema['maximum']);
        }
        if (isset($schema['exclusiveMinimum']) && $value <= $schema['exclusiveMinimum']) {
            $violations[] = sprintf('%s: must be greater than %s', $path, (string) $schema['exclusiveMinimum']);
        }
        if (isset($schema['exclusiveMaximum']) && $value >= $schema['exclusiveMaximum']) {
            $violations[] = sprintf('%s: must be less than %s', $path, (string) $schema['exclusiveMaximum']);
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<mixed>          $value
     *
     * @return list<string>
     */
    private function validateArray(array $schema, array $value, string $path): array
    {
        $violations = [];
        $count = count($value);

        if (isset($schema['minItems']) && $count < (int) $schema['minItems']) {
            $violations[] = sprintf('%s: must have at least %d items', $path, (int) $schema['minItems']);
        }
        if (isset($schema['maxItems']) && $count > (int) $schema['maxItems']) {
            $violations[] = sprintf('%s: must have at most %d items', $path, (int) $schema['maxItems']);
        }

        $items = $schema['items'] ?? null;
        if (is_array($items)) {
            foreach ($value as $index => $item) {
                $violations = [...$violations, ...$this->validate($items, $item, sprintf('%s[%d]', $path, $index))];
            }
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<mixed>         $value
     *
     * @return list<string>
     */
    private function validateObject(array $schema, array $value, string $path): array
    {
        $violations = [];

        // Contexts advertise `properties` as an empty stdClass so that it
        // serialises to `{}`; here it is simply "no properties".
        $properties = $schema['properties'] ?? [];
        if ($properties instanceof \stdClass) {
            $properties = (array) $properties;
        }
        if (!is_array($properties)) {
            $properties = [];
        }

        foreach ((array) ($schema['required'] ?? []) as $name) {
            if (is_string($name) && !array_key_exists($name, $value)) {
                $violations[] = sprintf('%s: missing required property "%s"', $path, $name);
            }
        }

        foreach ($properties as $name => $propertySchema) {
            if (!is_string($name) || !is_array($propertySchema) || !array_key_exists($name, $value)) {
                continue;
            }
            $violations = [...$violations, ...$this->validate($propertySchema, $value[$name], $path . '.' . $name)];
        }

        if (false === ($schema['additionalProperties'] ?? null)) {
            foreach ($value as $name => $unused) {
                if (!array_key_exists($name, $properties)) {
                    // The name is the caller's; it is not repeated.
                    $violations[] = sprintf('%s: additional properties are not allowed', $path);
                    break;
                }
            }
        }

        return $violations;
    }
}
