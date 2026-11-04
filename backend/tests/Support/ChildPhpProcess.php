<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\TextUI\Configuration\Registry;
use Symfony\Component\Process\Process;

/**
 * La ligne de commande d'un processus `php` lancé par un test (issue #420), et
 * l'environnement qu'il reçoit (issue #403).
 *
 * L'enfant ne reçoit pas les `<ini>` de `phpunit.dist.xml` : il part des
 * fichiers ini de la machine, qui dans le conteneur de dev laissent
 * `zend.exception_ignore_args` à Off (en CI, `php.ini-production` la pose
 * déjà, la transmission n'y change rien). Une exception non rattrapée y
 * écrirait sur la sortie d'erreur une trace avec les arguments des appels,
 * que le test recopie dans son message d'échec.
 * La commande transmet donc la valeur courante du parent, celle de PHPUnit,
 * ou celle qu'un `phpunit.xml` local a remise à 0 pour déboguer.
 */
final class ChildPhpProcess
{
    public const string DIRECTIVE = 'zend.exception_ignore_args';

    /**
     * Code de sortie d'un fils qui n'a pas pu se mettre en place (arguments,
     * services, barrière) : rien n'a été fait. Les fixtures des tests de
     * concurrence le rendent toutes, le test le distingue ainsi d'un échec de
     * l'opération elle-même.
     */
    public const int SETUP_FAILED = 2;

    /**
     * @return non-empty-list<string> `php -d zend.exception_ignore_args=<valeur du parent>` suivi des arguments
     */
    public static function command(string ...$arguments): array
    {
        // Directive inconnue (impossible depuis PHP 7.4) : le côté sûr.
        $value = \ini_get(self::DIRECTIVE);

        return [\PHP_BINARY, '-d', self::DIRECTIVE.'='.(false === $value ? '1' : $value), ...array_values($arguments)];
    }

    /**
     * Le processus `php` fils, prêt à démarrer, dans l'environnement exact du
     * test : sa ligne de commande ({@see self::command()}) et les variables du
     * test ({@see self::testEnvironment()}). C'est ainsi que les tests de
     * concurrence lancent leurs fils (#277, #364, #389, #435, #458).
     */
    public static function inTestEnvironment(int $timeoutSeconds, string ...$arguments): Process
    {
        return new Process(self::command(...$arguments), env: self::testEnvironment(), timeout: $timeoutSeconds);
    }

    /**
     * L'environnement du test, en deux couches. D'abord `$_SERVER` : les
     * variables que phpunit.dist.xml pose par `<server>` (APP_ENV, clés d'API
     * factices…) et celles que Dotenv a chargées. Une vraie ANTHROPIC_API_KEY
     * exportée dans le shell ne doit pas parvenir au fils à la place de la
     * valeur factice : Process ajoute ces variables à celles qu'il hérite
     * déjà, et elles l'emportent. Process hérite aussi, de lui-même, de
     * `$_ENV` : la copie de `$_SERVER` est ce qui ne dépend pas de ce détail,
     * et c'est elle qui fait passer une valeur que le test ne pose que dans
     * `$_SERVER`, ou qui y diffère de `$_ENV`.
     *
     * Puis, par-dessus, chaque `<env force="true">` de la configuration que
     * PHPUnit a chargée, avec la valeur qu'il a mise dans `$_ENV` (#748).
     * `<env force>` réécrit `$_ENV` et getenv(), jamais `$_SERVER` : quand
     * l'environnement du processus porte déjà la variable (le conteneur de dev
     * pose APP_PUBLIC_API_BASE_URL, #647), `$_SERVER` garde la valeur du
     * conteneur, que le noyau du parent ne lit pas (Symfony lit `$_ENV`
     * d'abord) ; sans cette couche, le fils servirait une autre base d'API que
     * le test, et chaque échange au point de jeton y répondrait 400
     * `invalid_target`. Les noms viennent de la configuration chargée (un
     * `phpunit.xml` local compris), non d'une liste : un `<env force>` ajouté
     * demain est transmis sans rien écrire ici. Une valeur que le test ne pose
     * que dans `$_SERVER` sous l'un de ces noms ne passe donc pas.
     *
     * Pour un `<server>` et un `<env force>`, le fils voit ainsi ce que voit le
     * noyau du parent. Un `<env>` sans `force` n'est pas recouvert : PHPUnit
     * retient alors dans `$_ENV` la valeur du processus s'il en porte une, et
     * la recouvrir ferait gagner le shell sur la copie de `$_SERVER` (C18-7 :
     * un `phpunit.xml` local qui poserait une clé par `<env>`). Le fils reçoit
     * pour lui la copie de `$_SERVER` et ce que Process hérite, qui peut
     * différer du `$_ENV` du parent : limite admise, qui ne touche dans
     * phpunit.dist.xml que MCP_D8_AUTHOR_NAME, que la CI exporte.
     *
     * La configuration se lit par {@see Registry}, `@internal` à PHPUnit : une
     * montée de version qui la renomme fait échouer à grand bruit chaque test
     * qui lance un fils (classe ou méthode introuvable), jamais en silence.
     *
     * Écrit ici seul (issue #403) : une variable à ne pas transmettre, par
     * exemple, s'exclurait ici pour tous les fils à la fois.
     *
     * @return array<string, string>
     */
    private static function testEnvironment(): array
    {
        $environment = [];
        foreach ($_SERVER as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $environment[$name] = $value;
            }
        }
        foreach (Registry::get()->php()->envVariables() as $variable) {
            $retained = $_ENV[$variable->name()] ?? null;
            if ($variable->force() && \is_string($retained)) {
                $environment[$variable->name()] = $retained;
            }
        }

        return $environment;
    }
}
