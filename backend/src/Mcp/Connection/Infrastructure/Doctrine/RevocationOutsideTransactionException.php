<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Doctrine;

use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * La révocation de toutes les connexions d'un compte (#650), ou le verrou
 * des connexions actives d'un client (#652), a été demandé hors
 * transaction : un défaut de l'appelant, jamais une issue ordinaire.
 *
 * Hors transaction, l'instruction seule serait validée tout de suite, avant
 * le reste de l'opération de l'appelant (le nouveau mot de passe, le rôle
 * retiré) : un échec de celle-ci laisserait des connexions coupées pour un
 * compte inchangé, et inversement. D'où un refus avant d'écrire quoi que ce
 * soit, sans mapping HTTP : un 500 `critical` qui porte un nom. Le message ne
 * cite que l'UUID du compte ou du client, jamais un identifiant (#363). Le
 * verrou d'un client, lui, ne vaut que tenu jusqu'à l'écriture qui le suit :
 * relâché à la fin de sa propre instruction, il ne garderait rien.
 */
final class RevocationOutsideTransactionException extends LogicException
{
    public static function forAccount(string $accountId): self
    {
        return new self(\sprintf('La révocation de toutes les connexions MCP du compte %s se fait dans la transaction de l\'appelant : hors transaction, elle serait validée avant le reste de son opération.', $accountId));
    }

    public static function forClient(Uuid $client): self
    {
        return new self(\sprintf('Le verrou des connexions MCP actives du client %s se prend dans la transaction de l\'appelant : hors transaction, il serait relâché avant l\'écriture qu\'il protège.', $client->toRfc4122()));
    }
}
