<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Une erreur de la base ne cite aucune valeur des données (issue #428).
 *
 * PostgreSQL recopie la valeur en cause dans son message : la clé en conflit
 * (`DETAIL: Key (email)=(personne@exemple.fr) already exists`), la ligne
 * entière d'une contrainte violée (`DETAIL: Failing row contains (…)`, le
 * condensat du mot de passe compris), la saisie qu'un type refuse
 * (`invalid input syntax for type uuid: "…"`). Le noyau comme le worker
 * journalisent le message et toute la chaîne `previous`, jusque dans
 * l'ErrorDetailsStamp du failure_transport.
 *
 * La rédaction se fait ici, au pilote, pour toutes les connexions et toutes
 * les tables : le pilote enveloppé ({@see RedactingDriver}) fait passer
 * chaque exception par son convertisseur ({@see RedactingExceptionConverter})
 * avant qu'elle ne devienne une exception DBAL.
 * La classe DBAL et le SQLSTATE, que des appelants lisent (une violation
 * d'unicité attrapée par CpgUserInviter, le verrou d'ordre), ne changent pas.
 */
#[AsMiddleware]
final class DriverErrorRedactionMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new RedactingDriver($driver);
    }
}
