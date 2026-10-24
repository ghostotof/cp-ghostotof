<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Http;

use App\Mcp\Infrastructure\Http\DeferredAuthorizationListener;
use App\Mcp\Infrastructure\Http\InboundMessageRefusalListener;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\EventListener\FirewallListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Le 401 différé (#648) décide sur l'identité que le firewall `mcp` a posée :
 * il doit donc passer **après** le FirewallListener de SecurityBundle — avant
 * lui, le jeton d'une connexion valide ne serait pas encore lu, et chaque
 * appel authentifié de l'outil nominatif recevrait le défi. Il passe aussi
 * après les refus d'avant le SDK (#644) : un lot de plusieurs messages reste
 * un 400, jamais jugé message par message. Ce test lit le vrai répartiteur de
 * `kernel.request` : c'est l'ordre relatif qui est épinglé, pas une valeur de
 * priorité.
 */
final class DeferredAuthorizationListenerPriorityTest extends KernelTestCase
{
    public function testItRunsAfterTheFirewallAndTheInboundRefusals(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');

        $positions = [];
        foreach ($dispatcher->getListeners(KernelEvents::REQUEST) as $position => $listener) {
            $target = \is_array($listener) ? $listener[0] : $listener;

            if ($target instanceof DeferredAuthorizationListener) {
                $positions['deferred'] = $position;
            } elseif ($target instanceof FirewallListener) {
                $positions['firewall'] = $position;
            } elseif ($target instanceof InboundMessageRefusalListener) {
                $positions['refusals'] = $position;
            }
        }

        self::assertArrayHasKey('deferred', $positions, 'DeferredAuthorizationListener n\'écoute pas kernel.request.');
        self::assertArrayHasKey('firewall', $positions, 'Le FirewallListener de SecurityBundle n\'écoute plus kernel.request : revoir la place du 401 différé.');
        self::assertArrayHasKey('refusals', $positions);
        self::assertGreaterThan($positions['firewall'], $positions['deferred'], 'Le 401 différé passe avant le firewall : l\'identité d\'une connexion n\'est pas encore posée.');
        self::assertGreaterThan($positions['refusals'], $positions['deferred'], 'Le 401 différé passe avant les refus d\'avant le SDK.');
    }
}
