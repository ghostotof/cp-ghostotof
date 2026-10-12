<?php

declare(strict_types=1);

namespace App\Portfolio\About\Application;

use App\Portfolio\About\Domain\Entity\AboutSettings;
use App\Portfolio\About\Domain\Exception\AboutSettingsNotFoundException;
use App\Portfolio\About\Domain\Repository\AboutSettingsRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

final readonly class AboutSettingsAdministrator implements AboutSettingsAdministratorInterface
{
    public function __construct(
        private AboutSettingsRepositoryInterface $aboutSettingsRepository,
    ) {
    }

    /**
     * Sans verrou, par décision (issue #553) : aucun chemin applicatif ne
     * supprime les réglages, et findByLocale() relit la base (recherche par
     * critère), si bien qu'une ligne supprimée en SQL depuis le chargement du
     * provider donne un 404 au lieu d'un 200. Ne pas remplacer cette recherche
     * par un find($id) sans verrou ; une suppression applicative des réglages
     * (purge du seed, DELETE) imposerait d'abord le verrou de ligne de #545.
     * Voir `.claude/rules/portfolio-contenus.md`, section About.
     */
    public function update(
        Locale $locale,
        string $siteEyebrow,
        string $meEyebrow,
        string $technicalSubtitle,
        string $personalSubtitle,
        string $hobbiesSubtitle,
    ): AboutSettings {
        $settings = $this->aboutSettingsRepository->findByLocale($locale);

        if (null === $settings) {
            throw AboutSettingsNotFoundException::forLocale($locale);
        }

        $settings->update($siteEyebrow, $meEyebrow, $technicalSubtitle, $personalSubtitle, $hobbiesSubtitle);
        $this->aboutSettingsRepository->save($settings);

        return $settings;
    }
}
