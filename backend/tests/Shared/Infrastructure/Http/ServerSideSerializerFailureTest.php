<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Tests\Support\ReadsAllChannelsLog;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Level;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Ce que le Serializer refuse **en sortie** est un défaut du serveur, jamais
 * une erreur du client (issue #360).
 *
 * Depuis l'issue #355, un corps de requête illisible sort en
 * MalformedRequestBodyException (400, `info`) : MalformedRequestBodyTest
 * épingle cette frontière-là. L'entrée large
 * `Serializer\ExceptionInterface` d'`exception_to_status` ne voyait plus que
 * des défauts serveur, et les rendait pourtant en **400** tout en les
 * journalisant en `critical`. Le visiteur recevait un `detail` qui accusait
 * sa requête, et nginx, les smoke tests et l'audit comptaient une panne
 * comme une erreur client.
 *
 * Le cas reproduit ici est une valeur non finie en base : un `NaN` dans une
 * colonne `double precision`, que PostgreSQL accepte et que `json_encode`
 * refuse (`Infinity` aussi, issue #372 — qui l'a depuis exclu par une
 * contrainte CHECK, que ce test lève le temps d'un cas). Une chaîne UTF-8 invalide, l'autre
 * exemple de l'issue, n'atteint jamais la base : elle est encodée en `UTF8`
 * et la refuse dès l'écriture.
 */
final class ServerSideSerializerFailureTest extends WebTestCase
{
    use ReadsAllChannelsLog;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        // Annule la ligne `NaN` et rend la contrainte levée pour l'écrire,
        // cf. clientWithANonEncodableTechnology().
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $connection->executeStatement('DELETE FROM experience_technology');
        parent::tearDown();
    }

    /**
     * Route publique, appelée sans le moindre identifiant : c'est
     * précisément là qu'un 400 trompe le plus, puisque le visiteur n'a rien
     * envoyé d'autre qu'un GET.
     */
    public function testANonEncodableResponseAnswers500(): void
    {
        $client = $this->clientWithANonEncodableTechnology();

        $client->request('GET', '/api/experience/technologies');

        self::assertSame(500, $client->getResponse()->getStatusCode());
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(500, $body['status'] ?? null);
    }

    /**
     * Le niveau, lui, était déjà le bon : aucun `log_level` ne vise l'entrée
     * large (ExceptionLogLevelCoverageTest::EXEMPT). Ce test garde le statut
     * et le niveau d'accord, et rougirait si quelqu'un « réparait » la
     * contradiction en baissant le niveau plutôt qu'en relevant le statut.
     */
    public function testANonEncodableResponseStaysLoggedAsCritical(): void
    {
        $client = $this->clientWithANonEncodableTechnology();

        $client->request('GET', '/api/experience/technologies');

        $uncaught = self::uncaughtExceptionRecords();
        self::assertCount(1, $uncaught);
        self::assertSame(Level::Critical, $uncaught[0]->level, $uncaught[0]->message);
        self::assertStringContainsString('NotEncodableValueException', $uncaught[0]->message);
    }

    /**
     * Ce que voit un visiteur en production, où `kernel.debug` est faux : un
     * document RFC 7807 générique, sans le message de l'encodeur ni la pile.
     * Seul le statut 5xx le garantit — API Platform ne masque le `detail` que
     * des erreurs serveur hors debug ; sous l'ancien 400, « Inf and NaN cannot
     * be JSON encoded » partait chez le visiteur anonyme. Le client est monté
     * sans debug expressément, l'environnement `test` le laissant actif.
     */
    public function testWithoutDebugTheServerFaultCarriesNoInternals(): void
    {
        $client = $this->clientWithANonEncodableTechnology(['debug' => false]);

        $client->request('GET', '/api/experience/technologies');

        self::assertSame(500, $client->getResponse()->getStatusCode());
        $raw =(string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('NaN', $raw);
        self::assertStringNotContainsString('trace', $raw);
        self::assertStringNotContainsString('/var/www', $raw);
        self::assertStringNotContainsString('Serializer', $raw);
    }

    /**
     * Le point d'entrée Hydra ne sait parler que `jsonld`, `jsonhal`,
     * `jsonapi` ou `html` (ApiPlatformExtension, `entrypoint_formats`), aucun
     * de ceux que le projet déclare. Actif hors production, il répondait 400
     * `critical` à tout `GET /api` (UnsupportedFormatException), un défaut
     * serveur de plus sous l'entrée large. Coupé partout, il laisse la route
     * au routeur : un 404 ordinaire, sans exception non rattrapée `critical`.
     */
    public function testTheHydraEntrypointIsNotServed(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        $client->request('GET', '/api');

        self::assertSame(404, $client->getResponse()->getStatusCode());
        foreach (self::uncaughtExceptionRecords() as $record) {
            self::assertNotSame(Level::Critical, $record->level, $record->message);
        }
    }

    /**
     * Une technologie dont la durée vaut `NaN`, écrite en SQL : le JSON n'a
     * pas de littéral `NaN`, aucune requête ne peut l'apporter. Depuis #372,
     * la base elle-même la refuse (`chk_experience_technology_years`) : la
     * ligne ne s'écrit qu'en levant la contrainte, dans une transaction que
     * le tearDown annule — PostgreSQL rend le DDL transactionnel, elle revient
     * donc même si le test échoue. Le client lit sur la même connexion
     * (`disableReboot`), donc dans cette transaction. Ce que ce test épingle
     * reste vrai : si une valeur non encodable atteint un jour la sortie,
     * c'est un défaut serveur, et il sort en 500.
     *
     * @param array<string, mixed> $options options du noyau, cf. createClient()
     */
    private function clientWithANonEncodableTechnology(array $options = []): KernelBrowser
    {
        $client = self::createClient($options);
        $client->disableReboot();
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->beginTransaction();
        $connection->executeStatement('ALTER TABLE experience_technology DROP CONSTRAINT chk_experience_technology_years');
        $connection->executeStatement(
            "INSERT INTO experience_technology (id, name, years) VALUES (uuidv7(), 'PHP', 'NaN'::float8)",
        );

        return $client;
    }
}
