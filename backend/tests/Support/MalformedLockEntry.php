<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Ce que fait {@see ComposerFiles::lockedPackages()} d'une entrée mal formée
 * de `composer.lock` : une section qui n'est pas une liste, une entrée qui
 * n'est pas un objet, un nom ou une version qui n'est pas une chaîne.
 *
 * Les deux gardes qui lisent le lock n'ont jamais eu la même tolérance, et
 * l'aide commune ne la change pour aucun : celui d'API Platform ignore
 * l'entrée (il juge la famille sur le reste), celui des épinglages exacts
 * échoue (un lock qu'il ne sait pas lire ne prouve rien).
 */
enum MalformedLockEntry
{
    /** L'entrée est ignorée ({@see \App\Tests\Support\ApiPlatformLockFamily}). */
    case Skipped;

    /** Le test échoue en la nommant ({@see \App\Tests\Shared\SymfonyAiAndMcpPinsMatchTheLockTest}). */
    case Fails;
}
