<?php

declare(strict_types=1);

/**
 * Bimoo — Moodle Stub Generator
 *
 * @author     Michael Meneses <michael@middag.com.br>
 * @copyright  2026 MIDDAG (https://www.middag.com.br)
 * @license    GNU General Public License v3.0 or later
 */

namespace Bimoo\Console\Command;

use Bimoo\Git\MoodleBranchManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'discover:updates',
    description: 'Discover Moodle branches and tags that need stub updates (outputs JSON for CI matrix)',
)]
class DiscoverCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption(
                'moodle-repo',
                null,
                InputOption::VALUE_REQUIRED,
                'Moodle repository URL',
                'git://git.moodle.org/moodle.git',
            )
            ->addOption(
                'stubs-repo',
                null,
                InputOption::VALUE_REQUIRED,
                'Stubs repository URL (for tag comparison)',
            )
            ->addOption(
                'stubs-repo-slug',
                null,
                InputOption::VALUE_REQUIRED,
                'Stubs repository owner/name for GitHub API (e.g., michaelmeneses/moodle-stubs)',
            )
            ->addOption(
                'branches',
                null,
                InputOption::VALUE_REQUIRED,
                'Branch filter pattern (e.g., MOODLE_40* or * for all)',
                '*',
            )
            ->addOption(
                'min-version',
                null,
                InputOption::VALUE_REQUIRED,
                'Minimum Moodle version for tag discovery',
                '4.0.0',
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force regeneration of all branches and tags',
            )
            ->addOption(
                'type',
                null,
                InputOption::VALUE_REQUIRED,
                'What to discover: branches, tags, or all',
                'all',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $moodleRepo = $input->getOption('moodle-repo');
        $stubsRepo = $input->getOption('stubs-repo');
        $stubsRepoSlug = $input->getOption('stubs-repo-slug');
        $branchPattern = $input->getOption('branches');
        $minVersion = $input->getOption('min-version');
        $force = $input->getOption('force');
        $type = $input->getOption('type');

        $result = [
            'branches' => [],
            'tags' => [],
        ];

        // Discover branches
        if ($type === 'all' || $type === 'branches') {
            $result['branches'] = $this->discoverBranches(
                $io,
                $moodleRepo,
                $stubsRepoSlug,
                $branchPattern,
                $force,
            );
        }

        // Discover tags
        if ($type === 'all' || $type === 'tags') {
            $result['tags'] = $this->discoverTags(
                $io,
                $moodleRepo,
                $stubsRepo,
                $minVersion,
                $force,
            );
        }

        // Output pure JSON (consumed by GitHub Actions)
        $output->write(json_encode($result, JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }

    /**
     * @return list<array{branch: string, moodle_sha: string}>
     */
    private function discoverBranches(
        SymfonyStyle $io,
        string $moodleRepo,
        ?string $stubsRepoSlug,
        string $branchPattern,
        bool $force,
    ): array {
        $remoteBranches = MoodleBranchManager::lsRemoteBranches($moodleRepo);
        $allNames = array_keys($remoteBranches);
        $filtered = MoodleBranchManager::filterBranches($allNames, $branchPattern);

        $matrix = [];

        foreach ($filtered as $branch) {
            $moodleSha = $remoteBranches[$branch];

            if (!$force && $stubsRepoSlug) {
                $storedSha = MoodleBranchManager::readStoredSha($stubsRepoSlug, $branch);

                if ($storedSha === $moodleSha) {
                    continue;
                }
            }

            $matrix[] = [
                'branch' => $branch,
                'moodle_sha' => $moodleSha,
            ];
        }

        return $matrix;
    }

    /**
     * @return list<array{tag: string, branch: ?string, moodle_sha: string}>
     */
    private function discoverTags(
        SymfonyStyle $io,
        string $moodleRepo,
        ?string $stubsRepo,
        string $minVersion,
        bool $force,
    ): array {
        $moodleTags = MoodleBranchManager::lsRemoteTags($moodleRepo);
        $allTagNames = array_keys($moodleTags);
        $filtered = MoodleBranchManager::filterTags($allTagNames, $minVersion);

        if ($force || empty($stubsRepo)) {
            // Return all filtered tags
            return array_map(function (string $tag) use ($moodleTags) {
                return [
                    'tag' => $tag,
                    'branch' => MoodleBranchManager::tagToBranch($tag),
                    'moodle_sha' => $moodleTags[$tag] ?? '',
                ];
            }, $filtered);
        }

        // Compare with existing stubs tags
        $stubsTags = MoodleBranchManager::lsRemoteTags($stubsRepo);
        $existingTagNames = array_keys($stubsTags);

        $newTags = array_diff($filtered, $existingTagNames);

        return array_values(array_map(function (string $tag) use ($moodleTags) {
            return [
                'tag' => $tag,
                'branch' => MoodleBranchManager::tagToBranch($tag),
                'moodle_sha' => $moodleTags[$tag] ?? '',
            ];
        }, $newTags));
    }
}
