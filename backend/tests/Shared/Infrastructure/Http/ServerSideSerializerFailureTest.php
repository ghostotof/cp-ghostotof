<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Tests\Support\ReadsAllChannelsLog;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Level;
use Monolog\LogRecord;
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
 * Le cas reproduit ici est le seul qu'une donnée en base peut provoquer :
 * un `NaN` dans une colonne `double precision`, que PostgreSQL accepte et que
 * `json_encode` refuse. Une chaîne UTF-8 invalide, l'autre exemple de
 * l'issue, n'atteint jamais la base : elle est encodée en `UTF8` et la
 * refuse dès l'écriture.
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
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM experience_technology');
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

        $uncaught = $this->uncaughtExceptionRecords();
        self::assertCount(1, $uncaught);
        self::assertSame(Level::Critical, $uncaught[0]->level, $uncaught[0]->message);
        self::assertStringContainsString('NotEncodableValueException', $uncaught[0]->message);
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
        foreach ($this->uncaughtExceptionRecords() as $record) {
            self::assertNotSame(Level::Critical, $record->level, $record->message);
        }
    }

    /**
     * Une technologie dont la durée vaut `NaN` : écrite en SQL, comme le
     * ferait une correction manuelle en base, puisque l'entité la refuse
     * (#[Assert\PositiveOrZero]) et que l'API ne l'accepterait pas.
     */
    private function clientWithANonEncodableTechnology(): KernelBrowser
    {
        $client = self::createClient();
        $client->disableReboot();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            "INSERT INTO experience_technology (id, name, years) VALUES (gen_random_uuid(), 'PHP', 'NaN'::float8)",
        );

        return $client;
    }

    /**
     * @return list<LogRecord>
     */
    private function uncaughtExceptionRecords(): array
    {
        return array_values(array_filter(
            self::allChannelsLogRecords(),
            static fn (LogRecord $record): bool => 'request' === $record->channel && str_starts_with($record->message, 'Uncaught PHP Exception'),
        ));
    }
}
