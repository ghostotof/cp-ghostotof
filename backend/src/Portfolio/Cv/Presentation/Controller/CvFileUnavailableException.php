<?php

declare(strict_types=1);

namespace App\Portfolio\Cv\Presentation\Controller;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Le CV nominatif n'est pas sur le disque, au chemin configuré
 * (`app.cv_file_path`) : un défaut du déploiement, que l'appelant — un compte
 * de confiance — ne peut ni causer ni corriger.
 *
 * Toujours une 404, comme avant (#678), mais nommée : `framework.exceptions`
 * met les 404 du framework en `info` (erreurs du client), et celle-ci y a sa
 * propre entrée en `warning`, **avant** celle de {@see NotFoundHttpException} —
 * le noyau retient la première qui correspond —, pour rester visible en
 * production.
 */
final class CvFileUnavailableException extends NotFoundHttpException
{
    public static function atConfiguredPath(): self
    {
        // Le chemin n'est pas cité : il ne regarde pas le client.
        return new self('CV non disponible.');
    }
}
