<?php

declare(strict_types=1);

namespace App\Shared\Application\Message;

use App\Shared\Domain\Exception\MessageQueueUnavailableException;
use Throwable;

/**
 * Message routé vers le transport `async` (issue #491) : il nomme l'exception
 * de son contexte, que {@see \App\Shared\Infrastructure\Messenger\QueueUnavailableMiddleware}
 * lève quand la file ne le prend pas (broker arrêté, refus ou accusé manquant,
 * #479) — un 503 nommé, `warning`, au lieu du 500 `critical` générique de la
 * TransportException de Messenger.
 *
 * La conversion est écrite une fois, dans le bus ; chaque contexte ne fournit
 * que sa classe, et `Shared` n'en connaît aucun (règle de #485). Tout message
 * routé `async` l'implémente, sauf exception justifiée :
 * AsyncMessagesNameTheirQueueFailureTest y veille, dans chaque environnement.
 */
interface AsyncMessageInterface
{
    /**
     * @param Throwable $cause la panne du transport, à garder en `previous`,
     *                         jamais citée dans le message (le `detail` du client)
     */
    public function queueUnavailable(Throwable $cause): MessageQueueUnavailableException;
}
