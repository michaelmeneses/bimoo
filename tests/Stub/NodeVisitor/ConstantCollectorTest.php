<?php

declare(strict_types=1);

/**
 * Bimoo — Moodle Stub Generator
 *
 * @author     Michael Meneses <michael@middag.com.br>
 * @copyright  2026 MIDDAG (https://www.middag.com.br)
 * @license    GNU General Public License v3.0 or later
 */

namespace Bimoo\Tests\Stub\NodeVisitor;

use Bimoo\Stub\NodeVisitor\ConstantCollector;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

class ConstantCollectorTest extends TestCase
{
    private function collectConstants(string $code): ConstantCollector
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);

        $collector = new ConstantCollector();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse($stmts);

        return $collector;
    }

    public function testCollectsDefineConstants(): void
    {
        $code = <<<'PHP'
            <?php
            define('MOODLE_INTERNAL', true);
            define('PARAM_INT', 'int');
            PHP;

        $collector = $this->collectConstants($code);

        $this->assertCount(2, $collector->getConstantNames());
        $this->assertContains('MOODLE_INTERNAL', $collector->getConstantNames());
        $this->assertContains('PARAM_INT', $collector->getConstantNames());
    }

    public function testCollectsFileConst(): void
    {
        $code = '<?php const VERSION = 2024042200;';
        $collector = $this->collectConstants($code);

        $this->assertCount(1, $collector->getConstantNames());
        $this->assertContains('VERSION', $collector->getConstantNames());
    }

    public function testCountReturnsTotal(): void
    {
        $code = <<<'PHP'
            <?php
            define('A', 1);
            const B = 2;
            define('C', 'three');
            PHP;

        $collector = $this->collectConstants($code);

        $this->assertSame(3, $collector->getCount());
    }

    public function testResetClearsState(): void
    {
        $code = "<?php define('A', 1);";
        $collector = $this->collectConstants($code);

        $this->assertSame(1, $collector->getCount());

        $collector->reset();

        $this->assertSame(0, $collector->getCount());
    }
}
