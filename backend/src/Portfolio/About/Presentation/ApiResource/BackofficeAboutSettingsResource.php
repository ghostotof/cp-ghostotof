<?php

declare(strict_types=1);

namespace App\Portfolio\About\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\Portfolio\About\Infrastructure\ApiPlatform\BackofficeAboutSettingsProcessor;
use App\Portfolio\About\Infrastructure\ApiPlatform\BackofficeAboutSettingsProvider;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Édition réservée ROLE_SUPER (cf. access_control ^/api/backoffice dans
 * config/packages/security.yaml) des textes de section À propos. Uniquement
 * Get/Put : AboutSettings est un singleton par locale, seedé une fois, jamais
 * créé ni supprimé depuis le backoffice (voir AboutSettingsAdministratorInterface).
 * La locale EST l'identifiant de la ressource (pas de {id} numérique).
 */
#[ApiResource(
    shortName: 'BackofficeAboutSettings',
    operations: [
        new Get(
            uriTemplate: '/backoffice/about/settings/{locale}',
            provider: BackofficeAboutSettingsProvider::class,
        ),
        new Put(
            uriTemplate: '/backoffice/about/settings/{locale}',
            provider: BackofficeAboutSettingsProvider::class,
            processor: BackofficeAboutSettingsProcessor::class,
        ),
    ],
)]
final class BackofficeAboutSettingsResource
{
    public function __construct(
        public ?string $locale = null,
        #[Assert\NotBlank]
        public string $siteEyebrow = '',
        #[Assert\NotBlank]
        public string $meEyebrow = '',
        #[Assert\NotBlank]
        public string $technicalSubtitle = '',
        #[Assert\NotBlank]
        public string $personalSubtitle = '',
        #[Assert\NotBlank]
        public string $hobbiesSubtitle = '',
    ) {
    }
}
