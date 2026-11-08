<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use RDLAB\UnusedFiles\Domain\DeleteOutcome;
use RDLAB\UnusedFiles\Domain\DeleteResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
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
        $identifier = $file->getCombinedIdentifier();

        if (!$this->finder->isUnreferenced($fileUid)) {
            return new DeleteResult($fileUid, $identifier, DeleteOutcome::SkippedReferenced);
        }

        try {
            $file->getStorage()->deleteFile($file);
        } catch (\Throwable $exception) {
            $this->logger?->error('Deleting unused file failed', ['file' => $identifier, 'exception' => $exception]);
            return new DeleteResult($fileUid, $identifier, DeleteOutcome::Failed, $exception->getMessage());
        }

        $outcome = $file->isDeleted() ? DeleteOutcome::Deleted : DeleteOutcome::MovedToRecycler;
        $this->logger?->info('Unused file removed', ['file' => $identifier, 'outcome' => $outcome->value]);
        $this->writeSystemLog($identifier, $outcome);

        return new DeleteResult($fileUid, $identifier, $outcome);
    }

    private function writeSystemLog(string $identifier, DeleteOutcome $outcome): void
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return;
        }
        $backendUser->writelog(
            SystemLogType::FILE,
            SystemLogFileAction::DELETE,
            SystemLogErrorClassification::MESSAGE,
            0,
            $outcome === DeleteOutcome::Deleted
                ? 'Unused file "%s" deleted (EXT:rdlab_unused_files)'
                : 'Unused file "%s" moved to recycler (EXT:rdlab_unused_files)',
            [$identifier]
        );
    }
}
