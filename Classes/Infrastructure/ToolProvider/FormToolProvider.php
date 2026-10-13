<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\ToolProvider;

use Koh\WebMcp\Domain\Contract\ToolProviderInterface;
use Koh\WebMcp\Domain\Model\ToolAnnotations;
use Koh\WebMcp\Domain\Model\ToolDefinition;
use Koh\WebMcp\Infrastructure\Form\FormSchemaMapper;
use TYPO3\CMS\Form\Mvc\Persistence\FormPersistenceManagerInterface;

final readonly class FormToolProvider implements ToolProviderInterface
{
    public function __construct(
        private FormPersistenceManagerInterface $formPersistenceManager,
        private FormSchemaMapper $formSchemaMapper,
    ) {}

    public function getIdentifier(): string
    {
        return 'form';
    }

    /**
     * @return ToolDefinition[]
     */
    public function getTools(array $settings = []): array
    {
        // Advertise only what the handler would accept. The handler is
        // deny-by-default on this same list; the provider used to ignore it and
        // offered every form in the installation, so an agent was told it could
        // "Submit the form X" for forms the site had never allowed — and only
        // found out after collecting the visitor's data.
        $allowedForms = $this->allowedForms($settings);
        if ([] === $allowedForms) {
            return [];
        }

        $tools = [];
        $forms = $this->formPersistenceManager->listForms();

        foreach ($forms as $formMeta) {
            $identifier = $formMeta['persistenceIdentifier'] ?? '';
            if ('' === $identifier) {
                continue;
            }

            try {
                $formDefinition = $this->formPersistenceManager->load($identifier);
            } catch (\Throwable) {
                continue;
            }

            $formId = $formDefinition['identifier'] ?? '';
            if ('' === $formId || !in_array($formId, $allowedForms, true)) {
                continue;
            }

            $inputSchema = $this->formSchemaMapper->mapToJsonSchema($formDefinition);
            $label = $formDefinition['label'] ?? $formId;

            // Sanitize form identifier to satisfy WebMCP tool name regex:
            // [A-Za-z0-9_.-]{1,128}. TYPO3 form identifiers are typically safe,
            // but defensively normalize and truncate to stay within the 128-char
            // budget after the `form-` prefix.
            $safeFormId = preg_replace('/[^A-Za-z0-9_.\-]/', '_', $formId) ?? '';
            if ('' === $safeFormId) {
                continue;
            }
            $toolName = 'form-' . substr($safeFormId, 0, 123);

            $tools[] = new ToolDefinition(
                name: $toolName,
                description: sprintf('Submit the form "%s"', $label),
                inputSchema: $inputSchema,
                annotations: new ToolAnnotations(
                    readOnlyHint: false,
                    untrustedContentHint: false,
                ),
                title: sprintf('Form: %s', $label),
            );
        }

        return $tools;
    }

    /**
     * Reads the same allow-list the handler enforces, parsed the same way.
     *
     * @param array<string, mixed> $settings
     *
     * @return string[]
     */
    private function allowedForms(array $settings): array
    {
        $raw = $settings['tools']['form']['allowedForms'] ?? '';
        if (!is_string($raw) || '' === $raw) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn(string $identifier): bool => '' !== $identifier,
        ));
    }
}
