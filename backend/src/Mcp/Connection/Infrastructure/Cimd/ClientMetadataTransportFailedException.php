<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Cimd;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Trace sans valeur d'une erreur de transport lors de la récupération d'un
 * document CIMD (#651).
 *
 * Le message d'une `TransportExceptionInterface` du client HTTP cite l'URL
 * complète du `client_id`, dont le chemin est choisi par un appelant anonyme :
 * chaîné tel quel, il finirait dans les journaux (le formateur JSON de Monolog
 * sérialise toute la chaîne `previous`), alors que l'invariant CIMD veut qu'on
 * n'y écrive que l'hôte. Cette exception est ce qu'on chaîne à la place : son
 * message est constant et ne nomme que la classe de l'erreur d'origine
 * (`TimeoutException`, `TransportException`…), ce qui distingue un délai d'un
 * échec de connexion sans rien porter de ce que l'appelant a choisi.
 *
 * Elle n'a pas d'exception précédente, volontairement : c'est la rupture de
 * chaîne qui protège.
 */
final class ClientMetadataTransportFailedException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function of(TransportExceptionInterface $transport): self
    {
        return new self(\sprintf('transport failure: %s', $transport::class));
    }
}
