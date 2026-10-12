<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use RuntimeException;

/**
 * La file de messages n'a pas pris le message (issue #485) : broker arrêté
 * (son redémarrage `Recreate` à chaque release), refus ou accusé manquant
 * depuis les confirmations de publication (#479). Une panne d'un tiers,
 * temporaire : 503, journalisée en `warning` — plus le 500 `critical`
 * générique de la TransportException de Messenger.
 *
 * Template Method : la classe porte ce qui est commun à tous les contextes
 * qui publient — le statut et le `type` problem+json
 * `/errors/message-queue-unavailable` que le client reconnaît —, chaque
 * contexte n'en fournit que la formulation, dans sa propre classe finale dont
 * le constructeur passe une phrase **littérale** : ProblemDetailStaysStaticTest
 * l'exige, le message étant le `detail` renvoyé au client (une fabrique
 * commune qui composait la phrase a été écartée pour cette raison). Le `Domain`
 * d'un contexte ne dépend ainsi que de `Shared/Domain`, et `Shared` ignore la
 * liste des contextes : un nouveau producteur de messages ajoute sa classe sans
 * rien modifier ici. Chaque classe concrète a son entrée `log_level` dans
 * `framework.exceptions` (ExceptionLogLevelCoverageTest refuse une entrée sur
 * une classe abstraite).
 *
 * La cause reste en `previous`, que le noyau journalise : une erreur de canal
 * AMQP ne cite que le code et le transport, jamais le corps du message — à
 * l'inverse des réponses SMTP, d'où la règle contraire des exceptions de
 * remise d'e-mail (#356). Le message, lui, est le `detail` montré au client :
 * il ne cite jamais la panne.
 */
abstract class MessageQueueUnavailableException extends RuntimeException implements ProblemExceptionInterface
{
    use HasProblemType;

    final protected function problemType(): string
    {
        return 'message-queue-unavailable';
    }

    final protected function problemStatus(): int
    {
        return 503;
    }
}
