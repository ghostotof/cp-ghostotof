<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Un audit dont la panne ne change jamais l'issue (#725, la règle de
 * l'auteur du 2026-10-27, appliquée par #726 au point de jeton et à la
 * purge), **écrit une fois** (#726, S1) : le refus de league rendu tel quel,
 * les jetons émis, la purge menée à son terme.
 *
 * La ligne est tentée ; une panne, quelle qu'elle soit (`Throwable` : une
 * erreur du pilote, du chiffrement, du journal lui-même), sort en `critical`
 * sur le canal principal — l'audit manqué reste visible — avec **la classe
 * seule**, jamais le message ni la trace : une erreur du pilote ou du
 * chiffrement pourrait citer l'artefact ou la connexion. Le message est une
 * constante de l'appelant, qui nomme ce qui n'a pas été audité.
 *
 * Un service plutôt qu'un trait : le journal est injecté une fois (le canal
 * principal, celui de l'autowiring), et chaque site garde sa boucle et son
 * message — une panne tentée par ligne, pour qu'aucune n'en taise une autre.
 */
final readonly class FailSafeAudit
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param Closure(): void  $write          l'écriture de la ligne d'audit
     * @param non-empty-string $failureMessage constant, sans rien de l'artefact
     */
    public function attempt(Closure $write, string $failureMessage): void
    {
        try {
            $write();
        } catch (Throwable $failure) {
            $this->logger->critical($failureMessage, ['exceptionClass' => $failure::class]);
        }
    }
}
