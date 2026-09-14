<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Domain\Entity;

use App\Portfolio\AnonymousCv\Infrastructure\Doctrine\AnonymousCvSectionRepository;
use App\Portfolio\Shared\Domain\Orderable;
use App\Portfolio\Shared\Domain\TranslatableContent;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
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
#[ORM\UniqueConstraint(name: 'uniq_anonymous_cv_section_translation_group_locale', columns: ['translation_group', 'locale'])]
#[ORM\Index(name: 'idx_anonymous_cv_section_locale_position', columns: ['locale', 'position'])]
class AnonymousCvSection implements Orderable, TranslatableContent
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

    /**
     * Spec 0004 D1 : identifiant partagé par les versions d'un même contenu
     * dans les différentes langues — deux lignes de même groupe sont le même
     * contenu traduit. Ce n'est délibérément pas une entité : le jour où un
     * besoin porte sur le groupe lui-même, cet UUID devient la clé primaire
     * d'une table de contenu que ces lignes référencent déjà.
     */
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $translationGroup;

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
        ?Uuid $translationGroup = null,
    ) {
        $this->id = Uuid::v7();
        $this->translationGroup = $translationGroup ?? Uuid::v7();
        $this->locale = $locale;
        $this->title = $title;
        $this->skills = $skills;
        $this->yearsOfExperience = $yearsOfExperience;
        $this->achievements = $achievements;
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
    ): void {
        $this->title = $title;
        $this->skills = $skills;
        $this->yearsOfExperience = $yearsOfExperience;
        $this->achievements = $achievements;
    }

    public function getTranslationGroup(): Uuid
    {
        return $this->translationGroup;
    }

    /**
     * Rattache cette entrée au groupe d'un contenu existant : elle en devient
     * la version dans sa propre langue. L'index unique (translation_group,
     * locale) refuse un groupe qui porte déjà cette langue.
     */
    public function attachToTranslationGroup(Uuid $translationGroup): void
    {
        $this->translationGroup = $translationGroup;
    }

    /**
     * Détache l'entrée de ses traductions. La colonne étant NOT NULL, elle
     * reçoit un groupe neuf plutôt que `null` : une entrée est toujours dans un
     * groupe, seul son cardinal change.
     */
    public function detachFromTranslationGroup(): void
    {
        $this->translationGroup = Uuid::v7();
    }

    /**
     * Spec 0004 D5 : la clé d'ordre est le groupe, pas l'id — toutes les
     * langues d'un même contenu se déplacent donc ensemble.
     */
    public function orderingKey(): string
    {
        return $this->translationGroup->toRfc4122();
    }

    public function moveToPosition(int $position): void
    {
        $this->position = $position;
    }
}
