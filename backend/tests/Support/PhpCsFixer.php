<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * La correction ponctuelle que proposent les gardes de style quand elles
 * échouent (UnsortedImports, MisqualifiedNativeCalls, issue #394).
 *
 * php-cs-fixer n'est volontairement pas une dépendance (#391). Son phar se pose
 * sous `var/`, qui n'est pas versionné, à une version épinglée, et n'est exécuté
 * qu'après vérification de sa somme. Les deux recenseurs reproduisent les règles
 * de cette version : la changer, c'est aussi relire leurs algorithmes et
 * recopier `MisqualifiedNativeCalls::COMPILER_OPTIMIZED`.
 */
final class PhpCsFixer
{
    public const string VERSION = '3.95.27';

    /**
     * La release ne publie pas de somme, seulement une signature GPG. Celle-ci
     * a été calculée le 2026-10-10 sur un phar dont la signature (`.asc`) a été
     * vérifiée : clé BBAB 5DF0 A0D6 6729 89CF 1869 E82B 2FB3 14E9 906E, Dariusz
     * Ruminski, mainteneur de php-cs-fixer.
     */
    public const string SHA256 = '47231757eefdd08778e2bd047d98a63dfaad881006c8c1d1bc870d63be7f6a9b';

    private const string PHAR = 'var/php-cs-fixer.phar';

    /**
     * Les commandes à lancer depuis la racine du dépôt, enchaînées par `&&` :
     * rien n'est corrigé si la somme ne concorde pas. La première télécharge le
     * phar s'il manque ou ne correspond pas à la somme, puis le vérifie. Les
     * deux suivantes corrigent `src/`, puis `tests/` : php-cs-fixer n'accepte
     * plusieurs chemins qu'avec un fichier de configuration.
     *
     * @param string $rules la valeur de `--rules`, un nom de règle ou du JSON
     */
    public static function command(string $rules, bool $risky = false): string
    {
        // Des guillemets doubles suffisent : la somme et le chemin sont des constantes sans
        // caractère spécial. `sha256sum` est celui de BusyBox : `-s` et non `--status`.
        $sum = 'echo "'.self::SHA256.'  '.self::PHAR.'" | sha256sum -c';
        $download = 'curl -fsSL -o '.self::PHAR
            .' https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/releases/download/v'.self::VERSION.'/php-cs-fixer.phar';
        $fix = 'docker compose exec -u dev backend php '.self::PHAR.' fix --using-cache=no'
            .($risky ? ' --allow-risky=yes' : '').' --rules='.escapeshellarg($rules);

        return 'docker compose exec -u dev backend sh -c '
            .escapeshellarg($sum.' -s 2>/dev/null || '.$download.'; '.$sum)." &&\n"
            .$fix." src &&\n"
            .$fix.' tests';
    }
}
