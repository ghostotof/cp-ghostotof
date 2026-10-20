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
 * Quatre garde-fous, parce qu'un prompt construit côté serveur ne doit pas
 * devenir un relais d'injection :
 *
 *  - **une borne**, {@see self::MAX_CHARACTERS} caractères (pas octets),
 *    comptée sur la saisie avant tout nettoyage (retirer des caractères ne fait
 *    pas passer un texte trop long) — celle que publie la description de
 *    l'argument ; et un **plafond** sur le texte nettoyé,
 *    {@see self::MAX_CLEANED_CHARACTERS}, parce que NFKC peut l'allonger
 *    (U+FDFA y devient 18 caractères : ~9 000 injectés avant #662). Le double
 *    de la borne, pas la borne : une autocorrection qui pose des « … » (trois
 *    caractères une fois repliés) ne fait pas refuser un texte qui la respecte.
 *    Au-delà, le texte est refusé — jamais tronqué : couper une phrase en
 *    change le sens, et la personne ne le verrait pas ;
 *  - **rien d'invisible** : caractères de contrôle (hors blancs), de format
 *    (largeur nulle, bidirectionnel, balises Unicode TAG), d'usage privé, non
 *    assignés, et tout ignorable par défaut — sélecteurs de variante, le
 *    graphème combinant de jonction U+034F, les lettres de remplissage hangûl
 *    (#662) — sont retirés. Une annonce piégée collée dans `role` y porterait
 *    des consignes que la personne ne peut pas relire ;
 *  - **une seule ligne** : toute suite de blancs, sauts de ligne compris,
 *    devient une espace (#662). Un intitulé et quelques exigences tiennent sur
 *    une ligne ; des sauts de ligne laissaient cacher une consigne soixante
 *    lignes plus bas, ou mimer sur sa ligne une fin de bloc et un faux rappel ;
 *  - **un bloc qu'il ne peut pas refermer** : {@see self::delimited()}
 *    l'encadre sous une balise qui porte un nonce ({@see RoleBlockTag}), que
 *    l'auteur d'une annonce piégée ne connaît pas — la défense qui tient
 *    contre tous les sosies. En profondeur, les signes ouvrants qui
 *    introduisent le mot `role` sont retirés aussi : la forme NFKC replie
 *    d'abord la pleine chasse (`＜／ｒｏｌｅ＞`), {@see self::OPENING} couvre ce
 *    qu'elle laisse. Comme le corpus de l'assistant de parcours (son
 *    `CorpusRenderer`), on ne retire **que ceux-là** : « équipe <10 personnes,
 *    PHP >= 8.4 » garde son sens ; « (role-based…) » perd sa parenthèse,
 *    accepté.
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

    /**
     * Le refus d'un `role` absent ou d'un autre type qu'une chaîne (#663) : un
     * argument de prompt est une chaîne (MCP, `GetPromptRequest`).
     */
    public const string NOT_A_TEXT = 'The role must be a text: describe the role to present the author for.';

    /** Le plafond du texte nettoyé : le double de la borne publiée (décision du 2026-10-20, #662). */
    public const int MAX_CLEANED_CHARACTERS = 2 * self::MAX_CHARACTERS;

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
     * d'une lettre la relisait jusqu'au bout — quadratique, sur un texte que
     * NFKC peut porter à ~9 000 caractères avant le plafond.
     */
    private const string TAG_RUN = '/(?<![^\p{L}\p{N}]|[ᐸᚲ])(?:[^\p{L}\p{N}]|[ᐸᚲ])*+\z/u';

    /**
     * Contrôles (hors blancs, que {@see self::BLANKS} réduit ensuite), format,
     * usage privé, non assignés, et les ignorables par défaut, que `\p{Cf}` ne
     * couvre pas tous : sélecteurs de variante, U+034F, U+180B–180D, U+180F,
     * U+17B4–17B5 (`Mn`), lettres de remplissage hangûl U+115F, U+1160, U+3164,
     * U+FFA0 (`Lo`).
     */
    private const string INVISIBLE = '/(?![\t\n\x{0B}\f\r\x{85}])\p{Cc}|[\p{Cf}\p{Co}\p{Cn}\p{Default_Ignorable_Code_Point}]/u';

    /**
     * Toute suite de blancs : ceux de PCRE et d'Unicode (sauts de ligne, NEL,
     * U+2028, U+2029, espaces insécables et idéographiques…), et deux
     * caractères qui s'affichent blancs sans en être, le blanc braille U+2800
     * et la note nulle U+1D159 (#662).
     */
    private const string BLANKS = '/[\s\p{Z}\x{85}\x{2800}\x{1D159}]+/u';

    private function __construct(
        public string $text,
    ) {
    }

    /**
     * L'argument tel que le SDK le reçoit, **non typé** (#663) : un paramètre
     * `string` laissait le SDK 0.8.1 convertir lui-même un autre type — un
     * tableau en « Array », avec un avertissement PHP sur le canal `php`,
     * qu'un anonyme provoquait à volonté en production. Le type est refusé
     * ici, avant toute conversion ; un `role` absent arrive `null` et l'est
     * de même.
     *
     * @throws PromptGetException                autre chose qu'une chaîne, texte sans lettre ni chiffre après nettoyage, au-delà de la borne ou du plafond
     * @throws RoleNeutralisationFailedException échec de PCRE ou de la normalisation : un défaut du serveur, jamais un « rôle vide »
     */
    public static function fromInput(mixed $input): self
    {
        if (!\is_string($input)) {
            throw new PromptGetException(self::NOT_A_TEXT);
        }

        // La borne se compte sur ce que la personne a saisi, avant tout
        // nettoyage : retirer des caractères ne doit pas faire passer un texte
        // trop long.
        self::assertAtMost(self::MAX_CHARACTERS, $input);

        $text = self::withoutInvisibles(self::normalised($input));
        $text = self::onOneLine(self::withoutTagOpenings($text));

        // Et le plafond sur ce qui entre dans le message : NFKC peut allonger
        // le texte.
        self::assertAtMost(self::MAX_CLEANED_CHARACTERS, $text);

        // Une entrée en UTF-8 invalide n'arrive pas jusqu'ici, le décodage
        // JSON de la requête l'a déjà refusée ; un rôle sans lettre ni chiffre
        // (que des blancs, des chevrons, de la ponctuation) ne dit aucun poste.
        if (1 !== preg_match('/[\p{L}\p{N}]/u', $text)) {
            throw new PromptGetException('The role is empty: describe the role to present the author for.');
        }

        return new self($text);
    }

    /** Le rôle dans son bloc, sous la balise que le prompt a tirée, tel qu'il termine le message. */
    public function delimited(RoleBlockTag $tag): string
    {
        return $tag->opening()."\n".$this->text."\n".$tag->closing();
    }

    /**
     * Le même message pour la borne et le plafond : la personne n'a qu'une
     * borne à respecter, celle que publie la description de l'argument, et le
     * plafond ne refuse pas un texte qui la respecte sans artifice.
     *
     * @throws PromptGetException au-delà de `$limit` caractères
     */
    private static function assertAtMost(int $limit, string $text): void
    {
        if (mb_strlen($text) > $limit) {
            throw new PromptGetException(\sprintf('The role is too long: describe it in %d characters at most.', self::MAX_CHARACTERS));
        }
    }

    private static function normalised(string $input): string
    {
        $folded = Normalizer::normalize($input, Normalizer::FORM_KC);
        if (!\is_string($folded)) {
            throw RoleNeutralisationFailedException::fromNormalizer();
        }

        return $folded;
    }

    private static function withoutInvisibles(string $text): string
    {
        return self::replaced(self::INVISIBLE, '', $text);
    }

    /**
     * Découpé sur le mot, chaque morceau qui le précède perd les signes
     * ouvrants de la séquence de caractères de balise qui le termine. Avant le
     * plafond, le texte replié peut compter ~9 000 caractères : d'où un
     * {@see self::TAG_RUN} linéaire par construction.
     */
    private static function withoutTagOpenings(string $text): string
    {
        $parts = preg_split('/('.RoleBlockTag::WORD.')/iu', $text, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if (false === $parts) {
            throw RoleNeutralisationFailedException::fromPcre();
        }

        for ($index = 0, $last = \count($parts) - 1; $index < $last; $index += 2) {
            if (1 !== preg_match(self::TAG_RUN, $parts[$index], $run, \PREG_OFFSET_CAPTURE)) {
                throw RoleNeutralisationFailedException::fromPcre();
            }

            $start = $run[0][1];
            $parts[$index] = substr($parts[$index], 0, $start).self::replaced(self::OPENING, '', substr($parts[$index], $start));
        }

        return implode('', $parts);
    }

    /**
     * Après le retrait des signes ouvrants, pour qu'il ne laisse pas deux
     * espaces (« PHP < /role » → « PHP /role ») ; les blancs réduits à une
     * espace, `trim()` n'a plus qu'elle à retirer aux bords.
     */
    private static function onOneLine(string $text): string
    {
        return trim(self::replaced(self::BLANKS, ' ', $text), ' ');
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
