<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Log\Fixtures\QuotaSources;

use App\Shared\Domain\Exception\RetryAfterAware;
use DateTimeImmutable;
use DomainException;

/**
 * Fixture de ThrottledRequestAuditCoverageTest (issue #361) : un quota de
 * plus, ajouté sans être rattaché à aucun événement d'audit ni justifié comme
 * quota par compte. Le garde-fou doit le signaler.
 */
final class UnauditedQuotaFixtureException extends DomainException implements RetryAfterAware
{
    public function __construct(public readonly DateTimeImmutable $retryAfter)
    {
        parent::__construct('Quota de test atteint.');
    }
}
