<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Presentation\Command;

use App\Portfolio\Watch\Infrastructure\Manifest\DeployedVersionsBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pendant, pour le développement, du relevé fait au `docker build`.
 *
 * En production le fichier naît avec l'image et ne peut donc pas s'en écarter.
 * En développement l'image n'est pas construite : sans cette commande, les cinq
 * produits concernés s'afficheraient sans version, ce qui est correct mais peu
 * pratique pour travailler sur la page.
 *
 * Elle lit les mêmes fichiers, montés en lecture seule dans le conteneur par le
 * docker-compose. Le tag Node vient alors de `.env` plutôt que d'un argument de
 * build, ce qui revient au même : c'est ce fichier qui pilote la construction.
 */
#[AsCommand(
    name: 'app:watch:build-versions',
    description: 'Relève les versions déployées depuis les manifestes k8s, .env et le lock npm.',
)]
final class BuildDeployedVersionsCommand extends Command
{
    public function __construct(private readonly DeployedVersionsBuilder $builder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $versions = $this->builder->build(new \DateTimeImmutable());

        if ([] === $versions) {
            // Sortie en succès malgré tout : hors du conteneur outillé, les
            // fichiers sources ne sont pas visibles, et c'est un cas prévu — pas
            // une panne. Le dire suffit.
            $io->warning('Aucune version relevée : les manifestes ne sont pas accessibles depuis ce conteneur.');

            return Command::SUCCESS;
        }

        foreach ($versions as $slug => $version) {
            $io->writeln(sprintf('  <info>%-12s</info> %s', $slug, $version));
        }

        $io->success(sprintf('%d version(s) relevée(s).', \count($versions)));

        return Command::SUCCESS;
    }
}
