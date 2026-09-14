<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Domain\Service;

use App\Portfolio\Shared\Domain\Exception\TranslationAlreadyExistsException;
use App\Portfolio\Shared\Domain\Exception\UnknownTranslationGroupException;
use App\Portfolio\Shared\Domain\TranslatableContent;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

/**
 * Spec 0004 D3 — où se place une entrée, et dans quel groupe.
 *
 * La position n'est plus jamais saisie : elle se déduit. Une entrée rattachée
 * à un groupe **hérite de la position du groupe** — c'est ce qui fait qu'un
 * déplacement suit le contenu quelle que soit la langue (D5) ; une entrée sans
 * groupe prend la fin de son périmètre. Même esprit que « aucune version n'est
 * jamais tapée » du contexte Watch : ce qui se déduit ne se saisit pas.
 *
 * Service pur : il ne connaît ni Doctrine ni API Platform, reçoit des entités
 * déjà chargées et n'en persiste aucune. C'est l'`Administrator` qui décide
 * quoi charger — et il ne charge que ce dont la branche a besoin, jamais tout
 * le périmètre pour un simple rattachement.
 *
 * Le « périmètre » est la table entière, toutes langues confondues (les
 * traductions d'un contenu partagent sa position, la numérotation est donc
 * commune), sauf pour les cartes « moi » où c'est la catégorie.
 */
final readonly class ContentPlacement
{
    /**
     * Position d'une entrée neuve sans groupe : après la dernière du
     * périmètre, 0 si le périmètre est vide.
     *
     * @param list<TranslatableContent> $scope toutes les entrées du périmètre, toutes langues confondues
     */
    public function atEndOf(array $scope): int
    {
        if ([] === $scope) {
            return 0;
        }

        return max(array_map(static fn (TranslatableContent $entry): int => $entry->getPosition(), $scope)) + 1;
    }

    /**
     * Position héritée d'un groupe existant, après avoir vérifié qu'il accepte
     * cette langue.
     *
     * @param list<TranslatableContent> $members entrées déjà dans ce groupe et dans le périmètre
     *
     * @throws UnknownTranslationGroupException  si le groupe n'existe pas dans le périmètre
     * @throws TranslationAlreadyExistsException si le groupe porte déjà cette langue
     */
    public function inGroup(Uuid $translationGroup, Locale $locale, array $members): int
    {
        if ([] === $members) {
            throw UnknownTranslationGroupException::forGroup($translationGroup);
        }

        foreach ($members as $member) {
            if ($member->getLocale() === $locale) {
                throw TranslationAlreadyExistsException::forGroupAndLocale($translationGroup, $locale);
            }
        }

        // Toutes les entrées d'un groupe partagent sa position (invariant tenu
        // par ce service et, à partir de B3, par OrderAssigner) : la première
        // vaut pour toutes.
        return $members[0]->getPosition();
    }

    /**
     * Applique à une entrée existante le groupe demandé par un `PUT`.
     *
     * `null` détache : l'entrée reçoit un groupe neuf et **garde sa position**
     * — on l'a séparée de ses traductions, pas déplacée. Détacher une entrée
     * déjà seule ne fait rien, plutôt que de lui forger un groupe neuf pour
     * rien.
     *
     * @param list<TranslatableContent> $members entrées du groupe demandé — ou, si `$translationGroup`
     *                                           est `null`, du groupe actuel de `$entry`
     *
     * @throws UnknownTranslationGroupException
     * @throws TranslationAlreadyExistsException
     */
    public function reattach(TranslatableContent $entry, ?Uuid $translationGroup, array $members): void
    {
        if (null === $translationGroup) {
            if (\count($members) > 1) {
                $entry->detachFromTranslationGroup();
            }

            return;
        }

        if ($translationGroup->equals($entry->getTranslationGroup())) {
            return;
        }

        $entry->moveToPosition($this->inGroup($translationGroup, $entry->getLocale(), $members));
        $entry->attachToTranslationGroup($translationGroup);
    }
}
