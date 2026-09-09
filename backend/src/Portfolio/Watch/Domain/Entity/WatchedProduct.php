<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Entity;

use App\Portfolio\Watch\Domain\Exception\InvalidWatchedProductException;
use App\Portfolio\Watch\Domain\ValueObject\VersionSource;
use App\Portfolio\Watch\Infrastructure\Doctrine\WatchedProductRepository;
use Doctrine\ORM\Mapping as ORM;
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
class WatchedProduct
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

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

        $this->slug = $slug;
        $this->label = $label;
        $this->versionSource = $versionSource;
        $this->version = $version;
        $this->position = $position;
    }

    public function getId(): ?int
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

    public function update(
        string $label,
        VersionSource $versionSource,
        ?string $version,
        int $position,
    ): void {
        $this->assertVersionMatchesSource($this->slug, $versionSource, $version);

        $this->label = $label;
        $this->versionSource = $versionSource;
        $this->version = $version;
        $this->position = $position;
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
}
