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
 * (ex. téléchargement du CV). Créé uniquement en ligne de commande
 * (App\Security\User\Presentation\Command\CreateCpgUserCommand) : il n'existe
 * volontairement aucun formulaire d'inscription. Identifiant de connexion :
 * un simple nom d'utilisateur (pas d'email stocké, aucune donnée personnelle
 * identifiante).
 */
#[ORM\Entity(repositoryClass: CpgUserRepository::class)]
#[ORM\Table(name: 'cpg_user')]
#[ORM\UniqueConstraint(name: 'uniq_cpg_user_username', columns: ['username'])]
class CpgUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    /** Rôle réservé à l'administration du backoffice (gestion de contenu, gestion des utilisateurs). */
    public const string ROLE_SUPER = 'ROLE_SUPER';

    public const int MIN_PASSWORD_LENGTH = 8;

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

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    #[Ignore]
    private string $password;

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

    public function getUserIdentifier(): string
    {
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
