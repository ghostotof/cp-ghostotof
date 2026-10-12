<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Le Dockerfile de l'image PHP, lu instruction par instruction comme Docker
 * le passe au shell : lignes de commentaire retirées, lignes de continuation
 * jointes. Un `|| true` renvoyé à la ligne suivante ou une commande cachée
 * derrière un commentaire ne lui échappent donc pas (issues #474, #489).
 */
final class ProductionDockerfile
{
    public const string WORKDIR = '/var/www/backend';

    public static function contents(): string
    {
        $path = \dirname(__DIR__, 3).'/docker/php/Dockerfile';
        Assert::assertFileIsReadable($path);

        return (string) file_get_contents($path);
    }

    /**
     * La seule instruction qui contient `$needle`, sur une ligne.
     */
    public static function instructionContaining(string $needle): string
    {
        $lines = array_filter(
            explode("\n", self::contents()),
            static fn (string $line): bool => !str_starts_with(ltrim($line), '#'),
        );
        $instructions = explode("\n", (string) preg_replace('/\\\\\n\s*/', ' ', implode("\n", $lines)));

        $matching = array_values(array_filter(
            $instructions,
            static fn (string $instruction): bool => str_contains($instruction, $needle),
        ));
        Assert::assertCount(1, $matching, \sprintf('Une seule instruction du Dockerfile doit contenir « %s ».', $needle));

        return $matching[0];
    }
}
