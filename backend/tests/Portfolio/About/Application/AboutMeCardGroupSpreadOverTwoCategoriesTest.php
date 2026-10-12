<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Application;

use App\Portfolio\About\Application\AboutMeCardAdministratorInterface;
use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\Shared\Domain\Exception\TranslationGroupAcrossSeveralScopesException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Tests\Portfolio\About\Support\CommitsSpreadMeCardGroup;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * Issue #561, sur la vraie base : un groupe de cartes « moi » réparti sur deux
 * catégories, antérieur à la garde de #501, reçoit un refus **nommé** et
 * **avant toute écriture** de chaque opération du backoffice qui le toucherait
 * — sauf « Détacher », qui le répare.
 *
 * Avant le correctif : « Créer la version EN » sortait en
 * `UniqueConstraintViolationException` brute (l'index unique
 * `(translation_group, locale)` passe avant le trigger), et un `reorder()` qui
 * déplaçait le membre égaré écrivait, puis se faisait refuser au COMMIT
 * (`23T91`) — dans les deux cas l'EntityManager fermé.
 */
final class AboutMeCardGroupSpreadOverTwoCategoriesTest extends KernelTestCase
{
    use CommitsSpreadMeCardGroup;

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM about_me_card');
        parent::tearDown();
    }

    public function testCreatingTheMissingVersionInTheCategoryOfOneMemberIsRefusedByNameWithoutWriting(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        [$french] = self::commitSpreadGroup($entityManager);

        $refusal = $this->refusalOf(static fn (AboutMeCardAdministratorInterface $administrator): AboutMeCard => $administrator->create(
            Locale::EN,
            AboutMeCardCategory::HOBBY,
            'Music',
            'Description.',
            'guitar',
            $french->getTranslationGroup(),
        ));

        self::assertInstanceOf(TranslationGroupAcrossSeveralScopesException::class, $refusal, \sprintf('Refus attendu sous son nom, reçu %s.', $refusal::class));
        self::assertNull($refusal->getPrevious(), "Le refus vient de la base : l'écriture a été tentée.");
        self::assertTrue($entityManager->isOpen(), "L'EntityManager a été fermé : le refus n'est pas tombé avant l'écriture.");
        self::assertSame(2, $this->connection()->fetchOne('SELECT COUNT(*) FROM about_me_card'));
    }

    public function testReorderMovingTheStrayMemberIsRefusedByNameBeforeWriting(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        [$french] = self::commitSpreadGroup($entityManager);
        $other = self::getContainer()->get(AboutMeCardAdministratorInterface::class)
            ->create(Locale::FR, AboutMeCardCategory::HOBBY, 'Lecture', 'Description.', 'book');

        // Échanger les deux lignes déplace le FR du groupe réparti : c'est ce
        // déplacement que la base refusait au COMMIT.
        $refusal = $this->refusalOf(static function (AboutMeCardAdministratorInterface $administrator) use ($french, $other): void {
            $administrator->reorder(AboutMeCardCategory::HOBBY, [
                $other->getTranslationGroup()->toRfc4122(),
                $french->getTranslationGroup()->toRfc4122(),
            ]);
        });

        self::assertInstanceOf(TranslationGroupAcrossSeveralScopesException::class, $refusal, \sprintf('Refus attendu sous son nom, reçu %s.', $refusal::class));
        self::assertNull($refusal->getPrevious(), "Le refus vient de la base au COMMIT : l'écriture a été tentée.");
        self::assertTrue($entityManager->isOpen(), "L'EntityManager a été fermé : le refus n'est pas tombé avant l'écriture.");
        self::assertSame(0, $this->connection()->fetchOne('SELECT position FROM about_me_card WHERE id = ?', [$french->getId()->toRfc4122()]));
    }

    public function testDetachingTheStrayMemberRepairsTheGroup(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        [$french, $english] = self::commitSpreadGroup($entityManager);

        // Le COMMIT du verrou passe le trigger : le groupe neuf du FR est seul,
        // l'ancien ne garde que l'EN.
        self::getContainer()->get(AboutMeCardAdministratorInterface::class)
            ->update($french->getId(), 'Musique', 'Description.', 'guitar', null);

        $groups = $this->connection()->fetchAllKeyValue(
            'SELECT id, translation_group FROM about_me_card WHERE id IN (?, ?)',
            [$french->getId()->toRfc4122(), $english->getId()->toRfc4122()],
        );
        self::assertNotSame($groups[$english->getId()->toRfc4122()], $groups[$french->getId()->toRfc4122()], 'Le détachement a été sauté : le groupe est toujours réparti.');
        self::assertSame($english->getTranslationGroup()->toRfc4122(), $groups[$english->getId()->toRfc4122()], "L'autre membre a changé de groupe.");
    }

    /**
     * @param callable(AboutMeCardAdministratorInterface): mixed $operation
     */
    private function refusalOf(callable $operation): Throwable
    {
        try {
            $operation(self::getContainer()->get(AboutMeCardAdministratorInterface::class));
        } catch (Throwable $refusal) {
            return $refusal;
        }

        self::fail('Une opération sur un groupe réparti a été acceptée.');
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }
}
