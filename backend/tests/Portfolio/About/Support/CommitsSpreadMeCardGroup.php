<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Support;

use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * Valide en base un groupe de cartes « moi » réparti sur deux catégories (FR
 * dans `HOBBY`, EN dans `TECHNICAL`, position 0), tel qu'il peut rester d'avant
 * la garde de #501 — l'état de départ de l'issue #561.
 *
 * Le trigger différé le refuserait au COMMIT. Une transaction annulée ne
 * suffit pas ici : l'`Administrator` refuse de tourner dans une transaction
 * déjà ouverte (#526), il faut donc que le groupe soit **validé**. Le trigger
 * est désactivé puis réactivé **dans la même transaction** : le DDL de
 * PostgreSQL est transactionnel, aucune autre session ne voit la table sans
 * son trigger, et les INSERT faits pendant ce temps ne laissent aucun
 * événement en attente — d'où aucun 55006 au `ENABLE`. Le `tearDown` du test
 * supprime les lignes : un `DELETE` ne réveille pas le trigger.
 */
trait CommitsSpreadMeCardGroup
{
    private const string SPREAD_GROUP_TRIGGER = 'trg_translation_group_single_position';

    /**
     * @return array{0: AboutMeCard, 1: AboutMeCard} le FR (`HOBBY`) puis l'EN (`TECHNICAL`), détachés de l'EntityManager
     */
    private static function commitSpreadGroup(EntityManagerInterface $entityManager): array
    {
        $french = new AboutMeCard(Locale::FR, AboutMeCardCategory::HOBBY, 'Musique', 'Description.', 'guitar', 0);
        $english = new AboutMeCard(Locale::EN, AboutMeCardCategory::TECHNICAL, 'Music', 'Description.', 'guitar', 0, $french->getTranslationGroup());

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            // L'ALTER prend un verrou ACCESS EXCLUSIVE : borné, il échoue vite
            // et nommé derrière une session oubliée, au lieu de figer la suite.
            $connection->executeStatement("SET LOCAL lock_timeout = '3s'");
            $connection->executeStatement(\sprintf('ALTER TABLE about_me_card DISABLE TRIGGER %s', self::SPREAD_GROUP_TRIGGER));
            $entityManager->persist($french);
            $entityManager->persist($english);
            $entityManager->flush();
            $connection->executeStatement(\sprintf('ALTER TABLE about_me_card ENABLE TRIGGER %s', self::SPREAD_GROUP_TRIGGER));
            $connection->commit();
        } catch (Throwable $failure) {
            // Ne pas laisser une transaction ouverte sur la connexion partagée :
            // les tests suivants la prendraient pour la leur. Le trigger, lui,
            // revient avec le ROLLBACK (DDL transactionnel).
            $connection->rollBack();

            throw $failure;
        }
        // Ce que l'opération décide, elle doit le lire en base, pas dans la
        // carte d'identité remplie par cette préparation.
        $entityManager->clear();

        return [$french, $english];
    }
}
