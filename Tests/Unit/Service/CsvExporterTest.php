<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RDLAB\UnusedFiles\Service\CsvExporter;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class CsvExporterTest extends UnitTestCase
{
    /**
     * @return iterable<string, array<mixed>>
     */
    public static function folderOfDataProvider(): iterable
    {
        yield 'file in root' => ['/file.pdf', '/'];
        yield 'file without slash' => ['file.pdf', '/'];
        yield 'file in sub folder' => ['/user_upload/file.pdf', '/user_upload/'];
        yield 'file in nested folder' => ['/a/b/c.jpg', '/a/b/'];
    }

    #[Test]
    #[DataProvider('folderOfDataProvider')]
    public function folderOfReturnsParentFolderWithSlashes(string $identifier, string $expected): void
    {
        self::assertSame($expected, CsvExporter::folderOf($identifier));
    }

    #[Test]
    public function escapeFormulaPrefixesValuesASpreadsheetWouldRun(): void
    {
        self::assertSame("'=1+1.pdf", CsvExporter::escapeFormula('=1+1.pdf'));
        self::assertSame("'+draft.pdf", CsvExporter::escapeFormula('+draft.pdf'));
        self::assertSame("'-draft.pdf", CsvExporter::escapeFormula('-draft.pdf'));
        self::assertSame("'@cmd.pdf", CsvExporter::escapeFormula('@cmd.pdf'));
        self::assertSame('report=2024.pdf', CsvExporter::escapeFormula('report=2024.pdf'));
        self::assertSame('', CsvExporter::escapeFormula(''));
    }

    #[Test]
    public function formatTimestampReturnsEmptyStringForUnsetDate(): void
    {
        self::assertSame('', CsvExporter::formatTimestamp(0));
        self::assertSame('', CsvExporter::formatTimestamp(-1));
    }

    #[Test]
    public function formatTimestampFormatsDate(): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            self::assertSame('2026-01-02 03:04:05', CsvExporter::formatTimestamp(1767323045));
        } finally {
            date_default_timezone_set($timezone);
        }
    }
}
