<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

/**
 * Ce que montre un objet par chacun des canaux qui l'affichent (issues #487,
 * #493) : les sorties de débogage de PHP, un transtypage en tableau,
 * `json_encode()`, et le VarDumper de `dump()` et du profiler — qui lit les
 * propriétés privées même quand un `__debugInfo()` les masquerait.
 *
 * Un test qui y cherche un secret n'affiche que le canal en cas d'échec,
 * jamais le dump, qui afficherait le secret.
 */
final class Dumps
{
    /**
     * @return array<string, string> le dump de `$object`, par canal
     */
    public static function of(object $object): array
    {
        ob_start();
        var_dump($object);

        return [
            'var_dump' => (string) ob_get_clean(),
            'print_r' => print_r($object, true),
            'var_export' => var_export($object, true),
            'cast' => print_r((array) $object, true),
            'json_encode' => (string) json_encode($object),
            'VarDumper' => (string) new CliDumper()->dump(new VarCloner()->cloneVar($object), true),
        ];
    }

    /**
     * Les canaux qui affichent `$needle`, en clair ou en hexadécimal.
     *
     * @return list<string>
     */
    public static function channelsShowing(object $object, string $needle): array
    {
        return array_keys(array_filter(
            self::of($object),
            static fn (string $dump): bool => str_contains($dump, $needle) || str_contains($dump, bin2hex($needle)),
        ));
    }
}
