<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Presentation\Command;

use App\Portfolio\Experience\Application\ExperienceTechnologyRegistrarInterface;
use App\Portfolio\Experience\Domain\Exception\ExperienceTechnologyAlreadyExistsException;
use App\Portfolio\Experience\Domain\Exception\InvalidExperienceYearsException;
use App\Portfolio\Experience\Domain\Exception\InvalidTechnologyNameException;
use App\Portfolio\Experience\Domain\ValueObject\ExperienceYears;
use App\Portfolio\Experience\Domain\ValueObject\TechnologyName;
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

        $name = $this->resolveName($input, $io);

        if (null === $name) {
            return Command::FAILURE;
        }

        try {
            $years = $this->resolveYears($input, $io);
        } catch (InvalidExperienceYearsException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if (null === $years) {
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

        $io->success(\sprintf('Technologie "%s" ajoutée (id: %s, %s ans).', $technology->getName(), $technology->getId()->toRfc4122(), $technology->getYears()));

        return Command::SUCCESS;
    }

    /**
     * L'option, ou la question, dont le validateur fait reposer la saisie tant
     * qu'elle est refusée. Null après un message d'erreur : la commande échoue.
     *
     * Deux pièges du QuestionHelper (issue #383) : en non interactif, `ask()`
     * rend la valeur par défaut (null) sans passer par le validateur, d'où le
     * refus qui nomme l'option ; et sur une fin d'entrée après une saisie
     * refusée, il relance la dernière erreur du validateur, d'où `ask()` dans
     * le `try`.
     */
    private function resolveName(InputInterface $input, SymfonyStyle $io): ?TechnologyName
    {
        try {
            $option = $input->getOption('name');

            if (null !== $option) {
                return $this->parseName($option);
            }

            $answer = $io->ask('Nom de la technologie', validator: $this->parseName(...));

            if (null === $answer) {
                $io->error('Aucun nom de technologie : en mode non interactif, passez --name.');

                return null;
            }

            // Le validateur de la question a déjà converti la réponse.
            return $answer instanceof TechnologyName ? $answer : $this->parseName($answer);
        } catch (InvalidTechnologyNameException $exception) {
            $io->error($exception->getMessage());

            return null;
        }
    }

    /**
     * La règle du domaine (TechnologyName) : le nom rogné, et non la saisie
     * brute, part au registrar — sans quoi `--name=' PHP '` échappait au
     * contrôle d'unicité (issue #386). Le QuestionHelper affiche le message
     * de l'exception et repose la question. Une réponse qui n'est pas une
     * chaîne (aucune, en pratique) est traitée comme vide.
     *
     * @throws InvalidTechnologyNameException
     */
    private function parseName(mixed $name): TechnologyName
    {
        return TechnologyName::fromString(\is_string($name) ? $name : '');
    }

    /**
     * Option d'abord, validée avant tout appel au registrar : une durée
     * invalide n'est jamais masquée par un nom déjà pris. Sinon la question,
     * dont le validateur fait reposer la valeur tant qu'elle est refusée ;
     * sur une fin d'entrée après une saisie refusée, l'erreur relancée par le
     * QuestionHelper est l'exception du domaine, que execute() rattrape. Une
     * entrée standard fermée sans saisie refusée (MissingInputException, la
     * question n'ayant pas de défaut) n'est pas rattrapée : la commande
     * échoue avec sa trace, comme avant #372 et comme pour le nom.
     *
     * Null après un message d'erreur : en non interactif, ask() rend la
     * valeur par défaut (null) sans passer par le validateur (issue #383).
     *
     * @throws InvalidExperienceYearsException
     */
    private function resolveYears(InputInterface $input, SymfonyStyle $io): ?ExperienceYears
    {
        $option = $input->getOption('years');

        if (null !== $option) {
            return $this->parseYears($option);
        }

        $answer = $io->ask('Temps cumulé (en années, ex. 13.5)', validator: $this->parseYears(...));

        if (null === $answer) {
            $io->error('Aucune durée : en mode non interactif, passez --years.');

            return null;
        }

        return $answer instanceof ExperienceYears ? $answer : $this->parseYears($answer);
    }

    /**
     * Le QuestionHelper rattrape toute {@see \Exception} d'un validateur, l'exception
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
