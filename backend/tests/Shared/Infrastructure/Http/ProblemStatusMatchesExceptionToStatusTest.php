<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Contact\Domain\Exception\ContactRateLimitExceededException;
use App\Contact\Presentation\ApiResource\ContactMessageResource;
use App\Tests\Support\CompiledExceptionConfig;
use App\Tests\Support\DeclaredClasses;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Sur une opération API Platform, le statut HTTP d'une erreur et le `status`
 * de son corps problem+json ne viennent pas du même endroit (issue #369).
 * {@see \ApiPlatform\Symfony\EventListener\ErrorListener} lit d'abord
 * `exception_to_status` (la table globale, fusionnée avec celles de la
 * ressource et de l'opération), alors que
 * {@see \ApiPlatform\State\ApiResource\Error::createFromException()} remplit le
 * corps avec getStatus() dès que l'exception est une ProblemExceptionInterface.
 * Une entrée qui contredit getStatus() rend donc, par exemple, une réponse 503
 * dont le corps dit 429 — et sans Retry-After, que RetryAfterListener ne pose
 * que sur un 429.
 *
 * Ce garde-fou exige que, pour toute ProblemExceptionInterface concrète de
 * src/ qui déclare un statut, chaque table qu'API Platform peut consulter lui
 * donne ce même statut, ou aucun. Les tables sont lues compilées
 * (CompiledExceptionConfig, issue #357), comme dans ExceptionLogLevelCoverageTest.
 */
final class ProblemStatusMatchesExceptionToStatusTest extends KernelTestCase
{
    private const string SOURCES = __DIR__.'/../../../../src';

    public function testNoExceptionToStatusEntryContradictsAProblemStatus(): void
    {
        $container = self::getContainer();
        /** @var array<string, int> $global */
        $global = $container->getParameter('api_platform.exception_to_status');
        $resourceClasses = iterator_to_array($container->get('api_platform.metadata.resource.name_collection_factory')->create(), false);
        // Une découverte qui ne trouverait rien laisserait le garde-fou vert
        // pour rien : une ressource connue doit y figurer.
        self::assertContains(ContactMessageResource::class, $resourceClasses, 'La découverte des ressources API Platform ne trouve plus ContactMessageResource : les exceptionToStatus des opérations ne sont plus lus.');
        $mappings = CompiledExceptionConfig::apiPlatformMappings(
            $container->get('api_platform.metadata.resource.metadata_collection_factory'),
            $resourceClasses,
            $global,
        );

        $problems = array_filter(
            DeclaredClasses::implementing(self::SOURCES, ProblemExceptionInterface::class),
            static fn (string $class): bool => !(new ReflectionClass($class))->isAbstract(),
        );
        self::assertNotEmpty($problems, 'Aucune ProblemExceptionInterface recensée : le garde-fou ne garderait rien.');

        self::assertSame(
            [],
            $this->contradictions($mappings, $problems),
            'Une entrée `exception_to_status` contredit le getStatus() de l\'exception : le statut HTTP et le `status` du corps divergeraient. Retirer l\'entrée (getStatus() suffit) ou l\'aligner.',
        );
    }

    /**
     * Le contrôle lui-même, sur des tables fabriquées : une entrée qui
     * contredit getStatus() est signalée, une entrée qui le répète ne l'est
     * pas, et une exception absente des tables non plus.
     */
    public function testAContradictingEntryIsFlagged(): void
    {
        $quota = [ContactRateLimitExceededException::class];

        self::assertSame(
            [ContactRateLimitExceededException::class => ['getStatus()' => 429, 'exception_to_status' => [503]]],
            $this->contradictions([[ContactRateLimitExceededException::class => 503]], $quota),
        );
        self::assertSame([], $this->contradictions([[ContactRateLimitExceededException::class => 429]], $quota));
        self::assertSame([], $this->contradictions([[]], $quota));
    }

    /**
     * @param list<array<string, int>>                         $mappings
     * @param iterable<class-string<ProblemExceptionInterface>> $problems
     *
     * @return array<string, array{'getStatus()': int, exception_to_status: list<int>}>
     */
    private function contradictions(array $mappings, iterable $problems): array
    {
        $found = [];
        foreach ($problems as $class) {
            // Instanciée sans son constructeur, comme dans les autres
            // garde-fous : getStatus() ne dépend que de la classe.
            $declared = (new ReflectionClass($class))->newInstanceWithoutConstructor()->getStatus();
            if (null === $declared) {
                continue;
            }

            $contradicting = array_values(array_diff(CompiledExceptionConfig::statusesFor($mappings, $class), [$declared]));
            if ([] !== $contradicting) {
                $found[$class] = ['getStatus()' => $declared, 'exception_to_status' => $contradicting];
            }
        }

        return $found;
    }
}
