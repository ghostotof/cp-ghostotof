<?php

declare(strict_types=1);

namespace App\Security\User\Presentation\Command;

use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\UsernameAlreadyUsedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Chemin d'amorçage : il n'existe volontairement aucun formulaire
 * d'inscription public, et depuis l'ADR 0001 la voie normale de création est
 * l'invitation depuis le backoffice. La CLI reste pour ce que l'invitation ne
 * peut pas faire — créer le premier ROLE_SUPER — et pour les comptes de
 * développement. Usage interactif (prompts) ou scripté (--username/--password).
 */
#[AsCommand(
    name: 'app:user:create',
    description: 'Crée un utilisateur pouvant s\'authentifier depuis le frontend (aucune inscription publique n\'existe).',
)]
final class CreateCpgUserCommand extends Command
{
    /**
     * Décision ADR 0003, Task 12 (#56, 2026-09-13) : **ROLE_TRUSTED n'est pas
     * attribuable ici, et c'est voulu.** D1 veut ce palier « accordé
     * nominativement » : l'invitation (CpgUserInviter) lie l'octroi à une
     * adresse e-mail, donc à une personne ; un compte CLI n'a qu'un username,
     * il ne dit pas *à qui* l'accès au CV a été ouvert. Un second chemin
     * d'octroi, sans cette trace, affaiblirait la seule garantie que le palier
     * de confiance apporte. ROLE_SUPER reste ici parce qu'il faut bien un
     * premier administrateur avant que l'invitation existe — et il hérite de
     * ROLE_TRUSTED par la role_hierarchy, ce qui est assumé : l'administrateur
     * du site est, par construction, la personne identifiée. Sans --role, le
     * compte reste au palier de base (ROLE_USER, cf. CpgUser::getRoles()),
     * ce qui est exactement ce qu'un compte de développement doit être.
     * Un test pince le refus.
     *
     * @var list<string>
     */
    private const array ALLOWED_ROLES = [CpgUser::ROLE_SUPER];

    public function __construct(
        private readonly CpgUserRegistrarInterface $cpgUserRegistrar,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'Nom d\'utilisateur')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Mot de passe en clair (usage scripté uniquement)')
            ->addOption('role', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, sprintf('Rôle additionnel à attribuer (répétable), parmi : %s', implode(', ', self::ALLOWED_ROLES)))
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = $input->getOption('username') ?? $io->ask('Nom d\'utilisateur', validator: $this->validateUsername(...));
        \assert(\is_string($username));

        if (1 !== preg_match(CpgUser::USERNAME_PATTERN, $username)) {
            $io->error('Le nom d\'utilisateur doit contenir entre 3 et 60 caractères (lettres, chiffres, ".", "_" ou "-").');

            return Command::FAILURE;
        }

        $plainPassword = $input->getOption('password') ?? $this->askPassword($io);
        \assert(\is_string($plainPassword));

        if (\strlen($plainPassword) < CpgUser::MIN_PASSWORD_LENGTH) {
            $io->error(sprintf('Le mot de passe doit contenir au moins %d caractères.', CpgUser::MIN_PASSWORD_LENGTH));

            return Command::FAILURE;
        }

        if (\strlen($plainPassword) > CpgUser::MAX_PASSWORD_LENGTH) {
            $io->error(sprintf('Le mot de passe ne doit pas dépasser %d caractères.', CpgUser::MAX_PASSWORD_LENGTH));

            return Command::FAILURE;
        }

        // Point d'audit B8 : même contrôle que l'endpoint backoffice
        // (BackofficeUserPasswordResource). En environnement de test, la
        // vérification réseau est désactivée (validator.yaml, when@test).
        if ($this->validator->validate($plainPassword, new Assert\NotCompromisedPassword())->count() > 0) {
            $io->error('Ce mot de passe figure dans une fuite de données connue (haveibeenpwned) : choisissez-en un autre.');

            return Command::FAILURE;
        }

        /** @var list<string> $roles */
        $roles = $input->getOption('role');
        $unknownRoles = array_diff($roles, self::ALLOWED_ROLES);

        if ([] !== $unknownRoles) {
            $io->error(sprintf('Rôle(s) inconnu(s) : %s. Rôles autorisés : %s.', implode(', ', $unknownRoles), implode(', ', self::ALLOWED_ROLES)));

            return Command::FAILURE;
        }

        try {
            $user = $this->cpgUserRegistrar->register($username, $plainPassword, $roles);
        } catch (UsernameAlreadyUsedException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Utilisateur "%s" créé (id: %d).', $user->getUsername(), $user->getId()));

        return Command::SUCCESS;
    }

    private function validateUsername(mixed $username): string
    {
        if (!\is_string($username) || 1 !== preg_match(CpgUser::USERNAME_PATTERN, $username)) {
            throw new \InvalidArgumentException('Le nom d\'utilisateur doit contenir entre 3 et 60 caractères (lettres, chiffres, ".", "_" ou "-").');
        }

        return $username;
    }

    private function askPassword(SymfonyStyle $io): string
    {
        $question = new Question('Mot de passe');
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setValidator(function (mixed $value): string {
            if (!\is_string($value) || '' === $value) {
                throw new \InvalidArgumentException('Le mot de passe ne peut pas être vide.');
            }

            return $value;
        });

        $password = $io->askQuestion($question);
        \assert(\is_string($password));

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
