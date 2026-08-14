<?php

declare(strict_types=1);

namespace App\Portfolio\About\Domain\Repository;

use App\Portfolio\About\Domain\Entity\AboutSettings;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

/**
 * Abstraction (DIP) dont dépend la couche Application
 * (AboutSettingsAdministrator) : elle ne connaît jamais Doctrine directement.
 * L'implémentation concrète vit dans Infrastructure\Doctrine\AboutSettingsRepository.
 *
 * Pas de findOneById/findAll/remove : AboutSettings est un singleton par
 * locale, seedé une fois, jamais créé ni supprimé depuis le backoffice (voir
 * AboutSettingsAdministratorInterface, update() uniquement).
 */
interface AboutSettingsRepositoryInterface
{
    public function findByLocale(Locale $locale): ?AboutSettings;

    public function save(AboutSettings $settings): void;
}
