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
     * Les commandes `app:*:seed` l'appellent une fois par entrée créée, avec
     * le périmètre rechargé à chaque fois : O(N²) en lectures, négligeable
     * pour des tables de dix entrées, à garder en tête si un seed devait un
     * jour en compter des centaines (#170, M3).
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
        // par ce service et par OrderAssigner) : la première vaut pour toutes.
        // Un groupe qui ne le respecte pas est un défaut du pipeline, pas une
        // saisie — il surface (500) plutôt que d'être arbitré en silence.
        $position = $members[0]->getPosition();
        foreach ($members as $member) {
            if ($member->getPosition() !== $position) {
                throw new \LogicException(\sprintf('Le groupe de traduction %s porte plusieurs positions.', $translationGroup->toRfc4122()));
            }
        }

        return $position;
    }

    /**
     * `PUT` avec `translationGroup: null` : sépare l'entrée de ses traductions
     * — groupe neuf — et l'envoie **en fin de périmètre** (issue #169). La
     * laisser à sa position paraissait plus doux (« séparée, pas déplacée »),
     * mais l'ancien groupe pouvait alors recevoir à nouveau cette langue par
     * « Créer la version », héritant de la même position : deux clés sur une
     * position, et un ordre public qui ne coïncide plus avec le tableau. Une
     * position par clé est l'invariant que tient ce service, et il ne se tient
     * qu'en déplaçant ce qu'on détache.
     *
     * Détacher une entrée déjà seule ne fait rien, plutôt que de lui forger un
     * groupe neuf et de la déplacer pour rien — c'est ce que le frontend envoie
     * pour toute entrée sans traduction.
     *
     * @param list<TranslatableContent> $members entrées du groupe actuel de `$entry`
     * @param list<TranslatableContent> $scope   toutes les entrées du périmètre, toutes langues confondues
     */
    public function detach(TranslatableContent $entry, array $members, array $scope): void
    {
        if (\count($members) <= 1) {
            return;
        }

        $entry->moveToPosition($this->atEndOf($scope));
        $entry->detachFromTranslationGroup();
    }

    /**
     * `PUT` avec un `translationGroup` : rattache l'entrée à ce groupe, dont
     * elle hérite la position. Son propre groupe est un non-geste.
     *
     * @param list<TranslatableContent> $members entrées du groupe demandé
     *
     * @throws UnknownTranslationGroupException
     * @throws TranslationAlreadyExistsException
     */
    public function reattach(TranslatableContent $entry, Uuid $translationGroup, array $members): void
    {
        if ($translationGroup->equals($entry->getTranslationGroup())) {
            return;
        }

        $entry->moveToPosition($this->inGroup($translationGroup, $entry->getLocale(), $members));
        $entry->attachToTranslationGroup($translationGroup);
    }
}
