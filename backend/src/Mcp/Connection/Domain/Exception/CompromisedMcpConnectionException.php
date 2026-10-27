<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Une connexion MCP compromise, constatée au point de jeton (#649) : un
 * rafraîchissement déjà tourné présenté de nouveau
 * ({@see \App\Mcp\Connection\Domain\Exception\RotatedRefreshTokenReusedException}), un code déjà échangé rejoué
 * ({@see \App\Mcp\Connection\Domain\Exception\AuthorizationCodeReplayedException}). Le jeton ou le code présenté a fuité, ou son client
 * rejoue une réponse perdue : les deux se ressemblent trait pour trait, et
 * la connexion doit mourir.
 *
 * Levée **dans** la transaction de league, qui est alors annulée (rien de
 * l'échange ne survit) ; le point de jeton la rattrape **après** le ROLLBACK,
 * révoque la connexion par le révocateur — une instruction SQL, valable
 * EntityManager fermé — puis rend au client le refus ordinaire de league,
 * mot pour mot, pour que la détection ne lui apprenne rien.
 *
 * Elle porte ce que la révocation demande, l'identifiant de la connexion et
 * la cause, rien d'autre : son message est constant, sans jeton, sans code,
 * sans empreinte ni UUID — il peut finir dans un journal.
 *
 * Une RuntimeException, **jamais une LogicException** (DomainException
 * comprise) ni une InvalidArgumentException : league entoure la validation
 * du code de ces deux `catch` — une InvalidArgumentException y devient un
 * `invalid_grant` « Cannot validate the provided authorization code », une
 * LogicException un `invalid_request` « Issue decrypting the authorization
 * code ». Sous DomainException, l'exception n'en sortait pas, la connexion
 * restait active (mesuré, tâche 4 de #649). Un rejeu est une
 * condition d'exécution, pas une erreur du programme : le parent est juste
 * aussi. `CompromisedMcpConnectionExceptionTest` l'épingle.
 *
 * Le point de jeton choisit le refus de league par le type de la sous-classe
 * ({@see \App\Mcp\Connection\Application\TokenEndpoint}), sans bras par défaut : une
 * nouvelle compromission s'ajoute **aussi** à ce choix —
 * `CompromisedMcpConnectionExceptionTest` recense les sous-classes de `src/`
 * et échoue sur une inconnue.
 */
abstract class CompromisedMcpConnectionException extends RuntimeException
{
    final protected function __construct(
        string $message,
        private readonly Uuid $connectionId,
        private readonly RevocationCause $revocationCause,
    ) {
        parent::__construct($message);
    }

    /** La connexion à révoquer. */
    public function connectionId(): Uuid
    {
        return $this->connectionId;
    }

    /** Pourquoi : la cause écrite avec la révocation, et dans son audit. */
    public function revocationCause(): RevocationCause
    {
        return $this->revocationCause;
    }
}
