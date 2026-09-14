<?php

declare(strict_types=1);

namespace App\Portfolio\Contribution\Application;

use App\Portfolio\Contribution\Domain\Entity\Contribution;
use App\Portfolio\Contribution\Domain\Exception\ContributionNotFoundException;
use App\Portfolio\Shared\Domain\Exception\IncompleteOrderException;
use App\Portfolio\Shared\Domain\Exception\TranslationAlreadyExistsException;
use App\Portfolio\Shared\Domain\Exception\UnknownOrderEntryException;
use App\Portfolio\Shared\Domain\Exception\UnknownTranslationGroupException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

interface ContributionAdministratorInterface
{
    /**
     * `$translationGroup` (spec 0004 D1) : groupe d'une entrée existante quand
     * on crée sa version dans une autre langue, `null` pour un contenu neuf —
     * l'entité s'en forge alors un.
     *
     * La position n'est pas un paramètre (spec 0004 D3) : elle se déduit du
     * groupe, ou de la fin du périmètre. Seul l'endpoint d'ordre l'écrira.
     *
     * @throws UnknownTranslationGroupException  groupe inconnu du périmètre
     * @throws TranslationAlreadyExistsException le groupe porte déjà cette langue
     */
    public function create(
        Locale $locale,
        string $title,
        string $project,
        string $reference,
        string $url,
        string $summary,
        string $body,
        ?Uuid $translationGroup = null,
    ): Contribution;

    /**
     * `$translationGroup` porte la sémantique du `PUT` (spec 0004 D3) : `null`
     * détache l'entrée de ses traductions en conservant sa position, un autre
     * groupe l'y rattache et lui en fait hériter la position, le sien ne fait
     * rien.
     *
     * @throws ContributionNotFoundException si l'id est inconnu
     * @throws UnknownTranslationGroupException groupe inconnu du périmètre
     * @throws TranslationAlreadyExistsException le groupe porte déjà cette langue
     */
    public function update(
        Uuid $id,
        string $title,
        string $project,
        string $reference,
        string $url,
        string $summary,
        string $body,
        ?Uuid $translationGroup,
    ): Contribution;

    /**
     * @throws ContributionNotFoundException si l'id est inconnu
     */
    public function delete(Uuid $id): void;

    /**
     * Spec 0004 D4/D5 : réordonne tout le périmètre (toutes langues
     * confondues) — `$keys` doit être exactement l'ensemble des groupes de traduction
     * existants, une occurrence chacun.
     *
     * @param list<string> $keys groupes de traduction, en RFC 4122
     *
     * @throws UnknownOrderEntryException une clé n'est pas dans le périmètre, ou y apparaît plus
     *                                    d'une fois
     * @throws IncompleteOrderException   une clé du périmètre est absente de `$keys`
     */
    public function reorder(array $keys): void;
}
