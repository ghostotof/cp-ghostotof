<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Security;

use App\Security\Authentication\Infrastructure\Security\LoginThrottlingRefusalListener;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * LoginThrottlingRefusalListener lève une exception, ce qui arrête la
 * propagation de LoginFailureEvent : tout écouteur placé après lui serait
 * sauté, et seulement sur les refus de throttling — le chemin d'une attaque,
 * en silence (issue #399). Ce test lit le vrai dispatcher du firewall `login`,
 * où RegisterGlobalSecurityEventListenersPass recopie aussi les écouteurs
 * globaux : un écouteur ajouté sous -200 par le projet, Symfony ou Lexik le
 * fait tomber, au lieu de perdre sa trace des refus.
 */
final class LoginThrottlingRefusalListenerPriorityTest extends KernelTestCase
{
    public function testItIsTheLastListenerOfTheLoginFirewallsFailureEvent(): void
    {
        $dispatcher = self::getContainer()->get('security.event_dispatcher.login');

        $listeners = $dispatcher->getListeners(LoginFailureEvent::class);
        self::assertNotEmpty($listeners, 'Aucun écouteur de LoginFailureEvent sur le firewall `login`.');

        $last = $listeners[array_key_last($listeners)];
        $target = \is_array($last) ? $last[0] : $last;

        self::assertInstanceOf(
            LoginThrottlingRefusalListener::class,
            $target,
            'Un écouteur de LoginFailureEvent passe désormais après LoginThrottlingRefusalListener : il ne verrait jamais un refus de throttling. Le placer plus haut, ou revoir la priorité du refus.',
        );

        $refusalPriority = $dispatcher->getListenerPriority(LoginFailureEvent::class, $last);
        foreach (\array_slice($listeners, 0, -1) as $listener) {
            self::assertGreaterThan($refusalPriority, $dispatcher->getListenerPriority(LoginFailureEvent::class, $listener), 'Deux écouteurs à la même priorité : l\'ordre ne dépendrait plus que de l\'enregistrement.');
        }
    }
}
