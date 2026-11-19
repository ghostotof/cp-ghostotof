<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\IsRateLimitedProblem;
use App\Shared\Domain\Exception\RetryAfterAware;
use DateTimeImmutable;
use DomainException;

/**
 * Levée lorsqu'un appelant a déclenché trop de récupérations de documents de
 * métadonnées CIMD sur la fenêtre de son quota, ou que tous les appelants
 * ensemble en ont déclenché trop sur celle du plafond commun (#651, D-F, cf.
 * {@see \App\Mcp\Connection\Infrastructure\Cimd\SymfonyClientMetadataFetchQuota}).
 * Une seule exception pour les deux : l'effet est le même, un refus
 * temporaire ; son `retryAfter` est celui du limiteur qui a refusé.
 *
 * La récupération sort du serveur vers un hôte choisi par un tiers : sans
 * plafond, un anonyme qui présente des `client_id` toujours différents ferait
 * du point d'autorisation un amplificateur de requêtes sortantes.
 *
 * Le refus ne sort pas tel quel de l'API : le résolveur
 * ({@see \App\Mcp\Connection\Application\Cimd\CimdClientResolver}) le convertit
 * en {@see ClientMetadataRefusedException} de cause `Throttled`, que le point
 * d'autorisation traduit en réponse OAuth. L'exception implémente tout de même
 * le contrat des quotas (`RetryAfterAware`, problème `/errors/rate-limited`,
 * niveau `info` dans `framework.exceptions`) que QuotaExceptionsContractTest
 * impose à toute exception de débit : son `retryAfter` est repris dans la
 * réponse quand elle sera rendue.
 */
final class ClientMetadataFetchRateLimitExceededException extends DomainException implements ProblemExceptionInterface, RetryAfterAware
{
    use IsRateLimitedProblem;

    public function __construct(public readonly DateTimeImmutable $retryAfter)
    {
        parent::__construct('Trop de documents de client récupérés. Réessayez plus tard.');
    }
}
