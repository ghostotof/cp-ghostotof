<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Attribute\SortAttributeNamedArgsRector;
use Rector\CodeQuality\Rector\FuncCall\SortCallLikeNamedArgsRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php84\Rector\MethodCall\NewMethodCallWithoutParenthesesRector;

/*
 * Rector : modernisation PHP + qualite de code. Le CI (job `rector-backend`)
 * le lance en `--dry-run` (bloquant) ; en local : `composer rector` pour voir
 * les diffs, `composer rector:fix` pour les appliquer.
 *
 * Les paquets rector/rector-symfony, -doctrine et -phpunit ne sont PAS
 * installes : leurs dependances entrent en conflit avec les contraintes
 * `symfony/* 8.1.*` du projet (meme raison que psalm/plugin-symfony). Le socle
 * rector/rector suffit ici, la base de code etant deja sur du Symfony 8 moderne.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    // migrations/ : fichiers generes, historique fige — jamais reecrits (hors paths).
    ->withSkip([
        __DIR__.'/src/Kernel.php',           // squelette Flex
        __DIR__.'/tests/bootstrap.php',       // squelette Flex

        // Regles ecartees : purement cosmetiques ou stylistiquement discutables,
        // sans gain de correctnesss/lisibilite pour ce projet.
        SortAttributeNamedArgsRector::class,          // reordonne les args nommes d'attributs
        SortCallLikeNamedArgsRector::class,           // idem sur les appels
        NewMethodCallWithoutParenthesesRector::class, // `new Foo()->bar()` moins lisible que `(new Foo())->bar()`
        FlipTypeControlToUseExclusiveTypeRector::class, // `$x instanceof Foo` la ou `null !== $x` est plus clair
        ClassPropertyAssignToConstructorPromotionRector::class, // les entites Doctrine gardent leurs proprietes explicites
    ])
    ->withCache(__DIR__.'/var/cache/rector')
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
        instanceOf: true,
    );
