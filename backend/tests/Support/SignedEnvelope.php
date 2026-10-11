<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Infrastructure\Messenger\AuthenticatedEnvelopeSerializer;
use LogicException;
use Psr\Log\NullLogger;
use SensitiveParameter;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Une enveloppe telle que l'application la mettrait en file (issue #444) : le
 * corps donné, signé par AuthenticatedEnvelopeSerializer sous le secret donné.
 *
 * Le sérialiseur authentifiant signe ce que son sérialiseur interne produit :
 * un sérialiseur interne qui rend le corps fabriqué le fait signer, sans
 * recopier ici le calcul du MAC ni la dérivation de sa clé.
 */
final class SignedEnvelope
{
    /**
     * @return array{body: string, headers?: array<string, string>}
     */
    public static function of(string $body, #[SensitiveParameter] string $secret): array
    {
        $fabricatedBody = new readonly class($body) implements SerializerInterface {
            public function __construct(private string $body)
            {
            }

            /**
             * @return array{body: string}
             */
            public function encode(Envelope $envelope): array
            {
                return ['body' => $this->body];
            }

            /**
             * @param array<mixed> $encodedEnvelope
             */
            public function decode(array $encodedEnvelope): Envelope
            {
                throw new LogicException('Ce sérialiseur ne fait que rendre un corps fabriqué, à signer.');
            }
        };

        return new AuthenticatedEnvelopeSerializer($fabricatedBody, $secret, true, new NullLogger())->encode(new Envelope(new stdClass()));
    }
}
