<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform;

/**
 * Les variables d'URI d'API Platform proviennent toujours de segments de
 * chemin, donc de type `string` a l'execution ; mais ProviderInterface /
 * ProcessorInterface les typent `array<string, mixed>` et la contravariance
 * LSP interdit de resserrer ce type dans les implementations. Ce trait
 * centralise l'extraction typee (avec garde a l'execution) pour eviter un
 * `(int)`/`(string)` sur `mixed` reecrit dans chaque Provider/Processor.
 *
 * Vit sous `App\Shared` (et non `App\Portfolio\Shared`) car il sert aussi les
 * contextes hors Portfolio (Security\User).
 */
trait ResolvesUriVariables
{
    /**
     * @param array<string, mixed> $uriVariables
     */
    private function uriVariableInt(array $uriVariables, string $key): int
    {
        $value = $uriVariables[$key] ?? null;
        \assert(\is_string($value) || \is_int($value));

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function uriVariableString(array $uriVariables, string $key): string
    {
        $value = $uriVariables[$key] ?? null;
        \assert(\is_string($value));

        return $value;
    }
}
