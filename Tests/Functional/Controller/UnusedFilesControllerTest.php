<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use RDLAB\UnusedFiles\Controller\UnusedFilesController;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class UnusedFilesControllerTest extends FunctionalTestCase
{
    private const ROUTE = 'file_rdlab_unused_files';

    // the list links every file to its folder in the filelist module
    protected array $coreExtensionsToLoad = ['filelist'];
    protected array $testExtensionsToLoad = ['rdlab/unused-files'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/files.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        // the test instance is shared by all tests of this class, so start from a clean folder
        GeneralUtility::rmdir($this->instancePath . '/fileadmin/user_upload', true);
        GeneralUtility::rmdir($this->instancePath . '/fileadmin/_recycler_', true);
        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/user_upload');
        foreach (['unused.pdf', 'metadata-only.jpg', 'deleted-ref.png', 'linked.pdf'] as $name) {
            file_put_contents($this->instancePath . '/fileadmin/user_upload/' . $name, $name);
        }
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private function dispatch(string $method, array $query = [], array $body = []): ResponseInterface
    {
        $request = (new ServerRequest('https://typo3-testing.local/typo3/module/file/rdlab-unused-files', $method))
            ->withQueryParams($query)
            ->withParsedBody($body)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $this->get(Router::class)->getRoute(self::ROUTE))
            ->withAttribute('module', $this->get(ModuleProvider::class)->getModule(self::ROUTE, $GLOBALS['BE_USER']));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $this->get(UnusedFilesController::class)->handleRequest($request);
    }

    /**
     * Query arguments of the redirect target, without the CSRF token.
     *
     * @return array<mixed>
     */
    private function redirectArguments(ResponseInterface $response): array
    {
        self::assertSame(303, $response->getStatusCode());
        parse_str((string)parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $arguments);
        unset($arguments['token']);
        return $arguments;
    }

    /**
     * @return list<FlashMessage>
     */
    private function flashMessages(): array
    {
        return $this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessagesAndFlush();
    }

    #[Test]
    public function listShowsUnusedFilesOfTheSelectedFolder(): void
    {
        $response = $this->dispatch('GET', ['id' => '1:/user_upload/']);

        self::assertSame(200, $response->getStatusCode());
        $html = (string)$response->getBody();
        self::assertStringContainsString('unused.pdf', $html);
        self::assertStringContainsString('metadata-only.jpg', $html);
        self::assertStringNotContainsString('deleted-ref.png', $html);
        self::assertStringNotContainsString('by-path.pdf', $html);
        self::assertStringNotContainsString('linked.pdf', $html);
        self::assertStringNotContainsString('Old_Report.pdf', $html);
        self::assertStringNotContainsString('second.pdf', $html);
    }

    #[Test]
    public function listWithoutFolderAppliesFilterAcrossStorages(): void
    {
        $response = $this->dispatch('GET', ['filter' => ['extension' => 'pdf', 'search' => 'second']]);

        self::assertSame(200, $response->getStatusCode());
        $html = (string)$response->getBody();
        self::assertStringContainsString('second.pdf', $html);
        self::assertStringNotContainsString('unused.pdf', $html);
    }

    #[Test]
    public function pageBeyondLastPageFallsBackToLastPage(): void
    {
        $response = $this->dispatch('GET', ['filter' => ['page' => '99']]);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('unused.pdf', (string)$response->getBody());
    }

    #[Test]
    public function csvActionReturnsCsvDownload(): void
    {
        $response = $this->dispatch('GET', ['action' => 'csv', 'id' => '1:/user_upload/', 'filter' => ['extension' => 'pdf']]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('attachment; filename="unused-files-', $response->getHeaderLine('Content-Disposition'));
        $lines = explode("\n", trim((string)$response->getBody()));
        self::assertCount(2, $lines);
        self::assertStringStartsWith('uid,storage,path,folder,name,', $lines[0]);
        self::assertStringStartsWith('1,1,/user_upload/unused.pdf,/user_upload/,unused.pdf,pdf,100,', $lines[1]);
    }

    #[Test]
    public function filterActionRedirectsToFirstPageWithNormalisedFilter(): void
    {
        $response = $this->dispatch('POST', ['id' => '1:/user_upload/'], [
            'action' => 'filter',
            'filter' => ['extension' => 'PDF', 'search' => ' report ', 'page' => '3'],
        ]);

        self::assertEquals(
            ['id' => '1:/user_upload/', 'filter' => ['extension' => 'pdf', 'search' => 'report']],
            $this->redirectArguments($response)
        );
    }

    #[Test]
    public function unknownPostActionRedirectsToListKeepingFilter(): void
    {
        $response = $this->dispatch('POST', [], ['filter' => ['storage' => '2', 'page' => '2']]);

        self::assertEquals(['filter' => ['storage' => '2', 'page' => '2']], $this->redirectArguments($response));
    }

    #[Test]
    public function deleteSingleRemovesFileAndRedirects(): void
    {
        $this->get(Registry::class)->set('core', 'sys_refindex_lastUpdate', time());
        $response = $this->dispatch('POST', ['id' => '1:/user_upload/'], ['deleteSingle' => '1', 'filter' => ['page' => '2']]);

        self::assertEquals(['id' => '1:/user_upload/', 'filter' => ['page' => '2']], $this->redirectArguments($response));
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/user_upload/unused.pdf');
        $messages = $this->flashMessages();
        self::assertCount(1, $messages);
        self::assertSame(ContextualFeedbackSeverity::OK, $messages[0]->getSeverity());
        self::assertSame('1 files removed', $messages[0]->getTitle());
        self::assertSame('0 deleted, 1 moved to the recycler folder.', $messages[0]->getMessage());
    }

    #[Test]
    public function deleteSelectedSkipsReferencedAndUnknownFiles(): void
    {
        $this->get(Registry::class)->set('core', 'sys_refindex_lastUpdate', time());
        $response = $this->dispatch('POST', ['id' => '1:/user_upload/'], ['action' => 'deleteSelected', 'files' => ['1', '3', '999']]);

        self::assertSame(303, $response->getStatusCode());
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/user_upload/unused.pdf');
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/linked.pdf');
        $messages = $this->flashMessages();
        self::assertCount(2, $messages);
        self::assertSame('1 files removed', $messages[0]->getTitle());
        self::assertSame(ContextualFeedbackSeverity::INFO, $messages[1]->getSeverity());
        self::assertSame('2 files were skipped because they are referenced by now or no longer exist.', $messages[1]->getMessage());
    }

    #[Test]
    public function deleteAllRemovesMatchingFilesUpToBulkLimit(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['rdlab_unused_files']['bulkDeleteLimit'] = '1';
        $this->get(Registry::class)->set('core', 'sys_refindex_lastUpdate', time());

        $response = $this->dispatch('POST', ['id' => '1:/user_upload/'], ['action' => 'deleteSelected', 'selectAll' => '1']);

        self::assertSame(303, $response->getStatusCode());
        // the folder holds four unused files, ordered by identifier the first one is deleted
        self::assertFileDoesNotExist($this->instancePath . '/fileadmin/user_upload/metadata-only.jpg');
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/unused.pdf');
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/deleted-ref.png');
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/linked.pdf');
        $messages = $this->flashMessages();
        self::assertCount(2, $messages);
        self::assertSame('1 files removed', $messages[0]->getTitle());
        self::assertSame(
            '3 more files match the filter. At most 1 files are deleted per run, repeat "Delete all" for the rest.',
            $messages[1]->getMessage()
        );
    }

    #[Test]
    public function deleteAllIsRefusedWithStaleReferenceIndex(): void
    {
        $response = $this->dispatch('POST', ['id' => '1:/user_upload/'], ['action' => 'deleteSelected', 'selectAll' => '1']);

        self::assertSame(303, $response->getStatusCode());
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/metadata-only.jpg');
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/unused.pdf');
        $messages = $this->flashMessages();
        self::assertCount(1, $messages);
        self::assertSame(ContextualFeedbackSeverity::ERROR, $messages[0]->getSeverity());
        self::assertSame(
            'The reference index is older than 24 hours. Run "typo3 referenceindex:update" before deleting files.',
            $messages[0]->getMessage()
        );
    }

    #[Test]
    public function deleteSingleIsRefusedWithStaleReferenceIndex(): void
    {
        $response = $this->dispatch('POST', ['id' => '1:/user_upload/'], ['deleteSingle' => '1']);

        self::assertSame(303, $response->getStatusCode());
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/unused.pdf');
        self::assertSame(ContextualFeedbackSeverity::ERROR, $this->flashMessages()[0]->getSeverity());
    }

    #[Test]
    public function deleteSelectedIsRefusedWithStaleReferenceIndex(): void
    {
        $response = $this->dispatch('POST', ['id' => '1:/user_upload/'], ['action' => 'deleteSelected', 'files' => ['1']]);

        self::assertSame(303, $response->getStatusCode());
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/unused.pdf');
        self::assertSame(ContextualFeedbackSeverity::ERROR, $this->flashMessages()[0]->getSeverity());
    }

    #[Test]
    public function deleteSelectedWithoutFilesShowsWarning(): void
    {
        $this->get(Registry::class)->set('core', 'sys_refindex_lastUpdate', time());
        $response = $this->dispatch('POST', [], ['action' => 'deleteSelected']);

        self::assertSame(303, $response->getStatusCode());
        self::assertFileExists($this->instancePath . '/fileadmin/user_upload/unused.pdf');
        $messages = $this->flashMessages();
        self::assertCount(1, $messages);
        self::assertSame(ContextualFeedbackSeverity::WARNING, $messages[0]->getSeverity());
        self::assertSame('No files were selected.', $messages[0]->getMessage());
    }

    #[Test]
    public function moduleIsNotAvailableForNonAdminUsers(): void
    {
        $editor = $this->setUpBackendUser(2);

        self::assertNull($this->get(ModuleProvider::class)->getModule(self::ROUTE, $editor));
    }
}
