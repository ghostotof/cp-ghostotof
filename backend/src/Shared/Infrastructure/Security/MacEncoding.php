<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

/**
 * Forme d'un {@see KeyedMac}, fixée par l'usage à la création (issue #487).
 */
enum MacEncoding
{
    /** 64 caractères hexadécimaux en minuscules : un en-tête, un cookie, un journal. */
    case Hex;

    /** 32 octets bruts : une colonne binaire (`BYTEA`). */
    case Binary;
}
