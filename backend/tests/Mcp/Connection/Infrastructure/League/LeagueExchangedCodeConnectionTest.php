<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Application\ExchangedCodeConnectionInterface;
use App\Tests\Mcp\Connection\DrivesTheOAuthFlow;
use Defuse\Crypto\Crypto;
use Doctrine\ORM\EntityManagerInterface;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le port du remplacement (#726, S3 et S6), tel que le conteneur le câble :
 * la connexion que désigne le `code` du corps, lue dans sa charge chiffrée par
 * le vrai GrantPayloadReader, sous la clé du serveur.
 *
 * Le code du cas nominal vient du vrai parcours ; une charge forgée est
 * rechiffrée sous cette clé, comme PkceReplayProofTest les forge : authentique
 * pour le serveur, c'est son contenu seul qui décide.
 */
final class LeagueExchangedCodeConnectionTest extends WebTestCase
{
    use DrivesTheOAuthFlow;

    protected function setUp(): void
    {
        $this->setUpOAuthFlow();
    }

    protected function tearDown(): void
    {
        $this->tearDownOAuthFlow();
        parent::tearDown();
    }

    /** Le code du parcours désigne la connexion que l'approbation a écrite. */
    public function testTheCodeDesignatesTheConnectionItWasGrantedFor(): void
    {
        $code = $this->grantedCode();
        $grantedConnection = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT id FROM mcp_connection');
        self::assertIsString($grantedConnection);

        $connectionId = $this->port()->connectionIdOf($this->tokenRequest(['code' => $code]));

        self::assertNotNull($connectionId);
        self::assertSame($grantedConnection, $connectionId->toRfc4122());
    }

    /**
     * @return iterable<string, array{array<string, mixed>|null}>
     */
    public static function bodiesWithoutAReadableCode(): iterable
    {
        yield 'corps non analysé' => [null];
        yield 'sans code' => [['grant_type' => 'authorization_code']];
        yield 'code d\'un autre type qu\'une chaîne' => [['grant_type' => 'authorization_code', 'code' => ['x']]];
        yield 'code vide' => [['grant_type' => 'authorization_code', 'code' => '']];
        yield 'code illisible' => [['grant_type' => 'authorization_code', 'code' => 'pas-un-code']];
    }

    /**
     * Un corps dont le code ne se lit pas — absent, d'un autre type, vide, ou
     * illisible sous la clé du serveur — ne désigne aucune connexion.
     *
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('bodiesWithoutAReadableCode')]
    public function testABodyWithoutAReadableCodeDesignatesNoConnection(?array $body): void
    {
        $request = new ServerRequest('POST', self::TOKEN_PATH);

        self::assertNull($this->port()->connectionIdOf(null === $body ? $request : $request->withParsedBody($body)));
    }

    /**
     * Une charge authentique — rechiffrée sous la clé du serveur — sans
     * connexion lisible : sans `user_id`, ou un `user_id` qui n'est pas l'UUID
     * RFC 4122 en minuscules que le serveur écrit.
     *
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function payloadsWithoutAConnection(): iterable
    {
        yield 'sans user_id' => [[], ['user_id']];
        yield 'user_id quelconque' => [['user_id' => 'quelqu-un'], []];
        yield 'user_id en majuscules' => [['user_id' => '0192A4E0-1111-7000-8000-000000000001'], []];
        yield 'user_id entier' => [['user_id' => 42], []];
    }

    /**
     * @param array<string, mixed> $changes
     * @param list<string>         $removed
     */
    #[DataProvider('payloadsWithoutAConnection')]
    public function testAPayloadWithoutAConnectionDesignatesNone(array $changes, array $removed): void
    {
        $payload = [...$this->payloadOf($this->grantedCode()), ...$changes];
        foreach ($removed as $key) {
            unset($payload[$key]);
        }
        $forged = Crypto::encrypt(json_encode($payload, \JSON_THROW_ON_ERROR), $this->encryptionKey());

        self::assertNull($this->port()->connectionIdOf($this->tokenRequest(['code' => $forged])));
    }

    private function port(): ExchangedCodeConnectionInterface
    {
        return self::getContainer()->get(ExchangedCodeConnectionInterface::class);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function tokenRequest(array $body): ServerRequest
    {
        return new ServerRequest('POST', self::TOKEN_PATH)->withParsedBody(['grant_type' => 'authorization_code', ...$body]);
    }
}
