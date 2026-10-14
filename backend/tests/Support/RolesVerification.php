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
}
