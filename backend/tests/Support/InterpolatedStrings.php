<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Les chaînes interpolées dont un fragment a le texte d'une accolade, d'une
 * parenthèse ou d'un crochet, écrites une fois pour les recenseurs par jetons
 * qui comptent des niveaux ({@see ClassNameResolver::scopes()},
 * {@see UnsortedImports}, {@see AnonymousClassesAndFreeFunctions} et le modèle
 * de `ProblemDetailStaysStaticTest`). Le texte d'un T_ENCAPSED_AND_WHITESPACE
 * `}` est celui d'une accolade du code, son identifiant n'en est pas un : un
 * recenseur qui compte par le texte décale son niveau (issue #748, C15-2).
 *
 * Chaque valeur est du code PHP, sans point-virgule : le cas l'écrit dans son
 * extrait. Les deux dernières de {@see self::BRACES} sont de vraies
 * interpolations (`T_CURLY_OPEN`, `T_DOLLAR_OPEN_CURLY_BRACES`) : aucun
 * fragment n'y a le texte d'une accolade, mais leur ouverture n'a pas
 * l'identifiant `{`.
 */
final class InterpolatedStrings
{
    /** @var array<string, string> libellé => chaîne dont un fragment, ou l'ouverture, est une accolade */
    public const array BRACES = [
        'un objet JSON écrit entre guillemets' => '"{\"id\":$id}"',
        'une accolade fermante après une variable' => '"$x}"',
        'une accolade échappée' => '"\{$a}"',
        'une accolade ouvrante après une variable' => '"$x{"',
        'une interpolation {$x}' => '"{$x}"',
        'une interpolation ${x}' => '"${x}"',
    ];

    /** @var array<string, string> libellé => chaîne dont un fragment est une parenthèse */
    public const array PARENTHESES = [
        'une parenthèse fermante après une variable' => '"$x)"',
        'une parenthèse ouvrante après une variable' => '"$x("',
    ];

    /** Un crochet fermant après une variable : `"$x["` ouvrirait un indice. */
    public const string CLOSING_BRACKET = '"$x]"';
}
