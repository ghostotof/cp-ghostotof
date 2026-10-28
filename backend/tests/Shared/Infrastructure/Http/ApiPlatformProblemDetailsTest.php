<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le `type`, le `title` et le statut des erreurs problem+json de l'API, un
 * statut après l'autre, chacun sur une route API Platform (issue #701).
 *
 * Le frontend, les smoke tests et l'audit lisent ces trois champs — le `type`
 * surtout, dont le client déduit le message à afficher. Une montée d'API
 * Platform les change sans que rien d'autre ne rougisse : leur forme vient de
 * son `ErrorListener` et de son normaliseur d'erreur, pas du projet. Les
 * autres tests épinglent le `type` d'une erreur du domaine (`/errors/<slug>`) ;
 * aucun ne le faisait pour les erreurs que le projet ne nomme pas — celles qu'API
 * Platform rend d'après le seul statut (`/errors/400`, `/errors/404`,
 * `/errors/415`, `title` « An error occurred ») — ni ne gardait le `title`.
 *
 * Valeurs relevées à l'identique sur le lock d'avant #701 (bundle 4.3.19,
 * composants 4.4.0) et sur la 4.4.6. Un changement voulu se lit ici, et se
 * note dans les notes de release : c'est un contrat que des clients lisent.
 *
 * Le 405 du routeur n'est pas rendu par API Platform — la route existe, la
 * méthode non, l'erreur naît avant lui —, mais par le `ProblemNormalizer` de
 * Symfony (`ApiJsonErrorFormatListener`) : d'où son `type` et son type MIME,
 * épinglés tels quels.
 */
final class ApiPlatformProblemDetailsTest extends WebTestCase
{
    use HttpJson;

    private const string SUPER_USERNAME = 'super';

    private const string PROBLEM_JSON = 'application/problem+json';

    private const string GENERIC_TITLE = 'An error occurred';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    /**
     * Un corps qui n'est pas du JSON : MalformedRequestBodyException, sans
     * `type` propre, donc celui qu'API Platform tire du statut.
     */
    public function testAnUnreadableBodyIsA400(): void
    {
        $client = $this->clientWithFreshQuotas();

        $client->request('POST', '/api/contact', server: ['CONTENT_TYPE' => 'application/json'], content: '{not json');

        $this->assertProblem($client, 400, '/errors/400', self::GENERIC_TITLE);
    }

    public function testAnUnknownPasswordSetupLinkIsA404(): void
    {
        $client = $this->clientWithFreshQuotas();

        $client->request('POST', '/api/account/password-setup/validate', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody(['token' => bin2hex(random_bytes(32))]));

        $this->assertProblem($client, 404, '/errors/404', self::GENERIC_TITLE);
    }

    public function testAWrongMethodOnAnApiPlatformRouteIsA405RenderedBySymfony(): void
    {
        $client = $this->clientWithFreshQuotas();

        $client->request('DELETE', '/api/contact');

        $this->assertProblem($client, 405, 'https://tools.ietf.org/html/rfc2616#section-10', self::GENERIC_TITLE, 'application/json');
    }

    /**
     * Le `type` et le `title` d'une erreur du domaine viennent de son
     * `ProblemExceptionInterface` : le slug, deux fois.
     */
    public function testAnExceptionOfTheDomainKeepsItsSlugAsTypeAndTitleOnA409(): void
    {
        $client = $this->clientWithFreshQuotas();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAsSuper($client);

        $client->request('GET', '/api/backoffice/users', server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        $users = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($users);
        self::assertIsArray($users[0] ?? null);
        self::assertIsString($users[0]['id'] ?? null);

        $client->request('PUT', \sprintf('/api/backoffice/users/%s/roles', $users[0]['id']), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['superAdmin' => false]));

        $this->assertProblem($client, 409, '/errors/cannot-modify-own-roles', 'cannot-modify-own-roles');
    }

    public function testAnUnsupportedContentTypeIsA415(): void
    {
        $client = $this->clientWithFreshQuotas();

        $client->request('POST', '/api/contact', server: ['CONTENT_TYPE' => 'text/plain'], content: 'hello');

        $this->assertProblem($client, 415, '/errors/415', self::GENERIC_TITLE);
    }

    /**
     * Une violation de validation : le `type` est celui de la contrainte
     * (`/validation_errors/<uuid>`), pas un `/errors/…` — et `violations` y
     * nomme le champ, ce que lisent les formulaires.
     */
    public function testAValidationFailureIsA422CarryingItsViolations(): void
    {
        $client = $this->clientWithFreshQuotas();

        $client->request('POST', '/api/contact', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'name' => 'Jane Doe',
            'email' => 'not-an-email',
            'message' => 'Bonjour, je souhaite vous contacter pour un projet.',
        ]));

        // L'UUID est celui de la contrainte `Email` de Symfony (INVALID_EMAIL_ERROR).
        $problem = $this->assertProblem($client, 422, '/validation_errors/bd79c0ab-ddba-46cc-a703-a7a4b08de310', self::GENERIC_TITLE);
        self::assertIsArray($problem['violations'] ?? null);
        self::assertSame('email', $problem['violations'][0]['propertyPath'] ?? null);
    }

    /**
     * Le `type` d'un refus de débit est le même partout (issue #369), nginx
     * compris ; le `title`, lui, est le slug.
     */
    public function testARateLimitedRequestIsA429(): void
    {
        $client = $this->clientWithFreshQuotas();
        $payload = self::jsonBody([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'message' => 'Bonjour, je souhaite vous contacter pour un projet.',
        ]);

        // Cinq par heure et par IP (limiteur `contact_form`).
        for ($i = 0; $i < 6; ++$i) {
            $client->request('POST', '/api/contact', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
        }

        $this->assertProblem($client, 429, '/errors/rate-limited', 'rate-limited');
    }

    /**
     * Statut du corps, `type` et `title` — le contrat —, plus un `detail` : le
     * message lisible est de la responsabilité de chaque erreur, sa présence
     * ne varie pas.
     *
     * @param non-empty-string $contentType
     *
     * @return array<mixed>
     */
    private function assertProblem(KernelBrowser $client, int $status, string $type, string $title, string $contentType = self::PROBLEM_JSON): array
    {
        $response = $client->getResponse();
        self::assertSame($status, $response->getStatusCode());
        self::assertStringStartsWith($contentType, (string) $response->headers->get('Content-Type'));

        $problem = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame($status, $problem['status'] ?? null, 'Le statut du corps.');
        self::assertSame($type, $problem['type'] ?? null, 'Le type du problème.');
        self::assertSame($title, $problem['title'] ?? null, 'Le titre du problème.');
        self::assertIsString($problem['detail'] ?? null);

        return $problem;
    }

    private function clientWithFreshQuotas(): KernelBrowser
    {
        $client = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();

        return $client;
    }

    private function loginAsSuper(KernelBrowser $client): string
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => self::SUPER_USERNAME,
            'password' => TestCredentials::superPassword(),
        ]));
        self::assertResponseIsSuccessful();

        $csrfCookie = $client->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($csrfCookie);

        return $csrfCookie->getValue();
    }
}
