<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Application\Service;

use Koh\WebMcp\Application\Service\ConsentService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConsentServiceTest extends TestCase
{
    #[Test]
    public function isToolAllowedReturnsTrueWhenConsentNotRequired(): void
    {
        $service = new ConsentService();
        $settings = [
            'tools' => [
                'search' => ['consent' => ['required' => false]],
            ],
        ];

        self::assertTrue($service->isToolAllowed('search', $settings));
    }

    #[Test]
    public function isToolAllowedReturnsFalseWhenConsentRequired(): void
    {
        $service = new ConsentService();
        $settings = [
            'tools' => [
                'search' => ['consent' => ['required' => true]],
            ],
        ];

        self::assertFalse($service->isToolAllowed('search', $settings));
    }

    #[Test]
    public function getConsentRequirementReturnsDefaults(): void
    {
        $service = new ConsentService();

        $requirement = $service->getConsentRequirement('search', []);

        self::assertSame('search', $requirement->toolName);
        self::assertFalse($requirement->required);
        self::assertSame('functional', $requirement->category);
        self::assertSame('native', $requirement->provider);
    }

    #[Test]
    public function getConsentRequirementReadsFromSettings(): void
    {
        $service = new ConsentService();
        $settings = [
            'consent' => ['provider' => 'klaro'],
            'tools' => [
                'search' => [
                    'consent' => [
                        'required' => true,
                        'category' => 'analytics',
                    ],
                ],
            ],
        ];

        $requirement = $service->getConsentRequirement('search', $settings);

        self::assertTrue($requirement->required);
        self::assertSame('analytics', $requirement->category);
        self::assertSame('klaro', $requirement->provider);
    }
}
