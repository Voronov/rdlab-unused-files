<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use RDLAB\UnusedFiles\Service\FileDeleter;
use RDLAB\UnusedFiles\Service\FileRestorer;
use RDLAB\UnusedFiles\Service\RecycledFileRepository;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class FileRestorerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['rdlab/unused-files'];

    private int $entryUid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/files.csv');
        // the test instance is shared by all tests of this class, so start from a clean folder
        GeneralUtility::rmdir($this->instancePath . '/fileadmin/user_upload', true);
        GeneralUtility::rmdir($this->instancePath . '/fileadmin/_recycler_', true);
        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/user_upload');
        file_put_contents($this->instancePath . '/fileadmin/user_upload/unused.pdf', 'unused');

        $this->get(FileDeleter::class)->delete(1);
        $this->entryUid = (int)$this->get(RecycledFileRepository::class)->findPage(1, 10)[0]['uid'];
    }

    #[Test]
    public function deletingRecordsWhereTheFileCameFrom(): void
    {
        $entry = $this->get(RecycledFileRepository::class)->findByUid($this->entryUid);

        self::assertNotNull($entry);
        self::assertSame(1, (int)$entry['storage']);
        self::assertSame('/user_upload/unused.pdf', $entry['original_identifier']);
        self::assertSame('/_recycler_/unused.pdf', $entry['recycler_identifier']);
        self::assertSame('unused.pdf', $entry['name']);
        self::assertSame(100, (int)$entry['size']);
    }

    #[Test]
    public function restoreMovesTheFileBackToItsOriginalFolder(): void
    {
        $result = $this->get(FileRestorer::class)->restore($this->entryUid);

        self::assertTrue($result->restored, $result->message);
        self::assertSame('1:/user_upload/unused.pdf', $result->identifier);
        self::assertStringEqualsFile($this->instancePath . '/fileadmin/user_upload/unused.pdf', 'unused');
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/_recycler_/unused.pdf');
        self::assertSame(0, $this->get(RecycledFileRepository::class)->countAll());
    }

    #[Test]
    public function restoreCreatesTheOriginalFolderWhenItIsGone(): void
    {
        GeneralUtility::rmdir($this->instancePath . '/fileadmin/user_upload', true);

        $result = $this->get(FileRestorer::class)->restore($this->entryUid);

        self::assertTrue($result->restored, $result->message);
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/unused.pdf');
    }

    #[Test]
    public function restoreDoesNotOverwriteAFileThatTookTheOldPlace(): void
    {
        file_put_contents($this->instancePath . '/fileadmin/user_upload/unused.pdf', 'newer');

        $result = $this->get(FileRestorer::class)->restore($this->entryUid);

        self::assertFalse($result->restored);
        self::assertStringEqualsFile($this->instancePath . '/fileadmin/user_upload/unused.pdf', 'newer');
        self::assertFileExists($this->instancePath . '/fileadmin/_recycler_/unused.pdf');
        self::assertSame(1, $this->get(RecycledFileRepository::class)->countAll());
    }

    #[Test]
    public function restoreDropsTheEntryOfAFileThatLeftTheRecycler(): void
    {
        unlink($this->instancePath . '/fileadmin/_recycler_/unused.pdf');

        $result = $this->get(FileRestorer::class)->restore($this->entryUid);

        self::assertFalse($result->restored);
        self::assertSame('the file is no longer in the recycler folder', $result->message);
        self::assertSame(0, $this->get(RecycledFileRepository::class)->countAll());
    }

    #[Test]
    public function restoreKeepsTheEntryWhileTheStorageIsOffline(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_file_storage');
        $configuration = (string)$connection->select(['configuration'], 'sys_file_storage', ['uid' => 1])->fetchOne();
        $connection->update('sys_file_storage', ['configuration' => str_replace('fileadmin/', 'unmounted/', $configuration)], ['uid' => 1]);
        $this->get(StorageRepository::class)->flush();

        $result = $this->get(FileRestorer::class)->restore($this->entryUid);

        self::assertFalse($result->restored);
        self::assertSame('the storage is offline', $result->message);
        self::assertSame(1, $this->get(RecycledFileRepository::class)->countAll());
    }

    #[Test]
    public function restoreReportsTheNameFalGaveAFileWithALegacyName(): void
    {
        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/legacy');
        file_put_contents($this->instancePath . '/fileadmin/legacy/My (old) File.pdf', 'legacy');
        $file = $this->get(StorageRepository::class)->findByUid(1)?->getFile('/legacy/My (old) File.pdf');
        $this->get(FileDeleter::class)->delete((int)$file?->getUid());

        $result = $this->get(FileRestorer::class)->restore((int)$this->get(RecycledFileRepository::class)->findPage(1, 10)[0]['uid']);

        self::assertTrue($result->restored, $result->message);
        self::assertSame('1:/legacy/My__old__File.pdf', $result->identifier);
        self::assertFileExists($this->instancePath . '/fileadmin/legacy/My__old__File.pdf');
        GeneralUtility::rmdir($this->instancePath . '/fileadmin/legacy', true);
    }

    #[Test]
    public function systemLogIsWrittenForBackendUser(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $this->get(FileRestorer::class)->restore($this->entryUid);

        $details = $this->getConnectionPool()->getConnectionForTable('sys_log')
            ->select(['details'], 'sys_log', ['userid' => 1])
            ->fetchFirstColumn();
        self::assertContains('Recycled file "%s" restored (EXT:rdlab_unused_files)', $details);
    }

    #[Test]
    public function restoreOfAnUnknownEntryFails(): void
    {
        self::assertFalse($this->get(FileRestorer::class)->restore(999)->restored);
    }

    #[Test]
    public function purgingTheRecyclerDropsTheEntry(): void
    {
        $movedUid = (int)$this->getConnectionPool()->getConnectionForTable('sys_file')
            ->select(['uid'], 'sys_file', ['identifier' => '/_recycler_/unused.pdf'])
            ->fetchOne();

        $this->get(FileDeleter::class)->purgeFromRecycler($movedUid);

        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/_recycler_/unused.pdf');
        self::assertSame(0, $this->get(RecycledFileRepository::class)->countAll());
    }
}
