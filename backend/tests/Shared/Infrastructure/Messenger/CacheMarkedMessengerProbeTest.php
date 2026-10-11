<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Shared\Application\Message\MessengerProbeMessage;
use App\Shared\Infrastructure\Messenger\CacheMarkedMessengerProbe;
use App\Shared\Infrastructure\Messenger\MessengerProbeHandler;
use App\Tests\Shared\Infrastructure\Messenger\Fixtures\RecordingBus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Le message témoin du smoke préprod (issue #470) : la sonde le met en file et
 * attend la marque que le worker pose en le traitant.
 *
 * Le bus de ces tests remplace la file et le worker : il exécute le vrai
 * handler (le worker a consommé), ou n'en fait rien (le worker a refusé
 * l'enveloppe, ou ne tourne pas). L'horloge est simulée : l'attente ne coûte
 * aucune seconde réelle.
 */
final class CacheMarkedMessengerProbeTest extends TestCase
{
    private ArrayAdapter $cache;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
        $this->clock = new MockClock('2026-10-11 12:00:00');
    }

    public function testTheProbeSucceedsWhenTheWorkerHandlesTheMessage(): void
    {
        $bus = new RecordingBus(new MessengerProbeHandler($this->cache));

        self::assertTrue($this->probe($bus)->probe(30));
        self::assertCount(1, $bus->dispatched);
    }

    /**
     * Un écart de clé de MAC entre l'API et le worker envoie le témoin dans
     * `failed` : le worker ne le traite jamais, la sonde échoue au délai.
     */
    public function testTheProbeFailsAfterItsTimeoutWhenNothingHandlesTheMessage(): void
    {
        $start = $this->clock->now();

        self::assertFalse($this->probe(new RecordingBus(null))->probe(30));

        $waited = $this->clock->now()->getTimestamp() - $start->getTimestamp();
        self::assertGreaterThanOrEqual(30, $waited);
        self::assertLessThan(32, $waited);
    }

    /**
     * Chaque sonde porte son propre identifiant : la marque d'une sonde précédente,
     * encore en cache, ne fait pas réussir la suivante.
     */
    public function testAMarkLeftByAnEarlierProbeDoesNotCount(): void
    {
        $handler = new MessengerProbeHandler($this->cache);
        $earlier = new RecordingBus($handler);
        self::assertTrue($this->probe($earlier)->probe(30));
        $handler(new MessengerProbeMessage($earlier->probeIds()[0]));

        self::assertFalse($this->probe(new RecordingBus(null))->probe(5));
    }

    public function testEveryProbeCarriesADistinctId(): void
    {
        $bus = new RecordingBus(new MessengerProbeHandler($this->cache));
        $probe = $this->probe($bus);

        $probe->probe(5);
        $probe->probe(5);

        $probeIds = $bus->probeIds();
        self::assertCount(2, $probeIds);
        self::assertNotSame($probeIds[0], $probeIds[1]);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $probeIds[0]);
    }

    /** La marque est retirée une fois lue : la table ne garde rien du smoke. */
    public function testTheMarkIsRemovedOnceRead(): void
    {
        $bus = new RecordingBus(new MessengerProbeHandler($this->cache));
        $this->probe($bus)->probe(5);

        self::assertFalse($this->cache->hasItem(CacheMarkedMessengerProbe::markerKey($bus->probeIds()[0])));
    }

    private function probe(MessageBusInterface $bus): CacheMarkedMessengerProbe
    {
        return new CacheMarkedMessengerProbe($bus, $this->cache, $this->clock);
    }
}
