<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\DependencyInjection\Compiler\AbstractRecursivePass;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Passe de compilation qui relève les références d'un service d'API Platform
 * à un alias ou à un service déprécié (issue #701).
 *
 * Quand une définition d'API Platform référence un alias ou un service
 * déprécié d'un autre paquet, la dépréciation est levée par les passes de
 * compilation de Symfony (`ResolveReferencesToAliasesPass`), jamais par un
 * fichier d'API Platform : {@see \App\Tests\Support\ApiPlatformDeprecations}
 * n'y voit aucune trame d'API Platform, et le message nomme l'alias sans dire
 * qui le référence. La référence se lit donc dans les définitions, avant que
 * cette passe ne la remplace par la cible de l'alias.
 *
 * Elle ne fait que lire : à poser en `TYPE_BEFORE_OPTIMIZATION`, de priorité
 * minimale (toutes les passes qui ajoutent des références explicites sont
 * passées), dans {@see \App\Tests\Support\EnvironmentServicesKernel}.
 *
 * Limite : une référence qu'un autre mécanisme ajoute plus tard, au cours de
 * l'optimisation (l'autowiring d'un service d'API Platform sur un alias
 * déprécié), n'est pas vue ici ; la compilation en mémoire qui l'accueille est
 * observée par {@see \App\Tests\Support\ApiPlatformDeprecations} pour ce que
 * ses passes lèvent depuis un fichier d'API Platform.
 */
final class DeprecatedReferencesOfApiPlatform extends AbstractRecursivePass
{
    /** Le préfixe des identifiants des services d'API Platform. */
    private const string SERVICE_ID_PREFIX = 'api_platform.';

    /** L'espace de noms des classes d'API Platform, pour les services qui portent leur classe en identifiant. */
    private const string NAMESPACE_PREFIX = 'ApiPlatform\\';

    /** @var list<string> */
    private array $found = [];

    /**
     * @return list<string> une ligne par référence : qui référence quoi, et la dépréciation déclarée
     */
    public function found(): array
    {
        return $this->found;
    }

    protected function processValue(mixed $value, bool $isRoot = false): mixed
    {
        if ($value instanceof Reference && $this->isApiPlatformService($this->currentId)) {
            $this->check((string) $this->currentId, (string) $value);
        }

        return parent::processValue($value, $isRoot);
    }

    private function isApiPlatformService(?string $id): bool
    {
        if (null === $id) {
            return false;
        }

        if (str_starts_with($id, self::SERVICE_ID_PREFIX) || str_starts_with($id, self::NAMESPACE_PREFIX)) {
            return true;
        }

        $container = $this->container;
        $class = null !== $container && $container->hasDefinition($id) ? $container->getDefinition($id)->getClass() : null;

        return null !== $class && str_starts_with($class, self::NAMESPACE_PREFIX);
    }

    /**
     * Suit la chaîne d'alias jusqu'à la définition : un alias déprécié en bout
     * de chaîne est une dépréciation tout autant que le premier.
     */
    private function check(string $referrer, string $reference): void
    {
        $container = $this->container;
        if (null === $container) {
            return;
        }

        $id = $reference;
        $seen = [];
        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $alias = $container->getAlias($id);
            if ($alias->isDeprecated()) {
                $this->found[] = \sprintf('%s référence l\'alias déprécié « %s » : %s', $referrer, $id, $alias->getDeprecation($id)['message']);
            }
            $id = (string) $alias;
        }

        if ($container->hasDefinition($id) && $container->getDefinition($id)->isDeprecated()) {
            $this->found[] = \sprintf('%s référence le service déprécié « %s » : %s', $referrer, $id, $container->getDefinition($id)->getDeprecation($id)['message']);
        }
    }
}
