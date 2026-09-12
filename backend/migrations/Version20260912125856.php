<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR 0003 (D2/D4) : ROLE_USER redevient le palier de base (authentifié,
 * sans plus) ; l'accès au CV et à /api/me exige désormais ROLE_TRUSTED.
 * Sans cette migration, tout compte existant qui n'avait que l'ancien
 * ROLE_USER implicite perdrait l'accès en silence — exactement le risque
 * documenté dans les conséquences de l'ADR.
 *
 * ROLE_SUPER n'a pas besoin d'être touché : il hérite déjà de ROLE_TRUSTED
 * via la role_hierarchy de security.yaml (ROLE_SUPER: [ROLE_TRUSTED]).
 *
 * Colonne `roles` en `json` simple (pas `jsonb`, cf. Version20260808205258) :
 * les opérateurs de containment/concaténation exigent un cast explicite.
 */
final class Version20260912125856 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR 0003 : accorde ROLE_TRUSTED aux comptes existants qui n\'ont pas déjà ROLE_SUPER.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE cpg_user
            SET roles = (roles::jsonb || '["ROLE_TRUSTED"]'::jsonb)::json
            WHERE NOT (roles::jsonb @> '["ROLE_SUPER"]'::jsonb)
              AND NOT (roles::jsonb @> '["ROLE_TRUSTED"]'::jsonb)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE cpg_user
            SET roles = (roles::jsonb - 'ROLE_TRUSTED')::json
            WHERE roles::jsonb @> '["ROLE_TRUSTED"]'::jsonb
              AND NOT (roles::jsonb @> '["ROLE_SUPER"]'::jsonb)
            SQL);
    }
}
