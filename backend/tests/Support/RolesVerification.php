<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Le SQL de `k8s/base/postgres-roles/verify.sql` — et le bloc de réparation de
 * `roles.sql` (issue #610) —, lu par les tests de forme
 * ({@see \App\Tests\Shared\Infrastructure\Kubernetes\PostgresRolesProcedureTest})
 * et rejoué sur la base de test
 * ({@see \App\Tests\Shared\Infrastructure\Postgres\DatabaseOwnerSettingsVerificationTest}).
 */
final class RolesVerification
{
    private const string VERIFY_SQL = 'postgres-roles/verify.sql';

    private const string ROLES_SQL = 'postgres-roles/roles.sql';

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
