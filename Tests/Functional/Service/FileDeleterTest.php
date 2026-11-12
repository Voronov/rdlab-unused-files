<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use RDLAB\UnusedFiles\Domain\DeleteOutcome;
use RDLAB\UnusedFiles\Domain\DeleteResult;
use RDLAB\UnusedFiles\Domain\UnusedFileFilter;
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
        GeneralUtility::rmdir($this->instancePath . '/fileadmin/_recycler_', true);
        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/user_upload');
        file_put_contents($this->instancePath . '/fileadmin/user_upload/unused.pdf', 'unused');
        file_put_contents($this->instancePath . '/fileadmin/user_upload/linked.pdf', 'linked');
    }

    #[Test]
    public function unusedFileIsMovedToRecyclerFolderCreatedInTheStorageRoot(): void
    {
        $result = $this->get(FileDeleter::class)->delete(1);

        self::assertSame(DeleteOutcome::MovedToRecycler, $result->outcome);
        self::assertSame(1, $result->fileUid);
        self::assertSame('1:/user_upload/unused.pdf', $result->identifier);
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/user_upload/unused.pdf');
        self::assertFileExists($this->instancePath . '/fileadmin/_recycler_/unused.pdf');
    }

    #[Test]
    public function unusedFileIsMovedToRecyclerFolder(): void
    {
        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/user_upload/_recycler_');

        $result = $this->get(FileDeleter::class)->delete(1);

        self::assertSame(DeleteOutcome::MovedToRecycler, $result->outcome);
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/user_upload/unused.pdf');
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/_recycler_/unused.pdf');
        self::assertDirectoryDoesNotExist($this->instancePath . '/fileadmin/_recycler_');
        // the moved file is indexed again at its new place, FAL removed the old entry
        $identifiers = $this->get(ConnectionPool::class)->getConnectionForTable('sys_file')
            ->select(['identifier'], 'sys_file', ['storage' => 1, 'name' => 'unused.pdf'])
            ->fetchFirstColumn();
        self::assertSame(['/user_upload/_recycler_/unused.pdf'], $identifiers);
    }

    #[Test]
    public function fileMovedToNestedRecyclerFolderIsNotDeletedAgain(): void
    {
        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/user_upload/_recycler_');
        $this->get(FileDeleter::class)->delete(1);
        $movedUid = (int)$this->get(ConnectionPool::class)->getConnectionForTable('sys_file')
            ->select(['uid'], 'sys_file', ['identifier' => '/user_upload/_recycler_/unused.pdf'])
            ->fetchOne();

        $result = $this->get(FileDeleter::class)->delete($movedUid);

        self::assertSame(DeleteOutcome::SkippedReferenced, $result->outcome);
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/_recycler_/unused.pdf');
    }

    #[Test]
    public function deleteMatchingPassesOverFilesThatCannotBeDeleted(): void
    {
        // the first matches by path: /docs/second.pdf in the read-only storage 2, /other/Old_Report.pdf missing on disk
        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/docs');
        file_put_contents($this->instancePath . '/fileadmin/docs/second.pdf', 'second');
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_storage')
            ->update('sys_file_storage', ['is_writable' => 0], ['uid' => 2]);

        $results = $this->get(FileDeleter::class)->deleteMatching(new UnusedFileFilter(), 2);

        self::assertSame(
            [[11, DeleteOutcome::Failed], [10, DeleteOutcome::Deleted], [2, DeleteOutcome::Deleted]],
            array_map(static fn(DeleteResult $result): array => [$result->fileUid, $result->outcome], $results)
        );
        GeneralUtility::rmdir($this->instancePath . '/fileadmin/docs', true);
    }

    #[Test]
    public function deleteMatchingWithoutLimitDeletesAllMatches(): void
    {
        $results = $this->get(FileDeleter::class)->deleteMatching(new UnusedFileFilter(folder: '/user_upload/'));

        self::assertSame([2, 20, 19, 1], array_map(static fn(DeleteResult $result): int => $result->fileUid, $results));
        self::assertFileExists($this->instancePath . '/fileadmin/_recycler_/unused.pdf');
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
    public function deletionFailureIsReported(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_storage')
            ->update('sys_file_storage', ['is_writable' => 0], ['uid' => 1]);

        $result = $this->get(FileDeleter::class)->delete(1);

        self::assertSame(DeleteOutcome::Failed, $result->outcome);
        self::assertSame('1:/user_upload/unused.pdf', $result->identifier);
        self::assertSame('Could not create the _recycler_ folder: You are not allowed to create directories in the folder "/"', $result->message);
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/unused.pdf');
        self::assertDirectoryDoesNotExist($this->instancePath . '/fileadmin/_recycler_');
    }

    #[Test]
    public function fileOfANonBrowsableStorageIsNotDeleted(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_storage')
            ->update('sys_file_storage', ['is_browsable' => 0], ['uid' => 1]);

        $result = $this->get(FileDeleter::class)->delete(1);

        self::assertSame(DeleteOutcome::Failed, $result->outcome);
        self::assertSame('The storage is offline or not browsable', $result->message);
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/unused.pdf');
    }

    #[Test]
    public function moveThatCannotBeRecordedForRestoringIsReported(): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('tx_rdlabunusedfiles_recycled');
        $connection->executeStatement('ALTER TABLE tx_rdlabunusedfiles_recycled RENAME TO tx_rdlabunusedfiles_recycled_away');
        try {
            $result = $this->get(FileDeleter::class)->delete(1);
        } finally {
            $connection->executeStatement('ALTER TABLE tx_rdlabunusedfiles_recycled_away RENAME TO tx_rdlabunusedfiles_recycled');
        }

        self::assertSame(DeleteOutcome::Failed, $result->outcome);
        self::assertStringStartsWith('Moved to the recycler folder, but not recorded for restoring: ', $result->message);
        self::assertFileExists($this->instancePath . '/fileadmin/_recycler_/unused.pdf');
    }

    #[Test]
    public function fileMissingOnDiskIsRemovedFromTheIndex(): void
    {
        unlink($this->instancePath . '/fileadmin/user_upload/unused.pdf');

        $result = $this->get(FileDeleter::class)->delete(1);

        self::assertSame(DeleteOutcome::Deleted, $result->outcome);
        self::assertSame(0, $this->get(ConnectionPool::class)->getConnectionForTable('sys_file')->count('uid', 'sys_file', ['uid' => 1]));
    }

    #[Test]
    public function fileInFolderMissingOnDiskIsRemovedFromTheIndex(): void
    {
        GeneralUtility::rmdir($this->instancePath . '/fileadmin/user_upload', true);

        $result = $this->get(FileDeleter::class)->delete(1);

        self::assertSame(DeleteOutcome::Deleted, $result->outcome);
        self::assertSame(0, $this->get(ConnectionPool::class)->getConnectionForTable('sys_file')->count('uid', 'sys_file', ['uid' => 1]));
        self::assertDirectoryDoesNotExist($this->instancePath . '/fileadmin/_recycler_');
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
        self::assertContains('Unused file "%s" moved to recycler (EXT:rdlab_unused_files)', $details);
    }
}
