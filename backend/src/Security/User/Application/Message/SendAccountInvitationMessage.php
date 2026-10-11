<?php

declare(strict_types=1);

namespace App\Security\User\Application\Message;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

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
 * Point d'audit C2 (décision D3) : le message ne transporte aucun secret —
 * l'identifiant du compte, la langue et la génération d'invitation, rien
 * d'autre. Le handler recharge le compte, crée le PasswordSetupToken (dans
 * une transaction) et n'a donc besoin ni de l'e-mail, ni du nom
 * d'utilisateur, ni surtout du jeton en clair. Un message routé vers le
 * failure_transport (SMTP indisponible…) ne révèle ainsi rien d'exploitable si
 * la base des messages échoués venait à fuiter.
 *
 * Issue #434 : la génération est le numéro de l'invitation que sert le
 * message ({@see \App\Security\User\Domain\Entity\CpgUser::getInvitationGeneration()}).
 * Un message d'une génération dépassée (réessai différé, rejeu du
 * failure_transport) est écarté par le handler au lieu de purger le jeton
 * d'une relance déjà remise. Ce numéro n'est pas un secret.
 */
final readonly class SendAccountInvitationMessage
{
    /**
     * La génération de la première invitation d'un compte
     * ({@see \App\Security\User\Domain\Entity\CpgUser::markInvited()} part de 0).
     */
    private const int FIRST_GENERATION = 1;

    public function __construct(
        public string $userId,
        public string $locale,
        public int $invitationGeneration,
    ) {
    }

    /**
     * {@see \Symfony\Component\Messenger\Transport\Serialization\PhpSerializer}
     * ne repasse pas par le constructeur : cette méthode vérifie la forme du
     * message qu'il restaure. Un message altéré échoue au décodage, sans
     * atteindre le handler (il suit alors les réessais du transport, puis le
     * failure_transport, comme tout échec). Un message sans génération — le
     * format d'avant l'issue #434, toléré jusqu'à la v0.23.0 — est refusé de
     * même depuis l'issue #448 : le handler ne saurait pas s'il sert une
     * invitation dépassée. Une génération inférieure à 1 ne vient pas de
     * CpgUserInviter (un compte invité a au moins la première).
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $userId = $data['userId'] ?? null;
        $locale = $data['locale'] ?? null;
        $invitationGeneration = $data['invitationGeneration'] ?? null;
        if (!\is_string($userId) || !Uuid::isValid($userId)
            || !\is_string($locale) || null === Locale::tryFrom($locale)) {
            throw MalformedAccountInvitationMessageException::fromSerializedData();
        }

        if (null === $invitationGeneration) {
            throw MalformedAccountInvitationMessageException::withoutGeneration();
        }

        if (!\is_int($invitationGeneration) || $invitationGeneration < self::FIRST_GENERATION) {
            throw MalformedAccountInvitationMessageException::fromSerializedData();
        }

        $this->userId = $userId;
        $this->locale = $locale;
        $this->invitationGeneration = $invitationGeneration;
    }
}
