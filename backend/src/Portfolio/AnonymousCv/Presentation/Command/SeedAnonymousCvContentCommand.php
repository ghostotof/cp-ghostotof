<?php

declare(strict_types=1);

namespace App\Portfolio\AnonymousCv\Presentation\Command;

use App\Portfolio\AnonymousCv\Application\AnonymousCvSectionAdministratorInterface;
use App\Portfolio\AnonymousCv\Domain\Repository\AnonymousCvSectionRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Presentation\Command\GuardsExistingContent;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Peuple/réinitialise le CV sans identité du palier de base (ADR 0003 D5),
 * servi depuis la base via GET /api/anonymous-cv/{locale} (ROLE_USER).
 * Idempotente, même garde-fou que les autres commandes app:*:seed
 * (GuardsExistingContent) : une base déjà peuplée est laissée intacte sans
 * --force.
 *
 * **Le contenu ci-dessous est un placeholder explicite** (titre préfixé
 * « [Exemple] »), pas du contenu de référence réel : les compétences,
 * l'ancienneté et surtout les réalisations sont la matière éditoriale de
 * Christophe, à saisir depuis le backoffice — sans nom, employeur, client ni
 * coordonnées. Même caveat que pour les études de cas et les Contributions.
 * Le préfixe empêche toute confusion si cette commande était un jour lancée
 * en production avant que le vrai contenu n'ait été fourni.
 */
#[AsCommand(
    name: 'app:anonymous-cv:seed',
    description: 'Peuple/réinitialise le CV sans identité (fr/en) avec un contenu placeholder.',
)]
final class SeedAnonymousCvContentCommand extends Command
{
    use GuardsExistingContent;

    public function __construct(
        private readonly AnonymousCvSectionRepositoryInterface $sectionRepository,
        private readonly AnonymousCvSectionAdministratorInterface $sectionAdministrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addForceOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->refusesToOverwrite($io, $input, $this->countExisting())) {
            return Command::SUCCESS;
        }

        foreach ($this->content() as $localeValue => $sections) {
            $locale = Locale::from($localeValue);

            foreach ($this->sectionRepository->findByLocale($locale) as $existing) {
                $this->sectionRepository->remove($existing);
            }

            foreach ($sections as $position => $section) {
                $this->sectionAdministrator->create(
                    $locale,
                    $section['title'],
                    $section['skills'],
                    $section['yearsOfExperience'],
                    $section['achievements'],
                    $position,
                );
            }

            $io->success(sprintf('[%s] %d section(s) de CV sans identité.', $localeValue, \count($sections)));
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, list<array{title: string, skills: string, yearsOfExperience: int, achievements: string}>>
     */
    private function content(): array
    {
        return [
            'fr' => [
                [
                    'title' => '[Exemple] Backend PHP / Symfony',
                    'skills' => 'Contenu à remplacer depuis le backoffice : les technologies et pratiques du domaine.',
                    'yearsOfExperience' => 0,
                    'achievements' => 'Contenu à remplacer depuis le backoffice : ce qui a été réalisé avec cette compétence, sans employeur ni client.',
                ],
            ],
            'en' => [
                [
                    'title' => '[Example] PHP / Symfony backend',
                    'skills' => 'Content to replace from the backoffice: the technologies and practices of the domain.',
                    'yearsOfExperience' => 0,
                    'achievements' => 'Content to replace from the backoffice: what was achieved with this skill, without employer or client.',
                ],
            ],
        ];
    }

    /**
     * Toutes locales confondues : une base à moitié peuplée (fr seulement, par
     * exemple) compte comme peuplée, et n'est donc pas écrasée en silence.
     */
    private function countExisting(): int
    {
        $total = 0;

        foreach (Locale::cases() as $locale) {
            $total += \count($this->sectionRepository->findByLocale($locale));
        }

        return $total;
    }
}
