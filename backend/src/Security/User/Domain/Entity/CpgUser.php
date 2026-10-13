<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Entity;

use App\Security\User\Domain\Exception\InvalidUsernameException;
use App\Security\User\Infrastructure\Doctrine\CpgUserRepository;
use App\Shared\Domain\Security\AccountReference;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Utilisateur permettant l'accès aux ressources/pages protégées du frontend
 * (ex. téléchargement du CV). Identifiant de connexion : un simple nom
 * d'utilisateur.
 *
 * Deux voies de création :
 * - CLI ({@see \App\Security\User\Presentation\Command\CreateCpgUserCommand}) :
 *   amorçage, notamment du premier ROLE_SUPER — sans email, compte utilisable
 *   immédiatement ;
 * - invitation depuis le backoffice ({@see \App\Security\User\Application\CpgUserInviter}) :
 *   un email est stocké (nullable, unique, jamais exposé avant authentification —
 *   cf. objectif n°9), le compte est créé "en attente d'activation" et la
 *   personne définit elle-même son mot de passe via un lien reçu par email.
 */
#[ORM\Entity(repositoryClass: CpgUserRepository::class)]
#[ORM\Table(name: 'cpg_user')]
#[ORM\UniqueConstraint(name: self::USERNAME_UNIQUE_INDEX, columns: ['username'])]
#[ORM\UniqueConstraint(name: self::EMAIL_UNIQUE_INDEX, columns: ['email'])]
class CpgUser implements UserInterface, PasswordAuthenticatedUserInterface, AccountReference
{
    /**
     * Noms des index uniques, tels que PostgreSQL les cite dans le message
     * d'une violation : CpgUserRepository::add() s'en sert pour dire laquelle
     * des deux unicités a refusé l'enregistrement (issue #364). Ils nomment aussi le
     * mapping ci-dessus, et ne peuvent donc pas s'en écarter.
     */
    public const string USERNAME_UNIQUE_INDEX = 'uniq_cpg_user_username';

    public const string EMAIL_UNIQUE_INDEX = 'uniq_cpg_user_email';

    /** Rôle réservé à l'administration du backoffice (gestion de contenu, gestion des utilisateurs). */
    public const string ROLE_SUPER = 'ROLE_SUPER';

    /**
     * Palier « identifiant » (ADR 0003) : accordé nominativement, jamais par
     * simple authentification. Ouvre le CV et /api/me. Hérité automatiquement
     * par ROLE_SUPER via la role_hierarchy de security.yaml.
     */
    public const string ROLE_TRUSTED = 'ROLE_TRUSTED';

    /** En caractères : la règle que lit la personne qui choisit son mot de passe. */
    public const int MIN_PASSWORD_LENGTH = 8;

    /**
     * Borne haute alignée sur PasswordHasherInterface::MAX_PASSWORD_LENGTH de
     * Symfony : au-delà, le hasher lève une exception (défense anti-DoS sur
     * bcrypt/argon). On valide donc en amont pour répondre 422, jamais 500.
     * En **octets**, comme le hasher la contrôle (`strlen`) : comptée en
     * caractères, un mot de passe multioctet passait la validation et
     * finissait en 500 (issue #386). Les deux bornes ne s'appliquent qu'à
     * travers Presentation\Validator\PlainPasswordLength.
     */
    public const int MAX_PASSWORD_LENGTH = 4096;

    /**
     * Lettres, chiffres, ".", "_" ou "-", 3 à 60 caractères. Ancré par `\z`
     * et non par `$`, qui accepte la position précédant un `\n` final : un
     * nom suivi d'un retour à la ligne passait (issue #386).
     */
    public const string USERNAME_PATTERN = '/^[a-zA-Z0-9_.-]{3,60}\z/';

    /**
     * Spec 0003 D1/D2 : UUID v7 natif PostgreSQL, posé par le constructeur et
     * non par la base au flush. Une entité connaît donc son identité dès sa
     * construction — elle se compare et se teste sans persistance.
     */
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 60, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 60)]
    private string $username;

    /**
     * Renseigné uniquement pour les comptes créés par invitation depuis le
     * backoffice (les comptes CLI n'en ont pas). Sert à envoyer le lien de
     * définition de mot de passe puis, plus tard, toute correspondance
     * d'administration. Jamais exposé à un visiteur non authentifié.
     */
    #[ORM\Column(length: 180, unique: true, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    #[Ignore]
    private ?string $email = null;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    #[Ignore]
    private string $password;

    /**
     * Date d'envoi de l'invitation (comptes créés depuis le backoffice ; `null`
     * pour un compte CLI). Avec fuseau, comme tout horodatage (issue #434).
     */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    #[Ignore]
    private ?DateTimeImmutable $invitedAt = null;

    /**
     * Numéro de la dernière (re)invitation, avancé par markInvited() (issue
     * #434). Chaque message d'invitation porte le sien : un message dont le
     * numéro est dépassé (réessai différé, rejeu du failure_transport) ne
     * purge pas le jeton d'une relance plus récente. Un compteur plutôt que
     * l'instant : deux relances dans la même seconde se distinguent, et
     * aucune horloge n'entre dans la comparaison.
     */
    #[ORM\Column(options: ['default' => 0])]
    #[Ignore]
    private int $invitationGeneration = 0;

    /**
     * Date à laquelle la personne invitée a défini son mot de passe via le lien
     * reçu. Un compte "en attente d'activation" est un compte invité mais pas
     * encore activé (cf. isPendingActivation()) ; un compte CLI, lui, n'a ni
     * invitation ni activation et reste utilisable d'emblée.
     */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    #[Ignore]
    private ?DateTimeImmutable $activatedAt = null;

    public function __construct(string $username, string $hashedPassword)
    {
        if (1 !== preg_match(self::USERNAME_PATTERN, $username)) {
            throw InvalidUsernameException::invalidFormat();
        }

        $this->id = Uuid::v7();
        $this->username = $username;
        $this->password = $hashedPassword;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    /**
     * L'UUID, seule désignation du compte dans un journal ou une clé de quota
     * (issue #363) : l'identifiant de connexion d'un invité est dérivé de son
     * adresse.
     */
    public function accountId(): string
    {
        return $this->id->toRfc4122();
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getInvitedAt(): ?DateTimeImmutable
    {
        return $this->invitedAt;
    }

    public function getInvitationGeneration(): int
    {
        return $this->invitationGeneration;
    }

    /**
     * Vrai si le compte a été (re)invité depuis la génération donnée : un
     * message qui sert celle-ci est périmé (issue #434). Une génération
     * d'avance sur la base (relance pas encore commitée quand le worker la
     * lit) ne l'est jamais : le dernier message ne doit pas se perdre.
     */
    public function wasReinvitedSince(int $invitationGeneration): bool
    {
        return $this->invitationGeneration > $invitationGeneration;
    }

    public function getActivatedAt(): ?DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function markInvited(DateTimeImmutable $invitedAt): void
    {
        $this->invitedAt = $invitedAt;
        ++$this->invitationGeneration;
    }

    /**
     * Une relance dont la génération vient d'être avancée en base, de façon
     * atomique (CpgUserRepositoryInterface::advanceInvitationGeneration(),
     * issue #435) : l'entité reprend l'état écrit, sans recompter elle-même.
     */
    public function markReinvited(DateTimeImmutable $invitedAt, int $invitationGeneration): void
    {
        $this->invitedAt = $invitedAt;
        $this->invitationGeneration = $invitationGeneration;
    }

    public function markActivated(DateTimeImmutable $activatedAt): void
    {
        $this->activatedAt = $activatedAt;
    }

    /**
     * Compte invité depuis le backoffice mais dont le mot de passe n'a pas
     * encore été défini via le lien reçu. Toujours faux pour un compte CLI
     * (aucune invitation) comme pour un compte invité déjà activé.
     */
    public function isPendingActivation(): bool
    {
        return null !== $this->invitedAt && null === $this->activatedAt;
    }

    /**
     * Round de correction (I2, issue #238) : un compte invité dont le mot de
     * passe n'a *jamais* été défini — la définition littérale d'« invitation
     * jamais activée » que retient la purge automatique
     * (PendingInvitationPurger). Se distingue délibérément de
     * isPendingActivation() : CpgUserAdministrator::changePassword() peut
     * poser un mot de passe sur un compte invité sans jamais appeler
     * markActivated() (ce n'est pas la personne qui a défini le sien via le
     * lien reçu) — un tel compte reste isPendingActivation() vrai mais se
     * connecte déjà, et ne doit jamais être purgé.
     */
    public function isAwaitingPasswordSetup(): bool
    {
        return $this->isPendingActivation() && '' === $this->password;
    }

    public function getUserIdentifier(): string
    {
        // Le constructeur garantit un username de 3 a 60 caracteres
        // (USERNAME_PATTERN) ; l'assertion l'explicite pour l'analyse statique
        // (UserInterface::getUserIdentifier() attend un non-empty-string).
        \assert('' !== $this->username);

        return $this->username;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * Le compte porte-t-il ROLE_SUPER, le rôle d'administration du backoffice ?
     * Lu sur les rôles **stockés**, comme le font les gardes et les journaux
     * qui l'interrogent (issue #560) : la role_hierarchy de security.yaml,
     * qui fait hériter ROLE_TRUSTED de ROLE_SUPER, ne joue pas ici.
     */
    public function isSuperAdmin(): bool
    {
        return \in_array(self::ROLE_SUPER, $this->roles, true);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    public function eraseCredentials(): void
    {
        // Aucun champ en clair n'est jamais stocké sur l'entité : rien à effacer.
    }
}
