<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Error;
use Lexik\Bundle\JWTAuthenticationBundle\TokenExtractor\TokenExtractorInterface;
use LogicException;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Prend l'instantané des services de sécurité (issue #698), enregistrée en
 * dernière passe de `TYPE_BEFORE_REMOVING` par {@see EnvironmentServicesKernel}.
 * À ce point de la compilation, les définitions sont celles que le conteneur
 * servira — autoconfiguration appliquée, décorateurs résolus, `prepend()` des
 * bundles et passes d'optimisation faits, paramètres résolus, écouteurs
 * enregistrés sur leurs dispatchers (RegisterListenersPass, même phase, plus
 * haut) —, et aucune n'est encore inlinée ni retirée : un voter ou un
 * fournisseur privé s'y lit par son identifiant.
 *
 * Le périmètre :
 *  - les identifiants `security.*` et `lexik_jwt_authentication.*` : la carte
 *    d'accès, les contextes et dispatchers de firewall, la fabrique des
 *    hachages, ce que Lexik pose et lit ;
 *  - les classes de `App\Security\` et celles qui implémentent un des
 *    contrats de CONTRACTS, quel que soit leur contexte (l'authentificateur de
 *    la connexion MCP, par exemple) ;
 *  - les services étiquetés `security.voter` ;
 *  - les écouteurs des dispatchers de firewall, et ceux du dispatcher
 *    principal sur un événement `kernel.*` ou de sécurité — dont la liste est
 *    gardée aussi, pour qu'un écouteur ajouté se voie quelle que soit sa
 *    classe.
 * Un service hors périmètre n'est vu que par la référence qu'un service du
 * périmètre lui fait.
 *
 * Les valeurs `%env()%` sont rendues sous leur nom : leur marque de
 * compilation est tirée au hasard à chaque construction.
 */
final class SecurityServicesSnapshotPass implements CompilerPassInterface
{
    private const array SERVICE_PREFIXES = ['security.', 'lexik_jwt_authentication.'];

    private const string FIREWALL_DISPATCHER_PREFIX = 'security.event_dispatcher.';

    private const string SECURITY_NAMESPACE = 'App\\Security\\';

    /**
     * Les contrats d'un service qui décide de l'authentification ou de
     * l'autorisation.
     */
    private const array CONTRACTS = [
        UserProviderInterface::class,
        UserCheckerInterface::class,
        AuthenticatorInterface::class,
        AuthenticationSuccessHandlerInterface::class,
        AuthenticationFailureHandlerInterface::class,
        AuthenticationEntryPointInterface::class,
        AccessDeniedHandlerInterface::class,
        VoterInterface::class,
        TokenExtractorInterface::class,
    ];

    /**
     * Les espaces de noms des événements de sécurité, dont les écouteurs du
     * dispatcher principal sont comparés avec ceux de `kernel.*`.
     */
    private const array SECURITY_EVENT_NAMESPACES = [
        'Symfony\\Component\\Security\\',
        'Symfony\\Bundle\\SecurityBundle\\',
        'Lexik\\Bundle\\JWTAuthenticationBundle\\',
    ];

    private ?SecurityServicesSnapshot $snapshot = null;

    public function process(ContainerBuilder $container): void
    {
        $beforeRemoving = $container->getCompilerPassConfig()->getBeforeRemovingPasses();
        if ($this !== array_last($beforeRemoving)) {
            throw new LogicException('L\'instantané des services de sécurité doit être la dernière passe de TYPE_BEFORE_REMOVING.');
        }

        $listeners = [];
        $listenerServices = [];
        foreach ($this->listenerCalls($container->findDefinition('event_dispatcher')) as [$event, $service, $method, $priority]) {
            if (str_starts_with($event, 'kernel.') || $this->isUnder($event, self::SECURITY_EVENT_NAMESPACES)) {
                $listeners[] = \sprintf('%s %s::%s %d', $event, $service, $method, $priority);
                $listenerServices[$service] = true;
            }
        }
        foreach ($container->getDefinitions() as $id => $definition) {
            if (str_starts_with($id, self::FIREWALL_DISPATCHER_PREFIX)) {
                foreach ($this->listenerCalls($definition) as [, $service]) {
                    $listenerServices[$service] = true;
                }
            }
        }

        $services = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            if ($this->isInPerimeter($id, $definition, isset($listenerServices[$id]))) {
                $described = $container->resolveEnvPlaceholders(self::describe($definition), '%%env(%s)%%');
                \assert(\is_array($described));
                /** @var array<string, mixed> $described */
                $services[$id] = $described;
            }
        }
        ksort($services);

        $aliases = [];
        foreach ($container->getAliases() as $id => $alias) {
            if ($this->isUnder($id, [...self::SERVICE_PREFIXES, self::SECURITY_NAMESPACE, ...self::SECURITY_EVENT_NAMESPACES])) {
                $aliases[$id] = (string) $alias;
            }
        }
        ksort($aliases);

        $passConfig = $container->getCompilerPassConfig();
        $this->snapshot = new SecurityServicesSnapshot(
            $services,
            $aliases,
            $listeners,
            array_values(array_map(get_class(...), [...$passConfig->getRemovingPasses(), ...$passConfig->getAfterRemovingPasses()])),
        );
    }

    public function snapshot(): SecurityServicesSnapshot
    {
        return $this->snapshot ?? throw new LogicException('Le conteneur n\'a pas été compilé : aucun instantané des services de sécurité.');
    }

    /**
     * Une définition abstraite n'est jamais servie — un gabarit dont les
     * enfants sont déjà résolus, celui d'une autoconfiguration
     * (`.instanceof.…`) —, et RemoveAbstractDefinitionsPass la retire : elle
     * n'est pas comparée.
     */
    private function isInPerimeter(string $id, Definition $definition, bool $listens): bool
    {
        if ($definition->isAbstract()) {
            return false;
        }
        if ($listens || $this->isUnder($id, self::SERVICE_PREFIXES) || $definition->hasTag('security.voter')) {
            return true;
        }
        $class = $definition->getClass();

        return null !== $class && (str_starts_with($class, self::SECURITY_NAMESPACE) || $this->implementsAContract($class));
    }

    private function implementsAContract(string $class): bool
    {
        try {
            return array_any(self::CONTRACTS, static fn (string $contract): bool => is_a($class, $contract, true));
        } catch (Error) {
            // Une classe du vendor dont une dépendance facultative manque ne
            // se charge pas (le fournisseur d'expressions d'API Platform, sans
            // ExpressionLanguage installé) : elle ne sert alors aucun contrat.
            return false;
        }
    }

    /**
     * @param list<string> $prefixes
     */
    private function isUnder(string $name, array $prefixes): bool
    {
        return array_any($prefixes, static fn (string $prefix): bool => str_starts_with($name, $prefix));
    }

    /**
     * Les `addListener` d'un dispatcher, tels que RegisterListenersPass les
     * écrit : l'événement, le service écouteur, sa méthode, sa priorité.
     *
     * @return list<array{string, string, string, int}>
     */
    private function listenerCalls(Definition $dispatcher): array
    {
        $calls = [];
        foreach ($dispatcher->getMethodCalls() as $call) {
            $arguments = $call[1] ?? null;
            if ('addListener' !== ($call[0] ?? null) || !\is_array($arguments)) {
                continue;
            }
            [$event, $listener, $priority] = [$arguments[0] ?? null, $arguments[1] ?? null, $arguments[2] ?? 0];
            $callable = \is_array($listener) ? array_values($listener) : [$listener, '__invoke'];
            $target = $callable[0] ?? null;
            if ($target instanceof ServiceClosureArgument) {
                $target = $target->getValues()[0] ?? null;
            }
            $calls[] = [
                \is_string($event) ? $event : get_debug_type($event),
                $target instanceof Reference ? (string) $target : get_debug_type($target),
                \is_string($callable[1] ?? null) ? $callable[1] : '__invoke',
                \is_int($priority) ? $priority : 0,
            ];
        }

        return $calls;
    }

    /**
     * Une définition, et tout ce qu'elle porte, en tableaux et scalaires.
     * Une définition inlinée par avance (un argument `!service`) est décrite
     * en entier ; une référence, par l'identifiant qu'elle vise.
     */
    private static function describe(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Definition => [
                'class' => $value->getClass(),
                'factory' => self::describe($value->getFactory()),
                'arguments' => self::describe($value->getArguments()),
                'calls' => self::describe($value->getMethodCalls()),
                'properties' => self::describe($value->getProperties()),
                'configurator' => self::describe($value->getConfigurator()),
                'tags' => self::describe($value->getTags()),
                'decorated' => self::describe($value->getDecoratedService()),
                'lazy' => $value->isLazy(),
                'shared' => $value->isShared(),
                'abstract' => $value->isAbstract(),
                'synthetic' => $value->isSynthetic(),
            ],
            $value instanceof Reference => '@'.$value,
            $value instanceof Parameter => '%'.$value.'%',
            $value instanceof ArgumentInterface => [$value::class => self::describe($value->getValues())],
            \is_array($value) => array_map(self::describe(...), $value),
            \is_object($value) => get_debug_type($value),
            default => $value,
        };
    }
}
