<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Domain\Entity;

use App\Portfolio\Experience\Infrastructure\Doctrine\ExperienceTechnologyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Une technologie du parcours technique, avec le temps cumulé passé dessus
 * (en années). Créée via la commande
 * app:experience:add-technology (cf. Presentation\Command\AddExperienceTechnologyCommand)
 * ou via le backoffice (ROLE_SUPER, cf. Presentation\ApiResource\BackofficeExperienceTechnologyResource),
 * éditable/supprimable uniquement depuis ce dernier.
 */
#[ORM\Entity(repositoryClass: ExperienceTechnologyRepository::class)]
#[ORM\Table(name: 'experience_technology')]
#[ORM\UniqueConstraint(name: 'uniq_experience_technology_name', columns: ['name'])]
class ExperienceTechnology
{
    /**
     * Spec 0003 D1/D2 : UUID v7 natif PostgreSQL, posé par le constructeur et
     * non par la base au flush. Une entité connaît donc son identité dès sa
     * construction — elle se compare et se teste sans persistance.
     */
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    private string $name;

    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private float $years;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $iconKey;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $relatedTechnologyName;

    /**
     * Technologie pratiquée au fil du parcours sans structurer le profil.
     * Elle sort du classement chiffré pour rejoindre une énumération unique,
     * sans durée.
     *
     * Pourquoi une donnée et non une règle de présentation : afficher « Python
     * ~6 mois » revient à publier l'endroit où l'on débute, alors que personne
     * ne l'a demandé — c'est un arbitrage éditorial, propre à chaque
     * technologie, qui doit rester modifiable depuis le backoffice sans
     * redéploiement. Un seuil automatique sur `years` produirait au contraire
     * un classement qui se réorganise tout seul au fil des mises à jour.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $secondary;

    public function __construct(
        string $name,
        float $years,
        ?string $iconKey = null,
        ?string $relatedTechnologyName = null,
        bool $secondary = false,
    ) {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->years = $years;
        $this->iconKey = $iconKey;
        $this->relatedTechnologyName = $relatedTechnologyName;
        $this->secondary = $secondary;
    }

    public function getId(): Uuid
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

    public function isSecondary(): bool
    {
        return $this->secondary;
    }

    public function update(
        string $name,
        float $years,
        ?string $iconKey,
        ?string $relatedTechnologyName,
        bool $secondary = false,
    ): void {
        $this->name = $name;
        $this->years = $years;
        $this->iconKey = $iconKey;
        $this->relatedTechnologyName = $relatedTechnologyName;
        $this->secondary = $secondary;
    }
}
