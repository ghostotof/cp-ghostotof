<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Domain\Entity;

use App\Portfolio\Quality\Infrastructure\Doctrine\QualityTraitRepository;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
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
    /**
     * Spec 0003 D1/D2 : UUID v7 natif PostgreSQL, posé par le constructeur et
     * non par la base au flush. Une entité connaît donc son identité dès sa
     * construction — elle se compare et se teste sans persistance.
     */
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(enumType: Locale::class, length: 2)]
    private Locale $locale;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    private string $label;

    #[ORM\Column]
    private int $position;

    public function __construct(Locale $locale, string $label, int $position)
    {
        $this->id = Uuid::v7();
        $this->locale = $locale;
        $this->label = $label;
        $this->position = $position;
    }

    public function getId(): Uuid
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
