<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Infrastructure\Doctrine;

use App\Portfolio\Shared\Domain\Exception\TranslationGroupAcrossSeveralScopesException;
use App\Portfolio\Shared\Domain\Exception\TranslationGroupHasSeveralPositionsException;
use Doctrine\DBAL\Exception\DriverException;

/**
 * Nomme les refus que le trigger différé `trg_translation_group_single_position`
 * oppose au COMMIT, d'après leur seul SQLSTATE (issue #506) : la seule
 * connaissance, dans l'application, de ces codes et de l'invariant que chacun
 * dit.
 *
 * Elle vivait dans {@see PostgresAdvisoryOrderScopeLock}, qui reste celui qui
 * **déclenche** la traduction : c'est lui qui possède le COMMIT où tombe un
 * refus différé, et le squelette des verrous l'appelle par son crochet
 * `onCommitRefused`. Mais un invariant des groupes de traduction n'a rien d'un
 * verrou : chaque nouvel invariant du trigger (#501 a ajouté le `23T91`)
 * modifiait l'adaptateur pour une raison étrangère à la prise du verrou, et
 * les tests du trigger lisaient ses SQLSTATE sur la classe du verrou.
 *
 * Seul le chemin applicatif passe par ici : toute écriture applicative
 * capable de réveiller le trigger est une écriture qui place, donc sous
 * `withLock()` (#389, `OrderScopeLockCoverageTest`). Une écriture hors verrou
 * reçoit le refus brut, dont le message nomme encore la table et le groupe
 * malgré la rédaction des erreurs du pilote : la `DriverException` elle-même
 * en SQL ou dans une migration, enveloppée par Doctrine dans une
 * {@see \Doctrine\ORM\OptimisticLockException} « Commit failed » pour un
 * `flush()` hors transaction — limite acceptée, épinglée par
 * `PostgresAdvisoryOrderScopeLockTest`. Écarté : un middleware
 * DBAL qui nommerait le refus partout, puisque `convert()` doit rendre une
 * `DriverException` (le nom de l'invariant n'en est pas une) ou donnerait deux
 * noms au même refus, et rendrait inutile le crochet du COMMIT.
 */
final class TranslationGroupRefusal
{
    /**
     * Un groupe sur plusieurs positions (migration Version20261012120000,
     * issue #390) : classe 23 (violation d'intégrité), sous-classe hors de
     * celles que PostgreSQL définit. Le reconnaître ne demande pas de lire le
     * message.
     */
    public const string ON_SEVERAL_POSITIONS = '23T90';

    /**
     * Un groupe rangé dans plusieurs périmètres d'ordre de sa table — deux
     * catégories de cartes « moi » (migration Version20261012160000, issue
     * #501). Contrôlé avant la position : un groupe à la fois réparti et sur
     * deux positions sort sous ce nom-ci, la cause, plutôt que sous la
     * conséquence.
     */
    public const string ACROSS_SEVERAL_SCOPES = '23T91';

    /**
     * L'exception qui nomme l'invariant refusé, avec le message de la base
     * (il nomme la table et le groupe) et le refus pour `previous` ; `null`
     * pour tout autre refus, que l'appelant relance tel quel.
     */
    public static function nameOf(DriverException $refusal): TranslationGroupHasSeveralPositionsException|TranslationGroupAcrossSeveralScopesException|null
    {
        return match ($refusal->getSQLState()) {
            self::ON_SEVERAL_POSITIONS => TranslationGroupHasSeveralPositionsException::refusedAtCommit($refusal),
            self::ACROSS_SEVERAL_SCOPES => TranslationGroupAcrossSeveralScopesException::refusedAtCommit($refusal),
            default => null,
        };
    }
}
