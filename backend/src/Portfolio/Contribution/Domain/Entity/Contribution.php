<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Domain\Entity;

use App\Portfolio\Contribution\Infrastructure\Doctrine\ContributionRepository;
use App\Portfolio\Shared\Domain\Orderable;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une contribution technique publique (discussion d'API, analyse d'outil),
 * avec le raisonnement qui la porte — pas seulement le lien.
 *
 * C'est le seul contenu du site qui ne peut pas exister sur un profil
 * LinkedIn, et donc la seule vraie raison de cliquer depuis celui-ci. D'où le
 * `body` : un titre et une URL ne prouvent rien, l'argument oui.
 *
 * Éditable depuis le backoffice (ROLE_SUPER) : de nouvelles contributions
 * s'ajoutent au fil de l'eau, sans redéploiement.
 */
#[ORM\Entity(repositoryClass: ContributionRepository::class)]
#[ORM\Table(name: 'contribution')]
#[ORM\UniqueConstraint(name: 'uniq_contribution_translation_group_locale', columns: ['translation_group', 'locale'])]
// Index créé par Version20260906200000 mais qui n'était pas déclaré ici : le
// mapping l'ignorait, si bien que chaque `doctrine:migrations:diff` proposait
// de le supprimer. Le déclarer aligne le mapping sur la base, sans SQL.
#[ORM\Index(name: 'idx_contribution_locale_position', columns: ['locale', 'position'])]
class Contribution implements Orderable
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

    /** La thèse défendue, pas le titre de l'issue : c'est elle qu'on retient. */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $title;

    /** Dépôt hôte, ex. « symfony/ai ». */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $project;

    /** Référence lisible, ex. « Issue #1688 ». */
    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    private string $reference;

    #[ORM\Column(length: 500)]
    #[Assert\NotBlank]
    #[Assert\Url]
    private string $url;

    /** Chapeau : le problème posé, en une ou deux phrases. */
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $summary;

    /**
     * L'argument développé. Paragraphes séparés par une ligne vide — la
     * présentation les découpe telles quelles. Volontairement du texte brut :
     * accepter du balisage ici ouvrirait une injection HTML sur une page
     * publique pour un gain de mise en forme négligeable.
     */
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $body;

    #[ORM\Column]
    private int $position;

    public function __construct(
        Locale $locale,
        string $title,
        string $project,
        string $reference,
        string $url,
        string $summary,
        string $body,
        int $position,
        ?Uuid $translationGroup = null,
    ) {
        $this->id = Uuid::v7();
        $this->translationGroup = $translationGroup ?? Uuid::v7();
        $this->locale = $locale;
        $this->title = $title;
        $this->project = $project;
        $this->reference = $reference;
        $this->url = $url;
        $this->summary = $summary;
        $this->body = $body;
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

    public function getProject(): string
    {
        return $this->project;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function update(
        string $title,
        string $project,
        string $reference,
        string $url,
        string $summary,
        string $body,
        int $position,
    ): void {
        $this->title = $title;
        $this->project = $project;
        $this->reference = $reference;
        $this->url = $url;
        $this->summary = $summary;
        $this->body = $body;
        $this->position = $position;
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
