<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use App\Security\Authentication\Infrastructure\Http\CsrfCookieRequestSubscriber;
use App\Security\Authentication\Infrastructure\Security\LoginThrottlingRefusalListener;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Infrastructure\Doctrine\CpgUserRepository;
use App\Security\User\Infrastructure\Security\BaseAccessUserProvider;
use App\Tests\Security\Authentication\BearerCookieSecurePerEnvironmentTest;
use App\Tests\Security\Authentication\CpgUserPasswordHasherTest;
use App\Tests\Security\Authentication\Infrastructure\Http\AuthCookieFactoryTest;
use App\Tests\Support\EnvironmentConfigKernel;
use App\Tests\Support\EnvironmentServicesKernel;
use App\Tests\Support\SecurityServicesSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * SecurityConfigEnvironmentParityTest compare d'un environnement à l'autre la
 * configuration **déclarée** de `security` et de `lexik_jwt_authentication`,
 * pas les **services** qu'elle désigne (issue #698, relevé par la relecture de
 * #659). Un service redéfini pour un seul environnement lui échappait, la
 * section restant identique : un fournisseur, un voter autoconfiguré déclaré
 * pour `prod` seul qui voterait ACCESS_GRANTED sur `ROLE_TRUSTED`, un
 * user_checker, un handler, un extracteur, le fournisseur du cookie `BEARER`
 * (commentaire de #714). Les gardes de l'exposition lisent le conteneur de
 * `test` : elles resteraient vertes, la production servirait `/api/cv` au
 * palier de base. Ce test compare donc les **définitions** des services de
 * sécurité de chaque environnement de {@see \App\Kernel::getAllowedEnvs()} à
 * celles de `test`.
 *
 * Lecture compilée, pas déclarée : la définition qui compte est celle que
 * les passes ont faite — autoconfiguration (un voter étiqueté par son seul
 * contrat), décorateurs résolus, `prepend()` des bundles, passes du projet
 * et des bundles, paramètres résolus. Mais pas le conteneur compilé jusqu'au
 * bout, comme {@see \App\Tests\Support\EnvironmentRoutesKernel} pour les routes : les passes de
 * retrait inlinent ou suppriment les services privés, voters et fournisseurs
 * compris, qui ne s'y lisent plus par leur identifiant. L'instantané est pris
 * entre les deux ({@see \App\Tests\Support\SecurityServicesSnapshotPass}, dernière passe de
 * `TYPE_BEFORE_REMOVING`) ; il couvre du même coup, pour ces services, les
 * deux autres limites écrites avec #659 (une passe, un `prepend()`), y
 * compris pour la carte de sécurité elle-même (`security.access_map`, les
 * contextes de firewall). Mesuré sur l'application : 0,3 à 0,7 s par
 * environnement.
 *
 * Le périmètre ({@see \App\Tests\Support\SecurityServicesSnapshotPass}) : les identifiants
 * `security.*` et `lexik_jwt_authentication.*`, les classes de
 * `App\Security\`, celles qui implémentent un contrat de sécurité, les
 * services étiquetés `security.voter`, les écouteurs des dispatchers de
 * firewall, et ceux du dispatcher principal sur `kernel.*` et sur les
 * événements de sécurité — leur liste comprise, pour qu'un écouteur ajouté
 * en `prod` seul se voie quelle que soit sa classe. Pas la fermeture
 * transitive : mesurée, elle atteint 723 services, journaux, caches et
 * routeur compris, qui diffèrent légitimement d'un environnement à l'autre ;
 * un service hors périmètre est comparé par sa référence, pas par sa
 * définition.
 *
 * Non vu, limites acceptées (aucun cas aujourd'hui) :
 *  - une passe enregistrée après l'instantané (`TYPE_REMOVE`,
 *    `TYPE_AFTER_REMOVING`) — testThePassesAfterTheSnapshotAreTheSameEverywhere
 *    exige qu'aucune ne vienne du projet et que toutes soient les mêmes
 *    partout ;
 *  - du code qui décide sur l'environnement à l'exécution, hors du conteneur ;
 *  - une valeur `%env()%`, comparée par son nom, pas par sa valeur ;
 *  - une règle qui dépendrait de `%kernel.debug%` : chaque environnement est
 *    lu hors débogage, comme la production, à la manière des autres gardes
 *    de parité ;
 *  - un service de sécurité hors périmètre (une classe d'un autre contexte
 *    qui n'implémente aucun contrat de sécurité et n'écoute aucun événement
 *    comparé) — testThePerimeterHoldsTheServicesTheIssueNames épingle ceux
 *    que l'issue nomme.
 */
final class SecurityServicesEnvironmentParityTest extends TestCase
{
    /**
     * Service => le chemin, dans sa définition, qui peut différer d'un
     * environnement à l'autre, la valeur qu'il doit y avoir **dans chaque
     * environnement**, pourquoi, et le test qui énonce la règle. Le chemin est
     * retiré de la comparaison avec `test`, rien de plus large, mais sa valeur
     * est vérifiée environnement par environnement : retirer le chemin sans
     * figer sa valeur laissait passer une redéfinition propre à `prod` du
     * cookie `BEARER` sans `Secure` (mesuré avec la fixture), le trou de #703
     * sous une autre forme. Un environnement ajouté au noyau sans sa valeur
     * ici fait rougir le test : à décider, pas à hériter. Le test est écrit en
     * `::class` pour qu'un renommage fasse rougir PHPStan.
     *
     * @var array<string, array{list<int|string>, array<string, mixed>, string, class-string<TestCase>}>
     */
    private const array ENVIRONMENT_SPECIFIC = [
        'security.password_hasher_factory' => [
            ['arguments', 0, CpgUser::class, 'arguments', 2],
            ['dev' => 13, 'prod' => 13, 'test' => 4],
            'Coût bcrypt abaissé à 4 sous when@test pour la vitesse de la suite (issue #590) ; même exemption que security.password_hashers dans la configuration déclarée.',
            CpgUserPasswordHasherTest::class,
        ],
        'lexik_jwt_authentication.cookie_provider.BEARER' => [
            ['arguments', 5],
            ['dev' => false, 'prod' => true, 'test' => true],
            'Secure partout sauf en dev, la règle d\'AuthCookieFactory (issue #714) ; même exemption que lexik_jwt_authentication.set_cookies.BEARER.secure dans la configuration déclarée.',
            BearerCookieSecurePerEnvironmentTest::class,
        ],
        AuthCookieFactory::class => [
            ['arguments', 0],
            ['dev' => 'dev', 'prod' => 'prod', 'test' => 'test'],
            'La fabrique reçoit l\'environnement (%kernel.environment%) pour décider de Secure, partout sauf en dev (issue #714) ; c\'est sa règle elle-même que fige son test.',
            AuthCookieFactoryTest::class,
        ],
    ];

    private const string PROD_ONLY_SERVICES = __DIR__.'/Fixtures/EnvironmentServices/prod-only-services.yaml';

    /**
     * Un instantané par environnement et fichier ajouté, gardé d'un test à
     * l'autre : chaque compilation charge tout src/.
     *
     * @var array<string, SecurityServicesSnapshot>
     */
    private static array $snapshots = [];

    public function testEveryEnvironmentDefinesTheSameSecurityServicesAsTest(): void
    {
        self::assertSame(
            [],
            $this->differences(null),
            'Service de sécurité défini autrement que dans le conteneur de test : les gardes de l\'exposition ne le voient pas. Le rendre commun à tous les environnements.',
        );
    }

    /**
     * La comparaison elle-même, sur l'application compilée avec un fichier de
     * plus qui surcharge, en `prod` seul, un service de chaque forme que
     * l'issue redoute (fixture prod-only-services.yaml) : chacune ressort, et
     * rien en `dev`.
     */
    public function testTheComparisonSeesEveryFormOfAProdOnlyOverride(): void
    {
        self::assertSame([
            // Le cookie BEARER redéfini sans Secure : chemin exempté de la
            // comparaison avec test, mais pas de sa valeur attendue.
            'prod ≠ attendu : service lexik_jwt_authentication.cookie_provider.BEARER [arguments][5] vaut false au lieu de true',
            // Le décorateur du fournisseur : le fournisseur décoré devient
            // `.inner`, et l'alias de SecurityBundle vise le décorateur.
            'prod ≠ test : alias App\Security\User\Infrastructure\Security\BaseAccessUserProvider : absent dans test, App\Tests\Security\Fixtures\EnvironmentServices\DecoratingUserProvider dans prod',
            'prod ≠ test : alias security.user.provider.concrete.base_access_users : App\Security\User\Infrastructure\Security\BaseAccessUserProvider dans test, App\Tests\Security\Fixtures\EnvironmentServices\DecoratingUserProvider dans prod',
            'prod ≠ test : service App\Security\User\Infrastructure\Security\BaseAccessUserProvider (seulement dans test)',
            // L'écouteur kernel.response, par sa définition et dans la liste.
            'prod ≠ test : service App\Tests\Security\Fixtures\EnvironmentServices\CookieRewritingListener (seulement dans prod)',
            'prod ≠ test : service App\Tests\Security\Fixtures\EnvironmentServices\DecoratingUserProvider (seulement dans prod)',
            'prod ≠ test : service App\Tests\Security\Fixtures\EnvironmentServices\DecoratingUserProvider.inner (seulement dans prod)',
            // Le voter, étiqueté par l'autoconfiguration seule.
            'prod ≠ test : service App\Tests\Security\Fixtures\EnvironmentServices\GrantsTrustedVoter (seulement dans prod)',
            'prod ≠ test : service lexik_jwt_authentication.generate_token_command [arguments][1][Symfony\Component\DependencyInjection\Argument\IteratorArgument][1]',
            'prod ≠ test : service security.access.decision_manager [arguments][0][Symfony\Component\DependencyInjection\Argument\IteratorArgument]',
            'prod ≠ test : service security.context_listener [arguments][1][Symfony\Component\DependencyInjection\Argument\IteratorArgument][1]',
            'prod ≠ test : service security.user.provider.concrete.app_users [arguments][0][Symfony\Component\DependencyInjection\Argument\IteratorArgument][0]',
            'prod ≠ test : service security.user_providers [arguments][0][Symfony\Component\DependencyInjection\Argument\IteratorArgument][1]',
            'prod ≠ test : écouteur kernel.response App\Tests\Security\Fixtures\EnvironmentServices\CookieRewritingListener::onKernelResponse 0 (seulement dans prod)',
        ], $this->differences(self::PROD_ONLY_SERVICES));
    }

    /**
     * Une exemption qui ne sert plus se retire : sinon elle couvrirait demain
     * une différence que personne n'a justifiée. Ses valeurs attendues
     * doivent donc différer d'un environnement à l'autre, et en nommer
     * chacun — la valeur réelle, elle, est vérifiée par
     * {@see self::differences()}, dans la fixture comme dans l'application.
     */
    public function testEveryEnvironmentSpecificPathIsStillNeeded(): void
    {
        $environments = EnvironmentConfigKernel::allowedEnvironments();
        sort($environments);
        foreach (self::ENVIRONMENT_SPECIFIC as $service => [$path, $expected, $reason, $pinnedBy]) {
            $named = array_keys($expected);
            sort($named);
            self::assertSame($environments, $named, \sprintf('%s %s : la valeur attendue de chaque environnement que le noyau admet doit être écrite, ni plus ni moins.', $service, $this->describePath($path)));
            self::assertGreaterThan(1, \count(array_unique(array_map(serialize(...), $expected))), \sprintf(
                '%s %s attend la même valeur dans tous les environnements : son exemption ne sert plus. Elle disait : « %s » Ne la retirer qu\'après avoir vérifié que cette raison ne tient plus (%s énonce la règle).',
                $service,
                $this->describePath($path),
                $reason,
                $pinnedBy,
            ));
        }
    }

    /**
     * L'instantané ne voit pas ce que font les passes qui le suivent. Aucune
     * ne doit donc venir du projet, et toutes doivent être les mêmes, dans le
     * même ordre, dans chaque environnement — ce sont alors celles de
     * Symfony, qui retirent et inlinent sans distinguer l'environnement.
     */
    public function testThePassesAfterTheSnapshotAreTheSameEverywhere(): void
    {
        $reference = $this->snapshot('test', null)->laterPasses;
        self::assertNotSame([], $reference, 'Aucune passe après l\'instantané : il n\'est plus pris avant les passes de retrait.');
        self::assertSame(
            [],
            array_values(array_filter($reference, static fn (string $pass): bool => str_starts_with($pass, 'App\\'))),
            'Passe du projet enregistrée après l\'instantané des services de sécurité : elle lui échappe. L\'enregistrer avant TYPE_REMOVE.',
        );
        foreach (EnvironmentConfigKernel::comparedEnvironments() as $environment) {
            self::assertSame($reference, $this->snapshot($environment, null)->laterPasses, \sprintf('Les passes qui suivent l\'instantané diffèrent entre %s et test.', $environment));
        }
    }

    /**
     * Le périmètre ne doit pas fondre en silence — une classe qui ne se
     * charge plus, un contrat renommé : les services que l'issue nomme y
     * sont, et les écouteurs comparés comptent la garde CSRF.
     */
    public function testThePerimeterHoldsTheServicesTheIssueNames(): void
    {
        $snapshot = $this->snapshot('test', null);
        foreach ([
            BaseAccessUserProvider::class,
            CpgUserRepository::class,
            LoginThrottlingRefusalListener::class,
            'security.access.role_hierarchy_voter',
            'security.access.decision_manager',
            'security.access_map',
            'security.firewall.map',
            'lexik_jwt_authentication.cookie_provider.BEARER',
        ] as $service) {
            self::assertArrayHasKey($service, $snapshot->services, \sprintf('%s n\'est plus dans le périmètre comparé.', $service));
        }
        self::assertNotSame(
            [],
            array_filter($snapshot->listeners, static fn (string $listener): bool => str_starts_with($listener, 'kernel.request '.CsrfCookieRequestSubscriber::class.'::')),
            'La garde CSRF n\'est plus parmi les écouteurs comparés.',
        );
    }

    /**
     * @return list<string> « env ≠ test : … », triées
     */
    private function differences(?string $extraConfigFile): array
    {
        $found = [];
        $reference = $this->snapshot('test', $extraConfigFile);
        foreach (EnvironmentConfigKernel::comparedEnvironments() as $environment) {
            $other = $this->snapshot($environment, $extraConfigFile);
            foreach ($this->serviceDifferences($reference, $other, $environment) as $difference) {
                $found[] = \sprintf('%s ≠ test : service %s', $environment, $difference);
            }
            foreach ($this->mapDifferences($reference->aliases, $other->aliases, $environment) as $difference) {
                $found[] = \sprintf('%s ≠ test : alias %s', $environment, $difference);
            }
            foreach ($this->listenerDifferences($reference->listeners, $other->listeners, $environment) as $difference) {
                $found[] = \sprintf('%s ≠ test : écouteur %s', $environment, $difference);
            }
        }
        foreach (EnvironmentConfigKernel::allowedEnvironments() as $environment) {
            foreach ($this->unexpectedEnvironmentSpecificValues($this->snapshot($environment, $extraConfigFile), $environment) as $difference) {
                $found[] = \sprintf('%s ≠ attendu : service %s', $environment, $difference);
            }
        }
        sort($found);

        return $found;
    }

    /**
     * @return list<string> « id », suivi du premier chemin qui diffère ou de l'environnement qui seul le définit
     */
    private function serviceDifferences(SecurityServicesSnapshot $reference, SecurityServicesSnapshot $other, string $environment): array
    {
        $found = [];
        foreach (array_unique([...array_keys($reference->services), ...array_keys($other->services)]) as $service) {
            if (!\array_key_exists($service, $other->services)) {
                $found[] = \sprintf('%s (seulement dans test)', $service);

                continue;
            }
            if (!\array_key_exists($service, $reference->services)) {
                $found[] = \sprintf('%s (seulement dans %s)', $service, $environment);

                continue;
            }
            $path = self::firstDifference($this->compared($service, $reference->services[$service]), $this->compared($service, $other->services[$service]), []);
            if (null !== $path) {
                $found[] = \sprintf('%s %s', $service, $this->describePath($path));
            }
        }

        return $found;
    }

    /**
     * Les chemins d'ENVIRONMENT_SPECIFIC dont la valeur n'est pas celle que
     * l'environnement doit avoir — `test` compris : le retirer de la
     * comparaison ne dit rien de ce qu'il vaut.
     *
     * @return list<string>
     */
    private function unexpectedEnvironmentSpecificValues(SecurityServicesSnapshot $snapshot, string $environment): array
    {
        $found = [];
        foreach (self::ENVIRONMENT_SPECIFIC as $service => [$path, $expected]) {
            $actual = $this->at($snapshot->services[$service] ?? null, $path);
            if (!\array_key_exists($environment, $expected) || $actual !== $expected[$environment]) {
                $found[] = \sprintf('%s %s vaut %s au lieu de %s', $service, $this->describePath($path), var_export($actual, true), \array_key_exists($environment, $expected) ? var_export($expected[$environment], true) : '(non écrite)');
            }
        }

        return $found;
    }

    /**
     * @param array<string, string> $reference
     * @param array<string, string> $other
     *
     * @return list<string>
     */
    private function mapDifferences(array $reference, array $other, string $environment): array
    {
        $found = [];
        foreach (array_unique([...array_keys($reference), ...array_keys($other)]) as $key) {
            if (($reference[$key] ?? null) !== ($other[$key] ?? null)) {
                $found[] = \sprintf('%s : %s dans test, %s dans %s', $key, $reference[$key] ?? 'absent', $other[$key] ?? 'absent', $environment);
            }
        }

        return $found;
    }

    /**
     * Les écouteurs d'un seul côté, puis l'ordre des communs : à priorité
     * égale, le dispatcher les appelle dans l'ordre d'enregistrement.
     *
     * @param list<string> $reference
     * @param list<string> $other
     *
     * @return list<string>
     */
    private function listenerDifferences(array $reference, array $other, string $environment): array
    {
        $found = [];
        foreach (array_diff($reference, $other) as $listener) {
            $found[] = \sprintf('%s (seulement dans test)', $listener);
        }
        foreach (array_diff($other, $reference) as $listener) {
            $found[] = \sprintf('%s (seulement dans %s)', $listener, $environment);
        }
        if (array_values(array_intersect($reference, $other)) !== array_values(array_intersect($other, $reference))) {
            $found[] = '(ordre)';
        }

        return $found;
    }

    /**
     * La définition d'un service, hors du chemin qu'ENVIRONMENT_SPECIFIC
     * exempte pour lui.
     */
    private function compared(string $service, mixed $definition): mixed
    {
        if (!isset(self::ENVIRONMENT_SPECIFIC[$service]) || !\is_array($definition)) {
            return $definition;
        }

        return self::without($definition, self::ENVIRONMENT_SPECIFIC[$service][0]);
    }

    /**
     * Le premier chemin, dans l'ordre des clés, où deux descriptions
     * divergent — l'ordre des clés compris (des arguments nommés, une table
     * d'écouteurs) —, ou `null` si elles sont identiques.
     *
     * @param list<int|string> $path
     *
     * @return list<int|string>|null
     */
    private static function firstDifference(mixed $reference, mixed $other, array $path): ?array
    {
        if ($reference === $other) {
            return null;
        }
        if (!\is_array($reference) || !\is_array($other) || array_keys($reference) !== array_keys($other)) {
            return $path;
        }
        foreach ($reference as $key => $value) {
            $difference = self::firstDifference($value, $other[$key], [...$path, $key]);
            if (null !== $difference) {
                return $difference;
            }
        }

        return $path;
    }

    /**
     * @param list<int|string> $path
     */
    private function describePath(array $path): string
    {
        return implode('', array_map(static fn (int|string $key): string => \sprintf('[%s]', $key), $path));
    }

    /**
     * @param list<int|string> $path
     */
    private function at(mixed $value, array $path): mixed
    {
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * La description sans la valeur au bout du chemin — et sans rien d'autre.
     *
     * @param array<array-key, mixed> $description
     * @param list<int|string>        $path
     *
     * @return array<array-key, mixed>
     */
    private static function without(array $description, array $path): array
    {
        $key = array_shift($path);
        if (null === $key || !\array_key_exists($key, $description)) {
            return $description;
        }
        if ([] === $path) {
            unset($description[$key]);

            return $description;
        }
        if (\is_array($description[$key])) {
            $description[$key] = self::without($description[$key], $path);
        }

        return $description;
    }

    private function snapshot(string $environment, ?string $extraConfigFile): SecurityServicesSnapshot
    {
        return self::$snapshots[$environment.'|'.$extraConfigFile] ??= new EnvironmentServicesKernel($environment, $extraConfigFile)->securityServices();
    }
}
