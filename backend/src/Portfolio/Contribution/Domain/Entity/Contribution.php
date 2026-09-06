<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Domain\Entity;

use App\Portfolio\Contribution\Infrastructure\Doctrine\ContributionRepository;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\Mapping as ORM;
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
class Contribution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: Locale::class, length: 2)]
    private Locale $locale;

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
    ) {
        $this->locale = $locale;
        $this->title = $title;
        $this->project = $project;
        $this->reference = $reference;
        $this->url = $url;
        $this->summary = $summary;
        $this->body = $body;
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
}
