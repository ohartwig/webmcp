<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\Form;

final readonly class FormSchemaMapper
{
    /**
     * Map a TYPO3 Form Framework form definition to a JSON Schema.
     *
     * @param array<string, mixed> $formDefinition Parsed form YAML definition
     *
     * @return array<string, mixed> JSON Schema
     */
    public function mapToJsonSchema(array $formDefinition): array
    {
        $properties = [];
        $required = [];

        $renderables = $formDefinition['renderables'] ?? [];
        foreach ($renderables as $page) {
            $pageRenderables = $page['renderables'] ?? [];
            foreach ($pageRenderables as $element) {
                $elementId = $element['identifier'] ?? '';
                if ('' === $elementId) {
                    continue;
                }

                $schema = $this->mapElementToSchema($element);
                if ([] !== $schema) {
                    $properties[$elementId] = $schema;
                }

                if ($this->isRequired($element)) {
                    $required[] = $elementId;
                }
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ([] !== $required) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $element
     *
     * @return array<string, mixed>
     */
    private function mapElementToSchema(array $element): array
    {
        $type = $element['type'] ?? '';
        $label = $element['label'] ?? '';

        $schema = match ($type) {
            'Text', 'Textarea', 'Hidden' => ['type' => 'string'],
            'Number' => ['type' => 'number'],
            'Checkbox' => ['type' => 'boolean'],
            'EmailAddress' => ['type' => 'string', 'format' => 'email'],
            'Telephone' => ['type' => 'string', 'format' => 'tel'],
            'Url' => ['type' => 'string', 'format' => 'uri'],
            'DatePicker' => ['type' => 'string', 'format' => 'date'],
            'SingleSelect', 'RadioButton' => $this->mapSelectElement($element),
            'MultiSelect', 'MultiCheckbox' => $this->mapMultiSelectElement($element),
            'FileUpload', 'ImageUpload' => ['type' => 'string', 'description' => 'File upload (base64 encoded)'],
            'StaticText', 'ContentElement' => [],
            default => ['type' => 'string'],
        };

        if ([] !== $schema && '' !== $label) {
            $schema['description'] = $label;
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $element
     *
     * @return array<string, mixed>
     */
    private function mapSelectElement(array $element): array
    {
        $options = $element['properties']['options'] ?? [];
        $enumValues = array_map(
            static fn($key) => (string) $key,
            array_keys($options)
        );

        $schema = ['type' => 'string'];
        if ([] !== $enumValues) {
            $schema['enum'] = $enumValues;
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $element
     *
     * @return array<string, mixed>
     */
    private function mapMultiSelectElement(array $element): array
    {
        $options = $element['properties']['options'] ?? [];
        $enumValues = array_map(
            static fn($key) => (string) $key,
            array_keys($options)
        );

        $items = ['type' => 'string'];
        if ([] !== $enumValues) {
            $items['enum'] = $enumValues;
        }

        return [
            'type' => 'array',
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $element
     */
    private function isRequired(array $element): bool
    {
        $validators = $element['validators'] ?? [];
        foreach ($validators as $validator) {
            if (($validator['identifier'] ?? '') === 'NotEmpty') {
                return true;
            }
        }

        return false;
    }
}
