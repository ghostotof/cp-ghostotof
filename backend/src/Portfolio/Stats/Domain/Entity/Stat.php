<?php

declare(strict_types=1);

namespace App\Portfolio\Stats\Domain\Entity;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Portfolio\Stats\Infrastructure\Doctrine\StatRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une statistique affichée sur la landing page (ex. "+50K lignes de code"),
 * éditable depuis le backoffice (ROLE_SUPER). Remplace le contenu jusque-là
 * codé en dur dans frontend/src/infrastructure/portfolio/content/{fr,en}.ts.
 */
#[ORM\Entity(repositoryClass: StatRepository::class)]
#[ORM\Table(name: 'stat')]
class Stat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: Locale::class, length: 2)]
    private Locale $locale;

    #[ORM\Column(length: 30)]
    #[Assert\NotBlank]
    private string $value;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    private string $label;

    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    private string $iconKey;

    #[ORM\Column]
    private int $position;

    public function __construct(Locale $locale, string $value, string $label, string $iconKey, int $position)
    {
        $this->locale = $locale;
        $this->value = $value;
        $this->label = $label;
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

    public function getValue(): string
    {
        return $this->value;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getIconKey(): string
    {
        return $this->iconKey;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function update(string $value, string $label, string $iconKey, int $position): void
    {
        $this->value = $value;
        $this->label = $label;
        $this->iconKey = $iconKey;
        $this->position = $position;
    }
}
