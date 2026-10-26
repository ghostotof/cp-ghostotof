<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Les services de sécurité d'un environnement, tels que les définitions les
 * portent avant les passes de retrait (issue #698), décrits en tableaux pour
 * se comparer à `===` d'un environnement à l'autre — voir
 * {@see SecurityServicesSnapshotPass}.
 */
final readonly class SecurityServicesSnapshot
{
    /**
     * @param array<string, array<string, mixed>> $services    identifiant => description de la définition
     * @param array<string, string>               $aliases     alias du périmètre => service visé
     * @param list<string>                        $listeners   « événement service::méthode priorité », dans l'ordre d'enregistrement
     * @param list<class-string>                  $laterPasses classes des passes qui suivent l'instantané, dans l'ordre
     */
    public function __construct(
        public array $services,
        public array $aliases,
        public array $listeners,
        public array $laterPasses,
    ) {
    }
}
