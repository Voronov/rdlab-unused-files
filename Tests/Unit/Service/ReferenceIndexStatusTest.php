<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use RDLAB\UnusedFiles\Service\ExtensionSettings;
use RDLAB\UnusedFiles\Service\ReferenceIndexStatus;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Registry;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ReferenceIndexStatusTest extends UnitTestCase
{
    private function createStatus(mixed $lastUpdate, int $maxAgeHours = 24): ReferenceIndexStatus
    {
        $registry = $this->createMock(Registry::class);
        $registry->method('get')->with('core', 'sys_refindex_lastUpdate')->willReturn($lastUpdate);

        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnCallback(
            static fn(string $extension, string $path): mixed => $path === 'referenceIndexMaxAgeHours' ? $maxAgeHours : ''
        );

        return new ReferenceIndexStatus($registry, new ExtensionSettings($extensionConfiguration));
    }

    #[Test]
    public function neverUpdatedIndexIsStale(): void
    {
        $status = $this->createStatus(null);

        self::assertNull($status->getLastUpdate());
        self::assertTrue($status->isStale());
        self::assertStringEndsWith('reference index update never', $status->getStatusText());
    }

    #[Test]
    public function zeroTimestampCountsAsNeverUpdated(): void
    {
        self::assertNull($this->createStatus(0)->getLastUpdate());
    }

    #[Test]
    public function lastUpdateIsReturnedAsDate(): void
    {
        $timestamp = time() - 60;
        $status = $this->createStatus($timestamp);

        self::assertSame($timestamp, $status->getLastUpdate()?->getTimestamp());
        self::assertStringEndsWith(
            (new \DateTimeImmutable())->setTimestamp($timestamp)->format('Y-m-d H:i'),
            $status->getStatusText()
        );
    }

    #[Test]
    public function recentUpdateIsNotStale(): void
    {
        self::assertFalse($this->createStatus(time() - 3600, 24)->isStale());
    }

    #[Test]
    public function updateOlderThanMaxAgeIsStale(): void
    {
        self::assertTrue($this->createStatus(time() - 3 * 3600, 2)->isStale());
    }
}
