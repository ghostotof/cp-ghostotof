<?php

declare(strict_types=1);

namespace App\Portfolio\About\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\Portfolio\About\Domain\Entity\AboutSettings;
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
        #[Assert\Length(max: 180)]
        public string $siteEyebrow = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 180)]
        public string $meEyebrow = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 180)]
        public string $technicalSubtitle = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 180)]
        public string $personalSubtitle = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: 180)]
        public string $hobbiesSubtitle = '',
    ) {
    }

    /**
     * Fabrique unique du DTO : le Provider et le Processor la partagent, faute
     * de quoi un champ ajouté à l'entité devait être reporté aux deux endroits
     * — sans qu'aucun test ne le rappelle (issue #15).
     */
    public static function fromEntity(AboutSettings $settings): self
    {
        return new self(
            locale: $settings->getLocale()->value,
            siteEyebrow: $settings->getSiteEyebrow(),
            meEyebrow: $settings->getMeEyebrow(),
            technicalSubtitle: $settings->getTechnicalSubtitle(),
            personalSubtitle: $settings->getPersonalSubtitle(),
            hobbiesSubtitle: $settings->getHobbiesSubtitle(),
        );
    }
}
