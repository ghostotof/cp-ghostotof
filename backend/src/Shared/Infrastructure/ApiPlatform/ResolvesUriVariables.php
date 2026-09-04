<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform;

use App\Portfolio\Shared\Domain\Exception\InvalidLocaleException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

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

    /**
     * Résout un segment `{locale}` en Value Object, en une étape. Évite le
     * `Locale::from($this->uriVariableString($uriVariables, 'locale'))` répété
     * dans chaque Provider/Processor de contenu, et surtout garantit que
     * l'échec produit une InvalidLocaleException (mappée 404) plutôt qu'un
     * `\ValueError` nu — cf. point d'audit I3.
     *
     * @param array<string, mixed> $uriVariables
     *
     * @throws InvalidLocaleException si le segment ne correspond à aucune langue gérée
     */
    private function uriVariableLocale(array $uriVariables, string $key = 'locale'): Locale
    {
        return Locale::fromString($this->uriVariableString($uriVariables, $key));
    }
}
