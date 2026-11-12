<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RDLAB\UnusedFiles\Domain\DeleteOutcome;
use RDLAB\UnusedFiles\Domain\UnusedFileFilter;
use RDLAB\UnusedFiles\Service\CsvExporter;
use RDLAB\UnusedFiles\Service\ExtensionSettings;
use RDLAB\UnusedFiles\Service\FileDeleter;
use RDLAB\UnusedFiles\Service\FileRestorer;
use RDLAB\UnusedFiles\Service\RecycledFileRepository;
use RDLAB\UnusedFiles\Service\ReferenceIndexStatus;
use RDLAB\UnusedFiles\Service\UnusedFileFinder;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Backend module "File > Unused files".
 *
 * The folder selected in the folder tree arrives as "id" (combined folder
 * identifier like "1:/user_upload/") and scopes the list including subfolders.
 */
#[AsController]
final class UnusedFilesController
{
    private const ROUTE = 'file_rdlab_unused_files';
    private const LLL = 'LLL:EXT:rdlab_unused_files/Resources/Private/Language/locallang.xlf:';
    private const RECYCLED_PER_PAGE = 50;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly PageRenderer $pageRenderer,
        private readonly IconFactory $iconFactory,
        private readonly ResourceFactory $resourceFactory,
        private readonly UnusedFileFinder $finder,
        private readonly FileDeleter $fileDeleter,
        private readonly CsvExporter $csvExporter,
        private readonly ReferenceIndexStatus $referenceIndexStatus,
        private readonly ExtensionSettings $settings,
        private readonly RecycledFileRepository $recycledFiles,
        private readonly FileRestorer $fileRestorer,
        private readonly StorageRepository $storageRepository,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $isPost = $request->getMethod() === 'POST';
        $params = $isPost ? (array)$request->getParsedBody() : $request->getQueryParams();
        $folderId = trim((string)($params['id'] ?? $request->getQueryParams()['id'] ?? ''));
        $filter = UnusedFileFilter::fromArray((array)($params['filter'] ?? []))
            ->withCombinedFolderIdentifier($folderId);

        if ($isPost) {
            $action = (string)($params['action'] ?? (isset($params['deleteSingle']) ? 'deleteSingle' : ''));
            return match ($action) {
                'filter' => $this->redirectToList($filter->withPage(1), $folderId),
                'deleteSingle', 'deleteSelected', 'deleteAll' => $this->handleDelete($request, $action, $filter, $folderId),
                'restore' => $this->handleRestore($request, $folderId),
                default => $this->redirectToList($filter, $folderId),
            };
        }

        if (($params['action'] ?? '') === 'csv') {
            return $this->csvResponse($filter);
        }
        if (($params['action'] ?? '') === 'recycled') {
            return $this->renderRecycled($request, max(1, (int)($params['page'] ?? 1)), $folderId);
        }

        return $this->renderList($request, $filter, $folderId);
    }

    private function renderList(ServerRequestInterface $request, UnusedFileFilter $filter, string $folderId): ResponseInterface
    {
        $this->pageRenderer->loadJavaScriptModule('@rdlab/unused-files/unused-files.js');

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->translate('module.title'));

        $totals = $this->finder->countMatching($filter);
        $pageCount = max(1, (int)ceil($totals['count'] / $filter->itemsPerPage));
        if ($filter->page > $pageCount) {
            $filter = $filter->withPage($pageCount);
        }
        $files = array_map($this->decorateRow(...), $this->finder->findPage($filter));

        $this->addDocHeaderButtons($view, $filter, $folderId);

        $view->assignMultiple([
            'filter' => $filter,
            'filterValues' => $filter->toArray(false),
            'folderId' => $folderId,
            'scopeLabel' => $folderId !== '' ? $folderId : '',
            'files' => $files,
            'total' => $totals['count'],
            'totalSize' => $totals['size'],
            'pagination' => $this->buildPagination($filter, $pageCount, $folderId),
            'multipleStorages' => count($this->finder->getStorages()) > 1,
            'extensions' => $this->finder->getExtensions(),
            'itemsPerPageOptions' => UnusedFileFilter::ALLOWED_ITEMS_PER_PAGE,
            'referenceIndexLastUpdate' => $this->referenceIndexStatus->getLastUpdate(),
            'referenceIndexStale' => $this->referenceIndexStatus->isStale(),
            'referenceIndexMaxAgeHours' => $this->settings->getReferenceIndexMaxAgeHours(),
            'excludedFolders' => $this->settings->getExcludedFolders(),
            'bulkDeleteLimit' => $this->settings->getBulkDeleteLimit(),
            'listUri' => $this->listUri(new UnusedFileFilter(), $folderId),
            'resetUri' => $this->listUri(new UnusedFileFilter(), $folderId),
        ]);

        return $view->renderResponse('UnusedFiles/Index');
    }

    private function handleDelete(ServerRequestInterface $request, string $action, UnusedFileFilter $filter, string $folderId): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $selectAll = (string)($body['selectAll'] ?? '0') === '1';
        $bulk = $action === 'deleteAll' || ($action === 'deleteSelected' && $selectAll);
        $view = $this->moduleTemplateFactory->create($request);
        if ($this->referenceIndexStatus->isStale()) {
            $view->addFlashMessage(
                $this->translate('message.staleIndex', [$this->settings->getReferenceIndexMaxAgeHours()]),
                '',
                ContextualFeedbackSeverity::ERROR
            );
            return $this->redirectToList($filter, $folderId);
        }
        if ($bulk) {
            // "Delete all matching files": in batches of bulkDeleteLimit
            $results = $this->fileDeleter->deleteMatching($filter, $this->settings->getBulkDeleteLimit());
        } else {
            $uids = $action === 'deleteSingle' ? [(int)($body['deleteSingle'] ?? 0)] : array_map('intval', (array)($body['files'] ?? []));
            $uids = array_values(array_unique(array_filter($uids, static fn(int $uid): bool => $uid > 0)));
            $results = array_map($this->fileDeleter->delete(...), $uids);
        }

        if ($results === []) {
            $view->addFlashMessage($this->translate('message.nothingSelected'), '', ContextualFeedbackSeverity::WARNING);
            return $this->redirectToList($filter, $folderId);
        }
        $remaining = $bulk ? $this->finder->countMatching($filter)['count'] : 0;

        $counts = array_fill_keys(array_map(static fn(DeleteOutcome $outcome): string => $outcome->value, DeleteOutcome::cases()), 0);
        $failures = [];
        foreach ($results as $result) {
            $counts[$result->outcome->value]++;
            if ($result->outcome === DeleteOutcome::Failed) {
                $failures[] = $result->identifier . ': ' . $result->message;
            }
        }

        $removed = $counts[DeleteOutcome::Deleted->value] + $counts[DeleteOutcome::MovedToRecycler->value];
        if ($removed > 0) {
            $view->addFlashMessage(
                $this->translate(
                    'message.deleted',
                    [$counts[DeleteOutcome::Deleted->value], $counts[DeleteOutcome::MovedToRecycler->value]]
                ),
                $this->translate('message.deleted.title', [$removed]),
                ContextualFeedbackSeverity::OK
            );
        }
        $skipped = $counts[DeleteOutcome::SkippedReferenced->value] + $counts[DeleteOutcome::SkippedNotFound->value];
        if ($skipped > 0) {
            $view->addFlashMessage($this->translate('message.skipped', [$skipped]), '', ContextualFeedbackSeverity::INFO);
        }
        if ($failures !== []) {
            $view->addFlashMessage(
                implode("\n", array_slice($failures, 0, 5)),
                $this->translate('message.failed.title', [count($failures)]),
                ContextualFeedbackSeverity::ERROR
            );
        }
        if ($remaining > 0) {
            $view->addFlashMessage(
                $this->translate('message.remaining', [$remaining, $this->settings->getBulkDeleteLimit()]),
                '',
                ContextualFeedbackSeverity::INFO
            );
        }

        return $this->redirectToList($filter, $folderId);
    }

    /**
     * Files the extension moved to a recycler folder, with a button to put
     * each one back. Entries whose file is gone from the recycler are dropped.
     */
    private function renderRecycled(ServerRequestInterface $request, int $page, string $folderId): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->translate('recycled.title'));

        $pageCount = max(1, (int)ceil($this->recycledFiles->countAll() / self::RECYCLED_PER_PAGE));
        $page = min($page, $pageCount);
        $files = [];
        foreach ($this->recycledFiles->findPage($page, self::RECYCLED_PER_PAGE) as $row) {
            $storage = $this->storageRepository->findByUid((int)$row['storage']);
            // an offline storage cannot tell whether the file is still there
            if ($storage === null || ($storage->isOnline() && !$storage->hasFile((string)$row['recycler_identifier']))) {
                $this->recycledFiles->remove((int)$row['uid']);
                continue;
            }
            $files[] = [
                'uid' => (int)$row['uid'],
                'name' => (string)$row['name'],
                'originalIdentifier' => (string)$row['original_identifier'],
                'originalFolder' => (int)$row['storage'] . ':' . CsvExporter::folderOf((string)$row['original_identifier']),
                'size' => (int)$row['size'],
                'deletedAt' => (int)$row['deleted_at'],
                'deletedBy' => (string)$row['deleted_by'],
            ];
        }

        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();
        $backButton = $buttonBar->makeLinkButton()
            ->setHref($this->listUri(new UnusedFileFilter(), $folderId))
            ->setTitle($this->translate('module.title'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL));
        $buttonBar->addButton($backButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        $view->assignMultiple([
            'files' => $files,
            'folderId' => $folderId,
            'page' => $page,
            'pageCount' => $pageCount,
            'formUri' => $this->listUri(new UnusedFileFilter(), $folderId),
            'previousUri' => $page > 1 ? $this->listUri(new UnusedFileFilter(), $folderId, ['action' => 'recycled', 'page' => $page - 1]) : '',
            'nextUri' => $page < $pageCount ? $this->listUri(new UnusedFileFilter(), $folderId, ['action' => 'recycled', 'page' => $page + 1]) : '',
        ]);

        return $view->renderResponse('UnusedFiles/Recycled');
    }

    private function handleRestore(ServerRequestInterface $request, string $folderId): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $view = $this->moduleTemplateFactory->create($request);
        $result = $this->fileRestorer->restore((int)($body['restore'] ?? 0));
        if ($result->restored) {
            $view->addFlashMessage($this->translate('message.restored', [$result->identifier]), '', ContextualFeedbackSeverity::OK);
        } else {
            $view->addFlashMessage(
                trim($result->identifier . ': ' . $result->message, ': '),
                $this->translate('message.restoreFailed.title'),
                ContextualFeedbackSeverity::ERROR
            );
        }

        return new RedirectResponse(
            $this->listUri(new UnusedFileFilter(), $folderId, ['action' => 'recycled', 'page' => max(1, (int)($body['page'] ?? 1))]),
            303
        );
    }

    private function csvResponse(UnusedFileFilter $filter): ResponseInterface
    {
        $handle = fopen('php://temp', 'wb+');
        if ($handle === false) {
            throw new \RuntimeException('Could not open temporary stream for the CSV export.', 1761750000);
        }
        $this->csvExporter->write($handle, $filter->withPage(1));
        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        $response = new Response();
        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="unused-files-' . date('Ymd-Hi') . '.csv"')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function redirectToList(UnusedFileFilter $filter, string $folderId): ResponseInterface
    {
        return new RedirectResponse($this->listUri($filter, $folderId), 303);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decorateRow(array $row): array
    {
        $identifier = (string)$row['identifier'];
        $folder = CsvExporter::folderOf($identifier);
        $publicUrl = '';
        try {
            $publicUrl = (string)($this->resourceFactory->getFileObject((int)$row['uid'], $row)->getPublicUrl() ?? '');
        } catch (\Throwable) {
            // File is listed anyway, only the preview link is missing
        }

        return [
            'uid' => (int)$row['uid'],
            'name' => (string)$row['name'],
            'identifier' => $identifier,
            'folder' => $folder,
            'combinedFolderIdentifier' => (int)$row['storage'] . ':' . $folder,
            'extension' => (string)$row['extension'],
            'iconIdentifier' => $this->iconFactory->getIconForFileExtension((string)$row['extension'])->getIdentifier(),
            'size' => (int)$row['size'],
            'createdAt' => (int)$row['creation_date'],
            'modifiedAt' => (int)$row['modification_date'],
            'storage' => (int)$row['storage'],
            'storageName' => (string)($row['storage_name'] ?? ''),
            'publicUrl' => $publicUrl,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPagination(UnusedFileFilter $filter, int $pageCount, string $folderId): array
    {
        $current = $filter->page;
        $window = [];
        for ($page = max(1, $current - 3); $page <= min($pageCount, $current + 3); $page++) {
            $window[] = [
                'page' => $page,
                'uri' => $this->listUri($filter->withPage($page), $folderId),
                'current' => $page === $current,
            ];
        }

        return [
            'current' => $current,
            'pageCount' => $pageCount,
            'window' => $window,
            'firstUri' => $current > 1 ? $this->listUri($filter->withPage(1), $folderId) : '',
            'previousUri' => $current > 1 ? $this->listUri($filter->withPage($current - 1), $folderId) : '',
            'nextUri' => $current < $pageCount ? $this->listUri($filter->withPage($current + 1), $folderId) : '',
            'lastUri' => $current < $pageCount ? $this->listUri($filter->withPage($pageCount), $folderId) : '',
        ];
    }

    /**
     * @param array<string, mixed> $additionalArguments
     */
    private function listUri(UnusedFileFilter $filter, string $folderId, array $additionalArguments = []): string
    {
        $arguments = $additionalArguments;
        if ($folderId !== '') {
            $arguments['id'] = $folderId;
        }
        $filterValues = $filter->toArray($folderId === '');
        if ($filterValues !== []) {
            $arguments['filter'] = $filterValues;
        }
        return (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, $arguments);
    }

    private function addDocHeaderButtons(ModuleTemplate $view, UnusedFileFilter $filter, string $folderId): void
    {
        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();

        $csvButton = $buttonBar->makeLinkButton()
            ->setHref($this->listUri($filter, $folderId, ['action' => 'csv']))
            ->setTitle($this->translate('button.csv'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-download', IconSize::SMALL));
        $buttonBar->addButton($csvButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        $recycledButton = $buttonBar->makeLinkButton()
            ->setHref($this->listUri(new UnusedFileFilter(), $folderId, ['action' => 'recycled']))
            ->setTitle($this->translate('button.recycled', [$this->recycledFiles->countAll()]))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-history', IconSize::SMALL));
        $buttonBar->addButton($recycledButton, ButtonBar::BUTTON_POSITION_LEFT, 2);

        $reloadButton = $buttonBar->makeLinkButton()
            ->setHref($this->listUri($filter, $folderId))
            ->setTitle($this->translate('button.reload'))
            ->setIcon($this->iconFactory->getIcon('actions-refresh', IconSize::SMALL));
        $buttonBar->addButton($reloadButton, ButtonBar::BUTTON_POSITION_RIGHT, 1);

        $shortcutArguments = ['filter' => $filter->toArray($folderId === '')];
        if ($folderId !== '') {
            $shortcutArguments['id'] = $folderId;
        }
        $shortcutButton = $buttonBar->makeShortcutButton()
            ->setRouteIdentifier(self::ROUTE)
            ->setDisplayName($this->translate('module.title'))
            ->setArguments($shortcutArguments);
        $buttonBar->addButton($shortcutButton, ButtonBar::BUTTON_POSITION_RIGHT, 2);
    }

    /**
     * @param list<int|string> $arguments
     */
    private function translate(string $key, array $arguments = []): string
    {
        $label = $this->getLanguageService()->sL(self::LLL . $key);
        return $arguments === [] ? $label : vsprintf($label, $arguments);
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
