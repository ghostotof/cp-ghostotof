<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Les chaînes interpolées dont un fragment a le texte d'une accolade, d'une
 * parenthèse, d'un crochet, d'une virgule ou d'un dollar, écrites une fois pour
 * les cas des recenseurs par jetons qui comptent des niveaux ou découpent sur
 * un délimiteur. Le texte d'un T_ENCAPSED_AND_WHITESPACE `}` est celui d'une
 * accolade du code, son identifiant n'en est pas un : un recenseur qui lit par
 * le texte décale son niveau, coupe un argument ou réaffecte à tort (issue
 * #748, C15-2, D4).
 *
 * Une constante par délimiteur : {@see self::BRACES}, {@see self::PARENTHESES},
 * {@see self::CLOSING_BRACKET}, {@see self::COMMAS}, {@see self::DOLLARS}, et
 * {@see self::HEREDOCS} pour ceux qu'un heredoc ou un nowdoc porte. Chaque
 * valeur est du code PHP, sans point-virgule : le cas l'écrit dans son extrait.
 * Les deux dernières de {@see self::BRACES} sont de vraies interpolations
 * (`T_CURLY_OPEN`, `T_DOLLAR_OPEN_CURLY_BRACES`) : aucun fragment n'y a le
 * texte d'une accolade, mais leur ouverture n'a pas l'identifiant `{`.
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

    /** @var array<string, string> libellé => chaîne dont un fragment est une virgule */
    public const array COMMAS = [
        'une virgule après une variable' => '"$x,"',
    ];

    /** @var array<string, string> libellé => chaîne dont un fragment est un dollar : `"$x$"` n'ouvre aucune variable */
    public const array DOLLARS = [
        'un dollar après une variable' => '"$x$"',
    ];

    /**
     * Un heredoc dont un fragment, entre deux variables, a exactement le texte
     * d'un caractère du code, et un nowdoc dont le corps est ce caractère.
     * Le fragment d'un nowdoc, ou celui qui finit un heredoc, porte aussi le
     * saut de ligne qui précède le marqueur : son texte n'est jamais celui d'un
     * seul caractère, et la lecture par le texte ne s'y trompe pas.
     *
     * @var array<string, string> libellé => heredoc ou nowdoc, marqueur de fin compris
     */
    public const array HEREDOCS = [
        'un heredoc dont un fragment est une parenthèse fermante' => "<<<TXT\n\$c)\$c\nTXT",
        'un heredoc dont un fragment est un crochet fermant' => "<<<TXT\n\$c]\$c\nTXT",
        'un heredoc dont un fragment est une virgule' => "<<<TXT\n\$c,\$c\nTXT",
        'un heredoc dont un fragment est un point-virgule' => "<<<TXT\n\$c;\$c\nTXT",
        'un heredoc dont un fragment est un dollar' => "<<<TXT\n\$c\$\$c\nTXT",
        'un nowdoc dont le corps est une parenthèse fermante' => "<<<'TXT'\n)\nTXT",
        'un nowdoc dont le corps est un crochet fermant' => "<<<'TXT'\n]\nTXT",
        'un nowdoc dont le corps est une virgule' => "<<<'TXT'\n,\nTXT",
        'un nowdoc dont le corps est un point-virgule' => "<<<'TXT'\n;\nTXT",
        'un nowdoc dont le corps est un dollar' => "<<<'TXT'\n\$\nTXT",
    ];
}
