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
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\SysLog\Action\File as SystemLogFileAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\SysLog\Type as SystemLogType;

/**
 * Deletes a file through FAL after re-checking that it is still unreferenced.
 * FAL applies the backend user's file permissions and moves the file to the
 * nearest _recycler_ folder when the storage has one.
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

        return $this->remove($file, 'Unused file');
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
