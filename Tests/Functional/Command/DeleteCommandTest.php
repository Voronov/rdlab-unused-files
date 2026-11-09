<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class DeleteCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['rdlab/unused-files'];

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/files.csv');
        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/user_upload');
        foreach (['unused.pdf', 'metadata-only.jpg', 'deleted-ref.png', 'linked.pdf', 'unlinked.jpg', 'orphan-ref.jpg'] as $name) {
            file_put_contents($this->instancePath . '/fileadmin/user_upload/' . $name, $name);
        }
        $this->commandTester = new CommandTester($this->get(CommandRegistry::class)->get('rdlab:unusedfiles:delete'));
    }

    private function markReferenceIndexAsFresh(): void
    {
        $this->get(Registry::class)->set('core', 'sys_refindex_lastUpdate', time());
    }

    #[Test]
    public function dryRunListsFilesWithoutDeleting(): void
    {
        $this->markReferenceIndexAsFresh();

        $exitCode = $this->commandTester->execute(['--folder' => '/user_upload/']);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('/user_upload/unused.pdf', $display);
        self::assertStringNotContainsString('/user_upload/linked.pdf', $display);
        self::assertStringContainsString('Dry run: 4 files would be deleted', $display);
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/unused.pdf');
    }

    #[Test]
    public function dryRunWithStaleIndexWarnsButSucceeds(): void
    {
        $exitCode = $this->commandTester->execute(['--folder' => '/user_upload/']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('has never been updated', $this->commandTester->getDisplay());
    }

    #[Test]
    public function executeRefusesToDeleteWithStaleIndex(): void
    {
        $exitCode = $this->commandTester->execute(['--execute' => true, '--folder' => '/user_upload/']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Refusing to delete', $this->commandTester->getDisplay());
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/unused.pdf');
    }

    #[Test]
    public function executeDeletesMatchingUnusedFiles(): void
    {
        $this->markReferenceIndexAsFresh();

        $exitCode = $this->commandTester->execute(['--execute' => true, '--folder' => '/user_upload/']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertMatchesRegularExpression('/Deleted\s+4/', $this->commandTester->getDisplay());
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/user_upload/unused.pdf');
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/user_upload/metadata-only.jpg');
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/user_upload/unlinked.jpg');
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/deleted-ref.png');
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/linked.pdf');
    }

    #[Test]
    public function executeStopsAtLimit(): void
    {
        $this->markReferenceIndexAsFresh();

        $this->commandTester->execute(['--execute' => true, '--folder' => '/user_upload/', '--limit' => '1']);

        self::assertMatchesRegularExpression('/Deleted\s+1/', $this->commandTester->getDisplay());
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/user_upload/metadata-only.jpg');
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/unused.pdf');
    }

    #[Test]
    public function noMatchesReportsSuccess(): void
    {
        $this->markReferenceIndexAsFresh();

        $exitCode = $this->commandTester->execute(['--extension' => 'docx']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No unused files match', $this->commandTester->getDisplay());
    }
}
