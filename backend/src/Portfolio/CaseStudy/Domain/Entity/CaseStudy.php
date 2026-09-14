<?php

declare(strict_types=1);

namespace App\Portfolio\CaseStudy\Domain\Entity;

use App\Portfolio\CaseStudy\Infrastructure\Doctrine\CaseStudyRepository;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * ADR 0003 D5 (palier `ROLE_TRUSTED`... non — palier de base `ROLE_USER`,
 * premier des trois contenus prévus) : un problème rencontré, ses
 * contraintes, la solution retenue, ses compromis, un résultat mesuré — sans
 * jamais nommer le client, ce qui est précisément ce qui permet à ce contenu
 * de rester non identifiant.
 *
 * Éditable depuis le backoffice (ROLE_SUPER), même mécanique que
 * Contribution/Incident.
 */
#[ORM\Entity(repositoryClass: CaseStudyRepository::class)]
#[ORM\Table(name: 'case_study')]
#[ORM\Index(name: 'idx_case_study_locale_position', columns: ['locale', 'position'])]
class CaseStudy
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

    /** La thèse retenue de l'étude de cas, pas un titre de ticket. */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $title;

    /** Le problème rencontré et ses contraintes — jamais le nom du client. */
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $problem;

    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $solution;

    /** Ce que la solution retenue a coûté, pas seulement ce qu'elle a apporté. */
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $tradeoffs;

    /** Un résultat mesuré, pas une affirmation ("plus rapide", "plus fiable"). */
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank]
    private string $measuredResult;

    #[ORM\Column]
    private int $position;

    public function __construct(
        Locale $locale,
        string $title,
        string $problem,
        string $solution,
        string $tradeoffs,
        string $measuredResult,
        int $position,
    ) {
        $this->id = Uuid::v7();
        $this->locale = $locale;
        $this->title = $title;
        $this->problem = $problem;
        $this->solution = $solution;
        $this->tradeoffs = $tradeoffs;
        $this->measuredResult = $measuredResult;
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

    public function getProblem(): string
    {
        return $this->problem;
    }

    public function getSolution(): string
    {
        return $this->solution;
    }

    public function getTradeoffs(): string
    {
        return $this->tradeoffs;
    }

    public function getMeasuredResult(): string
    {
        return $this->measuredResult;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function update(
        string $title,
        string $problem,
        string $solution,
        string $tradeoffs,
        string $measuredResult,
        int $position,
    ): void {
        $this->title = $title;
        $this->problem = $problem;
        $this->solution = $solution;
        $this->tradeoffs = $tradeoffs;
        $this->measuredResult = $measuredResult;
        $this->position = $position;
    }
}
