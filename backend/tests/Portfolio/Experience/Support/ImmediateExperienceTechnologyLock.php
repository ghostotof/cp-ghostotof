<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Experience\Support;

use App\Portfolio\Experience\Application\ExperienceTechnologyLockInterface;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyNotFoundException;
use App\Portfolio\Experience\Domain\Repository\ExperienceTechnologyRepositoryInterface;
use Closure;
use Symfony\Component\Uid\Uuid;

/**
 * `ExperienceTechnologyLockInterface` sans base : lit la technologie par le
 * dépôt reçu et exécute l'opération sur-le-champ, ou la dit introuvable. Les
 * tests unitaires de l'administrateur vérifient ce qu'il écrit, pas la
 * sérialisation (issue #545) — celle-ci est l'affaire de
 * {@see \App\Tests\Portfolio\Experience\Application\ExperienceTechnologyWritesUnderRowLockTest} et de
 * {@see \App\Tests\Portfolio\Experience\Infrastructure\Doctrine\PostgresExperienceTechnologyRowLockTest},
 * contre PostgreSQL.
 */
final readonly class ImmediateExperienceTechnologyLock implements ExperienceTechnologyLockInterface
{
    public function __construct(
        private ExperienceTechnologyRepositoryInterface $experienceTechnologyRepository,
    ) {
    }

    public function whileLocked(Uuid $id, Closure $operation): mixed
    {
        $technology = $this->experienceTechnologyRepository->findOneById($id);
        if (null === $technology) {
            throw ExperienceTechnologyNotFoundException::forId($id);
        }

        return $operation($technology);
    }
}
