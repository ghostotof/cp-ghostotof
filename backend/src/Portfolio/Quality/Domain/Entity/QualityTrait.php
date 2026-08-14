<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Domain\Entity;

use App\Portfolio\Quality\Infrastructure\Doctrine\QualityTraitRepository;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un trait de qualité affiché sous forme de badge sur la landing page (ex.
 * "Architecture propre"), éditable depuis le backoffice (ROLE_SUPER).
 * Remplace le contenu jusque-là codé en dur dans
 * frontend/src/infrastructure/portfolio/content/{fr,en}.ts.
 */
#[ORM\Entity(repositoryClass: QualityTraitRepository::class)]
#[ORM\Table(name: 'quality_trait')]
class QualityTrait
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: Locale::class, length: 2)]
    private Locale $locale;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    private string $label;

    #[ORM\Column]
    private int $position;

    public function __construct(Locale $locale, string $label, int $position)
    {
        $this->locale = $locale;
        $this->label = $label;
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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function update(string $label, int $position): void
    {
        $this->label = $label;
        $this->position = $position;
    }
}
