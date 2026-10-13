<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Security;

use App\Security\Authentication\Infrastructure\Security\OverlongPasswordRefusalListener;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\EventListener\CheckCredentialsListener;
use Symfony\Component\Security\Http\EventListener\LoginThrottlingListener;
use Symfony\Component\Security\Http\EventListener\UserCheckerListener;

/**
 * OverlongPasswordRefusalListener (issue #588) n'a de sens que dans une
 * fenêtre de `CheckPassportEvent` : **sous** le `checkPassport` de
 * `login_throttling`, qui pose l'identifiant tenté (sans lui, le refus serait
 * compté sous un identifiant vide, et une paire bridée ne recevrait plus son
 * 429), et **au-dessus** de tout écouteur qui charge le compte
 * (`UserCheckerListener`, `CheckCredentialsListener`) — sans quoi son temps
 * redépendrait de l'existence de l'identifiant. Ce test lit le vrai dispatcher
 * du firewall `login`, où les écouteurs globaux sont recopiés.
 */
final class OverlongPasswordRefusalListenerPriorityTest extends KernelTestCase
{
    public function testItSitsBetweenTheThrottlingAndTheFirstListenerThatLoadsTheAccount(): void
    {
        $dispatcher = self::getContainer()->get('security.event_dispatcher.login');

        $priorities = [];
        foreach ($dispatcher->getListeners(CheckPassportEvent::class) as $listener) {
            $target = \is_array($listener) ? $listener[0] : $listener;
            if (\is_object($target)) {
                $priorities[$target::class] = $dispatcher->getListenerPriority(CheckPassportEvent::class, $listener);
            }
        }

        self::assertArrayHasKey(OverlongPasswordRefusalListener::class, $priorities, 'Le refus n\'est pas inscrit sur le firewall `login`.');
        $refusal = $priorities[OverlongPasswordRefusalListener::class];

        foreach ([LoginThrottlingListener::class, UserCheckerListener::class, CheckCredentialsListener::class] as $class) {
            self::assertArrayHasKey($class, $priorities, \sprintf('%s n\'écoute plus CheckPassportEvent sur le firewall `login` : revoir la place du refus.', $class));
        }

        self::assertLessThan($priorities[LoginThrottlingListener::class], $refusal, 'Le refus passe avant `login_throttling` : il serait compté sous un identifiant vide.');
        self::assertGreaterThan($priorities[UserCheckerListener::class], $refusal, 'Le refus passe après UserCheckerListener, qui charge le compte.');
        self::assertGreaterThan($priorities[CheckCredentialsListener::class], $refusal, 'Le refus passe après la vérification du mot de passe.');
    }
}
