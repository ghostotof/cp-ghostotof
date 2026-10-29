<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\ApiPlatform\Fixtures;

use App\Tests\Support\ApiPlatformDeprecations;
use Closure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Lancé dans un PHPUnit enfant par
 * {@see \App\Tests\Shared\Infrastructure\ApiPlatform\ApiPlatformDeprecationHandoverTest} (issue #701) : son nom ne
 * finit pas par `Test`, la suite ne le ramasse pas.
 *
 * Une dépréciation **indirecte** (un paquet du vendor qui en appelle un autre)
 * levée par un `trigger_error()` brut, sans passer par `trigger_deprecation()`,
 * que `ignoreIndirectDeprecations` de `phpunit.dist.xml` ne compte pas. Les
 * deux tests la déclenchent à l'identique, l'un seul sous le collecteur : PHPUnit
 * doit la classer pareil.
 */
final class RawIndirectDeprecationProbe extends TestCase
{
    private ?string $directory = null;

    public function testWithoutTheCollector(): void
    {
        ($this->indirectDeprecation())();

        self::addToAssertionCount(1);
    }

    public function testUnderTheCollector(): void
    {
        $findings = ApiPlatformDeprecations::during($this->indirectDeprecation());

        self::assertSame([], $findings);
    }

    protected function tearDown(): void
    {
        if (null !== $this->directory) {
            new Filesystem()->remove($this->directory);
        }
        parent::tearDown();
    }

    /**
     * Un `vendor/symfony/foo` qui lève, appelé par un `vendor/symfony/bar`.
     *
     * @return Closure(): void
     */
    private function indirectDeprecation(): Closure
    {
        $namespace = 'RawIndirectDeprecationFixture'.bin2hex(random_bytes(4));
        $this->directory = sys_get_temp_dir().'/'.$namespace;
        $filesystem = new Filesystem();
        $legacy = $this->directory.'/vendor/symfony/foo/Legacy.php';
        $caller = $this->directory.'/vendor/symfony/bar/Caller.php';
        $filesystem->dumpFile($legacy, <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            final class Legacy
            {
                public static function old(): void
                {
                    trigger_error('Appel déprécié, levé à la main.', \E_USER_DEPRECATED);
                }
            }
            PHP);
        $filesystem->dumpFile($caller, <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            final class Caller
            {
                public static function run(): void
                {
                    Legacy::old();
                }
            }
            PHP);
        require_once $legacy;
        require_once $caller;

        /** @var class-string $class */
        $class = $namespace.'\\Caller';

        return static function () use ($class): void {
            $class::run();
        };
    }
}
