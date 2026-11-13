<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Domain\ValueObject;

use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La forme d'un `client_id` qui est l'URL d'un document de métadonnées (CIMD,
 * #651) : `https`, un nom d'hôte DNS en minuscules, aucun port, un chemin sans
 * segment vide, `.` ni `..`. Une chaîne hors de cette forme n'est qu'un
 * identifiant de ligne (`tryFrom()` rend `null`), jamais une erreur.
 *
 * Première des cinq couches contre le SSRF (plan de la tâche 12, D-D) : ce
 * qui passe ici est la seule chose pour laquelle une récupération peut
 * partir. Aucune normalisation : la valeur gardée est la chaîne reçue.
 */
final class CimdClientIdTest extends TestCase
{
    private const string SHORTEST_PREFIX = 'https://claude.ai/';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedIds(): iterable
    {
        yield 'Claude' => ['https://claude.ai/oauth/claude-code-client-metadata', 'claude.ai'];
        yield 'ChatGPT' => ['https://chatgpt.com/oauth/client.json', 'chatgpt.com'];
        yield 'sous-domaine' => ['https://a.example.test/x', 'a.example.test'];
        yield '255 caractères' => [self::urlOfLength(McpClientId::MAX_LENGTH), 'claude.ai'];
    }

    #[DataProvider('acceptedIds')]
    public function testAMetadataUrlIsRecognisedAndKeptAsReceived(string $url, string $expectedHost): void
    {
        $clientId = CimdClientId::tryFrom($url);

        self::assertNotNull($clientId);
        self::assertSame($url, $clientId->value());
        self::assertSame($expectedHost, $clientId->host());
        self::assertTrue($clientId->toMcpClientId()->equals(McpClientId::fromString($url)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedIds(): iterable
    {
        yield 'identifiant court' => ['claude-code'];
        yield 'http' => ['http://claude.ai/x'];
        yield 'schéma en majuscules' => ['HTTPS://claude.ai/x'];
        yield 'hôte en majuscules' => ['https://Claude.ai/x'];
        yield 'sans chemin' => ['https://claude.ai'];
        yield 'chemin réduit à la barre' => ['https://claude.ai/'];
        yield 'port' => ['https://claude.ai:443/x'];
        yield 'segment .. au milieu' => ['https://claude.ai/a/../b'];
        yield 'segment .' => ['https://claude.ai/./b'];
        yield 'segment .. final' => ['https://claude.ai/a/..'];
        yield 'adresse IPv4' => ['https://127.0.0.1/x'];
        yield 'adresse en entier décimal' => ['https://2130706433/x'];
        yield 'hôte sans point' => ['https://localhost/x'];
        yield 'hôte enraciné' => ['https://claude.ai./x'];
        yield 'label commençant par un tiret' => ['https://-claude.ai/x'];
        yield 'label vide' => ['https://claude..ai/x'];
        yield 'informations d\'utilisateur' => ['https://user@claude.ai/x'];
        yield 'requête' => ['https://claude.ai/x?y=1'];
        yield 'fragment' => ['https://claude.ai/x#f'];
        yield 'segment .. encodé' => ['https://claude.ai/%2e%2e/x'];
        yield 'adresse IPv6' => ['https://[::1]/x'];
        yield 'espace final' => ['https://claude.ai/x '];
        yield 'saut de ligne final' => ["https://claude.ai/x\n"];
        yield '256 caractères' => [self::urlOfLength(McpClientId::MAX_LENGTH + 1)];
    }

    #[DataProvider('refusedIds')]
    public function testAStringOutsideTheCimdShapeIsOnlyARowIdentifier(string $value): void
    {
        self::assertNull(CimdClientId::tryFrom($value));
    }

    /**
     * Un segment vide n'est écrit par aucun document de métadonnées, et un
     * serveur le lit à sa façon (`//x` fusionné en `/x` ou servi tel quel) :
     * l'URL demandée et celle que le document déclare pourraient désigner
     * deux ressources. Refusé comme `.` et `..`, barre finale comprise.
     *
     * @return iterable<string, array{string}>
     */
    public static function idsWithAnEmptyPathSegment(): iterable
    {
        yield 'segment vide en tête' => ['https://claude.ai//x'];
        yield 'segment vide au milieu' => ['https://claude.ai/a//b'];
        yield 'segment vide final' => ['https://claude.ai/a/'];
    }

    #[DataProvider('idsWithAnEmptyPathSegment')]
    public function testAPathWithAnEmptySegmentIsRefused(string $value): void
    {
        self::assertNull(CimdClientId::tryFrom($value));
    }

    /** Une URL de la forme admise, de la longueur demandée exactement. */
    private static function urlOfLength(int $length): string
    {
        return self::SHORTEST_PREFIX.str_repeat('a', $length - \strlen(self::SHORTEST_PREFIX));
    }
}
