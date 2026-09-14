<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Command;

use Symfony\Component\Uid\Uuid;

/**
 * Apparie les entrées d'une commande de peuplement d'une langue à l'autre.
 *
 * Spec 0004 D1 : deux lignes de même `translation_group` sont le même contenu
 * dans deux langues. Le contenu de référence des commandes `app:*:seed` est
 * **aligné par index** (`foreach ($items as $position => $item)`, la n-ième
 * entrée FR et la n-ième entrée EN disent la même chose) : l'index suffit donc
 * à les apparier, exactement comme la migration apparie l'existant sur la
 * position. Cette classe est ce rapprochement, et rien d'autre.
 *
 * Elle **retient** un groupe au lieu de le forger (spec 0004 D3) : la première
 * langue crée son entrée sans groupe — l'entité s'en forge un — et on note
 * celui-ci pour les langues suivantes. Forger le groupe ici, avant toute
 * entrée, produirait un groupe que rien ne porte encore : exactement ce que
 * `UnknownTranslationGroupException` refuse.
 *
 * Le `$scope` sépare les périmètres d'une même commande qui numérotent chacun
 * depuis zéro : « principes » et « traits » pour Qualité, les cartes site et
 * les cartes « moi » par catégorie pour À propos. Sans lui, l'entrée 0 des
 * principes et l'entrée 0 des traits partageraient un groupe et se
 * prétendraient traductions l'une de l'autre.
 *
 * Instanciée localement dans `execute()`, jamais injectée : son état ne doit
 * pas survivre à une exécution.
 */
final class TranslationGroupIndex
{
    /** @var array<string, array<int, Uuid>> */
    private array $groups = [];

    /**
     * Le groupe déjà connu pour ce couple, ou `null` s'il reste à naître.
     */
    public function forIndex(string $scope, int $index): ?Uuid
    {
        return $this->groups[$scope][$index] ?? null;
    }

    /**
     * Retient le groupe de la première entrée créée pour ce couple. Les appels
     * suivants ne l'écrasent pas : c'est la première langue qui fait foi.
     */
    public function remember(string $scope, int $index, Uuid $translationGroup): void
    {
        $this->groups[$scope][$index] ??= $translationGroup;
    }
}
