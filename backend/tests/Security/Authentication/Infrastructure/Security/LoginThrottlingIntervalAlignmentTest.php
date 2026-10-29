<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Security;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Le plafond de LoginThrottlingRefusalListener (issue #743) est la fenêtre du
 * limiteur `login_throttling` de security.yaml, recopiée dans le paramètre
 * `app.login_throttling_interval_minutes` : security.yaml ne peut pas la
 * lire d'un paramètre (SecurityConfigEnvironmentParityTest refuse toute
 * référence dans la section `security`), le paramètre ne peut donc pas en
 * être la source. Ce test est le lien entre les deux valeurs : changer l'une
 * sans l'autre le met au rouge.
 *
 * Un plafond trop bas raccourcirait le `Retry-After` annoncé en dessous du
 * vrai blocage (le client réessaie trop tôt), un plafond trop haut rouvrirait
 * l'excès de #743 (le calcul 901 s, 16 minutes, 960 est dans le docblock de
 * LoginThrottlingRefusalListener).
 *
 * Le firewall `login` déclare deux limiteurs (`_login_global_login` par IP,
 * `_login_local_login` par couple IP et identifiant), tous deux sur le même
 * `interval` : le refus peut venir de l'un ou de l'autre.
 */
final class LoginThrottlingIntervalAlignmentTest extends KernelTestCase
{
    private const string PARAMETER = 'app.login_throttling_interval_minutes';

    /** @return iterable<string, array{0: string}> */
    public static function provideTheLoginLimiters(): iterable
    {
        yield 'par IP' => ['limiter._login_global_login'];
        yield 'par IP et identifiant' => ['limiter._login_local_login'];
    }

    #[DataProvider('provideTheLoginLimiters')]
    public function testTheCapEqualsTheWindowOfEachLoginLimiter(string $serviceId): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $limiter = $container->get($serviceId);
        self::assertInstanceOf(RateLimiterFactory::class, $limiter);
        self::assertSame('fixed_window', $this->config($limiter)['policy'], 'Le plafond suppose une fenêtre fixe : une autre politique peut bloquer plus longtemps que son intervalle.');

        $cap = $container->getParameter(self::PARAMETER);
        self::assertSame(
            $cap * 60,
            $this->intervalInSeconds($this->config($limiter)['interval']),
            \sprintf('security.yaml (login_throttling.interval) et le paramètre %s ne disent plus la même durée.', self::PARAMETER),
        );
    }

    /**
     * Le `config` du RateLimiterFactory est privé : on le lit par réflexion,
     * faute d'accesseur. Si Symfony le renomme, ce test le dit en clair plutôt
     * que de passer à vide.
     *
     * @return array<string, mixed>
     */
    private function config(RateLimiterFactory $factory): array
    {
        $property = new ReflectionProperty(RateLimiterFactory::class, 'config');
        /** @var array<string, mixed> $config */
        $config = $property->getValue($factory);
        self::assertArrayHasKey('interval', $config, 'RateLimiterFactory::$config ne porte plus la clé `interval` : adapter la lecture.');
        self::assertArrayHasKey('policy', $config);

        return $config;
    }

    /**
     * Mesuré depuis une date fixe plutôt que par `i * 60` : l'intervalle est un
     * `DateInterval` normalisé par Symfony (`$now->diff($now->modify('+15 minutes'))`),
     * et ses unités ne se somment pas sans date de référence.
     */
    private function intervalInSeconds(mixed $interval): int
    {
        self::assertInstanceOf(DateInterval::class, $interval);
        $origin = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

        return $origin->add($interval)->getTimestamp() - $origin->getTimestamp();
    }
}
