<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Finds file paths in the text fields of all TCA tables: an absolute URL to a
 * file in an RTE link or a link field, a page of type "external URL", a
 * flexform or a TypoScript record. The reference index does not record URLs
 * (the typolink soft reference parser marks them "external", a type the
 * ReferenceIndex ignores), so a file that is only linked by its URL would
 * look unused without this scan.
 */
final class ContentPathScanner
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly StorageRepository $storageRepository,
    ) {}

    /**
     * Strings starting with the public path of a storage as found in the
     * content, e.g. "fileadmin/user_upload/report.pdf", without query string
     * or fragment. Soft deleted records are included, like file references.
     *
     * @return list<string>
     */
    public function findPaths(): array
    {
        $basePaths = $this->getStorageBasePaths();
        if ($basePaths === []) {
            return [];
        }
        $pattern = '~(?:' . implode('|', array_map(static fn(string $basePath): string => preg_quote($basePath, '~'), $basePaths)) . ')[^\s"\'<>?#&\\\\]*~iu';

        $tca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $paths = [];
        foreach ($tca as $table => $tableConfiguration) {
            $columns = is_array($tableConfiguration) ? ($tableConfiguration['columns'] ?? []) : [];
            $textColumns = is_array($columns) ? $this->getTextColumns($columns) : [];
            if ($textColumns === []) {
                continue;
            }
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable((string)$table);
            $queryBuilder->getRestrictions()->removeAll();
            $conditions = [];
            foreach ($textColumns as $column) {
                foreach ($basePaths as $basePath) {
                    $conditions[] = $queryBuilder->expr()->like(
                        $column,
                        $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($basePath) . '%')
                    );
                }
            }
            $result = $queryBuilder
                ->select(...$textColumns)
                ->from((string)$table)
                ->where($queryBuilder->expr()->or(...$conditions))
                ->executeQuery();
            while (($row = $result->fetchAssociative()) !== false) {
                foreach ($row as $value) {
                    if (is_string($value) && preg_match_all($pattern, $value, $matches) > 0) {
                        foreach ($matches[0] as $match) {
                            $paths[] = rtrim($match, '.,;:!)');
                        }
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Columns that can hold a link or a path. Plain text areas without soft
     * references (logs, notes) are skipped, they tend to be the large ones.
     *
     * @param array<mixed> $columns
     * @return list<string>
     */
    private function getTextColumns(array $columns): array
    {
        $names = [];
        foreach ($columns as $name => $column) {
            $config = is_array($column) ? ($column['config'] ?? null) : null;
            if (!is_array($config)) {
                continue;
            }
            $type = $config['type'] ?? '';
            if (in_array($type, ['input', 'link', 'flex'], true)
                || ($type === 'text' && (!empty($config['enableRichtext']) || !empty($config['softref'])))
            ) {
                $names[] = (string)$name;
            }
        }
        return $names;
    }

    /**
     * @return list<string>
     */
    private function getStorageBasePaths(): array
    {
        $basePaths = [];
        foreach ($this->storageRepository->findAll() as $storage) {
            if (!$storage->isOnline() || !$storage->isPublic()) {
                continue;
            }
            $basePath = (string)$storage->getPublicUrl($storage->getRootLevelFolder());
            if ($basePath !== '' && $basePath !== '/') {
                $basePaths[] = $basePath;
            }
        }
        return array_values(array_unique($basePaths));
    }
}
