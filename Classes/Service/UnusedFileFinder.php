<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Service;

use RDLAB\UnusedFiles\Domain\UnusedFileFilter;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Finds sys_file records that are not referenced anywhere in TYPO3.
 *
 * A file counts as referenced when it has at least one row in sys_refindex
 * (FAL relations, RTE links, link fields, ... - except the sys_file_metadata
 * self reference every file has), at least one live sys_file_reference row or
 * a soft deleted one whose record sits in the recycler (restoring the record
 * restores the relation), or when its path occurs in a sys_refindex string
 * reference or in a text field of any table, e.g. as an absolute URL in an
 * RTE link.
 */
final class UnusedFileFinder
{
    /** @var list<int>|null */
    private ?array $stringReferencedUids = null;

    /** @var list<int>|null */
    private ?array $restorableReferencedUids = null;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ExtensionSettings $settings,
        private readonly ContentPathScanner $contentPathScanner,
    ) {}

    /**
     * @return array{count: int, size: int}
     */
    public function countMatching(UnusedFileFilter $filter): array
    {
        $queryBuilder = $this->createBaseQuery($filter);
        $row = $queryBuilder
            ->selectLiteral(
                'COUNT(*) AS ' . $queryBuilder->quoteIdentifier('cnt'),
                'COALESCE(SUM(' . $queryBuilder->quoteIdentifier('f.size') . '), 0) AS ' . $queryBuilder->quoteIdentifier('total_size')
            )
            ->executeQuery()
            ->fetchAssociative();

        return [
            'count' => (int)($row['cnt'] ?? 0),
            'size' => (int)($row['total_size'] ?? 0),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findPage(UnusedFileFilter $filter): array
    {
        $queryBuilder = $this->createBaseQuery($filter);
        $this->selectFileColumns($queryBuilder);

        return $queryBuilder
            ->orderBy('f.identifier', 'ASC')
            ->setFirstResult(($filter->page - 1) * $filter->itemsPerPage)
            ->setMaxResults($filter->itemsPerPage)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function findAll(UnusedFileFilter $filter, int $limit = 0): \Generator
    {
        $queryBuilder = $this->createBaseQuery($filter);
        $this->selectFileColumns($queryBuilder);
        $queryBuilder->orderBy('f.identifier', 'ASC');
        if ($limit > 0) {
            $queryBuilder->setMaxResults($limit);
        }

        $result = $queryBuilder->executeQuery();
        while (($row = $result->fetchAssociative()) !== false) {
            yield $row;
        }
    }

    /**
     * Re-checks a single file right before deleting it. Files in excluded
     * folders are reported as "referenced" on purpose, so they are never deleted.
     */
    public function isUnreferenced(int $fileUid): bool
    {
        $queryBuilder = $this->createBaseQuery(new UnusedFileFilter());
        $queryBuilder->andWhere(
            $queryBuilder->expr()->eq('f.uid', $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT))
        );

        return (int)$queryBuilder->count('f.uid')->executeQuery()->fetchOne() === 1;
    }

    /**
     * @return array<int, string> storage uid => name
     */
    public function getStorages(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_storage');
        $rows = $queryBuilder
            ->select('uid', 'name')
            ->from('sys_file_storage')
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $storages = [];
        foreach ($rows as $row) {
            $storages[(int)$row['uid']] = (string)$row['name'];
        }
        return $storages;
    }

    /**
     * @return list<string>
     */
    public function getExtensions(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('extension')
            ->from('sys_file')
            ->where($queryBuilder->expr()->neq('extension', $queryBuilder->createNamedParameter('')))
            ->groupBy('extension')
            ->orderBy('extension', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_unique(array_map(static fn($extension): string => strtolower((string)$extension), $rows)));
    }

    private function selectFileColumns(QueryBuilder $queryBuilder): void
    {
        $queryBuilder
            ->select('f.uid', 'f.storage', 'f.identifier', 'f.name', 'f.extension', 'f.size', 'f.creation_date', 'f.modification_date')
            ->addSelectLiteral($queryBuilder->quoteIdentifier('s.name') . ' AS ' . $queryBuilder->quoteIdentifier('storage_name'));
    }

    private function createBaseQuery(UnusedFileFilter $filter): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->from('sys_file', 'f')
            ->leftJoin('f', 'sys_file_storage', 's', $queryBuilder->expr()->eq('s.uid', $queryBuilder->quoteIdentifier('f.storage')))
            ->where(
                // storage 0 is the legacy fallback for paths outside the file storages, never touch it
                $queryBuilder->expr()->gt('f.storage', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('f.missing', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->notIn('f.uid', $this->referenceIndexSubQuery($queryBuilder)),
                $queryBuilder->expr()->notIn('f.uid', $this->fileReferenceSubQuery($queryBuilder)),
            );

        foreach ($this->settings->getExcludedFolders() as $folder) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->notLike(
                    'f.identifier',
                    $queryBuilder->createNamedParameter($queryBuilder->escapeLikeWildcards($folder) . '%')
                )
            );
        }

        $excludedFileNames = $this->settings->getExcludedFileNames();
        if ($excludedFileNames !== []) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->notIn(
                    'f.name',
                    $queryBuilder->createNamedParameter($excludedFileNames, Connection::PARAM_STR_ARRAY)
                )
            );
        }

        if ($filter->storage > 0) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('f.storage', $queryBuilder->createNamedParameter($filter->storage, Connection::PARAM_INT))
            );
        }
        if ($filter->folder !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->like(
                    'f.identifier',
                    $queryBuilder->createNamedParameter($queryBuilder->escapeLikeWildcards($filter->folder) . '%')
                )
            );
        }
        if ($filter->extension !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('f.extension', $queryBuilder->createNamedParameter($filter->extension))
            );
        }
        if ($filter->minAgeDays > 0) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->lte(
                    'f.creation_date',
                    $queryBuilder->createNamedParameter(time() - $filter->minAgeDays * 86400, Connection::PARAM_INT)
                )
            );
        }
        if ($filter->search !== '') {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->like(
                    'f.name',
                    $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($filter->search) . '%')
                )
            );
        }

        $referencedUids = array_merge($this->findStringReferencedUids(), $this->findRestorableReferencedUids());
        if ($referencedUids !== []) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->notIn(
                    'f.uid',
                    $queryBuilder->createNamedParameter($referencedUids, Connection::PARAM_INT_ARRAY)
                )
            );
        }

        return $queryBuilder;
    }

    /**
     * All sys_file uids that have a reference index entry, except the
     * sys_file_metadata record every file owns.
     */
    private function referenceIndexSubQuery(QueryBuilder $queryBuilder): string
    {
        return 'SELECT ' . $queryBuilder->quoteIdentifier('r.ref_uid')
            . ' FROM ' . $queryBuilder->quoteIdentifier('sys_refindex') . ' ' . $queryBuilder->quoteIdentifier('r')
            . ' WHERE ' . $queryBuilder->quoteIdentifier('r.ref_table') . ' = ' . $queryBuilder->createNamedParameter('sys_file')
            . ' AND ' . $queryBuilder->quoteIdentifier('r.tablename') . ' <> ' . $queryBuilder->createNamedParameter('sys_file_metadata');
    }

    /**
     * All sys_file uids with a live FAL relation. Soft deleted relations are
     * handled by findRestorableReferencedUids().
     */
    private function fileReferenceSubQuery(QueryBuilder $queryBuilder): string
    {
        return 'SELECT ' . $queryBuilder->quoteIdentifier('fr.uid_local')
            . ' FROM ' . $queryBuilder->quoteIdentifier('sys_file_reference') . ' ' . $queryBuilder->quoteIdentifier('fr')
            . ' WHERE ' . $queryBuilder->quoteIdentifier('fr.deleted') . ' = 0';
    }

    /**
     * Files whose soft deleted FAL relation belongs to a record that is itself
     * in the recycler: the reference index skips deleted records, but restoring
     * the record restores its file relations, so the file is still needed. A
     * relation an editor removed from a live record does not count, nor one
     * whose record is gone. Run "cleanup:deletedrecords" to empty the recycler.
     *
     * @return list<int>
     */
    private function findRestorableReferencedUids(): array
    {
        if ($this->restorableReferencedUids !== null) {
            return $this->restorableReferencedUids;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder
            ->select('uid_local', 'tablenames', 'uid_foreign')
            ->from('sys_file_reference')
            ->where($queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)))
            ->executeQuery();
        $fileUidsByRecord = [];
        while (($row = $result->fetchAssociative()) !== false) {
            $fileUidsByRecord[(string)$row['tablenames']][(int)$row['uid_foreign']][] = (int)$row['uid_local'];
        }

        $uids = [];
        foreach ($fileUidsByRecord as $table => $fileUidsByRecordUid) {
            $tableControl = is_array($GLOBALS['TCA'][$table]['ctrl'] ?? null) ? $GLOBALS['TCA'][$table]['ctrl'] : [];
            $deleteColumn = (string)($tableControl['delete'] ?? '');
            if ($deleteColumn === '') {
                continue;
            }
            foreach (array_chunk(array_keys($fileUidsByRecordUid), 1000) as $recordUids) {
                $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
                $queryBuilder->getRestrictions()->removeAll();
                $deletedRecordUids = $queryBuilder
                    ->select('uid')
                    ->from($table)
                    ->where(
                        $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($recordUids, Connection::PARAM_INT_ARRAY)),
                        $queryBuilder->expr()->eq($deleteColumn, $queryBuilder->createNamedParameter(1, Connection::PARAM_INT))
                    )
                    ->executeQuery()
                    ->fetchFirstColumn();
                foreach ($deletedRecordUids as $recordUid) {
                    foreach ($fileUidsByRecordUid[(int)$recordUid] as $fileUid) {
                        $uids[] = $fileUid;
                    }
                }
            }
        }

        return $this->restorableReferencedUids = array_values(array_unique($uids));
    }

    /**
     * Files referenced by path instead of uid: sys_refindex "_STRING" rows
     * (ref_string holds the path) plus the paths the ContentPathScanner finds
     * in text fields, since the reference index does not record URLs. A file
     * counts as referenced when a URL-decoded string ends with its identifier,
     * compared case-insensitively. Matched in PHP by file name: a LIKE per
     * file would scan all strings for every file.
     *
     * @return list<int>
     */
    private function findStringReferencedUids(): array
    {
        if ($this->stringReferencedUids !== null) {
            return $this->stringReferencedUids;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $queryBuilder->getRestrictions()->removeAll();
        $strings = $queryBuilder
            ->select('ref_string')
            ->from('sys_refindex')
            ->where($queryBuilder->expr()->eq('ref_table', $queryBuilder->createNamedParameter('_STRING')))
            ->groupBy('ref_string')
            ->executeQuery()
            ->fetchFirstColumn();
        $strings = array_merge($strings, $this->contentPathScanner->findPaths());
        $stringsByFileName = [];
        foreach ($strings as $string) {
            $string = mb_strtolower(rawurldecode((string)$string));
            $fileName = strrchr($string, '/');
            if ($fileName !== false) {
                $stringsByFileName[$fileName][] = $string;
            }
        }
        if ($stringsByFileName === []) {
            return $this->stringReferencedUids = [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder
            ->select('uid', 'identifier')
            ->from('sys_file')
            ->where($queryBuilder->expr()->gt('storage', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->executeQuery();
        $uids = [];
        while (($row = $result->fetchAssociative()) !== false) {
            $identifier = mb_strtolower((string)$row['identifier']);
            foreach ($stringsByFileName[(string)strrchr($identifier, '/')] ?? [] as $string) {
                if (str_ends_with($string, $identifier)) {
                    $uids[] = (int)$row['uid'];
                    break;
                }
            }
        }

        return $this->stringReferencedUids = $uids;
    }
}
