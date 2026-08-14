<?php

declare(strict_types=1);

namespace App\Portfolio\About\Domain\Entity;

use App\Portfolio\About\Infrastructure\Doctrine\AboutSettingsRepository;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Textes de section de la page À propos (eyebrows, sous-titres), un
 * singleton par locale : contrairement à AboutSiteCard/AboutMeCard, il n'y a
 * ni création ni suppression exposées depuis le backoffice, seulement une
 * édition de l'existant (seedé une fois via une commande CLI dédiée).
 */
#[ORM\Entity(repositoryClass: AboutSettingsRepository::class)]
#[ORM\Table(name: 'about_settings')]
#[ORM\UniqueConstraint(name: 'uniq_about_settings_locale', columns: ['locale'])]
class AboutSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: Locale::class, length: 2)]
    private Locale $locale;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    private string $siteEyebrow;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    private string $meEyebrow;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    private string $technicalSubtitle;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    private string $personalSubtitle;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    private string $hobbiesSubtitle;

    public function __construct(
        Locale $locale,
        string $siteEyebrow,
        string $meEyebrow,
        string $technicalSubtitle,
        string $personalSubtitle,
        string $hobbiesSubtitle,
    ) {
        $this->locale = $locale;
        $this->siteEyebrow = $siteEyebrow;
        $this->meEyebrow = $meEyebrow;
        $this->technicalSubtitle = $technicalSubtitle;
        $this->personalSubtitle = $personalSubtitle;
        $this->hobbiesSubtitle = $hobbiesSubtitle;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLocale(): Locale
    {
        return $this->locale;
    }

    public function getSiteEyebrow(): string
    {
        return $this->siteEyebrow;
    }

    public function getMeEyebrow(): string
    {
        return $this->meEyebrow;
    }

    public function getTechnicalSubtitle(): string
    {
        return $this->technicalSubtitle;
    }

    public function getPersonalSubtitle(): string
    {
        return $this->personalSubtitle;
    }

    public function getHobbiesSubtitle(): string
    {
        return $this->hobbiesSubtitle;
    }

    public function update(
        string $siteEyebrow,
        string $meEyebrow,
        string $technicalSubtitle,
        string $personalSubtitle,
        string $hobbiesSubtitle,
    ): void {
        $this->siteEyebrow = $siteEyebrow;
        $this->meEyebrow = $meEyebrow;
        $this->technicalSubtitle = $technicalSubtitle;
        $this->personalSubtitle = $personalSubtitle;
        $this->hobbiesSubtitle = $hobbiesSubtitle;
    }
}
