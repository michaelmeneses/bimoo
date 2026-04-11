# ADR-002: Tag Synchronization and CI Optimization

- **Status:** Accepted
- **Date:** 2026-04-11
- **Authors:** Michael Meneses

## Context

ADR-001 established the core architecture: a custom stub generator (`bimoo`) pushing to a separate stubs repository (`moodle-stubs`) via GitHub Actions. The initial design synchronized only branches (`MOODLE_*_STABLE` + `master`) on a weekly cron.

Two gaps were identified:

1. **Tags for Packagist:** Composer requires version tags (e.g., `v4.3.2`) for proper dependency resolution. Without tags, consumers cannot pin to specific Moodle versions.
2. **CI efficiency:** Processing all branches every week wastes time when most branches have no new commits.

## Decision

### Tag Synchronization

Mirror Moodle's version tags (e.g., `v4.0.0`, `v4.3.2`, `v5.0.0`) to the `moodle-stubs` repository. Tags are created on the corresponding stable branch.

**Tag-to-branch mapping:**

- `v4.3.2` → commit on `MOODLE_403_STABLE`
- `v5.0.1` → commit on `MOODLE_500_STABLE`

**Minimum version:** `v4.0.0` (configurable via `--min-version`).

**Immutability:** Tags are never recreated. Once `v4.3.2` exists in `moodle-stubs`, it is skipped on subsequent runs.

**Naming:** Tags use the same names as Moodle (`v4.3.2`), which Packagist recognizes as semver automatically.

### SHA-Based Skip Logic

Each branch in `moodle-stubs` stores the Moodle commit SHA that generated its stubs in a `.bimoo-moodle-sha` file. Before regenerating a branch, the workflow compares the current Moodle HEAD with the stored SHA. If identical, the branch is skipped.

### Parallel CI with Matrix Strategy

The GitHub Actions workflow uses a three-phase approach:

1. **Discover** — Lists branches with changed SHAs and tags that don't exist yet. Outputs JSON for matrix.
2. **Sync branches** — Parallel matrix jobs (up to 5 concurrent) process only changed branches.
3. **Sync tags** — Sequential processing of new tags, grouped by branch for efficiency.

## CLI Commands

| Command            | Purpose                                                  |
|--------------------|----------------------------------------------------------|
| `discover:updates` | List branches/tags needing updates (JSON output for CI)  |
| `sync:branches`    | Sync branches with SHA-based skip and `--force` override |
| `sync:tags`        | Create tags for new Moodle versions                      |
| `generate:stubs`   | Local stub generation (unchanged)                        |

## Consequences

### Positive

- Packagist-ready: consumers can `composer require michaelmeneses/moodle-stubs:^4.3`
- Weekly cron runs in ~2 min when nothing changed (discover only)
- New Moodle releases are detected and processed automatically
- `--force` flag available for manual full regeneration

### Negative

- Initial tag backfill (v4.0.0 to current) requires a long first run (~2-3h)
- SHA tracking adds a `.bimoo-moodle-sha` file to each branch in `moodle-stubs`

## Credits

This project is a fork of [machitgarha/bimoo](https://github.com/machitgarha/bimoo), created by Mohammad Amin Chitgarha.
