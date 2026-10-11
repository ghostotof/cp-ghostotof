<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Security;

use App\Security\Authentication\Infrastructure\Http\CsrfCookieTokenSigner;
use App\Security\Authentication\Infrastructure\Log\LoginIdentifierFingerprint;
use App\Shared\Infrastructure\Cache\AuthenticatedCacheMarshaller;
use App\Shared\Infrastructure\Messenger\AuthenticatedEnvelopeSerializer;
use App\Shared\Infrastructure\Security\SecretKeyDerivation;
use App\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Passer les quatre usages d'`APP_SECRET` par KeyedMac n'est pas une rotation
 * (issue #487) : un MAC signé par le code d'avant est accepté par le code
 * d'après, et le code d'après signe ce que le code d'avant aurait signé.
 *
 * « Le code d'avant » est écrit ici en clair, une fois par usage : son
 * contexte **littéral** et son appel `hash_hmac()` d'avant #487. Une valeur
 * figée serait plus directe, mais la clé des tests est tirée à chaque
 * exécution (TestKeys) ; la formule, elle, ne dépend que du contexte et de
 * l'encodage, précisément ce qu'une rotation changerait. Changer un
 * `KEY_CONTEXT` fait donc rougir ce test : c'est voulu, une rotation se
 * décide et s'annonce dans les notes de release, elle ne passe pas en
 * refactorisation.
 */
final class DerivedMacsSurviveKeyedMacTest extends TestCase
{
    public function testACsrfCookieSignedBeforeIsStillValid(): void
    {
        $random = bin2hex(random_bytes(32));
        $signer = new CsrfCookieTokenSigner(TestKeys::material());

        self::assertTrue($signer->isValid($random.'.'.$this->hexMacBefore('cp-ghostotof/csrf-cookie/v1', $random)));
    }

    public function testACsrfCookieIssuedNowIsWhatTheCodeBeforeSigned(): void
    {
        [$random, $signature] = explode('.', new CsrfCookieTokenSigner(TestKeys::material())->issue());

        self::assertSame($this->hexMacBefore('cp-ghostotof/csrf-cookie/v1', $random), $signature);
    }

    /**
     * Une empreinte n'est jamais vérifiée, seulement comparée d'une ligne de
     * journal à l'autre : celles d'avant et d'après doivent être égales.
     */
    public function testALoginFingerprintIsWhatTheCodeBeforeComputed(): void
    {
        self::assertSame(
            substr($this->hexMacBefore('cp-ghostotof/login-identifier-fingerprint/v1', 'jane'), 0, 6),
            new LoginIdentifierFingerprint(TestKeys::material())->of('Jane'),
        );
    }

    public function testAnEnvelopeSignedBeforeIsStillDecoded(): void
    {
        $message = new stdClass();
        $message->text = 'bonjour';
        $encoded = new PhpSerializer()->encode(new Envelope($message));
        $encoded['headers'][AuthenticatedEnvelopeSerializer::HEADER] = $this->hexMacBefore('cp-ghostotof/messenger-envelope/v2', $encoded['body']);

        $decoded = $this->envelopeSerializer()->decode($encoded)->getMessage();

        self::assertEquals($message, $decoded);
    }

    public function testAnEnvelopeEncodedNowCarriesTheMacTheCodeBeforeComputed(): void
    {
        $encoded = $this->envelopeSerializer()->encode(new Envelope(new stdClass()));

        self::assertSame(
            $this->hexMacBefore('cp-ghostotof/messenger-envelope/v2', $encoded['body']),
            $encoded['headers'][AuthenticatedEnvelopeSerializer::HEADER] ?? null,
        );
    }

    public function testACacheValueSignedBeforeIsStillRead(): void
    {
        $serialized = new DefaultMarshaller()->marshall(['key' => ['tokens' => 4]], $failed)['key'] ?? null;
        self::assertIsString($serialized);

        $stored = $this->binaryMacBefore('cp-ghostotof/cache-value/v1', $serialized).$serialized;

        self::assertSame(['tokens' => 4], $this->cacheMarshaller()->unmarshall($stored));
    }

    public function testACacheValueWrittenNowCarriesTheMacTheCodeBeforeComputed(): void
    {
        $stored = $this->cacheMarshaller()->marshall(['key' => ['tokens' => 4]], $failed)['key'] ?? null;
        self::assertIsString($stored);

        $serialized = substr($stored, 32);

        self::assertSame($this->binaryMacBefore('cp-ghostotof/cache-value/v1', $serialized), substr($stored, 0, 32));
    }

    /**
     * `hash_hmac('sha256', …)` sous la clé dérivée, en hexadécimal : le
     * calcul du cookie CSRF, de l'empreinte et des enveloppes avant #487.
     */
    private function hexMacBefore(string $context, string $data): string
    {
        return hash_hmac('sha256', $data, SecretKeyDerivation::derive(TestKeys::material(), $context));
    }

    /**
     * Le même calcul en binaire : celui des valeurs de cache avant #487.
     */
    private function binaryMacBefore(string $context, string $data): string
    {
        return hash_hmac('sha256', $data, SecretKeyDerivation::derive(TestKeys::material(), $context), true);
    }

    private function envelopeSerializer(): AuthenticatedEnvelopeSerializer
    {
        return new AuthenticatedEnvelopeSerializer(new PhpSerializer(), TestKeys::material());
    }

    private function cacheMarshaller(): AuthenticatedCacheMarshaller
    {
        return new AuthenticatedCacheMarshaller(new DefaultMarshaller(), TestKeys::material());
    }
}
