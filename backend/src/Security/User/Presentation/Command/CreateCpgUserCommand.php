<?php

declare(strict_types=1);

namespace App\Security\User\Presentation\Command;

use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\InvalidUsernameException;
use App\Security\User\Domain\Exception\UsernameAlreadyUsedException;
use App\Security\User\Presentation\Validator\PlainPasswordLength;
use App\Shared\Presentation\Command\InvalidConsoleAnswerException;
use SensitiveParameter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * Chemin d'amorçage : il n'existe volontairement aucun formulaire
 * d'inscription public, et depuis l'ADR 0001 la voie normale de création est
 * l'invitation depuis le backoffice. La CLI reste pour ce que l'invitation ne
 * peut pas faire — créer le premier ROLE_SUPER — et pour les comptes de
 * développement. Usage interactif (prompts) ou scripté : `--username` et
 * `--password-stdin`, le mot de passe lu sur l'entrée standard.
 *
 * Jamais de mot de passe en argument (issue #386) : l'argv se lit dans `ps`,
 * dans l'historique du shell et dans le contexte `command` des journaux du
 * ErrorListener de la console. Même convention que `docker login`, et que
 * les rotations de secrets de `.claude/CLAUDE.md`.
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

    /**
     * Octets lus au plus sur l'entrée standard : la borne du hasher, en octets
     * (CpgUser::MAX_PASSWORD_LENGTH), plus une fin de ligne `\r\n`, plus un
     * octet. Une lecture qui atteint la borne est donc trop longue quoi
     * qu'elle contienne, et refusée comme telle avant toute validation — une
     * troncature au milieu d'un caractère la ferait passer pour de l'UTF-8
     * invalide. `yes | …` ne remplit pas la mémoire.
     */
    private const int STDIN_READ_LIMIT = CpgUser::MAX_PASSWORD_LENGTH + 3;

    /** Indicateur d'ordre des octets qu'un éditeur Windows place en tête de fichier. */
    private const string BYTE_ORDER_MARK = "\u{FEFF}";

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
            ->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Lit le mot de passe sur l\'entrée standard (usage scripté, exige --username)')
            ->addOption('role', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, \sprintf('Rôle additionnel à attribuer (répétable), parmi : %s', implode(', ', self::ALLOWED_ROLES)))
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Les options d'abord : ce qui se sait sans le mot de passe se refuse
        // avant de le lire, et avant de le soumettre à haveibeenpwned.
        $optionsError = $this->optionsError($input);

        if (null !== $optionsError) {
            $io->error($optionsError);

            return Command::FAILURE;
        }

        $username = $this->resolveUsername($input, $io);

        if (null === $username) {
            return Command::FAILURE;
        }

        $plainPassword = $this->resolvePassword($input, $io);

        if (null === $plainPassword || !$this->acceptsPassword($plainPassword, $io)) {
            return Command::FAILURE;
        }

        /** @var list<string> $roles */
        $roles = $input->getOption('role');

        try {
            $user = $this->cpgUserRegistrar->register($username, $plainPassword, $roles);
        } catch (UsernameAlreadyUsedException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Utilisateur "%s" créé (id: %s).', $user->getUsername(), $user->getId()->toRfc4122()));

        return Command::SUCCESS;
    }

    /**
     * Le refus d'une combinaison d'options, ou null. `--password-stdin` exige
     * `--username` : l'entrée standard porte le mot de passe, et l'invite du
     * nom d'utilisateur le lirait à sa place.
     */
    private function optionsError(InputInterface $input): ?string
    {
        if (true === $input->getOption('password-stdin') && null === $input->getOption('username')) {
            return '--password-stdin exige --username.';
        }

        /** @var list<string> $roles */
        $roles = $input->getOption('role');
        $unknownRoles = array_diff($roles, self::ALLOWED_ROLES);

        if ([] !== $unknownRoles) {
            return \sprintf('Rôle(s) inconnu(s) : %s. Rôles autorisés : %s.', implode(', ', $unknownRoles), implode(', ', self::ALLOWED_ROLES));
        }

        return null;
    }

    /**
     * La longueur, par la contrainte des deux ressources API (issue #386),
     * puis la fuite (point d'audit B8, même contrôle que le backoffice) : un
     * mot de passe déjà refusé n'interroge pas haveibeenpwned. En
     * environnement de test, la vérification réseau est désactivée
     * (validator.yaml, when@test).
     *
     * Sans `skipOnError`, la CLI échoue fermée quand le service est
     * injoignable — la personne est au terminal, elle réessaiera. Mais
     * l'exception du client HTTP ne sort pas de la commande : son message
     * porte l'URL, donc le préfixe SHA-1 du mot de passe, que le
     * ErrorListener de la console journaliserait en `critical`.
     */
    private function acceptsPassword(#[SensitiveParameter] string $plainPassword, SymfonyStyle $io): bool
    {
        $lengthViolations = $this->validator->validate($plainPassword, new PlainPasswordLength());

        if ($lengthViolations->count() > 0) {
            $io->error($this->lengthViolationMessage($lengthViolations->get(0)));

            return false;
        }

        try {
            $compromised = $this->validator->validate($plainPassword, new Assert\NotCompromisedPassword())->count() > 0;
        } catch (HttpClientExceptionInterface) {
            $io->error('haveibeenpwned est injoignable : le mot de passe n\'a pas pu être vérifié. Réessayez plus tard.');

            return false;
        }

        if ($compromised) {
            $io->error('Ce mot de passe figure dans une fuite de données connue (haveibeenpwned) : choisissez-en un autre.');

            return false;
        }

        return true;
    }

    /**
     * Les messages du Validator sont en anglais, ceux de la commande en
     * français ; le code de la violation dit laquelle des bornes a cédé.
     */
    private function lengthViolationMessage(ConstraintViolationInterface $violation): string
    {
        return match ($violation->getCode()) {
            Assert\Length::TOO_SHORT_ERROR => \sprintf('Le mot de passe doit contenir au moins %d caractères.', CpgUser::MIN_PASSWORD_LENGTH),
            Assert\Length::TOO_LONG_ERROR => $this->tooLongMessage(),
            Assert\Length::INVALID_CHARACTERS_ERROR => 'Le mot de passe n\'est pas de l\'UTF-8 valide.',
            default => (string) $violation->getMessage(),
        };
    }

    private function tooLongMessage(): string
    {
        return \sprintf('Le mot de passe ne doit pas dépasser %d octets.', CpgUser::MAX_PASSWORD_LENGTH);
    }

    /**
     * L'option, ou la question, dont le validateur fait reposer la saisie tant
     * qu'elle est refusée. Null après un message d'erreur : la commande échoue.
     *
     * Deux pièges du QuestionHelper (issue #383) : en non interactif, `ask()`
     * rend la valeur par défaut (null) sans passer par le validateur, d'où le
     * refus qui nomme l'option ; et sur une fin d'entrée après une saisie
     * refusée, il relance la dernière erreur du validateur, d'où `ask()` dans
     * le `try` — sans quoi elle quitterait la commande et le ErrorListener de
     * la console la journaliserait en `critical`.
     */
    private function resolveUsername(InputInterface $input, SymfonyStyle $io): ?string
    {
        try {
            $username = $input->getOption('username') ?? $io->ask('Nom d\'utilisateur', validator: $this->validateUsername(...));

            if (null === $username) {
                $io->error('Aucun nom d\'utilisateur : en mode non interactif, passez --username.');

                return null;
            }

            return $this->validateUsername($username);
        } catch (InvalidUsernameException $exception) {
            $io->error($exception->getMessage());

            return null;
        }
    }

    /**
     * La règle est celle du domaine (CpgUser::USERNAME_PATTERN), le message
     * aussi, sans la saisie : le QuestionHelper l'affiche et repose la question.
     *
     * @throws InvalidUsernameException
     */
    private function validateUsername(mixed $username): string
    {
        if (!\is_string($username) || 1 !== preg_match(CpgUser::USERNAME_PATTERN, $username)) {
            throw InvalidUsernameException::invalidFormat();
        }

        return $username;
    }

    /**
     * L'entrée standard, ou deux questions masquées. Null après un message
     * d'erreur : une entrée standard vide, pas de saisie en non interactif,
     * une fin d'entrée après une saisie vide (mêmes pièges que
     * resolveUsername()), ou une confirmation qui diffère — refusée comme
     * toute autre saisie, par un message et le code 1, pas par une exception
     * qui quitterait la commande.
     */
    private function resolvePassword(InputInterface $input, SymfonyStyle $io): ?string
    {
        if (true === $input->getOption('password-stdin')) {
            return $this->readPasswordFromStandardInput($input, $io);
        }

        try {
            $password = $io->askQuestion($this->hiddenQuestion('Mot de passe', static function (mixed $value): string {
                if (!\is_string($value) || '' === $value) {
                    throw InvalidConsoleAnswerException::empty('Le mot de passe');
                }

                return $value;
            }));
        } catch (InvalidConsoleAnswerException $exception) {
            $io->error($exception->getMessage());

            return null;
        }

        if (!\is_string($password)) {
            $io->error('Aucun mot de passe : en mode non interactif, passez --password-stdin.');

            return null;
        }

        if ($password !== $io->askQuestion($this->hiddenQuestion('Confirmez le mot de passe'))) {
            $io->error('Les deux mots de passe saisis ne correspondent pas.');

            return null;
        }

        return $password;
    }

    /**
     * Le flux est celui que lit le QuestionHelper (celui du CommandTester en
     * test), STDIN sinon. La fin de ligne qu'ajoutent `echo` ou un heredoc
     * est retirée, et elle seule, comme à l'invite : les espaces autour
     * restent, ainsi que json_login les prend.
     *
     * Refusé plutôt que nettoyé (revue de #386) : un terminal, sur lequel la
     * lecture attendrait Ctrl-D en affichant le mot de passe ; et ce qui
     * reste d'une fin de ligne ou d'un indicateur d'ordre des octets, qu'aucun
     * champ de mot de passe ne permet de taper — le compte serait
     * inutilisable sans que rien ne le dise.
     */
    private function readPasswordFromStandardInput(InputInterface $input, SymfonyStyle $io): ?string
    {
        $stream = ($input instanceof StreamableInputInterface ? $input->getStream() : null) ?? STDIN;

        if (stream_isatty($stream)) {
            $io->error('L\'entrée standard est un terminal : --password-stdin y afficherait le mot de passe. Redirigez-la (< fichier), ou omettez l\'option pour l\'invite masquée.');

            return null;
        }

        $read = stream_get_contents($stream, self::STDIN_READ_LIMIT);

        if (false !== $read && \strlen($read) >= self::STDIN_READ_LIMIT) {
            $io->error($this->tooLongMessage());

            return null;
        }

        $password = false === $read ? '' : (string) preg_replace('/\r?\n\z/', '', $read);

        if ('' === $password) {
            $io->error('Aucun mot de passe lu sur l\'entrée standard.');

            return null;
        }

        if (1 === preg_match('/[\r\n]/', $password) || str_contains($password, self::BYTE_ORDER_MARK)) {
            $io->error('Ce mot de passe ne peut pas être saisi à la connexion : il contient une fin de ligne ou un indicateur d\'ordre des octets (BOM).');

            return null;
        }

        return $password;
    }

    /**
     * Une question masquée qui rend le mot de passe tel qu'il a été tapé.
     * Une question est rognée par défaut, alors que `--password-stdin` et json_login
     * le prennent tel quel : une espace en tête ou en fin donnait un compte
     * inutilisable (issue #383). Sans rognage, la lecture garde en revanche la
     * fin de ligne de la saisie : le normaliseur, appliqué avant le
     * validateur, retire celle-là et rien d'autre.
     */
    private function hiddenQuestion(string $label, ?callable $validator = null): Question
    {
        $question = new Question($label);
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setTrimmable(false);
        $question->setNormalizer(static fn (mixed $value): mixed => \is_string($value) ? preg_replace('/\r?\n\z/', '', $value) : $value);
        $question->setValidator($validator);

        return $question;
    }
}
