<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Incident\Domain\Entity;

use App\Portfolio\Incident\Domain\Entity\Incident;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

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

    /**
     * Spec 0003 D1 : l'identite est posee par le constructeur, pas par le
     * flush. Une entite construite est donc deja identifiable, comparable et
     * testable sans base de donnees.
     */
    public function testANewIncidentIsIdentifiedByAUuidV7BeforeAnyPersistence(): void
    {
        self::assertInstanceOf(UuidV7::class, $this->incident()->getId());
    }

    /**
     * Pin le v7 et non le v4 : deux constructions successives doivent donner
     * des identifiants distincts et croissants, sur quoi repose l'ordre de
     * repli `ORDER BY id` du repository.
     */
    public function testTwoIncidentsBuiltInSequenceGetDistinctIncreasingIds(): void
    {
        $first = $this->incident();
        $second = $this->incident();

        self::assertNotSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());
        self::assertLessThan($second->getId()->toRfc4122(), $first->getId()->toRfc4122());
    }

    public function testConstructorSetsAllProperties(): void
    {
        $incident = $this->incident();

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
        );

        self::assertSame('Nouveau titre', $incident->getTitle());
        self::assertSame('v0.6.0', $incident->getVersion());
        self::assertSame('2026-09-10', $incident->getOccurredAt()->format('Y-m-d'));
        self::assertSame('Nouvel impact.', $incident->getImpact());
        self::assertSame('Nouvelle cause.', $incident->getRootCause());
        self::assertSame('Nouvelle résolution.', $incident->getResolution());
        self::assertSame('Nouvel invariant.', $incident->getInvariant());
        // Spec 0004 D3 : `update()` ne touche plus à la position — elle ne se
        // saisit pas, seuls le rattachement à un groupe et l'endpoint d'ordre
        // l'écrivent.
        self::assertSame(0, $incident->getPosition());
        // Changer la langue d'un incident revient à en créer un autre.
        self::assertSame(Locale::FR, $incident->getLocale());
    }

    /**
     * Spec 0004 D1 : le groupe de traduction est un identifiant partagé, pas
     * une entité. Une entrée construite sans groupe en reçoit un neuf — elle
     * est donc toujours dans un groupe, fût-il d'une seule langue, ce qui
     * permet à `translation_group` d'être NOT NULL.
     */
    public function testAnIncidentBuiltWithoutAGroupGetsAFreshTranslationGroup(): void
    {
        $first = $this->incident();
        $second = $this->incident();

        self::assertInstanceOf(UuidV7::class, $first->getTranslationGroup());
        self::assertFalse($first->getTranslationGroup()->equals($second->getTranslationGroup()));
    }

    /**
     * L'autre voie : la version EN d'un contenu reçoit le groupe de la version
     * FR à la construction (c'est ce que font les commandes de peuplement).
     */
    public function testAnIncidentBuiltWithAGroupCarriesIt(): void
    {
        $group = Uuid::v7();

        $incident = new Incident(
            Locale::EN,
            'RabbitMQ in CrashLoopBackOff',
            'v0.5.0',
            new \DateTimeImmutable('2026-09-03'),
            'Contact form returning 500 for fifteen minutes.',
            'The Erlang cookie became group-readable.',
            'File mode fixed, hotfix v0.5.1.',
            'A securityContext change on a stateful service needs a real preprod rollout.',
            0,
            $group,
        );

        self::assertTrue($group->equals($incident->getTranslationGroup()));
    }

    public function testAttachToTranslationGroupLinksTheEntryToAnExistingGroup(): void
    {
        $incident = $this->incident();
        $group = Uuid::v7();

        $incident->attachToTranslationGroup($group);

        self::assertTrue($group->equals($incident->getTranslationGroup()));
    }

    /**
     * Détacher ne remet pas le groupe à `null` — la colonne est NOT NULL : elle
     * en reçoit un neuf, ce qui isole l'entrée de ses anciennes traductions.
     */
    public function testDetachFromTranslationGroupGivesAFreshGroup(): void
    {
        $incident = $this->incident();
        $previous = $incident->getTranslationGroup();

        $incident->detachFromTranslationGroup();

        self::assertInstanceOf(UuidV7::class, $incident->getTranslationGroup());
        self::assertFalse($previous->equals($incident->getTranslationGroup()));
    }

    /**
     * Spec 0004 D5 : la clé d'ordre d'un contenu localisé est son groupe, pas
     * son id — c'est ce qui fait qu'un déplacement suit le contenu dans toutes
     * les langues.
     */
    public function testOrderingKeyIsTheTranslationGroupAndNotTheId(): void
    {
        $incident = $this->incident();

        self::assertSame($incident->getTranslationGroup()->toRfc4122(), $incident->orderingKey());
        self::assertNotSame($incident->getId()->toRfc4122(), $incident->orderingKey());
    }

    public function testMoveToPositionWritesThePosition(): void
    {
        $incident = $this->incident();

        $incident->moveToPosition(4);

        self::assertSame(4, $incident->getPosition());
    }
}
