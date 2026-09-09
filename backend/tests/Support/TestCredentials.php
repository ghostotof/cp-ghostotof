<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Mots de passe des comptes jetables créés par les tests fonctionnels.
 *
 * Ils étaient auparavant redéclarés en constante dans **dix-neuf** fichiers de
 * test, sous quelques variantes. Deux inconvénients, l'un mineur et l'autre
 * agaçant :
 *
 *  - la même valeur à dix-neuf endroits, à faire évoluer ensemble le jour où
 *    une contrainte de mot de passe changerait ;
 *  - GitGuardian y voyait des secrets en dur, et se déclenchait à **chaque
 *    nouveau fichier de test de backoffice** — trois fois sur ce dépôt. Ces
 *    alertes étaient de vrais faux positifs, mais les trier à la main revient
 *    vite à ne plus les lire, ce qui est exactement ce qu'on ne veut pas d'un
 *    scanner de secrets.
 *
 * D'où la génération plutôt qu'un littéral partagé : centraliser la valeur
 * n'aurait déplacé le problème que d'un cran, le fichier restant. Ici il n'y a
 * plus aucune chaîne ressemblant à un mot de passe dans le dépôt.
 *
 * La valeur est tirée **une fois par processus** et mémorisée : un compte créé
 * dans un test et authentifié dans un autre voit bien le même mot de passe.
 *
 * Effet de bord heureux : une valeur aléatoire ne figure dans aucune fuite
 * connue, donc `#[Assert\NotCompromisedPassword]` la laisse passer par
 * construction — là où un littéral choisi à la main pouvait un jour se
 * retrouver dans une base de mots de passe compromis.
 */
final class TestCredentials
{
    /**
     * @var array<string, string> valeur mémorisée par étiquette
     */
    private static array $passwords = [];

    /** Le compte ROLE_SUPER des tests de backoffice. */
    public static function superPassword(): string
    {
        return self::variant('super');
    }

    /** Le compte ROLE_USER, celui qui doit se voir refuser le backoffice. */
    public static function plainPassword(): string
    {
        return self::variant('plain');
    }

    /**
     * Pour les tests qui ont besoin d'une seconde valeur distincte — un
     * changement de mot de passe, par exemple, qui oppose l'ancien au nouveau.
     * Deux étiquettes différentes donnent deux valeurs différentes.
     *
     * Nommée `variant` et non `password` : sous ce dernier nom, chaque appel
     * formait `password('…')`, que les détecteurs génériques lisent comme
     * l'affectation d'un mot de passe dont la valeur serait l'étiquette. Toute
     * cette classe existe pour supprimer ce motif du dépôt ; son API n'a pas à
     * le réintroduire.
     */
    public static function variant(string $label): string
    {
        return self::$passwords[$label] ??= self::generate();
    }

    /**
     * La génération est isolée dans sa propre méthode, et pas seulement par
     * goût : écrite en ligne, elle formait `$passwords[...] = '…'`, que les
     * détecteurs génériques lisent comme une affectation de mot de passe en
     * dur. La ligne dont le rôle est précisément d'en supprimer un se faisait
     * ainsi signaler à leur place.
     *
     * Majuscule, minuscules et chiffres, bien au-delà de
     * `CpgUser::MIN_PASSWORD_LENGTH` : la valeur satisfait toute règle de
     * complexité plausible sans que les tests aient à s'en préoccuper.
     */
    private static function generate(): string
    {
        return 'Tst'.bin2hex(random_bytes(10)).'9Z';
    }
}
