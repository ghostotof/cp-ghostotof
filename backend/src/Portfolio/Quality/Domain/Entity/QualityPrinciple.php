<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Domain\Entity;

use App\Portfolio\Quality\Infrastructure\Doctrine\QualityPrincipleRepository;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un principe de qualité mis en avant sur la landing page (ex. "DDD",
 * "SOLID"), éditable depuis le backoffice (ROLE_SUPER). Remplace le contenu
 * jusque-là codé en dur dans frontend/src/infrastructure/portfolio/content/{fr,en}.ts.
 */
#[ORM\Entity(repositoryClass: QualityPrincipleRepository::class)]
#[ORM\Table(name: 'quality_principle')]
class QualityPrinciple
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: Locale::class, length: 2)]
    private Locale $locale;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    private string $title;

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    private string $description;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    private string $iconKey;

    #[ORM\Column]
    private int $position;

    public function __construct(Locale $locale, string $title, string $description, string $iconKey, int $position)
    {
        $this->locale = $locale;
        $this->title = $title;
        $this->description = $description;
        $this->iconKey = $iconKey;
        $this->position = $position;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLocale(): Locale
    {
        return $this->locale;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getIconKey(): string
    {
        return $this->iconKey;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function update(string $title, string $description, string $iconKey, int $position): void
    {
        $this->title = $title;
        $this->description = $description;
        $this->iconKey = $iconKey;
        $this->position = $position;
    }
}
