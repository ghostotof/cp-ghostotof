<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\AbstractRecursivePass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Passe de compilation qui relève les références d'un service d'API Platform
 * à un alias déprécié (issue #701).
 *
 * Quand une définition d'API Platform référence un alias déprécié d'un autre
 * paquet, la dépréciation est levée par les passes de compilation de Symfony
 * (`ResolveReferencesToAliasesPass`), jamais par un fichier d'API Platform :
 * {@see \App\Tests\Support\ApiPlatformDeprecations} n'y voit aucune trame
 * d'API Platform, et le message nomme l'alias sans dire qui le référence. La
 * référence se lit donc dans les définitions, avant que cette passe ne la
 * remplace par la cible de l'alias. Elle suit les conditions de Symfony, pas
 * plus : une définition dépréciée qui référence un alias déprécié ne lève rien,
 * et dans une chaîne d'alias chaque maillon a pour référent le précédent.
 *
 * **Seul l'alias est rapporté, pas le service déprécié.** Symfony lève la
 * dépréciation d'un service à son instanciation (`ContainerBuilder::createService()`,
 * ou l'usine que `PhpDumper` écrit dans le conteneur compilé), pas à la
 * compilation : la signaler ici serait imputer à la compilation ce qu'elle ne
 * lève pas, y compris pour un service qu'aucune requête n'instancie. Les
 * requêtes de la garde la voient, avec la pile qui dit qui l'a provoquée.
 *
 * Elle ne fait que lire : à poser en `TYPE_BEFORE_OPTIMIZATION`, de priorité
 * minimale (toutes les passes qui ajoutent des références explicites sont
 * passées), dans {@see \App\Tests\Support\EnvironmentServicesKernel}. Elle
 * rapporte la compilation en cours : `found()` repart de zéro à chaque passage.
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

    public function process(ContainerBuilder $container): void
    {
        $this->found = [];
        parent::process($container);
    }

    protected function processValue(mixed $value, bool $isRoot = false): mixed
    {
        if ($value instanceof Reference && $this->isApiPlatformService($this->currentId)) {
            $this->check((string) $this->currentId, (string) $value);
        }

        return parent::processValue($value, $isRoot);
    }

    /**
     * Un service d'API Platform se reconnaît à son identifiant (`api_platform.…`,
     * caché ou non — `.api_platform.…` — ; ou une classe de `ApiPlatform\`) ou à
     * sa classe. Une `ChildDefinition` n'a pas toujours la sienne : elle vient
     * de son parent, qu'on remonte comme Symfony le fait pour lire la classe
     * (`AbstractRecursivePass::getConstructor()`), identifiants compris.
     */
    private function isApiPlatformService(?string $id): bool
    {
        $container = $this->container;
        $seen = [];
        while (null !== $id && null !== $container && !isset($seen[$id])) {
            $seen[$id] = true;
            if (str_starts_with(ltrim($id, '.'), self::SERVICE_ID_PREFIX) || str_starts_with($id, self::NAMESPACE_PREFIX)) {
                return true;
            }

            if ($container->hasAlias($id)) {
                $id = (string) $container->getAlias($id);

                continue;
            }

            if (!$container->hasDefinition($id)) {
                return false;
            }

            $definition = $container->getDefinition($id);
            $class = $definition->getClass();
            if (null !== $class && str_starts_with($class, self::NAMESPACE_PREFIX)) {
                return true;
            }

            $id = $definition instanceof ChildDefinition ? $definition->getParent() : null;
        }

        return false;
    }

    /**
     * Suit la chaîne d'alias en rapportant ce que Symfony lève, maillon par
     * maillon (`ResolveReferencesToAliasesPass::getDefinitionId()`) : un alias
     * déprécié est signalé sauf si son référent l'est — le service pour le
     * premier, l'alias précédent pour les suivants.
     */
    private function check(string $referrer, string $reference): void
    {
        $container = $this->container;
        if (null === $container) {
            return;
        }

        $id = $reference;
        $referrerDeprecated = $container->hasDefinition($referrer) && $container->getDefinition($referrer)->isDeprecated();
        $seen = [];
        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $alias = $container->getAlias($id);
            if ($alias->isDeprecated() && !$referrerDeprecated) {
                $this->found[] = \sprintf('%s référence l\'alias déprécié « %s » : %s', $referrer, $id, $alias->getDeprecation($id)['message']);
            }
            $referrerDeprecated = $alias->isDeprecated();
            $id = (string) $alias;
        }
    }
}
