<?php

declare(strict_types=1);

namespace App\Security\User\Application\Message;

/**
 * Commande Messenger : demande d'envoi de l'e-mail d'invitation à définir son
 * mot de passe. Dispatchée par {@see \App\Security\User\Application\CpgUserInviter},
 * consommée par {@see \App\Security\User\Infrastructure\Messenger\SendAccountInvitationHandler}
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
 *
 * Issue #434 : il porte aussi l'instant de l'invitation qu'il sert, en
 * secondes Unix — la précision de la colonne `invited_at`. Un message plus
 * ancien que la dernière (re)invitation du compte (réessai différé, message
 * rejoué depuis le failure_transport) est écarté par le handler au lieu de
 * purger le jeton d'une relance déjà remise. Cet instant n'est pas un secret :
 * l'invariant ci-dessus tient.
 */
final readonly class SendAccountInvitationMessage
{
    /**
     * `null` pour un message mis en file avant l'issue #434 (cf.
     * __unserialize()) : le constructeur, lui, l'exige toujours.
     */
    public ?int $invitedAt;

    public function __construct(
        public string $userId,
        public string $locale,
        int $invitedAt,
    ) {
        $this->invitedAt = $invitedAt;
    }

    /**
     * PhpSerializer (le sérialiseur de Messenger) ne repasse pas par le
     * constructeur : sans cette méthode, un message sérialisé avant l'issue
     * #434 laisserait `invitedAt` non initialisée, et sa lecture planterait
     * le worker. Il est traité comme avant, sans contrôle d'ancienneté.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $userId = $data['userId'] ?? null;
        $locale = $data['locale'] ?? null;
        $invitedAt = $data['invitedAt'] ?? null;
        if (!\is_string($userId) || !\is_string($locale) || (null !== $invitedAt && !\is_int($invitedAt))) {
            throw MalformedAccountInvitationMessageException::fromSerializedData();
        }

        $this->userId = $userId;
        $this->locale = $locale;
        $this->invitedAt = $invitedAt;
    }
}
