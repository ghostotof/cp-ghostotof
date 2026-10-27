<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Domain\Exception;

use App\Mcp\Connection\Domain\Exception\AuthorizationCodeReplayedException;
use App\Mcp\Connection\Domain\Exception\CompromisedMcpConnectionException;
use App\Mcp\Connection\Domain\Exception\RotatedRefreshTokenReusedException;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Tests\Support\DeclaredClasses;
use App\Tests\Support\RepositoryFiles;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Ce qu'une connexion compromise porte au point de jeton (#649), et ce
 * qu'elle ne doit jamais être : league entoure la validation du code
 * d'autorisation (`AuthCodeGrant::respondToAccessTokenRequest()`) d'un
 * `catch (InvalidArgumentException)` et d'un `catch (LogicException)` qui
 * les changent en refus ordinaires — une exception de l'une de ces familles
 * n'en sortirait pas, et la connexion resterait active (mesuré : sous
 * DomainException, le rejeu d'un code rendait un `invalid_request` « Issue
 * decrypting the authorization code », rien révoqué).
 */
final class CompromisedMcpConnectionExceptionTest extends TestCase
{
    /**
     * @return iterable<string, array{CompromisedMcpConnectionException, RevocationCause}>
     */
    public static function compromises(): iterable
    {
        $connectionId = Uuid::v7();

        yield 'rafraîchissement réutilisé' => [RotatedRefreshTokenReusedException::forConnection($connectionId), RevocationCause::RefreshTokenReuse];
        yield 'code rejoué' => [AuthorizationCodeReplayedException::forConnection($connectionId), RevocationCause::CodeReplay];
    }

    #[DataProvider('compromises')]
    public function testItCarriesTheConnectionAndItsCauseAndEscapesLeaguesCatches(CompromisedMcpConnectionException $compromise, RevocationCause $cause): void
    {
        self::assertSame($cause, $compromise->revocationCause());
        self::assertStringNotContainsString($compromise->connectionId()->toRfc4122(), $compromise->getMessage());
        self::assertStringNotContainsString($cause->value, $compromise->getMessage());

        // Lue par réflexion : écrite sur le type, l'assertion serait jugée
        // toujours vraie par PHPStan, et ne garderait rien.
        $ancestors = $this->ancestorsOf($compromise);
        self::assertContains(RuntimeException::class, $ancestors);
        self::assertNotContains(LogicException::class, $ancestors);
        self::assertNotContains(InvalidArgumentException::class, $ancestors);
    }

    /**
     * Le point de jeton choisit le refus de league par le type de la
     * compromission (`TokenEndpoint::refusalOf()`, lot C des relectures de
     * #649), sans bras par défaut : les sous-classes de `src/` sont exactement
     * celles de {@see self::compromises()}, chacune avec son refus. Une
     * nouvelle fait échouer ce test tant que le point de jeton et ce
     * fournisseur ne la connaissent pas — sinon un `UnhandledMatchError`
     * après la révocation, un 500.
     */
    public function testTheTokenEndpointKnowsEveryCompromise(): void
    {
        self::assertSame(
            [AuthorizationCodeReplayedException::class, RotatedRefreshTokenReusedException::class],
            DeclaredClasses::implementing(RepositoryFiles::backend('src'), CompromisedMcpConnectionException::class),
        );
    }

    /**
     * Les classes dont l'objet hérite, du parent direct à la racine.
     *
     * @return list<class-string>
     */
    private function ancestorsOf(object $object): array
    {
        $ancestors = [];
        $class = new ReflectionClass($object);

        while (false !== $parent = $class->getParentClass()) {
            $ancestors[] = $parent->getName();
            $class = $parent;
        }

        return $ancestors;
    }
}
