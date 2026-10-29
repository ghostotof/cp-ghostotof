<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\ApiPlatform;

use App\Tests\Support\DeprecatedReferencesOfApiPlatform;
use App\Tests\Support\EnvironmentServicesKernel;
use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * La passe qui relève les références d'un service d'API Platform à un alias
 * ou à un service déprécié (issue #701) : ce qu'elle voit, et ce qu'elle laisse.
 *
 * Elle vaut parce que la dépréciation correspondante est levée par une passe
 * de Symfony, sans aucune trame d'API Platform, et que PHPUnit la tait
 * (`ignoreIndirectDeprecations`) : voir {@see \App\Tests\Support\DeprecatedReferencesOfApiPlatform}.
 */
final class DeprecatedReferencesOfApiPlatformTest extends TestCase
{
    public function testItFindsAnApiPlatformServiceReferencingADeprecatedAlias(): void
    {
        $container = $this->container();
        $container->setDefinition('api_platform.reader', new Definition(stdClass::class, [new Reference('legacy_alias')]));

        $found = $this->found($container);

        self::assertCount(1, $found);
        self::assertStringContainsString('api_platform.reader référence l\'alias déprécié « legacy_alias »', $found[0]);
        self::assertStringContainsString('remplacé', $found[0]);
    }

    public function testItFindsAReferenceNestedInAnArgument(): void
    {
        $container = $this->container();
        $container->setDefinition('api_platform.reader', new Definition(stdClass::class, [['nested' => [new Reference('legacy_alias')]]]));

        self::assertCount(1, $this->found($container));
    }

    /**
     * D4 : Symfony ne lève la dépréciation d'un **service** qu'à son
     * instanciation (`ContainerBuilder::createService()`, ou l'usine que
     * `PhpDumper` écrit dans le conteneur compilé), jamais à la compilation :
     * contrairement à un alias, que `ResolveReferencesToAliasesPass` signale
     * dès qu'une définition le référence. La passe ne rapporte que ce que la
     * compilation lève ; l'instanciation est vue par les requêtes de la garde,
     * avec la pile qui dit qui l'a provoquée.
     */
    public function testItDoesNotReportAReferenceToADeprecatedServiceSinceSymfonyRaisesItOnlyWhenItIsInstantiated(): void
    {
        $container = $this->container();
        $container->setDefinition('api_platform.reader', new Definition(stdClass::class, [new Reference('legacy_service')]));

        self::assertSame([], $this->found($container));
    }

    public function testItFollowsAChainOfAliases(): void
    {
        $container = $this->container();
        $container->setAlias('front_alias', new Alias('legacy_alias'));
        $container->setDefinition('api_platform.reader', new Definition(stdClass::class, [new Reference('front_alias')]));

        self::assertCount(1, $this->found($container));
    }

    /**
     * D3 : comme `ResolveReferencesToAliasesPass` (`!$referencingDefinition->isDeprecated()`),
     * une définition elle-même dépréciée peut référencer un alias déprécié
     * sans que Symfony le lève.
     */
    public function testItIgnoresAReferenceMadeByADeprecatedService(): void
    {
        $container = $this->container();
        $container->setDefinition('api_platform.reader', new Definition(stdClass::class, [new Reference('legacy_alias')]))
            ->setDeprecated('vendor/probe', '1.0', 'Le service "%service_id%" est remplacé.');

        self::assertSame([], $this->found($container));
    }

    /**
     * Dans une chaîne d'alias, Symfony compte le référent de chaque maillon :
     * le service pour le premier, l'alias précédent pour les suivants. Un
     * service déprécié ne couvre donc pas l'alias déprécié qu'un alias sain
     * lui cache ; un alias déprécié couvre celui qu'il référence.
     */
    public function testItJudgesEachLinkOfAChainByItsOwnReferrer(): void
    {
        $deprecatedService = $this->container();
        $deprecatedService->setAlias('front_alias', new Alias('legacy_alias'));
        $deprecatedService->setDefinition('api_platform.reader', new Definition(stdClass::class, [new Reference('front_alias')]))
            ->setDeprecated('vendor/probe', '1.0', 'Le service "%service_id%" est remplacé.');

        self::assertCount(1, $this->found($deprecatedService), 'Le service déprécié ne couvre pas l\'alias déprécié derrière un alias sain.');

        $deprecatedLinks = $this->container();
        $deprecatedLinks->setAlias('front_alias', new Alias('legacy_alias'))->setDeprecated('vendor/probe', '1.0', 'L\'alias "%alias_id%" est remplacé.');
        $deprecatedLinks->setDefinition('api_platform.reader', new Definition(stdClass::class, [new Reference('front_alias')]));

        self::assertCount(1, $this->found($deprecatedLinks), 'Un alias déprécié couvre l\'alias déprécié qu\'il référence : une seule dépréciation.');
    }

    /**
     * D10 : la passe s'applique à un conteneur à la fois. Rejouée sur le même,
     * ou sur un autre, elle ne rapporte pas ce qu'une compilation précédente a
     * trouvé.
     */
    public function testItDoesNotAccumulateFindingsFromOneCompilationToTheNext(): void
    {
        $container = $this->container();
        $container->setDefinition('api_platform.reader', new Definition(stdClass::class, [new Reference('legacy_alias')]));
        $pass = new DeprecatedReferencesOfApiPlatform();

        $pass->process($container);
        $pass->process($container);
        self::assertCount(1, $pass->found(), 'Deux passages sur le même conteneur : une seule référence.');

        $pass->process($this->container());
        self::assertSame([], $pass->found(), 'Un conteneur sans référence ne rend rien des passages précédents.');
    }

    /**
     * D5 : les services cachés (`.api_platform.…`) et ceux dont la classe vient
     * de leur parent (`ChildDefinition`) sont ceux d'API Platform tout autant.
     */
    public function testItRecognisesAHiddenServiceId(): void
    {
        $container = $this->container();
        $container->setDefinition('.api_platform.hidden_reader', new Definition(stdClass::class, [new Reference('legacy_alias')]));

        self::assertCount(1, $this->found($container));
    }

    public function testItRecognisesAChildDefinitionWhoseClassComesFromItsParents(): void
    {
        $container = $this->container();
        $container->setDefinition('some.base', new Definition('ApiPlatform\\Symfony\\Anything'));
        $container->setDefinition('some.middle', new ChildDefinition('some.base'));
        $container->setDefinition('some.child', new ChildDefinition('some.middle'))->setArguments([new Reference('legacy_alias')]);

        self::assertCount(1, $this->found($container));
    }

    public function testItRecognisesAChildDefinitionOfAnApiPlatformParentById(): void
    {
        $container = $this->container();
        $container->setDefinition('api_platform.abstract_reader', new Definition(stdClass::class))->setAbstract(true);
        $container->setDefinition('some.child', new ChildDefinition('api_platform.abstract_reader'))->setArguments([new Reference('legacy_alias')]);

        self::assertCount(1, $this->found($container));
    }

    public function testItIgnoresAChildDefinitionOfAProjectParent(): void
    {
        $container = $this->container();
        $container->setDefinition('App\\Base', new Definition(stdClass::class))->setAbstract(true);
        $container->setDefinition('some.child', new ChildDefinition('App\\Base'))->setArguments([new Reference('legacy_alias')]);

        self::assertSame([], $this->found($container));
    }

    public function testItRecognisesAServiceByItsClassWhateverItsId(): void
    {
        $container = $this->container();
        $container->setDefinition('some.id', new Definition('ApiPlatform\\Symfony\\Anything', [new Reference('legacy_alias')]));

        self::assertCount(1, $this->found($container));
    }

    public function testItIgnoresAServiceOfTheProjectReferencingTheSameAlias(): void
    {
        $container = $this->container();
        $container->setDefinition('App\\Some\\Service', new Definition(stdClass::class, [new Reference('legacy_alias')]));

        self::assertSame([], $this->found($container), 'Le projet qui référence un alias déprécié est son affaire, pas celle d\'API Platform.');
    }

    public function testItIgnoresAnApiPlatformServiceReferencingAnAliasThatIsNotDeprecated(): void
    {
        $container = $this->container();
        $container->setDefinition('api_platform.reader', new Definition(stdClass::class, [new Reference('current_alias'), new Reference('target')]));

        self::assertSame([], $this->found($container));
    }

    /**
     * La preuve sur la vraie compilation : un service `api_platform.*` de la
     * fixture référence un alias et un service dépréciés, et le noyau de test
     * compilé en mémoire rend l'alias. Symfony, lui, ne lève à la compilation
     * que la dépréciation de l'alias : celle du service attend son instanciation
     * (D4), et c'est pourquoi la passe ne la rapporte pas.
     */
    public function testItSeesTheAliasInTheCompiledKernelWhereSymfonyRaisesNothingForTheService(): void
    {
        $pass = new DeprecatedReferencesOfApiPlatform();
        $fixture = RepositoryFiles::backend('tests/Shared/Infrastructure/ApiPlatform/Fixtures/api-platform-references-deprecated-alias.php');
        $raised = [];
        set_error_handler(static function (int $level, string $message) use (&$raised): bool {
            $raised[] = $message;

            return true;
        });

        try {
            new EnvironmentServicesKernel('test', [$fixture], [$pass])->securityServices();
        } finally {
            restore_error_handler();
        }

        $found = $pass->found();
        self::assertCount(1, $found, implode("\n", $found));
        self::assertStringContainsString('api_platform.probe_reader référence l\'alias déprécié « probe.legacy_alias »', $found[0]);

        $ofTheFixture = array_values(array_filter($raised, static fn (string $message): bool => str_starts_with($message, 'Since vendor/probe')));
        self::assertCount(1, $ofTheFixture, implode("\n", $ofTheFixture));
        self::assertStringContainsString('probe.legacy_alias', $ofTheFixture[0]);
        self::assertStringContainsString('api_platform.probe_reader', $ofTheFixture[0]);
    }

    /**
     * Un alias déprécié, un autre qui ne l'est pas, un service déprécié, un autre qui ne l'est pas.
     */
    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition('target', new Definition(stdClass::class));
        $container->setAlias('legacy_alias', new Alias('target'))->setDeprecated('vendor/probe', '1.0', 'L\'alias "%alias_id%" est remplacé.');
        $container->setAlias('current_alias', new Alias('target'));
        $container->setDefinition('legacy_service', new Definition(stdClass::class))->setDeprecated('vendor/probe', '1.0', 'Le service "%service_id%" est remplacé.');

        return $container;
    }

    /**
     * @return list<string>
     */
    private function found(ContainerBuilder $container): array
    {
        $pass = new DeprecatedReferencesOfApiPlatform();
        $pass->process($container);

        return $pass->found();
    }
}
