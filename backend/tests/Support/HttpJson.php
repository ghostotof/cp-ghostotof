<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Aides JSON pour les tests fonctionnels HTTP.
 */
trait HttpJson
{
    /**
     * Corps de requete JSON. `JSON_THROW_ON_ERROR` garantit un `string` en
     * retour (jamais `false`), ce qu'attend KernelBrowser::request(content:).
     *
     * @param array<mixed> $data
     */
    private static function jsonBody(array $data): string
    {
        return json_encode($data, \JSON_THROW_ON_ERROR);
    }
}
