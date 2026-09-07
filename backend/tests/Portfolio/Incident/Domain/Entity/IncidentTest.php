<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Incident\Domain\Entity;

use App\Portfolio\Incident\Domain\Entity\Incident;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;

final class IncidentTest extends TestCase
{
    private function incident(): Incident
    {
        return new Incident(
            Locale::FR,
            'RabbitMQ en CrashLoopBackOff',
            'v0.5.0',
            new \DateTimeImmutable('2026-09-03'),
            'Formulaire de contact en 500 pendant quinze minutes.',
            'Le cookie Erlang est devenu accessible au groupe.',
            'Correction du mode du fichier, hotfix v0.5.1.',
            'Un securityContext sur un service à état demande un déploiement réel en préprod.',
            0,
        );
    }

    public function testConstructorSetsAllProperties(): void
    {
        $incident = $this->incident();

        self::assertNull($incident->getId());
        self::assertSame(Locale::FR, $incident->getLocale());
        self::assertSame('RabbitMQ en CrashLoopBackOff', $incident->getTitle());
        self::assertSame('v0.5.0', $incident->getVersion());
        self::assertSame('2026-09-03', $incident->getOccurredAt()->format('Y-m-d'));
        self::assertSame(0, $incident->getPosition());
    }

    /**
     * L'invariant est la raison d'être de l'entité : une panne sans règle
     * acquise n'a pas sa place sur la page. Le champ est obligatoire en base
     * comme dans le DTO ; ce test fige l'intention côté domaine.
     */
    public function testTheInvariantIsCarriedAsAFirstClassField(): void
    {
        self::assertSame(
            'Un securityContext sur un service à état demande un déploiement réel en préprod.',
            $this->incident()->getInvariant(),
        );
    }

    public function testUpdateReplacesEveryMutablePropertyButNotTheLocale(): void
    {
        $incident = $this->incident();

        $incident->update(
            'Nouveau titre',
            'v0.6.0',
            new \DateTimeImmutable('2026-09-10'),
            'Nouvel impact.',
            'Nouvelle cause.',
            'Nouvelle résolution.',
            'Nouvel invariant.',
            3,
        );

        self::assertSame('Nouveau titre', $incident->getTitle());
        self::assertSame('v0.6.0', $incident->getVersion());
        self::assertSame('2026-09-10', $incident->getOccurredAt()->format('Y-m-d'));
        self::assertSame('Nouvel impact.', $incident->getImpact());
        self::assertSame('Nouvelle cause.', $incident->getRootCause());
        self::assertSame('Nouvelle résolution.', $incident->getResolution());
        self::assertSame('Nouvel invariant.', $incident->getInvariant());
        self::assertSame(3, $incident->getPosition());
        // Changer la langue d'un incident revient à en créer un autre.
        self::assertSame(Locale::FR, $incident->getLocale());
    }
}
