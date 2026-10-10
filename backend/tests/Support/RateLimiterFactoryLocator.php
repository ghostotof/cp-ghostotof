<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Accès à une fabrique de limiteur hors d'un test PHPUnit, pour les processus
 * fils de RateLimiterConcurrencyTest (issue #277).
 *
 * `limiter.<nom>` est un service privé : seul le conteneur de test l'expose.
 * Hériter de KernelTestCase, c'est l'obtenir exactement comme n'importe quel
 * test — classe du kernel lue dans KERNEL_CLASS, même cache de conteneur. La
 * composition (un `new Kernel()` puis `test.service_container`) ferait la
 * même chose, mais phpstan-symfony analyse le conteneur de dev, qui n'a pas
 * ce service, et refuse l'appel.
 *
 * Jamais exécutée comme un test : pas de suffixe `Test.php`. Ne pas l'appeler
 * depuis un test : l'état du kernel de KernelTestCase est statique, donc
 * partagé, et le test perdrait le sien.
 */
final class RateLimiterFactoryLocator extends KernelTestCase
{
    public static function locate(string $limiterName): ?RateLimiterFactoryInterface
    {
        self::bootKernel();
        $factory = self::getContainer()->get('limiter.'.$limiterName);

        return $factory instanceof RateLimiterFactoryInterface ? $factory : null;
    }
}
