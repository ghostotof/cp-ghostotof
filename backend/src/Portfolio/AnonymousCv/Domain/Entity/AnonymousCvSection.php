<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Domain\Entity;

use App\Portfolio\AnonymousCv\Infrastructure\Doctrine\AnonymousCvSectionRepository;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * ADR 0003 D5, deuxième des trois contenus du palier de base (`ROLE_USER`) :
 * le « CV sans identité ». Une section par domaine de compétence — les
 * technologies qui le composent, l'ancienneté, et surtout ce qui a été
 * réalisé avec. **Ni nom, ni employeur, ni client, ni coordonnées** : c'est
 * cette absence qui rend le contenu non identifiant, et elle se décide à la
 * saisie (backoffice, ROLE_SUPER), pas par un filtre à la lecture.
 *
 * Ce que ce contenu n'est pas : une liste de mots-clés. Les années par
 * technologie sont déjà publiques (`/api/experience/technologies`) ; ce qui
 * justifie un palier au-dessus de l'anonyme, ce sont les réalisations —
 * d'où `achievements` NOT NULL, même choix éditorial structurel que
 * `Incident::$invariant`. Une section que l'on ne peut pas enregistrer sans
 * dire ce qu'on a fait de la compétence ne peut pas dériver en catalogue.
 *
 * Le vrai CV (nom, employeurs, parcours) reste derrière `ROLE_TRUSTED`
 * (`GET /api/cv`, D4) — la route de ce contenu, `/api/anonymous-cv`, est
 * choisie pour ne jamais matcher le préfixe `^/api/cv` de l'access_control.
 */
#[ORM\Entity(repositoryClass: AnonymousCvSectionRepository::class)]
#[ORM\Table(name: 'anonymous_cv_section')]
#[ORM\Index(name: 'idx_anonymous_cv_section_locale_position', columns: ['locale', 'position'])]
class AnonymousCvSection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: Locale::class, length: 2)]
    private Locale $locale;

    /** Le domaine de compétence (« Backend PHP / Symfony »), pas un intitulé de poste. */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $title;

    /** Les technologies et pratiques du domaine, en texte libre (« Symfony 7, Doctrine, API Platform… »). */
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $skills;

    /** Ancienneté sur le domaine, en années entières — un ordre de grandeur, pas une date de début. */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $yearsOfExperience;

    /**
     * Ce qui a été réalisé avec cette compétence, en paragraphes (texte brut,
     * rendu par RichText.vue — jamais de HTML). Sans employeur ni client.
     */
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $achievements;

    #[ORM\Column]
    private int $position;

    public function __construct(
        Locale $locale,
        string $title,
        string $skills,
        int $yearsOfExperience,
        string $achievements,
        int $position,
    ) {
        $this->locale = $locale;
        $this->title = $title;
        $this->skills = $skills;
        $this->yearsOfExperience = $yearsOfExperience;
        $this->achievements = $achievements;
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

    public function getSkills(): string
    {
        return $this->skills;
    }

    public function getYearsOfExperience(): int
    {
        return $this->yearsOfExperience;
    }

    public function getAchievements(): string
    {
        return $this->achievements;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    /**
     * La locale n'est pas modifiable : changer la langue d'une section revient
     * à en créer une autre (même choix que CaseStudy::update()).
     */
    public function update(
        string $title,
        string $skills,
        int $yearsOfExperience,
        string $achievements,
        int $position,
    ): void {
        $this->title = $title;
        $this->skills = $skills;
        $this->yearsOfExperience = $yearsOfExperience;
        $this->achievements = $achievements;
        $this->position = $position;
    }
}
