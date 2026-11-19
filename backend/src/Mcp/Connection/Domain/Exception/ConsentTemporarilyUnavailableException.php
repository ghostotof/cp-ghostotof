<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;
use DomainException;
use Throwable;

/**
 * Le consentement ne peut pas, pour l'instant, revalider le client de la
 * demande (#651, décision du 2026-11-16) : le document de métadonnées d'un
 * client CIMD n'a pas pu être récupéré — quota de récupération épuisé, hôte ou
 * réseau en défaut. Rien n'est jugé sur la demande : la personne peut
 * réessayer, ce n'est pas un 400.
 *
 * Même verdict qu'au point d'autorisation, mêmes codes : **429** pour le
 * quota, **503** pour l'hôte ou le réseau. Le code est celui du refus de
 * league ({@see \App\Mcp\Connection\Application\AuthorizationRequestValidator}),
 * repris tel quel.
 *
 * Le `detail` est constant et ne dit jamais la cause (décision du 2026-11-15,
 * « le 400 du consentement reste générique, sans indice de cause » ; le
 * `detail` est rendu tel quel, ProblemDetailStaysStaticTest) : la cause est
 * dans la chaîne des `previous`, que le journal du noyau lit et dont
 * `RetryAfterListener` tire l'échéance du quota — l'exception n'est pas elle-même
 * un quota (`RetryAfterAware`), le limiteur qui a refusé est plus bas dans la chaîne.
 * Le refus est déjà tracé à sa source (`mcp-client-metadata-refused`, par
 * l'admission du client) : aucune trace de plus ici.
 */
abstract class ConsentTemporarilyUnavailableException extends DomainException implements ProblemExceptionInterface
{
    use HasProblemType;

    final public function __construct(Throwable $refusal)
    {
        parent::__construct('Le client de la demande d\'autorisation ne peut pas être vérifié pour le moment. Réessayez plus tard.', 0, $refusal);
    }

    /**
     * Le refus passager du validateur, dont le code HTTP décide du verdict :
     * 429 pour le quota, 503 pour tout autre. Deux classes, parce que le statut
     * d'une exception ne dépend que de sa classe (les garde-fous de statut et de
     * niveau de journal l'instancient sans constructeur).
     */
    public static function after(Throwable $refusal, int $httpStatus): self
    {
        return 429 === $httpStatus ? new ConsentRevalidationThrottledException($refusal) : new ConsentRevalidationUnavailableException($refusal);
    }

    protected function problemType(): string
    {
        return 'consent-temporarily-unavailable';
    }
}
