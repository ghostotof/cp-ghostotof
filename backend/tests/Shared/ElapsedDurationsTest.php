<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\PhpSources;
use PhpToken;
use PHPUnit\Framework\TestCase;

/**
 * Une durée en heures s'ajoute en heures écoulées (issue #445).
 *
 * Dans le fuseau de PHP (Europe/Paris), `modify('+48 hours')` compte en heure
 * murale : 49 h la veille du passage à l'heure d'hiver, 47 h la veille du
 * passage à l'heure d'été (mesuré sous PHP 8.5). `add(new DateInterval('PT48H'))`
 * compte 48 h écoulées. Les minutes, elles, sont exactes avec `modify()`, et un
 * nombre de jours y est voulu en jours de calendrier : seules les heures sont
 * refusées.
 *
 * Recensement par jetons : toute chaîne littérale du code qui ajoute ou retire
 * des heures (`'+48 hours'` rangée dans une constante, `'+%d hours'` passée à
 * `sprintf()`), jamais un commentaire qui la cite.
 */
final class ElapsedDurationsTest extends TestCase
{
    private const string RELATIVE_HOURS = '/^[\'"][^\'"]*[+-]\s*(?:\d+|%d)\s*hours?\b/i';

    public function testNoStringLiteralAddsOrSubtractsHours(): void
    {
        $offenders = [];
        foreach (PhpSources::files(\dirname(__DIR__, 2).'/src') as $file) {
            $code = file_get_contents($file);
            self::assertIsString($code);
            $offenders = [...$offenders, ...$this->relativeHourLiterals($code, $file)];
        }

        self::assertSame([], $offenders, 'Durée relative en heures, que modify() compte en heure murale : add(new DateInterval(\'PTnH\')) (#445).');
    }

    /**
     * Les deux formes d'origine sont vues, les minutes, les jours, une
     * durée ISO et un commentaire ne le sont pas.
     */
    public function testTheCensusSeesHoursOnlyAndInCodeOnly(): void
    {
        $code = <<<'PHP'
            <?php
            const LIFETIME = '+48 hours';
            $at->modify(\sprintf('+%d hours', 36));
            $at->modify('-1 hour');
            $at->modify(\sprintf('+%d minutes', 5));
            $at->modify('-30 days');
            $at->add(new DateInterval('PT48H'));
            // modify('+48 hours') donnait 49 h
            PHP;

        self::assertSame(['fixture:2', 'fixture:3', 'fixture:4'], $this->relativeHourLiterals($code, 'fixture'));
    }

    /**
     * @return list<string> `<fichier>:<ligne>` de chaque chaîne littérale en heures relatives
     */
    private function relativeHourLiterals(string $code, string $file): array
    {
        $found = [];
        foreach (PhpToken::tokenize($code) as $token) {
            if ($token->is(\T_CONSTANT_ENCAPSED_STRING) && 1 === preg_match(self::RELATIVE_HOURS, $token->text)) {
                $found[] = \sprintf('%s:%d', $file, $token->line);
            }
        }

        return $found;
    }
}
