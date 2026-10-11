<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use Exception;
use ReflectionProperty;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * Refus d'une enveloppe dont la taille encodée, corps et en-têtes, dépasse la
 * borne de {@see AuthenticatedEnvelopeSerializer}, avant tout calcul de MAC
 * (issue #469).
 *
 * Définitif comme {@see EnvelopeMacRefusedException} : droit dans `failed`,
 * sans réessai. Mais à la différence de ce dernier, il ne garde **rien** de
 * l'enveloppe reçue : quelques Mo recopiés dans `failed`, puis relus par
 * `messenger:failed:show`, sont précisément ce qu'il écarte. Il garde à la
 * place un marqueur, corps vide et taille en en-tête, que le sérialiseur
 * redécode en ce même refus.
 *
 * Sa trace est vidée : sans `zend.exception_ignore_args` (le conteneur de
 * dev), elle garderait les arguments de `decode()` et des transports qui
 * l'appellent, soit le corps, et le recopierait dans `failed` par
 * l'exception elle-même (mesuré en relecture : 800 Ko pour un corps de
 * 300 Ko). Le message ne donne que la taille, jamais le contenu.
 */
final class EnvelopeTooLargeException extends MessageDecodingFailedException implements UnrecoverableExceptionInterface
{
    public const string SIZE_HEADER = 'X-Envelope-Refused-Size';

    public static function forSize(int $bytes, int $limit): Envelope
    {
        $refusal = new self(
            \sprintf('Enveloppe Messenger de %d octets (corps et en-têtes) refusée avant son MAC : au-delà de %d (issue #469).', $bytes, $limit),
            0,
            null,
            ['body' => '', 'headers' => [self::SIZE_HEADER => (string) $bytes]],
        );
        // La trace est une propriété privée d'Exception, sans mutateur : la
        // réflexion est le seul moyen de la vider après coup.
        new ReflectionProperty(Exception::class, 'trace')->setValue($refusal, []);

        return new Envelope($refusal);
    }
}
