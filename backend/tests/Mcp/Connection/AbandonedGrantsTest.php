<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection;

use App\Mcp\Connection\Domain\Entity\McpConnection;
use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use App\Tests\Mcp\Support\HoldsAnMcpConnection;
use App\Tests\Support\ReadsSecurityAuditLog;
use App\Tests\Support\TestCredentials;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les accords abandonnés (#730, piste 1) : une approbation dont le code n'est
 * jamais échangé écrit une connexion que rien ne remplace ni ne révoque — le
 * remplacement a lieu au premier échange d'un code (#726, D2), jamais à
 * l'approbation —, et qu'aucun usage ne tient en vie : sans cette échéance,
 * chaque approbation abandonnée restait active trente jours (l'inactivité), et
 * elles s'accumulaient.
 *
 * La connexion qui n'a pas échangé son code expire `connection_unexchanged`
 * après son octroi (une heure) ; celle qui l'a échangé ne connaît pas cette
 * échéance. Le parcours est le vrai (approbation par HTTP, échange au point de
 * jeton, `app:mcp:purge`) ; seul le temps est avancé, en reculant `granted_at`.
 */
final class AbandonedGrantsTest extends WebTestCase
{
    use HoldsAnMcpConnection;
    use ReadsSecurityAuditLog;

    private const string LIST_PATH = '/api/me/mcp-connections';

    protected function setUp(): void
    {
        $this->setUpOAuthFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownOAuthFlow();
        parent::tearDown();
    }

    /**
     * La reproduction de #730 : trois approbations du même compte au même
     * client, aucun code échangé — trois connexions actives (rien n'en
     * remplace aucune). Passée l'échéance des connexions jamais échangées, la
     * personne les voit `expired`, et la purge les marque `expired`, trois
     * lignes d'audit, acteur `system`.
     */
    public function testAbandonedApprovalsAccumulateThenExpire(): void
    {
        $this->loginAs(self::TRUSTED_USERNAME, ['ROLE_TRUSTED']);
        $this->codeGrantedByTheSignedInAccount();
        $this->codeGrantedByTheSignedInAccount();
        $this->codeGrantedByTheSignedInAccount();

        self::assertSame(['active', 'active', 'active'], $this->listedStates(), 'Trois approbations abandonnées : trois connexions actives.');
        self::assertSame(0, $this->countRows('SELECT count(*) FROM mcp_connection WHERE code_exchanged_at IS NOT NULL'), 'Un code jamais échangé ne note aucun échange.');

        $this->grantedLongerAgoThanTheUnexchangedLifetime();

        self::assertSame(['expired', 'expired', 'expired'], $this->listedStates(), 'Passée l\'échéance, la personne les voit échues.');

        $this->runThePurge();

        self::assertSame(3, $this->countRows("SELECT count(*) FROM mcp_connection WHERE revocation_cause = 'expired' AND revoked_at IS NOT NULL"));
        $lines = self::securityAuditEvents('mcp-connection-revoked');
        self::assertCount(3, $lines);
        foreach ($lines as $line) {
            self::assertSame('expired', $line['cause'] ?? null);
            self::assertSame('system', $line['actor'] ?? null);
        }
    }

    /**
     * Une connexion dont le code a été échangé ne connaît pas l'échéance des
     * non échangées : reculée de la même durée, elle reste active, la purge la
     * laisse, et son rafraîchissement est servi. Garde le premier échange :
     * c'est lui qui écrit `code_exchanged_at`.
     */
    public function testAnExchangedConnectionOutlivesTheUnexchangedLifetime(): void
    {
        $tokens = $this->json($this->exchange($this->grantedCode()));
        $refreshToken = $tokens['refresh_token'] ?? null;
        self::assertIsString($refreshToken);

        self::assertSame(1, $this->countRows('SELECT count(*) FROM mcp_connection WHERE code_exchanged_at IS NOT NULL'), 'Le premier échange du code n\'est pas noté.');

        $this->grantedLongerAgoThanTheUnexchangedLifetime();

        $this->runThePurge();
        self::assertSame(0, $this->countRows('SELECT count(*) FROM mcp_connection WHERE revoked_at IS NOT NULL'), 'La purge a marqué une connexion échangée.');
        self::assertSame(Response::HTTP_OK, $this->refresh($refreshToken)->getStatusCode());
        $this->signInAs(self::TRUSTED_USERNAME, TestCredentials::superPassword());
        self::assertSame(['active'], $this->listedStates());
    }

    /**
     * La garde de CR1 (#726) : la connexion dont le code est encore
     * échangeable — octroyée il y a la durée d'un code plus la minute de jeu —
     * n'est jamais présélectionnée par la purge ; la même, vieillie jusqu'à
     * l'échéance des non échangées, l'est (témoin : le test ne serait pas vide).
     */
    public function testACodeStillExchangeableIsNeverPreselected(): void
    {
        $this->grantedCode();
        $lifetimes = self::getContainer()->get(ConnectionLifetimes::class);
        $now = self::getContainer()->get(ClockInterface::class)->now();

        $this->grantedAgo($lifetimes->authorizationCode(), new DateInterval(ConnectionLifetimes::SECOND_TRUNCATION_AND_SKEW_MARGIN));
        self::assertSame(0, $this->preselectedAt($now), 'Un code encore échangeable est présélectionné : la purge pourrait verrouiller sa connexion que l\'échange tient.');

        $this->grantedLongerAgoThanTheUnexchangedLifetime();
        self::assertSame(1, $this->preselectedAt($now), 'Témoin : la connexion jamais échangée, échue, doit être présélectionnée.');
    }

    /** @return list<string> */
    private function listedStates(): array
    {
        $this->signInAs(self::TRUSTED_USERNAME, TestCredentials::superPassword());
        $this->client->request('GET', self::LIST_PATH, server: ['HTTP_ACCEPT' => 'application/json']);
        $connections = $this->json($this->client->getResponse())['connections'] ?? null;
        self::assertIsList($connections);

        $states = [];
        foreach ($connections as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['state'] ?? null);
            $states[] = $entry['state'];
        }

        return $states;
    }

    /** Recule `granted_at` de toutes les connexions de `connection_unexchanged` plus une seconde. */
    private function grantedLongerAgoThanTheUnexchangedLifetime(): void
    {
        $unexchanged = self::getContainer()->getParameter('app.mcp.lifetime.connection_unexchanged');

        $this->grantedAgo(new DateInterval($unexchanged), new DateInterval('PT1S'));
    }

    /**
     * `granted_at` ramené à « maintenant » moins la somme des intervalles (en
     * temps écoulé : des heures, minutes, secondes).
     */
    private function grantedAgo(DateInterval ...$intervals): void
    {
        $at = self::getContainer()->get(ClockInterface::class)->now();
        foreach ($intervals as $interval) {
            $at = $at->sub($interval);
        }

        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('UPDATE mcp_connection SET granted_at = :at', ['at' => $at->format(DateTimeImmutable::ATOM)]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /** Le nombre de connexions que la purge présélectionne à `$now` (la présélection verrouille : lue dans une transaction annulée). */
    private function preselectedAt(DateTimeImmutable $now): int
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $bounds = self::getContainer()->get(ConnectionLifetimes::class)->preselectionBoundsAt($now);
        $entityManager->beginTransaction();

        try {
            $candidates = self::getContainer()->get(McpConnectionRepositoryInterface::class)->findActiveCandidatesForExpiry($bounds);
        } finally {
            $entityManager->rollback();
        }
        $entityManager->clear();

        return \count(array_filter($candidates, static fn (McpConnection $connection): bool => !$connection->isRevoked()));
    }

    private function runThePurge(): void
    {
        $tester = new CommandTester(new Application(self::$kernel ?? self::fail('Aucun noyau.'))->find('app:mcp:purge'));
        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
    }
}
