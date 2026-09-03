<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Entity;

use App\Security\User\Infrastructure\Doctrine\PasswordSetupTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Jeton à usage unique et à durée de vie limitée permettant à une personne
 * invitée depuis le backoffice de définir elle-même son mot de passe.
 *
 * Seul le SHA-256 du jeton est stocké (`tokenHash`) : la valeur en clair ne
 * vit que le temps de construire le lien envoyé par e-mail, elle n'est jamais
 * persistée ni journalisée. Un compte ne conserve qu'un jeton actif à la fois
 * (cf. PasswordSetupTokenRepositoryInterface::deleteForUser).
 */
#[ORM\Entity(repositoryClass: PasswordSetupTokenRepository::class)]
#[ORM\Table(name: 'password_setup_token')]
#[ORM\UniqueConstraint(name: 'uniq_password_setup_token_hash', columns: ['token_hash'])]
class PasswordSetupToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CpgUser::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CpgUser $user;

    /** SHA-256 hexadécimal (64 caractères) du jeton en clair. */
    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct(CpgUser $user, string $tokenHash, \DateTimeImmutable $expiresAt)
    {
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): CpgUser
    {
        return $this->user;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    /** Ni déjà utilisé, ni expiré à l'instant `$now`. */
    public function isUsable(\DateTimeImmutable $now): bool
    {
        return null === $this->usedAt && $now < $this->expiresAt;
    }

    public function markUsed(\DateTimeImmutable $usedAt): void
    {
        $this->usedAt = $usedAt;
    }
}
