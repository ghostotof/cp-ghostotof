<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Domain\Entity;

use App\Portfolio\Experience\Infrastructure\Doctrine\ExperienceTechnologyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une technologie du classement affiché sur la page Expériences, avec le temps
 * cumulé passé dessus (en années). Donnée de référence : créée uniquement via
 * la commande app:experience:add-technology (cf.
 * Presentation\Command\AddExperienceTechnologyCommand), pas d'édition exposée
 * pour l'instant, donc pas de setters.
 */
#[ORM\Entity(repositoryClass: ExperienceTechnologyRepository::class)]
#[ORM\Table(name: 'experience_technology')]
#[ORM\UniqueConstraint(name: 'uniq_experience_technology_name', columns: ['name'])]
class ExperienceTechnology
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private readonly string $name;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private readonly float $years;

    #[ORM\Column(length: 60, nullable: true)]
    private readonly ?string $iconKey;

    #[ORM\Column(length: 180, nullable: true)]
    private readonly ?string $relatedTechnologyName;

    public function __construct(string $name, float $years, ?string $iconKey = null, ?string $relatedTechnologyName = null)
    {
        $this->name = $name;
        $this->years = $years;
        $this->iconKey = $iconKey;
        $this->relatedTechnologyName = $relatedTechnologyName;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getYears(): float
    {
        return $this->years;
    }

    public function getIconKey(): ?string
    {
        return $this->iconKey;
    }

    public function getRelatedTechnologyName(): ?string
    {
        return $this->relatedTechnologyName;
    }
}
