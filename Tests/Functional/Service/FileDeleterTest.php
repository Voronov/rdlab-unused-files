<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use RDLAB\UnusedFiles\Domain\DeleteOutcome;
use RDLAB\UnusedFiles\Service\FileDeleter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class FileDeleterTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['rdlab/unused-files'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/files.csv');
        // the test instance is shared by all tests of this class, so remove what a previous test left behind
        GeneralUtility::rmdir($this->instancePath . '/fileadmin/user_upload/_recycler_', true);
        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/user_upload');
        file_put_contents($this->instancePath . '/fileadmin/user_upload/unused.pdf', 'unused');
        file_put_contents($this->instancePath . '/fileadmin/user_upload/linked.pdf', 'linked');
    }

    #[Test]
    public function unusedFileIsDeleted(): void
    {
        $result = $this->get(FileDeleter::class)->delete(1);

        self::assertSame(DeleteOutcome::Deleted, $result->outcome);
        self::assertSame(1, $result->fileUid);
        self::assertSame('1:/user_upload/unused.pdf', $result->identifier);
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/user_upload/unused.pdf');
    }

    #[Test]
    public function unusedFileIsMovedToRecyclerFolder(): void
    {
        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/user_upload/_recycler_');

        $result = $this->get(FileDeleter::class)->delete(1);

        self::assertSame(DeleteOutcome::MovedToRecycler, $result->outcome);
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/user_upload/unused.pdf');
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/_recycler_/unused.pdf');
    }

    #[Test]
    public function referencedFileIsSkipped(): void
    {
        $result = $this->get(FileDeleter::class)->delete(3);

        self::assertSame(DeleteOutcome::SkippedReferenced, $result->outcome);
        self::assertSame('1:/user_upload/linked.pdf', $result->identifier);
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/linked.pdf');
    }

    #[Test]
    public function unknownFileUidIsSkippedAsNotFound(): void
    {
        $result = $this->get(FileDeleter::class)->delete(999);

        self::assertSame(DeleteOutcome::SkippedNotFound, $result->outcome);
        self::assertSame('', $result->identifier);
    }

    #[Test]
    public function purgeFromRecyclerSkipsFilesOutsideRecyclerFolders(): void
    {
        $result = $this->get(FileDeleter::class)->purgeFromRecycler(1);

        self::assertSame(DeleteOutcome::SkippedReferenced, $result->outcome);
        self::assertSame('not in a recycler folder', $result->message);
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/unused.pdf');
        self::assertSame(DeleteOutcome::SkippedNotFound, $this->get(FileDeleter::class)->purgeFromRecycler(999)->outcome);
    }

    #[Test]
    public function fileInExcludedFolderIsSkipped(): void
    {
        self::assertSame(DeleteOutcome::SkippedReferenced, $this->get(FileDeleter::class)->delete(6)->outcome);
    }

    #[Test]
    public function systemLogIsWrittenForBackendUser(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $this->get(FileDeleter::class)->delete(1);

        $details = $this->get(ConnectionPool::class)->getConnectionForTable('sys_log')
            ->select(['details'], 'sys_log', ['userid' => 1])
            ->fetchFirstColumn();
        self::assertContains('Unused file "%s" deleted (EXT:rdlab_unused_files)', $details);
    }
}
