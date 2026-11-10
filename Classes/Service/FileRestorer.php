<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use RDLAB\UnusedFiles\Domain\RestoreResult;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Moves a file the extension put into a recycler folder back to the folder it
 * came from. The folder is created again when it is gone; a file that took
 * the old place in the meantime is never overwritten.
 */
final class FileRestorer implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly RecycledFileRepository $recycledFiles,
        private readonly StorageRepository $storageRepository,
    ) {}

    public function restore(int $uid): RestoreResult
    {
        $row = $this->recycledFiles->findByUid($uid);
        if ($row === null) {
            return new RestoreResult(false, '', 'unknown entry');
        }
        $originalIdentifier = (string)$row['original_identifier'];
        $recyclerIdentifier = (string)$row['recycler_identifier'];
        $combinedIdentifier = (int)$row['storage'] . ':' . $originalIdentifier;

        $storage = $this->storageRepository->findByUid((int)$row['storage']);
        if ($storage === null || !$storage->hasFile($recyclerIdentifier)) {
            $this->recycledFiles->remove($uid);
            return new RestoreResult(false, $combinedIdentifier, 'the file is no longer in the recycler folder');
        }

        try {
            $folderIdentifier = CsvExporter::folderOf($originalIdentifier);
            $folder = $storage->hasFolder($folderIdentifier)
                ? $storage->getFolder($folderIdentifier)
                : $storage->createFolder(trim($folderIdentifier, '/'), $storage->getRootLevelFolder());
            $storage->moveFile($storage->getFile($recyclerIdentifier), $folder, basename($originalIdentifier), DuplicationBehavior::CANCEL);
        } catch (\Throwable $exception) {
            $this->logger?->error('Restoring file failed', ['file' => $combinedIdentifier, 'exception' => $exception]);
            return new RestoreResult(false, $combinedIdentifier, $exception->getMessage());
        }

        $this->recycledFiles->remove($uid);
        $this->logger?->info('Recycled file restored', ['file' => $combinedIdentifier]);

        return new RestoreResult(true, $combinedIdentifier);
    }
}
