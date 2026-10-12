<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Presentation\ApiResource;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserAdministratorInterface;
use App\Security\User\Application\CpgUserInviterInterface;
use App\Security\User\Application\PendingInvitationPurgerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use App\Tests\Support\HttpJson;
use App\Tests\Support\InvitesUsers;
use App\Tests\Support\TestCredentials;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Parcours public de définition de mot de passe (A7 / D6 : le jeton voyage
 * dans le corps, jamais dans un chemin) :
 * - POST /api/account/password-setup/validate {token} ;
 * - POST /api/account/password-setup {token, password}.
 * Aucune authentification, aucun CSRF (endpoint public, exclu du
 * double-submit-cookie comme /api/contact). Rate-limité par IP.
 */
final class AccountPasswordSetupResourceTest extends WebTestCase
{
    use HttpJson;
    use InvitesUsers;

    private const string EMAIL = 'newcomer@example.com';
    private const string DERIVED_USERNAME = 'newcomer';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM password_setup_token');
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testValidateAnswers204ForAKnownUsableTokenWithoutAuthentication(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken();

        $this->postValidate($client, ['token' => $token]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $client->getResponse()->getContent());
    }

    /**
     * Valider n'est pas consommer : le jeton reste exploitable après autant de
     * validations qu'on veut (le frontend valide à l'affichage du formulaire).
     */
    public function testValidateDoesNotConsumeTheToken(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken();

        $this->postValidate($client, ['token' => $token]);
        self::assertResponseStatusCodeSame(204);

        $this->postSetup($client, ['token' => $token, 'password' => TestCredentials::variant('new')]);
        self::assertResponseStatusCodeSame(204);
    }

    public function testValidateReturns404ForAnUnknownToken(): void
    {
        $client = $this->freshClient();

        $this->postValidate($client, ['token' => bin2hex(random_bytes(32))]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testValidateReturns404ForAnExpiredToken(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken();
        $this->expireAllTokens();

        $this->postValidate($client, ['token' => $token]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testValidateReturns404ForAnAlreadyUsedToken(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken();

        $this->postSetup($client, ['token' => $token, 'password' => TestCredentials::variant('new')]);
        self::assertResponseStatusCodeSame(204);

        $this->postValidate($client, ['token' => $token]);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, string> $payload
     */
    #[DataProvider('payloadsWithoutAUsableToken')]
    public function testValidateReturns422WithoutAUsableTokenField(array $payload): void
    {
        $client = $this->freshClient();

        $this->postValidate($client, $payload);

        self::assertResponseStatusCodeSame(422);
    }

    public function testSetupSetsThePasswordActivatesTheAccountAndConsumesTheToken(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken();

        $this->postSetup($client, ['token' => $token, 'password' => TestCredentials::variant('new')]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $client->getResponse()->getContent());

        // Le compte est désormais utilisable avec l'identifiant dérivé.
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => self::DERIVED_USERNAME,
            'password' => TestCredentials::variant('new'),
        ]));
        self::assertResponseIsSuccessful();

        // Le jeton est consommé : rejouer le POST échoue comme tout lien
        // inutilisable (issue #362).
        $this->postSetup($client, ['token' => $token, 'password' => TestCredentials::variant('new')]);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Issue #519 : un compte activé ne garde aucun jeton utilisable (#458),
     * vérifié sur la base réelle et non plus par le seul mock de
     * `PasswordSetupServiceTest`. Un second lien encore valable sur le même
     * compte, tel qu'en laissaient deux handlers concurrents avant le verrou
     * de #458, est posé par le dépôt — aucun cas d'usage n'en produit plus.
     *
     * Seul le décompte de la table rougit si la purge disparaît de
     * `complete()` : le lien restant serait de toute façon refusé, par la
     * garde « compte déjà actif » de validate()/complete(). Les réponses
     * vérifient l'autre moitié du contrat, celle de #362 : ce lien purgé ne se
     * distingue pas d'un lien inconnu.
     */
    public function testActivationPurgesTheOtherUsableLinksOfTheAccount(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken();
        $otherToken = $this->issueAnotherUsableToken(self::EMAIL);
        self::assertSame(['unused' => 2, 'total' => 2], $this->tokenCountsOf(self::EMAIL));

        $this->postSetup($client, ['token' => $token, 'password' => TestCredentials::variant('new')]);
        self::assertResponseStatusCodeSame(204);

        // Le jeton consommé reste (un rejeu est journalisé `replayed`), aucun
        // autre ne survit.
        self::assertSame(['unused' => 0, 'total' => 1], $this->tokenCountsOf(self::EMAIL));

        $unknown = bin2hex(random_bytes(32));
        foreach (['validate', 'setup'] as $route) {
            $responses = [];
            foreach (['autre lien du compte' => $otherToken, 'inconnu' => $unknown] as $case => $link) {
                if ('validate' === $route) {
                    $this->postValidate($client, ['token' => $link]);
                } else {
                    $this->postSetup($client, ['token' => $link, 'password' => TestCredentials::variant('other')]);
                }
                $responses[$case] = $this->observableResponse($client);
            }

            self::assertSame(404, $responses['inconnu']['status'], $route);
            self::assertSame($responses['inconnu'], $responses['autre lien du compte'], \sprintf('Route %s : le lien purgé se distingue d\'un lien inconnu.', $route));
        }
    }

    public function testSetupReturns404ForAnUnknownToken(): void
    {
        $client = $this->freshClient();

        $this->postSetup($client, ['token' => bin2hex(random_bytes(32)), 'password' => TestCredentials::variant('new')]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testSetupReturns404ForAnExpiredToken(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken();
        $this->expireAllTokens();

        $this->postSetup($client, ['token' => $token, 'password' => TestCredentials::variant('new')]);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, string> $payload
     */
    #[DataProvider('payloadsWithoutAUsableToken')]
    public function testSetupReturns422WithoutAUsableTokenField(array $payload): void
    {
        $client = $this->freshClient();

        $this->postSetup($client, [...$payload, 'password' => TestCredentials::variant('new')]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function payloadsWithoutAUsableToken(): iterable
    {
        yield 'token absent' => [[]];
        yield 'token vide' => [['token' => '']];
        // Borne haute (Assert\Length max 255) : un jeton réel fait 64 caractères,
        // rien de légitime n'approche cette taille — inutile de le hacher.
        yield 'token démesuré' => [['token' => str_repeat('a', 256)]];
    }

    public function testSetupReturns422ForATooShortPassword(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken();

        $this->postSetup($client, ['token' => $token, 'password' => 'short']);

        self::assertResponseStatusCodeSame(422);

        // Le refus n'a rien consommé : le jeton reste exploitable.
        $this->postValidate($client, ['token' => $token]);
        self::assertResponseStatusCodeSame(204);
    }

    /**
     * Issue #386 : la borne haute est celle du hasher, en octets. Un mot de
     * passe multioctet sous 4 096 caractères mais au-delà de 4 096 octets
     * passait la validation et faisait lever le hasher : un 500.
     */
    public function testSetupReturns422ForAMultibytePasswordBeyondTheHasherLimitInBytes(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken();

        $this->postSetup($client, ['token' => $token, 'password' => str_repeat('é', intdiv(CpgUser::MAX_PASSWORD_LENGTH, 2) + 1)]);

        self::assertResponseStatusCodeSame(422);

        $this->postValidate($client, ['token' => $token]);
        self::assertResponseStatusCodeSame(204);
    }

    /**
     * A7 / D6 : l'ancien contrat (jeton dans le chemin) a disparu, il n'en
     * reste ni lecture ni écriture. 404 = aucune route ; 405 = le chemin
     * existe pour une autre méthode — les deux prouvent qu'il n'est plus servi.
     */
    public function testTheLegacyTokenInPathContractIsGone(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken();

        $client->request('GET', '/api/account/password-setup/'.$token);
        self::assertContains($client->getResponse()->getStatusCode(), [404, 405]);

        $client->request('POST', '/api/account/password-setup/'.$token, server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['password' => TestCredentials::variant('new')]));
        self::assertContains($client->getResponse()->getStatusCode(), [404, 405]);

        // Et l'ancien POST n'a rien activé : le jeton est toujours exploitable.
        $this->postValidate($client, ['token' => $token]);
        self::assertResponseStatusCodeSame(204);
    }

    /**
     * Le listener de quota compare des chemins exacts ; le routeur ne sert pas
     * la variante à barre oblique finale d'une route POST (pas de redirection
     * hors GET/HEAD). Ce test épingle cette cohérence : le jour où le routeur
     * servirait `…/password-setup/`, le quota n'y serait pas appliqué.
     */
    public function testTrailingSlashVariantsAreNotServed(): void
    {
        $client = $this->freshClient();
        $token = $this->inviteAndCollectToken();

        $client->request('POST', '/api/account/password-setup/', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['token' => $token, 'password' => TestCredentials::variant('new')]));
        self::assertContains($client->getResponse()->getStatusCode(), [404, 405]);

        $client->request('POST', '/api/account/password-setup/validate/', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['token' => $token]));
        self::assertContains($client->getResponse()->getStatusCode(), [404, 405]);
    }

    public function testRequestsBeyondTheRateLimitReturn429WithRetryAfter(): void
    {
        $client = $this->freshClient();
        $unknown = bin2hex(random_bytes(32));

        // Limite : 10/heure (cf. rate_limiter.yaml). Les 10 premières passent
        // (404 ici), la 11e est rejetée avant même la validation du jeton.
        for ($i = 0; $i < 10; ++$i) {
            $this->postValidate($client, ['token' => $unknown]);
            self::assertResponseStatusCodeSame(404);
        }

        $this->postValidate($client, ['token' => $unknown]);
        $this->assertRateLimitedProblem($client);
    }

    /**
     * Audit C1 / décision D1 : le quota est consommé dans un listener
     * kernel.request, donc AVANT la désérialisation et la validation du corps.
     * Le 11e POST est rejeté en 429 même avec un corps volontairement invalide
     * (ni jeton ni mot de passe valable) : ni la validation (a fortiori l'appel
     * HIBP) ni la résolution du jeton ne sont atteintes.
     */
    public function testSetupBeyondTheRateLimitReturns429BeforeBodyValidation(): void
    {
        $client = $this->freshClient();
        $unknown = bin2hex(random_bytes(32));

        for ($i = 0; $i < 10; ++$i) {
            $this->postSetup($client, ['token' => $unknown, 'password' => TestCredentials::variant('new')]);
            self::assertResponseStatusCodeSame(404);
        }

        $this->postSetup($client, ['password' => 'short']);

        $this->assertRateLimitedProblem($client);
    }

    /**
     * Un corps invalide (422) consomme le quota comme un autre : sonder
     * l'endpoint avec des corps vides n'est pas gratuit.
     */
    public function testAnInvalidBodyConsumesTheQuotaToo(): void
    {
        $client = $this->freshClient();

        for ($i = 0; $i < 10; ++$i) {
            $this->postValidate($client, []);
            self::assertResponseStatusCodeSame(422);
        }

        $this->postValidate($client, []);
        self::assertResponseStatusCodeSame(429);
    }

    /**
     * Les deux routes partagent le même compteur par IP : 5 + 5 atteignent la
     * limite de 10, le 11e appel (quelle que soit la route) est rejeté.
     */
    public function testValidateAndSetupShareTheSameRateLimitQuota(): void
    {
        $client = $this->freshClient();
        $unknown = bin2hex(random_bytes(32));

        for ($i = 0; $i < 5; ++$i) {
            $this->postValidate($client, ['token' => $unknown]);
            self::assertResponseStatusCodeSame(404);
        }
        for ($i = 0; $i < 5; ++$i) {
            $this->postSetup($client, ['token' => $unknown, 'password' => TestCredentials::variant('new')]);
            self::assertResponseStatusCodeSame(404);
        }

        $this->postValidate($client, ['token' => $unknown]);
        self::assertResponseStatusCodeSame(429);

        $this->postSetup($client, ['token' => $unknown, 'password' => TestCredentials::variant('new')]);
        self::assertResponseStatusCodeSame(429);
    }

    /**
     * Régression issue #77 : `%2D` = '-'. Le routeur sert `password%2Dsetup`
     * comme `password-setup`, le quota par IP doit s'appliquer à l'identique —
     * sur les deux routes.
     */
    public function testPercentEncodedPathDoesNotBypassTheRateLimiter(): void
    {
        $client = $this->freshClient();
        $unknown = bin2hex(random_bytes(32));
        $server = ['CONTENT_TYPE' => 'application/json'];

        for ($i = 0; $i < 5; ++$i) {
            $client->request('POST', '/api/account/password%2Dsetup', server: $server, content: self::jsonBody(['token' => $unknown, 'password' => TestCredentials::variant('new')]));
            self::assertResponseStatusCodeSame(404);
        }
        for ($i = 0; $i < 5; ++$i) {
            $client->request('POST', '/api/account/password%2Dsetup/valid%61te', server: $server, content: self::jsonBody(['token' => $unknown]));
            self::assertResponseStatusCodeSame(404);
        }

        $client->request('POST', '/api/account/password%2Dsetup', server: $server, content: self::jsonBody(['token' => $unknown, 'password' => TestCredentials::variant('new')]));
        self::assertResponseStatusCodeSame(429);

        $client->request('POST', '/api/account/password%2Dsetup/valid%61te', server: $server, content: self::jsonBody(['token' => $unknown]));
        self::assertResponseStatusCodeSame(429);
    }

    /**
     * Régression issue #362 : la réponse à un lien inutilisable ne doit rien
     * dire de ce qu'il est devenu. 404 (inconnu, ou supprimé par une relance
     * ou la purge) et 410 (expiré ou déjà utilisé) se distinguaient, et un
     * jeton consommé étant conservé indéfiniment, passé le délai de purge un
     * 410 voulait dire « ce compte a été activé ». Statut, type, title et
     * detail sont désormais identiques, sur les deux routes.
     *
     * @param 'validate'|'setup' $route
     */
    #[DataProvider('bothRoutes')]
    public function testEveryUnusableLinkGetsTheSameResponse(string $route): void
    {
        $client = $this->freshClient();
        $links = $this->unusableLinks($client);
        // Chaque route a son lot d'appels, un par lien : le quota (10/h,
        // partagé par les deux routes) repart de zéro, il n'est pas l'objet de
        // ce test — au-delà de dix liens, scinder le lot.
        self::getContainer()->get('cache.rate_limiter')->clear();

        $responses = [];
        foreach ($links as $case => $token) {
            if ('validate' === $route) {
                $this->postValidate($client, ['token' => $token]);
            } else {
                $this->postSetup($client, ['token' => $token, 'password' => TestCredentials::variant('new')]);
            }
            $responses[$case] = $this->observableResponse($client);
        }

        self::assertSame(404, $responses['inconnu']['status']);
        foreach ($responses as $case => $response) {
            self::assertSame($responses['inconnu'], $response, \sprintf('Le lien « %s » se distingue d\'un lien inconnu.', $case));
        }
    }

    /**
     * @return iterable<string, array{'validate'|'setup'}>
     */
    public static function bothRoutes(): iterable
    {
        yield 'vérification' => ['validate'];
        yield 'définition' => ['setup'];
    }

    /**
     * Un lien de chaque devenir possible, état réel de la base, par les cas
     * d'usage du dépôt (purge et relance comprises) plutôt que par du SQL.
     *
     * @return array<string, string>
     */
    private function unusableLinks(KernelBrowser $client): array
    {
        $links = ['inconnu' => bin2hex(random_bytes(32))];

        // Expiré : 48 h passées, compte toujours en attente.
        $links['expiré'] = $this->inviteAndCollectSetupToken('expired@example.com', Locale::FR);
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement("UPDATE password_setup_token SET expires_at = '2000-01-01 00:00:00+00' WHERE user_id = (SELECT id FROM cpg_user WHERE email = 'expired@example.com')");

        // Déjà utilisé : le compte a été activé par ce lien, jeton conservé.
        $links['déjà utilisé'] = $this->inviteAndCollectSetupToken('activated@example.com', Locale::FR);
        $this->postSetup($client, ['token' => $links['déjà utilisé'], 'password' => TestCredentials::variant('new')]);
        self::assertResponseStatusCodeSame(204);

        // Remplacé : une relance a émis un nouveau lien.
        $links['remplacé par une relance'] = $this->inviteAndCollectSetupToken('resent@example.com', Locale::FR);
        $resent = self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByEmail('resent@example.com');
        self::assertNotNull($resent);
        self::getContainer()->get(CpgUserInviterInterface::class)->reinvite($resent, Locale::FR);
        $this->runInvitationHandlerAndCollectSetupToken();

        // Révoqué : un ROLE_SUPER a défini le mot de passe depuis le
        // backoffice, ce qui tue le lien en circulation (#460). Seul test réel
        // de cette révocation : sans `deleteUnusedForUser()` dans
        // `CpgUserAdministrator::changePassword()`, ce lien d'un compte encore
        // en attente répond 204 (mutation vérifiée dans #519).
        $links['révoqué par le backoffice'] = $this->inviteAndCollectSetupToken('revoked@example.com', Locale::FR);
        $revoked = self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByEmail('revoked@example.com');
        self::assertNotNull($revoked);
        self::getContainer()->get(CpgUserAdministratorInterface::class)->changePassword($revoked->getId(), TestCredentials::variant('admin'));
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        // Compte déjà actif, jeton encore utilisable : un reste d'avant #458,
        // que validate() comme complete() refusent.
        $links['compte déjà actif'] = $this->inviteAndCollectSetupToken('active@example.com', Locale::FR);
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement("UPDATE cpg_user SET activated_at = now() WHERE email = 'active@example.com'");

        // Purgé : invitation jamais activée au-delà de la rétention, le compte
        // et son jeton supprimés par la purge quotidienne.
        $links['purgé'] = $this->inviteAndCollectSetupToken('purged@example.com', Locale::FR);
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement("UPDATE cpg_user SET invited_at = now() - interval '40 days' WHERE email = 'purged@example.com'");
        $purge = self::getContainer()->get(PendingInvitationPurgerInterface::class)->purge(new DateInterval('P30D'));
        self::assertCount(1, $purge->purged);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $links;
    }

    /**
     * Ce qu'un appelant voit d'un refus : statut, tous les en-têtes et corps.
     * Seuls sont écartés ce qui varie d'une requête à l'autre sans rien dire
     * du lien (`Date`) et ce que le seul noyau de test, en debug, ajoute : la
     * `trace` du corps, qui nomme les lignes qui lèvent, et les en-têtes du
     * profileur — absents en production.
     *
     * @return array{status: int, headers: array<string, list<string|null>>, body: mixed}
     */
    private function observableResponse(KernelBrowser $client): array
    {
        $response = $client->getResponse();
        // Un lien resté utilisable répond 204 sans corps : le comparer, plutôt
        // que de lever au décodage, nomme le lien fautif (issue #519).
        $content = (string) $response->getContent();
        $body = '' === $content ? null : json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        if (\is_array($body)) {
            unset($body['trace']);
        }
        $headers = array_diff_key($response->headers->all(), array_flip(['date', 'x-debug-token', 'x-debug-token-link']));
        ksort($headers);

        return [
            'status' => $response->getStatusCode(),
            'headers' => $headers,
            'body' => $body,
        ];
    }

    /**
     * @param array<string, string> $payload
     */
    private function postValidate(KernelBrowser $client, array $payload): void
    {
        $client->request('POST', '/api/account/password-setup/validate', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody($payload));
    }

    /**
     * @param array<string, string> $payload
     */
    private function postSetup(KernelBrowser $client, array $payload): void
    {
        $client->request('POST', '/api/account/password-setup', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody($payload));
    }

    /**
     * Le refus de débit tel qu'un client le lit, sur chacune des deux
     * ressources du parcours (validation et définition) : 429, échéance
     * Retry-After, et le `type` de tout refus de débit, nginx compris
     * (issue #369) — pas le `/errors/429` qu'API Platform déduirait du seul
     * statut.
     */
    private function assertRateLimitedProblem(KernelBrowser $client): void
    {
        self::assertResponseStatusCodeSame(429);
        self::assertResponseHasHeader('Retry-After');
        self::assertGreaterThan(0, (int) $client->getResponse()->headers->get('Retry-After'));
        $problem = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame('/errors/rate-limited', $problem['type'] ?? null);
        self::assertSame(429, $problem['status'] ?? null);
    }

    private function freshClient(): KernelBrowser
    {
        $client = self::createClient();
        // Le quota est stocké dans le pool "cache.rate_limiter" (Doctrine DBAL
        // depuis l'ADR 0005, table cache_items de la base de test) et survit au
        // redémarrage de kernel (même IP 127.0.0.1 pour tous les tests
        // fonctionnels) : on repart d'un quota vierge à chaque test.
        self::getContainer()->get('cache.rate_limiter')->clear();

        return $client;
    }

    private function inviteAndCollectToken(): string
    {
        // Invite + exécute le handler + relit le jeton en clair dans l'e-mail
        // (il ne transite plus par le message Messenger, cf. audit C2 / D3).
        return $this->inviteAndCollectSetupToken(self::EMAIL, Locale::FR);
    }

    /**
     * Pose par le dépôt un second jeton utilisable sur le compte de l'adresse,
     * et renvoie sa valeur en clair (aléatoire, jamais un littéral).
     */
    private function issueAnotherUsableToken(string $email): string
    {
        $user = self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByEmail($email);
        self::assertNotNull($user);

        $clearToken = bin2hex(random_bytes(32));
        self::getContainer()->get(PasswordSetupTokenRepositoryInterface::class)->save(
            new PasswordSetupToken($user, hash('sha256', $clearToken), new DateTimeImmutable('+48 hours')),
        );
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $clearToken;
    }

    /**
     * Jetons du compte de l'adresse, lus dans la table : encore utilisables
     * (non consommés, échéance comprise ou non) et au total.
     *
     * @return array{unused: int, total: int}
     */
    private function tokenCountsOf(string $email): array
    {
        $row = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            'SELECT count(*) FILTER (WHERE t.used_at IS NULL) AS unused, count(*) AS total
               FROM password_setup_token t JOIN cpg_user u ON u.id = t.user_id
              WHERE u.email = :email',
            ['email' => $email],
        );
        self::assertIsArray($row);
        self::assertIsInt($row['unused']);
        self::assertIsInt($row['total']);

        return ['unused' => $row['unused'], 'total' => $row['total']];
    }

    private function expireAllTokens(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement("UPDATE password_setup_token SET expires_at = '2000-01-01 00:00:00+00'");
    }
}
