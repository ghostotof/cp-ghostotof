<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Une URL concrète pour un chemin de route de `/api`, écrite une fois (#748, St5-7) :
 * {@see \App\Tests\Security\ApiRouteExposureTest} et
 * {@see \App\Tests\Security\Authentication\Infrastructure\Security\PublicRouteIgnoresRefusedSessionTest}
 * la refaisaient chacun, et la seconde copie avait perdu l'UUID valide.
 */
final class ConcreteApiUrl
{
    /**
     * Substitue des valeurs plausibles aux paramètres d'URL : la ressource
     * visée n'a pas besoin d'exister, le contrôle d'accès s'applique bien avant
     * que le provider ne cherche quoi que ce soit.
     */
    public static function of(string $path): string
    {
        $url = str_replace(['.{_format}', '{_format}'], '', $path);

        return (string) preg_replace_callback(
            '/\{(\w+)\}/',
            static fn (array $matches): string => match ($matches[1]) {
                'locale' => 'fr',
                'token' => str_repeat('a', 64),
                'status' => '404',
                'index' => 'index',
                // UUID fixe et valide au sens de `Requirement::UUID` (nibble de
                // version 7, variante 8) : les tâches suivantes posent des
                // `requirements: ['id' => Requirement::UUID]` sur les
                // opérations d'item, et un `1` y serait un 404 du routeur
                // (RouterListener, priorité 32) avant même le firewall
                // (priorité 8) — le test perdrait sa portée sur ces routes.
                'id' => '019968a0-0000-7000-8000-000000000001',
                default => '1',
            },
            $url,
        );
    }
}
