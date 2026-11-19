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
            // Le serveur ajoute son propre `iss` (RFC 9207) : jamais deux.
            'iss dans la requête' => ['https://app.example/cb?iss=x'],
            'iss parmi d\'autres paramètres' => ['http://localhost/cb?a=1&iss=x'],
            'iss %-encodé' => ['https://app.example/cb?%69ss=x'],
            'iss en tableau' => ['https://app.example/cb?iss%5B%5D=x'],
            'iss sans valeur' => ['https://app.example/cb?iss'],
        ];
    }

    /**
     * Un paramètre dont le nom ne fait que commencer par `iss` n'est pas `iss`.
     */
    public function testARegistrationWhoseQueryMerelyStartsWithIssIsAccepted(): void
    {
        self::assertSame('https://app.example/cb?issuer=x', (string) RedirectUri::fromString('https://app.example/cb?issuer=x'));
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

    public function testTheHostIsTheLowercaseOneOfTheRegisteredValue(): void
    {
        self::assertSame('claude.ai', RedirectUri::fromString('https://claude.ai/api/mcp/auth_callback')->host());
        self::assertSame('claude.ai', RedirectUri::fromString('https://claude.ai:8443/cb')->host());
        self::assertSame('localhost', RedirectUri::fromString('http://localhost:49152/callback')->host());
        self::assertSame('[::1]', RedirectUri::fromString('http://[::1]/callback')->host());
    }

    public function testThePortIsTheExplicitOneOrNull(): void
    {
        self::assertSame(8443, RedirectUri::fromString('https://claude.ai:8443/cb')->port());
        self::assertSame(49152, RedirectUri::fromString('http://localhost:49152/callback')->port());
        self::assertSame(8765, RedirectUri::fromString('http://[::1]:8765/callback')->port());
        self::assertNull(RedirectUri::fromString('https://claude.ai/cb')->port());
        self::assertNull(RedirectUri::fromString('http://127.0.0.1/callback')->port());
    }

    public function testAnExplicitDefaultPortIsNotANormalForm(): void
    {
        // League\Uri retire `:443` : la chaîne écrite n'est plus sa forme, refusée à l'enregistrement.
        $this->expectException(InvalidRedirectUriException::class);

        RedirectUri::fromString('https://claude.ai:443/cb');
    }

    public function testOnlyAPlainHttpRedirectIsALoopback(): void
    {
        self::assertTrue(RedirectUri::fromString('http://localhost/callback')->isLoopback());
        self::assertTrue(RedirectUri::fromString('http://127.0.0.1/callback')->isLoopback());
        self::assertTrue(RedirectUri::fromString('http://[::1]/callback')->isLoopback());
        self::assertFalse(RedirectUri::fromString('https://localhost/callback')->isLoopback());
        self::assertFalse(RedirectUri::fromString('https://app.example/callback')->isLoopback());
    }

    /**
     * Une redirection de plus de `MAX_LENGTH` octets est refusée avant toute
     * analyse : League\Uri n'a pas à lire ce qu'un enregistrement ne peut pas
     * porter (T8 de #652). Le message est celui de la fabrique, constant.
     */
    public function testAnOverlongRedirectIsRefusedBeforeParsing(): void
    {
        try {
            RedirectUri::fromString('https://a.example.test/cb?x='.str_repeat('a', 2048));
            self::fail('La redirection aurait dû être refusée.');
        } catch (InvalidRedirectUriException $exception) {
            self::assertSame(InvalidRedirectUriException::tooLong()->getMessage(), $exception->getMessage());
        }
    }

    /**
     * La borne est inclusive : une redirection de `MAX_LENGTH` octets pile,
     * par ailleurs valide, est acceptée.
     */
    public function testARedirectOfExactlyTheMaximumLengthIsAccepted(): void
    {
        $prefix = 'https://a.example.test/cb?x=';
        $value = $prefix.str_repeat('a', RedirectUri::MAX_LENGTH - \strlen($prefix));

        self::assertSame(RedirectUri::MAX_LENGTH, \strlen($value));
        self::assertSame($value, (string) RedirectUri::fromString($value));
    }

    /**
     * 1 001 paramètres tiennent sous la borne de longueur (`a&a&…`, 2 027
     * octets) et dépassent `max_input_vars` (1 000 par défaut) : `parse_str()`
     * émettrait « Input variables exceeded », que dev et test changent en
     * exception. Le compte est donc fait avant : le gestionnaire ci-dessous
     * échoue sur tout avertissement, et prouve que `parse_str()` n'est pas appelé.
     */
    public function testAQueryWithTooManyParametersIsRefusedBeforeParsing(): void
    {
        $value = 'https://a.example.test/cb?'.implode('&', array_fill(0, 1001, 'a'));

        set_error_handler(static function (int $severity, string $message): never {
            self::fail(\sprintf('Un avertissement a été émis : %s', $message));
        });

        try {
            RedirectUri::fromString($value);
            self::fail('La redirection aurait dû être refusée.');
        } catch (InvalidRedirectUriException $exception) {
            self::assertSame(InvalidRedirectUriException::tooManyQueryParameters()->getMessage(), $exception->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    /**
     * La borne est inclusive : 50 paramètres passent, 51 sont refusés.
     */
    public function testFiftyQueryParametersAreAccepted(): void
    {
        $query = static fn (int $count): string => implode('&', array_map(static fn (int $i): string => \sprintf('p%d=1', $i), range(0, $count - 1)));

        $fifty = 'https://a.example.test/cb?'.$query(50);
        self::assertSame($fifty, (string) RedirectUri::fromString($fifty));

        $this->expectException(InvalidRedirectUriException::class);
        $this->expectExceptionMessage(InvalidRedirectUriException::tooManyQueryParameters()->getMessage());

        RedirectUri::fromString('https://a.example.test/cb?'.$query(51));
    }
}
