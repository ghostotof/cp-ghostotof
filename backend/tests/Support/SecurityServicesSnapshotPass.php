<?php

declare(strict_types=1);

namespace App\Tests\Support;

use ApiPlatform\Metadata\ResourceAccessCheckerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\KeyLoader\KeyLoaderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\PayloadEnrichmentInterface;
use Lexik\Bundle\JWTAuthenticationBundle\TokenExtractor\TokenExtractorInterface;
use LogicException;
use Symfony\Component\DependencyInjection\Argument\AbstractArgument;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\ParameterCircularReferenceException;
use Symfony\Component\DependencyInjection\Exception\ParameterNotFoundException;
use Symfony\Component\DependencyInjection\Parameter;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\RateLimiter\RequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Strategy\AccessDecisionStrategyInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\AccessToken\AccessTokenExtractorInterface;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\Firewall\FirewallListenerInterface;
use Throwable;
use UnitEnum;

/**
 * Prend l'instantané des services de sécurité (issue #698), enregistrée en
 * dernière passe de `TYPE_BEFORE_REMOVING` par {@see EnvironmentServicesKernel}.
 * À ce point de la compilation, les définitions sont celles que le conteneur
 * servira — autoconfiguration appliquée, décorateurs résolus, `prepend()` des
 * bundles et passes d'optimisation faits, écouteurs enregistrés sur leurs
 * dispatchers (RegisterListenersPass, même phase, plus haut) —, et aucune
 * n'est encore inlinée ni retirée : un voter ou un fournisseur privé s'y lit
 * par son identifiant.
 *
 * Le périmètre (SERVICE_PREFIXES, SERVICE_IDS, CONTRACTS) :
 *  - les identifiants de la sécurité, de Lexik, des limiteurs, de CORS et de
 *    Messenger, cachés compris (`.security.request_matcher.<hash>`, la règle
 *    `access_control` elle-même), et l'horloge des expirations ;
 *  - toutes les classes de l'application (`App\`) : une garde de palier peut
 *    vivre hors de `App\Security\` (le lecteur des routes du MCP) ;
 *  - les classes qui implémentent un contrat de sécurité, quel que soit leur
 *    espace de noms ;
 *  - les services étiquetés `security.voter`, les écouteurs des dispatchers
 *    de firewall, et ceux du dispatcher principal sur un événement de
 *    sécurité (SECURITY_EVENT_PREFIXES) ;
 *  - les alias de ces services, ou nommés sous ces préfixes.
 * Hors périmètre, un service n'est vu que par la référence qu'un service du
 * périmètre lui fait. S'y ajoutent trois listes, comparées quelle que soit la
 * classe en cause : **tous** les écouteurs du dispatcher principal (un
 * événement se nomme aussi par une chaîne, ceux de Lexik notamment), les
 * écouteurs Doctrine, et les paramètres que le noyau lit hors de toute
 * définition (PARAMETERS, les défauts `env(…)`).
 *
 * Une définition est décrite en entier (describe()) : paramètres résolus,
 * tableaux compris — ResolveParameterPlaceHoldersPass ne résout pas un
 * paramètre tableau, la hiérarchie des rôles restait
 * `%security.role_hierarchy.roles%` (audit de #698, mesuré) —, cas d'un
 * enum, fichier inclus, mode d'une référence. Les valeurs `%env()%` sont
 * rendues sous leur nom : leur marque de compilation est tirée au hasard à
 * chaque construction.
 */
final class SecurityServicesSnapshotPass implements CompilerPassInterface
{
    /**
     * Les préfixes d'identifiant du périmètre ; chacun vaut aussi précédé
     * d'un point, la forme d'un service caché.
     */
    public const array SERVICE_PREFIXES = [
        'security.',
        'lexik_jwt_authentication.',
        'limiter.',
        'nelmio_cors.',
        'messenger.',
        'signing.messenger.',
        'App\\',
    ];

    /**
     * Les identifiants exacts du périmètre.
     */
    public const array SERVICE_IDS = ['clock'];

    /**
     * Les contrats d'un service qui décide de l'authentification, de
     * l'autorisation ou d'une borne de sécurité.
     */
    public const array CONTRACTS = [
        UserProviderInterface::class,
        UserCheckerInterface::class,
        AuthenticatorInterface::class,
        AuthenticationSuccessHandlerInterface::class,
        AuthenticationFailureHandlerInterface::class,
        AuthenticationEntryPointInterface::class,
        AccessDeniedHandlerInterface::class,
        AccessTokenHandlerInterface::class,
        AccessTokenExtractorInterface::class,
        FirewallListenerInterface::class,
        VoterInterface::class,
        AccessDecisionManagerInterface::class,
        AccessDecisionStrategyInterface::class,
        RoleHierarchyInterface::class,
        TokenStorageInterface::class,
        RequestMatcherInterface::class,
        PasswordHasherFactoryInterface::class,
        PasswordHasherInterface::class,
        RequestRateLimiterInterface::class,
        RateLimiterFactoryInterface::class,
        ResourceAccessCheckerInterface::class,
        TokenExtractorInterface::class,
        JWTTokenManagerInterface::class,
        JWTEncoderInterface::class,
        KeyLoaderInterface::class,
        PayloadEnrichmentInterface::class,
    ];

    /**
     * Les paramètres que le noyau applique à la requête au démarrage
     * (FrameworkBundle::boot()), hors de toute définition.
     */
    private const array PARAMETERS = [
        'kernel.trusted_proxies',
        'kernel.trusted_headers',
        'kernel.trusted_hosts',
        'kernel.http_method_override',
        'kernel.allowed_http_method_override',
        'kernel.trust_x_sendfile_type_header',
    ];

    private const array DOCTRINE_LISTENER_TAGS = ['doctrine.event_listener', 'doctrine.event_subscriber', 'doctrine.orm.entity_listener'];

    /**
     * Les événements dont les écouteurs entrent au périmètre — leur
     * définition comparée, en plus de leur place dans la liste —, par le
     * préfixe de leur nom : `kernel.*`, ceux nommés par une chaîne (Lexik,
     * `security.switch_user`…) ou par leur classe.
     */
    private const array SECURITY_EVENT_PREFIXES = [
        'kernel.',
        'security.',
        'lexik_jwt_authentication.',
        'Symfony\\Component\\Security\\',
        'Symfony\\Bundle\\SecurityBundle\\',
        'Lexik\\Bundle\\JWTAuthenticationBundle\\',
    ];

    /**
     * Les espaces de noms dont un alias nommé est comparé quelle que soit sa
     * cible : l'alias d'autowiring d'une interface de sécurité peut viser un
     * service hors périmètre.
     */
    private const array ALIAS_NAMESPACES = [
        'Symfony\\Component\\Security\\',
        'Symfony\\Bundle\\SecurityBundle\\',
        'Symfony\\Component\\PasswordHasher\\',
        'Symfony\\Component\\RateLimiter\\',
        'Lexik\\Bundle\\JWTAuthenticationBundle\\',
        'Psr\\Clock\\',
    ];

    private const string MAIN_DISPATCHER = 'event_dispatcher';

    private const string FIREWALL_DISPATCHER_PREFIX = 'security.event_dispatcher.';

    private ?SecurityServicesSnapshot $snapshot = null;

    public function process(ContainerBuilder $container): void
    {
        $beforeRemoving = $container->getCompilerPassConfig()->getBeforeRemovingPasses();
        if ($this !== array_last($beforeRemoving)) {
            throw new LogicException('L\'instantané des services de sécurité doit être la dernière passe de TYPE_BEFORE_REMOVING.');
        }

        $mainListeners = $this->listenerCalls($container->findDefinition(self::MAIN_DISPATCHER));
        $securityListeners = array_filter($mainListeners, fn (array $call): bool => $this->isSecurityEvent($call[0]));
        $services = $this->services($container, [...$securityListeners, ...$this->firewallListenerCalls($container)]);
        $passConfig = $container->getCompilerPassConfig();

        $this->snapshot = new SecurityServicesSnapshot(
            $services,
            $this->aliases($container, $services),
            array_map(static fn (array $call): string => \sprintf('%s %s::%s %d', ...$call), $mainListeners),
            $this->doctrineListeners($container),
            $this->parameters($container),
            array_values(array_map(get_class(...), [...$passConfig->getRemovingPasses(), ...$passConfig->getAfterRemovingPasses()])),
        );
    }

    public function snapshot(): SecurityServicesSnapshot
    {
        return $this->snapshot ?? throw new LogicException('Le conteneur n\'a pas été compilé : aucun instantané des services de sécurité.');
    }

    /**
     * @param list<array{string, string, string, int}> $listenerCalls les écouteurs, dont le service entre au périmètre
     *
     * @return array<string, array<string, mixed>>
     */
    private function services(ContainerBuilder $container, array $listenerCalls): array
    {
        $listeners = array_fill_keys(array_column($listenerCalls, 1), true);
        $services = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            if ($this->isInPerimeter($container, $id, $definition, isset($listeners[$id]))) {
                $described = $container->resolveEnvPlaceholders($this->describe($container, $definition), '%%env(%s)%%');
                \assert(\is_array($described));
                /** @var array<string, mixed> $described */
                $services[$id] = $described;
            }
        }
        $services[self::MAIN_DISPATCHER] = $this->describedDispatcher($container);
        ksort($services);

        return $services;
    }

    /**
     * Le dispatcher principal lui-même — sa classe, ses arguments, tout appel
     * qui n'est pas un `addListener` (un `addSubscriber` qu'ajouterait une
     * passe) —, ses `addListener` étant comparés à part, comptés, dans la
     * liste des écouteurs.
     *
     * @return array<string, mixed>
     */
    private function describedDispatcher(ContainerBuilder $container): array
    {
        $dispatcher = clone $container->findDefinition(self::MAIN_DISPATCHER);
        $dispatcher->setMethodCalls(array_values(array_filter($dispatcher->getMethodCalls(), static fn (mixed $call): bool => !\is_array($call) || 'addListener' !== ($call[0] ?? null))));
        $described = $container->resolveEnvPlaceholders($this->describe($container, $dispatcher), '%%env(%s)%%');
        \assert(\is_array($described));
        /** @var array<string, mixed> $described */
        return $described;
    }

    /**
     * @param array<string, mixed> $services
     *
     * @return array<string, string>
     */
    private function aliases(ContainerBuilder $container, array $services): array
    {
        $aliases = [];
        foreach ($container->getAliases() as $id => $alias) {
            if ($this->isPerimeterId($id) || isset($services[(string) $alias]) || array_any(self::ALIAS_NAMESPACES, static fn (string $namespace): bool => str_starts_with($id, $namespace))) {
                $aliases[$id] = $alias.($alias->isPublic() ? ' (public)' : '').($alias->isDeprecated() ? ' (déprécié)' : '');
            }
        }
        ksort($aliases);

        return $aliases;
    }

    /**
     * @return list<array{string, string, string, int}>
     */
    private function firewallListenerCalls(ContainerBuilder $container): array
    {
        $calls = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            if (str_starts_with($id, self::FIREWALL_DISPATCHER_PREFIX)) {
                $calls = [...$calls, ...$this->listenerCalls($definition)];
            }
        }

        return $calls;
    }

    /**
     * @return list<string> « étiquette service attributs », triés
     */
    private function doctrineListeners(ContainerBuilder $container): array
    {
        $listeners = [];
        foreach (self::DOCTRINE_LISTENER_TAGS as $tag) {
            foreach ($container->findTaggedServiceIds($tag) as $id => $attributes) {
                $listeners[] = \sprintf('%s %s %s', $tag, $id, json_encode($attributes, \JSON_THROW_ON_ERROR));
            }
        }
        sort($listeners);

        return $listeners;
    }

    /**
     * @return array<string, mixed>
     */
    private function parameters(ContainerBuilder $container): array
    {
        $parameters = [];
        foreach ($container->getParameterBag()->all() as $name => $value) {
            if (\in_array($name, self::PARAMETERS, true) || str_starts_with((string) $name, 'env(')) {
                $parameters[(string) $name] = $container->resolveEnvPlaceholders($value, '%%env(%s)%%');
            }
        }
        ksort($parameters);

        return $parameters;
    }

    /**
     * Une définition abstraite n'est jamais servie — un gabarit dont les
     * enfants sont déjà résolus, celui d'une autoconfiguration
     * (`.instanceof.…`) —, et RemoveAbstractDefinitionsPass la retire : elle
     * n'est pas comparée.
     */
    private function isInPerimeter(ContainerBuilder $container, string $id, Definition $definition, bool $listens): bool
    {
        if ($definition->isAbstract()) {
            return false;
        }
        if ($listens || $this->isPerimeterId($id) || $definition->hasTag('security.voter')) {
            return true;
        }
        $class = $definition->getClass();

        return null !== $class && (str_starts_with($class, 'App\\') || $this->implementsAContract($container, $class));
    }

    private function isSecurityEvent(string $event): bool
    {
        return array_any(self::SECURITY_EVENT_PREFIXES, static fn (string $prefix): bool => str_starts_with($event, $prefix));
    }

    private function isPerimeterId(string $id): bool
    {
        return \in_array($id, self::SERVICE_IDS, true)
            || array_any(self::SERVICE_PREFIXES, static fn (string $prefix): bool => str_starts_with($id, $prefix) || str_starts_with($id, '.'.$prefix));
    }

    /**
     * Par la réflexion du ContainerBuilder, qui rend `null` pour une classe
     * qui ne se charge pas — une classe du vendor dont une dépendance
     * facultative manque, le fournisseur d'expressions d'API Platform sans
     * ExpressionLanguage —, plutôt que l'Error de l'autoload.
     */
    private function implementsAContract(ContainerBuilder $container, string $class): bool
    {
        $reflection = $container->getReflectionClass($class, false);

        return null !== $reflection && array_any(self::CONTRACTS, static fn (string $contract): bool => $reflection->implementsInterface($contract));
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
     * en entier ; une référence, par l'identifiant qu'elle vise et son mode
     * quand ce n'est pas celui par défaut (`@?`, `@!`) ; un paramètre, par sa
     * valeur.
     */
    private function describe(ContainerBuilder $container, mixed $value): mixed
    {
        return match (true) {
            $value instanceof Definition => [
                'class' => $value->getClass(),
                'file' => $value->getFile(),
                'factory' => $this->describe($container, $value->getFactory()),
                'arguments' => $this->describe($container, $value->getArguments()),
                'calls' => $this->describe($container, $value->getMethodCalls()),
                'properties' => $this->describe($container, $value->getProperties()),
                'configurator' => $this->describe($container, $value->getConfigurator()),
                'tags' => $this->describe($container, $value->getTags()),
                'decorated' => $this->describe($container, $value->getDecoratedService()),
                'errors' => $value->getErrors(),
                'public' => $value->isPublic(),
                'deprecated' => $value->isDeprecated(),
                'lazy' => $value->isLazy(),
                'shared' => $value->isShared(),
                'synthetic' => $value->isSynthetic(),
            ],
            $value instanceof Reference => '@'.$value.(ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE === $value->getInvalidBehavior() ? '' : '#'.$value->getInvalidBehavior()),
            $value instanceof Parameter => $this->describe($container, '%'.$value.'%'),
            $value instanceof ArgumentInterface => [$value::class => $this->describe($container, $value->getValues())],
            $value instanceof AbstractArgument => 'abstract:'.$value->getText(),
            $value instanceof UnitEnum => $value::class.'::'.$value->name,
            \is_string($value) => $this->describeString($container, $value),
            \is_array($value) => array_map(fn (mixed $item): mixed => $this->describe($container, $item), $value),
            \is_object($value) => $this->describeObject($value),
            default => $value,
        };
    }

    /**
     * Une chaîne qui référence un paramètre, par la valeur de ce paramètre,
     * tableau compris ; un paramètre introuvable reste écrit tel quel (aucun
     * à ce point de la compilation, ou elle aurait échoué).
     */
    private function describeString(ContainerBuilder $container, string $value): mixed
    {
        try {
            $resolved = $container->getParameterBag()->resolveValue($value);
        } catch (ParameterNotFoundException|ParameterCircularReferenceException) {
            return $value;
        }

        return $resolved === $value ? $value : $this->describe($container, $resolved);
    }

    /**
     * Un objet passé en valeur, par sa classe et son état sérialisé ; un objet
     * qui refuse la sérialisation, par sa classe seule.
     */
    private function describeObject(object $value): string
    {
        try {
            return get_debug_type($value).':'.serialize($value);
        } catch (Throwable) {
            return get_debug_type($value);
        }
    }
}
