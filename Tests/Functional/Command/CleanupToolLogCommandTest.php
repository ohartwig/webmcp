<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Functional\Command;

use Koh\WebMcp\Infrastructure\Command\CleanupToolLogCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The retention is only real if the scheduled command removes what is past
 * it and keeps what is not — against the actual table, not a mock
 * (AUD-003-F05, I-299).
 */
final class CleanupToolLogCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/webmcp'];

    private const string TABLE = 'tx_webmcp_tool_log';

    #[Test]
    public function entriesOlderThanTheRetentionAreRemovedAndNewerOnesKept(): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable(self::TABLE);
        $now = time();
        $day = 86400;

        foreach ([
            ['tool_name' => 'search', 'crdate' => $now - 40 * $day],
            ['tool_name' => 'search', 'crdate' => $now - 31 * $day],
            ['tool_name' => 'navigation', 'crdate' => $now - 29 * $day],
            ['tool_name' => 'page-content', 'crdate' => $now - 1 * $day],
        ] as $row) {
            $connection->insert(self::TABLE, $row + [
                'tstamp' => $row['crdate'],
                'site_identifier' => 'main',
                'root_page_id' => 1,
                'user_agent' => 'Test/1.0',
                'ip_hash' => str_repeat('a', 64),
                'success' => 1,
                'duration_ms' => 1,
            ]);
        }

        // The configured retention (extension default: 30 days) applies when
        // no --days is given — this is the invocation a scheduler task makes.
        $tester = new CommandTester($this->get(CleanupToolLogCommand::class));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Deleted 2 log entries older than 30 days.', $tester->getDisplay());

        $remaining = $connection->select(['tool_name'], self::TABLE, [], [], ['crdate' => 'ASC'])->fetchFirstColumn();
        self::assertSame(['navigation', 'page-content'], $remaining);
    }
}
