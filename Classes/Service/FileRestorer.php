<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use RDLAB\UnusedFiles\Domain\RestoreResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\SysLog\Action\File as SystemLogFileAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\SysLog\Type as SystemLogType;

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
        if ($storage !== null && !$storage->isOnline()) {
            return new RestoreResult(false, $combinedIdentifier, 'the storage is offline');
        }
        if ($storage === null || !$storage->hasFile($recyclerIdentifier)) {
            $this->recycledFiles->remove($uid);
            return new RestoreResult(false, $combinedIdentifier, 'the file is no longer in the recycler folder');
        }

        try {
            $folderIdentifier = CsvExporter::folderOf($originalIdentifier);
            $folder = $storage->hasFolder($folderIdentifier)
                ? $storage->getFolder($folderIdentifier)
                : $storage->createFolder(trim($folderIdentifier, '/'), $storage->getRootLevelFolder());
            $file = $storage->moveFile($storage->getFile($recyclerIdentifier), $folder, basename($originalIdentifier), DuplicationBehavior::CANCEL);
        } catch (\Throwable $exception) {
            $this->logger?->error('Restoring file failed', ['file' => $combinedIdentifier, 'exception' => $exception]);
            return new RestoreResult(false, $combinedIdentifier, $exception->getMessage());
        }

        $this->recycledFiles->remove($uid);
        // FAL sanitizes the file name on a move, so a legacy name is not kept
        $combinedIdentifier = (int)$row['storage'] . ':' . $file->getIdentifier();
        $this->logger?->info('Recycled file restored', ['file' => $combinedIdentifier]);
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser instanceof BackendUserAuthentication) {
            $backendUser->writelog(
                SystemLogType::FILE,
                SystemLogFileAction::MOVE,
                SystemLogErrorClassification::MESSAGE,
                null,
                'Recycled file "%s" restored (EXT:rdlab_unused_files)',
                [$combinedIdentifier]
            );
        }

        return new RestoreResult(true, $combinedIdentifier);
    }
}
