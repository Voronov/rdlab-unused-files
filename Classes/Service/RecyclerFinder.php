<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Finds files that have been lying in a _recycler_ folder for a while.
 */
final class RecyclerFinder
{
    public const FOLDER_NAME = '_recycler_';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly StorageRepository $storageRepository,
    ) {}

    public static function isInRecycler(string $identifier): bool
    {
        return str_contains($identifier, '/' . self::FOLDER_NAME . '/');
    }

    /**
     * sys_file rows in a _recycler_ folder whose index entry was written at least
     * $minAgeDays ago. FAL drops the index entry when it moves a file into a
     * recycler folder, so the recycler folders are indexed first: a file that is
     * new there gets an entry dated now.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function findExpired(int $minAgeDays, int $storage = 0, int $limit = 0): \Generator
    {
        $this->indexRecyclerFolders($storage);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->select('uid', 'storage', 'identifier', 'name', 'size', 'tstamp')
            ->from('sys_file')
            ->where(
                $queryBuilder->expr()->gt('storage', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->like(
                    'identifier',
                    $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards('/' . self::FOLDER_NAME . '/') . '%')
                ),
                $queryBuilder->expr()->lte(
                    'tstamp',
                    $queryBuilder->createNamedParameter(time() - $minAgeDays * 86400, Connection::PARAM_INT)
                ),
            )
            ->orderBy('identifier', 'ASC');
        if ($storage > 0) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('storage', $queryBuilder->createNamedParameter($storage, Connection::PARAM_INT))
            );
        }
        if ($limit > 0) {
            $queryBuilder->setMaxResults($limit);
        }

        $result = $queryBuilder->executeQuery();
        while (($row = $result->fetchAssociative()) !== false) {
            yield $row;
        }
    }

    private function indexRecyclerFolders(int $storageUid): void
    {
        foreach ($this->storageRepository->findAll() as $storage) {
            if (!$storage->isOnline() || ($storageUid > 0 && $storage->getUid() !== $storageUid)) {
                continue;
            }
            foreach ($storage->getFoldersInFolder($storage->getRootLevelFolder(), 0, 0, false, true) as $folder) {
                if ($folder->getName() === self::FOLDER_NAME) {
                    // listing a folder through FAL indexes the files it holds
                    $storage->getFilesInFolder($folder, 0, 0, false);
                }
            }
        }
    }
}
