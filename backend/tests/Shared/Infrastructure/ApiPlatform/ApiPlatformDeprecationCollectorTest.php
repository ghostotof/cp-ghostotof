<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\ApiPlatform;

use App\Tests\Support\ApiPlatformDeprecations;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;

use PHPUnit\Framework\TestCase;

/**
 * Le collecteur de dépréciations d'API Platform (issue #701), dont se sert
 * ApiPlatformRaisesNoDeprecationTest : ce qu'il retient, ce qu'il laisse
 * passer, et qu'il rend l'état qu'il a trouvé.
 *
 * Il ne vaut que s'il ne ment dans aucun sens : une dépréciation d'API
 * Platform non vue rend la garde aveugle (c'est la raison d'être de ce
 * ticket, `ignoreIndirectDeprecations` de phpunit.dist.xml l'étant déjà), et
 * une erreur d'un autre paquet avalée ferait disparaître un vrai signal.
 */
final class ApiPlatformDeprecationCollectorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function messagesAndOrigins(): iterable
    {
        yield 'message d\'API Platform, appelant dans le projet' => [
            'Since api-platform/core 4.4: Calling "denormalize()" is deprecated.',
            '/var/www/src/Contact/Foo.php',
            true,
        ];
        yield 'message d\'un composant d\'API Platform' => [
            'Since api-platform/serializer 4.4: x',
            '/var/www/src/Contact/Foo.php',
            true,
        ];
        yield 'message d\'un autre paquet, origine dans api-platform' => [
            'Since symfony/serializer 8.1: x',
            '/var/www/vendor/api-platform/serializer/ItemNormalizer.php',
            true,
        ];
        yield 'message sans paquet, origine dans api-platform' => [
            'Un message libre',
            '/var/www/vendor/api-platform/state/Foo.php',
            true,
        ];
        yield 'autre paquet, origine dans le projet' => [
            'Since symfony/serializer 8.1: x',
            '/var/www/src/Contact/Foo.php',
            false,
        ];
        yield 'autre paquet, origine dans un autre vendor' => [
            'Since doctrine/orm 3.6: x',
            '/var/www/vendor/doctrine/orm/src/Foo.php',
            false,
        ];
        yield 'un dossier qui ressemble sans l\'être' => [
            'Since symfony/serializer 8.1: x',
            '/var/www/vendor/api-platform-bridge/Foo.php',
            false,
        ];
        yield 'le préfixe doit commencer le message' => [
            'Voir Since api-platform/core 4.4: x',
            '/var/www/src/Contact/Foo.php',
            false,
        ];
    }

    #[DataProvider('messagesAndOrigins')]
    public function testItJudgesAnApiPlatformDeprecationByItsMessageOrItsOrigin(string $message, string $origin, bool $expected): void
    {
        self::assertSame($expected, ApiPlatformDeprecations::concerns($message, $origin));
    }

    public function testItReportsTheDeprecationAndWhereItWasRaised(): void
    {
        $findings = ApiPlatformDeprecations::during(static function (): void {
            trigger_deprecation('api-platform/core', '4.4', 'Calling "%s" is deprecated.', 'denormalize()');
        });

        self::assertCount(1, $findings);
        self::assertStringContainsString('Since api-platform/core 4.4: Calling "denormalize()" is deprecated.', $findings[0]);
        // L'origine est l'appelant de trigger_deprecation() — ce fichier —,
        // jamais le fichier de deprecation-contracts qui appelle trigger_error().
        self::assertStringContainsString(basename(__FILE__), $findings[0]);
        self::assertStringNotContainsString('deprecation-contracts', $findings[0]);
    }

    public function testItReportsEveryDeprecationNotOnlyTheFirst(): void
    {
        $findings = ApiPlatformDeprecations::during(static function (): void {
            trigger_deprecation('api-platform/core', '4.4', 'première');
            trigger_deprecation('api-platform/core', '4.4', 'seconde');
        });

        self::assertCount(2, $findings);
    }

    public function testItHandsAnotherPackagesDeprecationBackToTheHandlerItReplaced(): void
    {
        $received = [];
        set_error_handler(static function (int $level, string $message) use (&$received): bool {
            $received[] = $message;

            return true;
        });

        try {
            $findings = ApiPlatformDeprecations::during(static function (): void {
                trigger_deprecation('symfony/serializer', '8.1', 'autre paquet');
            });
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $findings, 'Une dépréciation d\'un autre paquet n\'est pas celle d\'API Platform.');
        self::assertSame(['Since symfony/serializer 8.1: autre paquet'], $received, 'Elle doit rester visible du gestionnaire en place.');
    }

    public function testItHandsAnyOtherErrorBackToo(): void
    {
        $received = [];
        set_error_handler(static function (int $level, string $message) use (&$received): bool {
            $received[] = $level.' '.$message;

            return true;
        });

        try {
            $findings = ApiPlatformDeprecations::during(static function (): void {
                trigger_error('une notice', \E_USER_NOTICE);
            });
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $findings);
        self::assertSame([\E_USER_NOTICE.' une notice'], $received);
    }

    public function testItRestoresTheHandlerItFound(): void
    {
        $mine = static fn (): bool => true;
        set_error_handler($mine);

        try {
            ApiPlatformDeprecations::during(static function (): void {
            });
            $current = set_error_handler(static fn (): bool => false);
            restore_error_handler();
        } finally {
            restore_error_handler();
        }

        self::assertSame($mine, $current);
    }

    public function testItRestoresTheHandlerWhenTheActionThrows(): void
    {
        $mine = static fn (): bool => true;
        set_error_handler($mine);

        try {
            try {
                ApiPlatformDeprecations::during(static function (): never {
                    throw new DomainException('échec de l\'action');
                });
                self::fail('L\'exception de l\'action doit remonter.');
            } catch (DomainException) {
            }
            $current = set_error_handler(static fn (): bool => false);
            restore_error_handler();
        } finally {
            restore_error_handler();
        }

        self::assertSame($mine, $current);
    }
}
