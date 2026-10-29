<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Security;

use App\Security\Authentication\Infrastructure\Security\PublicRouteIgnoresRefusedSessionListener;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Où vit le rendu de la main à une route publique (#748, R35) : sur le firewall
 * `api` — le seul qui lit le cookie `BEARER` —, **en dernier** sur
 * `LoginFailureEvent` (il a le dernier mot sur la réponse), et nulle part
 * ailleurs : `login` ne lit aucun JWT, `mcp` et `oauth` sont d'autres firewalls.
 */
final class PublicRouteIgnoresRefusedSessionRegistrationTest extends KernelTestCase
{
    public function testItIsRegisteredOnTheApiFirewallAndRunsLast(): void
    {
        $dispatcher = $this->dispatcher('api');
        $priorities = [];

        foreach ($dispatcher->getListeners(LoginFailureEvent::class) as $listener) {
            $target = \is_array($listener) ? $listener[0] : $listener;
            if (\is_object($target)) {
                $priorities[$target::class] = $dispatcher->getListenerPriority(LoginFailureEvent::class, $listener);
            }
        }

        self::assertArrayHasKey(PublicRouteIgnoresRefusedSessionListener::class, $priorities, 'L\'écouteur n\'est pas inscrit sur le firewall `api`.');
        $ours = $priorities[PublicRouteIgnoresRefusedSessionListener::class];
        unset($priorities[PublicRouteIgnoresRefusedSessionListener::class]);
        foreach ($priorities as $class => $priority) {
            self::assertLessThan($priority, $ours, \sprintf('L\'écouteur ne passe pas après %s : il n\'a plus le dernier mot sur la réponse.', $class));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function otherDispatchers(): iterable
    {
        yield 'login' => ['login'];
        yield 'mcp' => ['mcp'];
        yield 'oauth' => ['oauth'];
    }

    #[DataProvider('otherDispatchers')]
    public function testItIsNotRegisteredOnAnyOtherFirewall(string $firewall): void
    {
        foreach ($this->dispatcher($firewall)->getListeners(LoginFailureEvent::class) as $listener) {
            $target = \is_array($listener) ? $listener[0] : $listener;
            self::assertNotInstanceOf(PublicRouteIgnoresRefusedSessionListener::class, $target, \sprintf('L\'écouteur est inscrit sur le firewall `%s`.', $firewall));
        }
    }

    public function testItIsNotRegisteredOnTheMainDispatcher(): void
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get('event_dispatcher');

        foreach ($dispatcher->getListeners(LoginFailureEvent::class) as $listener) {
            $target = \is_array($listener) ? $listener[0] : $listener;
            self::assertNotInstanceOf(PublicRouteIgnoresRefusedSessionListener::class, $target, 'L\'écouteur est inscrit sur le dispatcher principal.');
        }
    }

    private function dispatcher(string $firewall): EventDispatcherInterface
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get('security.event_dispatcher.'.$firewall);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }
}
