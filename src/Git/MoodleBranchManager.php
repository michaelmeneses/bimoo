<?php

declare(strict_types=1);

/**
 * Bimoo — Moodle Stub Generator
 *
 * @author     Michael Meneses <michael@middag.com.br>
 * @copyright  2026 MIDDAG (https://www.middag.com.br)
 * @license    GNU General Public License v3.0 or later
 */

namespace Bimoo\Git;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class MoodleBranchManager
{
    private Filesystem $filesystem;

    public function __construct(
        private readonly string $moodleRepo,
        private readonly string $stubsRepo,
        private readonly string $workDir,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * Clone or fetch Moodle repository as a bare repo.
     * If the bare repo already exists, fetches updates instead of re-cloning.
     */
    public function cloneMoodle(): string
    {
        $dir = $this->workDir . '/moodle';

        if ($this->filesystem->exists($dir . '/HEAD')) {
            // Bare repo already exists — fetch updates
            $this->runGit(['fetch', '--all'], $dir);

            return $dir;
        }

        // Clean up invalid/partial directory before cloning
        if ($this->filesystem->exists($dir)) {
            $this->filesystem->remove($dir);
        }

        $this->runGit(['clone', '--bare', $this->moodleRepo, $dir]);

        return $dir;
    }

    /**
     * Clone or fetch stubs repository.
     */
    public function cloneStubs(): string
    {
        $dir = $this->workDir . '/moodle-stubs';

        if ($this->filesystem->exists($dir . '/.git')) {
            $this->runGit(['fetch', '--all'], $dir);

            return $dir;
        }

        if ($this->filesystem->exists($dir)) {
            $this->filesystem->remove($dir);
        }

        $this->runGit(['clone', $this->stubsRepo, $dir]);

        return $dir;
    }

    /**
     * List remote branches from the Moodle bare repo.
     *
     * @return string[]
     */
    public function listRemoteBranches(string $bareRepoDir): array
    {
        $process = $this->runGit(['branch', '--list', '--format=%(refname:short)'], $bareRepoDir);
        $output = trim($process->getOutput());

        if ($output === '') {
            return [];
        }

        return array_filter(explode("\n", $output));
    }

    /**
     * Checkout Moodle source at a specific branch into a working directory.
     */
    public function checkoutMoodleSource(string $bareRepoDir, string $branch): string
    {
        $sourceDir = $this->workDir . '/moodle-src';

        if ($this->filesystem->exists($sourceDir)) {
            $this->filesystem->remove($sourceDir);
        }

        $this->runGit(['clone', '--branch', $branch, '--depth=1', $bareRepoDir, $sourceDir]);

        return $sourceDir;
    }

    /**
     * Switch or create branch in the stubs repository.
     * Ensures base files (composer.json, README.md, LICENSE) exist from the default branch.
     */
    public function switchStubsBranch(string $stubsDir, string $branch): void
    {
        // Try switching to existing branch
        $process = new Process(['git', 'checkout', $branch], $stubsDir);
        $process->run();

        if (!$process->isSuccessful()) {
            // Create orphan branch if it doesn't exist
            $this->runGit(['checkout', '--orphan', $branch], $stubsDir);
            // Remove any tracked files from previous branch
            $process = new Process(['git', 'rm', '-rf', '.'], $stubsDir);
            $process->run(); // May fail if nothing to remove
        }

        // Ensure base files exist (required for Packagist to recognize the branch)
        $this->ensureBaseFiles($stubsDir);
    }

    /**
     * Copy base files from the default branch if they don't exist.
     * These files are required for Packagist to recognize each branch as a valid package.
     */
    private function ensureBaseFiles(string $stubsDir): void
    {
        $baseFiles = ['composer.json', 'README.md', 'LICENSE'];

        foreach ($baseFiles as $file) {
            if (!$this->filesystem->exists($stubsDir . '/' . $file)) {
                // Try to checkout from main, then master
                foreach (['main', 'master'] as $defaultBranch) {
                    $process = new Process(
                        ['git', 'show', "{$defaultBranch}:{$file}"],
                        $stubsDir,
                    );
                    $process->run();

                    if ($process->isSuccessful()) {
                        file_put_contents($stubsDir . '/' . $file, $process->getOutput());
                        break;
                    }
                }
            }
        }
    }

    /**
     * Commit and push changes in the stubs repository.
     *
     * @return bool True if there were changes to commit
     */
    public function commitAndPush(string $stubsDir, string $branch): bool
    {
        // Stage all changes
        $this->runGit(['add', '-A'], $stubsDir);

        // Check if there are changes
        $process = new Process(['git', 'diff', '--cached', '--quiet'], $stubsDir);
        $process->run();

        if ($process->isSuccessful()) {
            // No changes
            return false;
        }

        $message = self::buildCommitMessage($branch);
        $this->runGit(['commit', '-m', $message], $stubsDir);
        $this->runGit(['push', '-u', 'origin', $branch], $stubsDir);

        return true;
    }

    /**
     * Clean stubs directory (remove old stubs before writing new ones).
     */
    public function cleanStubsDir(string $stubsDir): void
    {
        $stubsPath = $stubsDir . '/stubs';
        if ($this->filesystem->exists($stubsPath)) {
            $this->filesystem->remove($stubsPath);
        }
    }

    /**
     * Clean up the working directory.
     */
    public function cleanup(): void
    {
        if ($this->filesystem->exists($this->workDir)) {
            $this->filesystem->remove($this->workDir);
        }
    }

    /**
     * Filter branch names to only include MOODLE_*_STABLE and master.
     *
     * @param string[] $branches
     *
     * @return string[]
     */
    public static function filterBranches(array $branches, string $pattern = '*'): array
    {
        return array_values(array_filter($branches, function (string $branch) use ($pattern) {
            $isStable = (bool) preg_match('/^MOODLE_\d+_STABLE$/', $branch);
            $isMaster = $branch === 'master';

            if (!$isStable && !$isMaster) {
                return false;
            }

            if ($pattern === '*') {
                return true;
            }

            return fnmatch($pattern, $branch);
        }));
    }

    /**
     * Get the HEAD SHA of a branch in the Moodle bare repo.
     */
    public function getMoodleBranchSha(string $bareRepoDir, string $branch): string
    {
        $process = $this->runGit(['rev-parse', $branch], $bareRepoDir);

        return trim($process->getOutput());
    }

    /**
     * Get remote branch HEADs without cloning (via ls-remote).
     *
     * @return array<string, string> branch name => SHA
     */
    public static function lsRemoteBranches(string $repoUrl): array
    {
        $process = new Process(['git', 'ls-remote', '--heads', $repoUrl]);
        $process->setTimeout(120);
        $process->mustRun();

        $branches = [];
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            if (preg_match('/^([a-f0-9]+)\s+refs\/heads\/(.+)$/', $line, $m)) {
                $branches[$m[2]] = $m[1];
            }
        }

        return $branches;
    }

    /**
     * Read the stored Moodle SHA from a branch in the stubs repo (via GitHub API).
     * Returns null if the file doesn't exist or the branch doesn't exist.
     */
    public static function readStoredSha(string $stubsRepoOwner, string $branch): ?string
    {
        $process = new Process([
            'gh', 'api',
            "repos/{$stubsRepoOwner}/contents/.bimoo-moodle-sha",
            '--jq', '.content',
            '-H', 'Accept: application/vnd.github.v3+json',
            '--method', 'GET',
            '-f', "ref={$branch}",
        ]);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $content = trim($process->getOutput());
        if ($content === '') {
            return null;
        }

        // GitHub API returns base64-encoded content
        $decoded = base64_decode($content, true);

        return $decoded !== false ? trim($decoded) : null;
    }

    /**
     * Write the Moodle SHA to .bimoo-moodle-sha in the stubs working directory.
     */
    public function writeMoodleSha(string $stubsDir, string $sha): void
    {
        file_put_contents($stubsDir . '/.bimoo-moodle-sha', $sha . "\n");
    }

    /**
     * Get remote tags without cloning (via ls-remote).
     *
     * @return array<string, string> tag name => SHA
     */
    public static function lsRemoteTags(string $repoUrl): array
    {
        $process = new Process(['git', 'ls-remote', '--tags', $repoUrl]);
        $process->setTimeout(120);
        $process->mustRun();

        $tags = [];
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            // Skip dereferenced tags (^{})
            if (str_contains($line, '^{}')) {
                continue;
            }
            if (preg_match('/^([a-f0-9]+)\s+refs\/tags\/(.+)$/', $line, $m)) {
                $tags[$m[2]] = $m[1];
            }
        }

        return $tags;
    }

    /**
     * Filter Moodle tags to only include version tags >= minVersion.
     *
     * @param string[] $tagNames
     *
     * @return string[] Sorted tag names
     */
    public static function filterTags(array $tagNames, string $minVersion = '4.0.0'): array
    {
        $filtered = [];

        foreach ($tagNames as $tag) {
            // Match vX.Y.Z pattern
            if (!preg_match('/^v(\d+\.\d+\.\d+)$/', $tag, $m)) {
                continue;
            }

            if (version_compare($m[1], $minVersion, '>=')) {
                $filtered[] = $tag;
            }
        }

        usort($filtered, function (string $a, string $b) {
            return version_compare(ltrim($a, 'v'), ltrim($b, 'v'));
        });

        return $filtered;
    }

    /**
     * Map a Moodle version tag to its stable branch name.
     *
     * e.g., v4.3.2 → MOODLE_403_STABLE, v5.0.1 → MOODLE_500_STABLE
     */
    public static function tagToBranch(string $tag): ?string
    {
        if (!preg_match('/^v(\d+)\.(\d+)\.\d+$/', $tag, $m)) {
            return null;
        }

        $major = $m[1];
        $minor = $m[2];

        return sprintf('MOODLE_%s%02d_STABLE', $major, (int) $minor);
    }

    /**
     * Create an annotated tag in the stubs repository (does not push).
     */
    public function createTag(string $stubsDir, string $tag, string $message): void
    {
        $this->runGit(['tag', '-a', $tag, '-m', $message], $stubsDir);
    }

    /**
     * Commit changes for a tag (without pushing the branch yet).
     *
     * @return bool True if there were changes to commit
     */
    public function commitForTag(string $stubsDir, string $tag): bool
    {
        $this->runGit(['add', '-A'], $stubsDir);

        $process = new Process(['git', 'diff', '--cached', '--quiet'], $stubsDir);
        $process->run();

        if ($process->isSuccessful()) {
            return false;
        }

        $message = "Stubs for Moodle {$tag}\n\nAuto-generated by bimoo";
        $this->runGit(['commit', '-m', $message], $stubsDir);

        return true;
    }

    /**
     * Push a branch to remote.
     */
    public function pushBranch(string $stubsDir, string $branch): void
    {
        $this->runGit(['push', '-u', 'origin', $branch], $stubsDir);
    }

    /**
     * Push all local tags to remote.
     */
    public function pushAllTags(string $stubsDir): void
    {
        $this->runGit(['push', 'origin', '--tags'], $stubsDir);
    }

    public static function buildCommitMessage(string $branch): string
    {
        $date = date('Y-m-d');

        return "Update stubs for {$branch} ({$date})\n\nAuto-generated by bimoo";
    }

    /**
     * @param string[] $args
     */
    private function runGit(array $args, ?string $cwd = null): Process
    {
        $process = new Process(['git', ...$args], $cwd);
        $process->setTimeout(600);
        $process->mustRun();

        return $process;
    }
}
