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
     * Génération que sert un message mis en file avant l'issue #434 : la
     * migration Version20261011120000 a posé 1 à tout compte déjà invité.
     */
    private const int GENERATION_BEFORE_NUMBERING = 1;

    /**
     * `null` pour un message mis en file avant l'issue #434 (cf.
     * __unserialize()) : le constructeur, lui, l'exige toujours.
     */
    public ?int $invitationGeneration;

    public function __construct(
        public string $userId,
        public string $locale,
        int $invitationGeneration,
    ) {
        $this->invitationGeneration = $invitationGeneration;
    }

    /**
     * La génération d'invitation que sert ce message, à comparer à celle du
     * compte ({@see \App\Security\User\Domain\Entity\CpgUser::wasReinvitedSince()}).
     * Un message d'avant #434 sert la première : une relance faite depuis le
     * déploiement l'écarte, il est traité sinon.
     */
    public function servedGeneration(): int
    {
        return $this->invitationGeneration ?? self::GENERATION_BEFORE_NUMBERING;
    }

    /**
     * {@see \Symfony\Component\Messenger\Transport\Serialization\PhpSerializer}
     * ne repasse pas par le constructeur : sans cette méthode, un message
     * sérialisé avant l'issue #434 laisserait `invitationGeneration` non
     * initialisée, et sa lecture planterait le worker. La forme est vérifiée
     * au passage : un message altéré échoue au décodage, sans atteindre le
     * handler (il suit alors les réessais du transport, puis le
     * failure_transport, comme tout échec).
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $userId = $data['userId'] ?? null;
        $locale = $data['locale'] ?? null;
        $invitationGeneration = $data['invitationGeneration'] ?? null;
        if (!\is_string($userId) || !Uuid::isValid($userId)
            || !\is_string($locale) || null === Locale::tryFrom($locale)
            || (null !== $invitationGeneration && !\is_int($invitationGeneration))) {
            throw MalformedAccountInvitationMessageException::fromSerializedData();
        }

        $this->userId = $userId;
        $this->locale = $locale;
        $this->invitationGeneration = $invitationGeneration;
    }
}
