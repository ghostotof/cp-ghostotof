<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\ApiPlatform;

use App\Tests\Support\DeprecatedReferencesOfApiPlatform;
use App\Tests\Support\EnvironmentServicesKernel;
use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\Alias;
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

    public function testItFindsAReferenceToADeprecatedServiceAndOneNestedInAnArgument(): void
    {
        $container = $this->container();
        $container->setDefinition('api_platform.reader', new Definition(stdClass::class, [['nested' => [new Reference('legacy_service')]]]));

        $found = $this->found($container);

        self::assertCount(1, $found);
        self::assertStringContainsString('api_platform.reader référence le service déprécié « legacy_service »', $found[0]);
    }

    public function testItFollowsAChainOfAliases(): void
    {
        $container = $this->container();
        $container->setAlias('front_alias', new Alias('legacy_alias'));
        $container->setDefinition('api_platform.reader', new Definition(stdClass::class, [new Reference('front_alias')]));

        self::assertCount(1, $this->found($container));
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
     * compilé en mémoire les rend. La compilation lève, elle, les dépréciations
     * de Symfony que ce test ne regarde pas.
     */
    #[IgnoreDeprecations]
    public function testItSeesThemInTheCompiledKernel(): void
    {
        $pass = new DeprecatedReferencesOfApiPlatform();
        $fixture = RepositoryFiles::backend('tests/Shared/Infrastructure/ApiPlatform/Fixtures/api-platform-references-deprecated-alias.php');

        new EnvironmentServicesKernel('test', [$fixture], [$pass])->securityServices();

        $found = $pass->found();
        self::assertCount(2, $found, implode("\n", $found));
        self::assertStringContainsString('api_platform.probe_reader référence l\'alias déprécié « probe.legacy_alias »', $found[0]);
        self::assertStringContainsString('api_platform.probe_reader référence le service déprécié « probe.legacy_service »', $found[1]);
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
