<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Security;

use App\Security\Authentication\Infrastructure\Security\SessionBeforePasswordChangeRefusalListener;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\EventListener\CheckCredentialsListener;
use Symfony\Component\Security\Http\EventListener\UserCheckerListener;

/**
 * Où vit le refus d'une session antérieure au changement de mot de passe (#650,
 * R1b) : sur le firewall `api` — le seul qui lit le cookie `BEARER` —, après le
 * chargement du compte, et nulle part ailleurs. Le bearer MCP (`mcp`) n'est pas
 * un JWT de session (il est révoqué en base au changement de mot de passe), le
 * login et `oauth` n'ont pas de passeport JWT.
 */
final class SessionBeforePasswordChangeRefusalListenerRegistrationTest extends KernelTestCase
{
    public function testItIsRegisteredOnTheApiFirewallAfterTheAccountIsLoaded(): void
    {
        $dispatcher = $this->dispatcher('api');
        $priorities = [];

        foreach ($dispatcher->getListeners(CheckPassportEvent::class) as $listener) {
            $target = \is_array($listener) ? $listener[0] : $listener;
            if (\is_object($target)) {
                $priorities[$target::class] = $dispatcher->getListenerPriority(CheckPassportEvent::class, $listener);
            }
        }

        self::assertArrayHasKey(SessionBeforePasswordChangeRefusalListener::class, $priorities, 'Le refus n\'est pas inscrit sur le firewall `api`.');
        $refusal = $priorities[SessionBeforePasswordChangeRefusalListener::class];
        self::assertLessThan($priorities[UserCheckerListener::class], $refusal, 'Le refus passe après UserCheckerListener, qui charge le compte.');
        self::assertGreaterThan($priorities[CheckCredentialsListener::class], $refusal);
    }

    /** @return iterable<string, array{string}> */
    public static function otherFirewalls(): iterable
    {
        yield 'login' => ['login'];
        yield 'mcp' => ['mcp'];
        yield 'oauth' => ['oauth'];
    }

    #[DataProvider('otherFirewalls')]
    public function testItIsNotRegisteredOnAnyOtherFirewall(string $firewall): void
    {
        foreach ($this->dispatcher($firewall)->getListeners(CheckPassportEvent::class) as $listener) {
            $target = \is_array($listener) ? $listener[0] : $listener;
            self::assertNotInstanceOf(SessionBeforePasswordChangeRefusalListener::class, $target, \sprintf('Le refus est inscrit sur le firewall `%s`.', $firewall));
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
