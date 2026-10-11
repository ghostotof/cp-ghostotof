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
 * Expand/contract (#175) : déploiement standard, migration avant le rollout,
 * sans fenêtre de maintenance. L'ancien code ignore la nouvelle colonne et
 * relit un `TIMESTAMP WITH TIME ZONE` sans erreur (DBAL retombe sur
 * `new DateTimeImmutable()`, qui comprend le décalage). Une écriture de
 * l'ancien code pendant le rollout — heure murale sans décalage — serait lue
 * dans le fuseau de la session, UTC : deux heures d'écart sur une invitation,
 * une activation, une expiration de lien ou un rafraîchissement de la veille
 * écrits dans cette fenêtre. Accepté : aucune de ces valeurs ne décide d'un
 * accès à la minute près (la purge compte en jours, le lien vit 48 h).
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
    }

    /**
     * Réversible : l'instant est ramené à l'heure murale de Paris, ce que
     * lisait l'ancien code. Les générations sont perdues, sans effet sur
     * l'ancien code qui les ignore.
     */
    public function down(Schema $schema): void
    {
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
