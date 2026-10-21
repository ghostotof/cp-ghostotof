<?php

declare(strict_types=1);

namespace App\Shared\Domain\Text;

use Normalizer;

/**
 * Empêche un texte qui n'est que de la donnée d'ouvrir ou de refermer le bloc
 * qui l'encadre, `<role…>` ou `<documents>` : le mot de la balise est le seul
 * paramètre (#667). Écrit une fois pour le rôle du prompt MCP
 * (`PromptRole`, #662) et le corpus de l'assistant de parcours
 * (`CorpusRenderer`) : les deux copies avaient divergé, le corpus resté en
 * retard sur le durcissement de #662.
 *
 * Trois passes, dans cet ordre :
 *
 *  - **NFKC**, qui replie la pleine chasse (`＜／ｒｏｌｅ＞`), les petits
 *    chevrons `﹤` et compose `≮` d'un `<` suivi de U+0338 — mais change aussi
 *    ce que lit le modèle : `ﬁ` → `fi`, `m²` → `m2`, `…` → `...`, l'espace
 *    insécable → une espace, accepté ;
 *  - **rien d'invisible** ({@see self::INVISIBLE}) : un texte piégé y porterait
 *    des consignes que personne ne relit, et `</d\u{200B}ocuments>` s'affiche
 *    comme la balise sans que le mot soit reconnu ;
 *  - **aucun signe ouvrant devant le mot** ({@see self::OPENING}), retiré à
 *    travers tout ce qui n'est ni lettre ni chiffre ({@see self::TAG_RUN}). On
 *    retire le signe plutôt que la balise entière : une balise non fermée
 *    n'avale aucun texte, et sans signe ouvrant ce n'est plus qu'un mot.
 *    « équipe <10 personnes, PHP >= 8.4 » garde son sens ; « (role-based…) »
 *    ou « [documents](…) » perdent leur signe ouvrant, accepté.
 *
 * Aucun blanc n'est touché (hors NFKC) : l'aplatissement sur une ligne
 * appartient au rôle MCP, le Markdown du corpus vit de ses sauts de ligne.
 *
 * **Pas exhaustif**, et ne prétend pas l'être : lettres d'autres alphabets
 * (`</d\u{043E}cuments>`), sosies du même alphabet (petites capitales), un signe
 * combinant dans le mot, des lettres employées comme séparateurs, entités
 * HTML, guillemets fermants employés comme ouvrants (`\p{Pf}`), symboles non
 * listés (`≺`, `◁`). Le rôle MCP a son nonce pour les couvrir ; le corpus s'en
 * remet à son préambule (règle 6) et au fait que seul ROLE_SUPER l'écrit.
 */
final readonly class TagWordNeutraliser
{
    /**
     * Ce qui peut ouvrir une balise et que NFKC ne replie pas sur `<` (la
     * pleine chasse `＜` et le petit `﹤`, eux, le sont) : par classe, tout
     * signe ouvrant (`\p{Ps}` : `〈 ⟨ 《 ❰ ❮ [ {`…) et tout guillemet initial
     * (`\p{Pi}` : `‹ «`…) — une liste de sosies s'arrêtait au dernier glyphe
     * qu'on y avait mis (#662) — ; par nom, les sosies qui ne sont pas de la
     * ponctuation : `<`, `≮` (que NFKC compose avec un `<` suivi de U+0338),
     * les modificateurs `˂ ˱`, la syllabique `ᐸ` et la runique `ᚲ`.
     */
    private const string OPENING = '/[<≮˂˱ᐸᚲ\p{Ps}\p{Pi}]/u';

    /**
     * La séquence qui peut séparer un signe ouvrant du mot : tout ce qui n'est
     * ni lettre ni chiffre — blancs, barres, signes combinants, symboles
     * (#662 : une liste de séparateurs laissait `<\role`, `<|role`, `<⟋role`) —
     * et les deux signes ouvrants qui sont des lettres (`ᐸ ᚲ`), pour qu'ils
     * s'enchaînent comme les autres. Une lettre ou un chiffre arrête la
     * séquence : « <10 personnes, role » garde son chevron.
     *
     * Linéaire : la recherche ne part qu'en tête d'une séquence (le
     * lookbehind refuse un départ au milieu) et ne revient pas sur ce qu'elle
     * a lu (`*+`). Sans eux, chaque position d'une longue séquence suivie
     * d'une lettre la relisait jusqu'au bout — quadratique. Le corpus est rendu
     * à chaque appel de l'assistant (troisième passe d'audit de la spec 0005).
     */
    private const string TAG_RUN = '/(?<![^\p{L}\p{N}]|[ᐸᚲ])(?:[^\p{L}\p{N}]|[ᐸᚲ])*+\z/u';

    /**
     * Contrôles (hors blancs), format, usage privé, non assignés, et les
     * ignorables par défaut, que `\p{Cf}` ne couvre pas tous : sélecteurs de
     * variante, U+034F, U+180B–180D, U+180F, U+17B4–17B5 (`Mn`), lettres de
     * remplissage hangûl U+115F, U+1160, U+3164, U+FFA0 (`Lo`).
     */
    private const string INVISIBLE = '/(?![\t\n\x{0B}\f\r\x{85}])\p{Cc}|[\p{Cf}\p{Co}\p{Cn}\p{Default_Ignorable_Code_Point}]/u';

    /**
     * @param non-empty-string $word le mot de la balise, `role` ou `documents`
     */
    public function __construct(
        private string $word,
    ) {
    }

    /**
     * @throws TextNeutralisationFailedException échec de PCRE ou de la normalisation : un défaut du serveur
     */
    public function neutralise(string $text): string
    {
        return $this->withoutTagOpenings($this->withoutInvisibles($this->normalised($text)));
    }

    private function normalised(string $text): string
    {
        $folded = Normalizer::normalize($text, Normalizer::FORM_KC);
        if (!\is_string($folded)) {
            throw TextNeutralisationFailedException::fromNormalizer();
        }

        return $folded;
    }

    private function withoutInvisibles(string $text): string
    {
        return $this->replaced(self::INVISIBLE, '', $text);
    }

    /**
     * Découpé sur le mot, chaque morceau qui le précède perd les signes
     * ouvrants de la séquence de caractères de balise qui le termine. Chaque
     * morceau n'est parcouru qu'une fois : le tout reste linéaire, et aucun
     * retrait ne peut rapprocher un autre signe du mot.
     */
    private function withoutTagOpenings(string $text): string
    {
        $parts = preg_split('/('.preg_quote($this->word, '/').')/iu', $text, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if (false === $parts) {
            throw TextNeutralisationFailedException::fromPcre();
        }

        for ($index = 0, $last = \count($parts) - 1; $index < $last; $index += 2) {
            if (1 !== preg_match(self::TAG_RUN, $parts[$index], $run, \PREG_OFFSET_CAPTURE)) {
                throw TextNeutralisationFailedException::fromPcre();
            }

            $start = $run[0][1];
            $parts[$index] = substr($parts[$index], 0, $start).$this->replaced(self::OPENING, '', substr($parts[$index], $start));
        }

        return implode('', $parts);
    }

    /**
     * `preg_replace()` rend `null` en cas d'échec : casté en chaîne, le texte
     * disparaîtrait sans que rien ne le signale.
     */
    private function replaced(string $pattern, string $replacement, string $text): string
    {
        return preg_replace($pattern, $replacement, $text) ?? throw TextNeutralisationFailedException::fromPcre();
    }
}
