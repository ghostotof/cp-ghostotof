<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Jwt;

use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use App\Security\User\Domain\ValueObject\BaseAccessUser;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * #748 (A3-9) : deux pods dont les horloges divergent de quelques secondes ne doivent pas
 * transformer un jeton légitime en 401 — et, depuis F1, un 401 efface les deux cookies : la session
 * entière, pas seulement la requête. `lexik_jwt_authentication.clock_skew` vaut donc 5 secondes
 * (décision de l'auteur du 2026-10-29), et pas davantage : il relâche **aussi** l'échéance, un jeton
 * expiré étant servi jusqu'à cinq secondes de plus.
 *
 * Ce que le réglage touche, relu dans Lexik 3.2 (`LoadedJWS`, `LcobucciJWSProvider::verify()`) :
 * `iat` (refusé s'il est en avance de plus que la tolérance) et `exp` (expiré une fois la tolérance
 * écoulée) ; `nbf`, que ce site ne pose pas, suivrait la même règle. `CredentialsVersionClaim`
 * (`cv`) ne lit aucune date, et le consentement MCP lit `iat` pour ses dix minutes de fraîcheur :
 * un `iat` jusqu'à cinq secondes dans le futur y donne un âge négatif, donc « récent » — sans
 * conséquence, c'est la tolérance même.
 *
 * Le jeton est celui d'un accès de base (aucun compte à créer), signé par le gestionnaire du
 * conteneur — la clé du site, jamais une clé écrite ici — ; la route est `GET /api/me`, qu'il
 * traverse jusqu'à son 403 de palier (authentifié, insuffisant). Un 401 est le jeton refusé.
 */
final class JwtClockSkewTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
    }

    public function testTheConfiguredSkewIsFiveSeconds(): void
    {
        self::assertSame(5, self::getContainer()->getParameter('lexik_jwt_authentication.clock_skew'));
    }

    public function testAnIssuedAtThreeSecondsAheadIsServed(): void
    {
        $this->callWithAToken(issuedAt: time() + 3, expiresAt: time() + 900);

        self::assertResponseStatusCodeSame(403, 'Authentifié au palier de base, pas un 401.');
    }

    public function testAnIssuedAtThirtySecondsAheadIsRefused(): void
    {
        $this->callWithAToken(issuedAt: time() + 30, expiresAt: time() + 900);

        self::assertResponseStatusCodeSame(401);
    }

    public function testATokenExpiredThreeSecondsAgoIsServed(): void
    {
        $this->callWithAToken(issuedAt: time() - 900, expiresAt: time() - 3);

        self::assertResponseStatusCodeSame(403, 'Dans la tolérance : servi au palier de base.');
    }

    public function testATokenExpiredThirtySecondsAgoIsRefused(): void
    {
        $this->callWithAToken(issuedAt: time() - 900, expiresAt: time() - 30);

        self::assertResponseStatusCodeSame(401);
    }

    /** Pose un accès de base aux instants donnés dans le cookie `BEARER`, puis appelle `GET /api/me`. */
    private function callWithAToken(int $issuedAt, int $expiresAt): void
    {
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->createFromPayload(
            new BaseAccessUser('guest-'.bin2hex(random_bytes(8))),
            [BaseAccessUser::TOKEN_CLAIM => true, 'iat' => $issuedAt, 'exp' => $expiresAt],
        );

        $this->client->getCookieJar()->set(new Cookie(AuthCookieFactory::BEARER, $jwt, null, '/', 'localhost', false, true, false, 'lax'));
        $this->client->request('GET', '/api/me');
    }
}
