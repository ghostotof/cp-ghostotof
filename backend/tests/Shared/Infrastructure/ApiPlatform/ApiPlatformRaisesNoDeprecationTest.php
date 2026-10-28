<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\ApiPlatform;

use App\Mcp\Application\RoutePayloadReaderInterface;
use App\Portfolio\Contribution\Application\ContributionAdministratorInterface;
use App\Portfolio\Experience\Application\ExperienceTechnologyRegistrarInterface;
use App\Portfolio\Experience\Domain\ValueObject\ExperienceYears;
use App\Portfolio\Experience\Domain\ValueObject\TechnologyName;
use App\Portfolio\Experience\Presentation\ApiResource\ExperienceTechnologyResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\ApiPlatformDeprecations;
use App\Tests\Support\HttpJson;
use App\Tests\Support\TestCredentials;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Aucune route API Platform ne déclenche de dépréciation d'API Platform
 * (issue #701).
 *
 * Pendant des semaines, chaque `POST /api/contact` a écrit dans les journaux
 * de production « Calling "denormalize()" on "ItemNormalizer" is deprecated » :
 * le bundle 4.3 câblait encore `ItemNormalizer` pour dénormaliser alors que
 * les composants, montés en 4.4 par des contraintes trop larges, le
 * déprécient. La suite, elle, ne le disait pas : `phpunit.dist.xml` pose
 * `ignoreIndirectDeprecations` — avec raison, une dépréciation du vendor
 * appelé par le vendor n'est pas une affaire du projet —, ce qui rend aveugle
 * à celle-ci précisément. `phpunit.dist.xml` ne bouge pas : cette garde ne
 * regarde que ce qui vient d'API Platform (`ApiPlatformDeprecations`), sur une
 * poignée de requêtes qui traversent chaque étage — dénormalisation d'un
 * corps, validation, lecture publique, normalisation, erreur — et rougit en
 * citant la dépréciation et l'endroit d'où elle part.
 *
 * Elle reste ciblée : c'est une dépréciation vue, pas l'absence de toute
 * dépréciation possible. Une route absente de la liste ci-dessous n'est pas
 * gardée ; en ajouter une coûte une ligne.
 */
final class ApiPlatformRaisesNoDeprecationTest extends WebTestCase
{
    use HttpJson;

    /** Un corps de contact valide ; le cas refusé le surcharge d'un champ. */
    private const array VALID_CONTACT = [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'message' => 'Bonjour, je souhaite vous contacter pour un projet.',
    ];

    private const string SUPER_USERNAME = 'super';

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        foreach (['contribution', 'experience_technology', 'cpg_user'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
        parent::tearDown();
    }

    /**
     * Une requête par étage de la chaîne d'API Platform. Le statut attendu est
     * vérifié : une requête qui sortirait plus tôt (un quota, un 500) ne
     * traverserait pas ce que la garde veut voir.
     *
     * Le corps est un tableau, `null` (pas de corps), ou une fonction qui le
     * produit au moment du test : un jeton inconnu doit être tiré à chaque
     * passage, jamais figé dans le jeu de données.
     *
     * @return iterable<string, array{string, string, array<string, mixed>|Closure(): array<string, mixed>|null, int}>
     */
    public static function representativeRequests(): iterable
    {
        // Le cas qui a motivé la garde : corps JSON dénormalisé, validé, puis
        // posté sur le bus.
        yield 'écriture valide (POST /api/contact, 202)' => ['POST', '/api/contact', self::VALID_CONTACT, 202];

        // Même route, refusée par la validation : la violation est
        // normalisée par API Platform
        // (ApiPlatform\Serializer\ConstraintViolationListNormalizer).
        yield 'écriture refusée (POST /api/contact, 422)' => ['POST', '/api/contact', [...self::VALID_CONTACT, 'email' => 'not-an-email'], 422];

        yield 'lecture publique, collection (GET /api/experience/technologies, 200)' => ['GET', '/api/experience/technologies', null, 200];
        yield 'lecture publique, ressource (GET /api/watch, 200)' => ['GET', '/api/watch', null, 200];

        // Le 404 d'un fournisseur d'API Platform (un jeton d'invitation
        // inconnu), rendu par son ApiPlatform\Symfony\EventListener\ErrorListener.
        yield 'erreur 404 (POST /api/account/password-setup/validate)' => [
            'POST',
            '/api/account/password-setup/validate',
            static fn (): array => ['token' => bin2hex(random_bytes(32))],
            404,
        ];
    }

    /**
     * @param array<string, mixed>|Closure(): array<string, mixed>|null $body
     */
    #[DataProvider('representativeRequests')]
    public function testTheRequestRaisesNoApiPlatformDeprecation(string $method, string $uri, array|Closure|null $body, int $expectedStatus): void
    {
        $client = self::createClient();
        // Les quotas (contact, jeton d'invitation) survivent à un redémarrage
        // du noyau : sans cela, l'ordre des tests déciderait du statut.
        self::getContainer()->get('cache.rate_limiter')->clear();
        $content = null === $body ? null : self::jsonBody($body instanceof Closure ? $body() : $body);

        // Le gestionnaire entoure la seule requête observée. Il ne craint pas un
        // redémarrage du noyau, que le navigateur fait avant chaque requête sauf
        // la première : `FrameworkBundle::boot()` ne remplace pas un gestionnaire
        // d'erreurs qu'il n'a pas posé (avec symfony/runtime, il se borne à lire
        // `get_error_handler()` ; sans lui, `ErrorHandler::register(null, false)`
        // retire le sien aussitôt). Vérifié : le même gestionnaire est en place
        // après trois requêtes.
        $findings = ApiPlatformDeprecations::during(static function () use ($client, $method, $uri, $content): void {
            $client->request($method, $uri, server: null === $content ? [] : ['CONTENT_TYPE' => 'application/json'], content: $content);
        });

        self::assertSame($expectedStatus, $client->getResponse()->getStatusCode(), 'La requête n\'a pas traversé ce que la garde veut voir.');
        self::assertSame(
            [],
            $findings,
            "API Platform déprécie ce que cette requête emprunte :\n - ".implode("\n - ", $findings),
        );
    }

    /**
     * Une écriture authentifiée du backoffice sur un objet qui existe : le
     * `PUT` passe par le provider de lecture, la dénormalisation du corps
     * (`ItemDenormalizer`), le processeur, puis la normalisation de la
     * réponse. C'est le cas de tout le backoffice, que le `POST` du formulaire
     * de contact (objet neuf, sans connexion) ne couvre pas.
     *
     * Limite mesurée : en 4.4.6 un `PUT` « standard » ne peuple aucun objet
     * (`object_to_populate` absent), et `ItemNormalizerTrait::updateObjectToPopulate()`
     * n'est atteint que si le corps porte un `id` — que `api_allow_update: false`
     * refuse, `MalformedRequestBodyTest` y veille. Cette branche n'est donc pas
     * gardée ici.
     */
    public function testAnAuthenticatedBackofficeWriteOnAnExistingObjectRaisesNoApiPlatformDeprecation(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAsSuper($client);
        $contribution = $client->getContainer()->get(ContributionAdministratorInterface::class)->create(
            Locale::FR,
            'Retry de transport',
            'symfony/ai',
            'Issue #1688',
            'https://github.com/symfony/ai/issues/1688',
            'Résumé.',
            'Corps.',
        );
        $uri = \sprintf('/api/backoffice/contributions/%s', $contribution->getId()->toRfc4122());

        $findings = ApiPlatformDeprecations::during(static function () use ($client, $uri, $csrfToken): void {
            $client->request('PUT', $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfToken], content: self::jsonBody([
                'locale' => 'fr',
                'title' => 'Titre mis à jour',
                'project' => 'symfony/ai',
                'reference' => 'Issue #1688',
                'url' => 'https://github.com/symfony/ai/issues/1688',
                'summary' => 'Résumé mis à jour.',
                'body' => 'Corps mis à jour.',
                'position' => 1,
            ]));
        });

        self::assertSame(200, $client->getResponse()->getStatusCode(), 'La requête n\'a pas traversé ce que la garde veut voir.');
        self::assertSame([], $findings, "API Platform déprécie ce que cette écriture emprunte :\n - ".implode("\n - ", $findings));
    }

    /**
     * Le lecteur de routes du serveur MCP appelle le provider et le
     * sérialiseur d'API Platform **sans requête HTTP** : aucune des requêtes
     * ci-dessus ne l'emprunte, et un changement de ce sérialiseur (son
     * contexte, un normaliseur) ne se verrait qu'ici.
     */
    public function testTheMcpRoutePayloadReaderRaisesNoApiPlatformDeprecation(): void
    {
        self::bootKernel();
        $registrar = self::getContainer()->get(ExperienceTechnologyRegistrarInterface::class);
        $registrar->register(TechnologyName::fromString('Symfony'), ExperienceYears::fromFloat(8.5), 'symfony', 'PHP');
        $reader = self::getContainer()->get(RoutePayloadReaderInterface::class);
        $payload = [];

        $findings = ApiPlatformDeprecations::during(static function () use ($reader, &$payload): void {
            $payload = $reader->read(ExperienceTechnologyResource::class);
        });

        self::assertNotSame([], $payload, 'Le lecteur n\'a rien normalisé : la garde ne verrait rien.');
        self::assertSame([], $findings, "API Platform déprécie ce que le lecteur MCP emprunte :\n - ".implode("\n - ", $findings));
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
