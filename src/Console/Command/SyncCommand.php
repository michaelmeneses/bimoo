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
    name: 'sync:branches',
    description: 'Sync stubs across Moodle branches and push to stubs repository',
)]
class SyncCommand extends Command
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
                'branches',
                null,
                InputOption::VALUE_REQUIRED,
                'Branch filter pattern (e.g., MOODLE_40* or * for all)',
                '*',
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
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force regeneration of all branches (ignore stored SHA)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $stubsRepo = $input->getOption('stubs-repo');
        $moodleRepo = $input->getOption('moodle-repo');
        $branchPattern = $input->getOption('branches');
        $workDir = $input->getOption('work-dir');
        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');

        if (empty($stubsRepo)) {
            $io->error('The --stubs-repo option is required.');

            return Command::FAILURE;
        }

        $io->title('Bimoo — Branch Sync');

        if ($dryRun) {
            $io->note('DRY RUN — no changes will be pushed.');
        }

        $manager = new MoodleBranchManager($moodleRepo, $stubsRepo, $workDir);

        try {
            // Step 1: Clone Moodle
            $io->section('Cloning Moodle repository...');
            $bareDir = $manager->cloneMoodle();
            $io->text('Done.');

            // Step 2: Clone stubs repo
            $io->section('Cloning stubs repository...');
            $stubsDir = $manager->cloneStubs();
            $io->text('Done.');

            // Step 3: List and filter branches
            $allBranches = $manager->listRemoteBranches($bareDir);
            $branches = MoodleBranchManager::filterBranches($allBranches, $branchPattern);

            if (empty($branches)) {
                $io->warning('No matching branches found.');

                return Command::SUCCESS;
            }

            $io->text(sprintf('Found <info>%d</info> branches to process.', count($branches)));
            $io->listing($branches);

            // Load global variable types
            $configPath = dirname(__DIR__, 3) . '/config/global-vars.php';
            $globalVarTypes = file_exists($configPath) ? require $configPath : [];

            // Step 4: Process each branch
            foreach ($branches as $index => $branch) {
                $io->section(sprintf('[%d/%d] Processing %s', $index + 1, count($branches), $branch));

                // Check SHA to skip unchanged branches
                $moodleSha = $manager->getMoodleBranchSha($bareDir, $branch);

                if (!$force) {
                    $manager->switchStubsBranch($stubsDir, $branch);
                    $shaFile = $stubsDir . '/.bimoo-moodle-sha';
                    $rawSha = is_file($shaFile) ? file_get_contents($shaFile) : false;
                    $storedSha = ($rawSha !== false) ? trim($rawSha) : null;

                    if ($storedSha === $moodleSha) {
                        $io->text('  Up-to-date (SHA: ' . substr($moodleSha, 0, 8) . '). Skipping.');
                        continue;
                    }
                } else {
                    $manager->switchStubsBranch($stubsDir, $branch);
                }

                $manager->cleanStubsDir($stubsDir);

                // Checkout Moodle source
                $io->text('Checking out Moodle source...');
                $sourceDir = $manager->checkoutMoodleSource($bareDir, $branch);

                // Generate stubs
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
                $manager->writeMoodleSha($stubsDir, $moodleSha);

                // Commit and push
                if ($dryRun) {
                    $io->text('  [DRY RUN] Would commit and push.');
                } else {
                    $pushed = $manager->commitAndPush($stubsDir, $branch);
                    $io->text($pushed ? '  Pushed.' : '  No changes detected.');
                }
            }

            $io->success('All branches processed.');
        } finally {
            if (!$dryRun) {
                $manager->cleanup();
            }
        }

        return Command::SUCCESS;
    }
}
