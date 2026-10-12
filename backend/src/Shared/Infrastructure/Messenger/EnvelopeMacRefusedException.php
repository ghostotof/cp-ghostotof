<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * Refus d'une enveloppe par {@see AuthenticatedEnvelopeSerializer} : MAC
 * absent, faux ou qui n'est pas une chaîne (issues #444, #450).
 *
 * Un échec de décodage comme un autre — il garde l'enveloppe reçue, jamais un
 * objet de son corps, et
 * {@see \Symfony\Component\Messenger\Middleware\DecodeFailedMessageMiddleware}
 * le lève tel quel —, mais définitif : il part dans `failed` sans réessai. Un
 * réessai ré-encode l'échec sous la clé du worker qui réessaie, alors que
 * l'enveloppe d'origine qu'il garde reste celle refusée : aucun réessai ne la
 * sauve, pas même au changement de clé d'un rollout. Les trois réessais du
 * transport n'étaient que du bruit, et quatre décodages par message forgé.
 *
 * Un échec du sérialiseur interne reste un {@see MessageDecodingFailedException}
 * ordinaire, réessayé : un worker d'une autre version peut le lire.
 */
final class EnvelopeMacRefusedException extends MessageDecodingFailedException implements UnrecoverableExceptionInterface
{
    /**
     * @param array{body: string, headers?: array<string, string>} $encodedEnvelope
     */
    public static function missing(array $encodedEnvelope): Envelope
    {
        return new Envelope(new self('Enveloppe Messenger sans MAC refusée (issue #444).', 0, null, $encodedEnvelope));
    }

    /**
     * @param array{body: string, headers?: array<string, string>} $encodedEnvelope
     */
    public static function invalid(array $encodedEnvelope): Envelope
    {
        return new Envelope(new self('Enveloppe Messenger au MAC invalide refusée (issue #444).', 0, null, $encodedEnvelope));
    }
}
