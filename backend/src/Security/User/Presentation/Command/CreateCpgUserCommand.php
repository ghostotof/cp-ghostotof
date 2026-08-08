<?php

declare(strict_types=1);

namespace App\Security\User\Presentation\Command;

use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Exception\UsernameAlreadyUsedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seul moyen de créer un CpgUser : il n'existe volontairement aucun
 * formulaire d'inscription public. Usage interactif (prompts) ou scripté
 * (--username/--password, ex. provisionnement dans un pipeline de déploiement).
 */
#[AsCommand(
    name: 'app:user:create',
    description: 'Crée un utilisateur pouvant s\'authentifier depuis le frontend (aucune inscription publique n\'existe).',
)]
final class CreateCpgUserCommand extends Command
{
    private const int MIN_PASSWORD_LENGTH = 8;
    private const string USERNAME_PATTERN = '/^[a-zA-Z0-9_.-]{3,60}$/';

    public function __construct(private readonly CpgUserRegistrarInterface $cpgUserRegistrar)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'Nom d\'utilisateur')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Mot de passe en clair (usage scripté uniquement)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = $input->getOption('username') ?? $io->ask('Nom d\'utilisateur', validator: $this->validateUsername(...));

        if (!preg_match(self::USERNAME_PATTERN, (string) $username)) {
            $io->error('Le nom d\'utilisateur doit contenir entre 3 et 60 caractères (lettres, chiffres, ".", "_" ou "-").');

            return Command::FAILURE;
        }

        $plainPassword = $input->getOption('password') ?? $this->askPassword($io);

        if (\strlen($plainPassword) < self::MIN_PASSWORD_LENGTH) {
            $io->error(sprintf('Le mot de passe doit contenir au moins %d caractères.', self::MIN_PASSWORD_LENGTH));

            return Command::FAILURE;
        }

        try {
            $user = $this->cpgUserRegistrar->register($username, $plainPassword);
        } catch (UsernameAlreadyUsedException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Utilisateur "%s" créé (id: %d).', $user->getUsername(), $user->getId()));

        return Command::SUCCESS;
    }

    private function validateUsername(?string $username): string
    {
        if (!preg_match(self::USERNAME_PATTERN, (string) $username)) {
            throw new \InvalidArgumentException('Le nom d\'utilisateur doit contenir entre 3 et 60 caractères (lettres, chiffres, ".", "_" ou "-").');
        }

        return $username;
    }

    private function askPassword(SymfonyStyle $io): string
    {
        $question = new Question('Mot de passe');
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setValidator(function (?string $value): string {
            if (null === $value || '' === $value) {
                throw new \InvalidArgumentException('Le mot de passe ne peut pas être vide.');
            }

            return $value;
        });

        $password = $io->askQuestion($question);

        $confirmationQuestion = new Question('Confirmez le mot de passe');
        $confirmationQuestion->setHidden(true);
        $confirmationQuestion->setHiddenFallback(false);
        $confirmation = $io->askQuestion($confirmationQuestion);

        if ($password !== $confirmation) {
            throw new \InvalidArgumentException('Les deux mots de passe saisis ne correspondent pas.');
        }

        return $password;
    }
}
