<?php

declare(strict_types=1);

namespace App\Portfolio\CaseStudy\Presentation\Command;

use App\Portfolio\CaseStudy\Application\CaseStudyAdministratorInterface;
use App\Portfolio\CaseStudy\Domain\Repository\CaseStudyRepositoryInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Presentation\Command\GuardsExistingContent;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Peuple/réinitialise les études de cas techniques du palier de base (ADR
 * 0003 D5), servies depuis la base via GET /api/case-studies/{locale}
 * (ROLE_USER). Idempotente, même garde-fou que les cinq autres commandes
 * app:*:seed (GuardsExistingContent) : une base déjà peuplée est laissée
 * intacte sans --force.
 *
 * **Le contenu ci-dessous est un placeholder explicite** (titre préfixé
 * « [Exemple] »), pas du contenu de référence réel : le texte des études de
 * cas est la matière éditoriale de Christophe (études de cas techniques
 * réelles, jamais de nom de client), à saisir depuis le backoffice — même
 * caveat que la rubrique Contributions (cf. tasks/plan.md). Ce préfixe
 * empêche toute confusion si cette commande était un jour lancée en
 * production sans que le vrai contenu n'ait encore été fourni.
 */
#[AsCommand(
    name: 'app:case-studies:seed',
    description: 'Peuple/réinitialise les études de cas techniques (fr/en) avec un contenu placeholder.',
)]
final class SeedCaseStudyContentCommand extends Command
{
    use GuardsExistingContent;

    public function __construct(
        private readonly CaseStudyRepositoryInterface $caseStudyRepository,
        private readonly CaseStudyAdministratorInterface $caseStudyAdministrator,
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

        foreach ($this->content() as $localeValue => $caseStudies) {
            $locale = Locale::from($localeValue);

            foreach ($this->caseStudyRepository->findByLocale($locale) as $existing) {
                $this->caseStudyRepository->remove($existing);
            }

            foreach ($caseStudies as $position => $caseStudy) {
                $this->caseStudyAdministrator->create(
                    $locale,
                    $caseStudy['title'],
                    $caseStudy['problem'],
                    $caseStudy['solution'],
                    $caseStudy['tradeoffs'],
                    $caseStudy['measuredResult'],
                    $position,
                );
            }

            $io->success(sprintf('[%s] %d étude(s) de cas.', $localeValue, \count($caseStudies)));
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, list<array{title: string, problem: string, solution: string, tradeoffs: string, measuredResult: string}>>
     */
    private function content(): array
    {
        return [
            'fr' => [
                [
                    'title' => '[Exemple] Un cache partagé qui servait des réponses à la mauvaise organisation',
                    'problem' => 'Contenu à remplacer depuis le backoffice : le problème rencontré et ses contraintes, sans jamais nommer le client.',
                    'solution' => 'Contenu à remplacer depuis le backoffice : la solution retenue.',
                    'tradeoffs' => 'Contenu à remplacer depuis le backoffice : ce que la solution a coûté, pas seulement ce qu\'elle a apporté.',
                    'measuredResult' => 'Contenu à remplacer depuis le backoffice : un résultat mesuré, pas une affirmation.',
                ],
            ],
            'en' => [
                [
                    'title' => '[Example] A shared cache that served responses to the wrong organization',
                    'problem' => 'Content to replace from the backoffice: the problem encountered and its constraints, never naming the client.',
                    'solution' => 'Content to replace from the backoffice: the solution retained.',
                    'tradeoffs' => 'Content to replace from the backoffice: what the solution cost, not just what it brought.',
                    'measuredResult' => 'Content to replace from the backoffice: a measured result, not a claim.',
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
            $total += \count($this->caseStudyRepository->findByLocale($locale));
        }

        return $total;
    }
}
