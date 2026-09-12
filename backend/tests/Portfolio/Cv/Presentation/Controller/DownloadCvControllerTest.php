<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Cv\Presentation\Controller;

use App\Portfolio\Cv\Presentation\Controller\DownloadCvController;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Couvre GET /api/cv : même protection par authentification que /api/me (cf.
 * AuthenticationFlowTest) et le cas limite "fichier absent" (déploiement où
 * CV_FILE_PATH ne pointe vers rien, cf. backend/resources/README.md). La
 * fixture (tests/Portfolio/Cv/Fixtures/dummy.pdf) est utilisée à la place du
 * vrai CV : voir CV_FILE_PATH dans .env.test.
 */
final class DownloadCvControllerTest extends WebTestCase
{
    use HttpJson;

    private const string USERNAME = 'jane';
    private const string FIXTURE_PATH = __DIR__.'/../../Fixtures/dummy.pdf';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testAnonymousRequestIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/cv');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Miroir du cas d'échec de ApiRouteExposureTest::testCvAndMeRefuseAnAuthenticatedUserWithoutRoleTrusted :
     * couvre ici le comportement précis (403, pas juste "pas 200") plutôt que
     * la seule non-régression transverse.
     */
    public function testAuthenticatedRequestWithoutRoleTrustedIsForbidden(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::USERNAME, TestCredentials::plainPassword());

        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'username' => self::USERNAME,
            'password' => TestCredentials::plainPassword(),
        ]));
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/cv');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAuthenticatedRequestReturnsThePdfFixture(): void
    {
        $client = self::createClient();
        // ADR 0003 : /api/cv exige ROLE_TRUSTED, au-delà du simple palier
        // de base ROLE_USER — accordé ici pour couvrir le cas d'accès autorisé.
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::USERNAME, TestCredentials::plainPassword(), [CpgUser::ROLE_TRUSTED]);

        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json'], content: self::jsonBody([
            'username' => self::USERNAME,
            'password' => TestCredentials::plainPassword(),
        ]));
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/cv');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('cv.pdf', (string) $client->getResponse()->headers->get('Content-Disposition'));

        $response = $client->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(realpath(self::FIXTURE_PATH), $response->getFile()->getRealPath());
    }

    public function testMissingFileYieldsNotFound(): void
    {
        $controller = new DownloadCvController('/path/that/does/not/exist.pdf', 'cv.pdf');

        $this->expectException(NotFoundHttpException::class);

        $controller();
    }
}
