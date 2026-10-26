<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Ai\Assistant\Infrastructure\Corpus\CorpusRenderer;
use App\Portfolio\Watch\Infrastructure\Http\EndOfLifeDateClient;
use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use App\Security\Authentication\Infrastructure\Http\CsrfCookieRequestSubscriber;
use App\Security\Authentication\Infrastructure\Security\LoginThrottlingRefusalListener;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Infrastructure\Doctrine\CpgUserRepository;
use App\Security\User\Infrastructure\Security\BaseAccessUserProvider;
use App\Tests\Ai\Assistant\Infrastructure\Corpus\CorpusSourcesTest;
use App\Tests\Portfolio\Watch\Presentation\ApiResource\BackofficeWatchedProductResourceTest;
use App\Tests\Security\Authentication\BearerCookieSecurePerEnvironmentTest;
use App\Tests\Security\Authentication\CpgUserPasswordHasherTest;
use App\Tests\Security\Authentication\Infrastructure\Http\AuthCookieFactoryTest;
use App\Tests\Security\Fixtures\EnvironmentServices\AfterRemovingProbePass;
use App\Tests\Shared\Infrastructure\Messenger\AmqpPublisherConfirmsTest;
use App\Tests\Support\EnvironmentConfigKernel;
use App\Tests\Support\EnvironmentServicesKernel;
use App\Tests\Support\EnvironmentSpecificPath;
use App\Tests\Support\KeyPaths;
use App\Tests\Support\SecurityServicesSnapshot;
use App\Tests\Support\SecurityServicesSnapshotPass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\Compiler\TestServiceContainerRealRefPass;
use Symfony\Component\Cache\DependencyInjection\CachePoolClearerPass;
use Symfony\Component\Cache\DependencyInjection\CachePoolPrunerPass;
use Symfony\Component\DependencyInjection\Compiler\AliasDeprecatedPublicServicesPass;
use Symfony\Component\DependencyInjection\Compiler\AnalyzeServiceReferencesPass;
use Symfony\Component\DependencyInjection\Compiler\CheckExceptionOnInvalidReferenceBehaviorPass;
use Symfony\Component\DependencyInjection\Compiler\CheckFactoryBuilderCircularReferencePass;
use Symfony\Component\DependencyInjection\Compiler\DefinitionErrorExceptionPass;
use Symfony\Component\DependencyInjection\Compiler\InlineServiceDefinitionsPass;
use Symfony\Component\DependencyInjection\Compiler\RegisterReverseContainerPass;
use Symfony\Component\DependencyInjection\Compiler\RemoveAbstractDefinitionsPass;
use Symfony\Component\DependencyInjection\Compiler\RemoveBuildParametersPass;
use Symfony\Component\DependencyInjection\Compiler\RemovePrivateAliasesPass;
use Symfony\Component\DependencyInjection\Compiler\RemoveUnusedDefinitionsPass;
use Symfony\Component\DependencyInjection\Compiler\ReplaceAliasByActualDefinitionPass;
use Symfony\Component\DependencyInjection\Compiler\ResolveHotPathPass;
use Symfony\Component\DependencyInjection\Compiler\ResolveNoPreloadPass;

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
 * palier de base. Ce test compare donc, dans chaque environnement de
 * {@see \App\Kernel::getAllowedEnvs()}, les **définitions** des services de
 * sécurité à celles de `test`.
 *
 * Lecture compilée, pas déclarée : la définition qui compte est celle que
 * les passes ont faite — autoconfiguration (un voter étiqueté par son seul
 * contrat), décorateurs résolus, `prepend()` des bundles, passes du projet
 * et des bundles. Mais pas le conteneur compilé jusqu'au bout, comme
 * {@see \App\Tests\Support\EnvironmentRoutesKernel} pour les routes : les
 * passes de retrait inlinent ou suppriment les services privés, voters et
 * fournisseurs compris, qui ne s'y lisent plus par leur identifiant.
 * L'instantané est pris entre les deux ({@see SecurityServicesSnapshotPass},
 * dernière passe de `TYPE_BEFORE_REMOVING`), et les paramètres y sont résolus
 * par la garde elle-même, tableaux compris (la compilation laisse un
 * paramètre tableau sous son nom). Il couvre du même coup les deux autres
 * limites écrites avec #659, une passe et un `prepend()`, y compris pour la
 * carte de sécurité elle-même : la hiérarchie des rôles, la règle
 * `access_control` (`.security.request_matcher.<hash>`), les contextes de
 * firewall. Mesuré sur l'application : 0,3 à 0,7 s par environnement.
 *
 * Le périmètre est celui de {@see SecurityServicesSnapshotPass} — la
 * sécurité, Lexik, les limiteurs, CORS, Messenger, l'horloge, toutes les
 * classes `App\`, les contrats de sécurité —, et trois listes comparées
 * quelle que soit la classe en cause : tous les écouteurs du dispatcher
 * principal, les écouteurs Doctrine, les paramètres que le noyau lit hors de
 * toute définition (proxys de confiance, surcharge de méthode, défauts
 * `env()`). Pas la fermeture transitive : mesurée, elle atteint 723
 * services, journaux, caches et routeur compris, qui diffèrent légitimement ;
 * hors périmètre, un service est comparé par sa référence.
 *
 * La configuration est chargée sous l'environnement lu
 * ({@see \App\Tests\Support\ProcessEnvironment}) : un fichier PHP qui lirait
 * `$_SERVER['APP_ENV']` prend la même branche que dans l'image de production.
 *
 * Non vu, limites acceptées (aucun cas aujourd'hui) :
 *  - du code qui décide sur l'environnement à l'exécution, hors du conteneur ;
 *  - une variable d'environnement de déploiement, comparée par son nom (son
 *    défaut `env(…)`, lui, est comparé) ;
 *  - une règle qui dépendrait de `%kernel.debug%` : chaque environnement est
 *    lu hors débogage, comme la préproduction et la production ;
 *  - un service hors périmètre redéfini sous son propre identifiant, qu'une
 *    référence seule désigne — testThePerimeterHoldsTheServicesTheIssueNames
 *    épingle ceux que l'issue et ses relectures nomment ;
 *  - les handlers Monolog, qui diffèrent à bon droit (un handler de test en
 *    `test`) : ceux du journal de sécurité en production sont figés à part
 *    ({@see \App\Tests\Security\Authentication\SecurityAuditLogChannelTest}).
 * Une passe enregistrée après l'instantané, elle, est refusée
 * (testThePassesAfterTheSnapshotAreTheExpectedOnes).
 */
final class SecurityServicesEnvironmentParityTest extends TestCase
{
    /**
     * Les écouteurs du dispatcher principal propres à certains environnements
     * => ceux qui les ont, et pourquoi. Ils sont retirés de la comparaison
     * avec `test`, et leur présence vérifiée environnement par environnement.
     *
     * @var array<string, array{list<string>, string}>
     */
    private const array ENVIRONMENT_SPECIFIC_LISTENERS = [
        'Symfony\Component\Mailer\Event\MessageEvent mailer.message_logger_listener::onMessage -255' => [
            ['test'],
            'Journal des e-mails envoyés, posé par framework.test pour les assertions de mail des tests fonctionnels.',
        ],
        'console.command monolog.handler.console::onCommand 255' => [
            ['dev', 'prod'],
            'Gestionnaire Monolog de la console, déclaré par monolog.yaml en dev et en prod, absent de test.',
        ],
        'console.terminate monolog.handler.console::onTerminate -255' => [
            ['dev', 'prod'],
            'Même gestionnaire Monolog de la console, à la fin de la commande.',
        ],
        'console.error maker.console_error_listener::onConsoleError 0' => [
            ['dev'],
            'MakerBundle, installé pour dev seul (bundles.php).',
        ],
        'console.terminate maker.console_error_listener::onConsoleTerminate 0' => [
            ['dev'],
            'MakerBundle, installé pour dev seul (bundles.php).',
        ],
    ];

    /**
     * Les passes que Symfony enregistre après l'instantané — celles du
     * conteneur, qui retirent et inlinent, puis celles du cache et de
     * FrameworkBundle —, les mêmes dans chaque environnement. Une liste figée,
     * pas un préfixe refusé : une classe anonyme, ou d'un autre espace de
     * noms, échappait à un filtre sur `App\` (audit de #698, mesuré).
     *
     * @var list<class-string>
     */
    private const array EXPECTED_LATER_PASSES = [
        RemovePrivateAliasesPass::class,
        ReplaceAliasByActualDefinitionPass::class,
        RemoveAbstractDefinitionsPass::class,
        RemoveUnusedDefinitionsPass::class,
        AnalyzeServiceReferencesPass::class,
        CheckExceptionOnInvalidReferenceBehaviorPass::class,
        InlineServiceDefinitionsPass::class,
        AnalyzeServiceReferencesPass::class,
        CheckFactoryBuilderCircularReferencePass::class,
        DefinitionErrorExceptionPass::class,
        ResolveHotPathPass::class,
        ResolveNoPreloadPass::class,
        AliasDeprecatedPublicServicesPass::class,
        CachePoolClearerPass::class,
        CachePoolPrunerPass::class,
        TestServiceContainerRealRefPass::class,
        RegisterReverseContainerPass::class,
        RemoveBuildParametersPass::class,
    ];

    private const string FIXTURES = __DIR__.'/Fixtures/EnvironmentServices';

    /**
     * Ce qu'une surcharge propre à `prod` de chaque forme doit faire
     * ressortir, par le début de sa ligne : la fixture n'en promet pas plus,
     * pour qu'une évolution du vendor (un argument ajouté, un identifiant
     * haché) ne fasse pas réécrire la liste en bloc.
     *
     * @var array<string, string> forme => début de la ligne attendue
     */
    private const array PROD_ONLY_FORMS = [
        'voter autoconfiguré' => 'prod ≠ test : service App\Tests\Security\Fixtures\EnvironmentServices\GrantsTrustedVoter (seulement dans prod)',
        'décorateur du fournisseur' => 'prod ≠ test : service App\Tests\Security\Fixtures\EnvironmentServices\DecoratingUserProvider (seulement dans prod)',
        'écouteur kernel.response' => 'prod ≠ test : écouteur kernel.response App\Tests\Security\Fixtures\EnvironmentServices\CookieRewritingListener::onKernelResponse 0 (seulement dans prod)',
        'écouteur d\'un événement Lexik nommé par une chaîne' => 'prod ≠ test : écouteur lexik_jwt_authentication.on_jwt_decoded App\Tests\Security\Fixtures\EnvironmentServices\JwtPayloadListener::onJwtDecoded 0 (seulement dans prod)',
        'écouteur Doctrine' => 'prod ≠ test : écouteur Doctrine doctrine.event_listener App\Tests\Security\Fixtures\EnvironmentServices\RolesRewritingDoctrineListener ',
        'cookie BEARER sans Secure' => 'prod ≠ attendu : service lexik_jwt_authentication.cookie_provider.BEARER [arguments][5] vaut false au lieu de true',
        'fichier inclus' => 'prod ≠ test : service App\Security\Authentication\Infrastructure\Http\CsrfCookieRequestSubscriber [file]',
        'cas d\'enum' => 'prod ≠ test : service fixture.enum_service [arguments][0]',
        'référence facultative' => 'prod ≠ test : service fixture.reference_service [arguments][1]',
        'alias de l\'horloge' => 'prod ≠ test : alias Psr\Clock\ClockInterface',
        'hiérarchie des rôles par une passe' => 'prod ≠ test : service security.role_hierarchy [arguments][0]',
        'matcher access_control caché' => 'prod ≠ test : service .security.request_matcher.',
        'enum d\'un service de Symfony par une passe' => 'prod ≠ test : service security.authenticator.manager.login [arguments]',
        'limiteur relevé' => 'prod ≠ test : service limiter.base_access ',
        'CORS' => 'prod ≠ test : service nelmio_cors.',
        'proxys de confiance' => 'prod ≠ test : paramètre kernel.trusted_proxies',
        'défaut env()' => 'prod ≠ test : paramètre env(JWT_PASSPHRASE)',
        'branche sur APP_ENV du processus' => 'prod ≠ test : service fixture.env_branch_voter (seulement dans prod)',
        'abonné ajouté au dispatcher par une passe' => 'prod ≠ test : service event_dispatcher [calls]',
        'en-tête X-Sendfile-Type de confiance' => 'prod ≠ test : paramètre kernel.trust_x_sendfile_type_header',
        'service rendu public' => 'prod ≠ test : service fixture.visibility_service [public]',
    ];

    /**
     * Un instantané par environnement et fichiers ajoutés, gardé d'un test à
     * l'autre : chaque compilation charge tout src/.
     *
     * @var array<string, SecurityServicesSnapshot>
     */
    private static array $snapshots = [];

    /**
     * Construites une fois : chaque comparaison de service les relit.
     *
     * @var list<EnvironmentSpecificPath>|null
     */
    private static ?array $environmentSpecificPaths = null;

    public function testEveryEnvironmentDefinesTheSameSecurityServicesAsTest(): void
    {
        self::assertSame(
            [],
            $this->differences([]),
            'Service de sécurité défini autrement que dans le conteneur de test : les gardes de l\'exposition ne le voient pas. Le rendre commun à tous les environnements.',
        );
    }

    /**
     * La comparaison elle-même, sur l'application compilée avec deux fichiers
     * de plus qui surchargent, en `prod` seul, un service de chaque forme que
     * l'issue et ses relectures redoutent (fixtures prod-only-services.*) :
     * chacune ressort, et rien en `dev`.
     */
    public function testTheComparisonSeesEveryFormOfAProdOnlyOverride(): void
    {
        $differences = $this->differences($this->fixtureFiles());
        foreach (self::PROD_ONLY_FORMS as $form => $expected) {
            self::assertNotSame(
                [],
                array_filter($differences, static fn (string $difference): bool => str_starts_with($difference, $expected)),
                \sprintf("Surcharge propre à prod non vue : %s.\nAttendu : %s…\nVu :\n%s", $form, $expected, implode("\n", $differences)),
            );
        }
        self::assertSame([], array_values(array_filter($differences, static fn (string $difference): bool => !str_starts_with($difference, 'prod '))), 'La fixture n\'agit qu\'en prod.');
    }

    /**
     * Une exemption qui ne sert plus se retire : sinon elle couvrirait demain
     * une différence que personne n'a justifiée. Ses valeurs attendues
     * doivent donc différer d'un environnement à l'autre, et en nommer
     * chacun — la valeur réelle, elle, est vérifiée par differences(), dans
     * la fixture comme dans l'application.
     */
    public function testEveryEnvironmentSpecificPathIsStillNeeded(): void
    {
        $environments = $this->sortedEnvironments();
        foreach ($this->environmentSpecificPaths() as $exemption) {
            $named = array_keys($exemption->expected);
            sort($named);
            self::assertSame($environments, $named, \sprintf('%s : la valeur attendue de chaque environnement que le noyau admet doit être écrite, ni plus ni moins.', $exemption->describe()));
            self::assertGreaterThan(1, \count(array_unique(array_map(serialize(...), $exemption->expected))), \sprintf(
                '%s attend la même valeur dans tous les environnements : son exemption ne sert plus. Elle disait : « %s » Ne la retirer qu\'après avoir vérifié que cette raison ne tient plus (%s énonce la règle).',
                $exemption->describe(),
                $exemption->reason,
                $exemption->pinnedBy,
            ));
        }
    }

    public function testEveryEnvironmentSpecificListenerIsStillNeeded(): void
    {
        $environments = $this->sortedEnvironments();
        foreach (self::ENVIRONMENT_SPECIFIC_LISTENERS as $listener => [$present, $reason]) {
            self::assertNotSame([], array_diff($environments, $present), \sprintf('%s est présent partout : son exemption ne sert plus (« %s »).', $listener, $reason));
            self::assertSame([], array_diff($present, $environments), \sprintf('%s nomme un environnement que le noyau n\'admet pas.', $listener));
        }
    }

    /**
     * L'instantané ne voit pas ce que font les passes qui le suivent : il
     * n'en admet aucune hors d'EXPECTED_LATER_PASSES, dans aucun
     * environnement. Une passe de plus, quelle que soit sa classe, rougit —
     * la fixture le prouve.
     */
    public function testThePassesAfterTheSnapshotAreTheExpectedOnes(): void
    {
        foreach (EnvironmentConfigKernel::allowedEnvironments() as $environment) {
            self::assertSame(self::EXPECTED_LATER_PASSES, $this->snapshot($environment, [])->laterPasses, \sprintf('Passes après l\'instantané des services de sécurité, en %s : elles lui échappent. En enregistrer une avant TYPE_REMOVE, ou justifier dans EXPECTED_LATER_PASSES une passe de Symfony.', $environment));
        }
        self::assertSame(
            [AfterRemovingProbePass::class],
            array_values(array_diff($this->snapshot('prod', $this->fixtureFiles())->laterPasses, self::EXPECTED_LATER_PASSES)),
        );
    }

    /**
     * Le périmètre ne doit pas fondre en silence — une classe qui ne se
     * charge plus, un contrat renommé : les services que l'issue et ses
     * relectures nomment y sont, chaque contrat existe, et les écouteurs
     * comparés comptent la garde CSRF et un événement de Lexik.
     */
    public function testThePerimeterHoldsTheServicesTheIssueNames(): void
    {
        $snapshot = $this->snapshot('test', []);
        foreach ([
            BaseAccessUserProvider::class,
            CpgUserRepository::class,
            LoginThrottlingRefusalListener::class,
            'security.access.role_hierarchy_voter',
            'security.access.decision_manager',
            'security.access_map',
            'security.firewall.map',
            'security.role_hierarchy',
            'lexik_jwt_authentication.cookie_provider.BEARER',
            'limiter._login_local_login',
            'messenger.transport.async',
            'clock',
        ] as $service) {
            self::assertArrayHasKey($service, $snapshot->services, \sprintf('%s n\'est plus dans le périmètre comparé.', $service));
        }
        self::assertNotSame([], array_filter(array_keys($snapshot->services), static fn (string $id): bool => str_starts_with($id, '.security.request_matcher.')), 'Les matchers d\'access_control ne sont plus dans le périmètre comparé.');
        foreach (SecurityServicesSnapshotPass::CONTRACTS as $contract) {
            self::assertTrue(interface_exists($contract), \sprintf('Le contrat %s n\'existe plus : le périmètre a fondu.', $contract));
        }
        foreach (['kernel.request '.CsrfCookieRequestSubscriber::class.'::', 'lexik_jwt_authentication.on_authentication_success '] as $listener) {
            self::assertNotSame([], array_filter($snapshot->listeners, static fn (string $line): bool => str_starts_with($line, $listener)), \sprintf('« %s… » n\'est plus parmi les écouteurs comparés.', $listener));
        }
        self::assertArrayHasKey('kernel.trusted_proxies', $snapshot->parameters);
    }

    /**
     * Les écarts voulus dans une définition, avec la valeur attendue de chaque
     * environnement. Le chemin seul ne suffisait pas : exempté, il masquait
     * une redéfinition propre à `prod` du cookie `BEARER` sans `Secure`
     * (mesuré avec la fixture), le trou de #703 sous une autre forme.
     *
     * @return list<EnvironmentSpecificPath>
     */
    private function environmentSpecificPaths(): array
    {
        return self::$environmentSpecificPaths ??= $this->buildEnvironmentSpecificPaths();
    }

    /**
     * @return list<EnvironmentSpecificPath>
     */
    private function buildEnvironmentSpecificPaths(): array
    {
        $environments = EnvironmentConfigKernel::allowedEnvironments();
        $byEnvironment = static fn (callable $value): array => array_combine($environments, array_map($value, $environments));

        return [
            new EnvironmentSpecificPath(
                'security.password_hasher_factory',
                ['arguments', 0, CpgUser::class, 'arguments', 2],
                ['dev' => 13, 'prod' => 13, 'test' => 4],
                'Coût bcrypt abaissé à 4 sous when@test pour la vitesse de la suite (issue #590) ; même exemption que security.password_hashers dans la configuration déclarée.',
                CpgUserPasswordHasherTest::class,
            ),
            new EnvironmentSpecificPath(
                'lexik_jwt_authentication.cookie_provider.BEARER',
                ['arguments', 5],
                $byEnvironment(static fn (string $environment): bool => new AuthCookieFactory($environment)->bearer('jwt')->isSecure()),
                'Secure partout sauf en dev, la règle d\'AuthCookieFactory (issue #714), dont la valeur attendue est tirée ; même exemption que lexik_jwt_authentication.set_cookies.BEARER.secure dans la configuration déclarée.',
                BearerCookieSecurePerEnvironmentTest::class,
            ),
            new EnvironmentSpecificPath(
                AuthCookieFactory::class,
                ['arguments', 0],
                $byEnvironment(static fn (string $environment): string => $environment),
                'La fabrique reçoit l\'environnement (%kernel.environment%) pour décider de Secure (issue #714) ; c\'est sa règle elle-même que fige son test.',
                AuthCookieFactoryTest::class,
            ),
            new EnvironmentSpecificPath(
                EndOfLifeDateClient::class,
                ['public'],
                $byEnvironment(static fn (string $environment): bool => 'test' === $environment),
                'Rendu public sous when@test (services.yaml) pour qu\'un test fonctionnel remplace la seule source d\'appels sortants ; privé partout ailleurs.',
                BackofficeWatchedProductResourceTest::class,
            ),
            new EnvironmentSpecificPath(
                CorpusRenderer::class,
                ['public'],
                $byEnvironment(static fn (string $environment): bool => 'test' === $environment),
                'Rendu public sous when@test (services.yaml) pour que CorpusSourcesTest lise les sources réellement injectées ; privé partout ailleurs.',
                CorpusSourcesTest::class,
            ),
            new EnvironmentSpecificPath(
                'messenger.transport.async',
                ['arguments', 0],
                ['dev' => '%env(MESSENGER_TRANSPORT_DSN)%', 'prod' => '%env(MESSENGER_TRANSPORT_DSN)%', 'test' => 'in-memory://'],
                'Pas de vrai broker en test : le transport async y est en mémoire (messenger.yaml, when@test). Le sérialiseur et les options du transport restent comparés.',
                AmqpPublisherConfirmsTest::class,
            ),
        ];
    }

    /**
     * @return list<string>
     */
    private function fixtureFiles(): array
    {
        return [self::FIXTURES.'/prod-only-services.yaml', self::FIXTURES.'/prod-only-services.php'];
    }

    /**
     * @return list<string>
     */
    private function sortedEnvironments(): array
    {
        $environments = EnvironmentConfigKernel::allowedEnvironments();
        sort($environments);

        return $environments;
    }

    /**
     * @param list<string> $extraConfigFiles
     *
     * @return list<string> « env ≠ test : … » ou « env ≠ attendu : … », triées
     */
    private function differences(array $extraConfigFiles): array
    {
        $found = [];
        $reference = $this->snapshot('test', $extraConfigFiles);
        foreach (EnvironmentConfigKernel::comparedEnvironments() as $environment) {
            $other = $this->snapshot($environment, $extraConfigFiles);
            $sections = [
                'service' => $this->serviceDifferences($reference, $other, $environment),
                'alias' => $this->mapDifferences($reference->aliases, $other->aliases, $environment),
                'écouteur' => $this->listenerDifferences($this->comparedListeners($reference), $this->comparedListeners($other), $environment),
                'écouteur Doctrine' => $this->listenerDifferences($reference->doctrineListeners, $other->doctrineListeners, $environment),
                'paramètre' => $this->mapDifferences($reference->parameters, $other->parameters, $environment),
            ];
            foreach ($sections as $section => $differences) {
                foreach ($differences as $difference) {
                    $found[] = \sprintf('%s ≠ test : %s %s', $environment, $section, $difference);
                }
            }
        }
        foreach (EnvironmentConfigKernel::allowedEnvironments() as $environment) {
            $snapshot = $this->snapshot($environment, $extraConfigFiles);
            foreach ([...$this->unexpectedEnvironmentSpecificValues($snapshot, $environment), ...$this->unexpectedEnvironmentSpecificListeners($snapshot, $environment)] as $difference) {
                $found[] = \sprintf('%s ≠ attendu : %s', $environment, $difference);
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
        $found = $this->presenceDifferences(array_keys($reference->services), array_keys($other->services), $environment);
        foreach (array_intersect_key($reference->services, $other->services) as $service => $definition) {
            $path = self::firstDifference($this->compared($service, $definition), $this->compared($service, $other->services[$service]), []);
            if (null !== $path) {
                $found[] = \sprintf('%s %s', $service, KeyPaths::describe($path));
            }
        }

        return $found;
    }

    /**
     * Ce qui n'est que d'un côté, avec le côté qui l'a.
     *
     * @param list<string> $reference
     * @param list<string> $other
     *
     * @return list<string>
     */
    private function presenceDifferences(array $reference, array $other, string $environment): array
    {
        $found = [];
        foreach (array_diff($reference, $other) as $entry) {
            $found[] = \sprintf('%s (seulement dans test)', $entry);
        }
        foreach (array_diff($other, $reference) as $entry) {
            $found[] = \sprintf('%s (seulement dans %s)', $entry, $environment);
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $reference
     * @param array<string, mixed> $other
     *
     * @return list<string>
     */
    private function mapDifferences(array $reference, array $other, string $environment): array
    {
        $found = [];
        foreach (array_unique([...array_keys($reference), ...array_keys($other)]) as $key) {
            $inReference = \array_key_exists($key, $reference);
            $inOther = \array_key_exists($key, $other);
            if ($inReference !== $inOther || ($inReference && $reference[$key] !== $other[$key])) {
                $found[] = \sprintf('%s : %s dans test, %s dans %s', $key, $inReference ? $this->export($reference[$key]) : 'absent', $inOther ? $this->export($other[$key]) : 'absent', $environment);
            }
        }

        return $found;
    }

    /**
     * Les écouteurs comptés, pas seulement présents — un écouteur enregistré
     * deux fois s'exécute deux fois —, puis l'ordre des communs : à priorité
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
        $referenceCounts = array_count_values($reference);
        $otherCounts = array_count_values($other);
        foreach (array_unique([...$reference, ...$other]) as $listener) {
            [$inReference, $inOther] = [$referenceCounts[$listener] ?? 0, $otherCounts[$listener] ?? 0];
            if ($inReference === $inOther) {
                continue;
            }
            $found[] = match (true) {
                0 === $inOther => \sprintf('%s (seulement dans test)', $listener),
                0 === $inReference => \sprintf('%s (seulement dans %s)', $listener, $environment),
                default => \sprintf('%s (%d fois dans test, %d dans %s)', $listener, $inReference, $inOther, $environment),
            };
        }
        // L'ordre n'a de sens qu'entre les mêmes écouteurs, en même nombre :
        // array_intersect garde les doublons de son premier tableau.
        if ([] === $found && array_values(array_intersect($reference, $other)) !== array_values(array_intersect($other, $reference))) {
            $found[] = '(ordre)';
        }

        return $found;
    }

    /**
     * Les écouteurs du dispatcher principal, hors ENVIRONMENT_SPECIFIC_LISTENERS.
     *
     * @return list<string>
     */
    private function comparedListeners(SecurityServicesSnapshot $snapshot): array
    {
        return array_values(array_filter($snapshot->listeners, static fn (string $listener): bool => !isset(self::ENVIRONMENT_SPECIFIC_LISTENERS[$listener])));
    }

    /**
     * @return list<string>
     */
    private function unexpectedEnvironmentSpecificListeners(SecurityServicesSnapshot $snapshot, string $environment): array
    {
        $found = [];
        foreach (self::ENVIRONMENT_SPECIFIC_LISTENERS as $listener => [$present]) {
            $expected = \in_array($environment, $present, true) ? 1 : 0;
            $actual = \count(array_keys($snapshot->listeners, $listener, true));
            if ($actual !== $expected) {
                $found[] = \sprintf('écouteur %s enregistré %d fois au lieu de %d', $listener, $actual, $expected);
            }
        }

        return $found;
    }

    /**
     * Les chemins d'environmentSpecificPaths() dont la valeur n'est pas celle
     * que l'environnement doit avoir — `test` compris : le retirer de la
     * comparaison ne dit rien de ce qu'il vaut.
     *
     * @return list<string>
     */
    private function unexpectedEnvironmentSpecificValues(SecurityServicesSnapshot $snapshot, string $environment): array
    {
        $found = [];
        foreach ($this->environmentSpecificPaths() as $exemption) {
            $actual = KeyPaths::at($snapshot->services[$exemption->service] ?? null, $exemption->path);
            if (!\array_key_exists($environment, $exemption->expected) || $actual !== $exemption->expected[$environment]) {
                $found[] = \sprintf('service %s vaut %s au lieu de %s', $exemption->describe(), $this->export($actual), \array_key_exists($environment, $exemption->expected) ? $this->export($exemption->expected[$environment]) : '(non écrite)');
            }
        }

        return $found;
    }

    /**
     * La définition d'un service, hors du chemin exempté pour lui.
     */
    private function compared(string $service, mixed $definition): mixed
    {
        foreach ($this->environmentSpecificPaths() as $exemption) {
            if ($exemption->service === $service && \is_array($definition)) {
                $definition = KeyPaths::without($definition, $exemption->path);
            }
        }

        return $definition;
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
     * Une valeur sur une ligne : var_export rend aussi un enum pur ou NAN,
     * que json_encode refuse.
     */
    private function export(mixed $value): string
    {
        return (string) preg_replace('/\s+/', ' ', var_export($value, true));
    }

    /**
     * @param list<string> $extraConfigFiles
     */
    private function snapshot(string $environment, array $extraConfigFiles): SecurityServicesSnapshot
    {
        return self::$snapshots[$environment.'|'.implode('|', $extraConfigFiles)] ??= new EnvironmentServicesKernel($environment, $extraConfigFiles)->securityServices();
    }
}
