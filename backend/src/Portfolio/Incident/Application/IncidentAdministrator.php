<?php

declare(strict_types=1);

namespace App\Portfolio\Incident\Application;

use App\Portfolio\Incident\Domain\Entity\Incident;
use App\Portfolio\Incident\Domain\Exception\IncidentNotFoundException;
use App\Portfolio\Incident\Domain\Repository\IncidentRepositoryInterface;
use App\Portfolio\Shared\Domain\Service\ContentPlacement;
use App\Portfolio\Shared\Domain\Service\OrderAssigner;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

final readonly class IncidentAdministrator implements IncidentAdministratorInterface
{
    public function __construct(
        private IncidentRepositoryInterface $incidentRepository,
        private ContentPlacement $contentPlacement,
        private OrderAssigner $orderAssigner,
    ) {
    }

    public function create(
        Locale $locale,
        string $title,
        string $version,
        \DateTimeImmutable $occurredAt,
        string $impact,
        string $rootCause,
        string $resolution,
        string $invariant,
        ?Uuid $translationGroup = null,
    ): Incident {
        $incident = new Incident($locale, $title, $version, $occurredAt, $impact, $rootCause, $resolution, $invariant, $this->positionFor($locale, $translationGroup), $translationGroup);

        $this->incidentRepository->save($incident);

        return $incident;
    }

    public function update(
        Uuid $id,
        string $title,
        string $version,
        \DateTimeImmutable $occurredAt,
        string $impact,
        string $rootCause,
        string $resolution,
        string $invariant,
        ?Uuid $translationGroup,
    ): Incident {
        $incident = $this->incidentRepository->findOneById($id);

        if (null === $incident) {
            throw IncidentNotFoundException::forId($id);
        }

        if (null === $translationGroup) {
            // Issue #169 : détacher envoie l'entrée en fin de périmètre, d'où
            // le chargement du périmètre — le même qu'à la création sans groupe.
            $this->contentPlacement->detach(
                $incident,
                $this->incidentRepository->findByTranslationGroup($incident->getTranslationGroup()),
                $this->incidentRepository->findAll(),
            );
        } else {
            $this->contentPlacement->reattach(
                $incident,
                $translationGroup,
                $this->incidentRepository->findByTranslationGroup($translationGroup),
            );
        }
        $incident->update($title, $version, $occurredAt, $impact, $rootCause, $resolution, $invariant);
        $this->incidentRepository->save($incident);

        return $incident;
    }

    public function delete(Uuid $id): void
    {
        $incident = $this->incidentRepository->findOneById($id);

        if (null === $incident) {
            throw IncidentNotFoundException::forId($id);
        }

        $this->incidentRepository->remove($incident);
    }

    public function reorder(array $keys): void
    {
        $scope = $this->incidentRepository->findAll();

        $this->orderAssigner->assign($scope, $keys);

        $this->incidentRepository->saveAll($scope);
    }

    /**
     * Spec 0004 D3 : sans groupe, l'entrée se range en fin de table (toutes
     * langues confondues) ; avec un groupe, elle hérite de sa position. Le
     * périmètre n'est chargé que dans la première branche.
     */
    private function positionFor(Locale $locale, ?Uuid $translationGroup): int
    {
        if (null === $translationGroup) {
            return $this->contentPlacement->atEndOf($this->incidentRepository->findAll());
        }

        return $this->contentPlacement->inGroup(
            $translationGroup,
            $locale,
            $this->incidentRepository->findByTranslationGroup($translationGroup),
        );
    }
}
