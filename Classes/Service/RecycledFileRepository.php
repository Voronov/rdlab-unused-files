<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Remembers where a file came from when the extension moves it to a recycler
 * folder: FAL puts all files flat into that folder, so the original path
 * would be lost otherwise.
 */
final class RecycledFileRepository
{
    private const TABLE = 'tx_rdlabunusedfiles_recycled';

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    public function add(int $storage, string $originalIdentifier, string $recyclerIdentifier, string $name, int $size, string $deletedBy): void
    {
        // an entry left behind by a file that was removed from the recycler by hand
        $this->removeByRecyclerIdentifier($storage, $recyclerIdentifier);
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'storage' => $storage,
            'original_identifier' => $originalIdentifier,
            'recycler_identifier' => $recyclerIdentifier,
            'name' => $name,
            'size' => $size,
            'deleted_at' => time(),
            'deleted_by' => $deletedBy,
        ]);
    }

    public function remove(int $uid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->delete(self::TABLE, ['uid' => $uid]);
    }

    public function removeByRecyclerIdentifier(int $storage, string $recyclerIdentifier): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('storage', $queryBuilder->createNamedParameter($storage, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('recycler_identifier', $queryBuilder->createNamedParameter($recyclerIdentifier))
            )
            ->executeStatement();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUid(int $uid): ?array
    {
        $row = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['*'], self::TABLE, ['uid' => $uid])
            ->fetchAssociative();
        return $row === false ? null : $row;
    }

    public function countAll(): int
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE)->count('uid', self::TABLE, []);
    }

    /**
     * Newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function findPage(int $page, int $itemsPerPage): array
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['*'], self::TABLE, [], [], ['deleted_at' => 'DESC', 'uid' => 'DESC'], $itemsPerPage, (max(1, $page) - 1) * $itemsPerPage)
            ->fetchAllAssociative();
    }
}
