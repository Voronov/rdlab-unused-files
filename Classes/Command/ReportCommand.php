<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Command;

use RDLAB\UnusedFiles\Domain\UnusedFileFilter;
use RDLAB\UnusedFiles\Service\CsvExporter;
use RDLAB\UnusedFiles\Service\ReferenceIndexStatus;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ReportCommand extends Command
{
    public function __construct(
        private readonly CsvExporter $csvExporter,
        private readonly ReferenceIndexStatus $referenceIndexStatus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                'Writes a CSV list of all FAL files without any reference in TYPO3 (reference index and FAL relations). '
                . 'Run "referenceindex:update" first, otherwise the result is unreliable.'
            )
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write the CSV to this file instead of STDOUT')
            ->addOption('storage', null, InputOption::VALUE_REQUIRED, 'Only files of this storage uid (0 = all)', 0)
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'Only files below this folder, e.g. /user_upload/', '')
            ->addOption('extension', null, InputOption::VALUE_REQUIRED, 'Only files with this extension, e.g. pdf', '')
            ->addOption('min-age-days', null, InputOption::VALUE_REQUIRED, 'Only files created at least this many days ago', 0)
            ->addOption('delimiter', null, InputOption::VALUE_REQUIRED, 'CSV delimiter', ',');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Messages go to STDERR so that a CSV written to STDOUT stays clean
        $io = new SymfonyStyle($input, $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output);

        $filter = UnusedFileFilter::fromArray([
            'storage' => $input->getOption('storage'),
            'folder' => $input->getOption('folder'),
            'extension' => $input->getOption('extension'),
            'minAgeDays' => $input->getOption('min-age-days'),
        ]);
        $delimiter = (string)$input->getOption('delimiter');
        if (strlen($delimiter) !== 1) {
            $io->error('The delimiter must be exactly one character.');
            return Command::INVALID;
        }

        if ($this->referenceIndexStatus->isStale()) {
            $lastUpdate = $this->referenceIndexStatus->getLastUpdate();
            $io->warning(
                'The reference index ' . ($lastUpdate === null ? 'has never been updated' : 'was last updated on ' . $lastUpdate->format('Y-m-d H:i'))
                . '. Run "referenceindex:update" first for a reliable result.'
            );
        }

        $outputPath = $input->getOption('output');
        $handle = $outputPath !== null ? @fopen((string)$outputPath, 'wb') : fopen('php://stdout', 'wb');
        if ($handle === false) {
            $io->error(sprintf('Could not open "%s" for writing.', (string)$outputPath));
            return Command::FAILURE;
        }

        $summary = $this->csvExporter->write($handle, $filter, $delimiter);
        fclose($handle);

        $io->success(sprintf(
            '%d unused files (%s)%s',
            $summary['count'],
            GeneralUtility::formatSize($summary['size']),
            $outputPath !== null ? ' written to ' . $outputPath : ''
        ));

        return Command::SUCCESS;
    }
}
