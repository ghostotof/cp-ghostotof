<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * Le problem+json de tout refus de débit applicatif : `type`
 * `/errors/rate-limited`, statut 429 (issue #369). C'est le `type` que rendent
 * aussi les zones nginx (`@rate_limited`), si bien qu'un client n'a qu'une
 * valeur à reconnaître, quelle que soit la borne atteinte.
 *
 * Une exception de quota déclare
 * `implements ProblemExceptionInterface, RetryAfterAware` et utilise ce
 * trait. Un trait ne peut pas porter le `implements` : c'est
 * QuotaExceptionsContractTest qui exige les deux de toute RetryAfterAware de
 * src/. Le `detail` reste le message de chaque exception, littéral
 * (ProblemDetailStaysStaticTest).
 */
trait IsRateLimitedProblem
{
    use HasProblemType;

    protected function problemType(): string
    {
        return 'rate-limited';
    }

    protected function problemStatus(): int
    {
        return 429;
    }
}
