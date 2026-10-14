<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Le SQL de `k8s/base/postgres-roles/verify.sql`, lu par les tests de forme
 * ({@see \App\Tests\Shared\Infrastructure\Kubernetes\PostgresRolesProcedureTest})
 * et rejoué sur la base de test
 * ({@see \App\Tests\Shared\Infrastructure\Postgres\DatabaseOwnerSettingsVerificationTest}).
 */
final class RolesVerification
{
    private const string VERIFY_SQL = 'postgres-roles/verify.sql';

    /**
     * Le bloc `DO $$ … $$;` qui porte les refus d'une issue (`#608`), seul :
     * une assertion sur le fichier entier serait satisfaite par la requête
     * d'affichage qui le précède, même si le refus avait disparu.
     */
    public static function blockOf(string $issue): string
    {
        $statements = (string) preg_replace('/^\s*--.*$/m', '', KubernetesManifests::base()->source(self::VERIFY_SQL));
        preg_match_all('/^DO \$\$$.*?^\$\$;$/ms', $statements, $blocks);
        $mine = array_values(array_filter($blocks[0], static fn (string $block): bool => str_contains($block, "RAISE EXCEPTION '".$issue.' :')));
        Assert::assertCount(1, $mine, \sprintf('verify.sql : un et un seul bloc DO porte les refus %s.', $issue));

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
     * Les empreintes que le bloc `#611` admet, par dernière migration passée
     * (`''` : base neuve, avant toute migration).
     *
     * @return array<string, string>
     */
    public static function schemaFingerprints(): array
    {
        preg_match_all("/\\('((?:DoctrineMigrations\\\\Version\\d{14})?)', '([0-9a-f]{32})'\\)/", self::blockOf('#611'), $rows, \PREG_SET_ORDER);
        Assert::assertNotEmpty($rows, 'verify.sql : le bloc #611 porte ses empreintes.');

        return array_column($rows, 2, 1);
    }
}
