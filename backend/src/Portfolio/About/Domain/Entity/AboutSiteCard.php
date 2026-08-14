<?php

declare(strict_types=1);

namespace App\Portfolio\About\Domain\Entity;

use App\Portfolio\About\Infrastructure\Doctrine\AboutSiteCardRepository;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une carte de la section "À propos de ce site" (frontend :
 * AboutContent.site.cards), éditable depuis le backoffice (ROLE_SUPER).
 * Remplace le contenu jusque-là codé en dur dans
 * frontend/src/infrastructure/portfolio/content/{fr,en}.ts.
 */
#[ORM\Entity(repositoryClass: AboutSiteCardRepository::class)]
#[ORM\Table(name: 'about_site_card')]
class AboutSiteCard
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

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $iconKey;

    #[ORM\Column]
    private int $position;

    public function __construct(Locale $locale, string $title, string $description, ?string $iconKey, int $position)
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

    public function getIconKey(): ?string
    {
        return $this->iconKey;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function update(string $title, string $description, ?string $iconKey, int $position): void
    {
        $this->title = $title;
        $this->description = $description;
        $this->iconKey = $iconKey;
        $this->position = $position;
    }
}
