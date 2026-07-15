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

use PhpParser\Comment\Doc;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

class StubNodeVisitor extends NodeVisitorAbstract
{
    private const KIND_CLASS = 'class';
    private const KIND_ABSTRACT_CLASS = 'abstract class';
    private const KIND_INTERFACE = 'interface';
    private const KIND_TRAIT = 'trait';
    private const KIND_ENUM = 'enum';

    private ?string $currentNamespace = null;

    /** @var array<string, string> lowercased import alias => fully qualified name */
    private array $useMap = [];

    /** @var array<string, string> lowercased declared FQCN => KIND_* value */
    private array $declaredKinds = [];

    /** @var array<string, array{origin: string, alias: string}> keyed by lowercased alias FQCN */
    private array $pendingAliases = [];

    /**
     * @param Node[] $nodes
     *
     * @return Node[]|null
     */
    public function beforeTraverse(array $nodes): ?array
    {
        $this->currentNamespace = null;
        $this->useMap = [];
        $this->declaredKinds = [];
        $this->pendingAliases = [];

        return null;
    }

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
            $this->currentNamespace = $node->name?->toString();
            $this->useMap = [];
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
        /** @var Stmt[] $stmts */
        $stmts = $nodes;

        return $this->appendAliasDeclarations($this->filterStatements($stmts));
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
            if ($stmt instanceof Stmt\Expression && $this->recordClassAlias($stmt)) {
                // Re-emitted as a declaration in appendAliasDeclarations()
                continue;
            }

            if (!$this->shouldKeep($stmt)) {
                continue;
            }

            $this->recordSymbols($stmt);

            if ($stmt instanceof Stmt\Expression && $this->isDefineCall($stmt)) {
                $stmt = $this->sanitizeDefineCall($stmt);
            }

            $kept[] = $stmt;
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

        // Always keep: use imports — stripping them leaves extends/implements
        // clauses and signature types resolving as wrong-namespace short names
        if ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse) {
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

    /**
     * Track use imports and declared class-likes so class_alias() arguments
     * can be resolved to fully qualified names and to the right declaration
     * kind (class/interface/…) later on.
     */
    private function recordSymbols(Stmt $stmt): void
    {
        if ($stmt instanceof Stmt\Use_ && $stmt->type === Stmt\Use_::TYPE_NORMAL) {
            foreach ($stmt->uses as $use) {
                $this->useMap[$use->getAlias()->toLowerString()] = $use->name->toString();
            }

            return;
        }

        if ($stmt instanceof Stmt\GroupUse) {
            foreach ($stmt->uses as $use) {
                $type = $use->type !== Stmt\Use_::TYPE_UNKNOWN ? $use->type : $stmt->type;

                if ($type === Stmt\Use_::TYPE_NORMAL) {
                    $this->useMap[$use->getAlias()->toLowerString()]
                        = $stmt->prefix->toString() . '\\' . $use->name->toString();
                }
            }

            return;
        }

        if ($stmt instanceof Stmt\ClassLike && $stmt->name !== null) {
            $fqcn = $this->qualify($stmt->name->toString());

            $this->declaredKinds[strtolower($fqcn)] = match (true) {
                $stmt instanceof Stmt\Interface_ => self::KIND_INTERFACE,
                $stmt instanceof Stmt\Trait_ => self::KIND_TRAIT,
                $stmt instanceof Stmt\Enum_ => self::KIND_ENUM,
                $stmt instanceof Stmt\Class_ && $stmt->isAbstract() => self::KIND_ABSTRACT_CLASS,
                default => self::KIND_CLASS,
            };
        }
    }

    /**
     * Detect `class_alias(Origin::class, 'legacy_name')` statements with
     * statically-known arguments and queue them for re-emission. The call
     * itself never survives into the stub: static analysers do not evaluate
     * class_alias() in scanned files, so the alias only exists for them as a
     * real declaration (see appendAliasDeclarations()).
     */
    private function recordClassAlias(Stmt\Expression $node): bool
    {
        $expr = $node->expr;

        if (!$expr instanceof FuncCall
            || !$expr->name instanceof Name
            || $expr->name->toLowerString() !== 'class_alias'
            || count($expr->args) < 2
        ) {
            return false;
        }

        $origin = $this->resolveClassArgument($expr->args[0]);
        $alias = $this->resolveClassArgument($expr->args[1]);

        // Dynamic arguments (variables, calls…) cannot be modeled statically;
        // keep dropping those statements as before.
        if ($origin === null || $alias === null || strcasecmp($origin, $alias) === 0) {
            return false;
        }

        $this->pendingAliases[strtolower($alias)] = ['origin' => $origin, 'alias' => $alias];

        return true;
    }

    /**
     * Resolve a class_alias() argument to a fully qualified class name, or
     * null when the value is not statically known.
     */
    private function resolveClassArgument(Node\Arg|Node\VariadicPlaceholder $arg): ?string
    {
        if (!$arg instanceof Node\Arg || $arg->name !== null || $arg->unpack) {
            return null;
        }

        $value = $arg->value;

        // 'legacy_name' — string arguments are already fully qualified
        if ($value instanceof Scalar\String_) {
            $name = trim($value->value, '\\');

            return $name === '' ? null : $name;
        }

        // Origin::class / \core\url::class
        if ($value instanceof Node\Expr\ClassConstFetch
            && $value->class instanceof Name
            && $value->name instanceof Node\Identifier
            && $value->name->toLowerString() === 'class'
        ) {
            return $this->resolveName($value->class);
        }

        return null;
    }

    private function resolveName(Name $name): ?string
    {
        if (in_array($name->toLowerString(), ['self', 'static', 'parent'], true)) {
            return null;
        }

        if ($name instanceof Name\FullyQualified) {
            return $name->toString();
        }

        if ($name instanceof Name\Relative) {
            return $this->qualify($name->toString());
        }

        $imported = $this->useMap[strtolower($name->getFirst())] ?? null;

        if ($imported !== null) {
            $rest = array_slice($name->getParts(), 1);

            return $rest === [] ? $imported : $imported . '\\' . implode('\\', $rest);
        }

        return $this->qualify($name->toString());
    }

    private function qualify(string $name): string
    {
        return $this->currentNamespace === null ? $name : $this->currentNamespace . '\\' . $name;
    }

    /**
     * Re-emit recorded class_alias() calls as declarations placed in the
     * namespace of the alias name.
     *
     * Inheritance covers the receiving direction (an alias-typed value flows
     * into signatures typed with the canonical class); signatures that EXPECT
     * the legacy name still need consumer-side handling, since class identity
     * cannot be expressed statically.
     *
     * @param Stmt[] $stmts
     *
     * @return Stmt[]
     */
    private function appendAliasDeclarations(array $stmts): array
    {
        if ($this->pendingAliases === []) {
            return $stmts;
        }

        $hasNamespaceNodes = false;

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                $hasNamespaceNodes = true;
                break;
            }
        }

        /** @var array<string, Stmt[]> $byNamespace */
        $byNamespace = [];

        foreach ($this->pendingAliases as $lowerAlias => $alias) {
            // The alias name is already a real declaration in this file
            if (isset($this->declaredKinds[$lowerAlias])) {
                continue;
            }

            $declaration = $this->buildAliasDeclaration($alias['origin'], $alias['alias']);

            if ($declaration === null) {
                continue;
            }

            $pos = strrpos($alias['alias'], '\\');
            $namespace = $pos === false ? '' : substr($alias['alias'], 0, $pos);

            $byNamespace[$namespace][] = $declaration;
        }

        foreach ($byNamespace as $namespace => $declarations) {
            if ($hasNamespaceNodes) {
                $stmts[] = new Stmt\Namespace_(
                    $namespace === '' ? null : new Name($namespace),
                    $declarations,
                );
            } elseif ($namespace === '') {
                // Unnamespaced file: global aliases are emitted inline. A
                // namespaced alias here would force wrapping every statement
                // in braced namespace blocks — vendored edge case, dropped.
                $stmts = array_merge($stmts, $declarations);
            }
        }

        return $stmts;
    }

    private function buildAliasDeclaration(string $origin, string $alias): ?Stmt
    {
        $kind = $this->declaredKinds[strtolower($origin)] ?? self::KIND_CLASS;

        // Enums are final and traits cannot be extended — no declaration can
        // model those aliases, so skip rather than emit invalid PHP.
        if ($kind === self::KIND_ENUM || $kind === self::KIND_TRAIT) {
            return null;
        }

        $pos = strrpos($alias, '\\');
        $shortName = $pos === false ? $alias : substr($alias, $pos + 1);

        if (preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $shortName) !== 1) {
            return null;
        }

        $doc = new Doc(sprintf(
            "/**\n * Runtime class alias of \\%s registered by the original source,\n"
            . " * re-emitted as a declaration so static analysers can resolve the name.\n */",
            $origin,
        ));

        if ($kind === self::KIND_INTERFACE) {
            return new Stmt\Interface_(
                $shortName,
                ['extends' => [new Name\FullyQualified($origin)]],
                ['comments' => [$doc]],
            );
        }

        return new Stmt\Class_(
            $shortName,
            [
                'flags' => $kind === self::KIND_ABSTRACT_CLASS ? Modifiers::ABSTRACT : 0,
                'extends' => new Name\FullyQualified($origin),
            ],
            ['comments' => [$doc]],
        );
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
