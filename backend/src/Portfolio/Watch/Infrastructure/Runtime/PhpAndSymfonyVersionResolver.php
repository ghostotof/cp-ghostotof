<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\Runtime;

use App\Portfolio\Watch\Domain\Service\InstalledVersionResolverInterface;
use App\Portfolio\Watch\Domain\ValueObject\VersionSource;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Lit les deux versions que le processus connaît de lui-même.
 *
 * C'est le cœur de la décision D2 : sur les deux entrées qu'un lecteur regarde
 * en premier, la page ne peut pas mentir. Un radar de veille affichant une
 * version périmée par simple oubli de saisie se retournerait contre son auteur.
 */
final readonly class PhpAndSymfonyVersionResolver implements InstalledVersionResolverInterface
{
    public function resolve(VersionSource $source): ?string
    {
        return match ($source) {
            VersionSource::RUNTIME_PHP => PHP_VERSION,
            VersionSource::RUNTIME_SYMFONY => Kernel::VERSION,
            VersionSource::MANUAL => null,
        };
    }
}
