<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;

/**
 * Le seul scope du serveur d'autorisation (spec 0007 phase 2) : lire le
 * corpus nominatif au nom de la personne qui a consenti. Rien d'autre ne
 * s'accorde, ni ne se demande.
 */
final class NominativeReadScope implements ScopeEntityInterface
{
    use ScopeTrait;

    public const string IDENTIFIER = 'nominative:read';

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }
}
