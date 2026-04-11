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

use Bimoo\Stub\StubGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'generate:stubs',
    description: 'Generate .stub.php files from a Moodle installation',
)]
class GenerateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument(
                'moodle-dir',
                InputArgument::REQUIRED,
                'Path to the Moodle root directory',
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output directory for generated stubs',
                './stubs',
            )
            ->addOption(
                'include-tests',
                null,
                InputOption::VALUE_NONE,
                'Include test directories in stub generation',
            )
            ->addOption(
                'exclude',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Directory patterns to exclude',
                [],
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $moodleDirRaw = $input->getArgument('moodle-dir');
        $moodleDir = realpath($moodleDirRaw);
        $outputDir = $input->getOption('output');
        $includeTests = $input->getOption('include-tests');
        $excludePatterns = $input->getOption('exclude');

        if (!is_string($moodleDir) || !is_dir($moodleDir)) {
            $io->error('Invalid Moodle directory: ' . $moodleDirRaw);

            return Command::FAILURE;
        }

        $io->title('Bimoo — Moodle Stub Generator');

        // Load global variable types from config
        $configPath = dirname(__DIR__, 3) . '/config/global-vars.php';
        $globalVarTypes = file_exists($configPath) ? require $configPath : [];

        $generator = new StubGenerator(
            moodleDir: $moodleDir,
            outputDir: $outputDir,
            includeTests: $includeTests,
            excludePatterns: $excludePatterns,
            globalVarTypes: $globalVarTypes,
        );

        $io->text("Discovering PHP files in <info>{$moodleDir}</info>...");

        $progressBar = null;

        $result = $generator->generate(function (string $file, int $current, int $total) use ($io, $output, &$progressBar) {
            if ($progressBar === null) {
                $io->text("Found <info>{$total}</info> PHP files.");
                $io->newLine();
                $io->text('Generating stubs...');
                $progressBar = new ProgressBar($output, $total);
                $progressBar->start();
            }

            $progressBar->setProgress($current);

            if ($output->isVerbose()) {
                $progressBar->clear();
                $io->text("  Processing: {$file}");
                $progressBar->display();
            }
        });

        if ($progressBar !== null) {
            $progressBar->finish();
        }

        $io->newLine(2);
        $io->success([
            "{$result->stubsGenerated} stubs generated ({$result->filesSkipped} files skipped — no declarations found)",
            "{$result->globalVarsGenerated} global variables in globals.stub.php",
            "{$result->constantsCollected} constants collected",
            "Output: {$outputDir}/",
        ]);

        if ($result->errors > 0) {
            $io->warning("{$result->errors} files could not be parsed.");
        }

        return Command::SUCCESS;
    }
}
