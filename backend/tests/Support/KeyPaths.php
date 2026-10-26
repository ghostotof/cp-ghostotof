<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Un chemin de clés dans un tableau imbriqué — une clé de configuration, un
 * argument d'une définition —, lu ou retiré par les gardes de parité entre
 * environnements ({@see \App\Tests\Security\SecurityConfigEnvironmentParityTest},
 * {@see \App\Tests\Security\SecurityServicesEnvironmentParityTest}), qui
 * l'écrivaient chacune (relecture de #698).
 */
final class KeyPaths
{
    /**
     * La valeur au bout du chemin, `null` si le chemin n'existe pas.
     *
     * @param list<int|string> $path
     */
    public static function at(mixed $value, array $path): mixed
    {
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Le tableau sans la valeur au bout du chemin — et sans rien d'autre.
     *
     * @param array<array-key, mixed> $value
     * @param list<int|string>        $path
     *
     * @return array<array-key, mixed>
     */
    public static function without(array $value, array $path): array
    {
        $key = array_shift($path);
        if (null === $key || !\array_key_exists($key, $value)) {
            return $value;
        }
        if ([] === $path) {
            unset($value[$key]);

            return $value;
        }
        if (\is_array($value[$key])) {
            $value[$key] = self::without($value[$key], $path);
        }

        return $value;
    }

    /**
     * « [clé][clé]… », pour un diagnostic.
     *
     * @param list<int|string> $path
     */
    public static function describe(array $path): string
    {
        return implode('', array_map(static fn (int|string $key): string => \sprintf('[%s]', $key), $path));
    }
}
