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

use Bimoo\Parser\FileParser;
use PhpParser\Node\Stmt\Function_;
use PHPUnit\Framework\TestCase;

class FileParserTest extends TestCase
{
    private FileParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FileParser();
    }

    public function testParseValidPhpFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'bimoo_');
        file_put_contents($tmpFile, '<?php function hello(): string { return "world"; }');

        $result = $this->parser->parse($tmpFile);

        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(Function_::class, $result[0]);

        unlink($tmpFile);
    }

    public function testParsePreservesPhpDoc(): void
    {
        $code = <<<'PHP'
            <?php
            /**
             * Says hello.
             *
             * @param string $name The name
             * @return string The greeting
             */
            function hello(string $name): string { return "Hello $name"; }
            PHP;

        $tmpFile = tempnam(sys_get_temp_dir(), 'bimoo_');
        file_put_contents($tmpFile, $code);

        $result = $this->parser->parse($tmpFile);

        $this->assertNotNull($result);
        $docComment = $result[0]->getDocComment();
        $this->assertNotNull($docComment);
        $this->assertStringContainsString('@param string $name', $docComment->getText());

        unlink($tmpFile);
    }

    public function testParseInvalidFileReturnsNull(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'bimoo_');
        file_put_contents($tmpFile, '<?php function { broken syntax');

        $result = $this->parser->parse($tmpFile);

        $this->assertNull($result);

        unlink($tmpFile);
    }

    public function testParseNonExistentFileReturnsNull(): void
    {
        $result = $this->parser->parse('/nonexistent/file.php');

        $this->assertNull($result);
    }
}
