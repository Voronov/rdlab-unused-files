<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PurgeRecyclerCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['rdlab/unused-files'];

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/recycler.csv');
        GeneralUtility::rmdir($this->instancePath . '/fileadmin', true);
        foreach (['user_upload/_recycler_/old.pdf', 'user_upload/_recycler_/new.pdf', 'user_upload/kept.pdf', '_recycler_/root-old.jpg'] as $path) {
            GeneralUtility::mkdir_deep(dirname($this->instancePath . '/fileadmin/' . $path));
            file_put_contents($this->instancePath . '/fileadmin/' . $path, $path);
        }
        $this->commandTester = new CommandTester($this->get(CommandRegistry::class)->get('rdlab:unusedfiles:purgerecycler'));
    }

    /**
     * @return list<int>
     */
    private function remainingFileUids(): array
    {
        return array_map('intval', $this->get(ConnectionPool::class)->getConnectionForTable('sys_file')
            ->select(['uid'], 'sys_file', [], [], ['uid' => 'ASC'])
            ->fetchFirstColumn());
    }

    #[Test]
    public function dryRunListsExpiredFilesWithoutDeleting(): void
    {
        $exitCode = $this->commandTester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('/user_upload/_recycler_/old.pdf', $display);
        self::assertStringContainsString('/_recycler_/root-old.jpg', $display);
        self::assertStringNotContainsString('new.pdf', $display);
        self::assertStringContainsString('Dry run: 2 files would be deleted permanently', $display);
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/_recycler_/old.pdf');
    }

    #[Test]
    public function executeDeletesExpiredFilesPermanently(): void
    {
        $exitCode = $this->commandTester->execute(['--execute' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertMatchesRegularExpression('/Deleted\s+2/', $this->commandTester->getDisplay());
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/user_upload/_recycler_/old.pdf');
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/_recycler_/root-old.jpg');
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/_recycler_/new.pdf');
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/kept.pdf');
        self::assertSame([2, 3, 5], $this->remainingFileUids());
    }

    #[Test]
    public function executeStopsAtLimit(): void
    {
        $this->commandTester->execute(['--execute' => true, '--limit' => '1']);

        self::assertMatchesRegularExpression('/Deleted\s+1/', $this->commandTester->getDisplay());
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/_recycler_/root-old.jpg');
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/_recycler_/old.pdf');
    }

    #[Test]
    public function noMatchesReportsSuccess(): void
    {
        $exitCode = $this->commandTester->execute(['--storage' => '2']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No files have been in a _recycler_ folder for 30 days or more', $this->commandTester->getDisplay());
    }
}
