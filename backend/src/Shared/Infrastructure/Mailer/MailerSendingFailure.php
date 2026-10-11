<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mailer;

use Symfony\Component\Messenger\Exception\WrappedExceptionsInterface;
use Throwable;
use Twig\Error\Error as TwigError;

/**
 * Ce qu'on peut dire, sans risque, d'un échec de la construction ou de
 * l'envoi d'un e-mail autre que celui du transport ({@see MailerTransportFailure}) :
 * son type et son emplacement — le gabarit et la ligne pour une erreur Twig,
 * le fichier et la ligne sinon. Jamais son message (issues #419, #427).
 *
 * Le message cite ce que l'e-mail transporte : Twig nomme la clé introuvable
 * (`Key "…/set-password#<jeton>" … does not exist`, `Impossible to access a
 * key ("<adresse du visiteur>")…`), Mime l'adresse qu'il refuse
 * (`RfcComplianceException`). Repris dans l'exception que le handler lève, ou
 * gardé en `previous`, il partirait dans les logs du worker à chaque
 * tentative et dans l'`ErrorDetailsStamp` jusqu'au failure_transport.
 *
 * Le nom d'un gabarit est celui que le loader connaît ; seul
 * `template_from_string(x, nom)` en ferait une donnée, et son extension n'est
 * pas chargée.
 *
 * Le gabarit est rendu deux fois dans send() : au
 * {@see \Symfony\Component\Mailer\Event\MessageEvent} « queued » que
 * {@see \Symfony\Component\Mailer\Mailer} émet avant son bus
 * ({@see \Symfony\Component\Mailer\EventListener\MessageListener} rend sans
 * regarder `isQueued()`), d'où un échec nu, puis dans le transport, derrière
 * le bus ({@see \Symfony\Component\Mailer\Messenger\SendEmailMessage} traité
 * en synchrone, issue #426), d'où un échec enveloppé dans une
 * HandlerFailedException par bus traversé : c'est celle du fond qui est
 * décrite ({@see self::innermost()}).
 */
final class MailerSendingFailure
{
    public static function describe(Throwable $exception): string
    {
        $exception = self::innermost($exception);
        $type = get_debug_type($exception);
        $template = $exception instanceof TwigError ? $exception->getSourceContext()?->getName() : null;

        if (!$exception instanceof TwigError || null === $template) {
            // Hors gabarit, ou erreur Twig levée hors rendu (une extension,
            // un runtime) : l'emplacement PHP, sûr.
            return \sprintf('%s, %s:%d', $type, basename($exception->getFile()), $exception->getLine());
        }

        $line = $exception->getTemplateLine();

        // Twig rend -1 quand il ne sait pas la ligne.
        return $line < 1 ? \sprintf('%s, %s', $type, $template) : \sprintf('%s, %s:%d', $type, $template, $line);
    }

    /**
     * L'exception du fond, sous toutes les enveloppes de Messenger
     * (HandlerFailedException, une par bus traversé, mais aussi
     * DelayedMessageHandlingException) : c'est elle qui dit ce qui a échoué,
     * et c'est sur elle qu'un handler reconnaît un échec du transport. Une
     * enveloppe de plusieurs échecs est décrite par le premier.
     */
    public static function innermost(Throwable $exception): Throwable
    {
        while ($exception instanceof WrappedExceptionsInterface && $exception->getPrevious() instanceof Throwable) {
            $exception = $exception->getPrevious();
        }

        return $exception;
    }
}
