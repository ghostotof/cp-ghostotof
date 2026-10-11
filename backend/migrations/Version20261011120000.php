<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #434 — les horodatages gardent leur décalage, et chaque invitation son
 * numéro.
 *
 * Fuseau. Les cinq horodatages d'entité étaient des `TIMESTAMP(0) WITHOUT
 * TIME ZONE` : Doctrine y écrivait l'heure murale du fuseau de PHP
 * (`date.timezone = Europe/Paris`, php.dev.ini et php.prod.ini), sans
 * décalage. L'heure répétée du passage à l'heure d'hiver revenait une heure
 * plus tard, et un paramètre de requête comparé à une colonne avec fuseau
 * serait lu dans le fuseau de la session PostgreSQL (UTC). Chaque valeur
 * existante est réinterprétée comme heure de Paris — ce qu'elle a toujours
 * été — par `AT TIME ZONE 'Europe/Paris'`, écrit en dur : une migration est un
 * historique figé. Seule une valeur déjà saisie dans l'heure répétée reste
 * ambiguë ; PostgreSQL la lit comme la seconde occurrence, ce que faisait
 * déjà PHP à la relecture.
 *
 * Génération. `cpg_user.invitation_generation` numérote les invitations d'un
 * compte ; le message d'invitation porte la sienne, et le handler écarte un
 * message dépassé au lieu de purger le jeton d'une relance plus récente. Un
 * compte déjà invité part de 1, comme s'il venait d'être invité une fois ;
 * les messages déjà en file n'ont pas de génération et restent traités.
 *
 * Fuseau de session. Une heure sans décalage qui atteint encore la base est
 * lue dans le fuseau de la session PostgreSQL, UTC par défaut : celles de
 * l'ancien code, pendant le rollout ou indéfiniment après un retour arrière
 * (`rollout undo` ne rejoue pas `down()`), et celle d'un futur paramètre de
 * requête non typé. Deux heures d'écart : un lien valable 50 h, une purge des
 * invitations deux heures trop tôt, irréversible. La base règle donc ses
 * sessions sur le fuseau de PHP, qui produit ces heures — à l'heure répétée
 * près. Effet sur les sessions suivantes seulement : le Job de migration,
 * lui, convertit par des `AT TIME ZONE` explicites. Le rôle applicatif est
 * propriétaire de la base (image PostgreSQL officielle, POSTGRES_USER), ce
 * que demande `ALTER DATABASE`.
 *
 * Expand/contract (#175) : déploiement standard, migration avant le rollout,
 * sans fenêtre de maintenance. L'ancien code ignore la nouvelle colonne et
 * relit un `TIMESTAMP WITH TIME ZONE` sans erreur (DBAL retombe sur
 * `new DateTimeImmutable()`, qui comprend le décalage) ; ses écritures sont
 * lues en heure de Paris, ci-dessus. Les `ALTER … TYPE` réécrivent trois
 * petites tables sous verrou exclusif : `lock_timeout` borne l'attente à
 * 5 s, pour que le Job échoue vite plutôt que de mettre le login en file
 * derrière une transaction qui traîne.
 */
final class Version20261011120000 extends AbstractMigration
{
    /** @var array<string, list<string>> table => horodatages */
    private const array TIMESTAMPS = [
        'cpg_user' => ['invited_at', 'activated_at'],
        'password_setup_token' => ['expires_at', 'used_at'],
        'watch_snapshot' => ['refreshed_at'],
    ];

    public function getDescription(): string
    {
        return 'Horodatages avec fuseau et génération d\'invitation sur cpg_user (#434).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        $this->addSql('ALTER TABLE cpg_user ADD invitation_generation INT DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE cpg_user SET invitation_generation = 1 WHERE invited_at IS NOT NULL');

        foreach (self::TIMESTAMPS as $table => $columns) {
            foreach ($columns as $column) {
                $this->addSql(\sprintf(
                    "ALTER TABLE %s ALTER %s TYPE TIMESTAMP(0) WITH TIME ZONE USING %2\$s AT TIME ZONE 'Europe/Paris'",
                    $table,
                    $column,
                ));
            }
        }

        $this->addSql("DO $$ BEGIN EXECUTE format('ALTER DATABASE %I SET timezone TO %L', current_database(), 'Europe/Paris'); END $$");
    }

    /**
     * Réversible : l'instant est ramené à l'heure murale de Paris, ce que
     * lisait l'ancien code. Les générations sont perdues, sans effet sur
     * l'ancien code qui les ignore.
     */
    public function down(Schema $schema): void
    {
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        $this->addSql("DO $$ BEGIN EXECUTE format('ALTER DATABASE %I RESET timezone', current_database()); END $$");

        foreach (self::TIMESTAMPS as $table => $columns) {
            foreach ($columns as $column) {
                $this->addSql(\sprintf(
                    "ALTER TABLE %s ALTER %s TYPE TIMESTAMP(0) WITHOUT TIME ZONE USING %2\$s AT TIME ZONE 'Europe/Paris'",
                    $table,
                    $column,
                ));
            }
        }

        $this->addSql('ALTER TABLE cpg_user DROP invitation_generation');
    }
}
