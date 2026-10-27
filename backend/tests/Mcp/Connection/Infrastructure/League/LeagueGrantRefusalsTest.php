<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Application\InvalidTarget;
use App\Mcp\Connection\Domain\ValueObject\GrantRefusal;
use App\Mcp\Connection\Infrastructure\League\LeagueGrantRefusals;
use League\OAuth2\Server\Exception\OAuthServerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le classement d'un refus de league (#725). Chaque cause tirée d'une
 * indication de league est produite par le vrai league dans
 * `GrantRefusalAuditTest` ; ici, ce que le flux HTTP ne produit pas : le
 * repli, qui garde tout refus d'un artefact authentique au journal, et les
 * deux types d'erreur classés sans leur indication.
 */
final class LeagueGrantRefusalsTest extends TestCase
{
    /**
     * @return iterable<string, array{OAuthServerException}>
     */
    public static function refusalsLeagueDoesNotNameForUs(): iterable
    {
        yield 'panne du serveur' => [OAuthServerException::serverError('panne')];
        yield 'invalid_grant d\'une indication inconnue' => [OAuthServerException::invalidGrant('Une indication que league 9.4.1 n\'écrit pas')];
        yield 'invalid_request d\'un autre paramètre' => [OAuthServerException::invalidRequest('code')];
        yield 'invalid_grant sans indication' => [OAuthServerException::invalidGrant()];
    }

    #[DataProvider('refusalsLeagueDoesNotNameForUs')]
    public function testAnyOtherRefusalIsUnrecognisedNeverLost(OAuthServerException $refusal): void
    {
        self::assertSame(GrantRefusal::Unrecognised, LeagueGrantRefusals::causeOf($refusal));
    }

    public function testAnInvalidScopeIsClassifiedByItsTypeWhateverTheScope(): void
    {
        self::assertSame(GrantRefusal::InvalidScope, LeagueGrantRefusals::causeOf(OAuthServerException::invalidScope('n\'importe:lequel')));
    }

    public function testAForeignResourceIsClassifiedByItsType(): void
    {
        self::assertSame(GrantRefusal::ForeignResource, LeagueGrantRefusals::causeOf(InvalidTarget::refusal()));
    }
}
