<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Command;

use RDLAB\UnusedFiles\Domain\DeleteOutcome;
use RDLAB\UnusedFiles\Service\FileDeleter;
use RDLAB\UnusedFiles\Service\RecyclerFinder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class PurgeRecyclerCommand extends Command
{
    public function __construct(
        private readonly RecyclerFinder $finder,
        private readonly FileDeleter $fileDeleter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                'Permanently deletes files that have been lying in a _recycler_ folder for at least --min-age-days days. '
                . 'Without --execute the command only lists what it would delete.'
            )
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Really delete the files (default is a dry run)')
            ->addOption('min-age-days', null, InputOption::VALUE_REQUIRED, 'Only files moved to the recycler at least this many days ago', 30)
            ->addOption('storage', null, InputOption::VALUE_REQUIRED, 'Only files of this storage uid (0 = all)', 0)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many files (0 = no limit)', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $execute = (bool)$input->getOption('execute');
        $minAgeDays = max(0, (int)$input->getOption('min-age-days'));
        $storage = max(0, (int)$input->getOption('storage'));
        $limit = max(0, (int)$input->getOption('limit'));

        $candidates = iterator_to_array($this->finder->findExpired($minAgeDays, $storage, $limit), false);
        if ($candidates === []) {
            $io->success(sprintf('No files have been in a %s folder for %d days or more.', RecyclerFinder::FOLDER_NAME, $minAgeDays));
            return Command::SUCCESS;
        }

        if (!$execute) {
            $io->table(
                ['uid', 'path', 'size', 'in recycler since'],
                array_map(static fn(array $row): array => [
                    (int)$row['uid'],
                    (string)$row['identifier'],
                    GeneralUtility::formatSize((int)$row['size']),
                    date('Y-m-d', (int)$row['tstamp']),
                ], $candidates)
            );
            $io->note(sprintf('Dry run: %d files would be deleted permanently. Add --execute to delete them.', count($candidates)));
            return Command::SUCCESS;
        }

        $deleted = 0;
        $skipped = 0;
        $failures = [];
        $progress = $io->createProgressBar(count($candidates));
        foreach ($candidates as $row) {
            $result = $this->fileDeleter->purgeFromRecycler((int)$row['uid']);
            if ($result->outcome === DeleteOutcome::Deleted) {
                $deleted++;
            } elseif ($result->outcome === DeleteOutcome::Failed) {
                $failures[] = $result->identifier . ': ' . $result->message;
            } else {
                $skipped++;
            }
            $progress->advance();
        }
        $progress->finish();
        $io->newLine(2);

        $io->definitionList(
            ['Deleted' => $deleted],
            ['Skipped' => $skipped],
            ['Failed' => count($failures)],
        );
        if ($failures !== []) {
            $io->listing($failures);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
