<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Presentation\ApiResource;

use App\Portfolio\Watch\Application\WatchedProductAdministratorInterface;
use App\Portfolio\Watch\Domain\Exception\ReleaseCycleSourceUnavailableException;
use App\Portfolio\Watch\Domain\Service\ReleaseCycleSourceInterface;
use App\Portfolio\Watch\Domain\ValueObject\VersionSource;
use App\Portfolio\Watch\Infrastructure\Http\EndOfLifeDateClient;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Couvre le CRUD réservé ROLE_SUPER de /api/backoffice/watch/products, en
 * miroir du test du endpoint public (WatchResourceTest).
 */
final class BackofficeWatchedProductResourceTest extends WebTestCase
{
    use HttpJson;

    private const string SUPER_USERNAME = 'super';
    private const string SUPER_PASSWORD = 'SuperSecret123';
    private const string PLAIN_USERNAME = 'jane';
    private const string PLAIN_PASSWORD = 'SecurePassword123';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM watched_product');
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testAnonymousRequestIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/backoffice/watch/products');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Authentifié n'est pas autorisé : le compte invité partagé ne doit pas
     * pouvoir toucher au catalogue.
     */
    public function testRequestWithoutRoleSuperIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, self::PLAIN_PASSWORD);
        $this->loginAs($client, self::PLAIN_USERNAME, self::PLAIN_PASSWORD);

        $client->request('GET', '/api/backoffice/watch/products');

        self::assertResponseStatusCodeSame(403);
    }

    public function testFullCrudCycleAsRoleSuper(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);
        $this->stubSlugVerification($client);

        $client->getContainer()->get(WatchedProductAdministratorInterface::class)
            ->create('postgresql', 'PostgreSQL', VersionSource::MANUAL, '18.4', 0);

        // GetCollection
        $client->request('GET', '/api/backoffice/watch/products');
        self::assertResponseIsSuccessful();
        $collection = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(1, $collection);
        self::assertSame('postgresql', $collection[0]['slug']);
        $id = $collection[0]['id'];
        self::assertIsInt($id);

        // Get
        $client->request('GET', sprintf('/api/backoffice/watch/products/%d', $id));
        self::assertResponseIsSuccessful();

        // Post
        $client->request('POST', '/api/backoffice/watch/products', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'slug' => 'nginx',
            'label' => 'nginx',
            'versionSource' => 'manual',
            'version' => '1.30.4',
            'position' => 1,
        ]));
        self::assertResponseIsSuccessful();
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('nginx', $created['slug']);

        // Post - slug déjà suivi => 409
        $client->request('POST', '/api/backoffice/watch/products', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'slug' => 'nginx',
            'label' => 'Doublon',
            'versionSource' => 'manual',
            'version' => '1.0',
            'position' => 2,
        ]));
        self::assertResponseStatusCodeSame(409);

        // Put
        $client->request('PUT', sprintf('/api/backoffice/watch/products/%d', $id), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'slug' => 'postgresql',
            'label' => 'PostgreSQL 18',
            'versionSource' => 'manual',
            'version' => '18.6',
            'position' => 3,
        ]));
        self::assertResponseIsSuccessful();
        $updated = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('18.6', $updated['version']);

        // Put - changement de slug => 409
        $client->request('PUT', sprintf('/api/backoffice/watch/products/%d', $id), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'slug' => 'mariadb',
            'label' => 'MariaDB',
            'versionSource' => 'manual',
            'version' => '11.4',
            'position' => 3,
        ]));
        self::assertResponseStatusCodeSame(409);

        // Put - id inconnu => 404
        $client->request('PUT', '/api/backoffice/watch/products/999999', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'slug' => 'rust',
            'label' => 'Rust',
            'versionSource' => 'manual',
            'version' => '1.0',
            'position' => 0,
        ]));
        self::assertResponseStatusCodeSame(404);

        // Delete - id inconnu => 404
        $client->request('DELETE', '/api/backoffice/watch/products/999999', server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(404);

        // Delete
        $client->request('DELETE', sprintf('/api/backoffice/watch/products/%d', $id), server: ['HTTP_X_XSRF_TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(204);
    }

    /**
     * L'invariant du domaine remonte en 422, pas en 500 : c'est une saisie que
     * l'auteur peut corriger lui-même, pas un défaut du serveur.
     */
    public function testAManualSourceWithoutVersionIsRejectedAsInvalidInput(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);
        $this->stubSlugVerification($client);

        $client->request('POST', '/api/backoffice/watch/products', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'slug' => 'postgresql',
            'label' => 'PostgreSQL',
            'versionSource' => 'manual',
            'version' => '',
            'position' => 0,
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Décision D10 : un slug absent du catalogue est signalé sur le champ, tout
     * de suite, plutôt que de produire une ligne « inconnue » que l'auteur ne
     * découvrirait qu'au prochain rafraîchissement.
     */
    public function testASlugAbsentFromTheProviderCatalogIsRejected(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);
        $this->stubSlugVerification($client, slugExists: false);

        $client->request('POST', '/api/backoffice/watch/products', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'slug' => 'phpp',
            'label' => 'PHP (typo)',
            'versionSource' => 'manual',
            'version' => '8.5.9',
            'position' => 0,
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('phpp', (string) $client->getResponse()->getContent());
    }

    /**
     * Le test qui porte vraiment D10 : le fournisseur est injoignable, donc la
     * vérification n'aboutit pas — et l'enregistrement passe quand même. Une
     * panne chez un tiers ne doit jamais empêcher d'administrer son propre
     * site, d'autant qu'un slug fautif se limiterait à une ligne affichée
     * « inconnue », réparable à tout moment.
     */
    public function testAnUnreachableProviderDoesNotBlockAdministration(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);

        $source = self::createStub(ReleaseCycleSourceInterface::class);
        $source->method('supportsProduct')->willThrowException(
            ReleaseCycleSourceUnavailableException::forUnexpectedStatus('postgresql', 503),
        );
        $this->replaceReleaseCycleSource($client, $source);

        $client->request('POST', '/api/backoffice/watch/products', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'slug' => 'postgresql',
            'label' => 'PostgreSQL',
            'versionSource' => 'manual',
            'version' => '18.4',
            'position' => 0,
        ]));

        self::assertResponseIsSuccessful();
    }

    /**
     * Le pendant : une source runtime n'accepte pas de version saisie. Une
     * chaîne vide venue du formulaire est normalisée en `null` et passe donc
     * sans erreur — c'est exactement ce que l'auteur voulait exprimer.
     */
    public function testARuntimeSourceAcceptsAnEmptyVersionFromTheForm(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);
        $this->stubSlugVerification($client);

        $client->request('POST', '/api/backoffice/watch/products', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'slug' => 'php',
            'label' => 'PHP',
            'versionSource' => 'runtime_php',
            'version' => '',
            'position' => 0,
        ]));

        self::assertResponseIsSuccessful();
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertNull($created['version']);
    }

    public function testAnInvalidSlugIsRejectedByValidation(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::SUPER_USERNAME, self::SUPER_PASSWORD, [CpgUser::ROLE_SUPER]);
        $csrfToken = $this->loginAs($client, self::SUPER_USERNAME, self::SUPER_PASSWORD);
        $this->stubSlugVerification($client);

        $client->request('POST', '/api/backoffice/watch/products', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_XSRF_TOKEN' => $csrfToken,
        ], content: self::jsonBody([
            'slug' => 'PostgreSQL 18',
            'label' => 'PostgreSQL',
            'versionSource' => 'manual',
            'version' => '18.4',
            'position' => 0,
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Substitue la source de cycles de vie pour que la validation du slug (D10)
     * ne sorte jamais sur le réseau.
     *
     * La substitution vise l'id de la **classe concrète**, et non l'alias
     * ReleaseCycleSourceInterface : c'est la classe que le conteneur injecte
     * réellement, l'alias n'étant qu'un panneau indicateur résolu à la
     * compilation. Le double reste, lui, un doublon de l'interface — c'est le
     * type que déclare le consommateur.
     *
     * Le premier jet de ces tests substituait `http_client` : sans effet, car
     * un service privé à consommateur unique est « inliné ». Ils simulaient
     * donc le fournisseur tout en l'appelant pour de bon — ils passaient, et la
     * CI aurait cassé au premier incident chez le tiers. La classe est rendue
     * publique sous `when@test` (config/services.yaml) pour éviter cet inlining.
     */
    private function stubSlugVerification(KernelBrowser $client, bool $slugExists = true): void
    {
        $source = self::createStub(ReleaseCycleSourceInterface::class);
        $source->method('supportsProduct')->willReturn($slugExists);

        $this->replaceReleaseCycleSource($client, $source);
    }

    /**
     * `disableReboot()` est indispensable : par défaut KernelBrowser redémarre
     * le noyau entre deux requêtes, ce qui reconstruit le conteneur et jette le
     * service substitué. Sans lui, le double est bien enregistré puis
     * silencieusement remplacé par le vrai client au moment de la requête.
     */
    private function replaceReleaseCycleSource(KernelBrowser $client, ReleaseCycleSourceInterface $source): void
    {
        $client->disableReboot();
        $client->getContainer()->set(EndOfLifeDateClient::class, $source);
    }

    private function loginAs(KernelBrowser $client, string $username, string $password): string
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'username' => $username,
            'password' => $password,
        ]));
        self::assertResponseIsSuccessful();

        $csrfCookie = $client->getCookieJar()->get('XSRF-TOKEN');
        self::assertNotNull($csrfCookie);

        return $csrfCookie->getValue();
    }
}
