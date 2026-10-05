<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Presentation\ApiResource;

use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\ReadsAllChannelsLog;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Couvre POST /api/backoffice/users (invitation d'un utilisateur par e-mail),
 * réservé ROLE_SUPER. Le transport Messenger "async" est en in-memory en test
 * (cf. when@test dans messenger.yaml) : on inspecte le message dispatché sans
 * consommer, donc sans envoyer de vrai e-mail.
 */
final class BackofficeUserInviteResourceTest extends WebTestCase
{
    use HttpJson;
    use ReadsAllChannelsLog;

    private const string SUPER_USERNAME = 'super';
    private const string PLAIN_USERNAME = 'jane';

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

    public function testAnonymousInviteIsRejected(): void
    {
        $client = self::createClient();

        $client->request('POST', '/api/backoffice/users', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'email' => 'newcomer@example.com',
            'locale' => 'fr',
        ]));

        // Bloqué en amont du firewall par la protection CSRF (aucun cookie/header
        // XSRF-TOKEN sans login) : de toute façon inaccessible sans ROLE_SUPER.
        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->asyncTransport()->getSent());
    }

    public function testInviteWithoutRoleSuperIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $csrfToken = $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        $client->request('POST', '/api/backoffice/users', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['email' => 'newcomer@example.com', 'locale' => 'fr']));

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->asyncTransport()->getSent());
    }

    public function testRoleSuperInvitesAUserAndAnInvitationMessageIsDispatched(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('POST', '/api/backoffice/users', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['email' => 'jean.dupont@example.com', 'locale' => 'fr']));

        self::assertResponseStatusCodeSame(201);

        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('jean.dupont', $body['username']);
        self::assertSame('jean.dupont@example.com', $body['email']);
        self::assertSame('pending', $body['status']);
        self::assertContains('ROLE_USER', $body['roles']);
        // ADR 0003 D1 : l'invitation par un ROLE_SUPER est l'octroi nominatif de ROLE_TRUSTED.
        self::assertContains(CpgUser::ROLE_TRUSTED, $body['roles']);
        self::assertArrayNotHasKey('password', $body);

        // Le message ne porte plus que { userId, locale } (audit C2 / D3) :
        // aucun secret, et le jeton est créé côté handler.
        $sent = $this->asyncTransport()->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SendAccountInvitationMessage::class, $message);
        // Spec 0003 D7 : l'id circule en chaîne RFC 4122, des deux côtés.
        self::assertIsString($body['id']);
        self::assertTrue(Uuid::isValid($body['id']));
        self::assertSame($body['id'], $message->userId);
        self::assertSame('fr', $message->locale);
    }

    public function testInviteWithAnAlreadyUsedEmailReturns409(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $payload = self::jsonBody(['email' => 'jean.dupont@example.com', 'locale' => 'fr']);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfToken];

        $client->request('POST', '/api/backoffice/users', server: $server, content: $payload);
        self::assertResponseStatusCodeSame(201);

        $client->request('POST', '/api/backoffice/users', server: $server, content: $payload);
        self::assertResponseStatusCodeSame(409);
    }

    /**
     * Issue #356 : l'adresse refusée ne sort ni dans la réponse ni dans aucun
     * journal. Le noyau journalise le message de l'exception (en `info`,
     * framework.exceptions), et la préprod tourne en LOG_LEVEL=debug : un
     * message qui cite l'adresse la ferait sortir du pod, hors de la règle
     * « jamais d'e-mail dans un journal » que seul `security_audit` pinçait.
     */
    public function testTheRefusedEmailAppearsNeitherInTheResponseNorInAnyLog(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $payload = self::jsonBody(['email' => 'sentinel.taken@example.com', 'locale' => 'fr']);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => $csrfToken];
        $client->request('POST', '/api/backoffice/users', server: $server, content: $payload);
        self::assertResponseStatusCodeSame(201);

        $client->request('POST', '/api/backoffice/users', server: $server, content: $payload);

        self::assertResponseStatusCodeSame(409);
        // Le `detail` seulement : en test, la réponse porte aussi la `trace`
        // de débogage d'API Platform (arguments des appels compris), absente
        // en production.
        $problem = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertIsString($problem['detail'] ?? null);
        self::assertStringNotContainsString('sentinel.taken', $problem['detail']);
        $refusals = array_filter(
            self::allChannelsLogRecords(),
            static fn (LogRecord $record): bool => str_contains($record->message, 'EmailAlreadyUsedException'),
        );
        // Garde-fou : la sonde a bien vu passer le refus.
        self::assertCount(1, $refusals);
        $formatter = new JsonFormatter();
        foreach (self::allChannelsLogRecords() as $record) {
            // Le canal `doctrine` cite les paramètres SQL (l'adresse cherchée),
            // mais seulement là où son middleware de journalisation est
            // enregistré, c'est-à-dire avec kernel.debug : en dev et en test,
            // jamais en préprod ni en production (APP_DEBUG=0, Dockerfile).
            if ('doctrine' === $record->channel) {
                continue;
            }
            self::assertStringNotContainsString('sentinel.taken', $formatter->format($record));
        }
    }

    public function testInviteWithAnInvalidEmailReturns422(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('POST', '/api/backoffice/users', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['email' => 'not-an-email', 'locale' => 'fr']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testInviteWithAMissingLocaleReturns422(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, TestCredentials::superPassword(), [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, TestCredentials::superPassword());

        $client->request('POST', '/api/backoffice/users', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody(['email' => 'newcomer@example.com']));

        self::assertResponseStatusCodeSame(422);
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function loginAs(KernelBrowser $client, string $username, string $password): string
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => $username,
            'password' => $password,
        ]));
        self::assertResponseIsSuccessful();

        $csrfCookie = $client->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($csrfCookie);

        return $csrfCookie->getValue();
    }
}
