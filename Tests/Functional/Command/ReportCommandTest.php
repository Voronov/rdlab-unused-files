<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Registry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ReportCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['rdlab/unused-files'];

    private CommandTester $commandTester;
    private string $outputFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/files.csv');
        $this->commandTester = new CommandTester($this->get(CommandRegistry::class)->get('rdlab:unusedfiles:report'));
        $this->outputFile = $this->instancePath . '/unused.csv';
        @unlink($this->outputFile);
    }

    /**
     * @return list<list<string|null>>
     */
    private function readCsv(string $delimiter = ','): array
    {
        $rows = [];
        $handle = fopen($this->outputFile, 'rb');
        if ($handle === false) {
            self::fail('Cannot open ' . $this->outputFile);
        }
        while (($row = fgetcsv($handle, null, $delimiter, '"', '\\')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }

    #[Test]
    public function csvContainsHeaderAndUnusedFiles(): void
    {
        $this->get(Registry::class)->set('core', 'sys_refindex_lastUpdate', time());

        $exitCode = $this->commandTester->execute(['--output' => $this->outputFile]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $rows = $this->readCsv();
        self::assertSame(['uid', 'storage', 'path', 'folder', 'name', 'extension', 'size_bytes', 'created', 'modified', 'public_url', 'status'], $rows[0]);
        self::assertSame(['11', '10', '2', '1'], array_column(array_slice($rows, 1), 0));
        self::assertSame(['1', '1', '/user_upload/unused.pdf', '/user_upload/', 'unused.pdf', 'pdf', '100'], array_slice($rows[4], 0, 7));
        self::assertStringStartsWith('No references in TYPO3', (string)$rows[4][10]);
        self::assertStringContainsString('4 unused files', $this->commandTester->getDisplay());
        self::assertStringNotContainsString('Run "referenceindex:update" first', $this->commandTester->getDisplay());
    }

    #[Test]
    public function filterOptionsAndDelimiterAreApplied(): void
    {
        $this->commandTester->execute([
            '--output' => $this->outputFile,
            '--storage' => '1',
            '--folder' => 'user_upload',
            '--extension' => 'JPG',
            '--delimiter' => ';',
        ]);

        $rows = $this->readCsv(';');
        self::assertCount(2, $rows);
        self::assertSame('2', $rows[1][0]);
    }

    #[Test]
    public function staleReferenceIndexShowsWarning(): void
    {
        $exitCode = $this->commandTester->execute(['--output' => $this->outputFile]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('has never been updated', $this->commandTester->getDisplay());
    }

    #[Test]
    public function invalidDelimiterIsRejected(): void
    {
        $exitCode = $this->commandTester->execute(['--output' => $this->outputFile, '--delimiter' => ';;']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertFileDoesNotExist($this->outputFile);
    }

    #[Test]
    public function unwritableOutputFails(): void
    {
        $exitCode = $this->commandTester->execute(['--output' => $this->instancePath . '/does/not/exist.csv']);

        self::assertSame(Command::FAILURE, $exitCode);
    }
}
