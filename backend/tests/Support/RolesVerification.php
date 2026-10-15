<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;

/**
 * Le SQL de `k8s/base/postgres-roles/verify.sql` — et le bloc de réparation de
 * `roles.sql` (issue #610), sa lecture des migrations, son garde du chemin de
 * recherche et sa remise des réglages hérités (#618) —, plus les options de
 * connexion de la procédure, lues dans le runbook (`k8s/README.md`, l'unique
 * `exec` de sa section), lu par les tests de forme
 * ({@see \App\Tests\Shared\Infrastructure\Kubernetes\PostgresRolesProcedureTest})
 * et rejoué sur la base de test
 * ({@see \App\Tests\Shared\Infrastructure\Postgres\DatabaseOwnerSettingsVerificationTest}).
 */
final class RolesVerification
{
    private const string VERIFY_SQL = 'postgres-roles/verify.sql';

    private const string ROLES_SQL = 'postgres-roles/roles.sql';

    /** Le refus qui reconnaît le garde du chemin de recherche (issue #633). */
    private const string SEARCH_PATH_GUARD_MARK = "RAISE EXCEPTION '#633 : le chemin de recherche";

    /**
     * Le bloc `DO $$ … $$;` qui porte les refus d'une issue (`#608`), seul :
     * une assertion sur le fichier entier serait satisfaite par la requête
     * d'affichage qui le précède, même si le refus avait disparu.
     */
    public static function blockOf(string $issue): string
    {
        return self::blockCarrying(self::VERIFY_SQL, "RAISE EXCEPTION '".$issue.' :', 'les refus '.$issue);
    }

    /**
     * Le bloc `DO $$ … $$;` de roles.sql qui remet la base de l'application en
     * l'état que le bloc `#608` de verify.sql attend (issue #610), reconnu à
     * ses avis `#610`.
     */
    public static function repairBlock(): string
    {
        return self::blockCarrying(self::ROLES_SQL, "RAISE NOTICE '#610 :", 'la réparation #610');
    }

    /**
     * Le bloc de roles.sql qui lit la table des migrations sous le rôle de
     * migration (revue de #610) et range ce qu'il lit dans des réglages de
     * transaction, reconnu à la fonction de lecture qu'il crée.
     */
    public static function migrationsReading(): string
    {
        return self::blockCarrying(self::ROLES_SQL, 'CREATE FUNCTION pg_temp.cpg_migrations(', 'la lecture des migrations');
    }

    /**
     * Le garde du chemin de recherche (issue #633) de roles.sql, juste après
     * la lecture des migrations : il refuse une session dont le chemin n'est
     * pas exactement celui des options de la procédure.
     */
    public static function searchPathGuardOfRoles(): string
    {
        return self::blockCarrying(self::ROLES_SQL, self::SEARCH_PATH_GUARD_MARK, 'le garde du chemin de recherche');
    }

    /** Le même garde, en tête de verify.sql. */
    public static function searchPathGuardOfVerify(): string
    {
        return self::blockCarrying(self::VERIFY_SQL, self::SEARCH_PATH_GUARD_MARK, 'le garde du chemin de recherche');
    }

    /**
     * Ce que roles.sql joue en tête pour rendre à la session de la procédure
     * les valeurs de démarrage des réglages qu'elle hérite de la base ou d'un
     * rôle (issue #618), pris entre ses marqueurs, dans le fichier brut.
     */
    public static function inheritedSettingsReset(): string
    {
        $source = KubernetesManifests::base()->source(self::ROLES_SQL);
        Assert::assertSame(1, preg_match('/^-- #618 : réglages hérités, début\n(.*?)^-- #618 : réglages hérités, fin$/ms', $source, $reset), 'roles.sql : la remise des réglages hérités, entre ses marqueurs.');

        return trim($reset[1]);
    }

    /**
     * Les options de connexion de la procédure (`PGOPTIONS` de son unique
     * `exec`, issue #608), telles que le runbook les donne.
     */
    public static function sessionOptions(): string
    {
        $execs = Runbook::execLines(Runbook::repository()->bashBlockAfter(Runbook::ROLES_PROCEDURE));
        Assert::assertCount(1, $execs, 'La procédure des rôles tient en un seul exec.');
        Assert::assertSame(1, preg_match('/PGOPTIONS="([^"]*)" psql /', $execs[0], $options), 'PGOPTIONS entre guillemets doubles, juste avant psql.');

        return $options[1];
    }

    /** Le seul bloc `DO $$ … $$;` multiligne du fichier qui contient `$mark`, sans les commentaires de ligne entière. */
    private static function blockCarrying(string $file, string $mark, string $what): string
    {
        $statements = (string) preg_replace('/^\s*--.*$/m', '', KubernetesManifests::base()->source($file));
        preg_match_all('/^DO \$\$$.*?^\$\$;$/ms', $statements, $blocks);
        $mine = array_values(array_filter($blocks[0], static fn (string $block): bool => str_contains($block, $mark)));
        Assert::assertCount(1, $mine, \sprintf('%s : un et un seul bloc DO porte %s.', basename($file), $what));

        return $mine[0];
    }

    /**
     * La requête des lignes du schéma du bloc `#611` (issue #611), une ligne
     * canonique par objet : prise entre ses deux marqueurs, dans le fichier
     * brut, pour être rejouée et comparée à sa copie du runbook.
     */
    public static function schemaLinesQuery(): string
    {
        $source = KubernetesManifests::base()->source(self::VERIFY_SQL);
        Assert::assertSame(1, preg_match('/^\s*-- #611 : lignes du schéma, début\n(.*?)^\s*-- #611 : lignes du schéma, fin$/ms', $source, $query), 'verify.sql : la requête des lignes du schéma, entre ses marqueurs.');

        return trim($query[1]);
    }

    /**
     * La requête d'empreinte du bloc `#611` entière — l'agrégat et ses
     * lignes —, prise entre ses marqueurs : le test la rejoue telle que le
     * bloc la calcule, sans recopier son enveloppe.
     */
    public static function schemaFingerprintQuery(): string
    {
        $source = KubernetesManifests::base()->source(self::VERIFY_SQL);
        Assert::assertSame(1, preg_match('/^\s*-- #611 : empreinte, début\n(.*?)^\s*-- #611 : empreinte, fin$/ms', $source, $query), 'verify.sql : la requête d\'empreinte, entre ses marqueurs.');

        return trim($query[1]);
    }

    /**
     * Les réglages qui changent l'impression d'une définition, que le bloc
     * `#611` fixe lui-même avant de calculer l'empreinte (issue #634) — nom =>
     * valeur —, pris entre leurs marqueurs, dans le fichier brut.
     *
     * @return array<string, string>
     */
    public static function schemaPrintSettings(): array
    {
        $source = KubernetesManifests::base()->source(self::VERIFY_SQL);
        Assert::assertSame(1, preg_match('/^\s*-- #611 : réglages d\'impression, début\n(.*?)^\s*-- #611 : réglages d\'impression, fin$/ms', $source, $region), 'verify.sql : les réglages d\'impression, entre leurs marqueurs.');
        preg_match_all("/set_config\\('([A-Za-z_]+)', '([^']*)', true\\)/", $region[1], $settings, \PREG_SET_ORDER);
        Assert::assertNotEmpty($settings, 'verify.sql : le bloc #611 fixe ses réglages d\'impression.');

        return array_column($settings, 2, 1);
    }

    /**
     * L'empreinte du schéma que le bloc `#611` calcule : sa requête, sous ses
     * réglages d'impression ({@see self::schemaPrintSettings()}), posés dans
     * un point de sauvegarde annulé ensuite — la session appelante, qui doit
     * avoir une transaction ouverte, n'en garde rien.
     */
    public static function schemaFingerprint(Connection $connection): string
    {
        $connection->executeStatement('SAVEPOINT cpg_empreinte_634');
        try {
            foreach (self::schemaPrintSettings() as $name => $value) {
                $connection->executeQuery('SELECT pg_catalog.set_config(?, ?, true)', [$name, $value]);
            }
            $fingerprint = $connection->fetchOne(self::schemaFingerprintQuery());
        } finally {
            $connection->executeStatement('ROLLBACK TO SAVEPOINT cpg_empreinte_634');
            $connection->executeStatement('RELEASE SAVEPOINT cpg_empreinte_634');
        }
        Assert::assertIsString($fingerprint);

        return $fingerprint;
    }

    /**
     * Les empreintes que le bloc `#611` admet, par migrations passées — la
     * dernière (`''` : aucune) et leur nombre, `dernière|nombre` — : une ou
     * plusieurs chacune (une montée de PostgreSQL qui change l'impression
     * d'une définition en admet deux le temps de la transition).
     *
     * @return array<string, list<string>>
     */
    public static function schemaFingerprints(): array
    {
        preg_match_all("/\\('((?:DoctrineMigrations\\\\Version\\d{14})?)', (\\d+), '([0-9a-f]{32})'\\)/", self::blockOf('#611'), $rows, \PREG_SET_ORDER);
        Assert::assertNotEmpty($rows, 'verify.sql : le bloc #611 porte ses empreintes.');

        $fingerprints = [];
        foreach ($rows as [, $latest, $count, $fingerprint]) {
            $fingerprints[$latest.'|'.$count][] = $fingerprint;
        }

        return $fingerprints;
    }
}
