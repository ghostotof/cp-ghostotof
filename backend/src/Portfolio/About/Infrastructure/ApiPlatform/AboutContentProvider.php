<?php

declare(strict_types=1);

namespace App\Portfolio\About\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Portfolio\About\Application\AboutMeCardPresenterInterface;
use App\Portfolio\About\Application\AboutSettingsPresenterInterface;
use App\Portfolio\About\Application\AboutSiteCardPresenterInterface;
use App\Portfolio\About\Domain\Entity\AboutMeCard;
use App\Portfolio\About\Domain\Entity\AboutSiteCard;
use App\Portfolio\About\Domain\Exception\AboutSettingsNotFoundException;
use App\Portfolio\About\Domain\Repository\AboutMeCardRepositoryInterface;
use App\Portfolio\About\Domain\Repository\AboutSettingsRepositoryInterface;
use App\Portfolio\About\Domain\Repository\AboutSiteCardRepositoryInterface;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Portfolio\About\Presentation\ApiResource\AboutCardResource;
use App\Portfolio\About\Presentation\ApiResource\AboutContentResource;
use App\Portfolio\About\Presentation\ApiResource\AboutMeSectionResource;
use App\Portfolio\About\Presentation\ApiResource\AboutSiteSectionResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

/**
 * Relie AboutContentResource (Presentation) au Domain, en agrégeant les
 * settings et les deux collections de cartes (site + me, cette dernière
 * rangée par catégorie) : seule cette classe Infrastructure a le droit de
 * connaître à la fois les entités Doctrine et la ressource API Platform.
 * Même pattern que QualityContentProvider.
 *
 * @implements ProviderInterface<AboutContentResource>
 */
final readonly class AboutContentProvider implements ProviderInterface
{
    public function __construct(
        private AboutSettingsRepositoryInterface $aboutSettingsRepository,
        private AboutSettingsPresenterInterface $aboutSettingsPresenter,
        private AboutSiteCardRepositoryInterface $aboutSiteCardRepository,
        private AboutSiteCardPresenterInterface $aboutSiteCardPresenter,
        private AboutMeCardRepositoryInterface $aboutMeCardRepository,
        private AboutMeCardPresenterInterface $aboutMeCardPresenter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AboutContentResource
    {
        $locale = Locale::from((string) $uriVariables['locale']);

        $settings = $this->aboutSettingsRepository->findByLocale($locale);

        if (null === $settings) {
            throw AboutSettingsNotFoundException::forLocale($locale);
        }

        $presentedSettings = $this->aboutSettingsPresenter->present($settings);

        return new AboutContentResource(
            site: new AboutSiteSectionResource(
                eyebrow: $presentedSettings['siteEyebrow'],
                cards: array_map($this->toSiteCardResource(...), $this->aboutSiteCardRepository->findByLocale($locale)),
            ),
            me: new AboutMeSectionResource(
                eyebrow: $presentedSettings['meEyebrow'],
                technicalSubtitle: $presentedSettings['technicalSubtitle'],
                technicalCards: array_map(
                    $this->toMeCardResource(...),
                    $this->aboutMeCardRepository->findByLocaleAndCategory($locale, AboutMeCardCategory::TECHNICAL),
                ),
                personalSubtitle: $presentedSettings['personalSubtitle'],
                personalCards: array_map(
                    $this->toMeCardResource(...),
                    $this->aboutMeCardRepository->findByLocaleAndCategory($locale, AboutMeCardCategory::PERSONAL),
                ),
                hobbiesSubtitle: $presentedSettings['hobbiesSubtitle'],
                hobbiesCards: array_map(
                    $this->toMeCardResource(...),
                    $this->aboutMeCardRepository->findByLocaleAndCategory($locale, AboutMeCardCategory::HOBBY),
                ),
            ),
        );
    }

    private function toSiteCardResource(AboutSiteCard $card): AboutCardResource
    {
        return new AboutCardResource(...$this->aboutSiteCardPresenter->present($card));
    }

    private function toMeCardResource(AboutMeCard $card): AboutCardResource
    {
        return new AboutCardResource(...$this->aboutMeCardPresenter->present($card));
    }
}
