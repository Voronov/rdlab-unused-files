<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RDLAB\UnusedFiles\Domain\UnusedFileFilter;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class UnusedFileFilterTest extends UnitTestCase
{
    #[Test]
    public function fromEmptyArrayReturnsDefaults(): void
    {
        $filter = UnusedFileFilter::fromArray([]);

        self::assertSame(0, $filter->storage);
        self::assertSame('', $filter->folder);
        self::assertSame('', $filter->extension);
        self::assertSame(0, $filter->minAgeDays);
        self::assertSame('', $filter->search);
        self::assertSame(1, $filter->page);
        self::assertSame(UnusedFileFilter::DEFAULT_ITEMS_PER_PAGE, $filter->itemsPerPage);
        self::assertFalse($filter->isActive());
    }

    public static function folderDataProvider(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'root slash' => ['/', ''];
        yield 'no slashes' => ['user_upload', '/user_upload/'];
        yield 'leading slash' => ['/user_upload', '/user_upload/'];
        yield 'trailing slash' => ['user_upload/', '/user_upload/'];
        yield 'nested with whitespace' => ['  /a/b/  ', '/a/b/'];
    }

    #[Test]
    #[DataProvider('folderDataProvider')]
    public function fromArrayNormalisesFolder(string $input, string $expected): void
    {
        self::assertSame($expected, UnusedFileFilter::fromArray(['folder' => $input])->folder);
    }

    public static function extensionDataProvider(): iterable
    {
        yield 'plain' => ['pdf', 'pdf'];
        yield 'upper case' => ['PDF', 'pdf'];
        yield 'leading dot' => ['.jpg', 'jpg'];
        yield 'whitespace' => [" Png \n", 'png'];
    }

    #[Test]
    #[DataProvider('extensionDataProvider')]
    public function fromArrayNormalisesExtension(string $input, string $expected): void
    {
        self::assertSame($expected, UnusedFileFilter::fromArray(['extension' => $input])->extension);
    }

    #[Test]
    public function fromArrayClampsNegativeNumbers(): void
    {
        $filter = UnusedFileFilter::fromArray(['storage' => -3, 'minAgeDays' => '-10', 'page' => 0]);

        self::assertSame(0, $filter->storage);
        self::assertSame(0, $filter->minAgeDays);
        self::assertSame(1, $filter->page);
    }

    #[Test]
    public function fromArrayAcceptsAllowedItemsPerPage(): void
    {
        self::assertSame(200, UnusedFileFilter::fromArray(['itemsPerPage' => '200'])->itemsPerPage);
    }

    #[Test]
    public function fromArrayFallsBackToDefaultForUnknownItemsPerPage(): void
    {
        self::assertSame(
            UnusedFileFilter::DEFAULT_ITEMS_PER_PAGE,
            UnusedFileFilter::fromArray(['itemsPerPage' => 10000])->itemsPerPage
        );
    }

    #[Test]
    public function fromArrayTrimsSearch(): void
    {
        self::assertSame('report', UnusedFileFilter::fromArray(['search' => '  report '])->search);
    }

    #[Test]
    public function withPageKeepsOtherValuesAndClampsPage(): void
    {
        $filter = new UnusedFileFilter(2, '/a/', 'pdf', 7, 'x', 1, 100);

        $paged = $filter->withPage(5);
        self::assertSame(5, $paged->page);
        self::assertSame([2, '/a/', 'pdf', 7, 'x', 100], [$paged->storage, $paged->folder, $paged->extension, $paged->minAgeDays, $paged->search, $paged->itemsPerPage]);
        self::assertSame(1, $filter->page, 'original filter stays unchanged');

        self::assertSame(1, $filter->withPage(-1)->page);
    }

    public static function combinedFolderIdentifierDataProvider(): iterable
    {
        yield 'storage root' => ['1:/', 1, ''];
        yield 'sub folder' => ['1:/user_upload/', 1, '/user_upload/'];
        yield 'without trailing slash' => ['2:/a/b', 2, '/a/b/'];
        yield 'whitespace' => [' 3:/x/ ', 3, '/x/'];
    }

    #[Test]
    #[DataProvider('combinedFolderIdentifierDataProvider')]
    public function withCombinedFolderIdentifierSetsScope(string $identifier, int $expectedStorage, string $expectedFolder): void
    {
        $filter = (new UnusedFileFilter(9, '/old/', 'pdf', 3, 'abc', 4, 25))->withCombinedFolderIdentifier($identifier);

        self::assertSame($expectedStorage, $filter->storage);
        self::assertSame($expectedFolder, $filter->folder);
        self::assertSame(['pdf', 3, 'abc', 4, 25], [$filter->extension, $filter->minAgeDays, $filter->search, $filter->page, $filter->itemsPerPage]);
    }

    #[Test]
    public function withCombinedFolderIdentifierIgnoresInvalidIdentifier(): void
    {
        $filter = new UnusedFileFilter(1, '/a/');

        self::assertSame($filter, $filter->withCombinedFolderIdentifier(''));
        self::assertSame($filter, $filter->withCombinedFolderIdentifier('/user_upload/'));
    }

    #[Test]
    public function toArrayContainsOnlyNonDefaultValues(): void
    {
        self::assertSame([], (new UnusedFileFilter())->toArray());

        $filter = new UnusedFileFilter(1, '/a/', 'pdf', 30, 'x', 2, 100);
        self::assertSame(
            ['storage' => 1, 'folder' => '/a/', 'extension' => 'pdf', 'minAgeDays' => 30, 'search' => 'x', 'page' => 2, 'itemsPerPage' => 100],
            $filter->toArray()
        );
        self::assertSame(
            ['extension' => 'pdf', 'minAgeDays' => 30, 'search' => 'x', 'page' => 2, 'itemsPerPage' => 100],
            $filter->toArray(false)
        );
    }

    #[Test]
    public function toArrayRoundTripsThroughFromArray(): void
    {
        $filter = new UnusedFileFilter(1, '/a/', 'pdf', 30, 'x', 2, 100);

        self::assertEquals($filter, UnusedFileFilter::fromArray($filter->toArray()));
    }

    public static function activeFilterDataProvider(): iterable
    {
        yield 'storage' => [new UnusedFileFilter(storage: 1)];
        yield 'folder' => [new UnusedFileFilter(folder: '/a/')];
        yield 'extension' => [new UnusedFileFilter(extension: 'pdf')];
        yield 'min age' => [new UnusedFileFilter(minAgeDays: 1)];
        yield 'search' => [new UnusedFileFilter(search: 'x')];
    }

    #[Test]
    #[DataProvider('activeFilterDataProvider')]
    public function isActiveReturnsTrueForAnyRestriction(UnusedFileFilter $filter): void
    {
        self::assertTrue($filter->isActive());
    }

    #[Test]
    public function isActiveIgnoresPagination(): void
    {
        self::assertFalse((new UnusedFileFilter(page: 3, itemsPerPage: 200))->isActive());
    }
}
