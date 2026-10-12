<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Application;

use App\Portfolio\Experience\Domain\Entity\ExperienceTechnology;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyAlreadyExistsException;
use App\Portfolio\Experience\Domain\Repository\ExperienceTechnologyRepositoryInterface;
use App\Portfolio\Experience\Domain\ValueObject\ExperienceYears;
use App\Portfolio\Experience\Domain\ValueObject\TechnologyName;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;

/**
 * Modifie et supprime les technologies du backoffice.
 *
 * Chaque écriture tourne sous le verrou de ligne de sa technologie (issue
 * #545, {@see ExperienceTechnologyLockInterface}), corps entier : le verrou
 * relit la technologie, ou la dit introuvable si elle a été supprimée depuis
 * que le provider l'a chargée. Une suppression concurrente passe donc
 * entièrement avant l'écriture (404) ou entièrement après, jamais entre la
 * lecture et le `flush()` : le 200 d'un `PUT` décrit une technologie en base,
 * et le perdant de deux `DELETE` simultanés répond 404.
 */
final readonly class ExperienceTechnologyAdministrator implements ExperienceTechnologyAdministratorInterface
{
    public function __construct(
        private ExperienceTechnologyRepositoryInterface $experienceTechnologyRepository,
        private ExperienceTechnologyLockInterface $experienceTechnologyLock,
    ) {
    }

    public function update(Uuid $id, TechnologyName $name, ExperienceYears $years, ?string $iconKey, ?string $relatedTechnologyName, bool $secondary = false): ExperienceTechnology
    {
        return $this->experienceTechnologyLock->whileLocked(
            $id,
            fn (ExperienceTechnology $technology): ExperienceTechnology => $this->applyUpdate($technology, $name, $years, $iconKey, $relatedTechnologyName, $secondary),
        );
    }

    public function delete(Uuid $id): void
    {
        $this->experienceTechnologyLock->whileLocked($id, function (ExperienceTechnology $technology): void {
            $this->experienceTechnologyRepository->remove($technology);
        });
    }

    /**
     * Le corps de `update()`, sous le verrou : la technologie reçue est celle
     * que la base porte.
     */
    private function applyUpdate(ExperienceTechnology $technology, TechnologyName $name, ExperienceYears $years, ?string $iconKey, ?string $relatedTechnologyName, bool $secondary): ExperienceTechnology
    {
        $existingWithSameName = $this->experienceTechnologyRepository->findOneByName($name->value);

        // Comparaison par valeur (equals()) et non par identité (!==) : deux
        // Uuid de même valeur peuvent rester deux objets distincts.
        if (null !== $existingWithSameName && !$existingWithSameName->getId()->equals($technology->getId())) {
            throw ExperienceTechnologyAlreadyExistsException::forName($name->value);
        }

        $technology->update($name, $years, $iconKey, $relatedTechnologyName, $secondary);

        try {
            $this->experienceTechnologyRepository->save($technology);
        } catch (UniqueConstraintViolationException) {
            // Cf. ExperienceTechnologyRegistrar::register() : la vérification
            // findOneByName() ci-dessus n'est pas atomique avec ce save() (le
            // verrou ne tient que cette ligne-ci), la contrainte unique en
            // base reste le dernier rempart.
            throw ExperienceTechnologyAlreadyExistsException::forName($name->value);
        }

        return $technology;
    }
}
