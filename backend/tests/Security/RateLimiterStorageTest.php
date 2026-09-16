<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
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
        // Les deux limiteurs que `login_throttling` déclare pour le firewall
        // `login` (par couple IP+identifiant, puis par IP seule).
        yield 'login throttling (local)' => ['_login_local_login'];
        yield 'login throttling (global)' => ['_login_global_login'];
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
