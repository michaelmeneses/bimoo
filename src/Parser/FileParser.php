<?php

declare(strict_types=1);

/**
 * Bimoo — Moodle Stub Generator
 *
 * @author     Michael Meneses <michael@middag.com.br>
 * @copyright  2026 MIDDAG (https://www.middag.com.br)
 * @license    GNU General Public License v3.0 or later
 */

namespace Bimoo\Parser;

use PhpParser\Node\Stmt;
use PhpParser\Parser;
use PhpParser\ParserFactory;

class FileParser
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * Parse a PHP file and return its AST statements.
     *
     * @return Stmt[]|null The AST statements, or null on failure
     */
    public function parse(string $filePath): ?array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return null;
        }

        $code = file_get_contents($filePath);
        if ($code === false) {
            return null;
        }

        try {
            return $this->parser->parse($code);
        } catch (\Throwable) {
            return null;
        }
    }
}
