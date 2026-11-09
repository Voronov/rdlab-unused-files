<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use RDLAB\UnusedFiles\Domain\DeleteOutcome;
use RDLAB\UnusedFiles\Domain\DeleteResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\FolderInterface;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\SysLog\Action\File as SystemLogFileAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\SysLog\Type as SystemLogType;

/**
 * Deletes a file through FAL after re-checking that it is still unreferenced.
 * FAL applies the backend user's file permissions and moves the file to the
 * nearest _recycler_ folder, which is created in the storage root when missing.
 */
final class FileDeleter implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly UnusedFileFinder $finder,
    ) {}

    public function delete(int $fileUid): DeleteResult
    {
        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
        } catch (FileDoesNotExistException) {
            return new DeleteResult($fileUid, '', DeleteOutcome::SkippedNotFound);
        }

        if (!$this->finder->isUnreferenced($fileUid)) {
            return new DeleteResult($fileUid, $file->getCombinedIdentifier(), DeleteOutcome::SkippedReferenced);
        }

        try {
            $this->ensureRecyclerFolder($file);
        } catch (\Throwable $exception) {
            $this->logger?->error('Creating the recycler folder failed', ['file' => $file->getCombinedIdentifier(), 'exception' => $exception]);
            return new DeleteResult($fileUid, $file->getCombinedIdentifier(), DeleteOutcome::Failed, 'Could not create the ' . RecyclerFinder::FOLDER_NAME . ' folder: ' . $exception->getMessage());
        }

        return $this->remove($file, 'Unused file');
    }

    /**
     * Creates _recycler_ in the storage root unless the file already has one
     * on its way up to the root (the folder FAL would move it to).
     */
    private function ensureRecyclerFolder(File $file): void
    {
        $storage = $file->getStorage();
        $folder = $file->getParentFolder();
        while ($folder instanceof Folder) {
            if ($folder->getRole() === FolderInterface::ROLE_RECYCLER || $folder->hasFolder(RecyclerFinder::FOLDER_NAME)) {
                return;
            }
            $parentFolder = $folder->getParentFolder();
            if ($folder->getIdentifier() === $parentFolder->getIdentifier()) {
                break;
            }
            $folder = $parentFolder;
        }

        $storage->createFolder(RecyclerFinder::FOLDER_NAME, $storage->getRootLevelFolder());
    }

    /**
     * Permanently deletes a file that lies in a _recycler_ folder: FAL never
     * moves a file from a recycler folder into another one.
     */
    public function purgeFromRecycler(int $fileUid): DeleteResult
    {
        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
        } catch (FileDoesNotExistException) {
            return new DeleteResult($fileUid, '', DeleteOutcome::SkippedNotFound);
        }

        if (!RecyclerFinder::isInRecycler($file->getIdentifier())) {
            return new DeleteResult($fileUid, $file->getCombinedIdentifier(), DeleteOutcome::SkippedReferenced, 'not in a recycler folder');
        }

        return $this->remove($file, 'Recycled file');
    }

    private function remove(File $file, string $what): DeleteResult
    {
        $identifier = $file->getCombinedIdentifier();
        try {
            $file->getStorage()->deleteFile($file);
        } catch (\Throwable $exception) {
            $this->logger?->error('Deleting file failed', ['file' => $identifier, 'exception' => $exception]);
            return new DeleteResult($file->getUid(), $identifier, DeleteOutcome::Failed, $exception->getMessage());
        }

        $outcome = $file->isDeleted() ? DeleteOutcome::Deleted : DeleteOutcome::MovedToRecycler;
        if ($outcome === DeleteOutcome::MovedToRecycler) {
            // FAL dropped the index entry on the move, index the file at its new place so the move date is known
            $file->getStorage()->getFile($file->getIdentifier());
        }
        $this->logger?->info($what . ' removed', ['file' => $identifier, 'outcome' => $outcome->value]);
        $this->writeSystemLog($what, $identifier, $outcome);

        return new DeleteResult($file->getUid(), $identifier, $outcome);
    }

    private function writeSystemLog(string $what, string $identifier, DeleteOutcome $outcome): void
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return;
        }
        $backendUser->writelog(
            SystemLogType::FILE,
            SystemLogFileAction::DELETE,
            SystemLogErrorClassification::MESSAGE,
            null,
            $outcome === DeleteOutcome::Deleted
                ? $what . ' "%s" deleted (EXT:rdlab_unused_files)'
                : $what . ' "%s" moved to recycler (EXT:rdlab_unused_files)',
            [$identifier]
        );
    }
}
