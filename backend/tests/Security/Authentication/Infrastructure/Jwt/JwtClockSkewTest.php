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
 *
 * **Les marges (CR4-10, 4e relecture).** Les vérifications lisent l'horloge réelle : `LoadedJWS`
 * (`checkExpiration()`, `checkIssuedAt()`) compare à la seconde entière, `LooseValidAt` de lcobucci
 * à la microseconde. Un instant écrit avec `time()` avant la requête peut donc avoir vieilli de
 * plusieurs secondes sur une CI chargée, et un cas posé trop près de la tolérance devient instable.
 * Chaque cas garde au moins **4 secondes** entre son instant et la frontière qu'il pique :
 * - « servi » : `exp = time() - 1` reste servi tant que la requête part dans les 4 s qui suivent
 *   la signature (tolérance 5 s) ; `iat = time() + 1` est servi quoi qu'il arrive, l'avance ne fait
 *   que fondre ;
 * - « refusé » : `exp = time() - 7` est refusé pour toujours (7 > 5, le temps ne fait que
 *   l'aggraver) ; `iat = time() + 9` est refusé tant que moins de 4 s ont passé (9 - 4 = 5).
 * Ces deux bornes tiennent la tolérance **sous 7 s côté `exp` et sous 9 s côté `iat`** : un
 * `clock_skew` relevé à 10 les fait rougir. Elles ne disent pas la valeur exacte, que
 * {@see self::testTheConfiguredSkewIsFiveSeconds()} épingle seul.
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

    public function testAnIssuedAtOneSecondAheadIsServed(): void
    {
        $this->callWithAToken(issuedAt: time() + 1, expiresAt: time() + 900);

        self::assertResponseStatusCodeSame(403, 'Authentifié au palier de base, pas un 401.');
    }

    /** Refusé tant que moins de 4 s ont passé depuis la signature : la tolérance est sous 9 s. */
    public function testAnIssuedAtNineSecondsAheadIsRefused(): void
    {
        $this->callWithAToken(issuedAt: time() + 9, expiresAt: time() + 900);

        self::assertResponseStatusCodeSame(401);
    }

    public function testATokenExpiredOneSecondAgoIsServed(): void
    {
        $this->callWithAToken(issuedAt: time() - 900, expiresAt: time() - 1);

        self::assertResponseStatusCodeSame(403, 'Dans la tolérance : servi au palier de base.');
    }

    /** Refusé pour toujours : 7 s dépassent la tolérance, et le temps qui passe ne la rattrape pas. */
    public function testATokenExpiredSevenSecondsAgoIsRefused(): void
    {
        $this->callWithAToken(issuedAt: time() - 900, expiresAt: time() - 7);

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
