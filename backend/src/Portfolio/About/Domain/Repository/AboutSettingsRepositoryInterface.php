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
    /**
     * Exécute son SELECT à chaque appel, jamais une simple lecture de la carte
     * d'identité de l'EntityManager (issue #553) : AboutSettingsAdministrator::update()
     * n'a pas de verrou parce que cette requête voit une ligne disparue (null,
     * donc 404) ou recréée sous un autre id depuis le chargement du provider.
     * Elle dit quelle ligne existe, pas ce qu'elle contient : les champs d'une
     * instance déjà chargée ne sont pas rafraîchis (Doctrine garde l'objet de
     * la carte d'identité). Une implémentation qui rendrait l'instance déjà
     * chargée sans requête (un find($id), un cache de second niveau, un
     * décorateur qui mémorise) ferait répondre 200 à un PUT sur une ligne
     * disparue : il faudrait alors le verrou de ligne de #545. Voir
     * `.claude/rules/portfolio-contenus.md`, section About.
     */
    public function findByLocale(Locale $locale): ?AboutSettings;

    public function save(AboutSettings $settings): void;
}
