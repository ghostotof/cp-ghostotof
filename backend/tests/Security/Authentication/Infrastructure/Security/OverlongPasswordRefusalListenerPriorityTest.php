<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Security;

use App\Security\Authentication\Infrastructure\Security\OverlongPasswordRefusalListener;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\EventListener\CheckCredentialsListener;
use Symfony\Component\Security\Http\EventListener\LoginThrottlingListener;
use Symfony\Component\Security\Http\EventListener\UserCheckerListener;
use Symfony\Component\Security\Http\EventListener\UserProviderListener;

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
    /**
     * Les écouteurs qui passent avant le refus, dans l'ordre, et pourquoi ils
     * ne chargent pas le compte.
     *
     * @var list<class-string>
     */
    private const array LISTENERS_ALLOWED_ABOVE = [
        // Lit l'identifiant du UserBadge (getUserIdentifier(), pas getUser())
        // et consulte le limiteur : aucun accès au compte.
        LoginThrottlingListener::class,
        // 2048 sur le dispatcher du firewall (SecurityExtension) : pose le
        // chargeur du UserBadge s'il n'en a pas (setUserLoader()), sans
        // l'appeler.
        UserProviderListener::class,
    ];

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

    /**
     * Le firewall `api` ré-authentifie le JWT à chaque requête et ne reçoit
     * jamais de mot de passe : le refus n'y est pas inscrit, au lieu d'y
     * tourner pour rien sur chaque requête authentifiée.
     */
    public function testItIsNotRegisteredOnTheApiFirewall(): void
    {
        $dispatcher = self::getContainer()->get('security.event_dispatcher.api');

        foreach ($dispatcher->getListeners(CheckPassportEvent::class) as $listener) {
            $target = \is_array($listener) ? $listener[0] : $listener;
            self::assertNotInstanceOf(OverlongPasswordRefusalListener::class, $target, 'Le refus d\'un mot de passe trop long est inscrit sur le firewall `api`.');
        }
    }

    /**
     * Tout écouteur placé au-dessus du refus tourne aussi pour une saisie trop
     * longue : s'il chargeait le compte, ce chemin redeviendrait plus lent
     * pour un identifiant existant, et le test ci-dessus, qui ne nomme que
     * trois écouteurs, resterait vert. Au-dessus, seulement ce qui ne charge
     * rien.
     */
    public function testNoListenerAboveItCanLoadTheAccount(): void
    {
        $dispatcher = self::getContainer()->get('security.event_dispatcher.login');
        $refusal = null;
        $above = [];

        foreach ($dispatcher->getListeners(CheckPassportEvent::class) as $listener) {
            $target = \is_array($listener) ? $listener[0] : $listener;
            if (!\is_object($target)) {
                continue;
            }
            if ($target instanceof OverlongPasswordRefusalListener) {
                $refusal = $dispatcher->getListenerPriority(CheckPassportEvent::class, $listener);

                break;
            }
            // getListeners() rend les écouteurs par priorité décroissante.
            $above[] = $target::class;
        }

        self::assertNotNull($refusal, 'Le refus n\'est pas inscrit sur le firewall `login`.');
        self::assertSame(
            self::LISTENERS_ALLOWED_ABOVE,
            $above,
            'Un écouteur de CheckPassportEvent passe désormais avant le refus d\'un mot de passe trop long : vérifier qu\'il ne charge pas le compte, puis l\'ajouter à LISTENERS_ALLOWED_ABOVE avec sa justification.',
        );
    }
}
