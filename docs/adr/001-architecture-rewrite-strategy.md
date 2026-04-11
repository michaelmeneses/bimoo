# ADR-001: Bimoo Architectural Rewrite — Strategy and Decisions

- **Status:** Accepted
- **Date:** 2026-04-11
- **Authors:** Michael Meneses
- **Original project:** [machitgarha/bimoo](https://github.com/machitgarha/bimoo) (forked to michaelmeneses/bimoo)

## Context

The `bimoo` repository is a fork of the original project created by Mohammad Amin Chitgarha to generate PHP stubs for Moodle LMS. The original code is outdated:

- Covers only ~22 manually listed files/globs
- Generates a single monolithic `stubs.php` file (~37k lines)
- No CI/CD automation
- No support for multiple Moodle branches
- Thin wrapper over `php-stubs/generator` (last release: 2022)

Moodle has ~3M lines of PHP code, heavy use of global variables (`$DB`, `$CFG`, `$USER`, `$PAGE`, `$OUTPUT`), procedural functions, constants via `define()`, and an extensible plugin architecture. A stub generator needs to handle all of these specifics.

## Decision

Rewrite the stub generator from scratch, keeping the `bimoo` repository as the CLI tool home and creating a separate repository (`moodle-stubs`) for the generated output.

### Consolidated Decisions

| Aspect             | Decision                                                 | Rationale                                                        |
|--------------------|----------------------------------------------------------|------------------------------------------------------------------|
| **Coverage**       | Full (`**/*.php` recursive)                              | No developer wants to discover a missing stub mid-work           |
| **Output format**  | Multiple `.stub.php` files mirroring Moodle structure    | Readable diffs, better IDE indexing, direct debugging            |
| **Distribution**   | Phase 1: GitHub branches. Phase 2: Packagist             | Automation first, publishing later                               |
| **Repositories**   | Separate: `bimoo` (generator) + `moodle-stubs` (product) | Clean Packagist package, independent versioning                  |
| **Parsing engine** | `nikic/php-parser` directly (no `php-stubs/generator`)   | Full output control, native multi-file, Moodle-specific handling |
| **Naming**         | `michaelmeneses/moodle-stubs` on Packagist               | Credit to original author preserved in README and LICENSE        |

## Approaches Considered

### A. Wrapper over `php-stubs/generator` (evolution of current bimoo)

- **Pros:** Minimal parsing code, battle-tested library
- **Cons:** Generates single file (requires post-processing for multi-file), last release in 2022, less control over output
- **Decision:** Rejected — fighting the tool for multi-file output

### B. Custom engine with `nikic/php-parser` (chosen)

- **Pros:** Full control, native multi-file, Moodle-specific global variable handling, ~300-500 lines of core logic
- **Cons:** More code to write initially
- **Decision:** Accepted — `nikic/php-parser` is maintained by PHP core contributor (Nikita Popov), very low risk

### C. Hybrid (`php-stubs/generator` for parsing + custom output)

- **Pros:** Reuses existing parsing
- **Cons:** Coupling with unstable internal API, complexity of understanding internals
- **Decision:** Rejected — coupling cost not worth it

## Architecture

### Repositories

```
michaelmeneses/bimoo           → CLI tool (generator)
  - branch: master
  - PHP 8.1+, nikic/php-parser, Symfony Console
  - GitHub Actions for automation

michaelmeneses/moodle-stubs    → Generated product (stubs)
  - branches: MOODLE_401_STABLE, MOODLE_402_STABLE, ..., master
  - Stubs only + composer.json for Packagist
```

### Generator Structure (`bimoo`)

```
bimoo/
├── bin/
│   └── bimoo                           # CLI entry point
├── src/
│   ├── Console/
│   │   ├── Application.php             # Symfony Console app
│   │   └── Command/
│   │       ├── DiscoverCommand.php     # discover:updates (CI matrix output)
│   │       ├── GenerateCommand.php     # generate:stubs
│   │       ├── SyncCommand.php         # sync:branches
│   │       └── SyncTagsCommand.php     # sync:tags
│   ├── Parser/
│   │   ├── FileParser.php              # Parses a PHP file via php-parser
│   │   └── MoodleSourceDiscovery.php   # Discovers all .php files (Finder)
│   ├── Stub/
│   │   ├── StubGenerator.php           # Orchestrates: discovery → parse → write
│   │   ├── NodeVisitor/
│   │   │   ├── StubNodeVisitor.php     # Removes bodies, preserves signatures + PHPDoc
│   │   │   ├── ConstantCollector.php   # Collects define() and const
│   │   │   └── GlobalVarCollector.php  # Collects $DB, $CFG, $USER, $PAGE, $OUTPUT
│   │   └── Writer/
│   │       └── StubFileWriter.php      # Writes .stub.php preserving relative path
│   └── Git/
│       └── MoodleBranchManager.php     # Clone, checkout branches, push stubs
├── config/
│   └── global-vars.php                 # Known global variables + their types
├── tests/
├── composer.json
└── README.md
```

### Generation Flow (`generate:stubs`)

```
MoodleSourceDiscovery           FileParser                NodeVisitors
  (Symfony Finder)             (nikic/php-parser)         (Stub + Const + Global)
        │                            │                           │
        ▼                            ▼                           ▼
  List all .php files ──→  Parse AST for each ──→  Clean AST (no implementation)
  with smart exclusions      file                        │
                                                         ▼
                                                   StubFileWriter
                                                   (PrettyPrinter)
                                                         │
                                                         ▼
                                                   stubs/lib/moodlelib.stub.php
                                                   stubs/mod/assign/locallib.stub.php
```

### Synchronization Flow (`sync:branches`)

```
1. git clone/fetch git://git.moodle.org/moodle.git (shallow)
2. List branches MOODLE_*_STABLE + master
3. For each branch:
   a. Checkout the branch
   b. Run generate:stubs
   c. Copy stubs to moodle-stubs clone
   d. Commit + push to corresponding branch
```

## Moodle-Specific Handling

### Global Variables

Moodle relies on core global variables that must be present in stubs with their correct types:

| Variable   | Type               | Source       |
|------------|--------------------|--------------|
| `$CFG`     | `\stdClass`        | `config.php` |
| `$DB`      | `\moodle_database` | `setup.php`  |
| `$USER`    | `\stdClass`        | `setup.php`  |
| `$PAGE`    | `\moodle_page`     | `setup.php`  |
| `$OUTPUT`  | `\core_renderer`   | `setup.php`  |
| `$SESSION` | `\stdClass`        | `setup.php`  |
| `$COURSE`  | `\stdClass`        | contextual   |
| `$SITE`    | `\stdClass`        | `setup.php`  |
| `$FULLME`  | `string`           | `setup.php`  |
| `$ME`      | `string`           | `setup.php`  |

These types are defined in `config/global-vars.php` and injected into stubs via `GlobalVarCollector`.

### Constants

Two patterns captured by `ConstantCollector`:

- `define('MOODLE_INTERNAL', true)` — global constants via `define()`
- `const NAME = value` — namespace/class constants

### Exclusions

`MoodleSourceDiscovery` excludes:

- `vendor/`, `node_modules/` — external dependencies
- `.git/` — git metadata
- `tests/` — optional, configurable via CLI flag
- Configuration files (`config.php`, `config-dist.php`)

## Generator Dependencies

| Package                         | Purpose               |
|---------------------------------|-----------------------|
| `nikic/php-parser` ^5.0         | PHP AST parser        |
| `symfony/console` ^6.0\|^7.0    | CLI framework         |
| `symfony/finder` ^6.0\|^7.0     | File discovery        |
| `symfony/filesystem` ^6.0\|^7.0 | Filesystem operations |
| `symfony/process` ^6.0\|^7.0    | Git command execution |

## Consequences

### Positive

- Full control over stub format and content
- Complete automation: new Moodle versions generate stubs automatically
- Granular stubs make debugging and code review easier
- Clear path to Packagist publishing

### Negative

- More code to maintain compared to a wrapper
- Need to track `nikic/php-parser` breaking changes (low risk)

### Risks

- **Performance:** Parsing all of Moodle (~10k+ PHP files) may be slow. Mitigation: file-by-file processing (no full memory load).
- **Parser edge cases:** Legacy Moodle PHP code may have unusual patterns. Mitigation: graceful error handling with logging.

## Credits

This project is a fork of [machitgarha/bimoo](https://github.com/machitgarha/bimoo), created by Mohammad Amin Chitgarha. The original work laid the foundation and the idea for a Moodle stub generator.
