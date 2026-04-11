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
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

class StubNodeVisitor extends NodeVisitorAbstract
{
    public function leaveNode(Node $node): int|Node|null
    {
        // Remove function/method bodies — replace with empty body
        // stmts === null means abstract or interface method (no body to remove)
        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod) {
            if ($node->stmts !== null) {
                $node->stmts = [];
            }

            return $node;
        }

        return null;
    }

    public function enterNode(Node $node): int|Node|null
    {
        // At namespace scope, filter what we keep
        if ($node instanceof Stmt\Namespace_) {
            $node->stmts = $this->filterStatements($node->stmts);

            return $node;
        }

        return null;
    }

    /**
     * @param Node[] $nodes
     *
     * @return Node[]
     */
    public function afterTraverse(array $nodes): ?array
    {
        // At root level, all nodes are Stmt instances
        /** @var Stmt[] $nodes */
        return $this->filterStatements($nodes);
    }

    /**
     * Filter statements to keep only declarations.
     *
     * @param Stmt[] $stmts
     *
     * @return Stmt[]
     */
    private function filterStatements(array $stmts): array
    {
        $kept = [];

        foreach ($stmts as $stmt) {
            if ($this->shouldKeep($stmt)) {
                if ($stmt instanceof Stmt\Expression && $this->isDefineCall($stmt)) {
                    $stmt = $this->sanitizeDefineCall($stmt);
                }
                $kept[] = $stmt;
            }
        }

        return $kept;
    }

    private function shouldKeep(Node $node): bool
    {
        // Always keep: classes, interfaces, traits, enums
        if ($node instanceof Stmt\Class_
            || $node instanceof Stmt\Interface_
            || $node instanceof Stmt\Trait_
            || $node instanceof Stmt\Enum_
        ) {
            return true;
        }

        // Always keep: function declarations
        if ($node instanceof Stmt\Function_) {
            return true;
        }

        // Always keep: const declarations at file scope
        if ($node instanceof Stmt\Const_) {
            return true;
        }

        // Keep namespace nodes only if they have declarations after filtering
        if ($node instanceof Stmt\Namespace_) {
            return !empty($node->stmts);
        }

        // Keep: define() calls
        if ($node instanceof Stmt\Expression && $this->isDefineCall($node)) {
            return true;
        }

        // Remove everything else (includes, expressions, assignments, echo, etc.)
        return false;
    }

    private function isDefineCall(Stmt\Expression $node): bool
    {
        $expr = $node->expr;

        return $expr instanceof FuncCall
            && $expr->name instanceof Name
            && $expr->name->toLowerString() === 'define';
    }

    /**
     * Replace non-literal define() values with null.
     */
    private function sanitizeDefineCall(Stmt\Expression $node): Stmt\Expression
    {
        $expr = $node->expr;

        if (!$expr instanceof FuncCall || count($expr->args) < 2) {
            return $node;
        }

        $value = $expr->args[1]->value;

        if (!$this->isLiteralValue($value)) {
            $expr->args[1]->value = new Node\Expr\ConstFetch(new Name('null'));
        }

        return $node;
    }

    private function isLiteralValue(Node\Expr $expr): bool
    {
        return $expr instanceof Scalar
            || $expr instanceof Node\Expr\ConstFetch
            || $expr instanceof Node\Expr\Array_
            || $expr instanceof Node\Expr\UnaryMinus
            || $expr instanceof Node\Expr\UnaryPlus;
    }
}
