<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use RDLAB\UnusedFiles\Domain\UnusedFileFilter;
use RDLAB\UnusedFiles\Service\UnusedFileFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class UnusedFileFinderTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['rdlab/unused-files'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/files.csv');
    }

    /**
     * @return list<int>
     */
    private function findUids(UnusedFileFilter $filter): array
    {
        $uids = [];
        foreach ($this->get(UnusedFileFinder::class)->findAll($filter) as $row) {
            $uids[] = (int)$row['uid'];
        }
        return $uids;
    }

    #[Test]
    public function findAllReturnsOnlyUnreferencedFilesOrderedByIdentifier(): void
    {
        // 1 unused, 2 metadata reference only, 10 unused, 11 unused in storage 2, 19 relation removed from a live
        // element, 20 relation of a record that no longer exists; left out: 3 refindex, 4 file reference,
        // 5 soft deleted file reference of an element in the recycler, 6 excluded folder, 7 excluded name,
        // 8 missing, 9 storage 0, 12 referenced by path, 13 referenced by URL
        self::assertSame([11, 10, 2, 20, 19, 1], $this->findUids(new UnusedFileFilter()));
    }

    #[Test]
    public function countMatchingReturnsCountAndTotalSize(): void
    {
        self::assertSame(['count' => 6, 'size' => 1631], $this->get(UnusedFileFinder::class)->countMatching(new UnusedFileFilter()));
    }

    #[Test]
    public function countMatchingReturnsZeroWithoutMatches(): void
    {
        self::assertSame(
            ['count' => 0, 'size' => 0],
            $this->get(UnusedFileFinder::class)->countMatching(new UnusedFileFilter(extension: 'docx'))
        );
    }

    #[Test]
    public function filterByStorage(): void
    {
        self::assertSame([11], $this->findUids(new UnusedFileFilter(storage: 2)));
        self::assertSame([10, 2, 20, 19, 1], $this->findUids(new UnusedFileFilter(storage: 1)));
    }

    #[Test]
    public function filterByFolder(): void
    {
        self::assertSame([2, 20, 19, 1], $this->findUids(new UnusedFileFilter(folder: '/user_upload/')));
    }

    #[Test]
    public function filterByExtension(): void
    {
        self::assertSame([11, 10, 1], $this->findUids(new UnusedFileFilter(extension: 'pdf')));
    }

    #[Test]
    public function filterByMinimumAge(): void
    {
        self::assertSame([10], $this->findUids(new UnusedFileFilter(minAgeDays: 30)));
    }

    #[Test]
    public function filterBySearchMatchesPartOfTheName(): void
    {
        self::assertSame([10], $this->findUids(new UnusedFileFilter(search: 'report')));
        self::assertSame([2], $this->findUids(new UnusedFileFilter(search: 'metadata')));
    }

    #[Test]
    public function searchTreatsLikeWildcardsLiterally(): void
    {
        self::assertSame([], $this->findUids(new UnusedFileFilter(search: '%')));
        self::assertSame([10], $this->findUids(new UnusedFileFilter(search: 'old_')));
    }

    #[Test]
    public function findAllRespectsLimit(): void
    {
        $uids = [];
        foreach ($this->get(UnusedFileFinder::class)->findAll(new UnusedFileFilter(), 2) as $row) {
            $uids[] = (int)$row['uid'];
        }
        self::assertSame([11, 10], $uids);
    }

    #[Test]
    public function findPageReturnsRequestedPage(): void
    {
        $finder = $this->get(UnusedFileFinder::class);

        $page = $finder->findPage(new UnusedFileFilter(page: 1, itemsPerPage: 25));
        self::assertCount(6, $page);
        self::assertSame('second', $page[0]['storage_name']);
        self::assertSame('/docs/second.pdf', $page[0]['identifier']);

        self::assertSame([], $finder->findPage(new UnusedFileFilter(page: 2, itemsPerPage: 25)));
    }

    #[Test]
    public function excludedFoldersAndFileNamesComeFromExtensionConfiguration(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['rdlab_unused_files'] = [
            'excludedFolders' => '/other/',
            'excludedFileNames' => 'unused.pdf',
        ];

        // the default exclusions no longer apply, so the processed file and index.html are listed now
        self::assertSame([6, 11, 7, 2, 20, 19], $this->findUids(new UnusedFileFilter()));
    }

    #[Test]
    public function isUnreferencedChecksSingleFile(): void
    {
        $finder = $this->get(UnusedFileFinder::class);

        self::assertTrue($finder->isUnreferenced(1));
        self::assertTrue($finder->isUnreferenced(2), 'metadata reference only');
        self::assertFalse($finder->isUnreferenced(3), 'referenced in sys_refindex');
        self::assertFalse($finder->isUnreferenced(4), 'file reference');
        self::assertFalse($finder->isUnreferenced(5), 'soft deleted file reference of an element in the recycler');
        self::assertTrue($finder->isUnreferenced(19), 'file reference removed from a live element');
        self::assertTrue($finder->isUnreferenced(20), 'soft deleted file reference of a record that no longer exists');
        self::assertFalse($finder->isUnreferenced(12), 'referenced by path');
        self::assertFalse($finder->isUnreferenced(13), 'referenced by URL');
        self::assertFalse($finder->isUnreferenced(6), 'excluded folder');
        self::assertFalse($finder->isUnreferenced(7), 'excluded file name');
        self::assertFalse($finder->isUnreferenced(8), 'missing file');
        self::assertFalse($finder->isUnreferenced(9), 'storage 0');
        self::assertFalse($finder->isUnreferenced(999), 'no sys_file record');
    }

    #[Test]
    public function stringReferencesMatchEncodedAndDifferentlyCasedPaths(): void
    {
        $finder = $this->get(UnusedFileFinder::class);

        self::assertFalse($finder->isUnreferenced(14), 'URL-encoded umlaut and space');
        self::assertFalse($finder->isUnreferenced(15), 'different case');
    }

    #[Test]
    public function pathsInContentFieldsCountAsReferences(): void
    {
        $finder = $this->get(UnusedFileFinder::class);

        // none of these files has a sys_refindex row, the reference index does not record URLs
        self::assertFalse($finder->isUnreferenced(16), 'URL in an RTE link');
        self::assertFalse($finder->isUnreferenced(17), 'URL-encoded path with query string in pages.url');
        self::assertFalse($finder->isUnreferenced(18), 'plain path followed by punctuation');
    }

    #[Test]
    public function getStoragesReturnsNamesByUid(): void
    {
        self::assertSame([1 => 'fileadmin', 2 => 'second'], $this->get(UnusedFileFinder::class)->getStorages());
    }

    #[Test]
    public function getExtensionsReturnsDistinctExtensions(): void
    {
        self::assertSame(['html', 'jpg', 'pdf', 'png'], $this->get(UnusedFileFinder::class)->getExtensions());
    }
}
