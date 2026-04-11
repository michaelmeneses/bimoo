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
use Bimoo\Stub\StubGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sync:tags',
    description: 'Generate stubs for Moodle version tags and push to stubs repository',
)]
class SyncTagsCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption(
                'stubs-repo',
                null,
                InputOption::VALUE_REQUIRED,
                'Stubs repository URL for push',
            )
            ->addOption(
                'moodle-repo',
                null,
                InputOption::VALUE_REQUIRED,
                'Moodle repository URL',
                'git://git.moodle.org/moodle.git',
            )
            ->addOption(
                'min-version',
                null,
                InputOption::VALUE_REQUIRED,
                'Minimum Moodle version to process (e.g., 4.0.0)',
                '4.0.0',
            )
            ->addOption(
                'tags',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated list of specific tags to process (e.g., v4.3.0,v4.3.1)',
            )
            ->addOption(
                'work-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Temporary working directory for clones',
                '/tmp/bimoo-work',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be done without pushing',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $stubsRepo = $input->getOption('stubs-repo');
        $moodleRepo = $input->getOption('moodle-repo');
        $minVersion = $input->getOption('min-version');
        $specificTags = $input->getOption('tags');
        $workDir = $input->getOption('work-dir');
        $dryRun = $input->getOption('dry-run');

        if (empty($stubsRepo)) {
            $io->error('The --stubs-repo option is required.');

            return Command::FAILURE;
        }

        $io->title('Bimoo — Tag Sync');

        if ($dryRun) {
            $io->note('DRY RUN — no changes will be pushed.');
        }

        $manager = new MoodleBranchManager($moodleRepo, $stubsRepo, $workDir);

        try {
            // Step 1: Get Moodle tags
            $io->section('Fetching Moodle tags...');
            $moodleTags = MoodleBranchManager::lsRemoteTags($moodleRepo);
            $allTagNames = array_keys($moodleTags);

            if ($specificTags) {
                // Process only specific tags
                $tagsToProcess = array_map('trim', explode(',', $specificTags));
                $tagsToProcess = array_intersect($tagsToProcess, $allTagNames);
            } else {
                // Filter by min version
                $tagsToProcess = MoodleBranchManager::filterTags($allTagNames, $minVersion);
            }

            // Step 2: Get existing tags in stubs repo
            $io->section('Checking existing stubs tags...');
            $stubsTags = MoodleBranchManager::lsRemoteTags($stubsRepo);
            $existingTagNames = array_keys($stubsTags);

            // Step 3: Find new tags
            $newTags = array_diff($tagsToProcess, $existingTagNames);

            if (empty($newTags)) {
                $io->success('All tags are up-to-date. Nothing to do.');

                return Command::SUCCESS;
            }

            $io->text(sprintf(
                'Found <info>%d</info> new tags to process (out of %d total).',
                count($newTags),
                count($tagsToProcess),
            ));
            $io->listing($newTags);

            // Step 4: Clone repos
            $io->section('Cloning Moodle repository...');
            $bareDir = $manager->cloneMoodle();
            $io->text('Done.');

            $io->section('Cloning stubs repository...');
            $stubsDir = $manager->cloneStubs();
            $io->text('Done.');

            // Load global variable types
            $configPath = dirname(__DIR__, 3) . '/config/global-vars.php';
            $globalVarTypes = file_exists($configPath) ? require $configPath : [];

            // Step 5: Process each tag (sorted by version)
            $newTags = array_values($newTags);
            $total = count($newTags);

            // Group tags by branch for efficient processing
            $tagsByBranch = [];
            foreach ($newTags as $tag) {
                $branch = MoodleBranchManager::tagToBranch($tag);
                if ($branch === null) {
                    $io->warning("Cannot map tag {$tag} to a branch. Skipping.");
                    continue;
                }
                $tagsByBranch[$branch][] = $tag;
            }

            $processed = 0;
            foreach ($tagsByBranch as $branch => $tags) {
                // Sort tags within branch by version
                usort($tags, function (string $a, string $b) {
                    return version_compare(ltrim($a, 'v'), ltrim($b, 'v'));
                });

                // Switch to the correct branch in stubs repo
                $manager->switchStubsBranch($stubsDir, $branch);

                foreach ($tags as $tag) {
                    $processed++;
                    $io->section(sprintf('[%d/%d] Processing %s → %s', $processed, $total, $tag, $branch));

                    // Checkout Moodle at this tag
                    $io->text('Checking out Moodle source...');
                    $sourceDir = $manager->checkoutMoodleSource($bareDir, $tag);

                    // Clean and generate stubs
                    $manager->cleanStubsDir($stubsDir);

                    $io->text('Generating stubs...');
                    $generator = new StubGenerator(
                        moodleDir: $sourceDir,
                        outputDir: $stubsDir . '/stubs',
                        globalVarTypes: $globalVarTypes,
                    );

                    $result = $generator->generate(function (string $_file, int $current, int $total) use ($io) {
                        if ($current === 1) {
                            $io->text("  Found {$total} PHP files.");
                        }
                    });

                    $io->text(sprintf(
                        '  Generated %d stubs (%d skipped, %d errors)',
                        $result->stubsGenerated,
                        $result->filesSkipped,
                        $result->errors,
                    ));

                    // Write SHA tracker
                    $moodleSha = $moodleTags[$tag] ?? '';
                    $manager->writeMoodleSha($stubsDir, $moodleSha);

                    if ($dryRun) {
                        $io->text("  [DRY RUN] Would commit as {$tag} on {$branch}.");
                        continue;
                    }

                    // Commit, tag, and push
                    $committed = $manager->commitForTag($stubsDir, $tag);
                    if ($committed) {
                        $manager->createTag($stubsDir, $tag, "Stubs for Moodle {$tag}");
                        $io->text("  Tagged {$tag}.");
                    } else {
                        $io->text('  No changes from previous version. Tagging current HEAD.');
                        $manager->createTag($stubsDir, $tag, "Stubs for Moodle {$tag}");
                    }
                }

                // Push branch + all tags at once
                if (!$dryRun) {
                    $manager->pushBranch($stubsDir, $branch);
                    $manager->pushAllTags($stubsDir);
                    $io->text("  Pushed {$branch} with " . count($tags) . ' tags.');
                }
            }

            $io->success(sprintf('Processed %d tags.', $processed));
        } finally {
            if (!$dryRun) {
                $manager->cleanup();
            }
        }

        return Command::SUCCESS;
    }
}
