<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\DoctrineDbalPostgreSqlStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Aucun état applicatif ne vit sur le système de fichiers du pod (ADR 0005).
 *
 * Audit du 2026-09-16, constat A1 : en production, les pods tournent avec
 * `readOnlyRootFilesystem: true` et seul `var/log` est monté. Le pool `cache.app`
 * — dont héritent `cache.rate_limiter`, donc *tous* les limiteurs de débit, et
 * le cache de résultats Doctrine — était le FilesystemAdapter par défaut, sous
 * `var/cache/prod/pools`. Son `save()` échouait en silence (`false`, pas
 * d'exception) : chaque requête repartait d'un compteur vide. Quatorze logins
 * erronés d'affilée ont répondu « Invalid credentials. », jamais « Too many
 * failed login attempts » ; l'anti-brute-force, le quota du formulaire de
 * contact, celui du palier de base et celui de l'assistant de traduction
 * n'existaient plus qu'en dev et en CI, où le disque est inscriptible.
 *
 * C'est précisément pourquoi ce test ne peut pas reproduire l'incident : la CI
 * écrit où elle veut. Il pince donc la *configuration* — le stockage des
 * limiteurs et `cache.app` sont un adaptateur Doctrine DBAL (partagé entre les
 * réplicas, durable au redémarrage, sans service supplémentaire), jamais un
 * FilesystemAdapter. Le comportement réel, lui, est exercé par le smoke test
 * de la préprod (`tools/smoke-login-throttling.sh`), seul endroit où un pod
 * réel est interrogé.
 *
 * Un nouveau limiteur déclaré dans `rate_limiter.yaml` s'ajoute à la liste
 * ci-dessous ; il hérite de `cache.app` sauf `cache_pool` explicite, et c'est
 * ce pool explicite qu'un oubli ici laisserait passer.
 *
 * Le stockage ne suffit pas : il faut aussi un **verrou partagé entre pods**
 * (issue #272). Sans verrou, `consume()` fait un lire-modifier-écrire non
 * atomique sur `cache.app` : vingt requêtes simultanées sur la même clé ne
 * décomptaient qu'une seule unité (reproduit en dev, 3 passages sur 3). Le
 * verrou doit être un advisory lock PostgreSQL — jamais `flock` ni
 * `semaphore`, locaux au pod, qui répareraient un poste de dev et laisseraient
 * la production ouverte dès deux réplicas. Ce test-ci pince le câblage ;
 * RateLimiterConcurrencyTest en vérifie l'effet (issue #277).
 */
final class RateLimiterStorageTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideLimiterNames(): iterable
    {
        yield 'contact_form' => ['contact_form'];
        yield 'account_password_setup' => ['account_password_setup'];
        yield 'base_access' => ['base_access'];
        yield 'translation_assistant' => ['translation_assistant'];
        yield 'career_assistant' => ['career_assistant'];
        // Les deux limiteurs que `login_throttling` déclare pour le firewall
        // `login` (par couple IP+identifiant, puis par IP seule).
        yield 'login throttling (local)' => ['_login_local_login'];
        yield 'login throttling (global)' => ['_login_global_login'];
    }

    /**
     * La liste ci-dessus est tenue à la main ; ce test la confronte au
     * conteneur. Un limiteur ajouté (`career_assistant` à la fusion de la
     * spec 0005, par exemple) sans y figurer ferait rougir la suite au lieu
     * d'échapper aux contrôles de stockage et de verrou (audit du hotfix #272,
     * constat B3).
     */
    public function testEveryRateLimiterOfTheContainerIsCoveredByThisTest(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $declared = [];
        foreach ([...$container->getServiceIds(), ...array_keys($container->getRemovedIds())] as $id) {
            if (1 === preg_match('/^limiter\.(?!storage\.)(.+)$/', $id, $match)) {
                $declared[$match[1]] = true;
            }
        }
        $declared = array_keys($declared);
        sort($declared);

        $covered = array_map(static fn (array $arguments): string => $arguments[0], iterator_to_array(self::provideLimiterNames(), false));
        sort($covered);

        self::assertNotEmpty($declared);
        self::assertSame($declared, $covered, 'Chaque limiteur du conteneur doit figurer dans provideLimiterNames().');
    }

    #[DataProvider('provideLimiterNames')]
    public function testEveryRateLimiterStoresItsStateInTheDatabase(string $limiterName): void
    {
        self::bootKernel();

        // Service privé, mais référencé par `limiter.<nom>` : le conteneur de
        // test l'expose tel quel.
        $storage = self::getContainer()->get('limiter.storage.'.$limiterName);
        self::assertInstanceOf(CacheStorage::class, $storage);

        $pool = (new \ReflectionProperty(CacheStorage::class, 'pool'))->getValue($storage);
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);

        $this->assertPoolIsDatabaseBacked($pool, 'limiter.storage.'.$limiterName);
    }

    #[DataProvider('provideLimiterNames')]
    public function testEveryRateLimiterSerialisesItsWritesWithALockSharedAcrossPods(string $limiterName): void
    {
        self::bootKernel();

        $factory = self::getContainer()->get('limiter.'.$limiterName);
        self::assertInstanceOf(RateLimiterFactory::class, $factory);

        $lockFactory = (new \ReflectionProperty(RateLimiterFactory::class, 'lockFactory'))->getValue($factory);
        self::assertInstanceOf(
            LockFactory::class,
            $lockFactory,
            \sprintf('limiter.%s n\'a pas de verrou : des consume() simultanés se partagent une seule unité de quota (#272).', $limiterName),
        );

        $store = (new \ReflectionProperty(LockFactory::class, 'store'))->getValue($lockFactory);
        self::assertInstanceOf(PersistingStoreInterface::class, $store);
        self::assertInstanceOf(
            DoctrineDbalPostgreSqlStore::class,
            $store,
            \sprintf('Le verrou de limiter.%s doit être un advisory lock PostgreSQL, partagé entre pods ; obtenu : %s.', $limiterName, $store::class),
        );
    }

    public function testAppCacheStoresItsStateInTheDatabase(): void
    {
        self::bootKernel();

        $this->assertPoolIsDatabaseBacked(self::getContainer()->get('cache.app'), 'cache.app');
    }

    private function assertPoolIsDatabaseBacked(CacheItemPoolInterface $pool, string $serviceId): void
    {
        // En debug, chaque pool est enveloppé pour le profiler : on regarde
        // l'adaptateur réel, pas l'enveloppe.
        while ($pool instanceof TraceableAdapter) {
            $pool = $pool->getPool();
        }

        self::assertNotInstanceOf(
            FilesystemAdapter::class,
            $pool,
            \sprintf('%s écrit sur le système de fichiers du pod : en production il est en lecture seule et l\'état est perdu à chaque requête (ADR 0005).', $serviceId),
        );
        self::assertInstanceOf(
            DoctrineDbalAdapter::class,
            $pool,
            \sprintf('%s doit être adossé à Doctrine DBAL (partagé entre les réplicas, ADR 0005) ; obtenu : %s.', $serviceId, $pool::class),
        );
    }
}
