<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ExtensionSettings
{
    private const EXTENSION_KEY = 'rdlab_unused_files';

    public function __construct(private readonly ExtensionConfiguration $extensionConfiguration) {}

    /**
     * Folder identifier prefixes, each normalised to "/folder/".
     *
     * @return list<string>
     */
    public function getExcludedFolders(): array
    {
        $raw = (string)($this->get('excludedFolders') ?? '/_processed_/,/_temp_/,/_recycler_/');
        $result = [];
        foreach (GeneralUtility::trimExplode(',', $raw, true) as $folder) {
            $folder = trim($folder, '/');
            if ($folder !== '') {
                $result[] = '/' . $folder . '/';
            }
        }
        return array_values(array_unique($result));
    }

    /**
     * @return list<string>
     */
    public function getExcludedFileNames(): array
    {
        $raw = (string)($this->get('excludedFileNames') ?? 'index.html,.htaccess');
        return array_values(array_unique(GeneralUtility::trimExplode(',', $raw, true)));
    }

    public function getBulkDeleteLimit(): int
    {
        return max(1, (int)($this->get('bulkDeleteLimit') ?? 200));
    }

    public function getReferenceIndexMaxAgeHours(): int
    {
        return max(1, (int)($this->get('referenceIndexMaxAgeHours') ?? 24));
    }

    private function get(string $path): mixed
    {
        try {
            $value = $this->extensionConfiguration->get(self::EXTENSION_KEY, $path);
        } catch (\Throwable) {
            return null;
        }
        return $value === '' ? null : $value;
    }
}
