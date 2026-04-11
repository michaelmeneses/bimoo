# Bimoo — Moodle Stub Generator

Bimoo generates PHP stub files (`.stub.php`) for the [Moodle LMS](https://moodle.org/) codebase. These stubs help IDEs and static analysis tools (PHPStan, Psalm, PhpStorm) understand Moodle's classes, functions, constants, and global variables without requiring a full Moodle installation.

## Features

- **Full coverage** — Parses all PHP files in a Moodle installation recursively
- **Multi-file output** — Generates `.stub.php` files mirroring Moodle's directory structure
- **Moodle-aware** — Handles global variables (`$DB`, `$CFG`, `$USER`, `$PAGE`, `$OUTPUT`), `define()` constants, and procedural functions
- **AST-based** — Uses `nikic/php-parser` for accurate parsing (no regex)
- **Version tags** — Mirrors Moodle version tags (`v4.0.0`, `v4.3.2`, `v5.0.0`, etc.) for Composer version pinning
- **Automated** — GitHub Actions syncs stubs across all Moodle stable branches and tags weekly

## Generated Stubs

The generated stubs are published to a separate repository:

**[michaelmeneses/moodle-stubs](https://github.com/michaelmeneses/moodle-stubs)**

- **Branches:** `MOODLE_400_STABLE`, `MOODLE_403_STABLE`, `MOODLE_500_STABLE`, `master`, etc.
- **Tags:** `v4.0.0`, `v4.3.2`, `v5.0.0`, etc. (mirrors Moodle release tags from v4.0.0+)

## Usage

### Generate stubs locally

```bash
# Install
git clone https://github.com/michaelmeneses/bimoo.git
cd bimoo
composer install

# Generate stubs from a local Moodle installation
php bin/bimoo generate:stubs /path/to/moodle --output=./stubs

# Options
php bin/bimoo generate:stubs /path/to/moodle \
    --output=./stubs \
    --include-tests \
    --exclude="mod/legacy" \
    -v
```

### Sync branches

```bash
php bin/bimoo sync:branches \
    --stubs-repo=git@github.com:michaelmeneses/moodle-stubs.git \
    --branches="MOODLE_405*" \
    --dry-run
```

### Sync tags

```bash
# Sync all tags from v4.0.0+
php bin/bimoo sync:tags \
    --stubs-repo=git@github.com:michaelmeneses/moodle-stubs.git \
    --dry-run

# Sync specific tags
php bin/bimoo sync:tags \
    --stubs-repo=git@github.com:michaelmeneses/moodle-stubs.git \
    --tags="v4.3.2,v5.0.0"
```

### Discover updates (CI)

```bash
# Output JSON of branches/tags that need updating (for GitHub Actions matrix)
php bin/bimoo discover:updates \
    --moodle-repo=git://git.moodle.org/moodle.git \
    --stubs-repo=git@github.com:michaelmeneses/moodle-stubs.git \
    --stubs-repo-slug=michaelmeneses/moodle-stubs
```

## Commands

| Command | Description |
|---|---|
| `generate:stubs` | Generate `.stub.php` files from a local Moodle installation |
| `sync:branches` | Sync stubs across Moodle branches with SHA-based skip logic |
| `sync:tags` | Create stubs for Moodle version tags (v4.0.0+) |
| `discover:updates` | Discover branches/tags needing updates (JSON output for CI) |

## Composer Scripts

| Script | Description |
|---|---|
| `composer test` | Run PHPUnit tests |
| `composer test:coverage` | Run tests with coverage report |
| `composer cs:check` | Check code style (PSR-12) |
| `composer cs:fix` | Auto-fix code style |
| `composer analyse` | Run PHPStan static analysis (level 6) |
| `composer quality` | Run all checks: cs:check + analyse + test |

## Requirements

- PHP 8.1+
- Composer

## Architecture

See [docs/adr/](docs/adr/) for Architecture Decision Records.

## Sponsors

Maintained by [Michael Meneses](https://github.com/michaelmeneses) and supported by [MIDDAG](https://middag.com.br) — Moodle development and technical services. Check out [MIDDAG for Moodle](https://middag.io), a plugin suite and development framework for Moodle.

## Credits

This project is a fork of [machitgarha/bimoo](https://github.com/machitgarha/bimoo), created by [Mohammad Amin Chitgarha](https://github.com/machitgarha). The original work laid the foundation for Moodle stub generation.

## License

Licensed under [GPL 3.0](./LICENSE.md).
