<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Service;

/**
 * Ne laisse passer qu'une URL qu'on peut poser sans risque dans un `href` de
 * page publique.
 *
 * Le besoin vient d'un cas concret : le lien de documentation d'un produit est
 * fourni par endoflife.date, dont le catalogue est un jeu de données **ouvert**.
 * Sans ce filtre, la chaîne traversait la couche anti-corruption, la base, puis
 * l'API publique intacte, et arrivait telle quelle dans un `:href` — or Vue ne
 * filtre pas les `href`. Un `javascript:…` publié chez le tiers devenait donc du
 * script exécutable sur une page anonyme du site. Compromettre un serveur
 * n'était pas nécessaire : faire accepter une donnée suffisait.
 *
 * D'où le principe retenu : **liste blanche de schémas, pas liste noire**.
 * Interdire `javascript:` laisserait passer `data:`, `vbscript:`, et le prochain
 * schéma que les navigateurs inventeront. On n'autorise donc que `https`.
 *
 * `http` en est délibérément exclu : un lien de documentation en clair est un
 * défaut chez le fournisseur, et le refuser ne coûte que l'absence du lien —
 * l'entrée reste affichée, en texte simple.
 *
 * Le filtre vit dans `Domain/` parce qu'il énonce une règle du domaine (« ce
 * qu'on accepte de republier »), et non une contrainte technique d'un
 * fournisseur donné. Il est appliqué **deux fois**, à l'écriture comme à la
 * lecture : voir la note de `WatchProvider`.
 */
final readonly class ExternalUrlFilter
{
    /**
     * @var list<string> schémas republiables, en minuscules
     */
    private const array ALLOWED_SCHEMES = ['https'];

    /**
     * Rend l'URL telle quelle si elle est republiable, `null` sinon — jamais une
     * version « nettoyée ». Réparer une URL douteuse reviendrait à deviner
     * l'intention de son auteur, et c'est ainsi qu'on reconstruit une charge
     * utile qu'on croyait avoir neutralisée.
     */
    public function keepIfSafe(?string $url): ?string
    {
        if (null === $url || '' === $url) {
            return null;
        }

        // Espaces, tabulations, retours et caractères de contrôle : les
        // navigateurs les ignorent en analysant un href, si bien que
        // "java\tscript:…" s'exécute. Une URL légitime n'en contient aucun,
        // donc on refuse plutôt que de les retirer.
        if (1 === preg_match('/[\x00-\x20\x7F]/', $url)) {
            return null;
        }

        $scheme = parse_url($url, \PHP_URL_SCHEME);

        if (!\is_string($scheme) || !\in_array(strtolower($scheme), self::ALLOWED_SCHEMES, true)) {
            return null;
        }

        // Un schéma valide ne suffit pas : encore faut-il un hôte. `https:/foo`
        // passe l'étape précédente sans désigner personne.
        $host = parse_url($url, \PHP_URL_HOST);

        return \is_string($host) && '' !== $host ? $url : null;
    }
}
