<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Domain\ValueObject;

use App\Mcp\Connection\Domain\Exception\InvalidClientMetadataDocumentException;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataDocument;
use App\Mcp\Connection\Domain\ValueObject\McpClientName;
use App\Tests\Mcp\Connection\Fixtures\ClaudeCodeMetadata;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le document de métadonnées d'un client CIMD (#651) n'est cru qu'une fois
 * validé : `client_id` égal, au caractère près, à l'URL demandée (draft §4.1),
 * client public, flux par code, nom et redirections admissibles. Un document
 * refusé l'est en entier (échec fermé), sans jamais citer ce qu'il contenait.
 *
 * Aucun accès réseau : le document est une chaîne, la récupération est ailleurs.
 */
final class ClientMetadataDocumentTest extends TestCase
{
    /**
     * Le document de référence décodé, auquel un cas ajoute, remplace ou retire
     * des champs, puis ré-encodé.
     *
     * @param array<string, mixed> $replaced champs posés (remplacés ou ajoutés)
     * @param list<string>         $removed  champs retirés
     */
    private static function documentWith(array $replaced = [], array $removed = []): string
    {
        $document = json_decode(ClaudeCodeMetadata::json(), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        foreach ($removed as $field) {
            unset($document[$field]);
        }

        return json_encode([...$document, ...$replaced], JSON_THROW_ON_ERROR);
    }

    private function expected(): CimdClientId
    {
        $expected = CimdClientId::tryFrom(ClaudeCodeMetadata::CLIENT_ID);
        self::assertInstanceOf(CimdClientId::class, $expected);

        return $expected;
    }

    private function assertRefusedAs(InvalidClientMetadataDocumentException $expected, string $json): void
    {
        try {
            ClientMetadataDocument::fromJson($this->expected(), $json);
            self::fail('Le document aurait dû être refusé.');
        } catch (InvalidClientMetadataDocumentException $refusal) {
            self::assertSame($expected->getMessage(), $refusal->getMessage());
        }
    }

    public function testTheClaudeCodeDocumentIsAccepted(): void
    {
        $document = ClientMetadataDocument::fromJson($this->expected(), ClaudeCodeMetadata::json());

        self::assertSame(ClaudeCodeMetadata::CLIENT_ID, $document->clientId->value());
        self::assertSame('Claude Code', (string) $document->name);
        self::assertCount(2, $document->redirectUris);

        foreach ($document->redirectUris as $redirectUri) {
            self::assertTrue($redirectUri->isLoopback());
        }

        // Une boucle locale se présente sur un port éphémère (RFC 8252 §7.3).
        self::assertTrue($document->redirectUris[0]->matches('http://localhost:49152/callback'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function mismatchedClientIds(): iterable
    {
        yield 'absent' => [self::documentWith(removed: ['client_id'])];
        yield 'non-chaîne' => [self::documentWith(['client_id' => 42])];
        yield 'liste' => [self::documentWith(['client_id' => [ClaudeCodeMetadata::CLIENT_ID]])];
        yield 'autre URL' => [self::documentWith(['client_id' => 'https://claude.ai/oauth/another-client'])];
        yield 'majuscule dans le chemin' => [self::documentWith(['client_id' => 'https://claude.ai/oauth/Claude-code-client-metadata'])];
        yield 'majuscule dans l\'hôte' => [self::documentWith(['client_id' => 'https://Claude.ai/oauth/claude-code-client-metadata'])];
        yield 'barre finale' => [self::documentWith(['client_id' => ClaudeCodeMetadata::CLIENT_ID.'/'])];
    }

    #[DataProvider('mismatchedClientIds')]
    public function testAClientIdThatDiffersOnlyByCaseOrTrailingSlashIsRefused(string $json): void
    {
        $this->assertRefusedAs(InvalidClientMetadataDocumentException::clientIdMismatch(), $json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documentsThatAreNotObjects(): iterable
    {
        yield 'texte non JSON' => ['ceci n\'est pas du JSON'];
        yield 'vide' => [''];
        yield 'liste vide' => ['[]'];
        yield 'liste' => ['[{"client_id":"x"}]'];
        yield 'objet vide' => ['{}'];
        yield 'chaîne JSON' => ['"x"'];
        yield 'null' => ['null'];
        yield 'nombre' => ['42'];
        yield 'huit objets imbriqués (profondeur 9)' => [
            '{"a":'.str_repeat('{"a":', 7).'1'.str_repeat('}', 7).'}',
        ];
    }

    #[DataProvider('documentsThatAreNotObjects')]
    public function testWhatIsNotAJsonObjectIsRefused(string $json): void
    {
        $this->assertRefusedAs(InvalidClientMetadataDocumentException::notAnObject(), $json);
    }

    public function testTheNestingLimitIsTheOnlyReasonTheDeepDocumentFails(): void
    {
        // Témoin positif, sur la borne : sept objets imbriqués (profondeur 8)
        // passent le décodage, et le refus vient de la forme (aucun client_id),
        // pas de la profondeur. Un objet de plus est refusé comme notAnObject.
        $json = '{"a":'.str_repeat('{"a":', 6).'1'.str_repeat('}', 6).'}';

        $this->assertRefusedAs(InvalidClientMetadataDocumentException::clientIdMismatch(), $json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function declaredSecrets(): iterable
    {
        yield 'client_secret' => [self::documentWith(['client_secret' => 'x'])];
        yield 'client_secret null' => [self::documentWith(['client_secret' => null])];
        yield 'client_secret vide' => [self::documentWith(['client_secret' => ''])];
        yield 'client_secret_expires_at' => [self::documentWith(['client_secret_expires_at' => 0])];
        yield 'client_secret_expires_at null' => [self::documentWith(['client_secret_expires_at' => null])];
    }

    #[DataProvider('declaredSecrets')]
    public function testADeclaredSecretIsRefused(string $json): void
    {
        $this->assertRefusedAs(InvalidClientMetadataDocumentException::secretDeclared(), $json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function confidentialAuthMethods(): iterable
    {
        foreach (['client_secret_post', 'client_secret_basic', 'client_secret_jwt', 'private_key_jwt', 'x', ''] as $method) {
            yield 'méthode '.('' === $method ? 'vide' : $method) => [self::documentWith(['token_endpoint_auth_method' => $method])];
        }

        yield 'nombre' => [self::documentWith(['token_endpoint_auth_method' => 0])];
        yield 'null' => [self::documentWith(['token_endpoint_auth_method' => null])];
        yield 'liste' => [self::documentWith(['token_endpoint_auth_method' => ['none']])];
        yield 'none en majuscules' => [self::documentWith(['token_endpoint_auth_method' => 'NONE'])];

        // RFC 7591 §2 : absent, la méthode vaut `client_secret_basic`, un client confidentiel.
        yield 'absent' => [self::documentWith(removed: ['token_endpoint_auth_method'])];
    }

    #[DataProvider('confidentialAuthMethods')]
    public function testOnlyAPublicClientIsAccepted(string $json): void
    {
        $this->assertRefusedAs(InvalidClientMetadataDocumentException::unsupportedAuthMethod(), $json);
    }

    public function testAnExplicitNoneAuthMethodIsAccepted(): void
    {
        $none = ClientMetadataDocument::fromJson($this->expected(), self::documentWith(['token_endpoint_auth_method' => 'none']));

        self::assertSame('Claude Code', (string) $none->name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documentsWithoutTheCodeGrant(): iterable
    {
        yield 'sans authorization_code' => [self::documentWith(['grant_types' => ['refresh_token']])];
        yield 'liste vide' => [self::documentWith(['grant_types' => []])];
        yield 'chaîne au lieu d\'une liste' => [self::documentWith(['grant_types' => 'authorization_code'])];
        yield 'membre non-chaîne' => [self::documentWith(['grant_types' => ['authorization_code', 1]])];
        yield 'objet' => [self::documentWith(['grant_types' => ['a' => 'authorization_code']])];
        yield 'null' => [self::documentWith(['grant_types' => null])];
        yield 'casse différente' => [self::documentWith(['grant_types' => ['Authorization_Code']])];
    }

    #[DataProvider('documentsWithoutTheCodeGrant')]
    public function testTheGrantTypesMustIncludeTheCodeFlow(string $json): void
    {
        $this->assertRefusedAs(InvalidClientMetadataDocumentException::unsupportedGrantTypes(), $json);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function documentsWithoutTheCodeResponse(): iterable
    {
        yield 'sans code' => [self::documentWith(['response_types' => ['token']])];
        yield 'liste vide' => [self::documentWith(['response_types' => []])];
        yield 'chaîne au lieu d\'une liste' => [self::documentWith(['response_types' => 'code'])];
        yield 'membre non-chaîne' => [self::documentWith(['response_types' => ['code', 1]])];
        yield 'null' => [self::documentWith(['response_types' => null])];
    }

    #[DataProvider('documentsWithoutTheCodeResponse')]
    public function testTheResponseTypesMustIncludeTheCodeFlow(string $json): void
    {
        $this->assertRefusedAs(InvalidClientMetadataDocumentException::unsupportedResponseTypes(), $json);
    }

    public function testBothTypeListsAbsentIsAccepted(): void
    {
        $document = ClientMetadataDocument::fromJson(
            $this->expected(),
            self::documentWith(removed: ['grant_types', 'response_types']),
        );

        self::assertCount(2, $document->redirectUris);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'absent' => [self::documentWith(removed: ['client_name'])];
        yield 'non-chaîne' => [self::documentWith(['client_name' => 42])];
        yield 'liste' => [self::documentWith(['client_name' => ['Claude Code']])];
        yield 'null' => [self::documentWith(['client_name' => null])];
        yield 'vide' => [self::documentWith(['client_name' => ''])];
        yield 'blancs' => [self::documentWith(['client_name' => " \u{3000} "])];
        yield '101 caractères' => [self::documentWith(['client_name' => str_repeat('a', McpClientName::MAX_LENGTH + 1)])];
        yield 'caractère de contrôle' => [self::documentWith(['client_name' => "Claude\u{0007}Code"])];
        yield 'saut de ligne' => [self::documentWith(['client_name' => "Claude\nCode"])];
        yield 'inversion de sens' => [self::documentWith(['client_name' => "Claude\u{202E}edoC"])];
    }

    #[DataProvider('invalidNames')]
    public function testTheNameIsRequiredAndBounded(string $json): void
    {
        $this->assertRefusedAs(InvalidClientMetadataDocumentException::invalidName(), $json);
    }

    public function testAHundredCharactersNameIsAccepted(): void
    {
        $document = ClientMetadataDocument::fromJson(
            $this->expected(),
            self::documentWith(['client_name' => str_repeat('a', McpClientName::MAX_LENGTH)]),
        );

        self::assertSame(McpClientName::MAX_LENGTH, \strlen((string) $document->name));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRedirectUriLists(): iterable
    {
        yield 'absent' => [self::documentWith(removed: ['redirect_uris'])];
        yield 'null' => [self::documentWith(['redirect_uris' => null])];
        yield 'liste vide' => [self::documentWith(['redirect_uris' => []])];
        yield 'chaîne' => [self::documentWith(['redirect_uris' => 'http://localhost/callback'])];
        yield 'objet' => [self::documentWith(['redirect_uris' => ['a' => 'http://localhost/callback']])];
        yield 'membre non-chaîne' => [self::documentWith(['redirect_uris' => ['http://localhost/callback', 1]])];
        yield 'membre null' => [self::documentWith(['redirect_uris' => ['http://localhost/callback', null]])];
        yield '11 membres' => [self::documentWith([
            'redirect_uris' => array_map(
                static fn (int $i): string => 'https://app.example.test/cb'.$i,
                range(1, ClientMetadataDocument::MAX_REDIRECT_URIS + 1),
            ),
        ])];
        // Un seul membre invalide refuse le document entier (échec fermé).
        yield 'http hors boucle locale' => [self::documentWith(['redirect_uris' => ['http://example.test/cb']])];
        yield 'un bon membre puis un http hors boucle' => [self::documentWith(['redirect_uris' => ['http://localhost/callback', 'http://example.test/cb']])];
        yield 'fragment' => [self::documentWith(['redirect_uris' => ['https://x.test/cb#f']])];
        yield 'schéma javascript' => [self::documentWith(['redirect_uris' => ['javascript:alert(1)']])];
        yield 'schéma privé' => [self::documentWith(['redirect_uris' => ['com.example.app:/cb']])];
        yield 'non normalisée' => [self::documentWith(['redirect_uris' => ['HTTPS://X.test/cb']])];
    }

    #[DataProvider('invalidRedirectUriLists')]
    public function testTheRedirectUrisAreRequiredBoundedAndEachValid(string $json): void
    {
        $this->assertRefusedAs(InvalidClientMetadataDocumentException::invalidRedirectUris(), $json);
    }

    public function testTenRedirectUrisAreAccepted(): void
    {
        $uris = array_map(
            static fn (int $i): string => 'https://claude.ai/cb'.$i,
            range(1, ClientMetadataDocument::MAX_REDIRECT_URIS),
        );

        $document = ClientMetadataDocument::fromJson($this->expected(), self::documentWith(['redirect_uris' => $uris]));

        self::assertCount(ClientMetadataDocument::MAX_REDIRECT_URIS, $document->redirectUris);
        self::assertTrue($document->redirectUris[9]->matches('https://claude.ai/cb10'));
    }

    /**
     * Une redirection hors boucle locale reste sur l'hôte du `client_id` : la
     * liste des domaines admis ne compare que cet hôte, et un chemin d'un hôte
     * listé peut servir un JSON choisi par un tiers. Égalité stricte, sans
     * sous-domaine implicite (comme la liste), sans préfixe de chemin.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function redirectUrisOutsideTheClientHost(): iterable
    {
        yield 'hôte tiers' => [['https://tiers.example/cb']];
        yield 'sous-domaine du client' => [['https://evil.claude.ai/cb']];
        yield 'domaine parent du client' => [['https://ai/cb']];
        yield 'hôte qui contient celui du client' => [['https://claude.ai.tiers.example/cb']];
        // Le même hôte que le `client_id`, mais un autre service : `CimdClientId` refuse
        // tout port pour que la cible reste le 443 de l'hôte, les redirections aussi.
        yield 'même hôte, autre port' => [['https://claude.ai:8443/cb']];
        yield 'un bon membre puis le même hôte sur un autre port' => [['https://claude.ai/cb', 'https://claude.ai:8443/cb']];
        yield 'un bon membre puis un hôte tiers' => [['https://claude.ai/api/mcp/auth_callback', 'https://tiers.example/cb']];
        yield 'une boucle locale puis un hôte tiers' => [['http://localhost/callback', 'https://tiers.example/cb']];
    }

    /**
     * @param list<string> $redirectUris
     */
    #[DataProvider('redirectUrisOutsideTheClientHost')]
    public function testARedirectOutsideTheClientHostIsRefused(array $redirectUris): void
    {
        $this->assertRefusedAs(
            InvalidClientMetadataDocumentException::redirectOutsideClientHost(),
            self::documentWith(['redirect_uris' => $redirectUris]),
        );
    }

    /**
     * Refusées en amont par `RedirectUri` (`notNormalized` : League\Uri retire `:443`,
     * la casse de l'hôte est abaissée) : ce n'est pas la règle de l'hôte qui les juge,
     * elles gardent le refus de forme. Non-régression, et preuve que les deux refus
     * restent distincts.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function redirectUrisRefusedUpstream(): iterable
    {
        yield 'même hôte, port 443 explicite (non normalisée)' => [['https://claude.ai:443/cb']];
        yield 'casse de l\'hôte (non normalisée)' => [['https://Claude.AI/cb']];
    }

    /**
     * @param list<string> $redirectUris
     */
    #[DataProvider('redirectUrisRefusedUpstream')]
    public function testANonNormalizedRedirectKeepsTheMalformedRefusal(array $redirectUris): void
    {
        $this->assertRefusedAs(
            InvalidClientMetadataDocumentException::invalidRedirectUris(),
            self::documentWith(['redirect_uris' => $redirectUris]),
        );
    }

    public function testTheOutsideHostRefusalIsDistinctAndNeverNamesAValue(): void
    {
        $outside = InvalidClientMetadataDocumentException::redirectOutsideClientHost()->getMessage();

        self::assertNotSame(InvalidClientMetadataDocumentException::invalidRedirectUris()->getMessage(), $outside);
        self::assertStringContainsString('redirect_uris', $outside);
        self::assertStringContainsString('client_id', $outside);
    }

    public function testARedirectOnTheClientHostIsAccepted(): void
    {
        $document = ClientMetadataDocument::fromJson($this->expected(), self::documentWith([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback', 'https://claude.ai/cb'],
        ]));

        self::assertCount(2, $document->redirectUris);
        self::assertTrue($document->redirectUris[0]->matches('https://claude.ai/api/mcp/auth_callback'));
        self::assertTrue($document->redirectUris[1]->matches('https://claude.ai/cb'));
    }

    public function testALoopbackRedirectIsAcceptedWhateverTheClientHost(): void
    {
        $document = ClientMetadataDocument::fromJson($this->expected(), self::documentWith([
            'redirect_uris' => ['http://localhost/callback', 'http://127.0.0.1/callback', 'http://[::1]/callback'],
        ]));

        self::assertCount(3, $document->redirectUris);
    }

    /**
     * La boucle locale garde son port (RFC 8252 §7.3) : un client natif ouvre un
     * port éphémère, Claude Code en tête.
     */
    public function testALoopbackRedirectKeepsItsPort(): void
    {
        $document = ClientMetadataDocument::fromJson($this->expected(), self::documentWith([
            'redirect_uris' => ['http://127.0.0.1:53682/callback', 'http://localhost:49152/callback', 'http://[::1]:8765/callback'],
        ]));

        self::assertCount(3, $document->redirectUris);
        self::assertTrue($document->redirectUris[0]->matches('http://127.0.0.1:60000/callback'));
    }

    public function testAHostMatchedClientAndALoopbackCanBeMixed(): void
    {
        $document = ClientMetadataDocument::fromJson($this->expected(), self::documentWith([
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback', 'http://localhost/callback'],
        ]));

        self::assertCount(2, $document->redirectUris);
    }

    public function testUnknownFieldsAreIgnored(): void
    {
        $document = ClientMetadataDocument::fromJson($this->expected(), self::documentWith([
            'client_uri' => 'https://claude.ai',
            'logo_uri' => 'http://169.254.169.254/latest/meta-data',
            'scope' => 'nominative:read admin:everything',
            'tos_uri' => 'https://claude.ai/tos',
            'jwks_uri' => 'https://claude.ai/jwks.json',
            'a_field_nobody_defined' => ['nested' => true],
        ]));

        self::assertSame('Claude Code', (string) $document->name);
        self::assertCount(2, $document->redirectUris);
    }

    /**
     * Chaque règle, fautive par une valeur portant un jeton aléatoire : le
     * message du refus, et celui de sa cause chaînée, n'en contiennent rien.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function faultyValues(): iterable
    {
        $token = bin2hex(random_bytes(8));

        yield 'client_id' => [['client_id' => 'https://claude.ai/'.$token], $token];
        yield 'client_secret' => [['client_secret' => $token], $token];
        yield 'token_endpoint_auth_method' => [['token_endpoint_auth_method' => $token], $token];
        yield 'grant_types' => [['grant_types' => [$token]], $token];
        yield 'response_types' => [['response_types' => [$token]], $token];
        yield 'client_name' => [['client_name' => $token."\u{0007}"], $token];
        yield 'redirect_uris' => [['redirect_uris' => ['http://'.$token.'.test/cb#'.$token]], $token];
        yield 'redirect_uris non-chaîne' => [['redirect_uris' => [[$token]]], $token];
    }

    /**
     * @param array<string, mixed> $replaced
     */
    #[DataProvider('faultyValues')]
    public function testARefusalNeverCopiesAValue(array $replaced, string $token): void
    {
        try {
            ClientMetadataDocument::fromJson($this->expected(), self::documentWith($replaced));
            self::fail('Le document aurait dû être refusé.');
        } catch (InvalidClientMetadataDocumentException $refusal) {
            self::assertStringNotContainsString($token, $refusal->getMessage());

            for ($cause = $refusal->getPrevious(); null !== $cause; $cause = $cause->getPrevious()) {
                self::assertStringNotContainsString($token, $cause->getMessage());
            }
        }
    }

    public function testAJsonErrorNeverCopiesTheText(): void
    {
        $token = bin2hex(random_bytes(8));

        try {
            ClientMetadataDocument::fromJson($this->expected(), '{"client_id": '.$token);
            self::fail('Le document aurait dû être refusé.');
        } catch (InvalidClientMetadataDocumentException $refusal) {
            self::assertStringNotContainsString($token, $refusal->getMessage());
            // La cause du décodeur reste chaînée pour le diagnostic.
            self::assertInstanceOf(JsonException::class, $refusal->getPrevious());
        }
    }

    public function testEachRefusalNamesItsField(): void
    {
        $messages = [
            'client_id' => InvalidClientMetadataDocumentException::clientIdMismatch()->getMessage(),
            'client_secret' => InvalidClientMetadataDocumentException::secretDeclared()->getMessage(),
            'token_endpoint_auth_method' => InvalidClientMetadataDocumentException::unsupportedAuthMethod()->getMessage(),
            'grant_types' => InvalidClientMetadataDocumentException::unsupportedGrantTypes()->getMessage(),
            'response_types' => InvalidClientMetadataDocumentException::unsupportedResponseTypes()->getMessage(),
            'client_name' => InvalidClientMetadataDocumentException::invalidName()->getMessage(),
            'redirect_uris' => InvalidClientMetadataDocumentException::invalidRedirectUris()->getMessage(),
            'redirect_uris (hôte)' => InvalidClientMetadataDocumentException::redirectOutsideClientHost()->getMessage(),
        ];

        foreach ($messages as $field => $message) {
            self::assertStringContainsString(explode(' ', $field)[0], $message);
        }

        self::assertCount(8, array_unique($messages));
    }
}
