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

use Bimoo\Stub\NodeVisitor\StubNodeVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use PHPUnit\Framework\TestCase;

class StubNodeVisitorTest extends TestCase
{
    private function processCode(string $code): string
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new StubNodeVisitor());
        $stmts = $traverser->traverse($stmts);

        return (new PrettyPrinter())->prettyPrintFile($stmts);
    }

    public function testFunctionBodyIsRemoved(): void
    {
        $input = '<?php function hello(string $name): string { return "Hello " . $name; }';
        $output = $this->processCode($input);

        $this->assertStringContainsString('function hello(string $name): string', $output);
        $this->assertStringNotContainsString('return "Hello "', $output);
    }

    public function testClassMethodBodyIsRemoved(): void
    {
        $input = <<<'PHP'
            <?php
            class Foo {
                public function bar(int $x): bool {
                    if ($x > 0) {
                        return true;
                    }
                    return false;
                }
            }
            PHP;

        $output = $this->processCode($input);

        $this->assertStringContainsString('public function bar(int $x): bool', $output);
        $this->assertStringNotContainsString('if ($x > 0)', $output);
    }

    public function testAbstractMethodKeptAsIs(): void
    {
        $input = '<?php abstract class Foo { abstract public function bar(): void; }';
        $output = $this->processCode($input);

        $this->assertStringContainsString('abstract public function bar(): void;', $output);
    }

    public function testClassPropertiesPreserved(): void
    {
        $input = <<<'PHP'
            <?php
            class Config {
                public string $name = 'default';
                protected int $count;
                private static bool $active = true;
            }
            PHP;

        $output = $this->processCode($input);

        $this->assertStringContainsString("public string \$name = 'default'", $output);
        $this->assertStringContainsString('protected int $count', $output);
        $this->assertStringContainsString('private static bool $active = true', $output);
    }

    public function testInterfacePreserved(): void
    {
        $input = '<?php interface Cacheable { public function getKey(): string; }';
        $output = $this->processCode($input);

        $this->assertStringContainsString('interface Cacheable', $output);
        $this->assertStringContainsString('public function getKey(): string;', $output);
    }

    public function testTraitPreserved(): void
    {
        $input = <<<'PHP'
            <?php
            trait Loggable {
                public function log(string $msg): void { echo $msg; }
            }
            PHP;

        $output = $this->processCode($input);

        $this->assertStringContainsString('trait Loggable', $output);
        $this->assertStringContainsString('public function log(string $msg): void', $output);
        $this->assertStringNotContainsString('echo $msg', $output);
    }

    public function testEnumPreserved(): void
    {
        $input = <<<'PHP'
            <?php
            enum Status: string {
                case Active = 'active';
                case Inactive = 'inactive';
            }
            PHP;

        $output = $this->processCode($input);

        $this->assertStringContainsString('enum Status', $output);
        $this->assertStringContainsString(': string', $output);
        $this->assertStringContainsString("case Active = 'active'", $output);
    }

    public function testPhpDocPreserved(): void
    {
        $input = <<<'PHP'
            <?php
            /**
             * A utility class.
             *
             * @package core
             */
            class Util {
                /**
                 * Do something.
                 *
                 * @param string $input The input
                 * @return bool True on success
                 */
                public function doSomething(string $input): bool {
                    return strlen($input) > 0;
                }
            }
            PHP;

        $output = $this->processCode($input);

        $this->assertStringContainsString('@package core', $output);
        $this->assertStringContainsString('@param string $input The input', $output);
        $this->assertStringContainsString('@return bool True on success', $output);
    }

    public function testDefineCallsPreserved(): void
    {
        $input = "<?php define('MOODLE_INTERNAL', true);";
        $output = $this->processCode($input);

        $this->assertStringContainsString("define('MOODLE_INTERNAL', true)", $output);
    }

    public function testIncludeStatementsRemoved(): void
    {
        $input = <<<'PHP'
            <?php
            require_once(__DIR__ . '/config.php');
            include('lib/setup.php');
            function hello(): void {}
            PHP;

        $output = $this->processCode($input);

        $this->assertStringNotContainsString('require_once', $output);
        $this->assertStringNotContainsString('include', $output);
        $this->assertStringContainsString('function hello(): void', $output);
    }

    public function testNonDeclarationExpressionsRemoved(): void
    {
        $input = <<<'PHP'
            <?php
            $x = 1;
            echo "hello";
            some_function_call();
            class Foo {}
            PHP;

        $output = $this->processCode($input);

        $this->assertStringNotContainsString('$x = 1', $output);
        $this->assertStringNotContainsString('echo', $output);
        $this->assertStringNotContainsString('some_function_call', $output);
        $this->assertStringContainsString('class Foo', $output);
    }

    public function testFileConstPreserved(): void
    {
        $input = '<?php const VERSION = 2024042200;';
        $output = $this->processCode($input);

        $this->assertStringContainsString('const VERSION = 2024042200', $output);
    }

    public function testClassConstPreserved(): void
    {
        $input = <<<'PHP'
            <?php
            class Roles {
                const STUDENT = 5;
                public const TEACHER = 3;
            }
            PHP;

        $output = $this->processCode($input);

        $this->assertStringContainsString('const STUDENT = 5', $output);
        $this->assertStringContainsString('public const TEACHER = 3', $output);
    }

    public function testUseImportsPreserved(): void
    {
        $input = <<<'PHP'
            <?php
            namespace core\output;

            use core\context;
            use moodle_url as url_alias;
            use function core\bootstrap;
            use const core\VERSION;

            class renderer extends base_renderer {
                public function render(context $ctx): string { return ''; }
            }
            PHP;

        $output = $this->processCode($input);

        $this->assertStringContainsString('use core\context;', $output);
        $this->assertStringContainsString('use moodle_url as url_alias;', $output);
        $this->assertStringContainsString('use function core\bootstrap;', $output);
        $this->assertStringContainsString('use const core\VERSION;', $output);
        $this->assertStringContainsString('class renderer extends base_renderer', $output);
    }

    public function testGroupUseImportsPreserved(): void
    {
        $input = <<<'PHP'
            <?php
            namespace core;

            use core\output\{renderer, templatable};

            class page {
                public function out(renderer $r): void {}
            }
            PHP;

        $output = $this->processCode($input);

        $this->assertStringContainsString('use core\output\{renderer, templatable};', $output);
        $this->assertStringContainsString('class page', $output);
    }

    public function testNonLiteralDefineValueReplacedWithNull(): void
    {
        $input = "<?php define('CLI_SCRIPT', php_sapi_name() === 'cli');";
        $output = $this->processCode($input);

        $this->assertStringContainsString("define('CLI_SCRIPT',", $output);
        $this->assertStringContainsString('null', $output);
    }

    public function testClassAliasReemittedAsGlobalDeclaration(): void
    {
        $input = <<<'PHP'
            <?php
            namespace core;

            class url {
                public function out(bool $escaped = true): string { return ''; }
            }

            class_alias(url::class, \moodle_url::class);
            PHP;

        $output = $this->processCode($input);

        $this->assertStringNotContainsString('class_alias', $output);
        $this->assertStringContainsString('class moodle_url extends \core\url', $output);
        // The alias lands in the global namespace block, not inside core
        $this->assertMatchesRegularExpression(
            '/namespace \{.*class moodle_url extends \\\\core\\\\url/s',
            $output,
        );
        $this->assertStringContainsString('Runtime class alias of \core\url', $output);
    }

    public function testClassAliasWithStringAliasName(): void
    {
        $input = <<<'PHP'
            <?php
            namespace core;

            class plugin_manager {}

            class_alias(plugin_manager::class, 'core_plugin_manager');
            PHP;

        $output = $this->processCode($input);

        $this->assertStringContainsString(
            'class core_plugin_manager extends \core\plugin_manager',
            $output,
        );
    }

    public function testClassAliasOfInterfaceEmitsInterface(): void
    {
        $input = <<<'PHP'
            <?php
            namespace core\output;

            interface templatable {
                public function export_for_template(): array;
            }

            class_alias(templatable::class, \templatable::class);
            PHP;

        $output = $this->processCode($input);

        $this->assertStringContainsString(
            'interface templatable extends \core\output\templatable',
            $output,
        );
        $this->assertStringNotContainsString('class templatable', $output);
    }

    public function testClassAliasOfAbstractClassStaysAbstract(): void
    {
        $input = <<<'PHP'
            <?php
            namespace core;

            abstract class base_thing {
                abstract public function run(): void;
            }

            class_alias(base_thing::class, \legacy_base::class);
            PHP;

        $output = $this->processCode($input);

        $this->assertStringContainsString(
            'abstract class legacy_base extends \core\base_thing',
            $output,
        );
    }

    public function testClassAliasOfEnumIsDropped(): void
    {
        $input = <<<'PHP'
            <?php
            namespace core;

            enum status: string {
                case Active = 'active';
            }

            class_alias(status::class, \legacy_status::class);
            PHP;

        $output = $this->processCode($input);

        $this->assertStringNotContainsString('class_alias', $output);
        $this->assertStringNotContainsString('legacy_status', $output);
    }

    public function testClassAliasWithDynamicArgumentsIsDropped(): void
    {
        $input = <<<'PHP'
            <?php
            namespace core;

            class renamed {}

            class_alias($newclassname, $classname);
            PHP;

        $output = $this->processCode($input);

        $this->assertStringNotContainsString('class_alias', $output);
        $this->assertStringContainsString('class renamed', $output);
    }

    public function testClassAliasResolvesUseImports(): void
    {
        $input = <<<'PHP'
            <?php
            namespace core\deprecated;

            use core\url as canonical_url;

            class keeper {}

            class_alias(canonical_url::class, \moodle_url::class);
            PHP;

        $output = $this->processCode($input);

        $this->assertStringContainsString('class moodle_url extends \core\url', $output);
    }

    public function testClassAliasInUnnamespacedFileEmittedInline(): void
    {
        $input = <<<'PHP'
            <?php
            class_alias('SimplePie\Author', 'SimplePie_Author');
            class Foo {}
            PHP;

        $output = $this->processCode($input);

        $this->assertStringContainsString(
            'class SimplePie_Author extends \SimplePie\Author',
            $output,
        );
        $this->assertStringNotContainsString('namespace', $output);
    }

    public function testClassAliasMatchingExistingDeclarationIsSkipped(): void
    {
        $input = <<<'PHP'
            <?php
            namespace core;

            class emoticon_manager {}

            class_alias(emoticon_manager::class, \core\emoticon_manager::class);
            PHP;

        $output = $this->processCode($input);

        $this->assertStringNotContainsString('class_alias', $output);
        $this->assertSame(
            1,
            substr_count($output, 'class emoticon_manager'),
            'Self-referential alias must not duplicate the declaration',
        );
    }
}
