<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use ApiPlatform\Symfony\EventListener\ExceptionListener as ApiPlatformExceptionListener;
use App\Shared\Infrastructure\Http\ApiProblemResponseListener;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * L'écouteur partagé ne décide pas « hors API Platform » par un attribut de
 * requête : il est placé APRÈS l'ExceptionListener d'API Platform, qui arrête
 * la propagation sur toutes ses routes. Ce qui lui parvient est donc hors API
 * Platform par construction (issue #322). Il doit aussi passer AVANT le rendu
 * générique de Symfony, sinon il n'est jamais appelé. Ce test lit le vrai
 * dispatcher : une priorité changée dans un sens ou dans l'autre le fait
 * tomber, avant qu'une route API Platform ne perde son rendu.
 */
final class ApiProblemResponseListenerPriorityTest extends KernelTestCase
{
    public function testItRunsAfterApiPlatformAndBeforeSymfonysRendering(): void
    {
        $priorities = [];
        $dispatcher = self::getContainer()->get('event_dispatcher');

        foreach ($dispatcher->getListeners(KernelEvents::EXCEPTION) as $listener) {
            $priority = $dispatcher->getListenerPriority(KernelEvents::EXCEPTION, $listener);
            $target = \is_array($listener) ? $listener[0] : $listener;

            if ($target instanceof ApiProblemResponseListener) {
                $priorities['shared'] = $priority;
            } elseif ($target instanceof ApiPlatformExceptionListener) {
                $priorities['apiPlatform'] = $priority;
            } elseif ($target instanceof ErrorListener && \is_array($listener) && 'onKernelException' === $listener[1]) {
                $priorities['symfonyRendering'] = $priority;
            }
        }

        self::assertArrayHasKey('shared', $priorities, 'ApiProblemResponseListener n\'est pas enregistré.');
        self::assertArrayHasKey('apiPlatform', $priorities);
        self::assertArrayHasKey('symfonyRendering', $priorities);
        self::assertLessThan($priorities['apiPlatform'], $priorities['shared']);
        self::assertGreaterThan($priorities['symfonyRendering'], $priorities['shared']);
    }
}
