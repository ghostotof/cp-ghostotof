<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Accès à un service du conteneur de test hors d'un test PHPUnit, pour les
 * processus fils des tests de concurrence (issues #277, #389, #435, #458).
 *
 * Les services applicatifs et les fabriques `limiter.<nom>` sont privés : seul
 * le conteneur de test les expose. Hériter de KernelTestCase l'obtient
 * exactement comme un test (KERNEL_CLASS, même cache de conteneur). La
 * composition (un `new Kernel()` puis `test.service_container`) ferait la même
 * chose, mais phpstan-symfony analyse le conteneur de dev, qui n'a pas ces
 * services, et refuse l'appel. Elle remplace RateLimiterFactoryLocator, qui
 * faisait la même chose pour les seuls limiteurs (issue #403).
 *
 * Le kernel n'est démarré qu'au premier appel : les services tirés par
 * plusieurs appels partagent ainsi un seul conteneur, donc une seule
 * connexion PostgreSQL — redémarrer le kernel à chaque appel laisserait le
 * premier service sur un conteneur éteint.
 *
 * Jamais exécutée comme un test : pas de suffixe `Test.php`. Ne pas l'appeler
 * depuis un test : l'état du kernel de KernelTestCase est statique, donc
 * partagé, et le test perdrait le sien.
 */
final class TestServiceLocator extends KernelTestCase
{
    public static function locate(string $id): object
    {
        if (!self::$booted) {
            self::bootKernel();
        }

        return self::getContainer()->get($id);
    }
}
