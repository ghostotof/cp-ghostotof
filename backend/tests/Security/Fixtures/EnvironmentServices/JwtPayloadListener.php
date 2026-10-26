<?php

declare(strict_types=1);

namespace App\Tests\Security\Fixtures\EnvironmentServices;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;

/**
 * Fixture de SecurityServicesEnvironmentParityTest (relecture de #698) : un
 * écouteur de `lexik_jwt_authentication.on_jwt_decoded`, un événement nommé
 * par une chaîne et non par sa classe, déclaré pour `prod` seul. Là, il
 * pourrait réécrire `username` dans le payload décodé et faire passer un
 * jeton du palier de base pour un compte réel (mesuré par l'audit). Il ne
 * réécrit rien : seule sa déclaration compte. Jamais chargé hors de ce test.
 */
final class JwtPayloadListener
{
    public function onJwtDecoded(JWTDecodedEvent $event): void
    {
        $event->getPayload();
    }
}
