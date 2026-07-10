<?php declare(strict_types=1);

namespace InteractionDesignFoundation\PsalmLaravelNova\Support;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use Psalm\Codebase;
use Psalm\Storage\ClassLikeStorage;

/**
 * Resolves the class-string a `public static $property = SomeClass::class` declaration points at,
 * read from the class's AST rather than from storage: a typed static property (e.g. `string $model`)
 * keeps its declared scalar type in `ClassLikeStorage`, so the concrete class-string default is only
 * recoverable by re-reading the property's initializer expression.
 *
 * Shared by any handler that needs to follow a Laravel Nova static convention property (`$model`,
 * `$policy`, …) to the FQCN it points at.
 * @internal
 */
final class StaticClassPropertyResolver
{
    /** @return class-string|null */
    public static function resolve(ClassLikeStorage $classStorage, Codebase $codebase, string $propertyName): ?string
    {
        $filePath = $classStorage->location?->file_path;
        if ($filePath === null) {
            return null;
        }

        // Psalm's stored AST does not carry php-parser's name-resolution data ('namespacedName'
        // on declarations, resolved names), so both the class lookup and the
        // `Relative\Name::class` resolution below would silently fail. Resolve names ourselves —
        // but NEVER on Psalm's shared, cached statements: Psalm's own pipeline stores the
        // 'resolvedName' attribute as a STRING, while php-parser's NameResolver stores a Name
        // object, and polluting the shared AST with object-typed attributes crashes Psalm's
        // analyzer later (getFQCLNFromNameObject(): Return value must be of type string).
        // Deep-clone the tree first, then resolve with node replacement on the private copy.
        $cloner = new NodeTraverser(new CloningVisitor());
        /** @var list<\PhpParser\Node\Stmt> $statements */
        $statements = $cloner->traverse($codebase->getStatementsForFile($filePath));

        $resolver = new NodeTraverser(new NameResolver());
        /** @var list<\PhpParser\Node\Stmt> $statements */
        $statements = $resolver->traverse($statements);

        $classNode = self::findClassNode($statements, $classStorage->name);
        if ($classNode === null) {
            return null;
        }

        return self::resolveClassConstFetch(self::findStaticPropertyDefault($classNode, $propertyName));
    }

    private static function findStaticPropertyDefault(Stmt\ClassLike $classNode, string $propertyName): ?Expr
    {
        foreach ($classNode->stmts as $stmt) {
            if (!$stmt instanceof Stmt\Property || !$stmt->isStatic()) {
                continue;
            }

            foreach ($stmt->props as $property) {
                if ($property->name->name === $propertyName) {
                    return $property->default;
                }
            }
        }

        return null;
    }

    /**
     * Resolve a `SomeClass::class` default expression to its fully qualified class name. Returns null
     * for any other expression shape (constant, string literal, null default, …).
     * @return class-string|null
     * @psalm-mutation-free
     */
    private static function resolveClassConstFetch(?Expr $expr): ?string
    {
        // resolve() ran php-parser's NameResolver with node replacement on this tree, so every
        // statically resolvable class name is already a FullyQualified node here. Anything else
        // (`self::class`, `static::class`, dynamic `$class::class`, …) is not resolvable to a
        // single FQCN — decline.
        if (!$expr instanceof ClassConstFetch
            || !$expr->name instanceof Identifier
            || $expr->name->name !== 'class'
            || !$expr->class instanceof Name\FullyQualified
        ) {
            return null;
        }

        /** @var class-string */
        return $expr->class->toString();
    }

    /**
     * @param list<\PhpParser\Node\Stmt> $stmts
     * @psalm-mutation-free
     */
    private static function findClassNode(array $stmts, string $fqcn): ?Stmt\ClassLike
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                $found = self::findClassNode($stmt->stmts, $fqcn);
                if ($found !== null) {
                    return $found;
                }

                continue;
            }

            // NameResolver (run by resolve()) populates the typed `namespacedName` property on
            // every named ClassLike declaration; it stays null on anonymous classes.
            if ($stmt instanceof Stmt\ClassLike && $stmt->namespacedName?->toString() === $fqcn) {
                return $stmt;
            }
        }

        return null;
    }
}
