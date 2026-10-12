<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use App\Shared\Application\Message\AsyncMessageInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Une publication que la file ne prend pas devient l'exception que nomme le
 * message (issue #491, suite de #485) : écrite ici une fois, plutôt qu'un
 * `try`/`catch` recopié chez chaque producteur, qu'un nouveau aurait pu oublier.
 *
 * Inscrit sur le bus par défaut (`messenger.yaml`), donc placé par Symfony
 * avant `send_message` : il entoure l'envoi au transport. Rattrape la classe
 * TransportException, que lève l'AmqpSender — elle n'implémente **pas**
 * TransportExceptionInterface (vérifié pendant #479).
 *
 * Ne touche jamais au worker : un message reçu (ReceivedStamp) n'est pas
 * republié par `send_message`, et les réessais comme le `failure_transport`
 * appellent leur sender directement, sans repasser par le bus. Un message qui
 * n'implémente pas {@see AsyncMessageInterface} — la sonde de #470, dont la
 * commande cite la panne — garde la TransportException telle quelle.
 */
final readonly class QueueUnavailableMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (TransportException $failure) {
            $message = $envelope->getMessage();
            if (!$message instanceof AsyncMessageInterface || null !== $envelope->last(ReceivedStamp::class)) {
                throw $failure;
            }

            throw $message->queueUnavailable($failure);
        }
    }
}
