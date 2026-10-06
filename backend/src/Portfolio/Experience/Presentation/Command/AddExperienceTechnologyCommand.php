<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Presentation\Command;

use App\Portfolio\Experience\Application\ExperienceTechnologyRegistrarInterface;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyAlreadyExistsException;
use App\Portfolio\Experience\Domain\Exception\InvalidExperienceYearsException;
use App\Portfolio\Experience\Domain\ValueObject\ExperienceYears;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seul moyen d'alimenter le classement des technologies affiché sur la page
 * Expériences : pas de formulaire ni d'endpoint d'écriture exposé, l'API
 * publique (GET /api/experience/technologies) est en lecture seule.
 */
#[AsCommand(
    name: 'app:experience:add-technology',
    description: 'Ajoute une technologie au classement affiché sur la page Expériences.',
)]
final class AddExperienceTechnologyCommand extends Command
{
    public function __construct(private readonly ExperienceTechnologyRegistrarInterface $experienceTechnologyRegistrar)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nom de la technologie')
            ->addOption('years', null, InputOption::VALUE_REQUIRED, 'Temps cumulé passé dessus, en années (ex. 13.5)')
            ->addOption('icon', null, InputOption::VALUE_REQUIRED, 'Clé d\'icône côté frontend (optionnel)')
            ->addOption('related-technology', null, InputOption::VALUE_REQUIRED, 'Nom d\'une techno toujours utilisée à côté, affichée en label (optionnel)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = $input->getOption('name') ?? $io->ask('Nom de la technologie', validator: $this->validateName(...));
        \assert(\is_string($name));

        if ('' === trim($name)) {
            $io->error('Le nom de la technologie ne peut pas être vide.');

            return Command::FAILURE;
        }

        try {
            $years = $this->resolveYears($input, $io);
        } catch (InvalidExperienceYearsException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $icon = $input->getOption('icon');
        $relatedTechnology = $input->getOption('related-technology');

        try {
            $technology = $this->experienceTechnologyRegistrar->register(
                $name,
                $years,
                \is_string($icon) && '' !== $icon ? $icon : null,
                \is_string($relatedTechnology) && '' !== $relatedTechnology ? $relatedTechnology : null,
            );
        } catch (ExperienceTechnologyAlreadyExistsException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Technologie "%s" ajoutée (id: %s, %s ans).', $technology->getName(), $technology->getId()->toRfc4122(), $technology->getYears()));

        return Command::SUCCESS;
    }

    private function validateName(mixed $name): string
    {
        if (!\is_string($name) || '' === trim($name)) {
            throw new \InvalidArgumentException('Le nom de la technologie ne peut pas être vide.');
        }

        return $name;
    }

    /**
     * Option d'abord, validée avant tout appel au registrar : une durée
     * invalide n'est jamais masquée par un nom déjà pris. Sinon la question,
     * dont le validateur fait reposer la valeur tant qu'elle est refusée.
     *
     * @throws InvalidExperienceYearsException
     */
    private function resolveYears(InputInterface $input, SymfonyStyle $io): ExperienceYears
    {
        $option = $input->getOption('years');

        if (null !== $option) {
            return $this->parseYears($option);
        }

        $answer = $io->ask('Temps cumulé (en années, ex. 13.5)', validator: $this->parseYears(...));

        // En non-interactif, ask() rend la valeur par défaut (null) sans
        // passer par le validateur : on l'y soumet ici.
        return $answer instanceof ExperienceYears ? $answer : $this->parseYears($answer);
    }

    /**
     * Le QuestionHelper rattrape toute \Exception d'un validateur, l'exception
     * du domaine comprise : nul besoin de la ré-emballer pour que la question
     * soit reposée avec son message.
     *
     * @throws InvalidExperienceYearsException
     */
    private function parseYears(mixed $years): ExperienceYears
    {
        return ExperienceYears::fromString(\is_string($years) ? $years : '');
    }
}
