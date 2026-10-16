<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\Command;

use Koh\WebMcp\Application\Contract\LogRetentionInterface;
use Koh\WebMcp\Domain\Contract\ToolInvocationLogRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'webmcp:cleanup-log',
    description: 'Delete old WebMCP tool invocation log entries',
)]
final class CleanupToolLogCommand extends Command
{
    public function __construct(
        private readonly ToolInvocationLogRepositoryInterface $repository,
        private readonly LogRetentionInterface $logRetention,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'days',
            'd',
            InputOption::VALUE_REQUIRED,
            'Delete entries older than this many days (default: the configured retention, extension setting logRetentionDays)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $option = $input->getOption('days');
        $days = null === $option ? $this->logRetention->days() : (int) $option;

        if ($days < 1) {
            $io->error('Days must be at least 1');

            return Command::FAILURE;
        }

        $cutoff = new \DateTimeImmutable("-{$days} days");
        $deleted = $this->repository->deleteOlderThan($cutoff);

        $io->success(sprintf('Deleted %d log entries older than %d days.', $deleted, $days));

        return Command::SUCCESS;
    }
}
