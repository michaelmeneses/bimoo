<?php

declare(strict_types=1);

/**
 * Bimoo — Moodle Stub Generator
 *
 * @author     Michael Meneses <michael@middag.com.br>
 * @copyright  2026 MIDDAG (https://www.middag.com.br)
 * @license    GNU General Public License v3.0 or later
 */

namespace Bimoo\Stub;

use Bimoo\Parser\FileParser;
use Bimoo\Parser\MoodleSourceDiscovery;
use Bimoo\Stub\NodeVisitor\ConstantCollector;
use Bimoo\Stub\NodeVisitor\GlobalVarCollector;
use Bimoo\Stub\NodeVisitor\StubNodeVisitor;
use Bimoo\Stub\Writer\StubFileWriter;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;

class StubGenerator
{
    private FileParser $parser;
    private StubFileWriter $writer;
    private MoodleSourceDiscovery $discovery;
    private GlobalVarCollector $globalVarCollector;

    /**
     * @param array<string, string> $globalVarTypes
     * @param string[] $excludePatterns
     */
    public function __construct(
        private readonly string $moodleDir,
        string $outputDir,
        bool $includeTests = false,
        array $excludePatterns = [],
        array $globalVarTypes = [],
    ) {
        $this->parser = new FileParser();
        $this->writer = new StubFileWriter($outputDir);
        $this->discovery = new MoodleSourceDiscovery($moodleDir, $includeTests, $excludePatterns);
        $this->globalVarCollector = new GlobalVarCollector($globalVarTypes);
    }

    /**
     * @param callable(string, int, int): void|null $onProgress Called with (filePath, current, total)
     */
    public function generate(?callable $onProgress = null): GenerationResult
    {
        $files = $this->discovery->discover();
        $total = count($files);

        $stubsGenerated = 0;
        $filesSkipped = 0;
        $errors = 0;
        $totalConstants = 0;

        foreach ($files as $index => $relativePath) {
            $absolutePath = $this->moodleDir . '/' . $relativePath;

            if ($onProgress !== null) {
                $onProgress($relativePath, $index + 1, $total);
            }

            $stmts = $this->parser->parse($absolutePath);

            if ($stmts === null) {
                $errors++;
                continue;
            }

            // Traverse: strip bodies + collect constants
            $stubVisitor = new StubNodeVisitor();
            $constantCollector = new ConstantCollector();

            $traverser = new NodeTraverser();
            $traverser->addVisitor($stubVisitor);
            $traverser->addVisitor($constantCollector);

            /** @var Stmt[] $cleanedStmts */
            $cleanedStmts = $traverser->traverse($stmts);

            $totalConstants += $constantCollector->getCount();

            if ($this->writer->hasDeclarations($cleanedStmts)) {
                $this->writer->write($cleanedStmts, $relativePath);
                $stubsGenerated++;
            } else {
                $filesSkipped++;
            }
        }

        // Generate globals.stub.php
        if ($this->globalVarCollector->getCount() > 0) {
            $this->writer->writeRaw(
                $this->globalVarCollector->generate(),
                'globals.stub.php',
            );
        }

        return new GenerationResult(
            filesProcessed: $total,
            stubsGenerated: $stubsGenerated,
            filesSkipped: $filesSkipped,
            constantsCollected: $totalConstants,
            globalVarsGenerated: $this->globalVarCollector->getCount(),
            errors: $errors,
        );
    }
}
