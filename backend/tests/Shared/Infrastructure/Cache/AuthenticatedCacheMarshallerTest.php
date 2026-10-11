<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Cache;

use App\Shared\Infrastructure\Cache\AuthenticatedCacheMarshaller;
use App\Shared\Infrastructure\Cache\CacheValueMacRefusedException;
use App\Shared\Infrastructure\Security\EmptyApplicationSecretException;
use App\Tests\Support\TestKeys;
use App\Tests\Support\UnserializationProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;

/**
 * Une valeur de cache est authentifiée avant le moindre `unserialize()`
 * (issue #468).
 *
 * `cache_items` est relue par DefaultMarshaller, soit `unserialize()` avec
 * toutes les classes permises : qui écrit dans la table (Adminer, injection
 * SQL, identifiants de la base) obtient une injection d'objet dans l'API, par
 * les limiteurs de débit, et dans le worker, par le signal de redémarrage.
 * UnserializationProbe compte ses désérialisations : un gadget réel
 * s'exécuterait au même endroit.
 */
final class AuthenticatedCacheMarshallerTest extends TestCase
{
    protected function setUp(): void
    {
        UnserializationProbe::$unserializations = 0;
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function cachedValues(): iterable
    {
        $object = new stdClass();
        $object->tokens = 4;

        yield 'chaîne' => ['valeur'];
        yield 'tableau' => [['tokens' => 4, 'timer' => 1.5]];
        yield 'objet' => [$object];
        // DefaultMarshaller traite à part la sérialisation de false et de null.
        yield 'false' => [false];
        yield 'null' => [null];
    }

    #[DataProvider('cachedValues')]
    public function testAValueItMarshalledIsUnmarshalled(mixed $value): void
    {
        $marshaller = $this->marshaller();

        self::assertEquals($value, $marshaller->unmarshall($this->marshalled($marshaller, $value)));
    }

    /**
     * Une valeur changée derrière le MAC, qui reste un objet sérialisé valide.
     */
    public function testATamperedValueIsRefusedBeforeAnyUnserialization(): void
    {
        $marshaller = $this->marshaller();
        $stored = $this->marshalled($marshaller, new UnserializationProbe());
        self::assertStringContainsString('gadget', $stored);

        $this->expectException(CacheValueMacRefusedException::class);

        try {
            $marshaller->unmarshall(str_replace('gadget', 'forged', $stored));
        } finally {
            self::assertSame(0, UnserializationProbe::$unserializations);
        }
    }

    /**
     * Ce qu'écrirait un attaquant, et ce que contient la table au premier
     * déploiement : une valeur sérialisée nue.
     */
    public function testAValueWithoutMacIsRefusedBeforeAnyUnserialization(): void
    {
        $this->expectException(CacheValueMacRefusedException::class);

        try {
            $this->marshaller()->unmarshall(serialize(new UnserializationProbe()));
        } finally {
            self::assertSame(0, UnserializationProbe::$unserializations);
        }
    }

    public function testAValueShorterThanAMacIsRefused(): void
    {
        $this->expectException(CacheValueMacRefusedException::class);

        $this->marshaller()->unmarshall('N;');
    }

    public function testAValueSignedUnderAnotherSecretIsRefused(): void
    {
        $stored = $this->marshalled($this->marshaller(TestKeys::material('other')), new UnserializationProbe());

        $this->expectException(CacheValueMacRefusedException::class);

        try {
            $this->marshaller()->unmarshall($stored);
        } finally {
            self::assertSame(0, UnserializationProbe::$unserializations);
        }
    }

    /**
     * Le message part dans les journaux, en `warning`
     * ({@see \Symfony\Component\Cache\CacheItem::log()}) : il
     * nomme la cause, jamais le contenu de la valeur.
     */
    public function testTheRefusalNeverQuotesTheValue(): void
    {
        try {
            $this->marshaller()->unmarshall(str_repeat('x', 32).serialize('contenu-témoin'));
            self::fail('Une valeur au MAC faux doit être refusée.');
        } catch (CacheValueMacRefusedException $refusal) {
            self::assertStringNotContainsString('contenu-témoin', $refusal->getMessage());
        }
    }

    /**
     * Une valeur que le marshaller interne ne sait pas sérialiser reste
     * signalée comme telle : l'adaptateur ne l'écrit pas.
     */
    public function testAValueTheInnerMarshallerCannotSerializeIsReportedAsFailed(): void
    {
        $serialized = $this->marshaller()->marshall(['ok' => 'valeur', 'ko' => static fn (): int => 1], $failed);

        self::assertSame(['ok'], array_keys($serialized));
        self::assertSame(['ko'], $failed);
    }

    /**
     * L'interface ne type pas ce que rend le marshaller interne : ce qui n'est
     * pas une chaîne ne peut pas être signé, et part en échec plutôt qu'en
     * erreur de type.
     */
    public function testAValueTheInnerMarshallerRendersAsNonStringIsReportedAsFailed(): void
    {
        $inner = self::createStub(MarshallerInterface::class);
        $inner->method('marshall')->willReturn(['ok' => 's:1:"x";', 'ko' => 42]);

        $serialized = new AuthenticatedCacheMarshaller($inner, TestKeys::material())->marshall(['ok' => 'x', 'ko' => 'y'], $failed);

        self::assertSame(['ok'], array_keys($serialized));
        self::assertSame(['ko'], $failed);
    }

    /**
     * Le préchauffage du cache au `docker build` (`cache:clear`, `APP_SECRET`
     * vide) construit ce service par l'EntityManager et le cache de résultats
     * Doctrine : une dérivation au constructeur faisait échouer le
     * préchauffage, masqué par le `|| true` du Dockerfile, et l'image partait
     * sans cache système (relecture de #468).
     */
    public function testAnEmptySecretDoesNotPreventConstruction(): void
    {
        $marshaller = new AuthenticatedCacheMarshaller(new DefaultMarshaller(), '');

        // Rien à signer, donc rien à dériver : c'est la première valeur qui refuse.
        self::assertSame([], $marshaller->marshall([], $failed));
    }

    public function testAnEmptySecretIsRefusedAtTheFirstWrite(): void
    {
        $this->expectException(EmptyApplicationSecretException::class);

        new AuthenticatedCacheMarshaller(new DefaultMarshaller(), '')->marshall(['key' => 'valeur'], $failed);
    }

    public function testAnEmptySecretIsRefusedAtTheFirstRead(): void
    {
        $this->expectException(EmptyApplicationSecretException::class);

        new AuthenticatedCacheMarshaller(new DefaultMarshaller(), '')->unmarshall(str_repeat('x', 40));
    }

    private function marshaller(?string $secret = null): AuthenticatedCacheMarshaller
    {
        return new AuthenticatedCacheMarshaller(new DefaultMarshaller(), $secret ?? TestKeys::material());
    }

    private function marshalled(AuthenticatedCacheMarshaller $marshaller, mixed $value): string
    {
        $serialized = $marshaller->marshall(['key' => $value], $failed);
        self::assertSame([], $failed);
        self::assertIsString($serialized['key'] ?? null);

        return $serialized['key'];
    }
}
