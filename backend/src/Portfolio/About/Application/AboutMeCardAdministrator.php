<?php

declare(strict_types=1);

namespace App\Portfolio\About\Application;

use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\Exception\AboutMeCardCategoryIsImmutableException;
use App\Portfolio\About\Domain\Exception\AboutMeCardNotFoundException;
use App\Portfolio\About\Domain\Repository\AboutMeCardRepositoryInterface;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Application\OrderScopeLockInterface;
use App\Portfolio\Shared\Domain\Exception\TranslatableContentLocaleIsImmutableException;
use App\Portfolio\Shared\Domain\Exception\TranslationGroupAcrossSeveralScopesException;
use App\Portfolio\Shared\Domain\Service\ContentPlacement;
use App\Portfolio\Shared\Domain\Service\OrderAssigner;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

final readonly class AboutMeCardAdministrator implements AboutMeCardAdministratorInterface
{
    /**
     * Verrou d'ordre, pris par toute écriture : celles qui placent une entrée
     * (issue #389), et delete(), pour qu'une suppression ne valide pas sous une
     * autre écriture (issue #499). La table entière, plus large que le
     * périmètre d'ordre (une catégorie) : un verrou par catégorie n'aurait rien
     * à gagner avec un seul administrateur.
     */
    private const string ORDER_SCOPE = AboutMeCard::class;

    public function __construct(
        private AboutMeCardRepositoryInterface $aboutMeCardRepository,
        private ContentPlacement $contentPlacement,
        private OrderAssigner $orderAssigner,
        private OrderScopeLockInterface $orderScopeLock,
    ) {
    }

    public function create(
        Locale $locale,
        AboutMeCardCategory $category,
        string $title,
        string $description,
        ?string $iconKey,
        ?Uuid $translationGroup = null,
    ): AboutMeCard {
        return $this->orderScopeLock->withLock(self::ORDER_SCOPE, function () use ($locale, $category, $title, $description, $iconKey, $translationGroup): AboutMeCard {
            $card = new AboutMeCard($locale, $category, $title, $description, $iconKey, $this->positionFor($locale, $category, $translationGroup), $translationGroup);

            $this->aboutMeCardRepository->save($card);

            return $card;
        });
    }

    public function update(Uuid $id, Locale $locale, AboutMeCardCategory $category, string $title, string $description, ?string $iconKey, ?Uuid $translationGroup): AboutMeCard
    {
        return $this->orderScopeLock->withLock(self::ORDER_SCOPE, function () use ($id, $locale, $category, $title, $description, $iconKey, $translationGroup): AboutMeCard {
            $card = $this->aboutMeCardRepository->findOneById($id);

            if (null === $card) {
                throw AboutMeCardNotFoundException::forId($id);
            }

            // Issue #582 : une entrée ne change pas de langue. Refusé avant
            // toute autre lecture et tout changement en mémoire (#549), donc
            // avant le détachement ou le rattachement qu'il demanderait — et
            // avant la catégorie (#578) : la règle commune aux huit contextes
            // d'abord, celle des cartes ensuite ; les deux ne lisent que le corps.
            if ($card->getLocale() !== $locale) {
                throw TranslatableContentLocaleIsImmutableException::refused();
            }

            // Issue #578 : refusé avant toute autre lecture et tout changement
            // en mémoire (#549), donc avant le détachement que demanderait
            // `translationGroup: null`. Il passe aussi avant le refus d'un
            // groupe réparti (#561) : il ne dépend que du corps, et ce groupe
            // reste refusé sous son nom à la requête valide suivante.
            if ($card->getCategory() !== $category) {
                throw AboutMeCardCategoryIsImmutableException::refused();
            }

            if (null === $translationGroup) {
                // Issue #169 : détacher envoie l'entrée en fin de périmètre, d'où
                // le chargement du périmètre — le même qu'à la création sans groupe.
                // Issue #561 : le groupe est lu toutes catégories confondues. Sur
                // un membre d'un groupe réparti (antérieur à #501), « Détacher »
                // détache donc réellement, et répare le groupe, au lieu du
                // non-geste d'une entrée qui se croyait seule dans sa catégorie.
                $this->contentPlacement->detach(
                    $card,
                    $this->aboutMeCardRepository->findByTranslationGroup($card->getTranslationGroup()),
                    $this->aboutMeCardRepository->findByCategory($card->getCategory()),
                );
            } elseif (!$translationGroup->equals($card->getTranslationGroup())) {
                // Son propre groupe est un non-geste (`reattach()`), testé ici
                // pour ne rien relire : éditer le texte d'un membre d'un groupe
                // réparti renvoie ce groupe, et ne doit pas tomber sur le refus
                // de #561 — c'est l'une des cartes qu'il demande de corriger.
                $this->contentPlacement->reattach(
                    $card,
                    $translationGroup,
                    $this->membersInCategory($translationGroup, $card->getCategory()),
                );
            }
            $card->update($title, $description, $iconKey);
            $this->aboutMeCardRepository->save($card);

            return $card;
        });
    }

    public function delete(Uuid $id): void
    {
        $this->orderScopeLock->withLock(self::ORDER_SCOPE, function () use ($id): void {
            $card = $this->aboutMeCardRepository->findOneById($id);

            if (null === $card) {
                throw AboutMeCardNotFoundException::forId($id);
            }

            $this->aboutMeCardRepository->remove($card);
        });
    }

    /**
     * Spec 0004 D4/D5 : le périmètre chargé est la catégorie, pas la table
     * entière — `findByCategory()` — si bien que les deux autres catégories ne
     * sont pas écrites par cet appel ; elles ne sont lues que pour leurs
     * membres égarés (issue #561), vides sur une base saine.
     *
     * Ordre des gardes, toutes avant la moindre écriture : un groupe réparti
     * sur deux catégories (ici), puis un groupe sur plusieurs positions et la
     * règle d'ensemble exact d'`OrderAssigner` (500 puis 422, issue #503),
     * puis le trigger au COMMIT pour ce qui contourne
     * l'application — la cause avant sa conséquence, comme le trigger
     * lui-même, et un défaut du pipeline avant une erreur du client.
     */
    public function reorder(AboutMeCardCategory $category, array $keys): void
    {
        $this->orderScopeLock->withLock(self::ORDER_SCOPE, function () use ($category, $keys): void {
            $this->refuseGroupsSpreadOutsideOf($category);

            $scope = $this->aboutMeCardRepository->findByCategory($category);

            $this->orderAssigner->assign($scope, $keys);

            $this->aboutMeCardRepository->saveAll($scope);
        });
    }

    /**
     * Spec 0004 D3 : le périmètre d'ordre d'une carte « moi » est sa
     * **catégorie**, toutes langues confondues — c'est ce que servent les trois
     * tableaux de la page À propos, et donc ce que numérotent leurs positions.
     */
    private function positionFor(Locale $locale, AboutMeCardCategory $category, ?Uuid $translationGroup): int
    {
        if (null === $translationGroup) {
            return $this->contentPlacement->atEndOf($this->aboutMeCardRepository->findByCategory($category));
        }

        return $this->contentPlacement->inGroup($translationGroup, $locale, $this->membersInCategory($translationGroup, $category));
    }

    /**
     * Les entrées du groupe **dans cette catégorie**. Un groupe ne peut pas
     * chevaucher deux catégories : une même position y vaudrait dans deux
     * tableaux différents, et déplacer l'un déplacerait l'autre. Un groupe
     * entièrement dans une autre catégorie est donc filtré ici, et ressort
     * comme inconnu du périmètre — 422, `unknown-translation-group`.
     *
     * Un groupe qui a des membres **ici et ailleurs** (antérieur à la garde de
     * #501) est refusé sous son nom, avant toute écriture (issue #561) : filtré,
     * il laissait `inGroup()` proposer une langue que le groupe porte déjà dans
     * l'autre catégorie, et l'écriture finissait sur l'index unique
     * `(translation_group, locale)`, un 500 brut qui fermait l'EntityManager.
     * La lecture est la même qu'avant, toutes catégories confondues : aucune
     * requête de plus.
     *
     * @return list<AboutMeCard>
     *
     * @throws TranslationGroupAcrossSeveralScopesException
     */
    private function membersInCategory(Uuid $translationGroup, AboutMeCardCategory $category): array
    {
        $members = $this->aboutMeCardRepository->findByTranslationGroup($translationGroup);
        $inCategory = array_values(array_filter($members, static fn (AboutMeCard $card): bool => $card->getCategory() === $category));

        if ([] !== $inCategory && \count($inCategory) !== \count($members)) {
            throw TranslationGroupAcrossSeveralScopesException::forGroup($translationGroup, $this->categoriesOf($members));
        }

        return $inCategory;
    }

    /**
     * Issue #561 : une catégorie qui porte un groupe réparti ne se réordonne
     * pas. Le trigger ne la refusait qu'au COMMIT, après l'écriture, et
     * seulement quand le membre égaré changeait de position ; le refus tombe
     * ici avant tout, quel que soit l'ordre demandé — il ne dépend que de
     * l'état. La correction : supprimer l'entrée égarée et recréer sa version
     * dans la bonne catégorie, ou la détacher.
     *
     * @throws TranslationGroupAcrossSeveralScopesException
     */
    private function refuseGroupsSpreadOutsideOf(AboutMeCardCategory $category): void
    {
        $strays = $this->aboutMeCardRepository->findInOtherCategoriesSharingAGroupWith($category);

        if ([] === $strays) {
            return;
        }

        // Le premier groupe réparti suffit à refuser ; ses membres égarés
        // donnent ses autres catégories. Un second groupe réparti sera nommé
        // par le réordonnancement suivant, une fois le premier corrigé.
        $group = $strays[0]->getTranslationGroup();
        $categories = $this->categoriesOf(array_values(array_filter($strays, static fn (AboutMeCard $stray): bool => $stray->getTranslationGroup()->equals($group))));

        throw TranslationGroupAcrossSeveralScopesException::forGroup($group, $this->sortedUnique([$category->value, ...$categories]));
    }

    /**
     * @param list<AboutMeCard> $cards
     *
     * @return list<string>
     */
    private function categoriesOf(array $cards): array
    {
        return $this->sortedUnique(array_map(static fn (AboutMeCard $card): string => $card->getCategory()->value, $cards));
    }

    /**
     * Les catégories citées par le refus, une fois chacune et dans un ordre
     * stable, quel que soit le chemin qui le lève.
     *
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sortedUnique(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
