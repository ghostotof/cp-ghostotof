<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mailer;

use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;

/**
 * Ce qu'on peut journaliser d'un échec d'envoi d'e-mail : la classe de
 * l'exception et un code numérique, jamais le message (issue #356, audit I4).
 *
 * Le transport recopie la réponse du serveur dans son message — la ligne SMTP
 * (« 550 5.1.1 <adresse>: Recipient address rejected »), ou le corps de la
 * réponse de l'API Scaleway (ScalewayApiTransport) —, et cette réponse peut
 * citer l'expéditeur ou le destinataire. Chaînée en `previous`, elle sortait
 * dans le journal du worker et dans les détails d'erreur que la table d'échec
 * de Messenger garde avec le message. Le message lui-même, sérialisé dans
 * cette table, porte toujours ce qu'il transporte (le contact : nom, adresse
 * et texte du visiteur) : c'est sa rétention, pas l'exception, qui le règle.
 * Le pendant, côté e-mail, de ProviderFailure côté modèles de langage.
 *
 * Le code :
 *  - transport API (HttpTransportException) : le statut HTTP de la réponse,
 *    s'il en existe un — une API injoignable n'en a pas, et le lire
 *    relancerait l'échec du client HTTP ;
 *  - transport SMTP : le code de réponse, que le transport pose en code de
 *    l'exception (UnexpectedResponseException) ;
 *  - sinon (connexion impossible, DSN invalide…) : aucun.
 */
final readonly class MailerTransportFailure
{
    /**
     * @param class-string<TransportExceptionInterface> $exceptionClass
     */
    private function __construct(
        public string $exceptionClass,
        public ?int $code,
    ) {
    }

    public static function from(TransportExceptionInterface $exception): self
    {
        return new self($exception::class, self::codeOf($exception));
    }

    private static function codeOf(TransportExceptionInterface $exception): ?int
    {
        if ($exception instanceof HttpTransportException) {
            try {
                return $exception->getResponse()->getStatusCode();
            } catch (HttpClientException) {
                return null;
            }
        }

        $code = $exception->getCode();

        return $code > 0 ? $code : null;
    }
}
