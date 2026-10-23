<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Domain\ValueObject;

use App\Mcp\Connection\Domain\Exception\InvalidRedirectUriException;
use App\Mcp\Connection\Domain\ValueObject\RedirectUri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La redirection enregistrée d'un client MCP (#647) : `https` comparée en
 * égalité stricte de la chaîne, ou boucle locale `http` comparée sans son port
 * (RFC 8252 §7.3 — un client natif ouvre un port éphémère qu'il ne peut pas
 * annoncer d'avance). Tout le reste est refusé à l'enregistrement.
 */
final class RedirectUriTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function matchingPairs(): array
    {
        return [
            'localhost, port éphémère' => ['http://localhost/callback', 'http://localhost:53412/callback'],
            '127.0.0.1, port éphémère' => ['http://127.0.0.1/callback', 'http://127.0.0.1:1/callback'],
            '[::1], port éphémère' => ['http://[::1]/cb', 'http://[::1]:8000/cb'],
            'boucle locale sans port demandé' => ['http://localhost/callback', 'http://localhost/callback'],
            'port enregistré ignoré aussi' => ['http://localhost:8080/callback', 'http://localhost:9/callback'],
            'schéma et hôte sans casse (RFC 3986)' => ['http://localhost/callback', 'HTTP://LocalHost:5/callback'],
            'requête égale' => ['http://localhost/cb?client=a', 'http://localhost:5/cb?client=a'],
            'https, elle-même' => ['https://app.example/cb', 'https://app.example/cb'],
            'port 65535' => ['http://localhost/callback', 'http://localhost:65535/callback'],
            'encodage %xx déjà écrit' => ['http://localhost/c%20b', 'http://localhost:5/c%20b'],
        ];
    }

    #[DataProvider('matchingPairs')]
    public function testARequestedRedirectMatchesItsRegistration(string $registered, string $requested): void
    {
        self::assertTrue(RedirectUri::fromString($registered)->matches($requested));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedPairs(): array
    {
        return [
            'autre chemin' => ['http://localhost/callback', 'http://localhost:5/other'],
            'autre hôte' => ['http://localhost/callback', 'http://evil.example/callback'],
            'hôte qui commence par localhost' => ['http://localhost/callback', 'http://localhost.evil.example/callback'],
            'https contre http' => ['http://localhost/callback', 'https://localhost/callback'],
            'autre boucle locale' => ['http://localhost/callback', 'http://127.0.0.1/callback'],
            'chemin en casse différente' => ['http://localhost/Callback', 'http://localhost/callback'],
            'autre requête' => ['http://localhost/cb?client=a', 'http://localhost:5/cb?client=b'],
            'requête ajoutée' => ['http://localhost/cb', 'http://localhost:5/cb?client=a'],
            'fragment demandé' => ['http://localhost/callback', 'http://localhost:5/callback#x'],
            'fragment vide demandé' => ['http://localhost/callback', 'http://localhost:5/callback#'],
            'identifiants demandés' => ['http://localhost/callback', 'http://u@localhost:5/callback'],
            'demande illisible' => ['http://[::1]/cb', 'http://[::1'],
            'https, autre port' => ['https://app.example/cb', 'https://app.example:444/cb'],
            'https, port par défaut écrit' => ['https://app.example/cb', 'https://app.example:443/cb'],
            'https, hôte en majuscules' => ['https://app.example/cb', 'https://APP.example/cb'],
            'https, barre finale' => ['https://app.example/cb', 'https://app.example/cb/'],
            'https, requête ajoutée' => ['https://app.example/cb', 'https://app.example/cb?x=1'],
            // Revue de #647 : la demande doit être écrite sous sa forme
            // normalisée, le serveur redirigeant vers la chaîne demandée.
            'espace brute, que League encoderait' => ['http://localhost/c%20b', 'http://localhost:5/c b'],
            'https, espace brute' => ['https://app.example/c%20b', 'https://app.example/c b'],
            'hôte en pleine chasse' => ['http://localhost/callback', "http://\u{FF4C}\u{FF4F}\u{FF43}\u{FF41}\u{FF4C}\u{FF48}\u{FF4F}\u{FF53}\u{FF54}:5/callback"],
            'hôte %-encodé' => ['http://localhost/callback', 'http://%6Cocalhost:5/callback'],
            'hôte %-encodé, minuscules' => ['http://localhost/callback', 'http://%6cocalhost:5/callback'],
            'port vide (refusé, choix de #647)' => ['http://localhost/callback', 'http://localhost:/callback'],
            'port à zéro de tête' => ['http://localhost/callback', 'http://localhost:05/callback'],
            'port 0' => ['http://localhost/callback', 'http://localhost:0/callback'],
            'port 65536' => ['http://localhost/callback', 'http://localhost:65536/callback'],
            'port 99999' => ['http://localhost/callback', 'http://localhost:99999/callback'],
        ];
    }

    #[DataProvider('refusedPairs')]
    public function testARequestedRedirectOutsideItsRegistrationIsRefused(string $registered, string $requested): void
    {
        self::assertFalse(RedirectUri::fromString($registered)->matches($requested));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedRegistrations(): array
    {
        return [
            'http hors boucle locale' => ['http://app.example/cb'],
            'javascript:' => ['javascript:alert(1)'],
            'schéma privé (RFC 8252 §7.1, hors périmètre)' => ['com.example.app:/callback'],
            'fragment' => ['https://a.example/cb#x'],
            'fragment vide' => ['https://a.example/cb#'],
            'identifiants' => ['https://u:p@a.example/cb'],
            'identifiants vides' => ['https://@a.example/cb'],
            'relative' => ['/callback'],
            'sans hôte' => ['https:///cb'],
            'vide' => [''],
            'illisible' => ['http://[::1'],
            'localhost suivi d\'un point' => ['http://localhost./cb'],
            'boucle locale écrite autrement' => ['http://[0:0:0:0:0:0:0:1]/cb'],
            'pas sous sa forme normalisée (schéma)' => ['HTTPS://app.example/cb'],
            'pas sous sa forme normalisée (espace)' => ['https://app.example/c b'],
            'pas sous sa forme normalisée (port vide)' => ['https://app.example:/cb'],
            'pas sous sa forme normalisée (pleine chasse)' => ["http://\u{FF4C}\u{FF4F}\u{FF43}\u{FF41}\u{FF4C}\u{FF48}\u{FF4F}\u{FF53}\u{FF54}/cb"],
            'pas sous sa forme normalisée (hôte %-encodé)' => ['http://%6Cocalhost/cb'],
            'port 0' => ['http://localhost:0/cb'],
            'port 65536' => ['https://app.example:65536/cb'],
            'port 99999' => ['https://app.example:99999/cb'],
        ];
    }

    #[DataProvider('refusedRegistrations')]
    public function testAnInvalidRedirectIsRefusedAtRegistration(string $value): void
    {
        $this->expectException(InvalidRedirectUriException::class);

        RedirectUri::fromString($value);
    }

    /**
     * Le message d'un refus ne cite jamais la valeur : celui de League\Uri la
     * cite (« The uri `…` contains invalid characters »), il n'est pas repris.
     */
    public function testARefusalNeverQuotesTheValue(): void
    {
        try {
            RedirectUri::fromString('https://app.example/sentinelle ');
            self::fail('La redirection aurait dû être refusée.');
        } catch (InvalidRedirectUriException $exception) {
            self::assertStringNotContainsString('sentinelle', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testTheRegisteredValueIsKeptAsGiven(): void
    {
        self::assertSame('http://[::1]:8000/cb', (string) RedirectUri::fromString('http://[::1]:8000/cb'));
        self::assertSame('https://app.example/cb?client=a', (string) RedirectUri::fromString('https://app.example/cb?client=a'));
        self::assertSame('https://app.example:65535/cb', (string) RedirectUri::fromString('https://app.example:65535/cb'));
        self::assertSame('http://localhost:1/cb', (string) RedirectUri::fromString('http://localhost:1/cb'));
    }

    public function testOnlyAPlainHttpRedirectIsALoopback(): void
    {
        self::assertTrue(RedirectUri::fromString('http://localhost/callback')->isLoopback());
        self::assertTrue(RedirectUri::fromString('http://127.0.0.1/callback')->isLoopback());
        self::assertTrue(RedirectUri::fromString('http://[::1]/callback')->isLoopback());
        self::assertFalse(RedirectUri::fromString('https://localhost/callback')->isLoopback());
        self::assertFalse(RedirectUri::fromString('https://app.example/callback')->isLoopback());
    }
}
