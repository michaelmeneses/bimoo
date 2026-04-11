<?php

declare(strict_types=1);

/**
 * Bimoo — Moodle Stub Generator
 *
 * @author     Michael Meneses <michael@middag.com.br>
 * @copyright  2026 MIDDAG (https://www.middag.com.br)
 * @license    GNU General Public License v3.0 or later
 */

namespace Bimoo\Stub\NodeVisitor;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

class ConstantCollector extends NodeVisitorAbstract
{
    /** @var string[] */
    private array $constants = [];

    public function enterNode(Node $node): null
    {
        // Pattern 1: define('NAME', value)
        if ($node instanceof Stmt\Expression
            && $node->expr instanceof FuncCall
            && $node->expr->name instanceof Name
            && $node->expr->name->toLowerString() === 'define'
            && isset($node->expr->args[0])
            && $node->expr->args[0]->value instanceof String_
        ) {
            $this->constants[] = $node->expr->args[0]->value->value;
        }

        // Pattern 2: const NAME = value
        if ($node instanceof Stmt\Const_) {
            foreach ($node->consts as $const) {
                $this->constants[] = $const->name->toString();
            }
        }

        return null;
    }

    /** @return string[] */
    public function getConstantNames(): array
    {
        return $this->constants;
    }

    public function getCount(): int
    {
        return count($this->constants);
    }

    public function reset(): void
    {
        $this->constants = [];
    }
}
