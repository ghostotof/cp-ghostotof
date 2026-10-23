<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\RouteCollection;

/**
 * Le noyau de l'application dans un environnement donné, démarré pour lire les
 * routes qu'il sert (issue #659). Le tri par environnement est celui du noyau
 * lui-même — config/routes/<env>/, `when@<env>` en YAML comme en PHP,
 * `#[Route(env: …)]` —, aucune forme n'est relue à part. Il faut le conteneur
 * compilé, à la différence d'{@see EnvironmentConfigKernel} : les chargeurs
 * de routes (attributs, API Platform, MCP) sont des services.
 *
 * Hors débogage, comme la production : un environnement qui ne tourne qu'en
 * débogage (`dev`, `test` sous KernelTestCase) est donc lu tel qu'il serait
 * sans — une route qui dépendrait de `%kernel.debug%` échapperait à la
 * comparaison, limite acceptée (aucune).
 *
 * Le conteneur est compilé dans un cache propre au processus, vide au départ
 * et supprimé après lecture : hors débogage, le noyau reprendrait sans le
 * vérifier un conteneur compilé par une exécution précédente (des contrôleurs
 * et des ressources d'avant la modification), et un cache partagé pourrait
 * être effacé sous les pieds d'une autre suite qui tourne en même temps.
 */
final class EnvironmentRoutesKernel extends Kernel
{
    public function __construct(string $environment)
    {
        parent::__construct($environment, false);
    }

    public function getCacheDir(): string
    {
        return RepositoryFiles::backend(\sprintf('var/cache/environment_routes/%s-%d', $this->environment, getmypid()));
    }

    public function routes(): RouteCollection
    {
        $filesystem = new Filesystem();
        $filesystem->remove($this->getCacheDir());
        try {
            $this->boot();

            return $this->getContainer()->get('router')->getRouteCollection();
        } finally {
            $this->shutdown();
            $filesystem->remove($this->getCacheDir());
        }
    }
}
