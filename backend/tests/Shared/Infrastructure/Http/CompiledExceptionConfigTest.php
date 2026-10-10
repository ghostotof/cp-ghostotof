<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Tests\Shared\Infrastructure\Http\Fixtures\ExceptionToStatusFixtureResource;
use App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelAttributes\AbstractAttributedFixtureException;
use App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelAttributes\AttributeLoggedFixtureException;
use App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelAttributes\LoudAttributeFixtureException;
use App\Tests\Support\CompiledExceptionConfig;
use App\Tests\Support\ExtraConfigKernel;
use DomainException;
use OverflowException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Les lectures de CompiledExceptionConfig, éprouvées une à une (issue #357) :
 * ce que le noyau et API Platform font d'une exception, lu dans la
 * configuration compilée ou reproduit à l'identique. Les garde-fous de
 * log_level (ExceptionLogLevelCoverageTest) s'appuient sur elles ; ce sont
 * elles qui doivent rester fidèles au vendor.
 */
final class CompiledExceptionConfigTest extends KernelTestCase
{
    private const string EXTRA_CONFIG = __DIR__.'/Fixtures/ExtraConfig/exceptions.yaml';
    private const string EXTRA_CONFIG_OPTION = 'extra_config';

    /**
     * L'option `extra_config` de bootKernel() — propre à cette classe,
     * KernelTestCase ignore une option qu'il ne connaît pas — démarre le noyau
     * avec un fichier de configuration de plus (ExtraConfigKernel). Le noyau est éteint après
     * chaque test (KernelTestCase::tearDown) : les autres tests retrouvent le
     * noyau de l'application.
     *
     * @param array<mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $extraConfig = $options[self::EXTRA_CONFIG_OPTION] ?? null;

        return \is_string($extraConfig) ? new ExtraConfigKernel($extraConfig) : parent::createKernel($options);
    }

    /**
     * La lecture compilée elle-même (issue #357) : une entrée déclarée dans un
     * autre fichier de configuration, qui en surcharge une et en ajoute une
     * autre, doit apparaître dans ce que lit le garde-fou. Le fichier est
     * chargé par un noyau dédié, jamais par la vraie configuration ; une
     * lecture de framework.yaml par Yaml::parseFile ne verrait ni l'une ni
     * l'autre.
     */
    public function testAnEntryDeclaredInAnotherConfigFileIsRead(): void
    {
        self::bootKernel([self::EXTRA_CONFIG_OPTION => self::EXTRA_CONFIG]);
        $mapping = CompiledExceptionConfig::exceptionsMapping(self::getContainer()->get('exception_listener'));

        self::assertSame('warning', $mapping[CpgUserNotFoundException::class]['log_level'] ?? null, 'La surcharge déclarée ailleurs n\'est pas lue.');
        self::assertSame('info', $mapping[DomainException::class]['log_level'] ?? null, 'L\'entrée déclarée ailleurs n\'est pas lue.');
    }

    /**
     * Dans une table `exception_to_status`, API Platform retient la première
     * clé qui correspond par is_a(), pas l'entrée au nom exact de la classe
     * (ErrorListener::getStatusCode).
     */
    public function testTheFirstMatchingKeyGivesTheStatus(): void
    {
        self::assertSame(
            [500],
            CompiledExceptionConfig::statusesFor([[RuntimeException::class => 500, OverflowException::class => 404]], OverflowException::class),
        );
    }

    /**
     * Les tables qu'API Platform consulte : le paramètre global seul, et fusionné
     * avec celle de l'opération (array_merge : une clé déjà présente garde sa
     * place et prend la valeur de l'opération). La même exception peut donc
     * être rendue avec l'un ou l'autre statut.
     */
    public function testAnOperationOverridesTheGlobalStatusInPlace(): void
    {
        $mappings = CompiledExceptionConfig::apiPlatformMappings(
            self::getContainer()->get('api_platform.metadata.resource.metadata_collection_factory'),
            [ExceptionToStatusFixtureResource::class],
            [OverflowException::class => 404],
        );

        self::assertSame([404, 422], CompiledExceptionConfig::statusesFor($mappings, OverflowException::class));
    }

    /**
     * Le recensement ne lit que les opérations HTTP : celles de GraphQL ne
     * portent pas d'`exceptionToStatus`, et leurs erreurs suivent un autre
     * chemin. GraphQL est désactivé (webonyx/graphql-php n'est pas installé),
     * et la fabrique de métadonnées refuse alors toute opération GraphQL : le
     * cas n'existe pas aujourd'hui. Ce témoin rougit le jour où il existe
     * (issue #357).
     */
    public function testGraphQlStaysDisabled(): void
    {
        self::assertFalse(
            self::getContainer()->getParameter('api_platform.graphql.enabled'),
            'GraphQL activé : ses erreurs échappent à ce garde-fou. Étendre le recensement à ses opérations avant de retirer ce témoin.',
        );
    }

    /**
     * Une entrée `framework.exceptions` qui fixe un `status_code` fait
     * convertir l'exception par le noyau — rendue avec ce statut —, mais son
     * niveau reste résolu sur l'exception d'origine : sans `log_level`,
     * `critical` (issue #357).
     */
    public function testAStatusCodeEntryConvertsTheException(): void
    {
        $listener = CompiledExceptionConfig::listenerWith([OverflowException::class => ['status_code' => 404]]);

        self::assertSame([OverflowException::class => 404], CompiledExceptionConfig::kernelHttpStatus($listener, [OverflowException::class]));
    }

    /**
     * L'ordre du noyau : une entrée `status_code` convertit l'exception avant
     * que #[WithHttpStatus] soit lu ; c'est son statut qui est rendu.
     */
    public function testAStatusCodeEntryTakesPrecedenceOverWithHttpStatus(): void
    {
        $listener = CompiledExceptionConfig::listenerWith([DomainException::class => ['status_code' => 503]]);

        self::assertSame(
            [AttributeLoggedFixtureException::class => 503],
            CompiledExceptionConfig::kernelHttpStatus($listener, [AttributeLoggedFixtureException::class]),
        );
    }

    /**
     * Une exception abstraite n'est jamais levée telle quelle.
     */
    public function testAnAbstractExceptionIsNeverConverted(): void
    {
        self::assertSame([], CompiledExceptionConfig::kernelHttpStatus(self::getContainer()->get('exception_listener'), [AbstractAttributedFixtureException::class]));
    }

    /**
     * L'ordre du noyau, sur lequel le contrôle des niveaux s'appuie : une
     * entrée qui correspond l'emporte sur l'attribut.
     */
    public function testAnEntryTakesPrecedenceOverTheAttribute(): void
    {
        $listener = CompiledExceptionConfig::listenerWith([DomainException::class => ['log_level' => 'info']]);

        self::assertSame('info', CompiledExceptionConfig::kernelLogLevel($listener, LoudAttributeFixtureException::class));
    }
}
