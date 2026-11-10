<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Command;

use RDLAB\UnusedFiles\Domain\DeleteOutcome;
use RDLAB\UnusedFiles\Domain\UnusedFileFilter;
use RDLAB\UnusedFiles\Service\FileDeleter;
use RDLAB\UnusedFiles\Service\ReferenceIndexStatus;
use RDLAB\UnusedFiles\Service\UnusedFileFinder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class DeleteCommand extends Command
{
    public function __construct(
        private readonly UnusedFileFinder $finder,
        private readonly FileDeleter $fileDeleter,
        private readonly ReferenceIndexStatus $referenceIndexStatus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                'Deletes FAL files without any reference in TYPO3. Without --execute the command only lists what it would delete. '
                . 'Files are moved to the storage\'s _recycler_ folder, which is created when missing. Run "referenceindex:update" first.'
            )
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Really delete the files (default is a dry run)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many files (0 = no limit)', 0)
            ->addOption('storage', null, InputOption::VALUE_REQUIRED, 'Only files of this storage uid (0 = all)', 0)
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Only files below this folder, e.g. /user_upload/', '')
            ->addOption('extension', null, InputOption::VALUE_REQUIRED, 'Only files with this extension, e.g. pdf', '')
            ->addOption('min-age-days', null, InputOption::VALUE_REQUIRED, 'Only files created at least this many days ago', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $execute = (bool)$input->getOption('execute');
        $limit = max(0, (int)$input->getOption('limit'));
        $filter = UnusedFileFilter::fromArray([
            'storage' => $input->getOption('storage'),
            'folder' => $input->getOption('folder'),
            'extension' => $input->getOption('extension'),
            'minAgeDays' => $input->getOption('min-age-days'),
        ]);

        if ($this->referenceIndexStatus->isStale()) {
            $lastUpdate = $this->referenceIndexStatus->getLastUpdate();
            $message = 'The reference index ' . ($lastUpdate === null ? 'has never been updated' : 'was last updated on ' . $lastUpdate->format('Y-m-d H:i'))
                . '. Run "referenceindex:update" first.';
            if ($execute) {
                $io->error($message . ' Refusing to delete with a stale index.');
                return Command::FAILURE;
            }
            $io->warning($message);
        }

        $candidates = [];
        foreach ($this->finder->findAll($filter, $limit) as $row) {
            $candidates[] = $row;
        }
        if ($candidates === []) {
            $io->success('No unused files match the given filter.');
            return Command::SUCCESS;
        }

        if (!$execute) {
            $io->table(
                ['uid', 'path', 'size', 'created'],
                array_map(static fn(array $row): array => [
                    (int)$row['uid'],
                    (string)$row['identifier'],
                    GeneralUtility::formatSize((int)$row['size']),
                    date('Y-m-d', (int)$row['creation_date']),
                ], $candidates)
            );
            $io->note(sprintf('Dry run: %d files would be deleted. Add --execute to delete them.', count($candidates)));
            return Command::SUCCESS;
        }

        $counts = array_fill_keys(array_map(static fn(DeleteOutcome $outcome): string => $outcome->value, DeleteOutcome::cases()), 0);
        $failures = [];
        $progress = $io->createProgressBar();
        foreach ($this->fileDeleter->deleteMatching($filter, $limit, static fn() => $progress->advance()) as $result) {
            $counts[$result->outcome->value]++;
            if ($result->outcome === DeleteOutcome::Failed) {
                $failures[] = $result->identifier . ': ' . $result->message;
            }
        }
        $progress->finish();
        $io->newLine(2);

        $io->definitionList(
            ['Deleted' => $counts[DeleteOutcome::Deleted->value]],
            ['Moved to recycler' => $counts[DeleteOutcome::MovedToRecycler->value]],
            ['Skipped, referenced meanwhile' => $counts[DeleteOutcome::SkippedReferenced->value]],
            ['Skipped, not found' => $counts[DeleteOutcome::SkippedNotFound->value]],
            ['Failed' => $counts[DeleteOutcome::Failed->value]],
        );
        if ($failures !== []) {
            $io->listing($failures);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
