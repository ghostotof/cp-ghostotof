<?php

declare(strict_types=1);

namespace App\Security\User\Application\Message;

/**
 * Commande Messenger : demande d'envoi de l'e-mail d'invitation à définir son
 * mot de passe. Dispatchée par App\Security\User\Application\CpgUserInviter,
 * consommée par App\Security\User\Infrastructure\Messenger\SendAccountInvitationHandler
 * (transport "async"/RabbitMQ, cf. config/packages/messenger.yaml).
 *
 * `clearToken` est la valeur en clair du jeton (jamais persistée ; seul son
 * SHA-256 est stocké en base) : le handler en a besoin pour construire le lien.
 */
final readonly class SendAccountInvitationMessage
{
    public function __construct(
        public string $recipientEmail,
        public string $username,
        public string $clearToken,
        public string $locale,
    ) {
    }
}
