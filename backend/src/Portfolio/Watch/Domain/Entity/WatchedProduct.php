<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Entity;

use App\Portfolio\Shared\Domain\Orderable;
use App\Portfolio\Watch\Domain\Exception\InvalidWatchedProductException;
use App\Portfolio\Watch\Domain\ValueObject\VersionSource;
use App\Portfolio\Watch\Infrastructure\Doctrine\WatchedProductRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un produit dont on suit le cycle de vie chez endoflife.date.
 *
 * Le `slug` est l'identifiant du produit *chez le fournisseur* (« php »,
 * « postgresql »…), pas un libellé d'affichage : c'est lui qui construit
 * l'URL interrogée, d'où son unicité et son immuabilité — suivre un autre
 * produit, c'est créer une autre entrée.
 *
 * Contrairement aux autres contenus de `Portfolio/*`, cette entité **ne porte
 * pas de locale** (décision D6) : « PostgreSQL 18.4 » est un fait, pas une
 * traduction. Dupliquer la ligne par langue créerait deux vérités possibles
 * pour une même version installée.
 */
#[ORM\Entity(repositoryClass: WatchedProductRepository::class)]
#[ORM\Table(name: 'watched_product')]
#[ORM\UniqueConstraint(name: 'uniq_watched_product_slug', columns: ['slug'])]
class WatchedProduct implements Orderable
{
    /**
     * Spec 0003 D1/D2 : UUID v7 natif PostgreSQL, posé par le constructeur et
     * non par la base au flush. Une entité connaît donc son identité dès sa
     * construction — elle se compare et se teste sans persistance.
     */
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** Identifiant du produit chez endoflife.date, ex. « postgresql ». */
    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    private string $slug;

    /** Libellé affiché, ex. « PostgreSQL ». */
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private string $label;

    #[ORM\Column(enumType: VersionSource::class, length: 20)]
    private VersionSource $versionSource;

    /**
     * Version installée, **uniquement** pour une source `MANUAL`. Reste nulle
     * pour les sources runtime, où la valeur est lue à l'exécution (D2).
     */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $version;

    #[ORM\Column]
    private int $position;

    public function __construct(
        string $slug,
        string $label,
        VersionSource $versionSource,
        ?string $version,
        int $position,
    ) {
        $this->assertVersionMatchesSource($slug, $versionSource, $version);

        $this->id = Uuid::v7();
        $this->slug = $slug;
        $this->label = $label;
        $this->versionSource = $versionSource;
        $this->version = $version;
        $this->position = $position;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getVersionSource(): VersionSource
    {
        return $this->versionSource;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    /**
     * Ne touche pas à la position (spec 0004, D3) : modifier une entrée ne la
     * déplace pas, seul `moveToPosition()` — appelé par `OrderAssigner` — le fait.
     */
    public function update(
        string $label,
        VersionSource $versionSource,
        ?string $version,
    ): void {
        $this->assertVersionMatchesSource($this->slug, $versionSource, $version);

        $this->label = $label;
        $this->versionSource = $versionSource;
        $this->version = $version;
    }

    /**
     * Garde d'intégrité de D2, appliquée à la création comme à la modification :
     * une version saisie doit exister, une version runtime ne doit pas l'être.
     *
     * @throws InvalidWatchedProductException
     */
    private function assertVersionMatchesSource(
        string $slug,
        VersionSource $versionSource,
        ?string $version,
    ): void {
        if ($versionSource->isResolvedAtRuntime()) {
            if (null !== $version) {
                throw InvalidWatchedProductException::runtimeVersionMustNotBeProvided($slug, $versionSource);
            }

            return;
        }

        if (null === $version || '' === trim($version)) {
            throw InvalidWatchedProductException::manualVersionRequired($slug);
        }
    }

    /**
     * Spec 0004 D5 : sans locale (D6 du contexte Watch), un produit surveillé
     * n'a pas de groupe de traduction — il se range donc sous son propre id.
     * C'est la seule implémentation d'`Orderable` dont la clé n'est pas un
     * groupe, et sa ressource d'ordre prendra des `ids`, pas des `groups`.
     */
    public function orderingKey(): string
    {
        return $this->id->toRfc4122();
    }

    public function moveToPosition(int $position): void
    {
        $this->position = $position;
    }
}
