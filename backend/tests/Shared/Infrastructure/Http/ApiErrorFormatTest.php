<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Audit A15 / décision D8 : sous `/api`, une erreur sort en JSON, jamais en
 * page HTML — y compris celles qu'API Platform ne voit pas passer (404 et 405
 * du routeur, refus de nos listeners sur les routes hors API Platform).
 */
final class ApiErrorFormatTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    public function testAnUnknownApiPathAnswers404InJson(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/inexistant');

        self::assertResponseStatusCodeSame(404);
        $this->assertJsonProblemBody($client);
    }

    /**
     * Régression issue #77 : `%61` = 'a'. Le routeur décode le chemin avant de
     * décider, donc `/%61pi/inexistant` est bien une 404 « sous /api » ; sans
     * CanonicalPath, un octet d'encodage suffirait à récupérer la page HTML.
     */
    public function testAPercentEncodedApiPathAnswers404InJsonToo(): void
    {
        $client = self::createClient();

        $client->request('GET', '/%61pi/inexistant');

        self::assertResponseStatusCodeSame(404);
        $this->assertJsonProblemBody($client);
    }

    /**
     * 405 : le chemin existe, la méthode non. Même origine que la 404 (le
     * routeur), donc même traitement.
     */
    public function testAWrongMethodOnAnExistingApiRouteAnswers405InJson(): void
    {
        $client = self::createClient();

        $client->request('DELETE', '/api/contact');

        self::assertResponseStatusCodeSame(405);
        $this->assertJsonProblemBody($client);
    }

    /**
     * Les refus de nos propres listeners kernel.request sur les routes **hors
     * API Platform** passaient par le rendu d'erreur de Symfony, donc en HTML.
     * `POST /api/logout` sans en-tête CSRF est le cas de référence.
     */
    public function testACsrfRejectionOnANonApiPlatformRouteAnswersInJson(): void
    {
        $client = self::createClient();

        $client->request('POST', '/api/logout');

        self::assertResponseStatusCodeSame(403);
        $this->assertJsonProblemBody($client);
    }

    /**
     * Hors `/api`, rien ne change : la page HTML reste la réponse attendue
     * pour un navigateur, et ce listener n'a pas à trancher pour le reste de
     * l'application. Le backend n'expose que `/api`, mais l'ancrage doit se
     * voir dans les tests, pas seulement dans le code.
     */
    public function testAPathOutsideTheApiIsUntouched(): void
    {
        $client = self::createClient();

        $client->request('GET', '/inexistant-hors-api');

        self::assertResponseStatusCodeSame(404);
        self::assertStringStartsWith('text/html', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    /**
     * Garde-fou contre la variante écartée de ce correctif. Poser le format
     * `json` sur *toute* requête d'API (un listener `kernel.request`, comme le
     * prévoyait la lettre de D8) fait échouer la négociation de contenu
     * d'API Platform quand l'appelant n'envoie pas d'en-tête `Accept` : les
     * opérations dont les formats n'incluent pas `application/json` — la
     * documentation et le point d'entrée Hydra, dont les formats sont
     * `application/vnd.openapi+json` et `text/html` — répondaient alors 406.
     *
     * Ces deux routes sont coupées en production (`enable_docs` /
     * `enable_entrypoint` dans `when@prod`), mais elles sont ici le seul
     * témoin disponible d'une classe de régression plus large : une future
     * ressource servie en CSV ou en PDF se casserait de la même façon.
     *
     * @param non-empty-string $path
     */
    #[DataProvider('pathsNegotiatingANonJsonFormat')]
    public function testContentNegotiationIsUntouchedWithoutAnAcceptHeader(string $path): void
    {
        $client = self::createClient();

        $client->request('GET', $path);

        self::assertNotSame(406, $client->getResponse()->getStatusCode(), $path);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function pathsNegotiatingANonJsonFormat(): iterable
    {
        yield 'documentation' => ['/api/docs'];
        yield 'point d\'entrée' => ['/api'];
    }

    /**
     * Ce que voit un visiteur en production : `kernel.debug` y est faux, et le
     * document RFC 7807 se réduit alors au strict nécessaire — pas de trace,
     * pas de nom de classe, pas de chemin de fichier, et un `detail` générique
     * qui ne cite plus l'URL demandée.
     *
     * Le client est monté sans debug expressément : l'environnement `test` le
     * laisse actif, si bien qu'une assertion posée sur le client par défaut
     * n'apprendrait rien sur la production.
     */
    public function testWithoutDebugTheErrorBodyCarriesNoInternals(): void
    {
        $client = self::createClient(['debug' => false]);

        $client->request('GET', '/api/inexistant');

        self::assertResponseStatusCodeSame(404);
        $body = $this->assertJsonProblemBody($client);

        self::assertArrayNotHasKey('trace', $body);
        self::assertArrayNotHasKey('class', $body);

        $raw = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('/var/www', $raw);
        self::assertStringNotContainsString('.php', $raw);
        self::assertStringNotContainsString('Symfony\\', $raw);
        self::assertStringNotContainsString('NotFoundHttpException', $raw);
    }

    /**
     * Le corps est un document RFC 7807 analysable, servi en JSON.
     *
     * L'en-tête est `application/json` et non `application/problem+json` : le
     * format de requête est ce qui pilote à la fois l'encodage et le type MIME
     * du rendu d'erreur de Symfony, et `jsonproblem` n'est pas un format de
     * requête connu de Symfony (le déclarer reviendrait à muter le registre
     * statique `Request::$formats` depuis un listener). Ce qui compte ici — du
     * JSON analysable plutôt qu'une page HTML — est acquis, et le document
     * lui-même porte bien `type`/`title`/`status`/`detail`. Les erreurs
     * qu'API Platform traite, elles, gardent leur `application/problem+json`.
     *
     * @return array<mixed>
     */
    private function assertJsonProblemBody(KernelBrowser $client): array
    {
        $response = $client->getResponse();
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));

        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertArrayHasKey('status', $body);
        self::assertArrayHasKey('title', $body);
        self::assertArrayHasKey('detail', $body);
        self::assertSame($response->getStatusCode(), $body['status']);

        return $body;
    }
}
