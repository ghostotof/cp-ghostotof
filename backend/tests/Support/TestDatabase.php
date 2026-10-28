<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;

/**
 * La garde d'un test qui vide une table (#650, F-D de la seconde relecture) :
 * `TRUNCATE`, ou un `DELETE FROM <table>` sans `WHERE`. À appeler **avant** la
 * première de ces instructions, sur la connexion qui va l'exécuter.
 *
 * `doctrine.yaml` suffixe en environnement `test` le nom de la base par `_test`
 * et par le `TEST_TOKEN` de ParaTest s'il existe (`_test` + jeton) : la base
 * courante — lue au serveur (`current_database()`), pas déduite d'une variable
 * d'environnement que la connexion n'aurait pas suivie — doit finir exactement
 * par ce suffixe. Sinon le test échoue, en nommant la base, **sans rien avoir vidé**.
 * La suite se protège déjà par ce suffixe ; la garde ferme le cas où la connexion
 * a été ouverte autrement (paramètres recopiés, base d'un autre test, `APP_ENV`
 * oublié) : il ne coûte qu'une requête.
 */
final class TestDatabase
{
    /** Le suffixe de nom des bases de test, `doctrine.yaml` (`dbname_suffix`) sans son jeton. */
    private const string SUFFIX = '_test';

    /**
     * Le nom d'une base de test : le suffixe, puis le jeton de ParaTest (vide hors
     * ParaTest), **à la fin**, et quelque chose avant — `_test` seul n'est pas un
     * nom de base dont on ait dérivé le suffixe.
     */
    public static function isTestDatabase(string $name, string $token): bool
    {
        $suffix = self::SUFFIX.$token;

        return \strlen($name) > \strlen($suffix) && str_ends_with($name, $suffix);
    }

    public static function assertSafeToWipe(Connection $connection): void
    {
        $name = $connection->fetchOne('SELECT current_database()');
        Assert::assertIsString($name, 'La base courante est illisible : rien n\'est vidé.');

        Assert::assertTrue(
            self::isTestDatabase($name, self::token()),
            \sprintf('La base courante « %s » n\'est pas une base de test (suffixe attendu : « %s ») : rien n\'a été vidé.', $name, self::SUFFIX.self::token()),
        );
    }

    /** Le `TEST_TOKEN` que `doctrine.yaml` lit (`%env(default::TEST_TOKEN)%`) : vide s'il n'est pas posé. */
    private static function token(): string
    {
        $token = $_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? '';

        return \is_string($token) ? $token : '';
    }
}
