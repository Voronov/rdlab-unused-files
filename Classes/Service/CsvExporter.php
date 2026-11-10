<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Service;

use RDLAB\UnusedFiles\Domain\UnusedFileFilter;
use TYPO3\CMS\Core\Resource\ResourceFactory;

final class CsvExporter
{
    public const COLUMNS = [
        'uid',
        'storage',
        'path',
        'folder',
        'name',
        'extension',
        'size_bytes',
        'created',
        'modified',
        'public_url',
        'status',
    ];

    public function __construct(
        private readonly UnusedFileFinder $finder,
        private readonly ReferenceIndexStatus $referenceIndexStatus,
        private readonly ResourceFactory $resourceFactory,
    ) {}

    /**
     * Writes header and all matching rows to an open stream.
     *
     * @param resource $handle
     * @return array{count: int, size: int}
     */
    public function write($handle, UnusedFileFilter $filter, string $delimiter = ','): array
    {
        $status = $this->referenceIndexStatus->getStatusText();
        fputcsv($handle, self::COLUMNS, $delimiter, '"', '\\');

        $count = 0;
        $size = 0;
        foreach ($this->finder->findAll($filter) as $row) {
            fputcsv($handle, [
                (int)$row['uid'],
                (int)$row['storage'],
                (string)$row['identifier'],
                self::folderOf((string)$row['identifier']),
                self::escapeFormula((string)$row['name']),
                self::escapeFormula((string)$row['extension']),
                (int)$row['size'],
                self::formatTimestamp((int)$row['creation_date']),
                self::formatTimestamp((int)$row['modification_date']),
                self::escapeFormula($this->publicUrl($row)),
                $status,
            ], $delimiter, '"', '\\');
            $count++;
            $size += (int)$row['size'];
        }

        return ['count' => $count, 'size' => $size];
    }

    public static function folderOf(string $identifier): string
    {
        $folder = dirname($identifier);
        return $folder === '.' || $folder === '' ? '/' : rtrim($folder, '/') . '/';
    }

    /**
     * Keeps a spreadsheet from running a value that starts like a formula.
     */
    public static function escapeFormula(string $value): string
    {
        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'" . $value : $value;
    }

    public static function formatTimestamp(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function publicUrl(array $row): string
    {
        try {
            return (string)($this->resourceFactory->getFileObject((int)$row['uid'], $row)->getPublicUrl() ?? '');
        } catch (\Throwable) {
            return '';
        }
    }
}
