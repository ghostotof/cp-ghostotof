<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Closure;

/**
 * Le processus de PHPUnit tourne en APP_ENV=test : un fichier de
 * configuration PHP qui lirait `$_SERVER['APP_ENV']` (ou `$_ENV`, `getenv()`)
 * prendrait la branche de `test` dans chaque noyau de parité, quel que soit
 * l'environnement compilé, alors que l'image de production compile son cache
 * en APP_ENV=prod et prend l'autre (audit de #698, mesuré). Les noyaux de
 * parité chargent donc la configuration sous l'environnement qu'ils lisent,
 * hors débogage comme eux, puis rendent au processus ses valeurs.
 */
final class ProcessEnvironment
{
    private const array VARIABLES = ['APP_ENV', 'APP_DEBUG'];

    /**
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    public static function whileRunningAs(string $environment, Closure $operation): mixed
    {
        $saved = [];
        foreach (self::VARIABLES as $name) {
            $saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
        }
        try {
            foreach (['APP_ENV' => $environment, 'APP_DEBUG' => '0'] as $name => $value) {
                $_SERVER[$name] = $value;
                $_ENV[$name] = $value;
                putenv($name.'='.$value);
            }

            return $operation();
        } finally {
            foreach ($saved as $name => [$server, $env, $process]) {
                self::restore($_SERVER, $name, $server);
                self::restore($_ENV, $name, $env);
                putenv(false === $process ? $name : $name.'='.$process);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $variables
     */
    private static function restore(array &$variables, string $name, mixed $value): void
    {
        if (null === $value) {
            unset($variables[$name]);

            return;
        }
        $variables[$name] = $value;
    }
}
