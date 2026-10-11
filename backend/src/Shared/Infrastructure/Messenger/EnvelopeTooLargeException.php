<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * Refus d'une enveloppe dont le corps dépasse la borne de
 * {@see AuthenticatedEnvelopeSerializer}, avant tout calcul de MAC (issue #469).
 *
 * Définitif comme {@see EnvelopeMacRefusedException} : droit dans `failed`,
 * sans réessai. Mais à la différence de ce dernier, il ne garde **pas**
 * l'enveloppe reçue : un corps de plusieurs Mo, recopié dans `failed`, puis
 * relu par `messenger:failed:show`, est précisément ce qu'il écarte. Il garde à
 * la place un marqueur, corps vide et taille en en-tête, que le sérialiseur
 * redécode en ce même refus : {@see \Symfony\Component\Messenger\Middleware\DecodeFailedMessageMiddleware}
 * redécode l'enveloppe gardée, et un corps vide sans MAC y serait sinon refusé
 * « sans MAC », la cause réelle perdue.
 *
 * Le marqueur n'authentifie rien et n'en a pas besoin : forgé, il ne mène qu'à
 * ce refus. Le message ne donne que la taille, jamais le corps.
 */
final class EnvelopeTooLargeException extends MessageDecodingFailedException implements UnrecoverableExceptionInterface
{
    public const string SIZE_HEADER = 'X-Envelope-Refused-Size';

    public static function forSize(int $bytes): Envelope
    {
        return new Envelope(new self(
            \sprintf('Enveloppe Messenger de %d octets refusée avant son MAC : au-delà de %d (issue #469).', $bytes, AuthenticatedEnvelopeSerializer::MAX_BODY_BYTES),
            0,
            null,
            ['body' => '', 'headers' => [self::SIZE_HEADER => (string) $bytes]],
        ));
    }

    /**
     * La taille que porte un marqueur de refus, ou null si l'enveloppe n'en est
     * pas un : un corps non vide, ou un en-tête qui n'est pas un nombre.
     *
     * @param array{body: string, headers?: array<string, mixed>} $encodedEnvelope
     */
    public static function markedSize(array $encodedEnvelope): ?int
    {
        $size = $encodedEnvelope['headers'][self::SIZE_HEADER] ?? null;

        if ('' !== $encodedEnvelope['body'] || !\is_string($size) || 1 !== preg_match('/\A\d{1,12}\z/', $size)) {
            return null;
        }

        return (int) $size;
    }
}
