<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Shared\Application;

use App\Portfolio\Shared\Domain\Exception\TranslatableContentLocaleIsImmutableException;
use App\Portfolio\Shared\Domain\TranslatableContent;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Closure;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Une entrée localisée ne change jamais de langue (issue #582), sur les huit
 * contextes de ProvidesLocalizedAdministrators : un contexte ajouté au trait
 * est couvert d'office.
 *
 * Jusqu'à #582, `update()` ne recevait pas la langue : le `PUT` la validait,
 * l'ignorait et répondait 200 (#170 R2). Chaque Administrator la reçoit
 * désormais et refuse une autre langue que celle de l'entrée, juste après sa
 * relecture sous le verrou, avant toute autre lecture et toute écriture : la
 * base est inchangée, et l'EntityManager reste ouvert (#549, rien n'a été
 * écrit). Les tests fonctionnels de chaque ressource figent le 409 typé ; ici,
 * la règle elle-même et sa place parmi les gardes.
 *
 * @phpstan-import-type LocalizedContext from ProvidesLocalizedAdministrators
 */
final class LocaleIsImmutableOnUpdateTest extends KernelTestCase
{
    use ProvidesLocalizedAdministrators;

    private ?string $table = null;

    protected function tearDown(): void
    {
        if (null !== $this->table) {
            self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM '.$this->table);
        }
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: class-string<TranslatableContent>, 1: Closure(): LocalizedContext}>
     */
    public static function provideLocalizedContexts(): iterable
    {
        yield from self::localizedAdministrators();
    }

    /**
     * Une entrée FR traduite en EN, modifiée avec `en` et son propre groupe —
     * le corps d'un client qui croirait changer la langue : refusé, l'entrée
     * et sa place inchangées en base comme en mémoire.
     *
     * @param class-string<TranslatableContent> $entityClass
     * @param Closure(): LocalizedContext       $context
     */
    #[DataProvider('provideLocalizedContexts')]
    public function testAnotherLocaleIsRefusedBeforeAnyWrite(string $entityClass, Closure $context): void
    {
        $operations = $this->boot($entityClass, $context);
        $entry = $operations['create'](Locale::FR, null);
        $operations['create'](Locale::EN, $entry->getTranslationGroup());
        $before = $this->storedRowOf($entityClass, $entry);

        $refusal = $this->refusalOf(static fn (): TranslatableContent => $operations['update']($entry, $entry->getTranslationGroup(), Locale::EN));

        self::assertInstanceOf(TranslatableContentLocaleIsImmutableException::class, $refusal, \sprintf('Refus attendu sous son nom, reçu %s.', get_debug_type($refusal)));
        self::assertSame($before, $this->storedRowOf($entityClass, $entry), "L'entrée a changé en base malgré le refus.");
        self::assertSame(Locale::FR, $entry->getLocale());
        self::assertTrue(self::getContainer()->get(EntityManagerInterface::class)->isOpen(), "Le refus a fermé l'EntityManager : il est tombé après une écriture (#549).");
    }

    /**
     * Ordre des gardes : le refus de langue ne dépend que du corps, il passe
     * avant le 409 `translation-already-exists` que donnerait le rattachement
     * demandé (un groupe qui porte déjà la langue de l'entrée).
     *
     * @param class-string<TranslatableContent> $entityClass
     * @param Closure(): LocalizedContext       $context
     */
    #[DataProvider('provideLocalizedContexts')]
    public function testTheLocaleRefusalComesBeforeTheTranslationConflict(string $entityClass, Closure $context): void
    {
        $operations = $this->boot($entityClass, $context);
        $entry = $operations['create'](Locale::FR, null);
        $other = $operations['create'](Locale::FR, null);

        $refusal = $this->refusalOf(static fn (): TranslatableContent => $operations['update']($entry, $other->getTranslationGroup(), Locale::EN));

        self::assertInstanceOf(TranslatableContentLocaleIsImmutableException::class, $refusal, \sprintf('Refus de langue attendu en premier, reçu %s.', get_debug_type($refusal)));
    }

    /**
     * @param class-string<TranslatableContent> $entityClass
     * @param Closure(): LocalizedContext       $context
     *
     * @return LocalizedContext
     */
    private function boot(string $entityClass, Closure $context): array
    {
        self::bootKernel();
        $this->table = self::getContainer()->get(EntityManagerInterface::class)->getClassMetadata($entityClass)->getTableName();

        return $context();
    }

    /**
     * @param Closure(): TranslatableContent $operation
     */
    private function refusalOf(Closure $operation): ?Throwable
    {
        try {
            $operation();
        } catch (Throwable $refusal) {
            return $refusal;
        }

        return null;
    }

    /**
     * La ligne telle que la base la porte, lue hors de la carte d'identité.
     *
     * @param class-string<TranslatableContent> $entityClass
     *
     * @return array<string, mixed>
     */
    private function storedRowOf(string $entityClass, TranslatableContent $entry): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $entityManager->getClassMetadata($entityClass);
        $id = $metadata->getIdentifierValues($entry)['id'] ?? null;
        self::assertInstanceOf(Uuid::class, $id);

        $row = $entityManager->getConnection()->fetchAssociative(
            \sprintf(
                'SELECT %s AS locale, %s AS translation_group, %s AS position FROM %s WHERE %s = ?',
                $metadata->getColumnName('locale'),
                $metadata->getColumnName('translationGroup'),
                $metadata->getColumnName('position'),
                $metadata->getTableName(),
                $metadata->getColumnName('id'),
            ),
            [$id->toRfc4122()],
        );
        self::assertIsArray($row, "L'entrée a disparu de la base.");

        return $row;
    }
}
