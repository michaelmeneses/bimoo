<?php

declare(strict_types=1);

/**
 * Bimoo — Moodle Stub Generator
 *
 * @author     Michael Meneses <michael@middag.com.br>
 * @copyright  2026 MIDDAG (https://www.middag.com.br)
 * @license    GNU General Public License v3.0 or later
 */

namespace Bimoo\Parser;

use Symfony\Component\Finder\Finder;

class MoodleSourceDiscovery
{
    private const DEFAULT_EXCLUDES = [
        'vendor',
        'node_modules',
        '.git',
    ];

    /**
     * @param string[] $excludePatterns Additional directory patterns to exclude
     */
    public function __construct(
        private readonly string $moodleDir,
        private readonly bool $includeTests = false,
        private readonly array $excludePatterns = [],
    ) {
    }

    /**
     * Discover all PHP files in the Moodle directory.
     *
     * @return string[] Relative file paths
     */
    public function discover(): array
    {
        $finder = new Finder();
        $finder->files()
            ->name('*.php')
            ->in($this->moodleDir)
            ->sortByName();

        foreach (self::DEFAULT_EXCLUDES as $exclude) {
            $finder->exclude($exclude);
        }

        if (!$this->includeTests) {
            $finder->exclude('tests');
        }

        foreach ($this->excludePatterns as $pattern) {
            $finder->exclude($pattern);
        }

        $files = [];
        $prefixLength = strlen(rtrim($this->moodleDir, '/')) + 1;

        foreach ($finder as $file) {
            $files[] = substr($file->getPathname(), $prefixLength);
        }

        return $files;
    }
}
