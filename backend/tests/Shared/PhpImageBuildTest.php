<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Tests\Support\PhpImageBuild;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

/**
 * Ce que {@see PhpImageBuild} lit de `php.prod.ini` (issue #612) : les tests
 * qui pincent une directive des images déployées passent tous par lui, la
 * racine du dépôt n'est résolue qu'à un endroit.
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
}
