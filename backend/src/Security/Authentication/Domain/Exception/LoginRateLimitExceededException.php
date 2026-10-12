<?php

declare(strict_types=1);

namespace App\Security\Authentication\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\IsRateLimitedProblem;
use App\Shared\Domain\Exception\RetryAfterAware;
use DateTimeImmutable;
use DomainException;

/**
 * Levée quand le `login_throttling` du firewall `login` refuse une tentative
 * (5 échecs par 15 min et par couple IP + identifiant, 25 par IP seule,
 * `security.yaml`) : le mot de passe n'a pas été vérifié.
 *
 * Elle remplace, sur ce seul refus, le 401 de Lexik
 * (« Too many failed login attempts », sans `type` ni `Retry-After`) par le
 * 429 `/errors/rate-limited` de tout refus de débit (issue #399, décidé dans
 * #369) : un client n'a plus à comparer une chaîne pour distinguer un mot de
 * passe faux d'un throttling. Levée par
 * {@see \App\Security\Authentication\Infrastructure\Security\LoginThrottlingRefusalListener},
 * rendue par {@see \App\Shared\Infrastructure\Http\ApiProblemResponseListener}
 * (`POST /api/login_check` n'est pas une opération API Platform), l'en-tête
 * `Retry-After` posé par {@see \App\Shared\Infrastructure\Http\RetryAfterListener},
 * le niveau de journalisation (`info`) fixé dans `framework.exceptions`.
 *
 * Le `detail` ne dit rien du compte : le même refus vaut pour un identifiant
 * connu comme inconnu (non-énumération, audit A10).
 */
final class LoginRateLimitExceededException extends DomainException implements ProblemExceptionInterface, RetryAfterAware
{
    use IsRateLimitedProblem;

    public function __construct(public readonly DateTimeImmutable $retryAfter)
    {
        parent::__construct('Trop de tentatives de connexion. Réessayez plus tard.');
    }
}
