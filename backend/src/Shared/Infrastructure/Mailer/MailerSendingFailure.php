<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mailer;

use Symfony\Component\Messenger\Exception\HandlerFailedException;
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
 * décrite.
 */
final class MailerSendingFailure
{
    public static function describe(Throwable $exception): string
    {
        while ($exception instanceof HandlerFailedException && $exception->getPrevious() instanceof Throwable) {
            $exception = $exception->getPrevious();
        }

        $type = get_debug_type($exception);

        if (!$exception instanceof TwigError) {
            return \sprintf('%s, %s:%d', $type, basename($exception->getFile()), $exception->getLine());
        }

        $template = $exception->getSourceContext()?->getName();
        $line = $exception->getTemplateLine();

        return match (true) {
            null === $template => $type,
            // Twig rend -1 quand il ne sait pas la ligne.
            $line < 1 => \sprintf('%s, %s', $type, $template),
            default => \sprintf('%s, %s:%d', $type, $template, $line),
        };
    }
}
