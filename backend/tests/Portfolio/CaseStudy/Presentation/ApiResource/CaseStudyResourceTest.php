<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\CaseStudy\Presentation\ApiResource;

use App\Portfolio\CaseStudy\Domain\Entity\CaseStudy;
use App\Portfolio\CaseStudy\Domain\Repository\CaseStudyRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Couvre GET /api/case-studies/{locale}. ADR 0003 D5 : contenu du palier de
 * base — publiable, mais pas anonyme (contrairement à /api/contributions/{locale}) :
 * il exige ROLE_USER, donc au moins le jeton de base (ADR 0003 D6). Cette
 * route n'est PAS dans ApiRouteExposureTest::PUBLIC_PATHS — l'invariant n°1
 * la couvre déjà (elle refuse bien l'anonyme).
 */
final class CaseStudyResourceTest extends WebTestCase
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
        $connection->executeStatement('DELETE FROM case_study');
        $connection->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testAnonymousRequestIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/case-studies/fr');

        self::assertContains($client->getResponse()->getStatusCode(), [401, 403]);
    }

    public function testABaseTierAccountCanReadCaseStudiesOrderedByPosition(): void
    {
        $client = self::createClient();
        $this->createCaseStudy(Locale::FR, 'Deuxième', 1);
        $this->createCaseStudy(Locale::FR, 'Première', 0);

        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        $client->request('GET', '/api/case-studies/fr');

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame(['Première', 'Deuxième'], array_column($payload, 'title'));
    }

    public function testATrustedAccountCanAlsoReadCaseStudies(): void
    {
        $client = self::createClient();
        $this->createCaseStudy(Locale::FR, 'Seule entrée', 0);

        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::TRUSTED_USERNAME, TestCredentials::plainPassword(), [CpgUser::ROLE_TRUSTED]);
        $this->loginAs($client, self::TRUSTED_USERNAME, TestCredentials::plainPassword());

        $client->request('GET', '/api/case-studies/fr');

        self::assertResponseIsSuccessful();
    }

    public function testOnlyTheRequestedLocaleIsReturned(): void
    {
        $client = self::createClient();
        $this->createCaseStudy(Locale::FR, 'Française', 0);
        $this->createCaseStudy(Locale::EN, 'English', 0);

        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::PLAIN_USERNAME, TestCredentials::plainPassword());
        $this->loginAs($client, self::PLAIN_USERNAME, TestCredentials::plainPassword());

        $client->request('GET', '/api/case-studies/en');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame(['English'], array_column($payload, 'title'));
    }

    private function createCaseStudy(Locale $locale, string $title, int $position): void
    {
        self::getContainer()->get(CaseStudyRepositoryInterface::class)->save(new CaseStudy(
            $locale,
            $title,
            'Problème.',
            'Solution.',
            'Compromis.',
            'Résultat mesuré.',
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
