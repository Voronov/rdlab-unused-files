# Unused files (`rdlab_unused_files`)

TYPO3 13 extension that finds FAL files which are not referenced anywhere in TYPO3 and lets you export or delete them.

- **Backend module** `File > Unused files`: filterable, paginated list with upload date, size, folder and a direct link to the file. Files can be deleted one by one, as a selection or all at once, each behind a confirmation dialog.
- **CSV report** via CLI (`rdlab:unusedfiles:report`), also usable as a scheduler task.
- **CLI deletion** (`rdlab:unusedfiles:delete`), dry run by default.

## How "unused" is determined

A file is listed when **all** of the following are true:

1. `sys_refindex` has no entry pointing to the file, apart from the `sys_file_metadata` record every file owns. The reference index covers FAL relations, RTE links (`t3://file?uid=...`), link fields and all other soft references TYPO3 knows about.
2. `sys_file_reference` has no row for the file, soft-deleted rows included: a content element in the recycler can be restored together with its file relations. Run `cleanup:deletedrecords` (EXT:lowlevel) first if those files should show up.
3. `sys_refindex` has no string reference (`ref_table = _STRING`) ending with the file path, e.g. an absolute URL to the file in an RTE text or a link field.

Files in `_processed_`, `_temp_` and `_recycler_` (configurable), files marked as missing and the configured file names (`index.html`, `.htaccess`) are never listed or deleted.

**What TYPO3 cannot know:** files that are only used by hard-coded paths in templates, TypoScript or CSS, by folder based usage (file collections of type "folder", TypoScript `FILES` with `folders`, plugins that read a folder directly), by newsletters, by direct URLs on other websites or by search engines. Add such folders to `excludedFolders`, review the list folder by folder before deleting and keep a `_recycler_` folder (see below).

## Installation

```bash
composer require rdlab/unused-files
```

Afterwards flush caches and open `File > Unused files`. The module is available to admin users only: the list covers all storages regardless of file mounts.

## Before you use it

1. **Optional: purge deleted records**, so that files which are only attached to records in the recycler show up:
   ```bash
   vendor/bin/typo3 cleanup:deletedrecords
   ```
2. **Update the reference index**, otherwise the list is incomplete:
   ```bash
   vendor/bin/typo3 referenceindex:update
   ```
   The module and both commands warn when the index is older than the configured maximum age (default 24 hours), and the delete command refuses to run with `--execute` in that case. A scheduler task for `referenceindex:update` is recommended.
3. **Create a `_recycler_` folder** in the storage root (`fileadmin/_recycler_`). FAL then moves deleted files there instead of removing them, so a deletion can be undone until the recycler is emptied.

## Backend module

- The folder selected in the folder tree scopes the list (including subfolders); without a selection all storages are listed.
- Filter by file type, minimum age (days) and file name.
- "Download CSV" in the doc header exports the current filter.
- Selection works like in a mail client: the header checkbox selects the page, a banner then offers to select all files matching the filter. Deleting the whole selection processes at most `bulkDeleteLimit` files per request (default 200) and tells you how many are left.
- Every file is checked again for references immediately before it is deleted, so a file that was referenced after the list was rendered is skipped.
- Deletions are logged to the TYPO3 system log (`Log` module).

## CLI

```bash
# CSV to STDOUT
vendor/bin/typo3 rdlab:unusedfiles:report > unused-files.csv

# CSV to a file, PDFs older than a year in a folder, semicolon delimiter
vendor/bin/typo3 rdlab:unusedfiles:report --output=/tmp/unused.csv --folder=/user_upload/ --extension=pdf --min-age-days=365 --delimiter=';'

# Dry run, then delete
vendor/bin/typo3 rdlab:unusedfiles:delete --folder=/user_upload/ --min-age-days=365
vendor/bin/typo3 rdlab:unusedfiles:delete --folder=/user_upload/ --min-age-days=365 --execute
```

CSV columns: `uid, storage, path, folder, name, extension, size_bytes, created, modified, public_url, status`. The `status` column states that no reference exists as of the reference index update time.

## Configuration

Extension configuration (`Admin Tools > Settings > Extension Configuration > rdlab_unused_files`):

| Option | Default | Meaning |
|---|---|---|
| `excludedFolders` | `/_processed_/,/_temp_/,/_recycler_/` | Folder identifiers (relative to the storage root) that are never listed or deleted. Add folders that are referenced by path from templates, e.g. `/templates/`. |
| `excludedFileNames` | `index.html,.htaccess` | Exact file names that are never listed or deleted. |
| `bulkDeleteLimit` | `200` | Files deleted per "Delete all" request. |
| `referenceIndexMaxAgeHours` | `24` | Age of the reference index after which a warning is shown. |

The same values can be set in `config/system/settings.php` or a YAML configuration under `EXTENSIONS/rdlab_unused_files`.

## Tests

Unit and functional tests use `typo3/testing-framework`; functional tests run against SQLite.
The TYPO3 core testing image provides all required PHP extensions:

```bash
docker run --rm -v "$PWD":/app -w /app ghcr.io/typo3/core-testing-php83:latest composer install
docker run --rm -v "$PWD":/app -w /app ghcr.io/typo3/core-testing-php83:latest composer test:unit
docker run --rm -v "$PWD":/app -w /app -e typo3DatabaseDriver=pdo_sqlite ghcr.io/typo3/core-testing-php83:latest composer test:functional
```

## License

GPL-2.0-or-later
