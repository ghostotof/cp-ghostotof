<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Prompt;

use Mcp\Exception\PromptGetException;
use Normalizer;

/**
 * L'argument `role` du prompt `present_for_role`, tel qu'il entre dans le
 * message : le texte libre d'une personne qui recrute, donc une donnée, jamais
 * une instruction (spec 0007, « Prompt »).
 *
 * Trois garde-fous, parce qu'un prompt construit côté serveur ne doit pas
 * devenir un relais d'injection :
 *
 *  - **une borne**, {@see self::MAX_CHARACTERS} caractères (pas octets),
 *    comptée deux fois : sur la saisie avant tout nettoyage (retirer des
 *    caractères ne fait pas passer un texte trop long), puis sur le texte
 *    nettoyé (NFKC peut l'allonger : U+FDFA y devient 18 caractères, #662).
 *    Au-delà, le texte est refusé — jamais tronqué : couper une phrase en
 *    change le sens, et la personne ne le verrait pas ;
 *  - **rien d'invisible** : caractères de contrôle (hors saut de ligne et
 *    tabulation), de format (largeur nulle, bidirectionnel, balises Unicode
 *    TAG), d'usage privé, non assignés, et tout ignorable par défaut —
 *    sélecteurs de variante, signes combinants et lettres de remplissage
 *    invisibles (U+034F, U+180B, U+3164…, #662) — sont retirés. Une annonce
 *    piégée collée dans `role` y porterait des consignes que la personne ne
 *    peut pas relire ;
 *  - **un bloc qu'il ne peut pas refermer** : {@see self::delimited()}
 *    l'encadre entre `<role>` et `</role>`, et les signes ouvrants qui
 *    introduisent le mot `role` sont retirés — la forme NFKC replie d'abord la
 *    pleine chasse (`＜／ｒｏｌｅ＞`), {@see self::OPENINGS} couvre ce qu'elle
 *    laisse : `<`, deux sosies hors ponctuation (`˂`, `ᐸ`), et par classe tout
 *    signe ouvrant et tout guillemet initial (`‹`, `〈`, `《`, `❰`, `«`, mais
 *    aussi `[` et `{`, qui ouvrent une balise ailleurs, #662). Comme le corpus
 *    de l'assistant de parcours (son `CorpusRenderer`), on ne retire **que
 *    ceux-là** : « équipe <10 personnes, PHP >= 8.4 » garde son sens.
 *
 * Une valeur refusée est une `PromptGetException`, la seule erreur que le SDK
 * 0.8.1 rend avec son message (`-32603`, quand le protocole attendrait
 * `-32602` : le SDK ne laisse pas le choix). Son message ne cite jamais le
 * texte reçu : le SDK le journalise avec l'erreur.
 */
final readonly class PromptRole
{
    /** Un intitulé de poste et quelques exigences ; une fiche de poste entière est refusée (décision du 2026-10-19). */
    public const int MAX_CHARACTERS = 500;

    private const string TAG = 'role';

    /**
     * Ce qui peut ouvrir une balise : `<` et ses sosies que NFKC ne replie pas
     * (la pleine chasse `＜` et le petit `﹤`, eux, le sont) — tout signe
     * ouvrant (`\p{Ps}`), tout guillemet initial (`\p{Pi}`), et les deux qui
     * ne sont pas de la ponctuation, `˂` (modificateur) et `ᐸ` (syllabique).
     * Une classe plutôt qu'une liste de sosies : la liste s'arrêtait au
     * dernier glyphe qu'on avait pensé à y mettre (`《`, `❰`, `«` passaient).
     */
    private const string OPENINGS = '<˂ᐸ\p{Ps}\p{Pi}';

    /**
     * Ce qui peut séparer un signe ouvrant du mot dans une balise : blancs,
     * barres et leurs sosies, signes combinants (qui s'accrochent au signe
     * sans le séparer à l'œil), signes ouvrants.
     */
    private const string TAG_RUN = '/[\s\p{Z}\p{M}\/⁄∕⧸'.self::OPENINGS.']*\z/u';

    /**
     * Contrôles (hors \n et \t), format, usage privé, non assignés, et les
     * ignorables par défaut, que `\p{Cf}` ne couvre pas tous : sélecteurs de
     * variante, U+034F, U+180B–180F, U+17B4–17B5 (`Mn`), lettres de
     * remplissage hangûl U+115F, U+1160, U+3164, U+FFA0 (`Lo`).
     */
    private const string INVISIBLE = '/[^\P{Cc}\n\t]|[\p{Cf}\p{Co}\p{Cn}\p{Default_Ignorable_Code_Point}]/u';

    private function __construct(
        public string $text,
    ) {
    }

    /**
     * @throws PromptGetException                texte sans lettre ni chiffre (avant ou après nettoyage) ou au-delà de la borne
     * @throws RoleNeutralisationFailedException échec de PCRE ou de la normalisation : un défaut du serveur, jamais un « rôle vide »
     */
    public static function fromInput(string $input): self
    {
        // La borne se compte sur ce que la personne a saisi, avant tout
        // nettoyage : retirer des caractères ne doit pas faire passer un texte
        // trop long.
        self::assertWithinBound($input);

        $text = self::withoutInvisibles(self::normalised($input));
        $text = self::trimmed(self::withoutTagOpenings($text));

        // Et sur ce qui entre dans le message : NFKC peut allonger le texte.
        self::assertWithinBound($text);

        // Une entrée en UTF-8 invalide n'arrive pas jusqu'ici, le décodage
        // JSON de la requête l'a déjà refusée ; un rôle sans lettre ni chiffre
        // (que des blancs, des chevrons, de la ponctuation) ne dit aucun poste.
        if (1 !== preg_match('/[\p{L}\p{N}]/u', $text)) {
            throw new PromptGetException('The role is empty: describe the role to present the author for.');
        }

        return new self($text);
    }

    /** Le rôle dans son bloc, tel qu'il termine le message du prompt. */
    public function delimited(): string
    {
        return '<'.self::TAG.">\n".$this->text."\n</".self::TAG.'>';
    }

    /**
     * Le même refus avant et après nettoyage : la personne n'a qu'une borne à
     * respecter, celle que publie la description de l'argument.
     *
     * @throws PromptGetException au-delà de {@see self::MAX_CHARACTERS} caractères
     */
    private static function assertWithinBound(string $text): void
    {
        if (mb_strlen($text) > self::MAX_CHARACTERS) {
            throw new PromptGetException(\sprintf('The role is too long: describe it in %d characters at most.', self::MAX_CHARACTERS));
        }
    }

    /** NFKC, puis une seule fin de ligne (`\r\n`, `\r`, NEL, U+2028 et U+2029 deviennent `\n`). */
    private static function normalised(string $input): string
    {
        $folded = Normalizer::normalize($input, Normalizer::FORM_KC);
        if (!\is_string($folded)) {
            throw RoleNeutralisationFailedException::fromNormalizer();
        }

        return self::replaced('/\r\n|[\r\x{0085}\x{2028}\x{2029}]/u', "\n", $folded);
    }

    private static function withoutInvisibles(string $text): string
    {
        return self::replaced(self::INVISIBLE, '', $text);
    }

    /**
     * Découpé sur le mot, chaque morceau qui le précède perd les signes
     * ouvrants de la séquence de caractères de balise qui le termine. Linéaire
     * en pratique sur un texte de {@see self::MAX_CHARACTERS} caractères au plus
     * (le corpus de l'assistant, sans borne, a dû l'être par construction).
     */
    private static function withoutTagOpenings(string $text): string
    {
        $parts = preg_split('/('.self::TAG.')/iu', $text, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if (false === $parts) {
            throw RoleNeutralisationFailedException::fromPcre();
        }

        for ($index = 0, $last = \count($parts) - 1; $index < $last; $index += 2) {
            if (1 !== preg_match(self::TAG_RUN, $parts[$index], $run, \PREG_OFFSET_CAPTURE)) {
                throw RoleNeutralisationFailedException::fromPcre();
            }

            $start = $run[0][1];
            $parts[$index] = substr($parts[$index], 0, $start).self::replaced('/['.self::OPENINGS.']/u', '', substr($parts[$index], $start));
        }

        return implode('', $parts);
    }

    /** Blancs Unicode compris (espace insécable, espace idéographique…), pas seulement ceux de `trim()`. */
    private static function trimmed(string $text): string
    {
        return self::replaced('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $text);
    }

    /**
     * `preg_replace()` rend `null` en cas d'échec : casté en chaîne, un rôle
     * bien rempli aurait été refusé comme « vide », et le défaut du serveur
     * perdu.
     */
    private static function replaced(string $pattern, string $replacement, string $text): string
    {
        return preg_replace($pattern, $replacement, $text) ?? throw RoleNeutralisationFailedException::fromPcre();
    }
}
