<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Presentation\Command;

use App\Portfolio\Watch\Domain\Service\PackageManifestBuilderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fige la liste des paquets déployés, que l'analyse de vulnérabilités relira.
 *
 * Destinée à s'exécuter **au build**, où les deux fichiers de verrouillage
 * coexistent : le manifeste voyage ensuite dans l'image. C'est ce qui permet à
 * l'analyse quotidienne de porter sur le périmètre réel du déploiement — front
 * compris — sans que la production n'ait besoin d'accéder aux sources.
 *
 * Elle reste utilisable en développement : le conteneur backend ne voyant pas
 * le dépôt frontend, elle y produit un manifeste limité aux paquets PHP, ce qui
 * suffit à exercer toute la chaîne.
 */
#[AsCommand(
    name: 'app:watch:build-manifest',
    description: 'Fige la liste des paquets déployés à partir des fichiers de verrouillage.',
)]
final class BuildPackageManifestCommand extends Command
{
    public function __construct(private readonly PackageManifestBuilderInterface $builder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $manifest = $this->builder->build(new \DateTimeImmutable());

        $byEcosystem = [];
        foreach ($manifest->packages as $package) {
            $byEcosystem[$package->ecosystem] = ($byEcosystem[$package->ecosystem] ?? 0) + 1;
        }

        if ([] === $byEcosystem) {
            // Un manifeste vide reste un manifeste : il dit « rien à analyser »,
            // ce que l'application distingue de « analyse jamais tentée ».
            $io->warning('Aucun paquet relevé : les fichiers de verrouillage sont introuvables ou vides.');

            return Command::SUCCESS;
        }

        foreach ($byEcosystem as $ecosystem => $count) {
            $io->writeln(sprintf('  %s : %d paquets', $ecosystem, $count));
        }

        $io->success(sprintf('%d paquets relevés.', \count($manifest->packages)));

        return Command::SUCCESS;
    }
}
