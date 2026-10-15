<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\PhpImageBuild;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * Ce que {@see PhpImageBuild} lit de `php.prod.ini` (issue #612) et de
 * `www.prod.conf` (issue #615) : les tests qui pincent une directive des
 * images déployées passent par lui, et le chemin du fichier ne dépend plus de
 * leur emplacement. La lecture des directives est éprouvée sur des textes
 * écrits pour l'occasion.
 */
final class PhpImageBuildTest extends TestCase
{
    /**
     * La valeur brute, comme le fichier l'écrit : `On` reste `On` (le scanner
     * normal le lirait `1`), un fuseau reste un fuseau. Lire le vrai fichier
     * prouve aussi que la racine résolue est celle du dépôt.
     */
    public function testADirectiveOfTheProductionIniIsReadRaw(): void
    {
        self::assertSame('On', PhpImageBuild::productionIniDirective('zend.exception_ignore_args'));
        self::assertSame('Europe/Paris', PhpImageBuild::productionIniDirective('date.timezone'));
    }

    /**
     * Le pool FPM des images (issue #615), lu comme `php.prod.ini` : le vrai
     * fichier, quel que soit l'emplacement du test qui le demande. Sa valeur
     * reste aux deux tests de borne du verrou, qui la pincent.
     */
    public function testTheProductionPoolIsReadFromTheBuildContext(): void
    {
        self::assertMatchesRegularExpression('/^env\[PGOPTIONS\] = "[^"]+"$/m', PhpImageBuild::productionPool());
    }

    /**
     * Une directive absente fait échouer le test qui la demande, en la
     * nommant, au lieu de rendre une chaîne vide qu'une assertion pourrait
     * accepter.
     */
    public function testAMissingDirectiveFailsTheTestNamingIt(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('php.prod.ini : aucune directive « no.such.directive ».');

        PhpImageBuild::productionIniDirective('no.such.directive');
    }

    /**
     * Écrite deux fois, une directive vaut sa dernière occurrence, celle que
     * PHP applique : la première, lue par une expression régulière, ferait
     * passer un `On` qu'un `Off` plus bas annule.
     */
    public function testTheLastOccurrenceWinsAsPhpAppliesIt(): void
    {
        self::assertSame('Off', PhpImageBuild::iniDirective("a = On\na = Off\n", 'a', 'essai.ini'));
    }

    /**
     * Le commentaire de fin de ligne et les guillemets sont retirés, la
     * valeur reste brute.
     */
    public function testTheValueIsReadRawWithoutCommentNorQuotes(): void
    {
        self::assertSame('On', PhpImageBuild::iniDirective("a = On ; commentaire\n", 'a', 'essai.ini'));
        self::assertSame('x y', PhpImageBuild::iniDirective("a = \"x y\"\n", 'a', 'essai.ini'));
    }

    public function testACommentedDirectiveIsAbsent(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('essai.ini : aucune directive « a ».');

        PhpImageBuild::iniDirective("; a = On\n", 'a', 'essai.ini');
    }

    public function testAListDirectiveIsRefused(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('essai.ini : « a » n\'est pas une valeur simple.');

        PhpImageBuild::iniDirective("a[] = x\n", 'a', 'essai.ini');
    }
}
