<?php

declare(strict_types=1);

namespace App\Portfolio\Incident\Domain\Entity;

use App\Portfolio\Incident\Infrastructure\Doctrine\IncidentRepository;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Un incident de production, sa cause racine et l'invariant qu'il a laissé.
 *
 * La forme est celle d'un post-mortem, imposée par le modèle plutôt que laissée
 * à la rédaction : impact, cause, résolution, invariant. Un texte libre
 * dériverait vers le récit ; ces quatre champs obligent à répondre aux quatre
 * questions qu'un lecteur se pose, dans cet ordre.
 *
 * `invariant` est le champ qui justifie la page. Sans lui, publier ses pannes
 * ne raconte qu'une suite d'échecs ; avec lui, chaque entrée se termine sur une
 * règle acquise. Il est NOT NULL à dessein : on ne doit pas pouvoir enregistrer
 * un incident dont on n'a rien tiré.
 */
#[ORM\Entity(repositoryClass: IncidentRepository::class)]
#[ORM\Table(name: 'incident')]
class Incident
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: Locale::class, length: 2)]
    private Locale $locale;

    /** Ce qui a cassé, en une ligne. */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $title;

    /**
     * Version concernée, ex. « v0.5.0 ». Une panne datée et versionnée est
     * vérifiable ; une panne anonyme est une anecdote.
     */
    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    private string $version;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    private \DateTimeImmutable $occurredAt;

    /** Conséquence visible, et sa durée. */
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $impact;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $rootCause;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $resolution;

    /** La règle acquise. Obligatoire — voir le docblock de la classe. */
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $invariant;

    #[ORM\Column]
    private int $position;

    public function __construct(
        Locale $locale,
        string $title,
        string $version,
        \DateTimeImmutable $occurredAt,
        string $impact,
        string $rootCause,
        string $resolution,
        string $invariant,
        int $position,
    ) {
        $this->locale = $locale;
        $this->title = $title;
        $this->version = $version;
        $this->occurredAt = $occurredAt;
        $this->impact = $impact;
        $this->rootCause = $rootCause;
        $this->resolution = $resolution;
        $this->invariant = $invariant;
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

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getImpact(): string
    {
        return $this->impact;
    }

    public function getRootCause(): string
    {
        return $this->rootCause;
    }

    public function getResolution(): string
    {
        return $this->resolution;
    }

    public function getInvariant(): string
    {
        return $this->invariant;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function update(
        string $title,
        string $version,
        \DateTimeImmutable $occurredAt,
        string $impact,
        string $rootCause,
        string $resolution,
        string $invariant,
        int $position,
    ): void {
        $this->title = $title;
        $this->version = $version;
        $this->occurredAt = $occurredAt;
        $this->impact = $impact;
        $this->rootCause = $rootCause;
        $this->resolution = $resolution;
        $this->invariant = $invariant;
        $this->position = $position;
    }
}
