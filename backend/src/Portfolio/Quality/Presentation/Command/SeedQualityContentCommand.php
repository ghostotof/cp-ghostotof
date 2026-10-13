<?php

declare(strict_types=1);

namespace App\Portfolio\Quality\Presentation\Command;

use App\Portfolio\Quality\Application\QualityPrincipleAdministratorInterface;
use App\Portfolio\Quality\Application\QualityTraitAdministratorInterface;
use App\Portfolio\Quality\Domain\Entity\QualityPrinciple;
use App\Portfolio\Quality\Domain\Entity\QualityTrait;
use App\Portfolio\Quality\Domain\Repository\QualityPrincipleRepositoryInterface;
use App\Portfolio\Quality\Domain\Repository\QualityTraitRepositoryInterface;
use App\Portfolio\Shared\Application\OrderScopeLockInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Presentation\Command\GuardsExistingContent;
use App\Shared\Presentation\Command\SeedOutcome;
use App\Shared\Presentation\Command\TranslationGroupIndex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Peuple/réinitialise les principes et traits de qualité affichés sur la
 * landing page (fr/en), servis depuis la base via GET /api/quality/{locale}.
 * Même garde-fou que les autres commandes app:*:seed ({@see GuardsExistingContent}) :
 * si du contenu est déjà en place, elle décline (code de sortie 0). Avec
 * --force, elle purge les deux tables, toutes locales confondues, puis recrée
 * le contenu de référence, ce qui écrase les éditions faites au backoffice.
 * Le tout dans une seule transaction, sous les verrous d'ordre des deux
 * tables (issue #567) : une panne entre les deux ne laisse plus la seconde
 * vide, et un `--force` interrompu garde le contenu d'avant.
 */
#[AsCommand(
    name: 'app:quality:seed',
    description: 'Peuple/réinitialise les principes et traits de qualité (fr/en) avec leur contenu de référence.',
)]
final class SeedQualityContentCommand extends Command
{
    use GuardsExistingContent;

    /**
     * Les périmètres d'ordre écrits : la classe d'entité de chaque table,
     * celle que son `Administrator` verrouille — un autre périmètre ici serait
     * refusé à leur premier `withLock()`.
     */
    private const array ORDER_SCOPES = [QualityPrinciple::class, QualityTrait::class];

    public function __construct(
        private readonly QualityPrincipleRepositoryInterface $qualityPrincipleRepository,
        private readonly QualityTraitRepositoryInterface $qualityTraitRepository,
        private readonly QualityPrincipleAdministratorInterface $qualityPrincipleAdministrator,
        private readonly QualityTraitAdministratorInterface $qualityTraitAdministrator,
        private readonly OrderScopeLockInterface $orderScopeLock,
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

        // Issue #567 : décompte, purge et recréation dans une seule transaction,
        // sous les verrous des deux périmètres ; le compte rendu s'affiche après
        // son COMMIT.
        $outcome = $this->orderScopeLock->withLocks(
            self::ORDER_SCOPES,
            fn (): SeedOutcome => $this->seedUnlessPopulated($input, $this->countExisting(...), $this->seed(...)),
        );

        return $outcome->report($io);
    }

    /**
     * Purge puis recrée, sous les verrous : la purge des deux tables est écrite
     * ligne par ligne avant toute création.
     *
     * @return list<string> une ligne de compte rendu par locale
     */
    private function seed(): array
    {
        $translationGroups = new TranslationGroupIndex();
        $lines = [];

        // Spec 0004 D3 : la purge précède toute création, au lieu
        // d'être faite langue par langue. Une entrée sans groupe se range en
        // fin de périmètre, toutes langues confondues : les entrées d'une
        // autre langue encore en place pendant la création décaleraient la
        // numérotation d'autant, à chaque `--force`.
        foreach ($this->qualityPrincipleRepository->findAll() as $existing) {
            $this->qualityPrincipleRepository->remove($existing);
        }

        foreach ($this->qualityTraitRepository->findAll() as $existing) {
            $this->qualityTraitRepository->remove($existing);
        }

        foreach ($this->content() as $localeValue => $content) {
            $locale = Locale::from($localeValue);

            foreach ($content['principles'] as $index => $principle) {
                $created = $this->qualityPrincipleAdministrator->create(
                    $locale,
                    $principle['title'],
                    $principle['description'],
                    $principle['iconKey'],
                    $translationGroups->forIndex('principle', $index),
                );

                $translationGroups->remember('principle', $index, $created->getTranslationGroup());
            }

            foreach ($content['traits'] as $index => $trait) {
                $created = $this->qualityTraitAdministrator->create(
                    $locale,
                    $trait['label'],
                    $translationGroups->forIndex('trait', $index),
                );

                $translationGroups->remember('trait', $index, $created->getTranslationGroup());
            }

            $lines[] = \sprintf(
                '[%s] %d principe(s), %d trait(s).',
                $localeValue,
                \count($content['principles']),
                \count($content['traits']),
            );
        }

        return $lines;
    }

    /**
     * @return array<string, array{
     *     principles: list<array{title: string, description: string, iconKey: string}>,
     *     traits: list<array{label: string}>,
     * }>
     */
    private function content(): array
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

    /**
     * Principes et traits, toutes locales confondues : une base à moitié
     * peuplée compte comme peuplée, et n'est donc pas écrasée en silence.
     */
    private function countExisting(): int
    {
        $total = 0;

        foreach (Locale::cases() as $locale) {
            $total += \count($this->qualityPrincipleRepository->findByLocale($locale));
            $total += \count($this->qualityTraitRepository->findByLocale($locale));
        }

        return $total;
    }
}
