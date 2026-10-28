<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\ApiPlatform;

use App\Tests\Support\ApiPlatformDeprecations;
use App\Tests\Support\HttpJson;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Une requête par étage de la chaîne d'API Platform. Le statut attendu est
     * vérifié : une requête qui sortirait plus tôt (un quota, un 500) ne
     * traverserait pas ce que la garde veut voir.
     *
     * @return iterable<string, array{string, string, array<string, mixed>|null, int}>
     */
    public static function representativeRequests(): iterable
    {
        // Le cas qui a motivé la garde : corps JSON dénormalisé, validé, puis
        // posté sur le bus.
        yield 'écriture valide (POST /api/contact, 202)' => ['POST', '/api/contact', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'message' => 'Bonjour, je souhaite vous contacter pour un projet.',
        ], 202];

        // Même route, refusée par la validation : la violation est
        // normalisée par API Platform (ConstraintViolationListNormalizer).
        yield 'écriture refusée (POST /api/contact, 422)' => ['POST', '/api/contact', [
            'name' => 'Jane Doe',
            'email' => 'not-an-email',
            'message' => 'Bonjour, je souhaite vous contacter pour un projet.',
        ], 422];

        yield 'lecture publique, collection (GET /api/experience/technologies, 200)' => ['GET', '/api/experience/technologies', null, 200];
        yield 'lecture publique, ressource (GET /api/watch, 200)' => ['GET', '/api/watch', null, 200];

        // Le 404 d'un fournisseur d'API Platform (un jeton d'invitation
        // inconnu), rendu par son ErrorListener.
        yield 'erreur 404 (POST /api/account/password-setup/validate)' => ['POST', '/api/account/password-setup/validate', ['token' => '%unknown-token%'], 404];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('representativeRequests')]
    public function testTheRequestRaisesNoApiPlatformDeprecation(string $method, string $uri, ?array $body, int $expectedStatus): void
    {
        $client = self::createClient();
        // Les quotas (contact, jeton d'invitation) survivent à un redémarrage
        // du noyau : sans cela, l'ordre des tests déciderait du statut.
        self::getContainer()->get('cache.rate_limiter')->clear();
        $content = null === $body ? null : self::jsonBody(array_map(
            static fn (mixed $value): mixed => '%unknown-token%' === $value ? bin2hex(random_bytes(32)) : $value,
            $body,
        ));

        // Le gestionnaire n'est posé qu'après le démarrage du noyau, qui
        // installe le sien (FrameworkBundle), et pour une seule requête : un
        // second `request()` redémarrerait le noyau et l'écraserait.
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
}
