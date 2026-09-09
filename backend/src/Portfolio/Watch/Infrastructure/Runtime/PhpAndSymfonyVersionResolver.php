<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\Runtime;

use App\Portfolio\Watch\Domain\Service\InstalledVersionResolverInterface;
use App\Portfolio\Watch\Domain\ValueObject\VersionSource;
use App\Portfolio\Watch\Infrastructure\Manifest\FileDeployedVersionsReader;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Détermine la version de chaque produit sans jamais la demander à personne.
 *
 * C'est le cœur de la décision D2, étendu depuis à tout le catalogue : sur
 * aucune entrée la page ne peut mentir, parce qu'aucune version n'est saisie.
 *
 * Trois provenances, une seule intention :
 *
 *  - PHP et Symfony sont lus dans le processus qui sert la page ;
 *  - PostgreSQL, RabbitMQ et nginx viennent des manifestes Kubernetes, relevés
 *    au moment de la construction de l'image — c'est le cluster qui fait foi ;
 *  - Node et Vue viennent respectivement de `.env` et du lock npm, eux aussi
 *    relevés au build.
 *
 * Le nom de la classe est resté celui des deux premières par commodité
 * d'historique ; ce qu'elle fait est plus large.
 */
final readonly class PhpAndSymfonyVersionResolver implements InstalledVersionResolverInterface
{
    public function __construct(private FileDeployedVersionsReader $deployedVersions)
    {
    }

    public function resolve(VersionSource $source, string $slug): ?string
    {
        return match ($source) {
            VersionSource::RUNTIME_PHP => PHP_VERSION,
            VersionSource::RUNTIME_SYMFONY => Kernel::VERSION,
            // Une version absente du relevé rend `null`, et l'appelant affiche
            // alors l'entrée sans version plutôt que de la faire disparaître.
            VersionSource::DEPLOYED => $this->deployedVersions->read()[$slug] ?? null,
            VersionSource::MANUAL => null,
        };
    }
}
