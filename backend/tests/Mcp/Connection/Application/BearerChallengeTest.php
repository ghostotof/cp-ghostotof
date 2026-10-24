<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\BearerChallenge;
use App\Mcp\Connection\Application\InvalidChallengeDescriptionException;
use App\Mcp\Connection\Application\OAuthEndpoints;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le défi `WWW-Authenticate` de `/api/mcp` (#648, RFC 6750 §3, RFC 9728 §5.1),
 * écrit en un seul endroit : sans jeton, sur un jeton refusé, et la forme que
 * l'outil nominatif rend dans `_meta["mcp/www_authenticate"]` (ADR 0006 D3).
 *
 * Les valeurs attendues sont littérales : un test qui recomposerait le défi
 * comme la classe le compose resterait vert sur un défi faux.
 */
final class BearerChallengeTest extends TestCase
{
    private const string METADATA = 'resource_metadata="https://site.example/.well-known/oauth-protected-resource/api/mcp"';

    public function testWithoutTokenTheChallengeNamesTheMetadataAndTheScope(): void
    {
        self::assertSame('Bearer '.self::METADATA.', scope="nominative:read"', $this->challenge()->missingToken());
    }

    /** RFC 6750 §3.1 : un jeton présenté et refusé ajoute `invalid_token`, ce qui fait rafraîchir le client. */
    public function testARefusedTokenAddsInvalidToken(): void
    {
        self::assertSame('Bearer '.self::METADATA.', scope="nominative:read", error="invalid_token"', $this->challenge()->refusedToken());
    }

    public function testAnInsufficientScopeCarriesItsDescription(): void
    {
        self::assertSame(
            'Bearer '.self::METADATA.', scope="nominative:read", error="insufficient_scope", error_description="Connect your account first."',
            $this->challenge()->insufficientScope('Connect your account first.'),
        );
    }

    /**
     * La base de l'API est celle d'`OAuthEndpoints`, barre finale retirée :
     * le défi ne recompose pas l'URL des métadonnées.
     */
    public function testTheMetadataUrlFollowsThePublicApiBase(): void
    {
        $challenge = new BearerChallenge(new OAuthEndpoints('http://localhost:8080/', 'http://localhost:5173'));

        self::assertSame(
            'Bearer resource_metadata="http://localhost:8080/.well-known/oauth-protected-resource/api/mcp", scope="nominative:read"',
            $challenge->missingToken(),
        );
    }

    /**
     * RFC 6749 A.2, que RFC 6750 §3 reprend : `error_description` ne contient
     * que `%x20-21 / %x23-5B / %x5D-7E`, au moins un caractère. Un guillemet ou
     * une barre oblique inverse fermerait ou échapperait la chaîne entre
     * guillemets (RFC 7235) ; un retour à la ligne couperait l'en-tête.
     */
    #[DataProvider('invalidDescriptions')]
    public function testADescriptionOutsideTheRfcSetIsRefused(string $description): void
    {
        $this->expectException(InvalidChallengeDescriptionException::class);
        $this->expectExceptionMessage('The error_description of a Bearer challenge must be 1 or more characters of %x20-21 / %x23-5B / %x5D-7E (RFC 6749 A.2).');

        $this->challenge()->insufficientScope($description);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDescriptions(): iterable
    {
        yield 'guillemet' => ['Say "connect"'];
        yield 'barre oblique inverse' => ['C:\\path'];
        yield 'retour à la ligne' => ["Connect\r\nX-Injected: 1"];
        yield 'tabulation' => ["Connect\tnow"];
        yield 'hors ASCII' => ['Connectez-vous d’abord'];
        yield 'vide' => [''];
    }

    private function challenge(): BearerChallenge
    {
        return new BearerChallenge(new OAuthEndpoints('https://site.example', 'https://site.example'));
    }
}
