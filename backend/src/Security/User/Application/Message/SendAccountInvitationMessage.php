<?php

declare(strict_types=1);

namespace App\Security\User\Application\Message;

/**
 * Commande Messenger : demande d'envoi de l'e-mail d'invitation à définir son
 * mot de passe. Dispatchée par App\Security\User\Application\CpgUserInviter,
 * consommée par App\Security\User\Infrastructure\Messenger\SendAccountInvitationHandler
 * (transport "async"/RabbitMQ, cf. config/packages/messenger.yaml).
 *
 * L'identifiant voyage en chaîne RFC 4122 (spec 0003 D7) et non en Uuid : un
 * message sérialisé dans RabbitMQ, ou parqué dans le failure_transport, doit
 * rester lisible et rejouable sans dépendre de la sérialisation d'un objet.
 *
 * Point d'audit C2 (décision D3) : le message ne transporte QUE l'identifiant
 * du compte et la langue — aucun secret. Le handler recharge le compte, crée
 * le PasswordSetupToken (dans une transaction) et n'a donc besoin ni de
 * l'e-mail, ni du nom d'utilisateur, ni surtout du jeton en clair. Un message
 * routé vers le failure_transport (SMTP indisponible…) ne révèle ainsi rien
 * d'exploitable si la base des messages échoués venait à fuiter.
 */
final readonly class SendAccountInvitationMessage
{
    public function __construct(
        public string $userId,
        public string $locale,
    ) {
    }
}
