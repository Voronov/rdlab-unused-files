<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use RDLAB\UnusedFiles\Service\RecyclerFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RecyclerFinderTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['rdlab/unused-files'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/recycler.csv');
    }

    /**
     * @return list<int>
     */
    private function findUids(int $minAgeDays, int $storage = 0, int $limit = 0): array
    {
        $uids = [];
        foreach ($this->get(RecyclerFinder::class)->findExpired($minAgeDays, $storage, $limit) as $row) {
            $uids[] = (int)$row['uid'];
        }
        return $uids;
    }

    #[Test]
    public function findExpiredReturnsOldFilesInRecyclerFoldersOnly(): void
    {
        // 2 was moved there recently, 3 is not in a recycler, 5 is in a folder that only looks alike
        self::assertSame([4, 1], $this->findUids(30));
    }

    #[Test]
    public function findExpiredRespectsStorageAndLimit(): void
    {
        self::assertSame([], $this->findUids(30, 2));
        self::assertSame([4], $this->findUids(30, 1, 1));
    }

    #[Test]
    public function isInRecyclerChecksTheFolderName(): void
    {
        self::assertTrue(RecyclerFinder::isInRecycler('/user_upload/_recycler_/file.pdf'));
        self::assertTrue(RecyclerFinder::isInRecycler('/_recycler_/file.pdf'));
        self::assertFalse(RecyclerFinder::isInRecycler('/user_upload/file.pdf'));
        self::assertFalse(RecyclerFinder::isInRecycler('/xrecyclerx/file.pdf'));
        self::assertFalse(RecyclerFinder::isInRecycler('/_recycler_x/file.pdf'));
    }
}
