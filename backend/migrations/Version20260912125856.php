<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR 0003 (D2/D4) : ROLE_USER redevient le palier de base (authentifié,
 * sans plus) ; l'accès au CV et à /api/me exige désormais ROLE_TRUSTED.
 *
 * Décision prise le 2026-09-12 : cette migration n'attribue **aucun**
 * ROLE_TRUSTED. Un premier jet balayait automatiquement tout compte sans
 * ROLE_SUPER — erreur repérée avant tout déploiement : parmi les deux
 * comptes connus à cette date, l'un (`demo`) est précisément le compte
 * générique/partagé que l'ADR vise à cantonner au palier de base (contenu
 * « flou », jamais identifiant) — le promouvoir aurait reconstitué
 * exactement le risque que l'ADR corrige (fuite d'identifiants = accès au
 * vrai CV). ROLE_TRUSTED se veut « accordé nominativement » (D1) : une
 * décision individuelle par ROLE_SUPER, jamais un balayage automatique.
 *
 * ROLE_SUPER n'a besoin d'aucune action : il hérite déjà de ROLE_TRUSTED
 * via la role_hierarchy de security.yaml (ROLE_SUPER: [ROLE_TRUSTED]).
 * Aucun autre compte connu à cette date ne nécessitait de préservation.
 *
 * Si un compte réel doit un jour recevoir ROLE_TRUSTED, ce sera un octroi
 * explicite (nominatif), pas une migration de masse.
 */
final class Version20260912125856 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR 0003 : aucun octroi automatique de ROLE_TRUSTED (voir docblock — décision du 2026-09-12).';
    }

    public function up(Schema $schema): void
    {
        // Intentionnellement vide — voir le docblock de la classe.
    }

    public function down(Schema $schema): void
    {
        // Intentionnellement vide, symétrique de up().
    }
}
