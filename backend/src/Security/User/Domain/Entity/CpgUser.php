<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Entity;

use App\Security\User\Domain\Exception\InvalidUsernameException;
use App\Security\User\Infrastructure\Doctrine\CpgUserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Utilisateur permettant l'accès aux ressources/pages protégées du frontend
 * (ex. téléchargement du CV). Identifiant de connexion : un simple nom
 * d'utilisateur.
 *
 * Deux voies de création :
 * - CLI (App\Security\User\Presentation\Command\CreateCpgUserCommand) :
 *   amorçage, notamment du premier ROLE_SUPER — sans email, compte utilisable
 *   immédiatement ;
 * - invitation depuis le backoffice (App\Security\User\Application\CpgUserInviter) :
 *   un email est stocké (nullable, unique, jamais exposé avant authentification —
 *   cf. objectif n°9), le compte est créé "en attente d'activation" et la
 *   personne définit elle-même son mot de passe via un lien reçu par email.
 */
#[ORM\Entity(repositoryClass: CpgUserRepository::class)]
#[ORM\Table(name: 'cpg_user')]
#[ORM\UniqueConstraint(name: 'uniq_cpg_user_username', columns: ['username'])]
#[ORM\UniqueConstraint(name: 'uniq_cpg_user_email', columns: ['email'])]
class CpgUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    /** Rôle réservé à l'administration du backoffice (gestion de contenu, gestion des utilisateurs). */
    public const string ROLE_SUPER = 'ROLE_SUPER';

    public const int MIN_PASSWORD_LENGTH = 8;

    /**
     * Borne haute alignée sur PasswordHasherInterface::MAX_PASSWORD_LENGTH de
     * Symfony : au-delà, le hasher lève une exception (défense anti-DoS sur
     * bcrypt/argon). On valide donc en amont pour répondre 422, jamais 500.
     */
    public const int MAX_PASSWORD_LENGTH = 4096;

    /** Lettres, chiffres, ".", "_" ou "-", 3 à 60 caractères. */
    public const string USERNAME_PATTERN = '/^[a-zA-Z0-9_.-]{3,60}$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

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

    /** Date d'envoi de l'invitation (comptes créés depuis le backoffice ; `null` pour un compte CLI). */
    #[ORM\Column(nullable: true)]
    #[Ignore]
    private ?\DateTimeImmutable $invitedAt = null;

    /**
     * Date à laquelle la personne invitée a défini son mot de passe via le lien
     * reçu. Un compte "en attente d'activation" est un compte invité mais pas
     * encore activé (cf. isPendingActivation()) ; un compte CLI, lui, n'a ni
     * invitation ni activation et reste utilisable d'emblée.
     */
    #[ORM\Column(nullable: true)]
    #[Ignore]
    private ?\DateTimeImmutable $activatedAt = null;

    public function __construct(string $username, string $hashedPassword)
    {
        if (1 !== preg_match(self::USERNAME_PATTERN, $username)) {
            throw InvalidUsernameException::forUsername($username);
        }

        $this->username = $username;
        $this->password = $hashedPassword;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getInvitedAt(): ?\DateTimeImmutable
    {
        return $this->invitedAt;
    }

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function markInvited(\DateTimeImmutable $invitedAt): void
    {
        $this->invitedAt = $invitedAt;
    }

    public function markActivated(\DateTimeImmutable $activatedAt): void
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
