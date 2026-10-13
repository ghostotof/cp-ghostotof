<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Domain\Exception;

use LogicException;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Spec 0004 D3 : toutes les entrées d'un groupe de traduction partagent sa
 * position. ContentPlacement et OrderAssigner l'établissent (une version
 * hérite de la position de son groupe, un réordonnancement écrit la même sur
 * tous ses membres), la base le refuse au COMMIT depuis l'issue #390. Un
 * groupe dont les membres divergent est un défaut du pipeline, jamais une
 * saisie (issue #338).
 *
 * Trois origines : `ContentPlacement::inGroup()` (`forGroup()`) et
 * `OrderAssigner::assign()` (`forOrderingKey()`, issue #503) le trouvent avant
 * toute écriture, dans un groupe que le trigger n'a pas vu passer (écriture
 * triggers désactivés, SQL concurrent hors du verrou de périmètre…) ; la base
 * le refuse au COMMIT pour ce qui contourne l'application (`refusedAtCommit()`).
 *
 * Elle sort donc en 500 `critical` : ni `ProblemExceptionInterface`, ni
 * entrée `exception_to_status`, et elle étend {@see LogicException} plutôt que
 * {@see \DomainException}, que les erreurs clientes de ce dossier étendent. Le
 * message donne le groupe, ce qu'il faut retrouver en base.
 */
final class TranslationGroupHasSeveralPositionsException extends LogicException
{
    public static function forGroup(Uuid $translationGroup): self
    {
        return new self(\sprintf('Le groupe de traduction %s porte plusieurs positions.', $translationGroup->toRfc4122()));
    }

    /**
     * Issue #503 : un réordonnancement a trouvé, avant d'écrire, une clé
     * d'ordre — le groupe de traduction d'un contenu localisé — dont les
     * entités portent déjà plusieurs positions. Les réaligner effacerait la
     * preuve du défaut amont : le service refuse, et le message donne le
     * groupe, les positions trouvées et la réparation. Rien que le client ait
     * saisi : la clé est lue sur les entités du périmètre, pas dans la requête.
     *
     * @param list<int> $positions les positions distinctes trouvées, croissantes
     */
    public static function forOrderingKey(string $orderingKey, array $positions): self
    {
        return new self(\sprintf(
            'Le groupe de traduction %s porte plusieurs positions (%s) : réordonnancement refusé pour garder la preuve. Le réparer d\'abord : détacher l\'entrée égarée puis la rattacher au groupe (elle en reprend la position), ou corriger en SQL.',
            $orderingKey,
            implode(', ', $positions),
        ));
    }

    /**
     * La base a refusé au COMMIT une transaction qui laissait un groupe sur
     * plusieurs positions (issue #390). Son message, repris tel quel, nomme la
     * table et le groupe ; il ne contient rien que le client ait saisi.
     */
    public static function refusedAtCommit(Throwable $databaseRefusal): self
    {
        return new self(\sprintf('La base a refusé la transaction : %s', $databaseRefusal->getMessage()), 0, $databaseRefusal);
    }
}
