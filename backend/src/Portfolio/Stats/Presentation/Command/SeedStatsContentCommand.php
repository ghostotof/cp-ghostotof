<?php

declare(strict_types=1);

namespace App\Portfolio\Stats\Presentation\Command;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Portfolio\Stats\Application\StatAdministratorInterface;
use App\Portfolio\Stats\Domain\Repository\StatRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Peuple/réinitialise les statistiques clés affichées sur la landing page
 * (fr/en), servies depuis la base via GET /api/stats/{locale}. Idempotente :
 * chaque exécution purge et recrée l'intégralité du contenu par locale.
 */
#[AsCommand(
    name: 'app:stats:seed',
    description: 'Peuple/réinitialise les statistiques clés (fr/en) avec leur contenu de référence.',
)]
final class SeedStatsContentCommand extends Command
{
    public function __construct(
        private readonly StatRepositoryInterface $statRepository,
        private readonly StatAdministratorInterface $statAdministrator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach ($this->content() as $localeValue => $stats) {
            $locale = Locale::from($localeValue);

            foreach ($this->statRepository->findByLocale($locale) as $existing) {
                $this->statRepository->remove($existing);
            }

            foreach ($stats as $position => $stat) {
                $this->statAdministrator->create($locale, $stat['value'], $stat['label'], $stat['iconKey'], $position);
            }

            $io->success(sprintf('[%s] %d statistique(s).', $localeValue, \count($stats)));
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, list<array{value: string, label: string, iconKey: string}>>
     */
    private function content(): array
    {
        return [
            'fr' => [
                ['value' => '+50K', 'label' => 'Lignes de code', 'iconKey' => 'code'],
                ['value' => '10+', 'label' => 'Technologies maîtrisées', 'iconKey' => 'box'],
                ['value' => '100%', 'label' => 'Engagement qualité', 'iconKey' => 'users'],
                ['value' => '∞', 'label' => 'Passion', 'iconKey' => 'infinity'],
            ],
            'en' => [
                ['value' => '+50K', 'label' => 'Lines of code', 'iconKey' => 'code'],
                ['value' => '10+', 'label' => 'Technologies mastered', 'iconKey' => 'box'],
                ['value' => '100%', 'label' => 'Quality commitment', 'iconKey' => 'users'],
                ['value' => '∞', 'label' => 'Passion', 'iconKey' => 'infinity'],
            ],
        ];
    }
}
