<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Accès à un service du conteneur de test hors d'un test PHPUnit, pour les
 * processus fils des tests de concurrence (issue #389).
 *
 * Même raison d'être que {@see RateLimiterFactoryLocator} : les services
 * applicatifs sont privés, seul le conteneur de test les expose, et hériter de
 * KernelTestCase l'obtient exactement comme un test (KERNEL_CLASS, même cache
 * de conteneur).
 *
 * Jamais exécutée comme un test : pas de suffixe `Test.php`. Ne pas l'appeler
 * depuis un test : l'état du kernel de KernelTestCase est statique, donc
 * partagé, et le test perdrait le sien.
 */
final class TestServiceLocator extends KernelTestCase
{
    public static function locate(string $id): object
    {
        self::bootKernel();

        return self::getContainer()->get($id);
    }
}
