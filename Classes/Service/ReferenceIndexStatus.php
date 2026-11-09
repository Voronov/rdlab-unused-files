<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Service;

use TYPO3\CMS\Core\Registry;

final class ReferenceIndexStatus
{
    public function __construct(
        private readonly Registry $registry,
        private readonly ExtensionSettings $settings,
    ) {}

    public function getLastUpdate(): ?\DateTimeImmutable
    {
        $timestamp = (int)($this->registry->get('core', 'sys_refindex_lastUpdate') ?? 0);
        if ($timestamp <= 0) {
            return null;
        }
        return (new \DateTimeImmutable())->setTimestamp($timestamp);
    }

    public function isStale(): bool
    {
        $lastUpdate = $this->getLastUpdate();
        if ($lastUpdate === null) {
            return true;
        }
        return $lastUpdate->getTimestamp() < time() - $this->settings->getReferenceIndexMaxAgeHours() * 3600;
    }

    /**
     * Human readable status text used in the CSV "status" column.
     */
    public function getStatusText(): string
    {
        $lastUpdate = $this->getLastUpdate();
        return 'No references in TYPO3 (sys_refindex, sys_file_reference, paths in content) as of reference index update '
            . ($lastUpdate === null ? 'never' : $lastUpdate->format('Y-m-d H:i'));
    }
}
