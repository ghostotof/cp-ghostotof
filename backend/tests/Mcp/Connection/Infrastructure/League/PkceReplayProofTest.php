<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Application\ReplayProofInterface;
use App\Tests\Mcp\Connection\DrivesTheOAuthFlow;
use Defuse\Crypto\Crypto;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La preuve qu'un rejeu de code vient de qui détient son vérificateur PKCE
 * (#726) : le `code_verifier` du corps, haché en `S256`, doit rendre le
 * `code_challenge` que la charge chiffrée du code porte (RFC 7636 §4.6).
 * Seule cette preuve autorise le point de jeton à révoquer la connexion d'un
 * code rejoué ; sans elle, quiconque a intercepté la redirection couperait la
 * connexion qu'il ne peut pas échanger.
 *
 * Le code et son vérificateur viennent du vrai parcours, la charge est lue
 * par le vrai GrantPayloadReader, sous la clé du serveur ; les charges
 * forgées sont rechiffrées sous cette clé, comme GrantRefusalAuditTest les
 * forge : authentiques pour le serveur, c'est leur contenu seul qui décide.
 */
final class PkceReplayProofTest extends WebTestCase
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

    public function testTheCodesOwnVerifierProvesPossession(): void
    {
        self::assertTrue($this->proof()->provesPossession($this->tokenRequest([
            'code' => $this->grantedCode(),
            'code_verifier' => self::CODE_VERIFIER,
        ])));
    }

    /**
     * Le corps tel qu'il pourrait arriver, le code étant le vrai : un
     * vérificateur absent, d'un autre type qu'une chaîne, vide ou faux ne
     * prouve rien.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function verifiersProvingNothing(): iterable
    {
        yield 'absent' => [null];
        yield 'tableau' => [[self::CODE_VERIFIER]];
        yield 'vide' => [''];
        yield 'faux, bien formé' => [str_repeat('a', 43)];
    }

    #[DataProvider('verifiersProvingNothing')]
    public function testAnotherVerifierProvesNothing(mixed $verifier): void
    {
        $body = ['code' => $this->grantedCode()];
        if (null !== $verifier) {
            $body['code_verifier'] = $verifier;
        }

        self::assertFalse($this->proof()->provesPossession($this->tokenRequest($body)));
    }

    /**
     * Le défi est public (il figure dans la demande d'autorisation) : le
     * présenter comme vérificateur ne prouve rien.
     */
    public function testThePublicChallengeProvesNothing(): void
    {
        $code = $this->grantedCode();
        $challenge = $this->payloadOf($code)['code_challenge'] ?? null;
        self::assertIsString($challenge);

        self::assertFalse($this->proof()->provesPossession($this->tokenRequest(['code' => $code, 'code_verifier' => $challenge])));
    }

    /**
     * @return iterable<string, array{array<string, mixed>|null}>
     */
    public static function bodiesWithoutAReadableCode(): iterable
    {
        yield 'corps absent' => [null];
        yield 'sans code' => [['code_verifier' => self::CODE_VERIFIER]];
        yield 'code d\'un autre type qu\'une chaîne' => [['code' => ['x'], 'code_verifier' => self::CODE_VERIFIER]];
        yield 'code illisible' => [['code' => 'pas-un-code', 'code_verifier' => self::CODE_VERIFIER]];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('bodiesWithoutAReadableCode')]
    public function testABodyWithoutAReadableCodeProvesNothing(?array $body): void
    {
        $request = new ServerRequest('POST', self::TOKEN_PATH);

        self::assertFalse($this->proof()->provesPossession(null === $body ? $request : $request->withParsedBody($body)));
    }

    /**
     * Des charges authentiques — rechiffrées sous la clé du serveur — dont le
     * défi ne se vérifie pas en `S256` : sans défi, défi vide, d'un autre
     * type, ou méthode `plain` (le défi `S256` du flux gardé : seule la
     * méthode décide), méthode absente ou en minuscules. Le vrai
     * vérificateur ne prouve rien contre elles.
     *
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function payloadsWithoutAnS256Challenge(): iterable
    {
        yield 'sans code_challenge' => [[], ['code_challenge']];
        yield 'code_challenge vide' => [['code_challenge' => ''], []];
        yield 'code_challenge entier' => [['code_challenge' => 42], []];
        yield 'méthode plain' => [['code_challenge_method' => 'plain'], []];
        yield 'méthode absente' => [[], ['code_challenge_method']];
        yield 'méthode en minuscules' => [['code_challenge_method' => 's256'], []];
    }

    /**
     * @param array<string, mixed> $changes
     * @param list<string>         $removed
     */
    #[DataProvider('payloadsWithoutAnS256Challenge')]
    public function testAPayloadWithoutAnS256ChallengeProvesNothing(array $changes, array $removed): void
    {
        $payload = [...$this->payloadOf($this->grantedCode()), ...$changes];
        foreach ($removed as $key) {
            unset($payload[$key]);
        }
        $forged = Crypto::encrypt(json_encode($payload, \JSON_THROW_ON_ERROR), $this->encryptionKey());

        self::assertFalse($this->proof()->provesPossession($this->tokenRequest([
            'code' => $forged,
            'code_verifier' => self::CODE_VERIFIER,
        ])));
    }

    /**
     * Le témoin des charges forgées : la charge du flux, rechiffrée telle
     * quelle, est prouvée par son vérificateur — ce sont bien les changements
     * ci-dessus qui la font refuser, pas le rechiffrement.
     */
    public function testAReencryptedPayloadLeftAsIsIsProven(): void
    {
        $forged = Crypto::encrypt(json_encode($this->payloadOf($this->grantedCode()), \JSON_THROW_ON_ERROR), $this->encryptionKey());

        self::assertTrue($this->proof()->provesPossession($this->tokenRequest([
            'code' => $forged,
            'code_verifier' => self::CODE_VERIFIER,
        ])));
    }

    private function proof(): ReplayProofInterface
    {
        return self::getContainer()->get(ReplayProofInterface::class);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function tokenRequest(array $body): ServerRequest
    {
        return new ServerRequest('POST', self::TOKEN_PATH)->withParsedBody(['grant_type' => 'authorization_code', ...$body]);
    }
}
