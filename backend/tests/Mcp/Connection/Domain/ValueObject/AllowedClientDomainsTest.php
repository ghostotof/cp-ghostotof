<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Domain\ValueObject;

use App\Mcp\Connection\Domain\Exception\InvalidAllowedClientDomainException;
use App\Mcp\Connection\Domain\ValueObject\AllowedClientDomains;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La liste des domaines dont un client CIMD est admis (#651) : l'hôte du
 * `client_id` doit y figurer tel quel — ni sous-domaine implicite, ni suffixe,
 * ni préfixe. Une liste vide éteint CIMD ; une entrée qui n'est pas un nom
 * d'hôte en minuscules est un défaut de configuration, refusé à la
 * construction plutôt que de n'autoriser jamais rien en silence.
 */
final class AllowedClientDomainsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function listedHosts(): iterable
    {
        yield 'premier domaine' => ['https://claude.ai/oauth/claude-code-client-metadata'];
        yield 'second domaine' => ['https://chatgpt.com/oauth/client.json'];
    }

    #[DataProvider('listedHosts')]
    public function testAHostOfTheListIsAllowed(string $url): void
    {
        $domains = AllowedClientDomains::fromList(['claude.ai', 'chatgpt.com']);

        self::assertTrue($domains->allows($this->clientId($url)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unlistedHosts(): iterable
    {
        yield 'sous-domaine d\'un domaine listé' => ['https://evil.claude.ai/x'];
        yield 'domaine listé en préfixe' => ['https://claude.ai.evil.test/x'];
        yield 'domaine listé en suffixe de label' => ['https://notclaude.ai/x'];
    }

    #[DataProvider('unlistedHosts')]
    public function testOnlyTheExactHostIsAllowed(string $url): void
    {
        $domains = AllowedClientDomains::fromList(['claude.ai', 'chatgpt.com']);

        self::assertFalse($domains->allows($this->clientId($url)));
    }

    public function testAnEmptyListAllowsNothing(): void
    {
        $domains = AllowedClientDomains::fromList([]);

        self::assertFalse($domains->allows($this->clientId('https://claude.ai/oauth/claude-code-client-metadata')));
    }

    /**
     * Le point unique de la règle « identifiant d'un client CIMD d'un domaine
     * vérifié » : `CimdClientId::tryFrom()` puis `allows()`, que le
     * pré-enregistrement (registrar, validateur du backoffice) applique.
     */
    #[DataProvider('listedHosts')]
    public function testTheMetadataUrlOfAListedHostIsAVerifiedClientId(string $url): void
    {
        self::assertTrue(AllowedClientDomains::fromList(['claude.ai', 'chatgpt.com'])->isVerifiedClientId($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notVerifiedClientIds(): iterable
    {
        yield 'domaine non listé' => ['https://client.example.test/metadata.json'];
        yield 'sous-domaine d\'un domaine listé' => ['https://evil.claude.ai/x'];
        yield 'domaine listé avec un port' => ['https://claude.ai:443/metadata.json'];
        yield 'domaine listé en http' => ['http://claude.ai/metadata.json'];
        yield 'domaine listé en majuscules' => ['https://Claude.ai/metadata.json'];
        yield 'domaine listé sans chemin' => ['https://claude.ai'];
        yield 'identifiant ordinaire' => ['claude-code-dev'];
    }

    #[DataProvider('notVerifiedClientIds')]
    public function testAnythingElseIsNotAVerifiedClientId(string $clientId): void
    {
        self::assertFalse(AllowedClientDomains::fromList(['claude.ai', 'chatgpt.com'])->isVerifiedClientId($clientId));
    }

    public function testAnEmptyListVerifiesNoClientId(): void
    {
        self::assertFalse(AllowedClientDomains::fromList([])->isVerifiedClientId('https://claude.ai/oauth/claude-code-client-metadata'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedEntries(): iterable
    {
        yield 'majuscule' => ['Claude.ai'];
        yield 'barre finale' => ['claude.ai/'];
        yield 'vide' => [''];
    }

    #[DataProvider('malformedEntries')]
    public function testAnEntryThatIsNoLowerCaseHostNameIsRefusedWithoutQuotingIt(string $entry): void
    {
        try {
            AllowedClientDomains::fromList(['claude.ai', $entry]);
            self::fail('Entrée acceptée à tort.');
        } catch (InvalidAllowedClientDomainException $exception) {
            self::assertSame(InvalidAllowedClientDomainException::malformed()->getMessage(), $exception->getMessage());
        }
    }

    private function clientId(string $url): CimdClientId
    {
        $clientId = CimdClientId::tryFrom($url);
        self::assertNotNull($clientId, 'L\'URL du test doit avoir la forme CIMD.');

        return $clientId;
    }
}
