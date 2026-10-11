<?php

declare(strict_types=1);

namespace App\Contact\Application;

use App\Contact\Domain\Exception\ContactRateLimitExceededException;

/**
 * Quota de messages de contact par visiteur, en deux temps (issue #485) :
 * vérifié avant l'envoi, consommé seulement une fois le message parti. Un
 * envoi qui échoue (file indisponible) ne coûte rien au visiteur.
 *
 * L'identifiant du visiteur est l'IP côté HTTP (cf. ContactMessageProcessor),
 * jamais une donnée saisie par l'utilisateur.
 */
interface ContactRateLimiterInterface
{
    /**
     * Vérifie qu'un message peut encore partir, sans rien consommer.
     *
     * @throws ContactRateLimitExceededException si le quota est épuisé
     */
    public function assertWithinQuota(string $clientIdentifier): void;

    /**
     * Compte un message parti. Ne refuse jamais : le message est déjà en file,
     * même si une soumission concurrente a épuisé le quota entre-temps.
     */
    public function record(string $clientIdentifier): void;
}
