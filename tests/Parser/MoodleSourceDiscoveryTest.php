<?php

declare(strict_types=1);

/**
 * Bimoo — Moodle Stub Generator
 *
 * @author     Michael Meneses <michael@middag.com.br>
 * @copyright  2026 MIDDAG (https://www.middag.com.br)
 * @license    GNU General Public License v3.0 or later
 */

namespace Bimoo\Tests\Parser;

use Bimoo\Parser\MoodleSourceDiscovery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class MoodleSourceDiscoveryTest extends TestCase
{
    private string $tempDir;
    private Filesystem $fs;

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        $this->tempDir = sys_get_temp_dir() . '/bimoo_test_' . uniqid();
        $this->fs->mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->tempDir);
    }

    public function testDiscoversPhpFiles(): void
    {
        $this->fs->mkdir($this->tempDir . '/lib');
        file_put_contents($this->tempDir . '/lib/moodlelib.php', '<?php');
        file_put_contents($this->tempDir . '/lib/weblib.php', '<?php');

        $discovery = new MoodleSourceDiscovery($this->tempDir);
        $files = $discovery->discover();

        $this->assertCount(2, $files);
    }

    public function testExcludesVendorDirectory(): void
    {
        $this->fs->mkdir($this->tempDir . '/vendor/somelib');
        file_put_contents($this->tempDir . '/vendor/somelib/lib.php', '<?php');
        file_put_contents($this->tempDir . '/index.php', '<?php');

        $discovery = new MoodleSourceDiscovery($this->tempDir);
        $files = $discovery->discover();

        $this->assertCount(1, $files);
    }

    public function testExcludesNodeModules(): void
    {
        $this->fs->mkdir($this->tempDir . '/node_modules/pkg');
        file_put_contents($this->tempDir . '/node_modules/pkg/script.php', '<?php');
        file_put_contents($this->tempDir . '/index.php', '<?php');

        $discovery = new MoodleSourceDiscovery($this->tempDir);
        $files = $discovery->discover();

        $this->assertCount(1, $files);
    }

    public function testExcludesTestsByDefault(): void
    {
        $this->fs->mkdir($this->tempDir . '/lib/tests');
        file_put_contents($this->tempDir . '/lib/tests/moodlelib_test.php', '<?php');
        file_put_contents($this->tempDir . '/lib/moodlelib.php', '<?php');

        $discovery = new MoodleSourceDiscovery($this->tempDir);
        $files = $discovery->discover();

        $this->assertCount(1, $files);
    }

    public function testIncludesTestsWhenFlagSet(): void
    {
        $this->fs->mkdir($this->tempDir . '/lib/tests');
        file_put_contents($this->tempDir . '/lib/tests/moodlelib_test.php', '<?php');
        file_put_contents($this->tempDir . '/lib/moodlelib.php', '<?php');

        $discovery = new MoodleSourceDiscovery($this->tempDir, includeTests: true);
        $files = $discovery->discover();

        $this->assertCount(2, $files);
    }

    public function testCustomExclusions(): void
    {
        $this->fs->mkdir($this->tempDir . '/mod/legacy');
        file_put_contents($this->tempDir . '/mod/legacy/old.php', '<?php');
        file_put_contents($this->tempDir . '/index.php', '<?php');

        $discovery = new MoodleSourceDiscovery($this->tempDir, excludePatterns: ['mod/legacy']);
        $files = $discovery->discover();

        $this->assertCount(1, $files);
    }

    public function testReturnsRelativePaths(): void
    {
        file_put_contents($this->tempDir . '/index.php', '<?php');

        $discovery = new MoodleSourceDiscovery($this->tempDir);
        $files = $discovery->discover();

        $this->assertSame(['index.php'], $files);
    }
}
