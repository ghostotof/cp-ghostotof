<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\CommentedClassNames;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tout nom de classe écrit en entier dans un commentaire de `src/` ou de
 * `tests/` désigne une classe qui existe (issue #391). Une classe que le code
 * du fichier n'importe pas y est citée par son nom complet, sans `use` qui ne
 * servirait qu'à la documentation : ce test est la vérification qu'un tel
 * import n'aurait jamais eue, et attrape un renommage ou une suppression que
 * le commentaire n'a pas suivi.
 *
 * Le recensement est éprouvé d'abord sur des extraits littéraux : un
 * recenseur qui ne trouverait jamais rien rendrait le premier test vert sans
 * rien garder.
 */
final class CommentedClassNamesExistTest extends TestCase
{
    public function testEveryClassNamedInFullInACommentExists(): void
    {
        $root = \dirname(__DIR__, 2);

        self::assertSame([], CommentedClassNames::in($root.'/src', $root.'/tests'));
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('snippets')]
    public function testTheCensusReportsEveryNameThatResolvesToNothing(string $code, array $expected): void
    {
        self::assertSame($expected, CommentedClassNames::inCode($code));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function snippets(): iterable
    {
        yield 'classe du projet' => ["<?php\n/** Voir App\\Kernel. */\n", []];
        yield 'classe du projet absente' => ["<?php\n/** Voir App\\Nowhere\\Missing. */\n", ['2 App\\Nowhere\\Missing']];
        yield 'ligne exacte dans un docblock' => ["<?php\n/**\n * Rien.\n * App\\Nowhere\\Missing\n */\n", ['4 App\\Nowhere\\Missing']];
        yield 'classe native' => ["<?php\n// Lève une \\ValueError.\n", []];
        yield 'classe native absente' => ["<?php\n// Lève une \\NoSuchNativeError.\n", ['2 \\NoSuchNativeError']];
        yield 'classe d\'une dépendance' => ["<?php\n/** `Symfony\\Component\\Serializer\\Exception\\ExceptionInterface: 400` */\n", []];
        yield 'méthode existante' => ["<?php\n/** App\\Kernel::getAllowedEnvs() */\n", []];
        yield 'méthode absente' => ["<?php\n/** App\\Kernel::nowhere() */\n", ['2 App\\Kernel::nowhere()']];
        yield 'espace de noms' => ["<?php\n/** Vit sous App\\Shared, pas App\\Portfolio\\Shared. */\n", []];
        yield 'espace de noms parent d\'un préfixe PSR-4' => ["<?php\n/** Appels via Symfony\\AI. */\n", []];
        yield 'espace de noms absent' => ["<?php\n/** Vit sous App\\Nowhere. */\n", ['2 App\\Nowhere']];
        yield 'espace de noms suivi de \\*' => ["<?php\n/** App\\Security\\Authentication\\Infrastructure\\* */\n", []];
        yield 'nom relatif au contexte, non vérifié' => ["<?php\n/** Infrastructure\\Doctrine\\Nowhere */\n", []];
        yield 'raccourci Assert, non vérifié' => ["<?php\n/** #[Assert\\Nowhere] */\n", []];
        yield 'constante native' => ["<?php\n// Termine par \\PHP_EOL.\n", []];
        yield 'fonction native' => ["<?php\n// Formate par \\sprintf().\n", []];
        yield 'séquence d\'échappement' => ["<?php\n// Sépare par \"\\n\".\n", []];
        yield 'chaîne du code, pas un commentaire' => ["<?php\n\$class = 'App\\Nowhere\\Missing';\n", []];
    }
}
