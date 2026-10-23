<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Presentation\Command;

use App\Mcp\Connection\Application\McpClientRegistrarInterface;
use App\Mcp\Connection\Domain\Exception\InvalidRedirectUriException;
use App\Mcp\Connection\Domain\Exception\McpClientAlreadyRegisteredException;
use App\Mcp\Connection\Domain\ValueObject\RedirectUri;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seule voie d'enregistrement d'un client MCP (spec 0007 phase 2, #647) : le
 * serveur d'autorisation ne publie aucun `registration_endpoint`, un client
 * qu'aucun opérateur n'a enregistré ici n'obtient pas de connexion MCP.
 *
 * Usage scripté uniquement, toutes les valeurs en options : rien n'est secret
 * (un client public n'a pas de secret), et une commande sans invite échappe
 * aux pièges du QuestionHelper (#383). Aucun message ne reprend une valeur
 * saisie, hormis le récapitulatif du succès.
 */
#[AsCommand(
    name: 'app:mcp:client:register',
    description: 'Enregistre un client MCP public autorisé à demander une connexion MCP (aucun enregistrement dynamique n\'existe).',
)]
final class RegisterMcpClientCommand extends Command
{
    /**
     * 1 à 255 caractères d'un alphabet d'URL sans `?`, `#`, `%` ni espace :
     * un identifiant court (`claude-code`) ou l'URL d'un document de
     * métadonnées (CIMD). Plus strict que la colonne (512) : la commande est
     * seule à en décider, la colonne n'est qu'une borne de stockage.
     */
    private const string CLIENT_ID_PATTERN = '#^[A-Za-z0-9._:/-]{1,255}\z#';

    /**
     * Le nom déclaré, affiché sur la page de consentement : 1 à 100
     * caractères (rognés), en UTF-8 valide (le motif `u` refuse le reste),
     * sans caractère de contrôle ni de format (`\p{C}`) ni séparateur de ligne
     * ou de paragraphe (U+2028, U+2029 : `\p{Zl}`, `\p{Zp}`), qui tromperaient
     * l'affichage. Les espaces Unicode (`\p{Zs}`, insécable comprise) restent
     * admises à l'intérieur du nom.
     */
    private const string NAME_PATTERN = '/^[^\p{C}\p{Zl}\p{Zp}]{1,100}\z/u';

    /**
     * Les blancs rognés aux deux bouts du nom : ceux d'Unicode (`\p{Z}`,
     * U+00A0 et U+3000 compris) et ceux de `\s`, que trim() ne connaît pas
     * au-delà de l'ASCII — un nom fait d'espaces insécables n'est pas un nom.
     */
    private const string NAME_EDGE_BLANKS = '/^[\p{Z}\s]+|[\p{Z}\s]+\z/u';

    public function __construct(
        private readonly McpClientRegistrarInterface $registrar,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('client-id', null, InputOption::VALUE_REQUIRED, 'Identifiant public du client (client_id), 1 à 255 caractères parmi A-Z a-z 0-9 . _ : / -')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nom du client, affiché comme déclaré sur la page de consentement (1 à 100 caractères)')
            ->addOption('redirect-uri', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Redirection enregistrée (répétable, au moins une) : https exacte, ou http sur localhost, 127.0.0.1 ou [::1] (port ignoré)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $clientId = $this->resolveClientId($input, $io);

        if (null === $clientId) {
            return Command::FAILURE;
        }

        $name = $this->resolveName($input, $io);

        if (null === $name) {
            return Command::FAILURE;
        }

        $redirectUris = $this->resolveRedirectUris($input, $io);

        if (null === $redirectUris) {
            return Command::FAILURE;
        }

        try {
            $client = $this->registrar->register($clientId, $name, $redirectUris);
        } catch (McpClientAlreadyRegisteredException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Client MCP "%s" enregistré sous le nom "%s" (id: %s).', $client->getClientId(), $client->getName(), $client->getId()->toRfc4122()));
        $io->text('Redirections enregistrées :');
        $io->listing($client->redirectUris());

        return Command::SUCCESS;
    }

    /** L'identifiant public, ou null après un message d'erreur. */
    private function resolveClientId(InputInterface $input, SymfonyStyle $io): ?string
    {
        $clientId = $input->getOption('client-id');

        if (!\is_string($clientId) || 1 !== preg_match(self::CLIENT_ID_PATTERN, $clientId)) {
            $io->error('--client-id est requis : 1 à 255 caractères parmi A-Z a-z 0-9 . _ : / -.');

            return null;
        }

        return $clientId;
    }

    /** Le nom déclaré, rogné, ou null après un message d'erreur. */
    private function resolveName(InputInterface $input, SymfonyStyle $io): ?string
    {
        $name = $input->getOption('name');
        // preg_replace() rend null, et preg_match() false, sur de l'UTF-8
        // invalide : refusé comme le reste.
        $name = \is_string($name) ? preg_replace(self::NAME_EDGE_BLANKS, '', $name) : null;

        if (null === $name || 1 !== preg_match(self::NAME_PATTERN, $name)) {
            $io->error('--name est requis : 1 à 100 caractères en UTF-8, sans caractère de contrôle ni saut de ligne.');

            return null;
        }

        return $name;
    }

    /**
     * Chaque redirection passe RedirectUri avant tout enregistrement ; une
     * valeur répétée à l'identique n'est enregistrée qu'une fois. Null après
     * un message d'erreur qui désigne la redirection refusée par son rang
     * parmi les valeurs distinctes, sans la citer.
     *
     * @return non-empty-list<RedirectUri>|null
     */
    private function resolveRedirectUris(InputInterface $input, SymfonyStyle $io): ?array
    {
        /** @var list<string> $options */
        $options = $input->getOption('redirect-uri');
        $values = array_values(array_unique($options));

        if ([] === $values) {
            $io->error('Au moins une --redirect-uri est requise.');

            return null;
        }

        $redirectUris = [];

        foreach ($values as $index => $value) {
            try {
                $redirectUris[] = RedirectUri::fromString($value);
            } catch (InvalidRedirectUriException $exception) {
                $io->error(\sprintf('La --redirect-uri n°%d est refusée. %s', $index + 1, $exception->getMessage()));

                return null;
            }
        }

        return $redirectUris;
    }
}
