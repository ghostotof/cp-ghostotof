<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Service;

/**
 * Ne laisse passer qu'un nom de cycle ou de version qui a la forme d'un numéro
 * de version, et rien d'autre (issue #658).
 *
 * Le nom d'un cycle (« 8.5 ») et celui de sa dernière version (« 8.5.10 »)
 * viennent du jeu de données **ouvert** d'endoflife.date, comme le lien de
 * documentation qu'{@see ExternalUrlFilter} garde déjà. Ils atteignent la page
 * `/stack` et, depuis le serveur MCP, le modèle de toute personne qui y branche
 * son assistant (`get_tech_watch`, ADR 0006 D1). Une PR malveillante fusionnée
 * chez le fournisseur, ou son API compromise, y glisserait du texte libre — des
 * instructions lues par ce modèle : une injection de prompt indirecte. Vue
 * échappe le texte, la page ne risque rien ; le modèle, si.
 *
 * Le rapprochement de la version en service n'arrêtait rien :
 * {@see InstalledVersionMatcher} ne lit que le préfixe numérique de chaque
 * segment, si bien que « 8.5 — SYSTEM: … » correspondait au cycle 8.5.
 *
 * D'où une **liste blanche de forme**, plutôt qu'une liste noire de mots ou de
 * signes : un premier caractère alphanumérique ASCII, puis des alphanumériques,
 * `.`, `+`, `_` et `-` — de quoi écrire « 8.5.10 », « v1.2 », « 3.0.0-rc.1 » ou
 * « 1.2.3+build.5 », sans espace ni ponctuation de phrase. Une borne de
 * longueur s'y ajoute : le relevé du 2026-10-22 sur les sept produits du
 * catalogue donne 8 caractères au plus, 32 laisse une large marge sans laisser
 * de place à une phrase.
 *
 * Comme pour les URL, une valeur refusée devient **absente, jamais
 * « nettoyée »**, et le filtre s'applique **deux fois** : à l'écriture, dans le
 * client du fournisseur, et à la relecture, dans `WatchProvider` — un relevé
 * écrit avant ce correctif garde la valeur brute jusqu'au rafraîchissement
 * suivant.
 */
final readonly class VersionNameFilter
{
    /** Longueur maximale d'un nom accepté, en caractères (ASCII seulement). */
    public const int MAX_LENGTH = 32;

    /**
     * `\z` et non `$`, qui accepterait un retour à la ligne final. La borne
     * `{0,31}` vaut `MAX_LENGTH - 1` : le premier caractère est compté à part.
     */
    private const string WELL_FORMED = '/\A[0-9A-Za-z][0-9A-Za-z.+_-]{0,31}\z/';

    /**
     * Rend le nom tel quel s'il a la forme d'un numéro de version, `null`
     * sinon — jamais une version tronquée ou débarrassée de ses caractères en
     * trop, qui ferait passer pour fiable un morceau de la valeur hostile.
     */
    public function keepIfWellFormed(?string $name): ?string
    {
        if (null === $name) {
            return null;
        }

        return 1 === preg_match(self::WELL_FORMED, $name) ? $name : null;
    }
}
