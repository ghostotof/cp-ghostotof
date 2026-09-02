<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Http;

use App\Security\Authentication\Infrastructure\Http\CsrfCookieTokenSigner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Point d'audit B1 : la valeur du cookie CSRF est désormais signée par
 * APP_SECRET. Ce test couvre l'émission, la validation, et le rejet de toutes
 * les formes de jeton non conformes.
 */
final class CsrfCookieTokenSignerTest extends TestCase
{
    private const string SECRET = 'unit-test-app-secret';

    private CsrfCookieTokenSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new CsrfCookieTokenSigner(self::SECRET);
    }

    public function testIssuedTokenIsValid(): void
    {
        self::assertTrue($this->signer->isValid($this->signer->issue()));
    }

    public function testIssuedTokenHasRandomThenSignatureShape(): void
    {
        $token = $this->signer->issue();

        // 64 hexadécimaux (random_bytes(32)) . 64 hexadécimaux (HMAC-SHA256).
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\.[0-9a-f]{64}$/', $token);
    }

    public function testTwoIssuedTokensDiffer(): void
    {
        self::assertNotSame($this->signer->issue(), $this->signer->issue());
    }

    public function testTokenSignedByAnotherSecretIsRejected(): void
    {
        $foreignToken = (new CsrfCookieTokenSigner('another-secret'))->issue();

        self::assertFalse($this->signer->isValid($foreignToken));
    }

    public function testTamperedRandomPartIsRejected(): void
    {
        [$random, $signature] = explode('.', $this->signer->issue());
        $tampered = substr($random, 0, -1).($random[-1] === 'a' ? 'b' : 'a').'.'.$signature;

        self::assertFalse($this->signer->isValid($tampered));
    }

    #[DataProvider('malformedTokens')]
    public function testMalformedTokenIsRejected(string $token): void
    {
        self::assertFalse($this->signer->isValid($token));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokens(): iterable
    {
        yield 'empty string' => [''];
        yield 'no separator' => ['deadbeef'];
        yield 'empty random part' => ['.deadbeef'];
        yield 'empty signature part' => ['deadbeef.'];
        yield 'too many parts' => ['a.b.c'];
        yield 'plain legacy value (no signature)' => [bin2hex(random_bytes(32))];
    }
}
