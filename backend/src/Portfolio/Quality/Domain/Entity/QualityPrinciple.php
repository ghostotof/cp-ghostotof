<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Domain\Entity;

use App\Portfolio\Quality\Infrastructure\Doctrine\QualityPrincipleRepository;
use App\Portfolio\Shared\Domain\Orderable;
use App\Portfolio\Shared\Domain\TranslatableContent;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un principe de qualité mis en avant sur la landing page (ex. "DDD",
 * "SOLID"), éditable depuis le backoffice (ROLE_SUPER). Remplace le contenu
 * jusque-là codé en dur dans frontend/src/infrastructure/portfolio/content/{fr,en}.ts.
 */
#[ORM\Entity(repositoryClass: QualityPrincipleRepository::class)]
#[ORM\Table(name: 'quality_principle')]
#[ORM\UniqueConstraint(name: 'uniq_quality_principle_translation_group_locale', columns: ['translation_group', 'locale'])]
class QualityPrinciple implements Orderable, TranslatableContent
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

    public function __construct(
        Locale $locale,
        string $title,
        string $description,
        string $iconKey,
        int $position,
        ?Uuid $translationGroup = null,
    ) {
        $this->id = Uuid::v7();
        $this->translationGroup = $translationGroup ?? Uuid::v7();
        $this->locale = $locale;
        $this->title = $title;
        $this->description = $description;
        $this->iconKey = $iconKey;
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

    public function update(string $title, string $description, string $iconKey): void
    {
        $this->title = $title;
        $this->description = $description;
        $this->iconKey = $iconKey;
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
