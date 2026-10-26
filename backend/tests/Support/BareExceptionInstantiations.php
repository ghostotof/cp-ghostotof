<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Recense les `new Exception`, `new LogicException`, `new RuntimeException`
 * et `new InvalidArgumentException` nus d'un répertoire (issues #338 et #383),
 * et depuis #678 `new NotFoundHttpException` et `new BadRequestHttpException`,
 * journalisées en `info` comme des erreurs du client (une absence ou un refus
 * qui dit un défaut du serveur reçoit sa classe, son entrée la précédant),
 * d'après les jetons des sources et non
 * d'après un grep : un `use LogicException;` suivi de `new LogicException`, un
 * alias, un import dans une liste à virgules ou une casse différente désignent
 * la même classe pour PHP, et un homonyme de l'espace de noms courant n'en est
 * pas une.
 *
 * Toute instanciation compte, levée ou non : une exception construite puis
 * levée plus loin reste une exception générique, et une classe anonyme qui
 * l'étend sans rien y ajouter aussi. Les autres classes de la SPL
 * ({@see \DomainException}…) sont hors périmètre : aucune n'est levée nue dans
 * `src/`, et les ajouter ici est la marche à suivre le jour où l'une le serait.
 *
 * Les noms se résolvent par {@see ClassNameResolver}, partagé avec
 * {@see \App\Tests\Shared\Infrastructure\Http\ProblemDetailStaysStaticTest}
 * (issue #507) : un nom relatif d'un fichier à espace de noms y désigne une
 * classe de cet espace, jamais une classe globale visée. Limite assumée : un
 * import groupé (`use Foo\{A, B};`) n'est pas résolu, voir sa documentation ;
 * aucune des classes visées n'a d'ailleurs d'espace de noms à grouper.
 */
final class BareExceptionInstantiations
{
    /** Noms en minuscules, sans `\` initial : PHP résout les classes sans tenir compte de la casse. */
    private const array FORBIDDEN = [
        'exception',
        'logicexception',
        'runtimeexception',
        'invalidargumentexception',
        // En `info` dans framework.exceptions depuis #678 : nue dans src/, un
        // défaut du serveur rendu en 404 ou en 400 y disparaîtrait.
        'symfony\\component\\httpkernel\\exception\\notfoundhttpexception',
        'symfony\\component\\httpkernel\\exception\\badrequesthttpexception',
    ];

    /**
     * @return list<string> `chemin:ligne` de chaque instanciation, triés pour un diagnostic stable
     */
    public static function in(string $directory): array
    {
        return PhpSources::sites($directory, self::inCode(...));
    }

    /**
     * @return list<int> lignes des instanciations interdites, dans l'ordre du code
     */
    public static function inCode(string $code): array
    {
        $tokens = PhpSources::significantTokens($code);

        $lines = [];
        foreach (ClassNameResolver::scopes($tokens) as $index => [$namespace, $imports]) {
            if (!$tokens[$index]->is(\T_NEW)) {
                continue;
            }

            $class = ClassNameResolver::instantiatedName($tokens, $index + 1);
            if (null !== $class && \in_array(ClassNameResolver::resolve($class, $namespace, $imports), self::FORBIDDEN, true)) {
                $lines[] = $tokens[$index]->line;
            }
        }

        return $lines;
    }
}
