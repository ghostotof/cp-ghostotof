<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Entity;

use App\Security\User\Infrastructure\Doctrine\PasswordSetupTokenRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

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
    /** Spec 0003 D1/D2 : UUID v7 posé par le constructeur (cf. CpgUser). */
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

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
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): Uuid
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
