<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\AnonymousCv\Presentation\ApiResource;

use App\Portfolio\AnonymousCv\Domain\Entity\AnonymousCvSection;
use App\Portfolio\AnonymousCv\Domain\Repository\AnonymousCvSectionRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Couvre GET /api/anonymous-cv/{locale}. ADR 0003 D5 : contenu du palier de
 * base — publiable, mais pas anonyme : il exige ROLE_USER, donc au moins le
 * jeton de base (D6). La route n'est PAS dans ApiRouteExposureTest::PUBLIC_PATHS
 * — l'invariant n°1 la couvre (elle refuse bien l'anonyme).
 *
 * Le dernier test fixe une propriété que le nom de route porte à lui seul :
 * /api/anonymous-cv ne doit pas tomber sous `^/api/cv` (ROLE_TRUSTED), sinon
 * le palier de base n'y accéderait jamais et le contenu D5 serait mort-né.
 */
final class AnonymousCvSectionResourceTest extends WebTestCase
{
    private const string PLAIN_USERNAME = 'jane';
    private const string TRUSTED_USERNAME = 'trusted-jane';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM anonymous_cv_section');
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testAnonymousRequestIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/anonymous-cv/fr');

        self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
    }

    public function testABaseTierAccountCanReadSectionsOrderedByPosition(): void
    {
        $client = self::createClient();
        $this->createSection(Locale::FR, 'Deuxième', 1);
        $this->createSection(Locale::FR, 'Première', 0);

        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        $client->request('GET', '/api/anonymous-cv/fr');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame(['Première', 'Deuxième'], array_column($payload, 'title'));
        self::assertSame(['title', 'skills', 'yearsOfExperience', 'achievements'], array_keys($payload[0]));
    }

    public function testATrustedAccountCanAlsoReadSections(): void
    {
        $client = self::createClient();
        $this->createSection(Locale::FR, 'Seule section', 0);

        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::TRUSTED_USERNAME, TestCredentials::plainPassword(), [CpgUser::ROLE_TRUSTED]);
        $this->loginAs($client, self::TRUSTED_USERNAME, TestCredentials::plainPassword());

        $client->request('GET', '/api/anonymous-cv/fr');

        self::assertResponseIsSuccessful();
    }

    public function testOnlyTheRequestedLocaleIsReturned(): void
    {
        $client = self::createClient();
        $this->createSection(Locale::FR, 'Française', 0);
        $this->createSection(Locale::EN, 'English', 0);

        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        $client->request('GET', '/api/anonymous-cv/en');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame(['English'], array_column($payload, 'title'));
    }

    /**
     * ROLE_USER suffit : un compte de base ne doit pas recevoir 403 (ce qui
     * signifierait que la règle `^/api/cv` ROLE_TRUSTED a capturé la route).
     */
    public function testTheRouteIsNotCaughtByTheTrustedOnlyCvRule(): void
    {
        $client = self::createClient();

        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        $client->request('GET', '/api/anonymous-cv/fr');
        self::assertResponseStatusCodeSame(200);

        $client->request('GET', '/api/cv');
        self::assertResponseStatusCodeSame(403);
    }

    private function createSection(Locale $locale, string $title, int $position): void
    {
        self::getContainer()->get(AnonymousCvSectionRepositoryInterface::class)->save(new AnonymousCvSection(
            $locale,
            $title,
            'Compétences.',
            5,
            'Réalisations.',
            $position,
        ));
    }

    private function loginAs(KernelBrowser $client, string $username, string $password): void
    {
        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'username' => $username,
            'password' => $password,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
    }
}
