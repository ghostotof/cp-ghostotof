<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Presentation\Command;

use App\Portfolio\Quality\Application\QualityPrincipleAdministratorInterface;
use App\Portfolio\Quality\Application\QualityTraitAdministratorInterface;
use App\Portfolio\Quality\Domain\Repository\QualityPrincipleRepositoryInterface;
use App\Portfolio\Quality\Domain\Repository\QualityTraitRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Peuple/réinitialise les principes et traits de qualité affichés sur la
 * landing page (fr/en), servis depuis la base via GET /api/quality/{locale}.
 * Idempotente : chaque exécution purge et recrée l'intégralité du contenu par
 * locale.
 */
#[AsCommand(
    name: 'app:quality:seed',
    description: 'Peuple/réinitialise les principes et traits de qualité (fr/en) avec leur contenu de référence.',
)]
final class SeedQualityContentCommand extends Command
{
    public function __construct(
        private readonly QualityPrincipleRepositoryInterface $qualityPrincipleRepository,
        private readonly QualityTraitRepositoryInterface $qualityTraitRepository,
        private readonly QualityPrincipleAdministratorInterface $qualityPrincipleAdministrator,
        private readonly QualityTraitAdministratorInterface $qualityTraitAdministrator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach (self::content() as $localeValue => $content) {
            $locale = Locale::from($localeValue);

            foreach ($this->qualityPrincipleRepository->findByLocale($locale) as $existing) {
                $this->qualityPrincipleRepository->remove($existing);
            }
            foreach (array_values($content['principles']) as $position => $principle) {
                $this->qualityPrincipleAdministrator->create($locale, $principle['title'], $principle['description'], $principle['iconKey'], $position);
            }

            foreach ($this->qualityTraitRepository->findByLocale($locale) as $existing) {
                $this->qualityTraitRepository->remove($existing);
            }
            foreach (array_values($content['traits']) as $position => $trait) {
                $this->qualityTraitAdministrator->create($locale, $trait['label'], $position);
            }

            $io->success(sprintf(
                '[%s] %d principe(s), %d trait(s).',
                $localeValue,
                \count($content['principles']),
                \count($content['traits']),
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, array{
     *     principles: list<array{title: string, description: string, iconKey: string}>,
     *     traits: list<array{label: string}>,
     * }>
     */
    private static function content(): array
    {
        return [
            'fr' => [
                'principles' => [
                    ['title' => 'DDD', 'description' => 'Modélisation du domaine métier pour créer des applications alignées sur les besoins réels et faciles à faire évoluer.', 'iconKey' => 'boxes'],
                    ['title' => 'SOLID', 'description' => 'Des bases solides pour un code flexible, maintenable et extensible dans le temps.', 'iconKey' => 'columns-3'],
                    ['title' => 'Design Patterns', 'description' => 'Utilisation de patrons de conception adaptés pour résoudre des problèmes récurrents avec élégance et efficacité.', 'iconKey' => 'puzzle'],
                ],
                'traits' => [
                    ['label' => 'Architecture propre'],
                    ['label' => 'Maintenabilité'],
                    ['label' => 'Évolutivité'],
                    ['label' => 'Qualité du code'],
                    ['label' => 'Tests automatisés'],
                    ['label' => 'Revues de code'],
                    ['label' => 'Documentation claire'],
                ],
            ],
            'en' => [
                'principles' => [
                    ['title' => 'DDD', 'description' => 'Domain modeling to build applications aligned with real needs and easy to evolve.', 'iconKey' => 'boxes'],
                    ['title' => 'SOLID', 'description' => 'A solid foundation for flexible, maintainable, and extensible code over time.', 'iconKey' => 'columns-3'],
                    ['title' => 'Design Patterns', 'description' => 'Using proven design patterns to solve recurring problems elegantly and efficiently.', 'iconKey' => 'puzzle'],
                ],
                'traits' => [
                    ['label' => 'Clean architecture'],
                    ['label' => 'Maintainability'],
                    ['label' => 'Scalability'],
                    ['label' => 'Code quality'],
                    ['label' => 'Automated tests'],
                    ['label' => 'Code reviews'],
                    ['label' => 'Clear documentation'],
                ],
            ],
        ];
    }
}
