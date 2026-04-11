<?php

declare(strict_types=1);

/**
 * Bimoo — Moodle Stub Generator
 *
 * @author     Michael Meneses <michael@middag.com.br>
 * @copyright  2026 MIDDAG (https://www.middag.com.br)
 * @license    GNU General Public License v3.0 or later
 */

namespace Bimoo\Tests\Stub;

use Bimoo\Stub\StubGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class StubGeneratorTest extends TestCase
{
    private string $moodleDir;
    private string $outputDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        $this->moodleDir = sys_get_temp_dir() . '/bimoo_moodle_' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/bimoo_output_' . uniqid();
        $this->fs->mkdir($this->moodleDir);
        $this->fs->mkdir($this->outputDir);
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->moodleDir);
        $this->fs->remove($this->outputDir);
    }

    public function testGeneratesStubForClassFile(): void
    {
        $this->fs->mkdir($this->moodleDir . '/lib');
        file_put_contents($this->moodleDir . '/lib/pagelib.php', <<<'PHP'
            <?php
            class moodle_page {
                public string $title = '';
                public function set_title(string $title): void {
                    $this->title = $title;
                }
            }
            PHP);

        $generator = new StubGenerator($this->moodleDir, $this->outputDir);
        $result = $generator->generate();

        $this->assertFileExists($this->outputDir . '/lib/pagelib.stub.php');
        $content = file_get_contents($this->outputDir . '/lib/pagelib.stub.php');
        $this->assertStringContainsString('class moodle_page', $content);
        $this->assertStringContainsString('public function set_title(string $title): void', $content);
        $this->assertStringNotContainsString('$this->title = $title', $content);
        $this->assertSame(1, $result->stubsGenerated);
    }

    public function testGeneratesStubForFunctionFile(): void
    {
        file_put_contents($this->moodleDir . '/lib.php', <<<'PHP'
            <?php
            define('MOODLE_INTERNAL', true);
            function get_string(string $id): string { return $id; }
            PHP);

        $generator = new StubGenerator($this->moodleDir, $this->outputDir);
        $result = $generator->generate();

        $this->assertFileExists($this->outputDir . '/lib.stub.php');
        $content = file_get_contents($this->outputDir . '/lib.stub.php');
        $this->assertStringContainsString("define('MOODLE_INTERNAL', true)", $content);
        $this->assertStringContainsString('function get_string(string $id): string', $content);
    }

    public function testSkipsFilesWithNoDeclarations(): void
    {
        file_put_contents($this->moodleDir . '/config.php', <<<'PHP'
            <?php
            require_once('lib/setup.php');
            $CFG->dbtype = 'mysqli';
            PHP);

        $generator = new StubGenerator($this->moodleDir, $this->outputDir);
        $result = $generator->generate();

        $this->assertFileDoesNotExist($this->outputDir . '/config.stub.php');
        $this->assertSame(0, $result->stubsGenerated);
        $this->assertSame(1, $result->filesSkipped);
    }

    public function testGeneratesGlobalsStubFile(): void
    {
        file_put_contents($this->moodleDir . '/index.php', '<?php class Foo {}');

        $globalVars = ['$DB' => '\\moodle_database'];
        $generator = new StubGenerator($this->moodleDir, $this->outputDir, globalVarTypes: $globalVars);
        $generator->generate();

        $this->assertFileExists($this->outputDir . '/globals.stub.php');
        $content = file_get_contents($this->outputDir . '/globals.stub.php');
        $this->assertStringContainsString('global $DB', $content);
    }

    public function testResultContainsStats(): void
    {
        file_put_contents($this->moodleDir . '/a.php', "<?php class A {} define('X', 1);");
        file_put_contents($this->moodleDir . '/b.php', '<?php $x = 1;');

        $generator = new StubGenerator($this->moodleDir, $this->outputDir);
        $result = $generator->generate();

        $this->assertSame(2, $result->filesProcessed);
        $this->assertSame(1, $result->stubsGenerated);
        $this->assertSame(1, $result->filesSkipped);
        $this->assertSame(1, $result->constantsCollected);
        $this->assertSame(0, $result->errors);
    }
}
