<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use RDLAB\UnusedFiles\Service\ExtensionSettings;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ExtensionSettingsTest extends UnitTestCase
{
    /**
     * @param array<string, mixed> $values
     */
    private function createSettings(array $values): ExtensionSettings
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnCallback(
            static fn (string $extension, string $path): mixed => $values[$path] ?? ''
        );
        return new ExtensionSettings($extensionConfiguration);
    }

    #[Test]
    public function defaultsApplyWhenExtensionIsNotConfigured(): void
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new ExtensionConfigurationExtensionNotConfiguredException('', 1));
        $settings = new ExtensionSettings($extensionConfiguration);

        self::assertSame(['/_processed_/', '/_temp_/', '/_recycler_/'], $settings->getExcludedFolders());
        self::assertSame(['index.html', '.htaccess'], $settings->getExcludedFileNames());
        self::assertSame(200, $settings->getBulkDeleteLimit());
        self::assertSame(24, $settings->getReferenceIndexMaxAgeHours());
    }

    #[Test]
    public function emptyValuesFallBackToDefaults(): void
    {
        $settings = $this->createSettings([]);

        self::assertSame(['/_processed_/', '/_temp_/', '/_recycler_/'], $settings->getExcludedFolders());
        self::assertSame(['index.html', '.htaccess'], $settings->getExcludedFileNames());
        self::assertSame(200, $settings->getBulkDeleteLimit());
        self::assertSame(24, $settings->getReferenceIndexMaxAgeHours());
    }

    #[Test]
    public function excludedFoldersAreNormalisedAndDeduplicated(): void
    {
        $settings = $this->createSettings(['excludedFolders' => 'templates, /templates/ ,/a/b,/, ,c/']);

        self::assertSame(['/templates/', '/a/b/', '/c/'], $settings->getExcludedFolders());
    }

    #[Test]
    public function excludedFileNamesAreTrimmedAndDeduplicated(): void
    {
        $settings = $this->createSettings(['excludedFileNames' => ' robots.txt,,robots.txt , .keep']);

        self::assertSame(['robots.txt', '.keep'], $settings->getExcludedFileNames());
    }

    #[Test]
    public function numericSettingsAreAtLeastOne(): void
    {
        $settings = $this->createSettings(['bulkDeleteLimit' => '0', 'referenceIndexMaxAgeHours' => '-5']);

        self::assertSame(1, $settings->getBulkDeleteLimit());
        self::assertSame(1, $settings->getReferenceIndexMaxAgeHours());
    }

    #[Test]
    public function numericSettingsAreRead(): void
    {
        $settings = $this->createSettings(['bulkDeleteLimit' => '50', 'referenceIndexMaxAgeHours' => 6]);

        self::assertSame(50, $settings->getBulkDeleteLimit());
        self::assertSame(6, $settings->getReferenceIndexMaxAgeHours());
    }
}
