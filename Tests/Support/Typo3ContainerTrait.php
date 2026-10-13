<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Support;

use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Minimal PSR-11 container for unit tests.
 *
 * TYPO3's query restrictions resolve their dependencies through
 * GeneralUtility::makeInstance(). Without a container that falls back to
 * reflection instantiation, which fails for services with constructor
 * arguments (TcaSchemaFactory needs four). Registering the few services the
 * restriction containers touch keeps these tests plain unit tests — no
 * testing-framework bootstrap required.
 */
trait Typo3ContainerTrait
{
    /**
     * Call from setUp(). Remember to call tearDownTypo3Container() in tearDown().
     */
    private function setUpTypo3Container(): void
    {
        // No TCA in unit tests — reporting "unknown table" makes every
        // restriction skip building constraints instead of blowing up.
        $tcaSchemaFactory = $this->createMock(TcaSchemaFactory::class);
        $tcaSchemaFactory->method('has')->willReturn(false);

        $services = [TcaSchemaFactory::class => $tcaSchemaFactory];

        GeneralUtility::setContainer(new class ($services) implements ContainerInterface {
            /** @param array<string, object> $services */
            public function __construct(private readonly array $services) {}

            public function get(string $id): object
            {
                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        });
    }

    private function tearDownTypo3Container(): void
    {
        GeneralUtility::purgeInstances();
    }
}
