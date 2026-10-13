<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\About\Application;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\Portfolio\About\Application\AboutSettingsAdministratorInterface;
use App\Portfolio\About\Domain\Entity\AboutSettings;
use App\Portfolio\About\Domain\Exception\AboutSettingsNotFoundException;
use App\Portfolio\About\Domain\Repository\AboutSettingsRepositoryInterface;
use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutSettingsResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * `AboutSettingsAdministrator::update()` n'a pas de verrou, par décision
 * (issue #553, voir `.claude/rules/portfolio-contenus.md`, section About) :
 * il tient parce que sa lecture va en base.
 *
 * Le provider d'un `PUT` charge les réglages avant le processor, comme pour
 * les technologies de #545. Mais là où `find($id)` rend l'instance de la
 * carte d'identité sans requête, `findByLocale()` est une recherche par
 * critère (`findOneBy`) : Doctrine exécute toujours son `SELECT`. Une ligne
 * disparue depuis le chargement du provider est donc vue (404), et une ligne
 * recréée sous un autre id est celle qu'on écrit.
 *
 * Ces deux cas épinglent ce fait. Si `findByLocale()` devenait une lecture
 * dans la carte d'identité (un `find($id)` sur l'id chargé, un cache), le
 * `PUT` répondrait 200 sur une ligne disparue — l'`UPDATE` ne toucherait
 * aucune ligne, sans rien lever : il faudrait alors le verrou de ligne de #545.
 *
 * La suppression passe par SQL : aucun chemin applicatif ne supprime les
 * réglages (pas de `DELETE` au backoffice, ni de purge au seed, qui les crée
 * ou, avec --force, les réécrit), ce que le
 * troisième cas garde.
 */
final class AboutSettingsUpdateReadsCommittedRowTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection()->executeStatement('DELETE FROM about_settings');
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->connection()->executeStatement('DELETE FROM about_settings');
        parent::tearDown();
    }

    public function testAnUpdateOfSettingsDeletedSinceTheirLoadingIsANotFound(): void
    {
        $this->loadedAsTheProviderDoes();
        $this->connection()->executeStatement('DELETE FROM about_settings WHERE locale = :locale', ['locale' => Locale::FR->value]);

        $this->expectException(AboutSettingsNotFoundException::class);

        $this->administrator()->update(Locale::FR, 'Site', 'Moi', 'Technique', 'Perso', 'Loisirs');
    }

    public function testAnUpdateWritesTheRowRecreatedUnderAnotherIdSinceTheLoading(): void
    {
        $loaded = $this->loadedAsTheProviderDoes();
        $this->connection()->executeStatement('DELETE FROM about_settings WHERE locale = :locale', ['locale' => Locale::FR->value]);
        $recreatedId = Uuid::v7();
        $this->connection()->insert('about_settings', [
            'id' => $recreatedId->toRfc4122(),
            'locale' => Locale::FR->value,
            'site_eyebrow' => 'Site recréé',
            'me_eyebrow' => 'Moi recréé',
            'technical_subtitle' => 'Technique recréé',
            'personal_subtitle' => 'Perso recréé',
            'hobbies_subtitle' => 'Loisirs recréé',
        ]);

        $updated = $this->administrator()->update(Locale::FR, 'Nouveau site', 'Nouveau moi', 'Nouvelle technique', 'Nouveau perso', 'Nouveaux loisirs');

        self::assertNotSame($loaded, $updated, 'La mise à jour a écrit l\'instance chargée par le provider, celle d\'une ligne disparue.');
        self::assertTrue($recreatedId->equals($updated->getId()));
        self::assertSame(
            [['id' => $recreatedId->toRfc4122(), 'site_eyebrow' => 'Nouveau site', 'hobbies_subtitle' => 'Nouveaux loisirs']],
            $this->connection()->fetchAllAssociative('SELECT id, site_eyebrow, hobbies_subtitle FROM about_settings WHERE locale = :locale', ['locale' => Locale::FR->value]),
        );
    }

    /**
     * Le déclencheur de la règle, rendu exécutable : l'absence de verrou ne
     * tient que tant qu'aucun chemin applicatif ne supprime les réglages. Un
     * `remove()` au dépôt (une purge du seed, par exemple) ou une opération
     * `Delete` au backoffice fait rougir ce test : il faut alors d'abord le
     * verrou de ligne de #545, puis mettre ce test à jour.
     */
    public function testNothingInTheApplicationCanDeleteTheSettings(): void
    {
        $repositoryMethods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            new ReflectionClass(AboutSettingsRepositoryInterface::class)->getMethods(),
        );
        sort($repositoryMethods);
        self::assertSame(['findByLocale', 'save'], $repositoryMethods, 'AboutSettings ne se supprime plus seulement en SQL : verrou de ligne d\'abord (#553).');

        // Tous les `#[ApiResource]` de la classe : un second attribut porterait
        // ses propres opérations.
        $operations = [];
        foreach (new ReflectionClass(BackofficeAboutSettingsResource::class)->getAttributes(ApiResource::class) as $attribute) {
            foreach ($attribute->newInstance()->getOperations() ?? [] as $operation) {
                $operations[] = $operation::class;
            }
        }
        self::assertSame([Get::class, Put::class], $operations, 'Le backoffice expose une autre écriture que Put sur AboutSettings : verrou de ligne d\'abord (#553).');
    }

    /**
     * Charge les réglages FR exactement comme `BackofficeAboutSettingsProvider` :
     * par le dépôt, sur l'EntityManager que le processor utilisera ensuite.
     */
    private function loadedAsTheProviderDoes(): AboutSettings
    {
        $repository = self::getContainer()->get(AboutSettingsRepositoryInterface::class);
        $repository->save(new AboutSettings(Locale::FR, 'Site', 'Moi', 'Technique', 'Perso', 'Loisirs'));
        $this->em->clear();

        $loaded = $repository->findByLocale(Locale::FR);
        self::assertNotNull($loaded);

        return $loaded;
    }

    private function administrator(): AboutSettingsAdministratorInterface
    {
        return self::getContainer()->get(AboutSettingsAdministratorInterface::class);
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }
}
