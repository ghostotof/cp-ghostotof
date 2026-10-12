<?php

declare(strict_types=1);

namespace App\Shared\Application\Message;

use App\Shared\Domain\Exception\MessageQueueUnavailableException;
use Throwable;

/**
 * Message que le bus publie dans une file (issue #491) — `async`, ou tout
 * transport dont le DSN n'est pas `sync://` : il nomme l'exception de son
 * contexte, que {@see \App\Shared\Infrastructure\Messenger\QueueUnavailableMiddleware}
 * lève quand la file ne le prend pas (broker arrêté, refus ou accusé manquant,
 * #479) — un 503 nommé, `warning`, au lieu du 500 `critical` générique de la
 * {@see \Symfony\Component\Messenger\Exception\TransportException}.
 *
 * Factory Method : le middleware, écrit une fois dans `Shared`, ne sait pas
 * quelle exception instancier ; chaque message la fabrique, et `Shared`
 * n'en connaît aucune (règle de #485). Préférée à un attribut portant la
 * classe, qui aurait demandé un `new $class($failure)` que PHPStan juge
 * contre le constructeur de RuntimeException, et refuse.
 *
 * Tout message routé vers une file l'implémente, sauf exception justifiée :
 * {@see \App\Tests\Shared\Infrastructure\Messenger\QueuedMessagesNameTheirQueueFailureTest}
 * y veille, dans chaque environnement.
 */
interface QueuedMessageInterface
{
    /**
     * @param Throwable $cause la panne du transport, à garder en `previous`,
     *                         jamais citée dans le message (le `detail` du client)
     */
    public function queueUnavailableException(Throwable $cause): MessageQueueUnavailableException;
}
