<?php

declare(strict_types=1);

namespace App\Portfolio\About\Infrastructure\Doctrine;

use App\Portfolio\About\Domain\Entity\AboutSettings;
use App\Portfolio\About\Domain\Repository\AboutSettingsRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AboutSettings>
 */
class AboutSettingsRepository extends ServiceEntityRepository implements AboutSettingsRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AboutSettings::class);
    }

    public function findByLocale(Locale $locale): ?AboutSettings
    {
        return $this->findOneBy(['locale' => $locale]);
    }

    public function save(AboutSettings $settings): void
    {
        $this->getEntityManager()->persist($settings);
        $this->getEntityManager()->flush();
    }
}
