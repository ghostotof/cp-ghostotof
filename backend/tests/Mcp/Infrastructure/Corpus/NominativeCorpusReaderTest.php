<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Corpus;

use App\Ai\Assistant\Application\Corpus\CorpusRendererInterface;
use App\Mcp\Application\ProtectedTools;
use App\Mcp\Connection\Application\McpConnectionPrincipal;
use App\Mcp\Infrastructure\Corpus\NominativeCorpusReader;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Tests\Mcp\Fixtures\ConnectionPrincipal;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Uid\Uuid;

/**
 * L'accès au corpus nominatif et sa trace (#648), sur le vrai `Security` du
 * conteneur — donc la vraie `role_hierarchy` — et un principal de fixture
 * posé dans le `TokenStorage` comme le firewall `mcp` pose le sien.
 *
 * La défense en profondeur (relecture de sécurité de #648, A1) : une connexion
 * que l'authentificateur refuserait aujourd'hui (sans le palier, sans le
 * scope) n'existe pas en HTTP ; seul ce test la fabrique, pour que
 * l'assouplissement de l'authentificateur ne serve pas le corpus en silence.
 * Refusée, rien n'est rendu ni journalisé.
 */
final class NominativeCorpusReaderTest extends KernelTestCase
{
    private const string FRONTEND = 'https://site.example/';

    private const string CORPUS = 'Le corpus de la fixture.';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testAValidConnectionOpensTheCorpusWithoutAuditingIt(): void
    {
        $principal = new ConnectionPrincipal(Uuid::v7());
        $this->authenticateAs($principal);
        $renderer = $this->createMock(CorpusRendererInterface::class);
        $renderer->expects($this->once())->method('render')->with(Locale::EN)->willReturn(self::CORPUS);
        $audit = $this->createMock(SecurityAuditLoggerInterface::class);
        $audit->expects($this->never())->method('nominativeCorpusRead');

        $corpus = $this->reader($renderer, $audit)->open(Locale::EN);

        self::assertNotNull($corpus);
        self::assertSame(['corpus' => self::CORPUS, 'cvPageUrl' => 'https://site.example/en/assistant'], $corpus->payload());
        self::assertSame($principal, $corpus->reader);
    }

    /** La ligne désigne le compte, la connexion, le client et l'outil. */
    public function testRecordServedWritesTheAuditLine(): void
    {
        $principal = new ConnectionPrincipal(Uuid::v7());
        $this->authenticateAs($principal);
        $renderer = self::createStub(CorpusRendererInterface::class);
        $renderer->method('render')->willReturn(self::CORPUS);
        $audit = $this->createMock(SecurityAuditLoggerInterface::class);
        $audit->expects($this->once())->method('nominativeCorpusRead')
            ->with($principal, $principal->connectionId(), 'test-client', ProtectedTools::NOMINATIVE_CORPUS);
        $reader = $this->reader($renderer, $audit);

        $corpus = $reader->open(Locale::FR);
        self::assertNotNull($corpus);
        $reader->recordServed($corpus);
    }

    /**
     * Le palier se juge par la `role_hierarchy`, comme partout sur le site :
     * un ROLE_SUPER sans ROLE_TRUSTED stocké l'atteint.
     */
    public function testASuperAccountReachesTheTrustedTierThroughTheHierarchy(): void
    {
        $this->authenticateAs(new ConnectionPrincipal(Uuid::v7(), ['ROLE_SUPER']));
        $renderer = self::createStub(CorpusRendererInterface::class);
        $renderer->method('render')->willReturn(self::CORPUS);

        self::assertNotNull($this->reader($renderer, self::createStub(SecurityAuditLoggerInterface::class))->open(Locale::FR));
    }

    /**
     * @return iterable<string, array{?UserInterface}>
     */
    public static function refusedCallers(): iterable
    {
        yield 'sans connexion' => [null];
        yield 'un compte du site, pas une connexion' => [new InMemoryUser('trusted', null, [McpConnectionPrincipal::TRUSTED_TIER])];
        yield 'une connexion sans le palier de confiance' => [new ConnectionPrincipal(Uuid::v7(), ['ROLE_USER'])];
        yield 'une connexion sans le scope nominative:read' => [new ConnectionPrincipal(Uuid::v7(), grantsNominativeRead: false)];
    }

    /** Refusé : `null`, le corpus jamais rendu, aucune ligne d'audit. */
    #[DataProvider('refusedCallers')]
    public function testARefusedCallerGetsNothingAndNothingIsRead(?UserInterface $user): void
    {
        if (null !== $user) {
            $this->authenticateAs($user);
        }
        $renderer = $this->createMock(CorpusRendererInterface::class);
        $renderer->expects($this->never())->method('render');
        $audit = $this->createMock(SecurityAuditLoggerInterface::class);
        $audit->expects($this->never())->method('nominativeCorpusRead');

        self::assertNull($this->reader($renderer, $audit)->open(Locale::FR));
    }

    /** Pose `$user` comme le ferait un firewall, ses rôles stockés dans le jeton. */
    private function authenticateAs(UserInterface $user): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new PostAuthenticationToken($user, 'mcp', $user->getRoles()));
    }

    private function reader(CorpusRendererInterface $renderer, SecurityAuditLoggerInterface $audit): NominativeCorpusReader
    {
        return new NominativeCorpusReader(self::getContainer()->get(Security::class), $renderer, $audit, self::FRONTEND);
    }
}
