<?php

declare(strict_types=1);

namespace RDLAB\UnusedFiles\Domain;

/**
 * Immutable filter for the unused file list (backend module, CLI, CSV export).
 */
final class UnusedFileFilter
{
    public const DEFAULT_ITEMS_PER_PAGE = 50;
    public const ALLOWED_ITEMS_PER_PAGE = [25, 50, 100, 200];

    public function __construct(
        /** 0 = all storages */
        public readonly int $storage = 0,
        /** Folder identifier prefix like "/user_upload/", empty = whole storage */
        public readonly string $folder = '',
        /** Lower-case file extension, empty = all */
        public readonly string $extension = '',
        /** Only files created at least this many days ago, 0 = no limit */
        public readonly int $minAgeDays = 0,
        /** Case-insensitive part of the file name, empty = all */
        public readonly string $search = '',
        public readonly int $page = 1,
        public readonly int $itemsPerPage = self::DEFAULT_ITEMS_PER_PAGE,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $folder = trim((string)($data['folder'] ?? ''));
        $folder = trim($folder, '/');
        if ($folder !== '') {
            $folder = '/' . $folder . '/';
        }

        $itemsPerPage = (int)($data['itemsPerPage'] ?? self::DEFAULT_ITEMS_PER_PAGE);
        if (!in_array($itemsPerPage, self::ALLOWED_ITEMS_PER_PAGE, true)) {
            $itemsPerPage = self::DEFAULT_ITEMS_PER_PAGE;
        }

        return new self(
            storage: max(0, (int)($data['storage'] ?? 0)),
            folder: $folder,
            extension: strtolower(trim((string)($data['extension'] ?? ''), " .\t\n\r")),
            minAgeDays: max(0, (int)($data['minAgeDays'] ?? 0)),
            search: trim((string)($data['search'] ?? '')),
            page: max(1, (int)($data['page'] ?? 1)),
            itemsPerPage: $itemsPerPage,
        );
    }

    public function withPage(int $page): self
    {
        return new self(
            $this->storage,
            $this->folder,
            $this->extension,
            $this->minAgeDays,
            $this->search,
            max(1, $page),
            $this->itemsPerPage,
        );
    }

    /**
     * Returns a filter scoped to a combined folder identifier like "1:/user_upload/",
     * as passed by the backend folder tree in the "id" parameter.
     */
    public function withCombinedFolderIdentifier(string $combinedIdentifier): self
    {
        $combinedIdentifier = trim($combinedIdentifier);
        if ($combinedIdentifier === '' || !str_contains($combinedIdentifier, ':')) {
            return $this;
        }
        [$storage, $folder] = explode(':', $combinedIdentifier, 2);
        $folder = trim($folder, '/');

        return new self(
            max(0, (int)$storage),
            $folder === '' ? '' : '/' . $folder . '/',
            $this->extension,
            $this->minAgeDays,
            $this->search,
            $this->page,
            $this->itemsPerPage,
        );
    }

    /**
     * Non-default values only, suitable for URL arguments and hidden form fields.
     * Storage and folder are left out when the scope comes from the folder tree.
     *
     * @return array<string, int|string>
     */
    public function toArray(bool $withScope = true): array
    {
        $result = [];
        if ($withScope && $this->storage > 0) {
            $result['storage'] = $this->storage;
        }
        if ($withScope && $this->folder !== '') {
            $result['folder'] = $this->folder;
        }
        if ($this->extension !== '') {
            $result['extension'] = $this->extension;
        }
        if ($this->minAgeDays > 0) {
            $result['minAgeDays'] = $this->minAgeDays;
        }
        if ($this->search !== '') {
            $result['search'] = $this->search;
        }
        if ($this->page > 1) {
            $result['page'] = $this->page;
        }
        if ($this->itemsPerPage !== self::DEFAULT_ITEMS_PER_PAGE) {
            $result['itemsPerPage'] = $this->itemsPerPage;
        }
        return $result;
    }

    public function isActive(): bool
    {
        return $this->storage > 0
            || $this->folder !== ''
            || $this->extension !== ''
            || $this->minAgeDays > 0
            || $this->search !== '';
    }
}
