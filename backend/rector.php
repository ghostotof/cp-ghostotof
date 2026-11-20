<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Attribute\SortAttributeNamedArgsRector;
use Rector\CodeQuality\Rector\FuncCall\SortCallLikeNamedArgsRector;
use Rector\CodeQuality\Rector\If_\ExplicitNullableObjectInstanceRector;
use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
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
        ClassPropertyAssignToConstructorPromotionRector::class, // les entites Doctrine gardent leurs proprietes explicites

        // Celle-ci n'est pas cosmetique : elle met Rector et PHPStan en
        // desaccord. Transformer une closure en callable de premiere classe
        // capture la signature *complete* de la fonction native, parametres
        // optionnels compris — `version_compare(...)` devient
        // `callable(string,string,?string): (bool|int)`, que `usort` refuse
        // puisqu'il attend `callable(string,string): int`. La closure explicite
        // est la seule forme que les deux outils acceptent.
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class,

        // Celle-ci non plus n'est pas cosmetique, et elle est dangereuse : issue
        // #743. Ajoutee au jeu codeQuality par Rector 2.8, elle reecrit
        // `null === $x` en `!$x instanceof Foo` dans 85 fichiers, ce qui rend
        // statiquement toujours vraies (ou inatteignables) des gardes que le
        // typage ne garantit pas a l'execution. Enchainee avec
        // RemoveAlwaysTrueIfConditionRector, RemoveUnreachableStatementRector et
        // RemoveUnusedVariableAssignRector, elle a supprime le refus 400 du
        // serveur MCP dans InboundMessageRefusalListener (gardes #675 et #686)
        // dans un diff de 85 fichiers qu'une relecture rapide laisse passer. Ne la
        // retirer d'ici qu'apres avoir relu, un par un, les refus qu'elle ferait
        // disparaitre. Elle remplace FlipTypeControlToUseExclusiveTypeRector, que
        // Rector 2.8 deprecie en ce sens (docblock de la classe : « Use
        // ExplicitNullableObjectInstanceRector instead ») et n'enregistre plus :
        // l'ancien skip, devenu mort, a ete retire.
        ExplicitNullableObjectInstanceRector::class,
    ])
    ->withCache(__DIR__.'/var/cache/rector')
    // Toute classe est importee par `use`, natives comprises (`use Throwable;`,
    // jamais `\Throwable`) : issue #391, regle dans .claude/rules/backend-architecture.md.
    // Les fonctions et constantes natives gardent leur antislash (`\sprintf`) :
    // importShortClasses ne les touche pas, et l'antislash conserve les opcodes
    // dedies d'OPcache.
    ->withImportNames(importShortClasses: true, removeUnusedImports: true)
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
        instanceOf: true,
    );
