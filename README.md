# Composer Persistent Package Cache

A Composer 2 plugin that keeps validated ZIP distribution archives for explicitly allowlisted packages inside the project, so they can be committed and reused by CI or offline installs.

## Support and design

- PHP 8.1+ with `ext-zip`.
- Composer 2.1+ (`composer-plugin-api` `^2.1`).
- Distribution ZIP archives only. Source/VCS installs and other distribution formats remain under Composer's normal behavior and are not cached.
- Composer 2.0 is unsupported because package identity is not available from its pre-download event API.

**Interception strategy:** Composer 2.9.4 was inspected before implementation. `PreFileDownloadEvent::getContext()` exposes the package; `FileDownloader` applies the event's processed URL before its archive-cache lookup; and `PostFileDownloadEvent` supplies the package and downloaded temporary file. A valid project-local archive is selected by substituting its `file://` URL before Composer performs its normal cache lookup, then Composer's downloader, locked SHA-1 check (if the lock provides one), ZIP extraction, autoload generation, and install lifecycle are retained. This avoids using a global-cache entry keyed to the remote distribution URL. Composer may still reuse a local-URL-keyed entry from an earlier project-cache hit, as described below. On a project-cache miss the URL is not changed, so Composer keeps its normal global-cache and repository download behavior.

The post-download event allows the plugin to copy the successfully retrieved archive (including an archive Composer just read from its normal cache) into the project cache. The plugin does not replace Composer's installer, extract archives itself, inspect `vendor/`, change any Composer cache configuration, or write to `composer.lock`.

**Composer API limitation:** Composer's public download events cannot tell its `FileDownloader` to skip the global archive cache. Consequently, a project-cache hit is read by Composer's normal downloader and may create a duplicate entry in Composer's global archive cache, keyed by the local `file://` URL. The plugin does not change the global cache configuration or remove/overwrite existing remote-keyed cache entries, but strict zero-write preservation of global cache contents is not achievable using these supported hooks. Avoiding even that Composer-native duplicate would require replacing Composer's archive downloader and reproducing its private temporary-file conventions, which is deliberately not done because it is version-fragile and risks bypassing Composer's installation safeguards.

Some custom repository schemes may have URL-rewriting plugins of their own; if another plugin replaces the processed URL after this plugin, it may supersede the local hit. Private repositories work when Composer exposes a normal ZIP distribution and locked package metadata; credentials and URL query strings are not used as cache identity or written to metadata.

## Installation

The Composer package name is `hoarseleg/composer-persistent-cache` and its PHP namespace is `HoarseLeg\PersistentCache`. Add the plugin to the consuming project and explicitly allow it:

```sh
composer require hoarseleg/composer-persistent-cache
```

```json
{
  "config": {
    "allow-plugins": {
      "hoarseleg/composer-persistent-cache": true
    }
  }
}
```

Composer 2.2+ requires explicit plugin permission. Only allow plugins you trust: a Composer plugin runs PHP code as part of Composer.

## Configuration

Add the allowlist to the **root project's** `composer.json`. Installed dependencies cannot supply or widen it.

```json
{
  "require": {
    "vendor/package-a": "^2.0",
    "vendor/package-b": "^1.0"
  },
  "extra": {
    "persistent-cache": {
      "enabled": true,
      "directory": ".composer-cache",
      "packages": [
        "vendor/package-a",
        "vendor/package-b"
      ]
    }
  }
}
```

`enabled` must be a boolean, `directory` must be a safe project-relative path, and `packages` must be a list of Composer package names. Names are compared case-insensitively and stored lowercase. Missing configuration disables the feature; an empty allowlist caches nothing. Invalid configuration stops plugin activation with a diagnostic. Packages not on the allowlist are never written to this cache.

The cache key is a SHA-256 digest of the normalized package name, exact locked version, and distribution reference. ZIP entries are stored at:

```text
.composer-cache/
└── packages/
    └── vendor/
        └── package-a/
            └── <cache-key>/
                ├── package.zip
                └── metadata.json
```

Each metadata file records schema version, normalized package name, package version, distribution reference, distribution type, archive filename, SHA-256 checksum, archive size, and creation time. Credentials and signed distribution URLs are never persisted.

## Git, CI, and offline installation

Keep the project cache in Git. A typical `.gitignore` is:

```gitignore
/vendor/
!/.composer-cache/
/.composer-cache/.write.lock
```

The lock file is an internal writer synchronization file; it is safe to ignore. Do not ignore `.composer-cache/` itself.
If `directory` is customized, update the lock-file ignore pattern to match that cache root.

After resolving new package versions, review and explicitly commit the updated cache with the lock file:

```sh
composer update vendor/package-a
git add composer.json composer.lock .composer-cache/
git commit -m "Update package-a and persistent cache"
```

CI checks out the committed archives and uses the same configuration. If CI obtains an allowlisted archive on a cache miss, it is kept in that job's workspace; the plugin never runs Git commands or commits it automatically.

For offline installs, commit the matching ZIP and metadata generated for the exact locked version and distribution reference. Then run:

```sh
composer install --no-interaction
```

A corrupt or mismatched entry is treated as a cache miss. If the repository is unavailable and no valid project-cache entry exists, Composer's normal download failure is preserved.

## Validation and cleanup

Validate allowlisted packages against the root lock file without changing files:

```sh
composer persistent-cache:validate
```

The command reports valid, missing, invalid, unsupported, and stale entries. Missing, invalid, or stale entries produce a non-zero exit status. It does not delete or repair entries.

If an allowlisted package is already installed at the exact version in `composer.lock`, you can create a persistent cache archive directly from its files under Composer's `vendor-dir`:

```sh
composer persistent-cache:pack vendor/package-a
```

The command verifies the package is allowlisted and locked, checks the installed version/reference, and requires its resolved install path to be inside `vendor/`. It writes a normal ZIP archive containing the installed package files and stores it with `archiveSource: "vendor"` metadata. Symlinks and non-regular filesystem entries are rejected. Review the installed files before packing: this command intentionally captures the contents currently present in `vendor/`, which may include local modifications.

A vendor-packed ZIP is newly assembled and cannot match the original distribution archive's locked SHA-1 checksum. Therefore `persistent-cache:pack` refuses packages whose lock entry has a non-empty `dist.shasum`; Composer would reject the repacked archive during installation. When the lock has no distribution SHA-1, the repacked archive is checked using cache metadata SHA-256 and size, while package name, version, and distribution reference remain bound to the lock. Archives captured from Composer's distribution download continue to require the lock's SHA-1 when one is provided.

To invalidate a package manually, remove its specific directory under `.composer-cache/packages/<vendor>/<package>/` and reinstall while the repository is available. To remove all persistent archives, delete `.composer-cache/packages/`; the plugin does not perform automatic cleanup.

## Integrity and security

Before reuse, the plugin verifies metadata schema and identity, the archive size and SHA-256 checksum, ZIP central-directory consistency, and the lock's distribution SHA-1 checksum where present. A cache entry is never accepted for another version or distribution reference. Cache writes use temporary files and atomic renames, serialized by a project-local lock file. Cache paths are generated from validated package names and a fixed hash; symlinked cache path components and symlinked archive/metadata files are rejected.

A SHA-256 checksum stored beside an archive detects accidental corruption, but does not prove authenticity if both files are changed together. Commit cache changes through the same review and trust process as dependency and lock-file changes. Composer remains responsible for archive extraction; validation never executes package code.

The plugin does not alter `composer.lock`, Composer's global `cache-dir` or `cache-files-dir`, existing global cache entries, repository configuration, or Git history. Composer itself may add the local-URL-keyed duplicate described above.

## Development

```sh
composer install
vendor/bin/phpunit
composer validate --no-check-publish
```

GitHub Actions runs the metadata validation and PHPUnit suite on pushes, pull requests, and manual dispatches using PHP 8.4. The offline integration test uses a local ZIP and a temporary `COMPOSER_HOME`. It removes the test archive endpoint and the test Composer cache before reinstalling, then checks both installed files and byte-for-byte lock-file invariance.
