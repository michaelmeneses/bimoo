<?php

declare(strict_types=1);

/**
 * Bimoo — Moodle Stub Generator
 *
 * @author     Michael Meneses <michael@middag.com.br>
 * @copyright  2026 MIDDAG (https://www.middag.com.br)
 * @license    GNU General Public License v3.0 or later
 */

namespace Bimoo\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class GenerateStubsTest extends TestCase
{
    private string $moodleDir;
    private string $outputDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        $this->moodleDir = sys_get_temp_dir() . '/bimoo_integration_moodle_' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/bimoo_integration_output_' . uniqid();
        $this->fs->mkdir($this->moodleDir);

        $this->createFakeMoodle();
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->moodleDir);
        $this->fs->remove($this->outputDir);
    }

    private function createFakeMoodle(): void
    {
        // lib/moodlelib.php — functions + constants
        $this->fs->mkdir($this->moodleDir . '/lib/dml');
        file_put_contents($this->moodleDir . '/lib/moodlelib.php', <<<'PHP'
            <?php
            define('MOODLE_INTERNAL', true);
            define('PARAM_INT', 'int');

            /**
             * Get a string from the language pack.
             *
             * @param string $identifier The string identifier
             * @param string|null $component The component
             * @return string
             */
            function get_string(string $identifier, ?string $component = null): string {
                $manager = get_string_manager();
                return $manager->get_string($identifier, $component);
            }
            PHP);

        // lib/dml/moodle_database.php — abstract class
        file_put_contents($this->moodleDir . '/lib/dml/moodle_database.php', <<<'PHP'
            <?php
            abstract class moodle_database {
                /** @var string The database host */
                protected string $dbhost;

                abstract public function connect(string $dbhost, string $dbuser, string $dbpass, string $dbname): bool;

                /**
                 * Get a single record.
                 *
                 * @param string $table The table name
                 * @param array $conditions The conditions
                 * @return \stdClass|false
                 */
                public function get_record(string $table, array $conditions = []): \stdClass|false {
                    $sql = "SELECT * FROM " . $table;
                    return $this->get_record_sql($sql);
                }
            }
            PHP);

        // lib/pagelib.php — class
        file_put_contents($this->moodleDir . '/lib/pagelib.php', <<<'PHP'
            <?php
            class moodle_page {
                public string $title = '';

                public function set_title(string $title): void {
                    $this->title = $title;
                }
            }
            PHP);

        // config.php — should be skipped (no declarations)
        file_put_contents($this->moodleDir . '/config.php', <<<'PHP'
            <?php
            require_once('lib/setup.php');
            $CFG->dbtype = 'mysqli';
            $CFG->dbhost = 'localhost';
            PHP);

        // vendor/ — should be excluded
        $this->fs->mkdir($this->moodleDir . '/vendor/somelib');
        file_put_contents($this->moodleDir . '/vendor/somelib/lib.php', '<?php class Vendor {}');
    }

    public function testEndToEndStubGeneration(): void
    {
        $binPath = dirname(__DIR__, 2) . '/bin/bimoo';
        $cmd = sprintf(
            'php %s generate:stubs %s --output=%s 2>&1',
            escapeshellarg($binPath),
            escapeshellarg($this->moodleDir),
            escapeshellarg($this->outputDir),
        );

        exec($cmd, $output, $exitCode);
        $fullOutput = implode("\n", $output);

        // Command succeeds
        $this->assertSame(0, $exitCode, "Command failed with output:\n" . $fullOutput);

        // Stubs exist with correct paths
        $this->assertFileExists($this->outputDir . '/lib/moodlelib.stub.php');
        $this->assertFileExists($this->outputDir . '/lib/dml/moodle_database.stub.php');
        $this->assertFileExists($this->outputDir . '/lib/pagelib.stub.php');
        $this->assertFileExists($this->outputDir . '/globals.stub.php');

        // config.php was skipped (no declarations)
        $this->assertFileDoesNotExist($this->outputDir . '/config.stub.php');

        // vendor/ was excluded
        $this->assertFileDoesNotExist($this->outputDir . '/vendor/somelib/lib.stub.php');

        // Check moodlelib.stub.php content
        $moodlelib = file_get_contents($this->outputDir . '/lib/moodlelib.stub.php');
        $this->assertStringContainsString("define('MOODLE_INTERNAL', true)", $moodlelib);
        $this->assertStringContainsString('function get_string(string $identifier', $moodlelib);
        $this->assertStringContainsString('@param string $identifier', $moodlelib);
        $this->assertStringNotContainsString('$manager = get_string_manager()', $moodlelib);

        // Check moodle_database.stub.php content
        $db = file_get_contents($this->outputDir . '/lib/dml/moodle_database.stub.php');
        $this->assertStringContainsString('abstract class moodle_database', $db);
        $this->assertStringContainsString('abstract public function connect(', $db);
        $this->assertStringContainsString('public function get_record(string $table', $db);
        $this->assertStringNotContainsString('SELECT * FROM', $db);

        // Check globals.stub.php
        $globals = file_get_contents($this->outputDir . '/globals.stub.php');
        $this->assertStringContainsString('global $DB', $globals);
        $this->assertStringContainsString('\\moodle_database', $globals);
    }
}
