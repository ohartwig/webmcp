<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\ToolHandler;

use Koh\WebMcp\Application\Contract\ToolHandlerInterface;
use Koh\WebMcp\Domain\Model\ExecutionContext;
use Koh\WebMcp\Domain\Model\ToolResult;
use TYPO3\CMS\Form\Mvc\Persistence\FormPersistenceManagerInterface;

final readonly class FormToolHandler implements ToolHandlerInterface
{
    private const int MAX_FIELD_LENGTH = 10000;

    public function __construct(
        private FormPersistenceManagerInterface $formPersistenceManager,
    ) {}

    public function getToolName(): string
    {
        return 'form';
    }

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $formIdentifier = $input['__formIdentifier'] ?? '';
        if ('' === $formIdentifier || !is_string($formIdentifier)) {
            return ToolResult::error('Form identifier is required');
        }

        // Deny-by-default: if no forms configured, reject all
        $allowedForms = $this->getAllowedForms($context);
        if ([] === $allowedForms) {
            return ToolResult::error('No forms configured for WebMCP submission');
        }
        if (!in_array($formIdentifier, $allowedForms, true)) {
            return ToolResult::error('Form not allowed');
        }

        // Load form definition
        $persistenceIdentifier = $this->findPersistenceIdentifier($formIdentifier);
        if (null === $persistenceIdentifier) {
            return ToolResult::error('Form not found');
        }

        try {
            $formDefinition = $this->formPersistenceManager->load($persistenceIdentifier);
        } catch (\Throwable) {
            return ToolResult::error('Failed to load form');
        }

        // Validate input fields against form definition
        $validationErrors = $this->validateInput($input, $formDefinition);
        if ([] !== $validationErrors) {
            return ToolResult::error('Validation failed');
        }

        // Everything above validates. Nothing below submits: no FormRuntime is
        // started, no finisher runs, so no mail is sent and no record is
        // written. Reporting success here told the caller the opposite of what
        // happened, and an assistant relaying that would tell a visitor their
        // enquiry had been sent while the data was dropped.
        //
        // Until submission is actually implemented, the honest answer is that
        // it was not. The validation result is still worth returning: a caller
        // can use it to check the input before handing the visitor to the form.
        return ToolResult::error(
            'Form submission is not implemented. The input was validated against the form '
            . 'definition and passed, but nothing was submitted, sent or stored — direct the '
            . 'user to the form itself.',
        );
    }

    /**
     * @return string[]
     */
    private function getAllowedForms(ExecutionContext $context): array
    {
        $allowedFormsRaw = $context->settings['tools']['form']['allowedForms'] ?? '';
        if ('' === $allowedFormsRaw || !is_string($allowedFormsRaw)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $allowedFormsRaw)),
            static fn(string $identifier): bool => '' !== $identifier,
        ));
    }

    private function findPersistenceIdentifier(string $formIdentifier): ?string
    {
        $forms = $this->formPersistenceManager->listForms();
        foreach ($forms as $formMeta) {
            $persistenceId = $formMeta['persistenceIdentifier'] ?? '';
            if ('' === $persistenceId) {
                continue;
            }

            try {
                $definition = $this->formPersistenceManager->load($persistenceId);
                if (($definition['identifier'] ?? '') === $formIdentifier) {
                    return $persistenceId;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $formDefinition
     *
     * @return string[]
     */
    private function validateInput(array $input, array $formDefinition): array
    {
        $errors = [];
        $fieldIds = $this->collectFieldIdentifiers($formDefinition);

        foreach ($input as $key => $value) {
            if ('__formIdentifier' === $key) {
                continue;
            }
            if (!in_array($key, $fieldIds, true)) {
                $errors[] = 'Unknown field in input';
                continue;
            }
            // Reject arrays where scalar expected
            if (is_array($value)) {
                // Only multi-select types allow arrays — simplified check
                $errors[] = 'Invalid field value type';
            }
            // Length limit for string values
            if (is_string($value) && mb_strlen($value) > self::MAX_FIELD_LENGTH) {
                $errors[] = 'Field value exceeds maximum length';
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $formDefinition
     *
     * @return string[]
     */
    private function collectFieldIdentifiers(array $formDefinition): array
    {
        $ids = [];
        $renderables = $formDefinition['renderables'] ?? [];
        foreach ($renderables as $page) {
            $pageRenderables = $page['renderables'] ?? [];
            foreach ($pageRenderables as $element) {
                $id = $element['identifier'] ?? '';
                if ('' !== $id) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }
}
